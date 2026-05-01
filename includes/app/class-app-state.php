<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_State {
	/**
	 * @var Handik_Booking_App_Service_Catalog_Service
	 */
	protected $service_catalog;

	/**
	 * @param Handik_Booking_App_Service_Catalog_Service $service_catalog Catalog.
	 */
	public function __construct( $service_catalog ) {
		$this->service_catalog = $service_catalog;
	}

	/**
	 * @return array<int, string>
	 */
	public function steps() {
		return array(
			'task_selection',
			'photos',
			'client_type',
			'returning_verify',
			'address_details',
			'contact_details',
			'assistant',
			'booking',
			'unsafe',
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function task_catalog() {
		return $this->service_catalog->get_catalog();
	}
}
