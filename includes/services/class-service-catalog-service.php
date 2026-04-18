<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Service_Catalog_Service {
	/**
	 * @var Handik_Booking_App_Settings
	 */
	protected $settings;

	/**
	 * @param Handik_Booking_App_Settings $settings Settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_catalog() {
		$stored = $this->settings->get( 'service_catalog_json', '' );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			$decoded = json_decode( $stored, true );
			if ( is_array( $decoded ) ) {
				return $this->sanitize_catalog( $decoded );
			}
		}

		return $this->default_catalog();
	}

	/**
	 * @return string
	 */
	public function get_catalog_json() {
		return wp_json_encode( $this->get_catalog(), JSON_PRETTY_PRINT );
	}

	/**
	 * @param string $task_id Task ID.
	 * @return array<string, mixed>|null
	 */
	public function find_task( $task_id ) {
		$task_id = sanitize_key( $task_id );
		foreach ( $this->get_catalog() as $group ) {
			foreach ( $group['tasks'] as $task ) {
				if ( $task_id === $task['id'] ) {
					return $task;
				}
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $catalog Catalog.
	 * @return array<int, array<string, mixed>>
	 */
	protected function sanitize_catalog( array $catalog ) {
		$sanitized = array();

		foreach ( $catalog as $group ) {
			if ( empty( $group['group'] ) || empty( $group['tasks'] ) || ! is_array( $group['tasks'] ) ) {
				continue;
			}

			$tasks = array();
			foreach ( $group['tasks'] as $task ) {
				$task_id = sanitize_key( $task['id'] ?? '' );
				$label   = sanitize_text_field( $task['label'] ?? '' );
				if ( ! $task_id || ! $label ) {
					continue;
				}

				$tasks[] = array(
					'id'             => $task_id,
					'label'          => $label,
					'description'    => sanitize_textarea_field( $task['description'] ?? '' ),
					'rate_label'     => sanitize_text_field( $task['rate_label'] ?? '' ),
					'service_family' => sanitize_key( $task['service_family'] ?? '' ),
					'rate_family'    => sanitize_key( $task['rate_family'] ?? '' ),
				);
			}

			if ( empty( $tasks ) ) {
				continue;
			}

			$sanitized[] = array(
				'group' => sanitize_text_field( $group['group'] ),
				'tasks' => $tasks,
			);
		}

		return empty( $sanitized ) ? $this->default_catalog() : $sanitized;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function default_catalog() {
		return array(
			array(
				'group' => 'Install & Mount',
				'tasks' => array(
					array(
						'id'             => 'tv_mounting',
						'label'          => 'TV Mounting',
						'description'    => 'Flat-screen TV wall mounting, bracket install, cable routing, and basic leveling.',
						'rate_label'     => '$75/hr',
						'service_family' => 'install_mount',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'shelf_installation',
						'label'          => 'Shelves / Wall Storage',
						'description'    => 'Floating shelves, wall rails, closet storage pieces, and lightweight wall organization.',
						'rate_label'     => '$65/hr',
						'service_family' => 'install_mount',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'art_mirror_hanging',
						'label'          => 'Art / Mirror Hanging',
						'description'    => 'Mirrors, art walls, heavy frames, gallery layouts, and secure hanging hardware.',
						'rate_label'     => '$65/hr',
						'service_family' => 'install_mount',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'curtain_blinds',
						'label'          => 'Curtains / Blinds',
						'description'    => 'Curtain rods, blinds, shades, and window treatment installation.',
						'rate_label'     => '$65/hr',
						'service_family' => 'install_mount',
						'rate_family'    => 'standard',
					),
				),
			),
			array(
				'group' => 'Assembly & Repairs',
				'tasks' => array(
					array(
						'id'             => 'furniture_assembly',
						'label'          => 'Furniture Assembly',
						'description'    => 'IKEA, Wayfair, Amazon items, bed frames, dressers, patio sets, desks, chairs, grills, and fitness equipment.',
						'rate_label'     => '$50/hr',
						'service_family' => 'assembly_repair',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'door_hardware',
						'label'          => 'Door / Lock / Hardware',
						'description'    => 'Lock changes, knob swaps, latches, hinges, closers, and sticking-door adjustments.',
						'rate_label'     => '$70/hr',
						'service_family' => 'assembly_repair',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'drywall_patch',
						'label'          => 'Drywall Patch / Small Repair',
						'description'    => 'Small drywall holes, dents, corner damage, prep for paint, and minor wall repairs.',
						'rate_label'     => '$70/hr',
						'service_family' => 'assembly_repair',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'caulking_touchups',
						'label'          => 'Caulking / Sealing',
						'description'    => 'Bathroom, kitchen, window, and trim sealing or refresh work.',
						'rate_label'     => '$60/hr',
						'service_family' => 'assembly_repair',
						'rate_family'    => 'standard',
					),
				),
			),
			array(
				'group' => 'Fixture Work',
				'tasks' => array(
					array(
						'id'             => 'light_fixture',
						'label'          => 'Light Fixture / Ceiling Fan',
						'description'    => 'Fixture swaps, fan installs, vanity lighting, and basic electrical replacement work.',
						'rate_label'     => '$85/hr',
						'service_family' => 'fixture_work',
						'rate_family'    => 'skilled',
					),
					array(
						'id'             => 'faucet_fixture',
						'label'          => 'Faucet / Fixture Replacement',
						'description'    => 'Kitchen and bathroom faucet swaps, sink fixture changes, and related hardware install.',
						'rate_label'     => '$85/hr',
						'service_family' => 'fixture_work',
						'rate_family'    => 'skilled',
					),
					array(
						'id'             => 'toilet_repair',
						'label'          => 'Toilet / Bathroom Repair',
						'description'    => 'Running toilets, flappers, fill valves, toilet seats, and basic bathroom fixes.',
						'rate_label'     => '$85/hr',
						'service_family' => 'fixture_work',
						'rate_family'    => 'skilled',
					),
					array(
						'id'             => 'appliance_install',
						'label'          => 'Appliance / Utility Install',
						'description'    => 'Washer, dryer, dishwasher, and utility-room install support or replacement connections.',
						'rate_label'     => '$90/hr',
						'service_family' => 'fixture_work',
						'rate_family'    => 'skilled',
					),
				),
			),
			array(
				'group' => 'Finishing',
				'tasks' => array(
					array(
						'id'             => 'paint_touchup',
						'label'          => 'Paint Touch-Up',
						'description'    => 'Touch-ups, trim repaint, small wall areas, and punch-list paint work.',
						'rate_label'     => '$60/hr',
						'service_family' => 'finishing',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'trim_finish',
						'label'          => 'Trim / Finish Work',
						'description'    => 'Baseboard, shoe molding, light trim replacement, and finish carpentry touch-ups.',
						'rate_label'     => '$75/hr',
						'service_family' => 'finishing',
						'rate_family'    => 'skilled',
					),
					array(
						'id'             => 'weatherproofing',
						'label'          => 'Weatherproofing / Exterior Seals',
						'description'    => 'Door sweeps, draft control, basic sealing, and small exterior weatherproofing tasks.',
						'rate_label'     => '$65/hr',
						'service_family' => 'finishing',
						'rate_family'    => 'standard',
					),
					array(
						'id'             => 'general_other',
						'label'          => 'Other Handyman Task',
						'description'    => 'A task that does not fit the list above but still belongs in a general handyman visit.',
						'rate_label'     => '$65/hr',
						'service_family' => 'general',
						'rate_family'    => 'standard',
					),
				),
			),
		);
	}
}
