<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_State {
	/**
	 * @return array<int, string>
	 */
	public function steps() {
		return array(
			'client_type',
			'returning_verify',
			'task_selection',
			'address_photos',
			'assistant',
			'contact_details',
			'booking',
			'success',
			'unsafe',
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function task_catalog() {
		return array(
			array(
				'group' => 'Install & Mount',
				'tasks' => array(
					array( 'id' => 'tv_mounting', 'label' => 'TV Mounting' ),
					array( 'id' => 'shelf_installation', 'label' => 'Shelves / Wall Storage' ),
					array( 'id' => 'art_mirror_hanging', 'label' => 'Art / Mirror Hanging' ),
					array( 'id' => 'curtain_blinds', 'label' => 'Curtains / Blinds' ),
				),
			),
			array(
				'group' => 'Assembly & Repairs',
				'tasks' => array(
					array( 'id' => 'furniture_assembly', 'label' => 'Furniture Assembly' ),
					array( 'id' => 'door_hardware', 'label' => 'Door / Lock / Hardware' ),
					array( 'id' => 'drywall_patch', 'label' => 'Drywall Patch / Small Repair' ),
					array( 'id' => 'caulking_touchups', 'label' => 'Caulking / Sealing' ),
				),
			),
			array(
				'group' => 'Fixture Work',
				'tasks' => array(
					array( 'id' => 'light_fixture', 'label' => 'Light Fixture / Ceiling Fan' ),
					array( 'id' => 'faucet_fixture', 'label' => 'Faucet / Fixture Replacement' ),
					array( 'id' => 'toilet_repair', 'label' => 'Toilet / Bathroom Repair' ),
					array( 'id' => 'appliance_install', 'label' => 'Appliance / Utility Install' ),
				),
			),
			array(
				'group' => 'Finishing',
				'tasks' => array(
					array( 'id' => 'paint_touchup', 'label' => 'Paint Touch-Up' ),
					array( 'id' => 'trim_finish', 'label' => 'Trim / Finish Work' ),
					array( 'id' => 'weatherproofing', 'label' => 'Weatherproofing / Exterior Seals' ),
					array( 'id' => 'general_other', 'label' => 'Other Handyman Task' ),
				),
			),
		);
	}
}
