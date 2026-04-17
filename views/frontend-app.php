<?php
/**
 * @var array<string, mixed> $view_args
 */
?>
<?php if ( ! empty( $view_args['custom_css'] ) ) : ?>
<style><?php echo esc_html( $view_args['custom_css'] ); ?></style>
<?php endif; ?>
<div
	id="<?php echo esc_attr( $view_args['instance_id'] ); ?>"
	class="handik-booking-app"
	data-display="<?php echo esc_attr( $view_args['display'] ); ?>"
	style="<?php echo esc_attr( $view_args['style'] ); ?>"
>
	<div class="handik-booking-app__shell">
		<div class="handik-booking-app__loading"><?php echo esc_html( $view_args['title'] ); ?></div>
	</div>
</div>
