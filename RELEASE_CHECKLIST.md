# Release Checklist

Step-by-step for cutting a new release. Three files must stay in lockstep on every version bump.

---

## 1. Decide the version number

Use semantic-style bumps:

| Bump | When                                                                 | Example       |
|------|----------------------------------------------------------------------|---------------|
| Major (`X.0.0`) | Breaking API change, full rewrite                          | n/a (locked at 2.x) |
| Minor (`x.Y.0`) | New user-visible feature OR DB migration                   | `2.1.27.0` → `2.1.28.0` (reschedule feature) |
| Patch (`x.y.Z`) | Bug fix, no schema change, no new endpoint                 | `2.1.26.5` → `2.1.26.6` (wp_tempnam fix) |

The plugin DB version (`HANDIK_BOOKING_APP_DB_VERSION`) follows its own track and only bumps when a new migration ships. It doesn't have to match the plugin version.

---

## 2. Bump version in three files

All three MUST match — the GitHub-release updater compares these for upgrade detection.

### `handik-booking-app.php` (top of file)

```php
/**
 * Plugin Name: Handik Booking App
 * ...
 * Version: 2.1.28.0   ← header
 */

define( 'HANDIK_BOOKING_APP_VERSION', '2.1.28.0' );  ← constant
```

If shipping a migration:

```php
define( 'HANDIK_BOOKING_APP_DB_VERSION', '1.6.1' );  ← bump if new migration class
```

### `package.json`

```json
{
  "version": "2.1.28.0"
}
```

### `readme.txt`

```
Stable tag: 2.1.28.0
```

And add the new version's changelog entry to the `== Changelog ==` section.

---

## 3. Write the changelog entry

In `readme.txt`, prepend the entry above the previous version:

```
== Changelog ==

= 2.1.28.0 =
* **<short title>.** <One-paragraph summary of WHY this matters>
* <Detail line>
* <Detail line>
...

= 2.1.27.0 =
...
```

Tone: owner-facing. Lead with the user-visible effect, then the engineering detail. Reference owner-reports when they exist ("Owner-reported: ...") so the audit trail stays.

---

## 4. Commit the version bump

The release commit is a single commit that includes:

* The three version bumps
* The changelog entry
* The actual feature/fix code

Subject line: `X.Y.Z: <one-line description>`. Body: multi-paragraph explanation of WHY, NOT what (diff shows what).

If the feature was developed across multiple commits on a feature branch, squash-merge or keep all commits but ensure the final commit on `main` is the version bump.

---

## 5. Merge → main

* Open a PR from the feature branch to `main`.
* Self-review the diff one last time. Pay attention to:
  - Version numbers match across all three files
  - Migration is registered in BOTH `class-migrations.php` AND `class-loader.php`
  - No `console.log` / `var_dump` / `error_log` debug statements left in
  - No hardcoded test data / personal info in committed files
* Merge. `main` is always shippable.

---

## 6. Tag the release

```bash
git checkout main
git pull
git tag v2.1.28.0
git push origin v2.1.28.0
```

The leading `v` matters — the GitHub-release updater regex expects it.

---

## 7. Publish the GitHub release

* GitHub → Releases → "Draft a new release"
* Tag: `v2.1.28.0` (existing)
* Release title: `v2.1.28.0`
* Description: copy the matching `readme.txt` changelog entry
* **Asset**: attach a ZIP of the plugin folder named `handik-booking-app.zip`. The WordPress updater downloads this exact filename — if it's missing or named differently, auto-update fails silently.

ZIP build:

```bash
# From the plugin's parent dir (so the zip contains a top-level handik-booking-app/ folder)
cd ..
zip -r handik-booking-app.zip handik-booking-app/ \
  -x "handik-booking-app/.git/*" \
  -x "handik-booking-app/node_modules/*" \
  -x "handik-booking-app/.github/*"
```

Verify the ZIP:

```bash
unzip -l handik-booking-app.zip | head -20
# Should show handik-booking-app/handik-booking-app.php with the new Version: header
```

* "Publish release."

---

## 8. Verify the updater finds it

On a site running the previous version:

* `Handik Booking → System` → "Check for updates" button (or wait — checks every 12h)
* WordPress Plugins screen should show "Update available" within a few minutes
* Click "Update now"
* After update, plugin version on Plugins page = new version
* `Handik Booking → System` → "Last migration ran" updates if a migration shipped

If the updater doesn't pick up the new version:

* GitHub release must be `Published`, not `Draft`
* The asset filename must be exactly `handik-booking-app.zip`
* The tag name must start with `v` and be a strict semver suffix
* `Handik Booking → Settings → Updater` — verify the GitHub repo / token settings are right (token only needed for private repos)

---

## 9. Smoke-test in production

After the auto-update lands:

1. Open the public booking page → start a booking → make sure the SPA boots, the assistant step works, Cal embed loads
2. Admin → Bookings → confirm the list renders + status pills correct
3. Admin → Logs → no new red errors since the deploy
4. If a migration shipped: Admin → System → confirm `Last migration ran` timestamp is recent and `Current DB version` matches the new constant

If anything breaks, the previous version's release ZIP is still on GitHub — admin can roll back via "Plugins → Delete" + manual install of the old ZIP. Migrations are one-way; rolling code back is safe as long as the schema additions don't break the older code (they shouldn't, because additions are always NULL-able / optional).

---

## 10. Close the loop

* Tell the owner the release shipped (commit URL or GitHub release URL).
* Delete the feature branch on the remote.
* If the release introduced a follow-up item (deferred work mentioned in the changelog), open a tracker note for it.
