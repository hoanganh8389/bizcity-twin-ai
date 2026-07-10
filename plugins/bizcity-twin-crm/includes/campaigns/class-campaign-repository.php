<?php
/**
 * BizCity CRM — Campaign Repository (PHASE 0.35 M6.W1).
 *
 * CRUD over `bizcity_crm_campaigns` + read-side helpers for the visit ledger.
 * Soft-delete via `deleted_at` (R-PAR-soft-delete). All emit events through
 * BizCity_CRM_Event_Emitter so M2 automation rules can subscribe.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W1)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Campaign_Repository {

	const STATUS_DRAFT     = 'draft';
	const STATUS_ACTIVE    = 'active';
	const STATUS_PAUSED    = 'paused';
	const STATUS_ARCHIVED  = 'archived';

	/* PHASE 0.35 M6.W10 — scenario action enum (R-CMP-1). */
	const ACTION_RUN_SHORTCODE    = 'run_shortcode';
	const ACTION_SEND_MESSAGE     = 'send_message';
	const ACTION_KG_GROUNDED      = 'kg_grounded_reply';
	const ACTION_DELAY_ONLY       = 'delay_only';

	const REMINDER_UNIT_MINUTES = 'minutes';
	const REMINDER_UNIT_HOURS   = 'hours';
	const REMINDER_UNIT_DAYS    = 'days';

	const MAX_SCENARIO_ATTRS = 20;

	/* ============================================================
	 * CRUD — campaigns
	 * ============================================================ */

	/**
	 * Insert a new campaign. `code` must be unique; auto-generated when blank.
	 *
	 * @param array $data {
	 *     @type string $code               unique slug (a-z0-9_-, ≤64). Auto if empty.
	 *     @type string $name               display name. Required.
	 *     @type string $status             draft|active|paused|archived (default: draft).
	 *     @type string $landing_url        absolute URL the QR points to.
	 *     @type array  $utm                { source, medium, campaign, content, term }.
	 *     @type int    $loyalty_points_award integer (≥0) awarded on conversion (M6.W5).
	 *     @type int    $notebook_id        attached KG notebook (optional).
	 *     @type array  $notes              free-form metadata, JSON-encoded.
	 *     @type string $starts_at          MySQL DATETIME (UTC).
	 *     @type string $ends_at            MySQL DATETIME (UTC).
	 * }
	 * @return int|WP_Error campaign_id, or WP_Error on validation/DB failure.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$now = current_time( 'mysql' );

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( $name === '' ) {
			return new WP_Error( 'bizcity_crm_campaign_invalid', 'name is required' );
		}

		$code = self::sanitize_code( (string) ( $data['code'] ?? '' ) );
		if ( $code === '' ) {
			$code = self::generate_unique_code( $name );
		} else {
			// Ensure uniqueness — refuse on collision (caller can retry with edit).
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE code = %s", $code ) );
			if ( $exists ) {
				return new WP_Error( 'bizcity_crm_campaign_code_exists', sprintf( 'code "%s" already used', $code ) );
			}
		}

		$utm = is_array( $data['utm'] ?? null ) ? $data['utm'] : array();
		$row = array(
			'code'                 => $code,
			'name'                 => $name,
			'status'               => self::sanitize_status( (string) ( $data['status'] ?? self::STATUS_DRAFT ) ),
			'landing_url'          => self::sanitize_url( (string) ( $data['landing_url'] ?? '' ) ),
			'utm_source'           => self::trim_utm( $utm['source']   ?? '' ),
			'utm_medium'           => self::trim_utm( $utm['medium']   ?? '' ),
			'utm_campaign'         => self::trim_utm( $utm['campaign'] ?? $code ),
			'utm_content'          => self::trim_utm( $utm['content']  ?? '' ),
			'utm_term'             => self::trim_utm( $utm['term']     ?? '' ),
			'loyalty_points_award' => max( 0, (int) ( $data['loyalty_points_award'] ?? 0 ) ),
			'notebook_id'          => isset( $data['notebook_id'] ) ? (int) $data['notebook_id'] : null,
			'welcome_template_id'  => self::nullable_int( $data['welcome_template_id'] ?? null ),
			'bound_character_id'   => self::nullable_int( $data['bound_character_id']  ?? null ),
			'bound_notebook_id'    => self::nullable_int( $data['bound_notebook_id']   ?? null ),

			/* PHASE 0.35 M6.W10 — scenario builder fields. */
			'scenario_action_type'         => self::sanitize_action_type( (string) ( $data['scenario_action_type'] ?? self::ACTION_SEND_MESSAGE ) ),
			'scenario_shortcode'           => self::nullable_text( $data['scenario_shortcode'] ?? null ),
			'scenario_template'            => self::nullable_text( $data['scenario_template']  ?? null ),
			'scenario_attrs_json'          => self::encode_attrs( $data['scenario_attrs'] ?? null ),
			'scenario_prompt'              => self::nullable_text( $data['scenario_prompt'] ?? null ),
			'reminder_delay'               => max( 0, (int) ( $data['reminder_delay'] ?? 0 ) ),
			'reminder_unit'                => self::sanitize_reminder_unit( (string) ( $data['reminder_unit'] ?? self::REMINDER_UNIT_MINUTES ) ),
			'reminder_text'                => self::nullable_text( $data['reminder_text'] ?? null ),
			'reminder_only'                => ! empty( $data['reminder_only'] ) ? 1 : 0,
			'imported_from_bizgpt_flow_id' => self::nullable_int( $data['imported_from_bizgpt_flow_id'] ?? null ),
			// [2026-06-14 Johnny Chu] PHASE-0.45 QR-FIX — bind campaign to a specific FB Page (NULL = any page).
			'fb_page_id'                   => self::sanitize_page_id( $data['fb_page_id'] ?? null ),

			'notes_json'           => isset( $data['notes'] ) ? wp_json_encode( $data['notes'] ) : null,
			'starts_at'            => self::sanitize_datetime( $data['starts_at'] ?? null ),
			'ends_at'              => self::sanitize_datetime( $data['ends_at']   ?? null ),
			// [2026-06-07 Johnny Chu] PHASE-0.40 G4.1 — multi-variant random campaign (Deplao parity)
			'variants_json'        => isset( $data['variants'] ) ? wp_json_encode( $data['variants'] ) : null,
			'created_by'           => get_current_user_id() ?: null,
			'created_at'           => $now,
			'updated_at'           => $now,
		);

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			return new WP_Error( 'bizcity_crm_campaign_db', $wpdb->last_error ?: 'insert failed' );
		}
		$id = (int) $wpdb->insert_id;

		BizCity_CRM_Event_Emitter::emit( 'crm_campaign_created', array(
			'campaign_id' => $id,
			'code'        => $code,
			'status'      => $row['status'],
		) );

		return $id;
	}

	public static function update( int $id, array $patch ) {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$existing = self::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'bizcity_crm_campaign_not_found', 'campaign not found' );
		}

		$row = array( 'updated_at' => current_time( 'mysql' ) );

		if ( array_key_exists( 'name', $patch ) ) {
			$name = trim( (string) $patch['name'] );
			if ( $name === '' ) {
				return new WP_Error( 'bizcity_crm_campaign_invalid', 'name cannot be empty' );
			}
			$row['name'] = $name;
		}
		if ( array_key_exists( 'status', $patch ) ) {
			$row['status'] = self::sanitize_status( (string) $patch['status'] );
		}
		if ( array_key_exists( 'landing_url', $patch ) ) {
			$row['landing_url'] = self::sanitize_url( (string) $patch['landing_url'] );
		}
		if ( is_array( $patch['utm'] ?? null ) ) {
			$utm = $patch['utm'];
			if ( array_key_exists( 'source',   $utm ) ) { $row['utm_source']   = self::trim_utm( $utm['source'] ); }
			if ( array_key_exists( 'medium',   $utm ) ) { $row['utm_medium']   = self::trim_utm( $utm['medium'] ); }
			if ( array_key_exists( 'campaign', $utm ) ) { $row['utm_campaign'] = self::trim_utm( $utm['campaign'] ); }
			if ( array_key_exists( 'content',  $utm ) ) { $row['utm_content']  = self::trim_utm( $utm['content'] ); }
			if ( array_key_exists( 'term',     $utm ) ) { $row['utm_term']     = self::trim_utm( $utm['term'] ); }
		}
		if ( array_key_exists( 'loyalty_points_award', $patch ) ) {
			$row['loyalty_points_award'] = max( 0, (int) $patch['loyalty_points_award'] );
		}
		if ( array_key_exists( 'notebook_id', $patch ) ) {
			$row['notebook_id'] = $patch['notebook_id'] !== null ? (int) $patch['notebook_id'] : null;
		}
		if ( array_key_exists( 'welcome_template_id', $patch ) ) {
			$row['welcome_template_id'] = self::nullable_int( $patch['welcome_template_id'] );
		}
		if ( array_key_exists( 'bound_character_id', $patch ) ) {
			$row['bound_character_id'] = self::nullable_int( $patch['bound_character_id'] );
		}
		if ( array_key_exists( 'bound_notebook_id', $patch ) ) {
			$row['bound_notebook_id'] = self::nullable_int( $patch['bound_notebook_id'] );
		}
		/* PHASE 0.35 M6.W10 — scenario builder fields. */
		if ( array_key_exists( 'scenario_action_type', $patch ) ) {
			$row['scenario_action_type'] = self::sanitize_action_type( (string) $patch['scenario_action_type'] );
		}
		if ( array_key_exists( 'scenario_shortcode', $patch ) ) {
			$row['scenario_shortcode'] = self::nullable_text( $patch['scenario_shortcode'] );
		}
		if ( array_key_exists( 'scenario_template', $patch ) ) {
			$row['scenario_template'] = self::nullable_text( $patch['scenario_template'] );
		}
		if ( array_key_exists( 'scenario_attrs', $patch ) ) {
			$row['scenario_attrs_json'] = self::encode_attrs( $patch['scenario_attrs'] );
		}
		if ( array_key_exists( 'scenario_prompt', $patch ) ) {
			$row['scenario_prompt'] = self::nullable_text( $patch['scenario_prompt'] );
		}
		if ( array_key_exists( 'reminder_delay', $patch ) ) {
			$row['reminder_delay'] = max( 0, (int) $patch['reminder_delay'] );
		}
		if ( array_key_exists( 'reminder_unit', $patch ) ) {
			$row['reminder_unit'] = self::sanitize_reminder_unit( (string) $patch['reminder_unit'] );
		}
		if ( array_key_exists( 'reminder_text', $patch ) ) {
			$row['reminder_text'] = self::nullable_text( $patch['reminder_text'] );
		}
		if ( array_key_exists( 'reminder_only', $patch ) ) {
			$row['reminder_only'] = ! empty( $patch['reminder_only'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'imported_from_bizgpt_flow_id', $patch ) ) {
			$row['imported_from_bizgpt_flow_id'] = self::nullable_int( $patch['imported_from_bizgpt_flow_id'] );
		}
		// [2026-06-14 Johnny Chu] PHASE-0.45 QR-FIX — allow updating the bound FB Page.
		if ( array_key_exists( 'fb_page_id', $patch ) ) {
			$row['fb_page_id'] = self::sanitize_page_id( $patch['fb_page_id'] );
		}
		if ( array_key_exists( 'notes', $patch ) ) {
			$row['notes_json'] = $patch['notes'] !== null ? wp_json_encode( $patch['notes'] ) : null;
		}
		if ( array_key_exists( 'starts_at', $patch ) ) {
			$row['starts_at'] = self::sanitize_datetime( $patch['starts_at'] );
		}
		if ( array_key_exists( 'ends_at', $patch ) ) {
			$row['ends_at'] = self::sanitize_datetime( $patch['ends_at'] );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.1 — multi-variant support
		if ( array_key_exists( 'variants', $patch ) ) {
			$row['variants_json'] = $patch['variants'] !== null ? wp_json_encode( $patch['variants'] ) : null;
		}

		$ok = $wpdb->update( $tbl, $row, array( 'id' => $id ) );
		if ( false === $ok ) {
			return new WP_Error( 'bizcity_crm_campaign_db', $wpdb->last_error ?: 'update failed' );
		}

		BizCity_CRM_Event_Emitter::emit( 'crm_campaign_updated', array(
			'campaign_id' => $id,
			'changed'     => array_keys( $row ),
		) );

		return self::get( $id );
	}

	/** Soft delete — sets `deleted_at`. */
	public static function delete( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$now = current_time( 'mysql' );
		$ok = $wpdb->update( $tbl, array( 'deleted_at' => $now, 'status' => self::STATUS_ARCHIVED, 'updated_at' => $now ), array( 'id' => $id ) );
		if ( $ok ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_campaign_deleted', array( 'campaign_id' => $id ) );
		}
		return (bool) $ok;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d AND deleted_at IS NULL", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$code = self::sanitize_code( $code );
		if ( $code === '' ) { return null; }
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE code = %s AND deleted_at IS NULL", $code ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array $args { status?, q?, limit=50, offset=0 }
	 */
	public static function list( array $args = array() ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();

		$where  = array( 'deleted_at IS NULL' );
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = self::sanitize_status( (string) $args['status'] );
		}
		if ( ! empty( $args['q'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$where[]  = '(name LIKE %s OR code LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	/* ============================================================
	 * Visits — read-only helpers (write-side lands in M6.W3 Tracker)
	 * ============================================================ */

	public static function visits_count( int $campaign_id, ?string $since = null ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
		if ( $since ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl} WHERE campaign_id = %d AND created_at >= %s",
				$campaign_id, $since
			) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE campaign_id = %d", $campaign_id ) );
	}

	public static function conversions_count( int $campaign_id ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl} WHERE campaign_id = %d AND converted_contact_id IS NOT NULL",
			$campaign_id
		) );
	}

	/* ============================================================
	 * Internals
	 * ============================================================ */

	private static function hydrate( array $row ): array {
		$row['id']                   = (int) $row['id'];
		$row['loyalty_points_award'] = (int) $row['loyalty_points_award'];
		$row['notebook_id']          = $row['notebook_id'] !== null ? (int) $row['notebook_id'] : null;
		$row['welcome_template_id']  = isset( $row['welcome_template_id'] ) && $row['welcome_template_id'] !== null ? (int) $row['welcome_template_id'] : null;
		$row['bound_character_id']   = isset( $row['bound_character_id'] )  && $row['bound_character_id']  !== null ? (int) $row['bound_character_id']  : null;
		$row['bound_notebook_id']    = isset( $row['bound_notebook_id'] )   && $row['bound_notebook_id']   !== null ? (int) $row['bound_notebook_id']   : null;
		$row['created_by']           = $row['created_by']  !== null ? (int) $row['created_by']  : null;
		$row['notes']                = $row['notes_json']  ? json_decode( (string) $row['notes_json'], true ) : null;

		/* PHASE 0.35 M6.W10 — scenario fields hydration. */
		$row['scenario_action_type']         = isset( $row['scenario_action_type'] ) ? (string) $row['scenario_action_type'] : self::ACTION_SEND_MESSAGE;
		$row['scenario_attrs']               = isset( $row['scenario_attrs_json'] ) && $row['scenario_attrs_json']
			? ( json_decode( (string) $row['scenario_attrs_json'], true ) ?: array() )
			: array();
		$row['reminder_delay']               = isset( $row['reminder_delay'] ) ? (int) $row['reminder_delay'] : 0;
		$row['reminder_unit']                = isset( $row['reminder_unit'] ) ? (string) $row['reminder_unit'] : self::REMINDER_UNIT_MINUTES;
		$row['reminder_only']                = ! empty( $row['reminder_only'] ) ? 1 : 0;
		$row['imported_from_bizgpt_flow_id'] = isset( $row['imported_from_bizgpt_flow_id'] ) && $row['imported_from_bizgpt_flow_id']
			? (int) $row['imported_from_bizgpt_flow_id']
			: null;

		$row['utm']                  = array(
			'source'   => $row['utm_source'],
			'medium'   => $row['utm_medium'],
			'campaign' => $row['utm_campaign'],
			'content'  => $row['utm_content'],
			'term'     => $row['utm_term'],
		);

		/* Convenience — pre-rendered ref token (camp_<x>) for FE LinkBox. */
		if ( class_exists( 'BizCity_CRM_Campaign_Ref_Codec' ) ) {
			$row['ref'] = BizCity_CRM_Campaign_Ref_Codec::encode( (int) $row['id'] );
		}
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.1 — decode variants_json
		$row['variants'] = ( isset( $row['variants_json'] ) && $row['variants_json'] )
			? ( json_decode( (string) $row['variants_json'], true ) ?: array() )
			: array();

		return $row;
	}

	private static function sanitize_code( string $code ): string {
		$code = strtolower( trim( $code ) );
		$code = preg_replace( '/[^a-z0-9_-]+/', '-', $code );
		$code = trim( (string) $code, '-' );
		return substr( (string) $code, 0, 64 );
	}

	private static function generate_unique_code( string $name ): string {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		$base = self::sanitize_code( $name ) ?: 'camp';
		$base = substr( $base, 0, 56 );
		// Try base, then base-2, base-3...
		$try = $base;
		for ( $i = 1; $i < 50; $i++ ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE code = %s", $try ) );
			if ( ! $exists ) { return $try; }
			$try = $base . '-' . ( $i + 1 );
		}
		return $base . '-' . wp_generate_password( 6, false, false );
	}

	private static function sanitize_status( string $status ): string {
		$ok = array( self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_ARCHIVED );
		return in_array( $status, $ok, true ) ? $status : self::STATUS_DRAFT;
	}

	private static function sanitize_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		return $url ?: '';
	}

	private static function trim_utm( $val ): ?string {
		$v = trim( (string) $val );
		if ( $v === '' ) { return null; }
		// UTM convention: lowercase, hyphen-safe, no spaces.
		return substr( preg_replace( '/\s+/', '-', $v ), 0, 120 );
	}

	private static function nullable_int( $val ): ?int {
		if ( $val === null || $val === '' ) { return null; }
		$i = (int) $val;
		return $i > 0 ? $i : null;
	}

	private static function sanitize_datetime( $val ): ?string {
		if ( ! $val ) { return null; }
		$ts = is_numeric( $val ) ? (int) $val : strtotime( (string) $val );
		if ( ! $ts ) { return null; }
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/* ============================================================
	 * PHASE 0.35 M6.W10 — scenario field sanitizers (R-CMP-1, R-CMP-5)
	 * ============================================================ */

	private static function sanitize_action_type( string $val ): string {
		$ok = array( self::ACTION_RUN_SHORTCODE, self::ACTION_SEND_MESSAGE, self::ACTION_KG_GROUNDED, self::ACTION_DELAY_ONLY );
		return in_array( $val, $ok, true ) ? $val : self::ACTION_SEND_MESSAGE;
	}

	private static function sanitize_reminder_unit( string $val ): string {
		$ok = array( self::REMINDER_UNIT_MINUTES, self::REMINDER_UNIT_HOURS, self::REMINDER_UNIT_DAYS );
		return in_array( $val, $ok, true ) ? $val : self::REMINDER_UNIT_MINUTES;
	}

	private static function nullable_text( $val ): ?string {
		if ( $val === null ) { return null; }
		$s = trim( (string) $val );
		return $s === '' ? null : $s;
	}

	/**
	 * Sanitize + JSON-encode the `scenario_attrs` array.
	 *
	 * Shape: `[ {key:string<=64, prompt:string<=500}, ... ]` — capped at
	 * MAX_SCENARIO_ATTRS entries (R-CMP-5: snapshot in 1 column, no side table).
	 *
	 * @param mixed $val Array, JSON string, or null.
	 * @return string|null JSON or null.
	 */
	private static function encode_attrs( $val ): ?string {
		if ( $val === null || $val === '' ) { return null; }
		if ( is_string( $val ) ) {
			$val = json_decode( $val, true );
		}
		if ( ! is_array( $val ) ) { return null; }

		$clean = array();
		foreach ( $val as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$key    = isset( $row['key'] )    ? trim( (string) $row['key'] )    : '';
			$prompt = isset( $row['prompt'] ) ? trim( (string) $row['prompt'] ) : '';
			if ( $key === '' ) { continue; }
			$key = preg_replace( '/[^A-Za-z0-9_\-]+/', '_', $key );
			$clean[] = array(
				'key'    => substr( (string) $key, 0, 64 ),
				'prompt' => substr( $prompt, 0, 500 ),
			);
			if ( count( $clean ) >= self::MAX_SCENARIO_ATTRS ) { break; }
		}
		return $clean ? wp_json_encode( $clean ) : null;
	}

	// [2026-06-14 Johnny Chu] PHASE-0.45 QR-FIX — sanitize a FB page_id (numeric string or null).
	private static function sanitize_page_id( $val ): ?string {
		if ( $val === null || $val === '' || $val === '0' ) { return null; }
		$s = preg_replace( '/[^0-9]/', '', (string) $val );
		return $s !== '' ? $s : null;
	}
}
