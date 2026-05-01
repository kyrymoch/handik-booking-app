<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Routing_Service {
	/**
	 * @var array<string, array<string, string>>
	 */
	protected $task_map = array(
		'furniture_assembly_reassembly'   => array( 'service_family' => 'furniture_assembly', 'rate_family' => 'assembly_basic' ),
		'furniture_cabinet_repairs'       => array( 'service_family' => 'furniture_cabinet_repair', 'rate_family' => 'repair_standard' ),
		'mounting_hanging'                => array( 'service_family' => 'mounting_hanging', 'rate_family' => 'assembly_basic' ),
		'plumbing_fixtures_repairs'       => array( 'service_family' => 'plumbing_fixture_repair', 'rate_family' => 'trade_general' ),
		'plumbing_pipework_supply_lines'  => array( 'service_family' => 'plumbing_pipework', 'rate_family' => 'premium_specialty' ),
		'appliance_installation_repair'   => array( 'service_family' => 'appliance_service', 'rate_family' => 'repair_standard' ),
		'electrical_smart_home_installation' => array( 'service_family' => 'electrical_smart_home', 'rate_family' => 'trade_general' ),
		'lighting_ceiling_fans'           => array( 'service_family' => 'lighting_fans', 'rate_family' => 'trade_general' ),
		'in_wall_concealed_cable_work'    => array( 'service_family' => 'concealed_cable_work', 'rate_family' => 'premium_specialty' ),
		'drywall_plaster_repair'          => array( 'service_family' => 'wall_surface_repair', 'rate_family' => 'repair_standard' ),
		'painting_refinishing'            => array( 'service_family' => 'painting_refinishing', 'rate_family' => 'repair_standard' ),
		'sealing_caulking_weatherproofing' => array( 'service_family' => 'sealing_weatherproofing', 'rate_family' => 'trade_general' ),
		'flooring_tile'                   => array( 'service_family' => 'flooring_tile_repair', 'rate_family' => 'trade_general' ),
		'door_work_hardware'              => array( 'service_family' => 'door_work', 'rate_family' => 'installation_specialty' ),
		'window_treatment_work'           => array( 'service_family' => 'window_work', 'rate_family' => 'premium_specialty' ),
		'finish_carpentry_trim'           => array( 'service_family' => 'finish_carpentry', 'rate_family' => 'installation_specialty' ),
		'built_ins_wood_repairs'          => array( 'service_family' => 'built_ins_woodwork', 'rate_family' => 'premium_specialty' ),
		'deck_porch'                      => array( 'service_family' => 'deck_porch_repair', 'rate_family' => 'installation_specialty' ),
		'siding_fences'                   => array( 'service_family' => 'siding_fence_repair', 'rate_family' => 'exterior_premium' ),
		'yard_landscaping_no_mowing'      => array( 'service_family' => 'yard_landscaping', 'rate_family' => 'repair_standard' ),
		'concrete_cement_work'            => array( 'service_family' => 'concrete_cement', 'rate_family' => 'premium_specialty' ),
		'general_handyman_help'           => array( 'service_family' => 'general_handyman', 'rate_family' => 'general_diagnostic' ),
		'larger_scale_work'               => array( 'service_family' => 'project_large_job', 'rate_family' => 'project_custom' ),
	);

	/**
	 * @var array<string, int>
	 */
	protected $rate_priority = array(
		'project_custom'         => 100,
		'exterior_premium'       => 90,
		'premium_specialty'      => 80,
		'installation_specialty' => 70,
		'trade_general'          => 60,
		'repair_standard'        => 50,
		'assembly_basic'         => 40,
		'general_diagnostic'     => 30,
	);

	/**
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $assistant Assistant.
	 * @return array<string, mixed>
	 */
	public function route( array $request, array $assistant = array() ) {
		$tasks             = $this->normalize_tasks( $request['selected_tasks'] ?? array() );
		$description       = strtolower( (string) ( $request['short_description'] ?? '' ) );
		$is_project        = ! empty( $request['is_project'] ) || ! empty( $assistant['is_project'] );
		$assistant_summary = ! empty( $assistant['assistant_summary'] ) ? sanitize_textarea_field( (string) $assistant['assistant_summary'] ) : '';
		$estimate_notes    = ! empty( $assistant['estimate_notes'] ) ? sanitize_textarea_field( (string) $assistant['estimate_notes'] ) : '';
		$unsafe            = $this->detect_unsafe( $description, $tasks, $assistant );

		if ( $unsafe['unsafe_flag'] ) {
			return array(
				'service_family'           => 'safety_review',
				'rate_family'              => 'manual',
				'duration_bucket'          => 'project_consult',
				'booking_type'             => '',
				'suggested_duration_hours' => 'consult_1',
				'pricing_posture'          => 'consultation_first',
				'assistant_summary'        => $assistant_summary ? $assistant_summary : 'Request flagged for manual safety review.',
				'estimate_notes'           => $estimate_notes,
				'status'                   => 'unsafe',
				'routing_status'           => 'blocked',
				'unsafe_flag'              => 1,
				'unsafe_reason'            => $unsafe['unsafe_reason'],
			);
		}

		$inferred_rate_family = $this->infer_rate_family( $tasks, $description );
		$rate_family          = ! empty( $assistant['rate_family'] ) ? sanitize_key( (string) $assistant['rate_family'] ) : $inferred_rate_family;
		$booking_type         = $this->booking_type( $tasks, $description, $is_project, $assistant, $request, $rate_family );
		$duration_bucket      = ! empty( $assistant['duration_bucket'] ) ? sanitize_key( (string) $assistant['duration_bucket'] ) : $this->duration_bucket( $booking_type );
		$suggested_duration   = ! empty( $assistant['suggested_duration_hours'] ) ? sanitize_key( (string) $assistant['suggested_duration_hours'] ) : $this->suggested_duration_hours( $booking_type, $tasks, $rate_family, $description, $request );
		$pricing_posture      = ! empty( $assistant['pricing_posture'] ) ? sanitize_key( (string) $assistant['pricing_posture'] ) : $this->pricing_posture( $booking_type, $rate_family );
		$service_family       = ! empty( $assistant['service_family'] ) ? sanitize_key( (string) $assistant['service_family'] ) : $this->service_family( $tasks, $description, $rate_family );
		$assistant_has_readiness = array_key_exists( 'enough_information', $assistant );
		$enough                  = $assistant_has_readiness ? ! empty( $assistant['enough_information'] ) : ( ! empty( $request['address_full'] ) && ( ! empty( $tasks ) || ! empty( $description ) ) );
		$mismatch                = $this->selected_task_mismatch( $tasks, $service_family, $rate_family );

		return array(
			'service_family'           => $service_family,
			'rate_family'              => $rate_family,
			'duration_bucket'          => $duration_bucket,
			'booking_type'             => $booking_type,
			'suggested_duration_hours' => $suggested_duration,
			'pricing_posture'          => $pricing_posture,
			'assistant_summary'        => $assistant_summary ? $assistant_summary : $this->summary( $booking_type, $tasks, $request, $suggested_duration ),
			'estimate_notes'           => $estimate_notes,
			'status'                   => $enough ? 'ready_for_booking' : 'needs_more_info',
			'routing_status'           => $enough ? 'complete' : 'awaiting_assistant',
			'unsafe_flag'              => 0,
			'unsafe_reason'            => '',
			'selected_task_mismatch'   => $mismatch['selected_task_mismatch'],
			'mismatch_notes'           => $mismatch['mismatch_notes'],
		);
	}

	/**
	 * @param array<int, string> $tasks Selected task IDs.
	 * @param string             $service_family Routed service family.
	 * @param string             $rate_family Routed rate family.
	 * @return array<string, mixed>
	 */
	protected function selected_task_mismatch( array $tasks, $service_family, $rate_family ) {
		$tasks = array_values( array_filter( $tasks ) );
		if ( empty( $tasks ) || ! $service_family || ! $rate_family ) {
			return array(
				'selected_task_mismatch' => false,
				'mismatch_notes'         => '',
			);
		}

		foreach ( $tasks as $task_id ) {
			if ( empty( $this->task_map[ $task_id ] ) ) {
				continue;
			}
			$mapped = $this->task_map[ $task_id ];
			if ( $service_family === $mapped['service_family'] || $rate_family === $mapped['rate_family'] ) {
				return array(
					'selected_task_mismatch' => false,
					'mismatch_notes'         => '',
				);
			}
		}

		return array(
			'selected_task_mismatch' => true,
			'mismatch_notes'         => sprintf( 'Selected %s but assistant routed to %s / %s.', implode( ', ', $tasks ), $service_family, $rate_family ),
		);
	}

	/**
	 * @param string $description Description.
	 * @param array<int, string> $tasks Tasks.
	 * @param array<string, mixed> $assistant Assistant.
	 * @return array<string, mixed>
	 */
	protected function detect_unsafe( $description, array $tasks, array $assistant ) {
		if ( ! empty( $assistant['unsafe'] ) ) {
			return array(
				'unsafe_flag'  => 1,
				'unsafe_reason'=> sanitize_textarea_field( (string) ( $assistant['unsafe_reason'] ?? 'Unsafe request detected.' ) ),
			);
		}

		$keywords = array( 'gas leak', 'sparking', 'flood', 'fire', 'burst pipe', 'electrical emergency', 'smell gas', 'asbestos' );
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $description, $keyword ) ) {
				return array( 'unsafe_flag' => 1, 'unsafe_reason' => 'Potential emergency or unsafe condition detected.' );
			}
		}

		foreach ( $tasks as $task ) {
			if ( preg_match( '/(emergency|hazard|fire|gas)/i', (string) $task ) ) {
				return array( 'unsafe_flag' => 1, 'unsafe_reason' => 'Potential emergency or unsafe task detected.' );
			}
		}

		return array( 'unsafe_flag' => 0, 'unsafe_reason' => '' );
	}

	/**
	 * @param array<int, string> $tasks Tasks.
	 * @param string $description Description.
	 * @param bool $is_project Project flag.
	 * @param array<string, mixed> $assistant Assistant result.
	 * @param array<string, mixed> $request Request.
	 * @param string $rate_family Rate family.
	 * @return string
	 */
	protected function booking_type( array $tasks, $description, $is_project, array $assistant, array $request, $rate_family ) {
		$allowed = array( 'standard_visit', 'extended_visit', 'large_visit', 'project_consultation' );
		if ( ! empty( $assistant['booking_type'] ) && in_array( $assistant['booking_type'], $allowed, true ) ) {
			return $assistant['booking_type'];
		}

		if ( $this->has_project_signal( $tasks, $description, $is_project, $request, $rate_family ) ) {
			return 'project_consultation';
		}

		if ( $this->has_large_visit_signal( $tasks, $description, $request, $rate_family ) ) {
			return 'large_visit';
		}

		if ( $this->has_extended_visit_signal( $tasks, $description, $request, $rate_family ) ) {
			return 'extended_visit';
		}

		return 'standard_visit';
	}

	/**
	 * @param array<int, string> $tasks Tasks.
	 * @param string $description Description.
	 * @param string $rate_family Rate family.
	 * @return string
	 */
	protected function service_family( array $tasks, $description, $rate_family ) {
		foreach ( $tasks as $task ) {
			if ( ! empty( $this->task_map[ $task ]['service_family'] ) ) {
				return $this->task_map[ $task ]['service_family'];
			}
		}

		$joined = strtolower( implode( ' ', $tasks ) . ' ' . $description );
		if ( preg_match( '/(electrical|switch|outlet|fixture|light|smart home|camera|thermostat)/', $joined ) ) {
			return 'electrical_smart_home';
		}
		if ( preg_match( '/(plumbing|pipe|toilet|drain|faucet|sink|supply line)/', $joined ) ) {
			return 'plumbing_fixture_repair';
		}
		if ( preg_match( '/(paint|drywall|patch|trim|door|window|floor|tile|caulk)/', $joined ) ) {
			return 'wall_surface_repair';
		}
		if ( preg_match( '/(mount|assembly|furniture|tv|shelf)/', $joined ) ) {
			return 'mounting_hanging';
		}
		if ( 'project_custom' === $rate_family ) {
			return 'project_large_job';
		}

		return 'general_handyman';
	}

	/**
	 * @param array<int, string> $tasks Tasks.
	 * @param string $description Description.
	 * @return string
	 */
	protected function infer_rate_family( array $tasks, $description ) {
		$best_family   = 'general_diagnostic';
		$best_priority = $this->rate_priority[ $best_family ];

		foreach ( $tasks as $task ) {
			$family   = ! empty( $this->task_map[ $task ]['rate_family'] ) ? $this->task_map[ $task ]['rate_family'] : '';
			$priority = $family && isset( $this->rate_priority[ $family ] ) ? $this->rate_priority[ $family ] : 0;
			if ( $priority > $best_priority ) {
				$best_family   = $family;
				$best_priority = $priority;
			}
		}

		if ( 'general_diagnostic' !== $best_family ) {
			return $best_family;
		}

		if ( preg_match( '/(pipework|supply line|propress|copper|pex|concrete|cement|siding|fence|in-wall|concealed cable|built-in|custom wood)/', $description ) ) {
			return 'premium_specialty';
		}
		if ( preg_match( '/(door|window|trim|finish carpentry|deck|porch)/', $description ) ) {
			return 'installation_specialty';
		}
		if ( preg_match( '/(plumbing|electrical|tile|caulk|weatherproof|faucet|toilet|sink)/', $description ) ) {
			return 'trade_general';
		}
		if ( preg_match( '/(repair|patch|paint|yard|landscaping|appliance)/', $description ) ) {
			return 'repair_standard';
		}
		if ( preg_match( '/(assembly|mount|hanging|shelf|tv)/', $description ) ) {
			return 'assembly_basic';
		}

		return $best_family;
	}

	/**
	 * @param string $booking_type Booking type.
	 * @return string
	 */
	protected function duration_bucket( $booking_type ) {
		switch ( $booking_type ) {
			case 'project_consultation':
				return 'project_consult';
			case 'large_visit':
				return '6_8_hours';
			case 'extended_visit':
				return '3_5_hours';
			default:
				return '1_2_hours';
		}
	}

	/**
	 * @param string $booking_type Booking type.
	 * @param array<int, string> $tasks Tasks.
	 * @param string $rate_family Rate family.
	 * @param string $description Description.
	 * @param array<string, mixed> $request Request.
	 * @return string
	 */
	protected function suggested_duration_hours( $booking_type, array $tasks, $rate_family, $description, array $request ) {
		if ( 'project_consultation' === $booking_type ) {
			return 'consult_1';
		}

		$task_count         = count( $tasks );
		$heavy_task_count   = count( array_intersect( $tasks, $this->large_visit_tasks() ) );
		$has_medium_signal  = $this->has_medium_scope_signal( $description, $request );
		$has_large_signal   = $this->has_large_scope_signal( $description, $request );

		if ( 'large_visit' === $booking_type ) {
			if ( $task_count >= 5 || $heavy_task_count >= 2 ) {
				return '8';
			}
			if ( $task_count >= 4 || $has_large_signal ) {
				return '7';
			}
			return '6';
		}

		if ( 'extended_visit' === $booking_type ) {
			if ( $task_count >= 3 ) {
				return '5';
			}
			if ( 'installation_specialty' === $rate_family || $has_medium_signal || 'trade_general' === $rate_family ) {
				return '4';
			}
			return '3';
		}

		if ( 'assembly_basic' === $rate_family && 1 === $task_count && ! $has_medium_signal ) {
			return '1';
		}

		return '2';
	}

	/**
	 * @param string $booking_type Booking type.
	 * @param string $rate_family Rate family.
	 * @return string
	 */
	protected function pricing_posture( $booking_type, $rate_family ) {
		if ( 'project_consultation' === $booking_type || 'project_custom' === $rate_family ) {
			return 'consultation_first';
		}

		if ( in_array( $rate_family, array( 'assembly_basic', 'repair_standard', 'general_diagnostic' ), true ) ) {
			return 'hourly_only';
		}

		return 'hourly_plus_materials';
	}

	/**
	 * @param string $booking_type Booking type.
	 * @param array<int, string> $tasks Tasks.
	 * @param array<string, mixed> $request Request.
	 * @param string $suggested_duration Suggested duration.
	 * @return string
	 */
	protected function summary( $booking_type, array $tasks, array $request, $suggested_duration ) {
		$task_summary = ! empty( $tasks ) ? implode( ', ', $tasks ) : 'general handyman work';
		$duration     = 'consult_1' === $suggested_duration ? '1 hour consultation' : $suggested_duration . ' hour(s)';

		return sprintf(
			'Routed to %1$s for %2$s. Suggested duration: %3$s. Preferred timeframe: %4$s.',
			str_replace( '_', ' ', $booking_type ),
			$task_summary,
			$duration,
			! empty( $request['preferred_timeframe'] ) ? $request['preferred_timeframe'] : 'not specified'
		);
	}

	/**
	 * @param mixed $tasks Tasks.
	 * @return array<int, string>
	 */
	protected function normalize_tasks( $tasks ) {
		if ( ! is_array( $tasks ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'sanitize_key', array_map( 'strval', $tasks ) )
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function large_visit_tasks() {
		return array(
			'plumbing_pipework_supply_lines',
			'in_wall_concealed_cable_work',
			'concrete_cement_work',
			'siding_fences',
			'built_ins_wood_repairs',
			'window_treatment_work',
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function extended_visit_tasks() {
		return array(
			'door_work_hardware',
			'finish_carpentry_trim',
			'deck_porch',
			'electrical_smart_home_installation',
			'lighting_ceiling_fans',
		);
	}

	/**
	 * @param array<int, string> $tasks Tasks.
	 * @param string $description Description.
	 * @param bool $is_project Project flag.
	 * @param array<string, mixed> $request Request.
	 * @param string $rate_family Rate family.
	 * @return bool
	 */
	protected function has_project_signal( array $tasks, $description, $is_project, array $request, $rate_family ) {
		if ( $is_project || 'project_custom' === $rate_family || in_array( 'larger_scale_work', $tasks, true ) ) {
			return true;
		}

		$text = $this->combined_scope_text( $description, $request );

		return (bool) preg_match( '/(multiple rooms|multi-room|whole house|full house|remodel|renovation|addition|planning-first|planning first|consultation-first|consultation first|broader scope|multi-step|larger-scale|larger scale|project-style|project style|custom installation)/', $text );
	}

	/**
	 * @param array<int, string> $tasks Tasks.
	 * @param string $description Description.
	 * @param array<string, mixed> $request Request.
	 * @param string $rate_family Rate family.
	 * @return bool
	 */
	protected function has_large_visit_signal( array $tasks, $description, array $request, $rate_family ) {
		if ( count( $tasks ) >= 4 ) {
			return true;
		}

		if ( in_array( $rate_family, array( 'premium_specialty', 'exterior_premium' ), true ) ) {
			return true;
		}

		if ( count( array_intersect( $tasks, $this->large_visit_tasks() ) ) > 0 ) {
			return true;
		}

		return $this->has_large_scope_signal( $description, $request );
	}

	/**
	 * @param array<int, string> $tasks Tasks.
	 * @param string $description Description.
	 * @param array<string, mixed> $request Request.
	 * @param string $rate_family Rate family.
	 * @return bool
	 */
	protected function has_extended_visit_signal( array $tasks, $description, array $request, $rate_family ) {
		if ( count( $tasks ) >= 2 && count( $tasks ) <= 3 ) {
			return true;
		}

		if ( 'installation_specialty' === $rate_family ) {
			return true;
		}

		if ( count( array_intersect( $tasks, $this->extended_visit_tasks() ) ) > 0 ) {
			return true;
		}

		return $this->has_medium_scope_signal( $description, $request );
	}

	/**
	 * @param string $description Description.
	 * @param array<string, mixed> $request Request.
	 * @return bool
	 */
	protected function has_large_scope_signal( $description, array $request ) {
		$text = $this->combined_scope_text( $description, $request );

		return (bool) preg_match( '/(full day|all day|long single-day|single day|larger pipework|pipe rerouting|reroute|re-route|layout changes|layout change|new line|water line installation|propress|copper modifications|pex modifications|concrete|cement|siding|fence|in-wall|hidden wire|built-in|custom wood|structural|bigger woodwork|extensive)/', $text );
	}

	/**
	 * @param string $description Description.
	 * @param array<string, mixed> $request Request.
	 * @return bool
	 */
	protected function has_medium_scope_signal( $description, array $request ) {
		$text = $this->combined_scope_text( $description, $request );

		return (bool) preg_match( '/(not trivial|medium scope|normal visit|supply line|door work|window work|window treatment|finish carpentry|trim work|ceiling fan|lighting|multiple fixtures|deck repair|porch repair|still normal visit)/', $text );
	}

	/**
	 * @param string $description Description.
	 * @param array<string, mixed> $request Request.
	 * @return string
	 */
	protected function combined_scope_text( $description, array $request ) {
		$app_state = ! empty( $request['app_state'] ) && is_array( $request['app_state'] ) ? $request['app_state'] : array();
		$pieces    = array(
			(string) $description,
			(string) ( $app_state['photo_context_summary'] ?? '' ),
			(string) ( $app_state['visible_tasks_summary'] ?? '' ),
			(string) ( $app_state['visual_estimate_notes'] ?? '' ),
			(string) ( $app_state['safety_summary'] ?? '' ),
		);

		return strtolower( trim( implode( ' ', array_filter( $pieces ) ) ) );
	}
}
