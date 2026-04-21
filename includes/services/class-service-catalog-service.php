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
				$catalog = $this->sanitize_catalog( $decoded );
				if ( ! $this->looks_like_legacy_catalog( $catalog ) ) {
					return $catalog;
				}
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
	 * @param array<int, array<string, mixed>> $catalog Catalog.
	 * @return bool
	 */
	protected function looks_like_legacy_catalog( array $catalog ) {
		if ( empty( $catalog ) ) {
			return false;
		}

		$legacy_groups = array(
			'Install & Mount',
			'Assembly & Repairs',
			'Fixture Work',
			'Finishing',
		);

		$catalog_groups = array_map(
			static function( $group ) {
				return (string) ( $group['group'] ?? '' );
			},
			$catalog
		);

		if ( $legacy_groups === $catalog_groups ) {
			return true;
		}

		$legacy_task_ids = array(
			'tv_mounting',
			'shelf_installation',
			'art_mirror_hanging',
			'curtain_blinds',
			'furniture_assembly',
			'door_hardware',
			'drywall_patch',
			'caulking_touchups',
			'light_fixture',
			'faucet_fixture',
			'toilet_repair',
			'appliance_install',
			'paint_touchup',
			'trim_finish',
			'weatherproofing',
			'general_other',
		);

		$catalog_task_ids = array();
		foreach ( $catalog as $group ) {
			foreach ( $group['tasks'] ?? array() as $task ) {
				if ( ! empty( $task['id'] ) ) {
					$catalog_task_ids[] = (string) $task['id'];
				}
			}
		}

		sort( $legacy_task_ids );
		sort( $catalog_task_ids );

		return $legacy_task_ids === $catalog_task_ids;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function default_catalog() {
		return array(
			array(
				'group' => 'Assembly, Mounting & Home Setup',
				'tasks' => array(
					array(
						'id'             => 'furniture_assembly_reassembly',
						'label'          => 'Furniture Assembly',
						'description'    => 'Assembly or re-assembly of furniture such as beds, dressers, desks, shelving, and similar household items.',
						'rate_label'     => '$60/hr',
						'service_family' => 'furniture_assembly',
						'rate_family'    => 'assembly_basic',
					),
					array(
						'id'             => 'mounting_hanging',
						'label'          => 'Mounting & Hanging',
						'description'    => 'Mounting TVs, shelves, mirrors, curtain rods, artwork, and similar wall-mounted items.',
						'rate_label'     => '$60/hr',
						'service_family' => 'mounting_hanging',
						'rate_family'    => 'assembly_basic',
					),
					array(
						'id'             => 'furniture_cabinet_repairs',
						'label'          => 'Furniture & Cabinet Repairs',
						'description'    => 'Repairs for cabinets, drawers, hinges, panels, furniture parts, and similar wood-based household items.',
						'rate_label'     => '$70/hr',
						'service_family' => 'furniture_cabinet_repair',
						'rate_family'    => 'repair_standard',
					),
				),
			),
			array(
				'group' => 'Plumbing, Pipework & Appliances',
				'tasks' => array(
					array(
						'id'             => 'plumbing_fixtures_repairs',
						'label'          => 'Plumbing Fixtures & Repairs',
						'description'    => 'Targeted plumbing repairs and fixture replacements such as faucets, toilets, sinks, shower trim, shutoff valves, drains, and similar non-major plumbing work.',
						'rate_label'     => '$75/hr',
						'service_family' => 'plumbing_fixture_repair',
						'rate_family'    => 'trade_general',
					),
					array(
						'id'             => 'plumbing_pipework_supply_lines',
						'label'          => 'Plumbing Pipework & Supply Lines',
						'description'    => 'More involved plumbing work such as water line installation, pipe rerouting, supply line layout changes, PEX or copper modifications, ProPress work, and similar larger plumbing installations or reconfigurations.',
						'rate_label'     => '$95/hr',
						'service_family' => 'plumbing_pipework',
						'rate_family'    => 'premium_specialty',
					),
					array(
						'id'             => 'appliance_installation_repair',
						'label'          => 'Appliance Installation & Repair',
						'description'    => 'Installation, troubleshooting, and minor repair of common household appliances and related hookups.',
						'rate_label'     => '$70/hr',
						'service_family' => 'appliance_service',
						'rate_family'    => 'repair_standard',
					),
				),
			),
			array(
				'group' => 'Electrical, Smart Home & Low Voltage',
				'tasks' => array(
					array(
						'id'             => 'electrical_installation_repair',
						'label'          => 'Electrical Installation & Repair',
						'description'    => 'Minor electrical installation and repair such as fixtures, outlets, switches, and similar non-major electrical work.',
						'rate_label'     => '$70/hr',
						'service_family' => 'electrical_service',
						'rate_family'    => 'repair_standard',
					),
					array(
						'id'             => 'smart_home_installation',
						'label'          => 'Smart Home Installation',
						'description'    => 'Installation and setup of smart home devices such as doorbells, thermostats, cameras, locks, and sensors.',
						'rate_label'     => '$80/hr',
						'service_family' => 'smart_home_installation',
						'rate_family'    => 'installation_specialty',
					),
					array(
						'id'             => 'in_wall_concealed_cable_work',
						'label'          => 'In-Wall Cable Work',
						'description'    => 'Concealed cable routing, in-wall low-voltage cable work, and cleaner hidden wire solutions where access allows.',
						'rate_label'     => '$95/hr',
						'service_family' => 'concealed_cable_work',
						'rate_family'    => 'premium_specialty',
					),
				),
			),
			array(
				'group' => 'Walls, Paint & Interior Finishes',
				'tasks' => array(
					array(
						'id'             => 'drywall_plaster_repair',
						'label'          => 'Drywall & Plaster Repair',
						'description'    => 'Drywall and plaster patching, crack repair, hole repair, surface prep, and similar wall restoration work.',
						'rate_label'     => '$70/hr',
						'service_family' => 'wall_surface_repair',
						'rate_family'    => 'repair_standard',
					),
					array(
						'id'             => 'painting_refinishing',
						'label'          => 'Painting & Refinishing',
						'description'    => 'Interior paint touch-ups, repainting, trim painting, refinishing, and similar finish-related work.',
						'rate_label'     => '$70/hr',
						'service_family' => 'painting_refinishing',
						'rate_family'    => 'repair_standard',
					),
					array(
						'id'             => 'flooring_tile',
						'label'          => 'Flooring & Tile',
						'description'    => 'Minor flooring and tile repairs, transitions, grout-related fixes, trim details, and similar finish work.',
						'rate_label'     => '$75/hr',
						'service_family' => 'flooring_tile_repair',
						'rate_family'    => 'trade_general',
					),
					array(
						'id'             => 'sealing_caulking',
						'label'          => 'Sealing & Caulking',
						'description'    => 'Removal and replacement of caulk and sealant in kitchens, bathrooms, around trim, fixtures, and similar joints.',
						'rate_label'     => '$75/hr',
						'service_family' => 'sealing_caulking',
						'rate_family'    => 'trade_general',
					),
				),
			),
			array(
				'group' => 'Doors, Windows & Weatherproofing',
				'tasks' => array(
					array(
						'id'             => 'door_installation_replacement',
						'label'          => 'Door Installation & Replacement',
						'description'    => 'Installation or replacement of interior or exterior doors where fitting, alignment, and hardware setup are required.',
						'rate_label'     => '$80/hr',
						'service_family' => 'door_installation',
						'rate_family'    => 'installation_specialty',
					),
					array(
						'id'             => 'door_adjustments_hardware',
						'label'          => 'Door Adjustments & Hardware',
						'description'    => 'Door sticking, alignment, hinge work, locks, handles, closers, latches, and related door hardware fixes.',
						'rate_label'     => '$75/hr',
						'service_family' => 'door_hardware_adjustment',
						'rate_family'    => 'trade_general',
					),
					array(
						'id'             => 'window_installation_replacement',
						'label'          => 'Window Installation & Replacement',
						'description'    => 'Installation or replacement of window units and similar more advanced window-related work.',
						'rate_label'     => '$110/hr',
						'service_family' => 'window_installation',
						'rate_family'    => 'precision_specialty',
					),
					array(
						'id'             => 'window_treatment_repair',
						'label'          => 'Window & Treatment Repair',
						'description'    => 'Window adjustments, sash or mechanism issues, blinds, shades, curtain hardware, and similar window-area repairs.',
						'rate_label'     => '$75/hr',
						'service_family' => 'window_treatment_repair',
						'rate_family'    => 'trade_general',
					),
					array(
						'id'             => 'weatherproofing',
						'label'          => 'Weatherproofing',
						'description'    => 'Draft sealing, weatherstripping, gap sealing, and similar work to improve insulation and reduce air leakage.',
						'rate_label'     => '$75/hr',
						'service_family' => 'weatherproofing',
						'rate_family'    => 'trade_general',
					),
				),
			),
			array(
				'group' => 'Carpentry & Woodworking',
				'tasks' => array(
					array(
						'id'             => 'finish_carpentry',
						'label'          => 'Finish Carpentry',
						'description'    => 'Trim, casing, baseboards, molding details, and similar interior finish carpentry work.',
						'rate_label'     => '$80/hr',
						'service_family' => 'finish_carpentry',
						'rate_family'    => 'installation_specialty',
					),
					array(
						'id'             => 'built_ins',
						'label'          => 'Built-Ins',
						'description'    => 'Built-in units, integrated storage, custom cabinet-style installations, and similar more advanced interior build work.',
						'rate_label'     => '$110/hr',
						'service_family' => 'built_in_installation',
						'rate_family'    => 'precision_specialty',
					),
					array(
						'id'             => 'wood_repairs_reinforcement',
						'label'          => 'Wood Repairs & Reinforcement',
						'description'    => 'Reinforcement and repair of damaged wood components where added strength or rebuild work is needed.',
						'rate_label'     => '$85/hr',
						'service_family' => 'wood_reinforcement',
						'rate_family'    => 'structural_repair',
					),
				),
			),
			array(
				'group' => 'Exterior & Yard Improvements',
				'tasks' => array(
					array(
						'id'             => 'deck_porch',
						'label'          => 'Deck & Porch',
						'description'    => 'Deck and porch repairs, boards, railings, stairs, trim, and related exterior carpentry.',
						'rate_label'     => '$80/hr',
						'service_family' => 'deck_porch_repair',
						'rate_family'    => 'installation_specialty',
					),
					array(
						'id'             => 'siding_fences',
						'label'          => 'Siding & Fences',
						'description'    => 'Minor siding and fence repairs, adjustments, replacement of damaged sections, and related exterior work.',
						'rate_label'     => '$100/hr',
						'service_family' => 'siding_fence_repair',
						'rate_family'    => 'exterior_premium',
					),
					array(
						'id'             => 'yard_landscaping_no_mowing',
						'label'          => 'Yard & Landscaping',
						'description'    => 'Light yard work, cleanup, garden-related tasks, edging, planting help, and similar non-mowing outdoor work.',
						'rate_label'     => '$70/hr',
						'service_family' => 'yard_landscaping',
						'rate_family'    => 'repair_standard',
					),
					array(
						'id'             => 'concrete_cement_work',
						'label'          => 'Concrete & Cement Work',
						'description'    => 'Concrete patching, repair, forming, small pours, and related cement-based work.',
						'rate_label'     => '$95/hr',
						'service_family' => 'concrete_cement',
						'rate_family'    => 'premium_specialty',
					),
				),
			),
			array(
				'group' => 'Not Sure / Project Help',
				'tasks' => array(
					array(
						'id'             => 'general_handyman_help',
						'label'          => 'General Handyman Help',
						'description'    => 'Not sure what category fits? Choose this option for mixed, unclear, or general handyman tasks and we’ll route it correctly.',
						'rate_label'     => '$80/hr',
						'service_family' => 'general_handyman',
						'rate_family'    => 'general_diagnostic',
					),
					array(
						'id'             => 'project_large_job',
						'label'          => 'Complex Project Work',
						'description'    => 'Larger jobs, broader scopes, or project-style work such as multiple rooms, extensive repairs, or more complex installations.',
						'rate_label'     => 'Custom estimate',
						'service_family' => 'project_large_job',
						'rate_family'    => 'project_custom',
					),
				),
			),
		);
	}
}
