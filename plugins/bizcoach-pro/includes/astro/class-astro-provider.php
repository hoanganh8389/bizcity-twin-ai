<?php
/**
 * BizCoach Pro — Astrology Map Persona Provider.
 *
 * SCOPE — separate persona surface for the astrology / chiêm tinh tools so
 * an admin can bind ONE Twin Guru character to coaching templates
 * (`bizcoach_pro` provider) and ANOTHER character to astrology
 * (`bizcoach_astro` provider). Two providers = two independent dropdown
 * options in the Step 3 character bind UI.
 *
 * REPLACES — the legacy `BizCoach_Persona_Provider` (id=`bizcoach`) that
 * lived in `plugins/bizcoach-map/`. That bundled plugin has been removed
 * from production (2026-05-15). The DB tables it created
 * (`bccm_coachees`, `bccm_astro`, `bccm_transit_snapshots`,
 * `bccm_action_plans`) are now owned by bizcoach-pro via
 * `BizCoach_Pro_Artifact_Service` + `BizCoach_Pro_Installer`, so this
 * provider can read all astro artifacts without depending on the deleted
 * plugin.
 *
 * PATTERN — this class is the canonical example for the
 * "duplicate to add a new producer provider" recipe documented in
 * [PROVIDER-CANON.md §8](../PROVIDER-CANON.md). To add another producer
 * provider in the future:
 *
 *   1. Copy this file to `class-<your>-provider.php`.
 *   2. Rename the class + change `id()`, `label()`, `get_source_kinds()`.
 *   3. Replace the tools/chips arrays with your domain.
 *   4. Implement `render_to_passages()` against your own table.
 *   5. Register via `add_filter('bizcity_persona_tool_providers', …, 25)`
 *      in `bizcoach-pro.php` next to the existing provider registration.
 *
 * @since 0.2.0  Sprint K (2026-05-15)
 * @see   PHASE-0-RULE-PERSONA-PROVIDER.md (R-PP-1..R-PP-8)
 * @see   PROVIDER-CANON.md
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Provider' ) ) { return; }

class BizCoach_Pro_Astro_Provider extends BizCity_Persona_Tool_Provider {

	/* Source kinds owned by this provider (R-PP-3). */
	const KIND_NATAL   = 'astro_natal_chart';
	const KIND_TRANSIT = 'astro_transit_report';

	/**
	 * Sprint H.6 — system discriminator for the per-system provider split.
	 * 'all'    → legacy bundled provider (id=bizcoach_astro), all 5 tools.
	 * 'western'→ id=bizcoach_astro_western, only Western natal + transit + consult.
	 * 'vedic'  → id=bizcoach_astro_vedic,   only Vedic chart tool.
	 * 'chinese'→ id=bizcoach_astro_chinese, only BaZi tool.
	 */
	protected $system = 'all';

	public function __construct( $system = 'all' ) {
		$this->system = in_array( $system, array( 'all', 'western', 'vedic', 'chinese' ), true ) ? $system : 'all';
	}

	/* ───────────────────────────────────────────────────────────────
	 * R-PP-1 — Identity (per-system aware)
	 * ─────────────────────────────────────────────────────────────── */

	public function id(): string {
		switch ( $this->system ) {
			case 'western': return 'bizcoach_astro_western';
			case 'vedic':   return 'bizcoach_astro_vedic';
			case 'chinese': return 'bizcoach_astro_chinese';
		}
		return 'bizcoach_astro';
	}

	public function label(): string {
		switch ( $this->system ) {
			case 'western': return __( 'Astrology — Western (Phương Tây)', 'bizcoach-pro' );
			case 'vedic':   return __( 'Astrology — Vedic (Ấn Độ / Jyotish)', 'bizcoach-pro' );
			case 'chinese': return __( 'Astrology — Chinese (Tứ Trụ / BaZi)', 'bizcoach-pro' );
		}
		return __( 'Astrology Map (Chiêm tinh)', 'bizcoach-pro' );
	}

	public function version(): string {
		return BCPRO_VERSION;
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-3 — Source kinds owned by THIS provider
	 * ────────────────────────────────────────────────────────────── */

	public function get_source_kinds(): array {
		return [ self::KIND_NATAL, self::KIND_TRANSIT ];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-5 — Tool definitions
	 * ────────────────────────────────────────────────────────────── */

	public function get_tool_definitions(): array {
		$all = $this->all_tool_definitions();
		if ( $this->system === 'all' ) { return $all; }
		$allowed = $this->allowed_tool_names_for_system();
		return array_values( array_filter( $all, function ( $t ) use ( $allowed ) {
			return in_array( $t['name'], $allowed, true );
		} ) );
	}

	private function allowed_tool_names_for_system(): array {
		switch ( $this->system ) {
			case 'western': return array( 'create_natal_chart', 'create_transit_map', 'astro_consult' );
			case 'vedic':   return array( 'create_vedic_chart', 'astro_consult' );
			case 'chinese': return array( 'create_bazi_chart', 'astro_consult' );
		}
		return array();
	}

	private function all_tool_definitions(): array {
		return [
			[
				'name'          => 'create_natal_chart',
				'label'         => __( 'Tạo bản đồ sao', 'bizcoach-pro' ),
				'description'   => __( 'Sinh natal chart từ họ tên, ngày, giờ, nơi sinh.', 'bizcoach-pro' ),
				'slot_schema'   => [
					'full_name'   => 'text',
					'dob'         => 'date',
					'birth_time'  => 'time',
					'birth_place' => 'text',
					'gender'      => 'choice',
				],
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'callback'      => [ $this, 'tool_create_natal_chart' ],
				'required_caps' => [ 'read' ],
				'tool_class'    => 'producer', /* R-MPRT-12 */
			],
			[
				'name'          => 'create_transit_map',
				'label'         => __( 'Tạo bản đồ vận hạn', 'bizcoach-pro' ),
				'description'   => __( 'Tạo transit chart cho khoảng thời gian (tuần/tháng/năm).', 'bizcoach-pro' ),
				'slot_schema'   => [
					'time_range' => 'choice',
				],
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'callback'      => [ $this, 'tool_create_transit_map' ],
				'required_caps' => [ 'read' ],
				'tool_class'    => 'producer',
			],
			[
				'name'          => 'astro_consult',
				'label'         => __( 'Tư vấn chiêm tinh', 'bizcoach-pro' ),
				'description'   => __( 'Hỏi Twin Guru chiêm tinh về vận mệnh, bản đồ sao.', 'bizcoach-pro' ),
				'slot_schema'   => [
					'prompt'        => 'text',
					'prompt_images' => 'image',
				],
				'side_effect'   => 'external',
				'cost_class'    => 'high',
				'callback'      => [ $this, 'tool_astro_consult' ],
				'required_caps' => [ 'read' ],
				'tool_class'    => 'producer',
			],
			/* PHASE-0.2 Sprint G.3 — Vedic + Chinese tools (live via gateway client). */
			[
				'name'          => 'create_vedic_chart',
				'label'         => __( 'Tạo Vedic chart (D1+D9+Dasha)', 'bizcoach-pro' ),
				'description'   => __( 'Sinh Vedic natal (Lagna + 9 planets + 12 houses + D1/D9 vargas + Vimshottari dasha).', 'bizcoach-pro' ),
				'slot_schema'   => [
					'full_name'   => 'text',
					'dob'         => 'date',
					'birth_time'  => 'time',
					'birth_place' => 'text',
				],
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'callback'      => [ $this, 'tool_create_vedic_chart' ],
				'required_caps' => [ 'read' ],
				'tool_class'    => 'producer',
			],
			[
				'name'          => 'create_bazi_chart',
				'label'         => __( 'Tạo Tứ Trụ (Bát Tự / BaZi)', 'bizcoach-pro' ),
				'description'   => __( 'Sinh bát tự năm-tháng-ngày-giờ + Ngũ hành cường nhược.', 'bizcoach-pro' ),
				'slot_schema'   => [
					'full_name'   => 'text',
					'dob'         => 'date',
					'birth_time'  => 'time',
					'birth_place' => 'text',
					'gender'      => 'choice',
				],
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'callback'      => [ $this, 'tool_create_bazi_chart' ],
				'required_caps' => [ 'read' ],
				'tool_class'    => 'producer',
			],
		];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-1 — Smart source chips (Twin Guru panel buttons)
	 * ────────────────────────────────────────────────────────────── */

	public function get_smart_source_chips(): array {
		$all = $this->all_smart_source_chips();
		if ( $this->system === 'all' ) { return $all; }
		$allowed = $this->allowed_tool_names_for_system();
		return array_values( array_filter( $all, function ( $c ) use ( $allowed ) {
			return in_array( $c['tool'], $allowed, true );
		} ) );
	}

	private function all_smart_source_chips(): array {
		return [
			[
				'tool'               => 'create_natal_chart',
				'label'              => __( 'Tạo bản đồ sao', 'bizcoach-pro' ),
				'icon'               => '🔮',
				'action'             => 'persona_artifact_dialog',
				'requires_user_data' => [ 'dob', 'birth_time', 'birth_place' ],
				'payload_schema'     => [
					'full_name'   => [ 'type' => 'text', 'required' => true,  'label' => __( 'Họ tên', 'bizcoach-pro' ) ],
					'dob'         => [ 'type' => 'date', 'required' => true,  'label' => __( 'Ngày sinh', 'bizcoach-pro' ) ],
					'birth_time'  => [ 'type' => 'time', 'required' => true,  'label' => __( 'Giờ sinh', 'bizcoach-pro' ) ],
					'birth_place' => [ 'type' => 'text', 'required' => true,  'label' => __( 'Nơi sinh', 'bizcoach-pro' ) ],
					'gender'      => [
						'type'     => 'choice',
						'required' => false,
						'label'    => __( 'Giới tính', 'bizcoach-pro' ),
						'choices'  => [ 'male' => '👨 Nam', 'female' => '👩 Nữ' ],
					],
				],
			],
			[
				'tool'               => 'create_transit_map',
				'label'              => __( 'Bản đồ vận hạn', 'bizcoach-pro' ),
				'icon'               => '🌀',
				'action'             => 'persona_artifact_dialog',
				'requires_user_data' => [ 'natal_chart' ],
				'payload_schema'     => [
					'time_range' => [
						'type'     => 'choice',
						'required' => true,
						'label'    => __( 'Khoảng thời gian', 'bizcoach-pro' ),
						'choices'  => [
							'this_week'  => '📅 Tuần này',
							'this_month' => '📅 Tháng này',
							'this_year'  => '📅 Năm nay',
						],
					],
				],
			],
			/* Sprint H.6 — chips for the per-system providers. */
			[
				'tool'               => 'create_vedic_chart',
				'label'              => __( 'Tạo Vedic chart', 'bizcoach-pro' ),
				'icon'               => '🕉️',
				'action'             => 'persona_artifact_dialog',
				'requires_user_data' => [ 'dob', 'birth_time', 'birth_place' ],
				'payload_schema'     => [
					'full_name'   => [ 'type' => 'text', 'required' => true, 'label' => __( 'Họ tên', 'bizcoach-pro' ) ],
					'dob'         => [ 'type' => 'date', 'required' => true, 'label' => __( 'Ngày sinh', 'bizcoach-pro' ) ],
					'birth_time'  => [ 'type' => 'time', 'required' => true, 'label' => __( 'Giờ sinh', 'bizcoach-pro' ) ],
					'birth_place' => [ 'type' => 'text', 'required' => true, 'label' => __( 'Nơi sinh', 'bizcoach-pro' ) ],
				],
			],
			[
				'tool'               => 'create_bazi_chart',
				'label'              => __( 'Tạo Tứ Trụ (BaZi)', 'bizcoach-pro' ),
				'icon'               => '☯️',
				'action'             => 'persona_artifact_dialog',
				'requires_user_data' => [ 'dob', 'birth_time', 'birth_place' ],
				'payload_schema'     => [
					'full_name'   => [ 'type' => 'text', 'required' => true, 'label' => __( 'Họ tên', 'bizcoach-pro' ) ],
					'dob'         => [ 'type' => 'date', 'required' => true, 'label' => __( 'Ngày sinh', 'bizcoach-pro' ) ],
					'birth_time'  => [ 'type' => 'time', 'required' => true, 'label' => __( 'Giờ sinh', 'bizcoach-pro' ) ],
					'birth_place' => [ 'type' => 'text', 'required' => true, 'label' => __( 'Nơi sinh', 'bizcoach-pro' ) ],
					'gender'      => [
						'type'     => 'choice',
						'required' => false,
						'label'    => __( 'Giới tính', 'bizcoach-pro' ),
						'choices'  => [ 'male' => '👨 Nam', 'female' => '👩 Nữ' ],
					],
				],
			],
		];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-7 — Artifact → markdown passages
	 *
	 * Read existing rows from `bccm_coachees` (filtered to those that have
	 * an astro snapshot) + `bccm_astro` to build a passage list per
	 * coachee. Used by KG ingest when the user picks an astro artifact
	 * from the FE persona dialog.
	 * ────────────────────────────────────────────────────────────── */

	public function render_to_passages( string $kind, array $artifact ): array {
		if ( $kind !== self::KIND_NATAL && $kind !== self::KIND_TRANSIT ) {
			return array();
		}

		$coachee_id = isset( $artifact['id'] ) ? (int) $artifact['id'] : 0;
		if ( $coachee_id <= 0 && isset( $artifact['coachee_id'] ) ) {
			$coachee_id = (int) $artifact['coachee_id'];
		}
		if ( $coachee_id <= 0 ) { return array(); }

		if ( ! class_exists( 'BizCoach_Pro_Artifact_Service' ) ) {
			require_once BCPRO_DIR . 'includes/coaching/class-artifact-service.php';
		}

		global $wpdb;
		$cee = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, full_name, dob, phone, address, extra_fields_json
				   FROM {$wpdb->prefix}bccm_coachees
				  WHERE id = %d",
				$coachee_id
			),
			ARRAY_A
		);
		if ( ! $cee ) { return array(); }

		$title    = (string) $cee['full_name'] !== '' ? (string) $cee['full_name'] : 'Coachee #' . $coachee_id;
		$passages = array();

		// Passage 0 — birth profile summary (input payload).
		$lines = array();
		if ( ! empty( $cee['dob'] ) )     { $lines[] = '- **Ngày sinh**: '  . sanitize_text_field( (string) $cee['dob'] ); }
		if ( ! empty( $cee['address'] ) ) { $lines[] = '- **Nơi sinh**: '   . sanitize_text_field( (string) $cee['address'] ); }
		$extra = isset( $cee['extra_fields_json'] ) ? (string) $cee['extra_fields_json'] : '';
		$payload = $extra !== '' ? json_decode( $extra, true ) : null;
		if ( is_array( $payload ) ) {
			foreach ( array( 'birth_time', 'birth_place', 'gender' ) as $k ) {
				if ( ! empty( $payload[ $k ] ) ) {
					$lines[] = '- **' . sanitize_text_field( $k ) . '**: ' . sanitize_text_field( (string) $payload[ $k ] );
				}
			}
		}
		if ( ! empty( $lines ) ) {
			$passages[] = array(
				'title'   => $title . ' — ' . __( 'Hồ sơ chiêm tinh', 'bizcoach-pro' ),
				'content' => implode( "\n", $lines ),
				'meta'    => array(
					'kind'        => self::KIND_NATAL,
					'format'      => 'markdown',
					'coachee_id'  => $coachee_id,
					'section'     => 'birth_profile',
				),
			);
		}

		// Passage 1 — natal chart payload (whichever chart_type is stored).
		$natal = BizCoach_Pro_Artifact_Service::get_natal_chart( $coachee_id, 'western' );
		if ( ! $natal ) {
			$natal = BizCoach_Pro_Artifact_Service::get_natal_chart( $coachee_id, 'vedic' );
		}
		if ( $natal && is_array( $natal ) ) {
			$body = '';
			if ( ! empty( $natal['ai_summary'] ) ) {
				$body = (string) $natal['ai_summary'];
			} elseif ( ! empty( $natal['data_json'] ) ) {
				// Best-effort summary so embedder has something useful even
				// if no AI summary has run yet — keep it short.
				$body = "```json\n" . wp_strip_all_tags( (string) $natal['data_json'] ) . "\n```";
			}
			if ( $body !== '' ) {
				$passages[] = array(
					'title'   => $title . ' — ' . __( 'Natal Chart', 'bizcoach-pro' ),
					'content' => $body,
					'meta'    => array(
						'kind'       => self::KIND_NATAL,
						'format'     => 'markdown',
						'coachee_id' => $coachee_id,
						'chart_type' => isset( $natal['chart_type'] ) ? (string) $natal['chart_type'] : 'western',
						'section'    => 'natal_chart',
					),
				);
			}
		}

		// Passage 2 — most recent transit snapshot summary (if any).
		if ( $kind === self::KIND_TRANSIT ) {
			$today = current_time( 'Y-m-d' );
			$transit = BizCoach_Pro_Artifact_Service::get_transit_snapshot( $coachee_id, $today );
			if ( $transit && is_array( $transit ) ) {
				$body = '';
				if ( ! empty( $transit['ai_summary'] ) ) {
					$body = (string) $transit['ai_summary'];
				} elseif ( ! empty( $transit['data_json'] ) ) {
					$body = "```json\n" . wp_strip_all_tags( (string) $transit['data_json'] ) . "\n```";
				}
				if ( $body !== '' ) {
					$passages[] = array(
						'title'   => $title . ' — ' . __( 'Transit Snapshot', 'bizcoach-pro' ) . ' (' . esc_html( $today ) . ')',
						'content' => $body,
						'meta'    => array(
							'kind'         => self::KIND_TRANSIT,
							'format'       => 'markdown',
							'coachee_id'   => $coachee_id,
							'target_date'  => $today,
							'section'      => 'transit_snapshot',
						),
					);
				}
			}
		}

		return $passages;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Tool callbacks
	 *
	 * Sprint K ships the provider as a SURFACE — tool definitions +
	 * chips + render_to_passages — without re-implementing the
	 * FreeAstrologyAPI.com fetch pipeline (that lived in the deleted
	 * `bizcoach-map/lib/astro-api.php`). Callbacks therefore return a
	 * structured WP_Error pointing the caller at the frontend astro form.
	 *
	 * To activate live generation later: port `bccm_freeastrology_*`
	 * helpers into `BizCoach_Pro_Astro_Service` and replace the bodies
	 * below with calls to it. Schema (bccm_astro / bccm_transit_snapshots)
	 * is already created by `BizCoach_Pro_Installer`.
	 * ────────────────────────────────────────────────────────────── */

	public function tool_create_natal_chart( $args, $ctx ) {
		$payload = self::args_to_natal_payload( (array) $args );
		if ( is_wp_error( $payload ) ) { return $payload; }
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return new WP_Error( 'no_client', __( 'Astro client not loaded.', 'bizcoach-pro' ), array( 'status' => 500 ) );
		}
		$result = BizCoach_Pro_Astro_Client::natal_western( $payload );
		return self::result_or_error( $result, 'western_natal' );
	}

	public function tool_create_transit_map( $args, $ctx ) {
		$natal = self::args_to_natal_payload( (array) $args );
		if ( is_wp_error( $natal ) ) { return $natal; }
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return new WP_Error( 'no_client', __( 'Astro client not loaded.', 'bizcoach-pro' ), array( 'status' => 500 ) );
		}
		$payload = array(
			'natal'        => $natal,
			'transit_date' => isset( $args['transit_date'] ) ? (string) $args['transit_date'] : gmdate( 'Y-m-d\TH:i:s\Z' ),
			'tz_str'       => $natal['tz_str'],
			'lat'          => $natal['lat'],
			'lng'          => $natal['lng'],
		);
		$result = BizCoach_Pro_Astro_Client::transits_western( $payload );
		return self::result_or_error( $result, 'western_transits' );
	}

	/* PHASE-0.2 Sprint G.3 — Vedic / Chinese tool callbacks. */
	public function tool_create_vedic_chart( $args, $ctx ) {
		$payload = self::args_to_natal_payload( (array) $args );
		if ( is_wp_error( $payload ) ) { return $payload; }
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return new WP_Error( 'no_client', __( 'Astro client not loaded.', 'bizcoach-pro' ), array( 'status' => 500 ) );
		}
		// Vedic provider expects ayanamsa key; default Lahiri.
		$payload['ayanamsa'] = isset( $args['ayanamsa'] ) ? (string) $args['ayanamsa'] : 'lahiri';
		$payload['include_vargas']  = true;
		$payload['include_dasha']   = true;
		$result = BizCoach_Pro_Astro_Client::calculate_vedic( $payload );
		return self::result_or_error( $result, 'vedic_calculate' );
	}

	public function tool_create_bazi_chart( $args, $ctx ) {
		$payload = self::args_to_natal_payload( (array) $args );
		if ( is_wp_error( $payload ) ) { return $payload; }
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return new WP_Error( 'no_client', __( 'Astro client not loaded.', 'bizcoach-pro' ), array( 'status' => 500 ) );
		}
		$payload['gender'] = isset( $args['gender'] ) ? (string) $args['gender'] : 'male';
		$result = BizCoach_Pro_Astro_Client::bazi_chinese( $payload );
		return self::result_or_error( $result, 'chinese_bazi' );
	}

	/* ──────────────────────────────────────────────────────────────
	 * G.3 helpers
	 * ────────────────────────────────────────────────────────────── */

	private static function args_to_natal_payload( array $args ) {
		if ( empty( $args['dob'] ) ) {
			return new WP_Error( 'missing_dob', __( 'Thiếu ngày sinh.', 'bizcoach-pro' ), array( 'status' => 400 ) );
		}
		$time = isset( $args['birth_time'] ) ? (string) $args['birth_time'] : '12:00';
		if ( ! preg_match( '/^\d{2}:\d{2}/', $time ) ) { $time = '12:00'; }
		$dt = $args['dob'] . 'T' . substr( $time, 0, 5 ) . ':00';

		// Resolve coords from birth_place using geo_search (best-effort).
		$lat = isset( $args['lat'] ) ? (float) $args['lat'] : 0.0;
		$lng = isset( $args['lng'] ) ? (float) $args['lng'] : ( isset( $args['lon'] ) ? (float) $args['lon'] : 0.0 );
		$tz  = isset( $args['tz_str'] ) ? (string) $args['tz_str'] : ( isset( $args['timezone'] ) ? (string) $args['timezone'] : 'Asia/Ho_Chi_Minh' );

		if ( ( $lat == 0.0 && $lng == 0.0 ) && ! empty( $args['birth_place'] ) && class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			$geo = BizCoach_Pro_Astro_Client::geo_search( array(
				'q'     => (string) $args['birth_place'],
				'limit' => 1,
			) );
			if ( ! empty( $geo['success'] ) ) {
				$first = ( $geo['envelope']['results'][0] ?? null );
				if ( is_array( $first ) ) {
					$lat = (float) ( $first['lat'] ?? $lat );
					$lng = (float) ( $first['lng'] ?? $first['lon'] ?? $lng );
					if ( ! empty( $first['timezone'] ) ) { $tz = (string) $first['timezone']; }
				}
			}
		}

		return array(
			'datetime_utc' => $dt,
			'lat'          => $lat,
			'lng'          => $lng,
			'tz_str'       => $tz,
			'name'         => isset( $args['full_name'] ) ? (string) $args['full_name'] : '',
			'city'         => isset( $args['birth_place'] ) ? (string) $args['birth_place'] : '',
		);
	}

	private static function result_or_error( array $result, string $label ) {
		if ( ! empty( $result['success'] ) ) {
			return array(
				'status'    => 'ok',
				'tool_name' => $label,
				'envelope'  => $result['envelope'],
				'http'      => $result['http'] ?? array(),
			);
		}
		return new WP_Error(
			'gateway_call_failed',
			sprintf( /* translators: %1$s = label, %2$s = error */ __( 'Gateway call failed for %1$s: %2$s', 'bizcoach-pro' ), $label, (string) ( $result['error'] ?? 'unknown' ) ),
			array(
				'status' => (int) ( $result['http']['status'] ?? 502 ),
				'http'   => $result['http'] ?? array(),
			)
		);
	}

	public function tool_astro_consult( $args, $ctx ) {
		// Pass-through: actual consult logic is in the chat layer (LLM with
		// the bound Twin Guru's system prompt). The tool callback only
		// records that the request happened.
		return array(
			'status'    => 'pass_to_chat',
			'tool_name' => 'astro_consult',
			'prompt'    => isset( $args['prompt'] ) ? (string) $args['prompt'] : '',
		);
	}
}
