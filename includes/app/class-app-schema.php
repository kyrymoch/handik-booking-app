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
			'taskSelectionMode'  => 'overview',
			'isProject'          => false,
			'jobShape'           => '',
			'address'            => array(
				'address_id'     => 0,
				'address_full'   => '',
				'address_line_1' => '',
				'address_unit'   => '',
				'city'           => '',
				'state'          => '',
				'zip_code'       => '',
			),
			'photos'             => array(),
			'assistantResult'    => null,
			'assistantUserMessageSent' => false,
			'assistantThreadId'  => '',
			'contact'            => array(
				'first_name' => '',
				'last_name'  => '',
				'full_name'  => '',
				'email'      => '',
				'phone'      => '',
			),
			'bookingUrl'         => '',
			'bookingStatus'      => '',
			'bookingStatusMessage' => '',
			'unsafeReason'       => '',
			'appSessionKey'      => '',
			'message'            => '',
			'footerHint'         => '',
			'footerHintError'    => false,
			'lastAssistantNotice'=> '',
			'infoModeTooltipVisible' => false,
			'loading'            => false,
			'photoUploading'     => false,
			'bookingOpened'      => false,
			'notifications'      => array(),
		);
	}
}
