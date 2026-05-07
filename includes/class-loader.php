<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Loader {
	public static function load() {
		$files = array(
			'includes/class-logger.php',
			'includes/class-settings.php',
			'includes/db/class-db.php',
			'includes/db/class-migrations.php',
			'includes/db/migrations/class-migration-100.php',
			'includes/db/migrations/class-migration-110.php',
			'includes/db/migrations/class-migration-120.php',
			'includes/db/migrations/class-migration-130.php',
			'includes/db/migrations/class-migration-140.php',
			'includes/db/migrations/class-migration-141.php',
			'includes/services/class-changelog-service.php',
			'includes/services/class-appearance-service.php',
			'includes/services/class-service-catalog-service.php',
			'includes/services/class-contacts-service.php',
			'includes/services/class-addresses-service.php',
			'includes/services/class-job-requests-service.php',
			'includes/services/class-bookings-service.php',
			'includes/services/class-messages-service.php',
			'includes/services/class-auth-service.php',
			'includes/services/class-routing-service.php',
			'includes/services/class-cal-service.php',
			'includes/services/class-photo-analysis-service.php',
			'includes/services/class-chatkit-service.php',
			'includes/services/class-updater-service.php',
			'includes/services/class-webhook-service.php',
			'includes/forms/class-cal-api-service.php',
			'includes/forms/class-booking-presets-service.php',
			'includes/forms/class-direct-booking-service.php',
			'includes/forms/class-project-schedule-service.php',
			'includes/forms/class-forms-rest-api.php',
			'includes/forms/class-forms-router.php',
			'includes/app/class-app-state.php',
			'includes/app/class-app-schema.php',
			'includes/app/class-upload-service.php',
			'includes/app/class-app-controller.php',
			'includes/class-assets.php',
			'includes/class-frontend-app.php',
			'includes/class-shortcode.php',
			'includes/class-api-response.php',
			'includes/class-rest-api.php',
			'includes/admin/class-admin-helpers.php',
			'includes/admin/class-admin-dashboard.php',
			'includes/admin/class-admin-bookings.php',
			'includes/admin/class-admin-people.php',
			'includes/admin/class-admin-settings.php',
			'includes/admin/class-admin-logs.php',
			'includes/admin/class-admin-system.php',
			'includes/admin/class-admin-integrations.php',
			'includes/admin/class-admin-additional-forms.php',
			'includes/class-admin.php',
			'includes/class-widget-registry.php',
			'includes/class-plugin.php',
		);

		foreach ( $files as $file ) {
			require_once HANDIK_BOOKING_APP_PATH . $file;
		}
	}
}
