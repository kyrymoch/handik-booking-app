<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Schema {
	/**
	 * @return array<string, mixed>
	 */
	public function default_state() {
		return array(
			'step'               => 'client_type',
			'clientType'         => '',
			'verifiedProfile'    => null,
			'requestId'          => 0,
			'draftToken'         => '',
			'selectedTasks'      => array(),
			'isProject'          => false,
			'jobShape'           => '',
			'preferredTimeframe' => '',
			'address'            => array(
				'address_id'     => 0,
				'address_full'   => '',
				'address_line_1' => '',
				'city'           => '',
				'state'          => '',
				'zip_code'       => '',
			),
			'photos'             => array(),
			'shortDescription'   => '',
			'assistantResult'    => null,
			'contact'            => array(
				'first_name' => '',
				'last_name'  => '',
				'full_name'  => '',
				'email'      => '',
				'phone'      => '',
			),
			'bookingUrl'         => '',
			'unsafeReason'       => '',
		);
	}
}
