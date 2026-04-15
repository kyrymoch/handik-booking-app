<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Migrations {
	const OPTION_NAME = 'handik_booking_app_db_version';

	/**
	 * @var array<string, string>
	 */
	protected $map = array(
		'1.0.0' => 'Handik_Booking_App_Migration_100',
		'1.1.0' => 'Handik_Booking_App_Migration_110',
	);

	public function migrate() {
		$current = (string) get_option( self::OPTION_NAME, '0.0.0' );
		$target  = HANDIK_BOOKING_APP_DB_VERSION;

		foreach ( $this->map as $version => $class_name ) {
			if ( version_compare( $version, $current, '<=' ) ) {
				continue;
			}

			if ( version_compare( $version, $target, '>' ) ) {
				continue;
			}

			$migration = new $class_name();
			$migration->up();
			update_option( self::OPTION_NAME, $version, false );
		}
	}
}
