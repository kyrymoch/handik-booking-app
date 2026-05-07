<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Migrations {
	const OPTION_NAME      = 'handik_booking_app_db_version';
	const LAST_RUN_OPTION  = 'handik_booking_app_db_last_run';

	/**
	 * @var array<string, string>
	 */
	protected $map = array(
		'1.0.0' => 'Handik_Booking_App_Migration_100',
		'1.1.0' => 'Handik_Booking_App_Migration_110',
		'1.2.0' => 'Handik_Booking_App_Migration_120',
		'1.3.0' => 'Handik_Booking_App_Migration_130',
		'1.4.0' => 'Handik_Booking_App_Migration_140',
		'1.4.1' => 'Handik_Booking_App_Migration_141',
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
			update_option( self::LAST_RUN_OPTION, current_time( 'mysql' ), false );
		}
	}
}
