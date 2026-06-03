<?php
/**
 * BizCoach Pro — Persona Tool Provider (skeleton, Sprint H/I).
 *
 * Producer-hub bridge: each active template surfaces as 1 tool
 * `create_coach_map_<slug>` with payload_schema built from template.questions[].
 *
 * Sprint H ships:
 *  - id/label/version
 *  - source kinds (single canonical 'coach_map')
 *  - get_tool_definitions() iterates registry
 *  - get_smart_source_chips() iterates registry
 *
 * Sprint I ships:
 *  - tool_create_coach_map() execution (creates coachee row + runs generators)
 *  - render_to_passages() reads bccm_gen_results legacy until bcpro_gen_results lands
 *  - Federation::stamp + bizcity_artifact_created emission
 *
 * @since 0.1.0 (PHASE-0.36 / R-PROD-HUB / R-PP)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Persona_Provider' ) ) { return; }

class BizCoach_Pro_Persona_Provider extends BizCity_Persona_Tool_Provider {

	const KIND_COACH_MAP = 'coach_map';

	public function id(): string { return 'bizcoach_pro'; }

	public function label(): string {
		return __( 'BizCoach Pro — Producer Hub', 'bizcoach-pro' );
	}

	public function version(): string { return BCPRO_VERSION; }

	public function get_source_kinds(): array {
		return [ self::KIND_COACH_MAP ];
	}

	public function get_tool_definitions(): array {
		$tools = [];
		$templates = BizCoach_Pro_Template_Registry::all( 'active' );
		foreach ( $templates as $tpl ) {
			$slug   = isset( $tpl['slug'] ) ? (string) $tpl['slug'] : '';
			$label  = isset( $tpl['label'] ) ? (string) $tpl['label'] : $slug;
			$icon   = isset( $tpl['icon'] ) ? (string) $tpl['icon'] : '🗺️';
			if ( $slug === '' ) { continue; }

			$tools[] = [
				'name'          => 'create_coach_map_' . $slug,
				'label'         => sprintf( '%s Tạo bản đồ %s', $icon, $label ),
				'description'   => isset( $tpl['description'] ) ? (string) $tpl['description'] : '',
				'slot_schema'   => self::questions_to_slot_schema( isset( $tpl['questions'] ) ? $tpl['questions'] : [] ),
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'callback'      => [ $this, 'tool_create_coach_map' ],
				'required_caps' => [ 'read' ],
				'tool_class'    => 'producer', /* R-MPRT-12 */
				'extra'         => [ 'template_slug' => $slug ],
			];
		}
		return $tools;
	}

	public function get_smart_source_chips(): array {
		$chips = [];
		foreach ( BizCoach_Pro_Template_Registry::all( 'active' ) as $tpl ) {
			$slug  = isset( $tpl['slug'] ) ? (string) $tpl['slug'] : '';
			$icon  = isset( $tpl['icon'] ) ? (string) $tpl['icon'] : '🗺️';
			$label = isset( $tpl['label'] ) ? (string) $tpl['label'] : $slug;
			if ( $slug === '' ) { continue; }
			$chips[] = [
				'tool'           => 'create_coach_map_' . $slug,
				'label'          => sprintf( '%s Tạo bản đồ %s', $icon, $label ),
				'icon'           => $icon,
				'action'         => 'persona_artifact_dialog',
				'payload_schema' => self::questions_to_payload_schema( isset( $tpl['questions'] ) ? $tpl['questions'] : [] ),
			];
		}
		return $chips;
	}

	/**
	 * Tool callback — creates a coachee row + triggers legacy generators.
	 *
	 * Sprint H: thin delegation to legacy bizcoach-map paths. We DO NOT
	 * write a new schema; instead we INSERT into `bccm_coachees` (1 artifact
	 * row) and invoke any registered generator functions per template via
	 * `bccm_save_gen_result()` — same path used by legacy AJAX handler at
	 * `bizcoach-map/includes/ajax-coach-map-generator.php`.
	 *
	 * For astro-specific templates (kind=astro_natal_chart) the legacy
	 * `tool_create_natal_chart` Intent callback is the source of truth and
	 * MUST be reached through the `bizcoach` persona — bizcoach_pro does
	 * not duplicate the freeastrologyapi.com fetch path (R-NO-CONFLICT.5).
	 *
	 * @param array $args ['template_slug', payload fields...]
	 * @param array $ctx  ['user_id'=>int]
	 * @return array|WP_Error  ['coachee_id'=>int, 'template_slug'=>string, 'status'=>'created'|'queued']
	 */
	public function tool_create_coach_map( $args, $ctx ) {
		global $wpdb;

		$args = is_array( $args ) ? $args : array();
		$ctx  = is_array( $ctx )  ? $ctx  : array();

		$slug = isset( $args['template_slug'] ) ? sanitize_key( (string) $args['template_slug'] ) : '';
		if ( $slug === '' && isset( $args['__tool_extra']['template_slug'] ) ) {
			$slug = sanitize_key( (string) $args['__tool_extra']['template_slug'] );
		}
		if ( $slug === '' ) {
			return new WP_Error( 'bcpro_missing_template', __( 'template_slug is required.', 'bizcoach-pro' ) );
		}

		$tpl = BizCoach_Pro_Template_Registry::get( $slug );
		if ( ! $tpl ) {
			return new WP_Error( 'bcpro_template_not_found', sprintf( 'Template not found: %s', $slug ), array( 'status' => 404 ) );
		}

		// Collect payload (nested or inline).
		$payload = isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : array();
		if ( empty( $payload ) ) {
			foreach ( $args as $k => $v ) {
				if ( $k === 'template_slug' || $k === '__tool_extra' ) { continue; }
				if ( is_scalar( $v ) ) { $payload[ $k ] = $v; }
			}
		}

		// Validate required fields per template.questions[].
		$missing = array();
		foreach ( (array) ( $tpl['questions'] ?? array() ) as $q ) {
			if ( ! is_array( $q ) || empty( $q['key'] ) ) { continue; }
			if ( ! empty( $q['required'] ) ) {
				$key = (string) $q['key'];
				if ( ! isset( $payload[ $key ] ) || $payload[ $key ] === '' ) {
					$missing[] = $key;
				}
			}
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'bcpro_missing_required',
				'Missing required field(s): ' . implode( ', ', $missing ),
				array( 'fields' => $missing, 'status' => 400 ) );
		}

		// Astro-typed templates must route through legacy persona to keep
		// freeastrologyapi.com pipeline intact (no duplicate fetch path).
		$base_type = isset( $tpl['base_type'] ) ? (string) $tpl['base_type'] : '';
		if ( $base_type === 'astro_coach' || isset( $payload['__force_astro'] ) ) {
			return new WP_Error(
				'bcpro_route_to_legacy_astro',
				__( 'Astro templates must use bizcoach persona (create_natal_chart). bizcoach_pro does not duplicate the astro fetch path.', 'bizcoach-pro' ),
				array( 'redirect_tool' => 'create_natal_chart', 'status' => 409 )
			);
		}

		$user_id = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id();
		$now     = current_time( 'mysql' );
		$tbl     = $wpdb->prefix . 'bccm_coachees';

		// INSERT into legacy bccm_coachees. We populate only universal columns
		// + extra_fields_json bag; legacy code paths will fill JSON columns
		// (ai_summary etc.) on subsequent generator runs.
		$insert = array(
			'user_id'       => $user_id,
			'platform_type' => 'bizcoach_pro',
			'coach_type'    => $slug,
			'full_name'     => isset( $payload['full_name'] ) ? sanitize_text_field( (string) $payload['full_name'] ) : '',
			'phone'         => isset( $payload['phone'] )     ? sanitize_text_field( (string) $payload['phone'] )     : '',
			'address'       => isset( $payload['address'] )   ? sanitize_text_field( (string) $payload['address'] )   : '',
			'dob'           => isset( $payload['dob'] )       ? sanitize_text_field( (string) $payload['dob'] )       : null,
			'extra_fields_json' => wp_json_encode( $payload ),
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		$ok = $wpdb->insert( $tbl, $insert );
		if ( false === $ok ) {
			return new WP_Error( 'bcpro_db_insert_failed', $wpdb->last_error, array( 'status' => 500 ) );
		}
		$coachee_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a bizcoach_pro coachee row is created. Generator
		 * runner (Sprint I) will iterate template.generators[] and call
		 * legacy `bccm_save_gen_result()` for each. For now: side-effect free.
		 */
		do_action( 'bcpro_coachee_created', $coachee_id, $slug, $payload, $user_id );

		return array(
			'coachee_id'    => $coachee_id,
			'template_slug' => $slug,
			'status'        => 'created',
		);
	}

	/**
	 * Render artifact → passages (R-PP-7) by reading legacy bccm_* directly.
	 *
	 * For kind='coach_map': read bccm_coachees + bccm_gen_results, emit
	 * - 1 passage for input payload (fields from extra_fields_json)
	 * - 1 passage per success row in bccm_gen_results
	 * Astro kinds are owned by legacy bizcoach persona (R-NO-CONFLICT.2).
	 *
	 * @param string $kind     Source kind, expected self::KIND_COACH_MAP.
	 * @param array  $artifact ['id'=>int] from federation registry (id == coachee_id).
	 * @return array  list of passages [['title','content','meta'=>['gen_key','format','template_slug','artifact_id']]]
	 */
	public function render_to_passages( string $kind, array $artifact ): array {
		if ( $kind !== self::KIND_COACH_MAP ) { return array(); }

		$coachee_id = isset( $artifact['id'] ) ? (int) $artifact['id'] : 0;
		if ( $coachee_id <= 0 && isset( $artifact['coachee_id'] ) ) {
			$coachee_id = (int) $artifact['coachee_id'];
		}
		if ( $coachee_id <= 0 ) { return array(); }

		if ( ! class_exists( 'BizCoach_Pro_Artifact_Service' ) ) {
			require_once BCPRO_DIR . 'includes/coaching/class-artifact-service.php';
		}
		$row = BizCoach_Pro_Artifact_Service::get_artifact( $coachee_id );
		if ( ! $row ) { return array(); }

		$slug     = (string) $row['coach_type'];
		$title    = (string) $row['title'];
		$passages = array();

		// Passage 0: payload summary (extra_fields_json).
		$extra = isset( $row['profile']['extra_fields_json'] )
			? (string) $row['profile']['extra_fields_json'] : '';
		$payload = $extra !== '' ? json_decode( $extra, true ) : null;
		if ( is_array( $payload ) && ! empty( $payload ) ) {
			$lines = array();
			foreach ( $payload as $k => $v ) {
				if ( $v === '' || $v === null ) { continue; }
				if ( is_array( $v ) ) { $v = wp_json_encode( $v ); }
				$lines[] = '- **' . sanitize_text_field( (string) $k ) . '**: ' . sanitize_text_field( (string) $v );
			}
			if ( ! empty( $lines ) ) {
				$passages[] = array(
					'title'   => $title . ' — ' . __( 'Thông tin đầu vào', 'bizcoach-pro' ),
					'content' => implode( "\n", $lines ),
					'meta'    => array(
						'gen_key'       => '__payload',
						'format'        => 'markdown',
						'template_slug' => $slug,
						'artifact_id'   => $coachee_id,
					),
				);
			}
		}

		// Passages: one per success generator result.
		foreach ( (array) $row['gens'] as $gen_key => $g ) {
			if ( ! is_array( $g ) || ( $g['status'] ?? '' ) !== 'success' ) { continue; }
			$content = '';
			$res = $g['result'] ?? null;
			if ( is_array( $res ) ) {
				if ( isset( $res['markdown'] ) && is_string( $res['markdown'] ) ) {
					$content = $res['markdown'];
				} elseif ( isset( $res['content'] ) && is_string( $res['content'] ) ) {
					$content = $res['content'];
				} elseif ( isset( $res['text'] ) && is_string( $res['text'] ) ) {
					$content = $res['text'];
				} else {
					$content = wp_json_encode( $res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
				}
			} else {
				$content = (string) $g['result_raw'];
			}
			if ( $content === '' ) { continue; }

			$passages[] = array(
				'title'   => $title . ' — ' . ( $g['label'] !== '' ? (string) $g['label'] : (string) $gen_key ),
				'content' => $content,
				'meta'    => array(
					'gen_key'       => (string) $gen_key,
					'format'        => 'markdown',
					'template_slug' => $slug,
					'artifact_id'   => $coachee_id,
				),
			);
		}

		return $passages;
	}

	/* ──── Helpers ──── */

	private static function questions_to_slot_schema( $questions ) {
		$out = [];
		if ( ! is_array( $questions ) ) { return $out; }
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || empty( $q['key'] ) ) { continue; }
			$type = isset( $q['type'] ) ? (string) $q['type'] : 'text';
			// Map JSON template types → slot_schema scalar types per R-PP-5.
			$map = [
				'text' => 'text', 'textarea' => 'text', 'select' => 'choice',
				'date' => 'date', 'time' => 'time', 'number' => 'text',
				'email' => 'text', 'tel' => 'text',
			];
			$out[ (string) $q['key'] ] = isset( $map[ $type ] ) ? $map[ $type ] : 'text';
		}
		return $out;
	}

	private static function questions_to_payload_schema( $questions ) {
		$out = [];
		if ( ! is_array( $questions ) ) { return $out; }
		foreach ( $questions as $q ) {
			if ( ! is_array( $q ) || empty( $q['key'] ) ) { continue; }
			$entry = [
				'type'     => isset( $q['type'] ) ? (string) $q['type'] : 'text',
				'required' => ! empty( $q['required'] ),
				'label'    => isset( $q['label'] ) ? (string) $q['label'] : (string) $q['key'],
			];
			if ( ! empty( $q['choices'] ) && is_array( $q['choices'] ) ) {
				$entry['choices'] = $q['choices'];
			}
			if ( isset( $q['default'] ) ) { $entry['default'] = $q['default']; }
			$out[ (string) $q['key'] ] = $entry;
		}
		return $out;
	}
}
