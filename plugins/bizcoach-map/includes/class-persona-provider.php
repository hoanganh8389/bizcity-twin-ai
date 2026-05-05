<?php
/**
 * BizCoach — Persona Tool Provider (Wave 0.18.2).
 *
 * Bridges the Notebook Persona system (PHASE-0.18) with BizCoach's astrology
 * + coaching toolset. Implements `BizCity_Persona_Tool_Provider` so a Twin
 * Guru character (provider_id = "bizcoach") can:
 *
 *  • Expose smart-source chips ("🔮 Tạo bản đồ sao" / "🌀 Bản đồ vận hạn")
 *    in the notebook UI.
 *  • Declare owned source kinds so kg_sources accepts them
 *    (`astro_natal_chart`, `astro_transit_report`).
 *  • Render a stored bccm_astro row into Passage[] for the embedder so the
 *    chart becomes citable in chat (`[persona:astro_natal_chart#<id>]`).
 *  • Inject a short astro context block into the system prompt
 *    (priority-22 enrichment, ≤ 600 tokens per R-PP-6).
 *
 * Tool callbacks deliberately delegate to `BizCoach_Intent_Provider` so the
 * actual API/DB code (chart synthesis, LLM consult, transit calc) lives in
 * one place and Persona stays a thin adapter (R-PP-5).
 *
 * @package BizCoach_Map
 * @since   0.2.0 (Wave 0.18.2)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Persona_Provider' ) ) {
	return;
}

/**
 * @see BizCity_Persona_Tool_Provider
 * @see PHASE-0-RULE-PERSONA-PROVIDER.md (R-PP-1..R-PP-8)
 */
class BizCoach_Persona_Provider extends BizCity_Persona_Tool_Provider {

	const KIND_NATAL   = 'astro_natal_chart';
	const KIND_TRANSIT = 'astro_transit_report';

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-1 — Identity
	 * ────────────────────────────────────────────────────────────── */

	public function id(): string {
		return 'bizcoach';
	}

	public function label(): string {
		return __( 'BizCoach — Chiêm tinh & Coaching', 'bizcoach-map' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-3 — Source kinds owned by this provider
	 * ────────────────────────────────────────────────────────────── */

	public function get_source_kinds(): array {
		return [ self::KIND_NATAL, self::KIND_TRANSIT ];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-5 — Tool definitions (declarative)
	 *
	 * Tools are intentionally small: Persona owns the contract; the heavy
	 * lifting (Astro API call, AI consult, transit calc) is delegated to
	 * `BizCoach_Intent_Provider` callbacks already wired into the Intent
	 * Engine. We re-publish them here so Persona-aware dispatchers (smart
	 * source chips, notebook tool palette) can find them by name.
	 * ────────────────────────────────────────────────────────────── */

	public function get_tool_definitions(): array {
		return [
			[
				'name'          => 'create_natal_chart',
				'label'         => __( 'Tạo bản đồ sao', 'bizcoach-map' ),
				'description'   => __( 'Sinh natal chart từ họ tên, ngày, giờ, nơi sinh.', 'bizcoach-map' ),
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
			],
			[
				'name'          => 'create_transit_map',
				'label'         => __( 'Tạo bản đồ vận hạn', 'bizcoach-map' ),
				'description'   => __( 'Tạo transit chart cho khoảng thời gian (tuần/tháng/năm).', 'bizcoach-map' ),
				'slot_schema'   => [
					'time_range' => 'choice',
				],
				'side_effect'   => 'write',
				'cost_class'    => 'medium',
				'callback'      => [ $this, 'tool_create_transit_map' ],
				'required_caps' => [ 'read' ],
			],
			[
				'name'          => 'bizcoach-consult',
				'label'         => __( 'Tư vấn chiêm tinh', 'bizcoach-map' ),
				'description'   => __( 'Hỏi Twin Guru chiêm tinh về vận mệnh, bản đồ sao, phong thủy.', 'bizcoach-map' ),
				'slot_schema'   => [
					'prompt'        => 'text',
					'prompt_images' => 'image',
				],
				'side_effect'   => 'external',
				'cost_class'    => 'high',
				'callback'      => [ $this, 'tool_bizcoach_consult' ],
				'required_caps' => [ 'read' ],
			],
		];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-1 — Smart source chips for Notebook UI
	 * ────────────────────────────────────────────────────────────── */

	public function get_smart_source_chips(): array {
		return [
			[
				'tool'               => 'create_natal_chart',
				'label'              => __( 'Tạo bản đồ sao', 'bizcoach-map' ),
				'icon'               => '🔮',
				'action'             => 'persona_artifact_dialog',
				'requires_user_data' => [ 'dob', 'birth_time', 'birth_place' ],
				'payload_schema'     => [
					'full_name'   => [ 'type' => 'text', 'required' => true,  'label' => __( 'Họ tên', 'bizcoach-map' ) ],
					'dob'         => [ 'type' => 'date', 'required' => true,  'label' => __( 'Ngày sinh', 'bizcoach-map' ) ],
					'birth_time'  => [ 'type' => 'time', 'required' => true,  'label' => __( 'Giờ sinh', 'bizcoach-map' ) ],
					'birth_place' => [ 'type' => 'text', 'required' => true,  'label' => __( 'Nơi sinh', 'bizcoach-map' ) ],
					'gender'      => [ 'type' => 'choice', 'required' => false, 'label' => __( 'Giới tính', 'bizcoach-map' ),
						'choices' => [ 'male' => '👨 Nam', 'female' => '👩 Nữ' ] ],
				],
			],
			[
				'tool'               => 'create_transit_map',
				'label'              => __( 'Bản đồ vận hạn', 'bizcoach-map' ),
				'icon'               => '🌀',
				'action'             => 'persona_artifact_dialog',
				'requires_user_data' => [ 'natal_chart' ],
				'payload_schema'     => [
					'time_range' => [ 'type' => 'choice', 'required' => true, 'label' => __( 'Khoảng thời gian', 'bizcoach-map' ),
						'choices' => [
							'this_week'  => '📅 Tuần này',
							'this_month' => '📅 Tháng này',
							'this_year'  => '📅 Năm nay',
						],
						'default' => 'this_month',
					],
				],
			],
		];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-1 / R-PP-7 — Render artifact → passages for embedder
	 *
	 * Called by the ingest pipeline after a tool callback (or the personal
	 * artifact dialog) hands an artifact array to KG_Source_Service. We do
	 * NOT touch kg_sources directly here (R-PP-4). Pure transform.
	 *
	 * Artifact shape MAY come from one of two sources, so we stay forgiving:
	 *   1. A raw `bccm_astro` row (ARRAY_A from $wpdb->get_row).
	 *   2. A synthetic payload posted by the dialog
	 *      ({ full_name, dob, birth_time, birth_place, summary, traits, ... }).
	 * ────────────────────────────────────────────────────────────── */

	public function render_to_passages( string $kind, array $artifact ): array {
		switch ( $kind ) {
			case self::KIND_NATAL:
				return $this->passages_from_natal_chart( $artifact );
			case self::KIND_TRANSIT:
				return $this->passages_from_transit_report( $artifact );
		}
		return [];
	}

	/**
	 * Build 6–8 passages from a natal chart artifact.
	 *
	 * Sections we try to peel out of `summary` JSON (each becomes 1 passage):
	 *   personality, career, health, relationship, strengths, challenges, transits.
	 * Plus an Overview passage (always) and a Traits passage (when array present).
	 */
	private function passages_from_natal_chart( array $artifact ): array {
		$artifact_id = (int) ( $artifact['id'] ?? 0 );
		$name        = (string) ( $artifact['full_name'] ?? $artifact['name'] ?? __( 'Chủ thể', 'bizcoach-map' ) );
		$dob         = (string) ( $artifact['dob'] ?? '' );
		$birth_time  = (string) ( $artifact['birth_time'] ?? '' );
		$birth_place = (string) ( $artifact['birth_place'] ?? '' );
		$chart_type  = (string) ( $artifact['chart_type'] ?? 'western' );

		$summary = $this->maybe_decode( $artifact['summary'] ?? [] );
		$traits  = $this->maybe_decode( $artifact['traits']  ?? [] );

		$base_meta = [
			'kind'        => self::KIND_NATAL,
			'chart_type'  => $chart_type,
			'artifact_id' => $artifact_id,
			'subject'     => $name,
			'dob'         => $dob,
		];

		$passages   = [];
		$anchor_fn  = function ( $section ) use ( $artifact_id ) {
			return $artifact_id > 0
				? sprintf( 'persona:%s#%d:%s', self::KIND_NATAL, $artifact_id, $section )
				: null;
		};

		// Overview ─ always emit.
		$overview_lines = [
			sprintf( '%s — bản đồ sao %s', $name, ucfirst( $chart_type ) ),
			$dob         ? sprintf( '• Ngày sinh: %s', $dob ) : '',
			$birth_time  ? sprintf( '• Giờ sinh: %s', $birth_time ) : '',
			$birth_place ? sprintf( '• Nơi sinh: %s', $birth_place ) : '',
		];
		$personality = is_array( $summary ) ? ( $summary['personality'] ?? '' ) : '';
		if ( $personality ) {
			$overview_lines[] = '';
			$overview_lines[] = '## Tổng quan tính cách';
			$overview_lines[] = (string) $personality;
		}
		$passages[] = [
			'title'           => sprintf( '🔮 %s — Bản đồ sao', $name ),
			'body'            => trim( implode( "\n", array_filter( $overview_lines, 'strlen' ) ) ),
			'metadata'        => array_merge( $base_meta, [ 'section' => 'overview' ] ),
			'citation_anchor' => $anchor_fn( 'overview' ),
		];

		// Traits passage when JSON array is non-empty.
		if ( is_array( $traits ) && ! empty( $traits ) ) {
			$lines = [];
			foreach ( array_slice( $traits, 0, 12 ) as $t ) {
				if ( is_string( $t ) && $t !== '' ) {
					$lines[] = '• ' . $t;
				} elseif ( is_array( $t ) && ! empty( $t['text'] ) ) {
					$lines[] = '• ' . (string) $t['text'];
				}
			}
			if ( $lines ) {
				$passages[] = [
					'title'           => sprintf( '✨ %s — Đặc điểm nổi bật', $name ),
					'body'            => implode( "\n", $lines ),
					'metadata'        => array_merge( $base_meta, [ 'section' => 'traits' ] ),
					'citation_anchor' => $anchor_fn( 'traits' ),
				];
			}
		}

		// Section passages (career / health / relationship / strengths / challenges / transits).
		$sections = [
			'career'       => '💼 Sự nghiệp',
			'health'       => '🩺 Sức khoẻ',
			'relationship' => '💞 Quan hệ',
			'strengths'    => '💪 Điểm mạnh',
			'challenges'   => '⚠️ Thách thức',
			'transits'     => '🌀 Vận hạn',
		];
		if ( is_array( $summary ) ) {
			foreach ( $sections as $key => $label ) {
				if ( empty( $summary[ $key ] ) ) {
					continue;
				}
				$body = is_array( $summary[ $key ] )
					? wp_json_encode( $summary[ $key ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
					: (string) $summary[ $key ];
				$passages[] = [
					'title'           => sprintf( '%s — %s', $label, $name ),
					'body'            => trim( $body ),
					'metadata'        => array_merge( $base_meta, [ 'section' => $key ] ),
					'citation_anchor' => $anchor_fn( $key ),
				];
			}
		}

		// Fallback to llm_report if structured summary was empty.
		if ( count( $passages ) === 1 && ! empty( $artifact['llm_report'] ) ) {
			$passages[] = [
				'title'           => sprintf( '📝 %s — Báo cáo chi tiết', $name ),
				'body'            => (string) $artifact['llm_report'],
				'metadata'        => array_merge( $base_meta, [ 'section' => 'llm_report' ] ),
				'citation_anchor' => $anchor_fn( 'llm_report' ),
			];
		}

		return $passages;
	}

	/**
	 * Build a single passage (or two when split) from a transit report.
	 */
	private function passages_from_transit_report( array $artifact ): array {
		$artifact_id = (int) ( $artifact['id'] ?? 0 );
		$name        = (string) ( $artifact['full_name'] ?? $artifact['name'] ?? __( 'Chủ thể', 'bizcoach-map' ) );
		$range       = (string) ( $artifact['time_range'] ?? $artifact['target_date'] ?? '' );
		$report      = (string) ( $artifact['report'] ?? $artifact['llm_report'] ?? '' );
		$summary     = $this->maybe_decode( $artifact['summary'] ?? [] );

		$meta = [
			'kind'        => self::KIND_TRANSIT,
			'artifact_id' => $artifact_id,
			'subject'     => $name,
			'time_range'  => $range,
		];
		$anchor = $artifact_id > 0
			? sprintf( 'persona:%s#%d', self::KIND_TRANSIT, $artifact_id )
			: null;

		$body = $report;
		if ( ! $body && is_array( $summary ) ) {
			$body = wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		}
		if ( ! $body ) {
			return [];
		}

		return [
			[
				'title'           => sprintf( '🌀 %s — Vận hạn %s', $name, $range ?: 'hiện tại' ),
				'body'            => trim( $body ),
				'metadata'        => $meta,
				'citation_anchor' => $anchor,
			],
		];
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-6 — System prompt enrichment (priority 22, ≤600 tokens)
	 *
	 * Pulls the user's natal chart (most recent western chart, falling back
	 * to any chart) and emits a compact bullet block. Cached per
	 * user+character for 5 minutes (R-PP-6).
	 * ────────────────────────────────────────────────────────────── */

	public function enrich_system_prompt( int $user_id, int $character_id, array $ctx ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$cache_key = sprintf( 'bccm_persona_enrich_%d_%d', $user_id, $character_id );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		try {
			$text = $this->build_enrichment_text( $user_id );
		} catch ( \Throwable $e ) {
			$text = '';
		}

		// R-PP-6 hard cap (~600 tokens ≈ 2400 chars).
		if ( $text !== '' && function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 2400 ) {
			$text = mb_substr( $text, 0, 2400, 'UTF-8' ) . '…';
		}

		set_transient( $cache_key, $text, 5 * MINUTE_IN_SECONDS );
		return $text;
	}

	private function build_enrichment_text( int $user_id ): string {
		global $wpdb;
		$astro_table = $wpdb->prefix . 'bccm_astro';

		$astro = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$astro_table} WHERE user_id = %d ORDER BY chart_type='western' DESC, updated_at DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		if ( ! $astro ) {
			return '';
		}

		$summary = $this->maybe_decode( $astro['summary'] ?? [] );
		$lines   = [
			'### 🔮 Bối cảnh chiêm tinh người dùng',
			sprintf( '- Loại bản đồ: %s', ucfirst( (string) ( $astro['chart_type'] ?? 'western' ) ) ),
		];
		if ( ! empty( $astro['birth_time'] ) ) {
			$lines[] = sprintf( '- Giờ sinh: %s', (string) $astro['birth_time'] );
		}
		if ( ! empty( $astro['birth_place'] ) ) {
			$lines[] = sprintf( '- Nơi sinh: %s', (string) $astro['birth_place'] );
		}
		if ( is_array( $summary ) && ! empty( $summary['personality'] ) ) {
			$lines[] = sprintf(
				'- Tóm tắt tính cách: %s',
				mb_strimwidth( (string) $summary['personality'], 0, 320, '…', 'UTF-8' )
			);
		}
		return implode( "\n", $lines );
	}

	/* ──────────────────────────────────────────────────────────────
	 * R-PP-7 — Citation resolver
	 * ────────────────────────────────────────────────────────────── */

	public function resolve_citation( int $source_id ): array {
		return [
			'title'   => sprintf( '%s · #%d', $this->label(), $source_id ),
			'summary' => __( 'Mở Smart Sources để xem bản đồ sao đã ingest.', 'bizcoach-map' ),
			'actions' => [
				[ 'kind' => 'open_source', 'source_id' => $source_id ],
			],
		];
	}

	/* ──────────────────────────────────────────────────────────────
	 * Tool callbacks — delegate to existing Intent Provider.
	 *
	 * The Intent Engine remains the single execution path; Persona just
	 * provides the contract entrypoint. If `BizCoach_Intent_Provider` is
	 * not loaded (extremely unlikely since we require it) we return a
	 * structured error rather than throwing.
	 * ────────────────────────────────────────────────────────────── */

	public function tool_create_natal_chart( array $slots ) {
		return $this->delegate_to_intent( 'create_natal_chart', $slots );
	}

	public function tool_create_transit_map( array $slots ) {
		return $this->delegate_to_intent( 'create_transit_map', $slots );
	}

	public function tool_bizcoach_consult( array $slots ) {
		return $this->delegate_to_intent( 'bizcoach_consult', $slots );
	}

	private function delegate_to_intent( string $tool_name, array $slots ) {
		if ( ! class_exists( 'BizCoach_Intent_Provider' ) ) {
			return [
				'ok'    => false,
				'error' => 'intent_provider_unavailable',
			];
		}
		$intent = new BizCoach_Intent_Provider();
		$tools  = method_exists( $intent, 'get_tools' ) ? (array) $intent->get_tools() : [];
		$def    = $tools[ $tool_name ] ?? null;
		if ( ! $def || empty( $def['callback'] ) || ! is_callable( $def['callback'] ) ) {
			return [
				'ok'    => false,
				'error' => 'intent_tool_not_found:' . $tool_name,
			];
		}
		return call_user_func( $def['callback'], $slots );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Internal helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Tolerant JSON decode: accepts string OR already-decoded array.
	 */
	private function maybe_decode( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && $value !== '' ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}
}
