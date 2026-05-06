# Handik Booking App — Performance Audit (v2.1.8.5)

> **Scope:** end-to-end audit с фокусом на ассистент-этап (Virtual assistant) и общее быстродействие.
> **Цель:** срезать **time-to-first-token** в чате как минимум вдвое и сделать UX заметно "снаппирнее" на остальных шагах.
> **Метод:** прошёлся по фактическому коду v2.1.8.5 (тег `v2.1.8.5`), Agent Builder workflow и фронт-бридж.

---

## TL;DR — где зарыты секунды

Когда клиент отправляет первое сообщение ассистенту, под капотом происходит примерно вот что:

```
[Frontend submit]
  ↓ ~200ms  ChatKit web component → OpenAI ChatKit
  ↓
[Agent Builder workflow]
  Input Safety Check (moderation + jailbreak)        ~400–900ms   ← блокирующая, без необходимости
  ↓
  Classification Agent (gpt-5.4-mini, reasoning low) ~1.5–4s
    + tool: get_request_photo_context                +PHP RTT ~150ms
    + tool: get_request_pricing_context               +PHP RTT ~150ms
    + tool: save_assistant_routing_result             +PHP RTT 200–15000ms ⚠
  ↓
  Set state (синхронно)
  ↓
  If/Else
  ↓
  Presentation Agent (Continue / Ready / Unsafe)     ~300–900ms   ← ПОЛНОСТЬЮ ЛИШНИЙ ШАГ
  ↓
[Frontend renders]
```

**В сумме:** обычно 3–8 секунд до первого слова, иногда 15–60 секунд если попадает на photo-analysis. Половина — действительно нужная работа, половина — устранимый оверхед.

**Самые жирные находки:**

| # | Где | Текущая стоимость | Что делать |
|---|---|---|---|
| **A1** | `save_assistant_result` блокирует на photo-analysis (45s timeout) | до 15 сек на каждый save_assistant_routing_result | Сделать analyze_request **non-blocking** в этом пути; результат всё равно есть в `cached_analysis` |
| **A2** | Presentation Agents (Continue/Ready/Unsafe) — это **echo**, но запускают LLM-вызов | +0.3–1.0 сек / тёрн | Удалить из workflow целиком: возвращать `state.next_message` напрямую |
| **A3** | Prewarm session есть, но **результат не используется** при mount | первый `getClientSecret` уходит в OpenAI, а не в кэш | Передать prewarmed payload в `BRIDGE_CACHE` через `cachedSession` |
| **A4** | Input Safety Check блокирует Classification | +0.4–0.9 сек | Параллелизовать moderation с classification, jailbreak — пост-фактум блокировать |
| **A5** | Photo analysis на каждый save_assistant_result | до 45s timeout | Только из cache в этой ветке, тяжёлый анализ — отдельной асинхр. задачей |
| **A6** | `gpt-4.1-mini` для photo analysis, `gpt-5.4-mini` для classification | ~1.5–3с / вызов | Перейти на nano-варианты для коротких ответов; оставить mini только когда нужно reasoning |

После всех правок ожидаемое **time-to-first-token: 1.5–3 сек** на тёплой сессии (с реальным ответом ассистента, не с echo presentation).

---

## 1. Архитектурный диагноз — где сейчас работает плагин

### 1.1 Текущая схема Agent Builder workflow (по присланному коду)

```
Start
  → Input Safety Check  (moderation + jailbreak gpt-4.1-nano)
      Pass → Classification Agent (gpt-5.4-mini, reasoning low, store=true)
                ├─ tool: get_request_photo_context     [PHP REST]
                ├─ tool: get_request_pricing_context   [PHP REST]
                └─ tool: save_assistant_routing_result [PHP REST]
              → Set state
              → If/Else
                  state.unsafe == true        → Unsafe Presentation Agent (gpt-5.4) → End
                  state.enough_information!=true → Continue Intake Agent (gpt-5.4-nano) → End
                  else                          → Ready Presentation Agent (gpt-5.4-nano) → End
      Fail → Unsafe Presentation Agent
```

### 1.2 Plugin-side hot path (PHP)

| Endpoint | Что делает | Латентность | Замечания |
|---|---|---|---|
| `POST /chatkit-session` | создаёт сессию OpenAI ChatKit | ~600–1500 мс | timeout=15s, prewarm есть на step `address_details`, но **payload не используется** при реальном mount |
| `POST /photo-analysis` (warm) | OpenAI Vision (`gpt-4.1-mini`) | 3–20 сек | timeout=45s, max 4 photos detail=low |
| `POST /request-photo-context` | возвращает кешированный анализ + raw signals | ~50–200 мс | **может вызывать analyze_request** заново, если кеш пуст |
| `POST /request-pricing-context` | чистый PHP lookup в каталоге | ~30–80 мс | OK |
| `POST /assistant-result` | сохраняет routing + **снова вызывает analyze_request** ⚠ | ~100 мс — **до 15 сек** | Главный bottleneck save-стадии |
| `POST /chatkit-thread` | связывает thread с request_id | ~30–80 мс | OK |
| `POST /messages/record` | mirror chat в admin DB | ~30–80 мс | OK (B3) |

### 1.3 Bridge (frontend)

`assets/handik-chatkit-bridge.js` мостит ChatKit web component → REST endpoints. Использует `BRIDGE_CACHE` для cached client secret. Записывает thread_id, мирорит сообщения в admin (B3). **Сильное место** — много лога, много round-trips на client tools.

---

## 2. Критичные оптимизации (Priority 0, делать сразу)

### A1. Сделать `save_assistant_result` неблокирующим относительно photo analysis

**Проблема.** `class-chatkit-service.php:446`:

```php
$photo_analysis = $this->photo_analysis->analyze_request( $request, false );
$assistant      = $this->merge_assistant_result( ..., $photo_analysis ));
```

`analyze_request` синхронно вызывает OpenAI Vision API с `timeout=45s`. Это происходит **внутри обработчика `save_assistant_routing_result`**, то есть после того как ассистент уже сгенерировал ответ. Если фото не были warmup-нуты, юзер ждёт **дополнительные 5–15 сек** до того, как сообщение отрисуется — даже если визуальный анализ ему вообще не нужен для этого тёрна.

**Фикс.**

```php
// includes/services/class-chatkit-service.php — около строки 446
// Было:
$photo_analysis = $this->photo_analysis->analyze_request( $request, false );

// Стало: только cache; настоящий анализ запущен ещё в warm_photo_analysis().
$photo_analysis = $this->photo_analysis->cached_analysis( $request );
if ( empty( $photo_analysis ) && ! empty( $request['photos'] ) ) {
    // Schedule a single non-blocking refresh — return whatever cache has.
    wp_schedule_single_event( time() + 1, 'handik_booking_app_photo_analysis_refresh', array( (int) $request['id'] ) );
}
```

И зарегистрировать обработчик cron:

```php
add_action( 'handik_booking_app_photo_analysis_refresh', function( $request_id ) {
    $req = handik_booking_app()->job_requests->get( (int) $request_id );
    if ( $req ) {
        handik_booking_app()->photo_analysis->analyze_request( $req, true );
    }
} );
```

**Эффект.** -3…-15 сек на каждом save_assistant_routing_result. Анализ всё равно прогревается через `/photo-analysis` warm-call ещё на photos-step и при `prepareAssistantStep`.

---

### A2. Удалить Presentation Agents

**Проблема.** В коде workflow:

```ts
const continueIntakePresentationAgentInstructions = (runContext) => {
  const { stateNextMessage } = runContext.context;
  return `Output the following text exactly as-is, without any rewriting...
${stateNextMessage}`
}
```

Все три presentation-агента — **просто echo**. Они принимают `state.next_message` (который Classification Agent уже сгенерировал) и инструкцией "вывести как есть" заставляют LLM скопировать строку. Это:

- лишний API-call в OpenAI (300–1000 мс)
- лишние токены
- лишняя точка отказа
- **Unsafe Presentation Agent на gpt-5.4** — ещё дороже без причины

**Фикс.** Заменить три presentation-агента на статический output node, который просто возвращает строку:

```ts
// Вместо: await runner.run(continueIntakePresentationAgent, ...)
return { output_text: state.next_message ?? '' };
```

Или, если ваш Agent Builder требует обязательный финальный node — сделать **structured output node без LLM** (большинство фреймворков это поддерживают, в OpenAI Agents — `Agent({ outputType, instructions: 'echo' })` всё равно дёргает модель; лучше pure-function node).

**Эффект.** **-300...-1000 мс** на каждом тёрне ассистента. На безопасной ветке Unsafe — **-1500...-3000 мс** (там gpt-5.4). Почти "free win".

---

### A3. Реально использовать prewarm session

**Проблема.** Frontend на step `address_details` вызывает:

```js
this.assistantSessionPrewarmPromise = this.api( 'chatkit-session', { request_id, draft_token } )
    .then( ( payload ) => { /* logged, but payload thrown away */ } );
```

Полученный `client_secret` **никуда не сохраняется**. Когда пользователь дойдёт до step `assistant`, mountAssistant вызовет bridge, который через `getClientSecret()` сделает **второй** create-session запрос. Prewarm работает, но впустую — экономии 0.

**Фикс.** Передать prewarmed payload в bridge:

```js
// В prewarmAssistantSession (booking-app.js:1637):
this.assistantSessionPrewarmPromise = this.api( 'chatkit-session', {...} )
    .then( ( payload ) => {
        this.assistantPrewarmedSession = payload;  // ← cache it
        return payload;
    } );

// В mountAssistant (booking-app.js:2312):
this.assistantBridge = window.HandikChatKitBridge.mount( {
    container, requestId, draftToken,
    cacheKey: 'request_' + String( this.state.requestId ),
    prewarmedSession: this.assistantPrewarmedSession || null,  // ← pass it
    ...
} );
```

В bridge — на старте mount-а заполнить `record.cachedSession`:

```js
// handik-chatkit-bridge.js, рядом со строкой 249 (BRIDGE_CACHE.set)
if ( options.prewarmedSession ) {
    record.cachedSession = options.prewarmedSession;
    record.session = options.prewarmedSession;  // file_upload config etc.
}
```

**Эффект.** Первый `getClientSecret` отдаст cached, без round-trip — экономия **600–1500 мс** на самом первом mount-е (типичный пользователь видит ChatKit готовым на ~1 сек раньше). При навигации backward/forward между шагами эффект уже работал через `BRIDGE_CACHE`.

---

### A4. Параллелизовать Input Safety Check с Classification

**Проблема.** Сейчас порядок строго последовательный:

```
Input Safety Check (400–900ms) → Classification (1.5–4s)
```

Moderation — это быстрая проверка, jailbreak — медленный gpt-4.1-nano. Они блокируют ввод даже при безопасных стандартных запросах ("can you replace a faucet?"), которые проходят moderation за <100мс.

**Фикс.** Несколько вариантов, в порядке усиления:

**4a. Optimistic parallel run** (требует поддержки в Agent Builder):
- Запускать moderation+classification **параллельно**.
- Если moderation падает (tripwire) — отменить classification, вернуть unsafe.
- Если classification закончил первым и safety тоже OK — отдавать classification.

**4b. Snappy fast-path**: разделить guardrails на быстрые и медленные:
- **Pre-classification** (блокирующий): только moderation `categories: [hate/threatening, violence/graphic, sexual/minors, illicit/violent]`. Это ~50–150 мс на нативной OpenAI-стороне.
- **Post-classification** (асинхронно для логирования): jailbreak. Если он сработает на пост-проверке — добавить в админский лог и пометить request `unsafe_flag=1`, но первый ответ уже отдан.

**4c. Skip jailbreak для returning customers** — если `is_returning_client=true` и история чата чистая, jailbreak можно опустить.

**Эффект.** **-300...-700 мс** на старте чата.

---

### A5. Photo analysis — только один путь блокирует пользователя

**Текущее.** `analyze_request` может быть вызван:
1. `warm_photo_analysis` (REST `/photo-analysis`) — frontend вызывает в `prepareAssistantStep`. Хорошо.
2. `request_photo_context` (REST `/request-photo-context`) — fallback в client tool. Если кеш пуст, fallback в analyze_request. Может блокировать tool round-trip.
3. `save_assistant_result` (REST `/assistant-result`) — **самое плохое место**, см. A1.

**Фикс.**

В `request_photo_context`:

```php
// includes/services/class-chatkit-service.php около строки 232
if ( $has_photos ) {
    $analysis = $this->photo_analysis->cached_analysis( $request );
    if ( empty( $analysis ) ) {
        // Не запускаем синхронно — не блокируем tool-call.
        // Возвращаем "анализ ещё готовится", агент сам решит, нужно ли подождать.
        $payload['photo_analysis_status'] = 'processing';
        $payload['has_actionable_visual_context'] = false;
        // Запустить async refresh, если warm не успел.
        wp_schedule_single_event( time() + 1, 'handik_booking_app_photo_analysis_refresh', array( (int) $request['id'] ) );
    }
}
```

И обновить инструкции Classification Agent: «если `photo_analysis_status === 'processing'`, ответь без визуального контекста, попроси клиента подождать пару секунд». Большинство юзеров фото отправили раньше, и warm-up уже отработал.

**Эффект.** Tool-call больше никогда не висит 5–15 сек.

---

### A6. Понизить модели где можно

**Текущее:**

| Где | Модель | Reasoning effort | Цель |
|---|---|---|---|
| Classification Agent | `gpt-5.4-mini` | low | основная routing-логика |
| Continue/Ready Presentation | `gpt-5.4-nano` | none | echo |
| Unsafe Presentation | `gpt-5.4` | low | короткое сообщение об отказе |
| Jailbreak guardrail | `gpt-4.1-nano` | n/a | safety |
| Photo analysis | `gpt-4.1-mini` | n/a | vision |

**Фикс.**

- **Unsafe Presentation: `gpt-5.4` → удалить (см. A2)**, или хотя бы `gpt-5.4-nano`. Это просто короткое сообщение об отказе.
- **Photo analysis: `gpt-4.1-mini` → пробовать `gpt-4.1-nano`** для quick-mode (1–2 photos, обычное hands-on фото). Mini оставить только когда photos>2 или mime=heic. Можно сделать adaptive выбор:
  ```php
  $model = ( count( $photos ) > 2 ) ? 'gpt-4.1-mini' : 'gpt-4.1-nano';
  ```
- **Classification: оставить `gpt-5.4-mini`**, но рассмотреть `reasoning.effort: 'minimal'` если ваш SDK поддерживает (для следующих тёрнов в той же сессии — там контекст уже есть).
- **Jailbreak threshold 0.85** — повышенный confidence_threshold снизит false positives и не будет блокировать пограничные запросы.

**Эффект.** **-200...-600 мс** на photo-analysis warm. -400 мс на unsafe path.

---

## 3. Plugin-side оптимизации (Priority 1)

### B1. Кешировать `verify_draft_token` в рамках одного запроса

`Job_Requests_Service::verify_draft_token` использует `wp_check_password`, который запускает PHPass / phpass-stretches — это **намеренно медленно** (50–200 мс). Каждый эндпоинт ассистента её вызывает: `chatkit-session`, `request-photo-context`, `request-pricing-context`, `assistant-result`, `chatkit-thread`, `messages/record`. Один тёрн = ~6 round-trips, каждый по 50–200 мс на хеш.

**Фикс.** Ин-request memoization:

```php
// class-job-requests-service.php
protected $verified_tokens = array();

public function verify_draft_token( $request_id, $draft_token ) {
    $key = (int) $request_id . '|' . $draft_token;
    if ( isset( $this->verified_tokens[ $key ] ) ) {
        return $this->verified_tokens[ $key ];
    }
    $row = $this->get( $request_id );
    $ok  = $row && ! empty( $row['draft_token_hash'] ) && ! empty( $draft_token )
         && wp_check_password( $draft_token, $row['draft_token_hash'] );
    $this->verified_tokens[ $key ] = $ok;
    return $ok;
}
```

**Эффект.** На одном PHP-процессе верификация почти бесплатна со второго вызова. Сами round-trips это не уберёт, но снимет **50–200 мс с каждого** REST-вызова после первого.

---

### B2. Прокинуть thread_id и контекст в один батч-эндпоинт

Сейчас при первом сообщении frontend дёргает: `chatkit-session` → `chatkit-thread` (когда thread открылся) → `request-photo-context` (когда агент тулом запросил) → `request-pricing-context` (когда тул запросил) → `assistant-result` (когда финал) → `messages/record` ×N.

Каждый из них — отдельный HTTP + verify_draft_token + db reads.

**Фикс (medium effort).** Добавить `POST /handik-booking-app/v1/assistant-bootstrap`, который за один call возвращает: client_secret + photo_context + pricing_context + thread_id (если есть). Передавать всё в `state_variables` ChatKit-сессии — в `class-chatkit-service.php:62` уже есть `state_variables`. Если pre-fetch все контексты и положить туда, **агенту не нужно вызывать tools** для photo/pricing на первом тёрне.

```php
'state_variables' => array_merge(
    $this->state_variables( $request ),
    array(
        'photo_context'   => $this->build_photo_context_payload( $request, $cached_analysis ),
        'pricing_context' => $this->build_pricing_context_payload( $request ),
    )
),
```

И в инструкциях Classification Agent: "Если `state.photo_context` уже есть, **не вызывай get_request_photo_context**".

**Эффект.** **-300...-600 мс** на каждый ассистент-тёрн (2 tool round-trips отпали). Особенно ценно на mobile с медленным CPU/сетью.

---

### B3. Stream ответ агента (если ChatKit не делает этого уже)

ChatKit-фреймворк OpenAI обычно поддерживает streaming — токены приходят инкрементально. Проверьте, что:

- `record.element` (web component) рендерит токены по мере прихода (это его дефолт).
- Ваши presentation agents **не** буферизуют (они echo, и при удалении этого вообще не вопрос).

Если структурированный output (Classification Agent отдаёт JSON) приходит как один блок — это нормально для structured output, но **`next_message`** должен стримиться отдельно. Если ChatKit Web component уже делает это — оставьте. Если нет, рассмотреть переход с `outputType: ZodSchema` на гибридный режим: stream `next_message` как plain text, structured-payload — финальным.

---

### B4. Выкинуть избыточные client_log вызовы

Bridge шлёт `clientLog` info/debug на каждое событие ChatKit. Это:
- HTTP round-trip × ~10 на тёрн
- проходит через rate-limiter (`/client-log` 60/min)
- засоряет `wp_options`

**Фикс.** Bridge:

```js
// в clientLog (handik-chatkit-bridge.js)
function clientLog(level, message, context) {
    if ( level === 'debug' && !window.HandikDebugMode ) return;  // skip
    // batch info-уровня по 1с/8 событий и слать одним call-ом
}
```

Или просто `level === 'debug'` всегда дропать на проде. Можно добавить **batching**: накапливать события 1 сек и отправлять одним `/client-log-batch`.

**Эффект.** Меньше шума, меньше нагрузки на сервер, чище логи.

---

### B5. Сделать `analyze_request` идемпотентным в рамках одного процесса

Photo analysis может быть вызван несколько раз в одном request (`request-photo-context` + `assistant-result`). Каждый раз идёт `cached_analysis()` запрос к БД + signature compare. Кешировать в-памяти на одну сессию PHP:

```php
protected $analysis_cache = array();

public function analyze_request( array $request, $force = false ) {
    $rid = (int) $request['id'];
    if ( ! $force && isset( $this->analysis_cache[ $rid ] ) ) {
        return $this->analysis_cache[ $rid ];
    }
    // ...
    $this->analysis_cache[ $rid ] = $result;
    return $result;
}
```

**Эффект.** -50…-200 мс по сумме при одном тёрне с tool-calls.

---

## 4. Frontend оптимизации (Priority 2)

### C1. Lazy-load ChatKit web component

`HandikChatKitBridge` сейчас грузится через `<script>` тег вместе со всеми остальными ассетами. Если страница долго рендерится, это блокирует rendering.

**Фикс.**
- Defer-loading: `<script src="..." defer>`.
- Или динамически грузить bridge только когда юзер дошёл до step `task_selection` (первый шаг где он точно нажмёт next).

### C2. Preload OpenAI ChatKit CDN на step `photos`

Добавить preconnect/dns-prefetch:

```html
<link rel="preconnect" href="https://api.openai.com" crossorigin>
<link rel="preconnect" href="https://chatkit.openai.com" crossorigin>  <!-- если используется -->
<link rel="dns-prefetch" href="https://api.openai.com">
```

Должно быть в `enqueue_frontend()` в `class-assets.php` — добавить через `wp_resource_hints` filter.

**Эффект.** -50…-200 мс на TLS handshake к OpenAI на первой mount-е.

### C3. Skeleton для assistant-host пока bridge mounting

Уже частично есть в админке (мой 2.1.8.5 рендерит `.handik-skeleton`), но на фронте `class-assets.php:114` — `loadingTitle/loadingSubtitle` показываются как `Loading virtual assistant...`. Добавить shimmer-skeleton (CSS уже есть для админки), это уменьшит **perceived latency** даже если фактическая не уменьшилась.

### C4. Debounce save_draft на photo step

`save_draft` вызывается на каждом изменении полей. На photos step юзер может загружать 3-5 файлов подряд — каждый upload триггерит save_draft. Дебаунсить уже есть для address (300ms), сделать так же для photos (500ms).

---

## 5. Workflow / Agent Builder (Priority 1)

### D1. Изменить топологию

Текущая (упрощённо):
```
Start → Safety → Classification → Set state → If/Else → Presentation → End
```

Предлагаемая:
```
Start
 → [parallel: Moderation only] [Classification with all context in state_variables]
 → If unsafe: static "unsafe" message (no LLM)
 → Else: pass through state.next_message (no LLM)
 → End
```

Это убирает 1 LLM-call на presentation (~300-1000 мс) и параллелит safety с classification (~400-700 мс).

### D2. Уменьшить системный промпт Classification Agent

Текущий system prompt — **~6000 токенов** (видно по объёму). Это:
- ест бюджет каждого тёрна
- увеличивает latency процессинга
- увеличивает cost

**Фикс.** Разбить на:
- Тонкий **base prompt** (~1500 токенов) — роль, базовые правила, формат вывода.
- **Knowledge files** через File Search или vector store: routing rules, pricing rules, examples — подгружаются tool-ом только когда нужно.

Если вы используете Anthropic SDK + prompt caching — **кешируйте system prompt** (см. соответствующий guide). Для OpenAI Agents SDK это **automatic prompt caching** — но он работает только если prompt стабилен. Сейчас он стабилен, **должно кешироваться**. Проверьте traces: `cached_input_tokens` должно быть >0 на втором и далее вызовах.

**Эффект.** При prompt caching: -50% prompt cost, -200…-500 мс на TTFB после первого вызова в сессии.

### D3. Перевести photo/pricing tools в state_variables (см. B2)

Если контексты прокинуты в `state_variables` при создании сессии, агенту НЕ нужно их получать tool-ом. Это убирает 2 tool round-trips × ~150-400 мс = **300-800 мс** экономии на типичный тёрн.

Но: оставьте tools как fallback для тёрнов 2+, когда юзер изменил детали — там state стал stale.

### D4. Сократить `next_message` где можно

Промпт сейчас велит выдавать длинные сообщения с полным разбором цены. Это:
- больше токенов = дольше генерация (~50-100 мс на каждые 100 токенов)
- больше времени на чтение пользователем

**Фикс.** Добавить параметр `verbosity` или сделать варианты:
- **brief mode**: «This looks like a standard visit, ~1–2h. Continue to booking.»
- **detailed mode**: с полным разбором (текущий вариант), только когда юзер явно спросил про цену.

### D5. Понизить max_tokens / реквестировать reasoning summary только когда нужно

`modelSettings.reasoning.summary: "auto"` для presentation-агентов — лишнее, они и не reasoning-задача. Удалите там.

---

## 6. Backend hot-paths (Priority 2)

### E1. Кешировать `service_catalog` на одну страницу

В `build_pricing_context_payload` каждый раз вызывается `handik_booking_app()->service_catalog->find_task( $task_id )` (`class-chatkit-service.php:692`). Service Catalog уже кешируется (см. ваш fix в 0a50223 — `flush_cache`/`catalog_cache`), но это **per-instance**. На каждом REST-call плагин bootstrap-ится заново, так что catalog парсится. Это ~5-15 мс.

**Фикс.** Добавить wp transient (1 час) на parsed catalog:

```php
public function get_catalog() {
    if ( null !== $this->catalog_cache ) {
        return $this->catalog_cache;
    }
    $cached = get_transient( 'handik_booking_app_catalog_v1' );
    if ( is_array( $cached ) ) {
        return $this->catalog_cache = $cached;
    }
    // ... existing parse logic ...
    set_transient( 'handik_booking_app_catalog_v1', $catalog, HOUR_IN_SECONDS );
    return $catalog;
}
```

Invalidate в `flush_cache()` — добавить `delete_transient( 'handik_booking_app_catalog_v1' )`.

### E2. Index добавить на `selected_tasks_json` — нет, нельзя на JSON-колонку напрямую

Но можно для `count_references_for_tasks` (мой code из admin) добавить **generated column** или materialized side-table если catalog editor станет популярным. Сейчас задержка приемлемая.

### E3. OpenAI HTTP — заменить wp_remote_post на wp_remote_request с keep-alive

`wp_remote_post` создаёт новое cURL соединение каждый раз. Под нагрузкой можно переиспользовать TLS-соединение через persistent cURL handle.

**Фикс (advanced).** Добавить `'httpversion' => '1.1'` (по умолчанию 1.0!). Это включает keep-alive в WP HTTP API:

```php
// в wp_remote_post calls в class-chatkit-service.php и class-photo-analysis-service.php
$response = wp_remote_post( $url, array(
    'headers'     => $headers,
    'timeout'     => 15,
    'httpversion' => '1.1',   // ← добавить
    'body'        => ...,
) );
```

**Эффект.** -50…-150 мс TLS handshake на повторных вызовах в одной PHP-сессии (не очень велик, но бесплатный).

### E4. Логи — не писать каждый info на тёрне

`Logger::info()` обновляет `wp_options` (`update_option`) при каждом вызове. На один ассистент-тёрн пишется 5-15 info-записей. Каждый `update_option` — это **DB write + autoload cache invalidation**.

**Фикс.** Buffer-logger: накапливать info+ записи в memory и flushить одним `update_option` в `shutdown` action:

```php
class Handik_Booking_App_Logger {
    protected $pending_logs = array();
    protected $shutdown_registered = false;

    public function info( $message, array $context = array() ) {
        $this->buffer( 'info', $message, $context );
    }

    protected function buffer( $level, $message, array $context ) {
        // build $entry as before
        $this->pending_logs[] = $entry;
        error_log( '[Handik] ' . wp_json_encode( $entry ) );
        if ( ! $this->shutdown_registered ) {
            add_action( 'shutdown', array( $this, 'flush' ), 99 );
            $this->shutdown_registered = true;
        }
    }

    public function flush() {
        if ( empty( $this->pending_logs ) ) return;
        $logs = $this->get_logs();
        foreach ( $this->pending_logs as $e ) $logs[] = $e;
        // apply per-level retention as before
        update_option( self::OPTION_NAME, $logs, false );
        $this->pending_logs = array();
    }
}
```

**Эффект.** -100…-300 мс совокупно за тёрн. Особенно на старых хостингах с медленным MySQL. Также снимает риск race-condition при concurrent writes.

---

## 7. Что НЕ трогать (good already)

- `BRIDGE_CACHE` (frontend session reuse) — работает.
- `cached_analysis` структура и signature-based invalidation — корректно.
- ChatKit web component сам по себе — это OpenAI's hosted, оптимизировать его мы не можем.
- `verify_draft_token` через `wp_check_password` — медленно намеренно (защита от bruteforce). С memoization (см. B1) проблема снимается.
- Транзиент на dashboard counts (60s) — корректно.

---

## 8. Метрики, которые надо завести (чтобы потом измерить эффект)

Прежде чем что-то менять, **поставьте бенчмарки**. Без них нельзя честно сказать «стало быстрее».

### 8.1 Backend latency

В `class-chatkit-service.php` обернуть каждый OpenAI call:

```php
$start = microtime( true );
$response = wp_remote_post( ... );
$elapsed = (int) ( ( microtime( true ) - $start ) * 1000 );
$this->logger->info( 'OpenAI ChatKit session', array(
    'latency_ms' => $elapsed,
    'status'     => wp_remote_retrieve_response_code( $response ),
) );
```

Аналогично в photo-analysis-service.php. После ваших изменений — фильтровать логи в админке `?tab=logs&q=latency_ms` и смотреть P50/P95.

### 8.2 Frontend Web Vitals

Добавить в booking-app.js трекинг времени:
- `first_input → composer.submit` (UX-снап)
- `composer.submit → first assistant token`
- `composer.submit → onComplete`

Через `performance.mark()` + лог в client-log.

### 8.3 Agent Builder traces

OpenAI Agent Builder автоматически собирает traces. Зайти в OpenAI dashboard → Traces, смотреть workflow `Handik Agent`:
- сколько занимает каждая нода
- сколько `cached_input_tokens` (prompt cache hit)
- какие tools агент дёргает

Это самый честный источник latency-данных для Agent Builder части.

---

## 9. Дорожная карта

### Спринт 1 (1–2 дня) — самые жирные wins

- [ ] **A1** non-blocking photo analysis в `save_assistant_result` (class-chatkit-service.php:446)
- [ ] **A2** Удалить Presentation Agents из workflow (или заменить на static output node)
- [ ] **A3** Pass prewarmedSession в bridge mount (booking-app.js + handik-chatkit-bridge.js)
- [ ] **B1** Memoize `verify_draft_token` per-request
- [ ] **E3** `httpversion => '1.1'` во всех OpenAI calls

**Ожидаемый эффект:** -50% time-to-first-token (с ~5с до ~2.5с медианно).

### Спринт 2 (2–3 дня) — меньшие, но важные

- [ ] **A4** Параллелизовать moderation (через workflow-redesign в Agent Builder)
- [ ] **A5** Photo analysis только из cache в request_photo_context, фоновый refresh
- [ ] **A6** Понизить модели: photo→nano (когда photos<=2), unsafe presentation → удалить или nano
- [ ] **B2** state_variables преcontext (photo + pricing) в session-create
- [ ] **D2** Вынести часть инструкций Classification в file_search vector store (если объём промпта > 3000 токенов)
- [ ] **E4** Buffer-logger через shutdown action

**Ожидаемый эффект:** -25% дополнительно (с ~2.5с до ~1.8с медианно).

### Спринт 3 (1–2 дня) — polish

- [ ] **B4** Bridge logs: дропать debug на проде, batching info
- [ ] **C1** Lazy-load HandikChatKitBridge
- [ ] **C2** preconnect к api.openai.com на step photos
- [ ] **C3** Skeleton-shimmer для assistant-host
- [ ] **E1** Transient на parsed catalog
- [ ] **8.1/8.2/8.3** инструментировать метрики

---

## 10. Изменение по конкретным файлам — карта

| Файл | Что делать | Приоритет |
|---|---|---|
| `includes/services/class-chatkit-service.php:446` | analyze_request → cached_analysis + cron | **P0** |
| `includes/services/class-chatkit-service.php:62` | в state_variables прокинуть photo + pricing context | **P1** |
| `includes/services/class-chatkit-service.php:104, photo-analysis:144` | `httpversion => '1.1'` | **P1** |
| `includes/services/class-photo-analysis-service.php:8` | model: gpt-4.1-nano для ≤2 photos | **P1** |
| `includes/services/class-job-requests-service.php` | memoize verify_draft_token | **P0** |
| `includes/class-logger.php` | buffer + shutdown flush | **P2** |
| `includes/services/class-service-catalog-service.php` | wp_transient на 1 час | **P2** |
| `assets/booking-app.js:1637` | сохранить prewarmed session, передать в mount | **P0** |
| `assets/handik-chatkit-bridge.js:249` | accept options.prewarmedSession | **P0** |
| `assets/handik-chatkit-bridge.js (clientLog)` | drop debug на проде | **P2** |
| `includes/class-assets.php` | preconnect/dns-prefetch к OpenAI | **P2** |
| **Agent Builder workflow** | удалить 3 Presentation Agents | **P0** |
| **Agent Builder workflow** | параллелить moderation+classification | **P1** |
| **Agent Builder workflow** | разбить system prompt: base + file_search | **P1** |
| **Agent Builder workflow** | Unsafe Agent: gpt-5.4 → удалить или nano | **P0** |

---

## 11. Контрольный список перед деплоем

- [ ] PHP syntax `php -l` на всех изменённых файлах
- [ ] JS `node --check` на всех изменённых файлах
- [ ] Smoke test: пройти полный booking flow от start до booking confirmation
- [ ] Замерить P50/P95 time-to-first-token до/после на staging с живым трафиком (минимум 50 тёрнов)
- [ ] Проверить admin → Logs: нет новых error-уровневых записей
- [ ] Сравнить cost в OpenAI dashboard за неделю до/после — должно упасть на 20-40%

---

## 12. Что я бы сделал прямо сейчас, если у меня есть только 30 минут

**Топ-3 правки с наилучшим ROI**:

1. **Убрать Presentation Agents** в Agent Builder. 5 минут работы, 300-1000 мс экономии на каждом тёрне.
2. **Прокинуть prewarmedSession в bridge** (A3). 15 минут, 600-1500 мс на первом mount.
3. **`save_assistant_result` → cached_analysis only** (A1). 10 минут, до 15 секунд экономии в худшем случае.

Эти три выровняют ситуацию из «иногда 10+ секунд» в «стабильно 2-3 секунды».

---

*Готовлю PR с конкретными правками — скажите какой спринт начать первым.*
