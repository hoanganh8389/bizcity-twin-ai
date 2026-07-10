<?php
/**
 * BizCity CRM — Submissions → Pipeline Bridge.
 *
 * When a submission's follow_status changes, this bridge automatically
 * creates or updates the linked bizcity_crm_opportunity to the correct stage.
 *
 * Status → Stage mapping (R-SUB-3 / R-SUB-4):
 *   new             → (no opportunity created)
 *   contacted       → prospecting
 *   qualified       → qualification
 *   proposal_sent   → proposal
 *   negotiating     → negotiation
 *   closed_won      → closed_won
 *   closed_lost     → closed_lost
 *   invalid         → (mark existing opportunity closed_lost if any)
 *
 * R-SUB-4: No stage downgrade — stage order is strictly ascending.
 *
 * @package BizCity_Twin_CRM
 * @since   1.31.0
 */

// [2026-07-05 Johnny Chu] PHASE-0.46 M3 — submission → pipeline bridge

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Submissions_Pipeline_Bridge' ) ) :

class BizCity_CRM_Submissions_Pipeline_Bridge {

	/**
	 * Ordered stage list (ascending). Used to enforce R-SUB-4 no-downgrade rule.
	 */
	const STAGE_ORDER = array(
		'prospecting'  => 1,
		'qualification'=> 2,
		'proposal'     => 3,
		'negotiation'  => 4,
		'closed_won'   => 5,
		'closed_lost'  => 5, // parallel terminal state
	);

	/**
	 * follow_status → opportunity stage mapping.
	 * NULL = do not create/update opportunity.
	 */
	const STATUS_TO_STAGE = array(
		'new'           => null,
		'contacted'     => 'prospecting',
		'qualified'     => 'qualification',
		'proposal_sent' => 'proposal',
		'negotiating'   => 'negotiation',
		'closed_won'    => 'closed_won',
		'closed_lost'   => 'closed_lost',
		'invalid'       => 'closed_lost',
	);

	public static function register(): void {
		add_action( 'bizcity_crm_submission_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * Triggered when a submission's follow_status changes.
	 *
	 * @param int    $sub_id     Submission ID.
	 * @param string $old_status Previous follow_status.
	 * @param string $new_status New follow_status.
	 */
	public static function on_status_changed( int $sub_id, string $old_status, string $new_status ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		$target_stage = isset( self::STATUS_TO_STAGE[ $new_status ] )
			? self::STATUS_TO_STAGE[ $new_status ]
			: null;

		// 'new' or unknown → do nothing
		if ( null === $target_stage ) {
			return;
		}

		if ( ! class_exists( 'BizCity_CRM_Submissions_Repo' ) ) {
			return;
		}

		$sub = BizCity_CRM_Submissions_Repo::get_by_id( $sub_id );
		if ( ! $sub ) {
			return;
		}

		$existing_opp_id = (int) ( $sub['pipeline_opp_id'] ?? 0 );

		if ( $existing_opp_id ) {
			self::maybe_update_stage( $existing_opp_id, $target_stage, $sub );
		} else {
			// Only create opportunity once status moves past 'new'
			$new_opp_id = self::create_opportunity( $sub, $target_stage );
			if ( $new_opp_id ) {
				BizCity_CRM_Submissions_Repo::set_pipeline_opp( $sub_id, $new_opp_id );
			}
		}
	}

	/**
	 * Update an existing opportunity's stage — respecting no-downgrade rule (R-SUB-4).
	 *
	 * @param int    $opp_id
	 * @param string $target_stage
	 * @param array  $sub
	 */
	private static function maybe_update_stage( int $opp_id, string $target_stage, array $sub ): void {
		global $wpdb;

		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$opp = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, stage, owner_id FROM `{$tbl}` WHERE id = %d AND deleted_at IS NULL", $opp_id ),
			ARRAY_A
		);

		if ( ! $opp ) {
			return;
		}

		$current_order = isset( self::STAGE_ORDER[ $opp['stage'] ] ) ? self::STAGE_ORDER[ $opp['stage'] ] : 0;
		$target_order  = isset( self::STAGE_ORDER[ $target_stage ] ) ? self::STAGE_ORDER[ $target_stage ] : 0;

		// R-SUB-4: no downgrade (allow only lateral or forward moves, except closed_lost which can go from any stage)
		if ( $target_stage !== 'closed_lost' && $target_order <= $current_order ) {
			return;
		}

		$update = array(
			'stage'      => $target_stage,
			'updated_at' => current_time( 'mysql' ),
		);

		// Sync owner_id with assigned user if available
		$assigned_user = (int) ( $sub['assigned_to_wp_user_id'] ?? 0 );
		if ( $assigned_user && ! $opp['owner_id'] ) {
			$update['owner_id'] = $assigned_user;
		}

		$wpdb->update(
			$tbl,
			$update,
			array( 'id' => $opp_id ),
			array_fill( 0, count( $update ), '%s' ),
			array( '%d' )
		);

		do_action( 'bizcity_crm_opportunity_stage_bridged', $opp_id, $opp['stage'], $target_stage, $sub['id'] );
	}

	/**
	 * Create a new opportunity from a submission.
	 *
	 * @param array  $sub
	 * @param string $target_stage
	 * @return int  New opportunity ID, or 0 on failure.
	 */
	private static function create_opportunity( array $sub, string $target_stage ): int {
		global $wpdb;

		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return 0;
		}

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();

		// Build a meaningful name: contact name + source label
		$contact_name = (string) ( $sub['contact_name'] ?? ( $sub['contact_email'] ?? ( $sub['contact_phone'] ?? '' ) ) );
		$source_label = ucwords( str_replace( '_', ' ', (string) ( $sub['source_type'] ?? 'Submission' ) ) );
		$opp_name     = trim( $contact_name ) ? $contact_name . ' — ' . $source_label : $source_label . ' #' . $sub['id'];
		$assigned_to  = (int) ( $sub['assigned_to_wp_user_id'] ?? 0 );
		$now          = current_time( 'mysql' );

		$ok = $wpdb->insert(
			$tbl,
			array(
				'name'        => sanitize_text_field( $opp_name ),
				'stage'       => $target_stage,
				'status'      => 'open',
				'owner_id'    => $assigned_to ?: null,
				'source'      => (string) ( $sub['source_type'] ?? '' ),
				'probability' => self::stage_probability( $target_stage ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return 0;
		}

		$opp_id = (int) $wpdb->insert_id;
		do_action( 'bizcity_crm_opportunity_bridged_from_submission', $opp_id, (int) $sub['id'] );

		return $opp_id;
	}

	/**
	 * Default probability per stage.
	 *
	 * @param string $stage
	 * @return int
	 */
	private static function stage_probability( string $stage ): int {
		$map = array(
			'prospecting'   => 10,
			'qualification' => 25,
			'proposal'      => 50,
			'negotiation'   => 70,
			'closed_won'    => 100,
			'closed_lost'   => 0,
		);
		return isset( $map[ $stage ] ) ? $map[ $stage ] : 10;
	}
}

endif; // class_exists BizCity_CRM_Submissions_Pipeline_Bridge
