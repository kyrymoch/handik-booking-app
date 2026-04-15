<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Routing_Service {
	/**
	 * @param array<string, mixed> $request Request.
	 * @param array<string, mixed> $assistant Assistant.
	 * @return array<string, mixed>
	 */
	public function route( array $request, array $assistant = array() ) {
		$tasks       = ! empty( $request['selected_tasks'] ) ? (array) $request['selected_tasks'] : array();
		$description = strtolower( (string) ( $request['short_description'] ?? '' ) );
		$is_project  = ! empty( $request['is_project'] ) || ! empty( $assistant['is_project'] );
		$unsafe      = $this->detect_unsafe( $description, $tasks, $assistant );

		if ( $unsafe['unsafe_flag'] ) {
			return array(
				'service_family'    => 'safety_review',
				'rate_family'       => 'manual',
				'duration_bucket'   => 'project_consult',
				'booking_type'      => '',
				'assistant_summary' => ! empty( $assistant['assistant_summary'] ) ? $assistant['assistant_summary'] : 'Request flagged for manual safety review.',
				'estimate_notes'    => $assistant['estimate_notes'] ?? '',
				'status'            => 'unsafe',
				'routing_status'    => 'blocked',
				'unsafe_flag'       => 1,
				'unsafe_reason'     => $unsafe['unsafe_reason'],
			);
		}

		$booking_type = $this->booking_type( $tasks, $description, $is_project, $assistant );
		$enough       = ! empty( $assistant['enough_information'] ) || ( ! empty( $request['address_full'] ) && ( ! empty( $tasks ) || ! empty( $description ) ) );

		return array(
			'service_family'    => ! empty( $assistant['service_family'] ) ? sanitize_key( $assistant['service_family'] ) : $this->service_family( $tasks, $description ),
			'rate_family'       => ! empty( $assistant['rate_family'] ) ? sanitize_key( $assistant['rate_family'] ) : $this->rate_family( $booking_type ),
			'duration_bucket'   => ! empty( $assistant['duration_bucket'] ) ? sanitize_key( $assistant['duration_bucket'] ) : $this->duration_bucket( $booking_type, count( $tasks ) ),
			'booking_type'      => $booking_type,
			'assistant_summary' => ! empty( $assistant['assistant_summary'] ) ? sanitize_textarea_field( $assistant['assistant_summary'] ) : $this->summary( $booking_type, $tasks, $request ),
			'estimate_notes'    => ! empty( $assistant['estimate_notes'] ) ? sanitize_textarea_field( $assistant['estimate_notes'] ) : '',
			'status'            => $enough ? 'ready_for_booking' : 'needs_more_info',
			'routing_status'    => $enough ? 'complete' : 'awaiting_assistant',
			'unsafe_flag'       => 0,
			'unsafe_reason'     => '',
		);
	}

	protected function detect_unsafe( $description, array $tasks, array $assistant ) {
		if ( ! empty( $assistant['unsafe'] ) ) {
			return array( 'unsafe_flag' => 1, 'unsafe_reason' => sanitize_textarea_field( $assistant['unsafe_reason'] ?? 'Unsafe request detected.' ) );
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

	protected function booking_type( array $tasks, $description, $is_project, array $assistant ) {
		$allowed = array( 'standard_visit', 'extended_visit', 'large_visit', 'project_consultation' );
		if ( ! empty( $assistant['booking_type'] ) && in_array( $assistant['booking_type'], $allowed, true ) ) {
			return $assistant['booking_type'];
		}
		if ( $is_project ) {
			return 'project_consultation';
		}
		if ( count( $tasks ) >= 4 ) {
			return 'large_visit';
		}
		if ( count( $tasks ) >= 2 ) {
			return 'extended_visit';
		}
		if ( preg_match( '/(renovation|remodel|kitchen|bathroom|multiple rooms|full project)/', $description ) ) {
			return 'project_consultation';
		}
		return 'standard_visit';
	}

	protected function service_family( array $tasks, $description ) {
		$joined = strtolower( implode( ' ', $tasks ) . ' ' . $description );
		if ( preg_match( '/(electrical|switch|outlet|fixture|light)/', $joined ) ) {
			return 'electrical';
		}
		if ( preg_match( '/(plumbing|pipe|toilet|drain|faucet|sink)/', $joined ) ) {
			return 'plumbing';
		}
		if ( preg_match( '/(paint|drywall|patch|trim|door|window)/', $joined ) ) {
			return 'finish_carpentry';
		}
		if ( preg_match( '/(mount|assembly|furniture|tv|shelf)/', $joined ) ) {
			return 'assembly_install';
		}
		return 'general_handyman';
	}

	protected function rate_family( $booking_type ) {
		switch ( $booking_type ) {
			case 'project_consultation':
				return 'project_consult';
			case 'large_visit':
				return 'large_visit';
			case 'extended_visit':
				return 'extended_visit';
			default:
				return 'standard_visit';
		}
	}

	protected function duration_bucket( $booking_type, $task_count ) {
		switch ( $booking_type ) {
			case 'project_consultation':
				return 'project_consult';
			case 'large_visit':
				return '6_7_hours';
			case 'extended_visit':
				return $task_count >= 3 ? '6_7_hours' : '3_5_hours';
			default:
				return '1_2_hours';
		}
	}

	protected function summary( $booking_type, array $tasks, array $request ) {
		return sprintf(
			'Routed to %1$s for %2$s. Preferred timeframe: %3$s.',
			str_replace( '_', ' ', $booking_type ),
			! empty( $tasks ) ? implode( ', ', $tasks ) : 'general handyman work',
			! empty( $request['preferred_timeframe'] ) ? $request['preferred_timeframe'] : 'not specified'
		);
	}
}
