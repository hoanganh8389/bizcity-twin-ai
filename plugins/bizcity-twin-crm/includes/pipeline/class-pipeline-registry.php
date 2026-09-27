<?php
/**
 * BizCity CRM — Pipeline Registry (PHASE-0.63A WP-1.3/1.4/1.5, lane S).
 *
 * Read/write accessor over the pipeline definition layer. A definition is a `pipeline-definition@1.0.0`
 * document: the configuration of one internal business flow — its steps, gates, roles, calendars and
 * SLA quadruples. Two sources feed the registry:
 *
 *   1. `bizcity_crm_register_pipeline_kinds` — code defaults shipped by a `bizcity-crm-pipeline-<kind>`
 *      plugin. Same anti-spoof shape as `BizCity_CRM_Channel_Registry` (`class-channel-registry.php:23-38`):
 *      the array key must equal the entry's own `kind`, otherwise the registration is dropped rather
 *      than silently relabelling somebody else's pipeline.
 *   2. The `bzcrm_pipeline` CPT — what the team lead edited in the admin screen. The CPT always wins,
 *      because it is the row a human touched.
 *
 * Every write goes through `validate()` first (WP-1.5: validate before persist, never the reverse), and
 * `save()` keeps the last three definition versions so a run pinned to an older `_def_version` can still
 * read the exact steps it started on.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-1)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Registry', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Registry {

	const CPT             = 'bzcrm_pipeline';
	const META_KIND       = '_kind';
	const META_VERSION    = '_def_version';
	const META_VERSIONS   = '_versions';
	const MAX_VERSIONS    = 3;
	const CONTRACT        = 'pipeline-definition';
	// [2026-09-25 PHASE-0.63C GC-23] 1.1.0 is additive over 1.0.0 (stage.outcome, stage.ui, sub_step.ui, sub_step.return_to,
	// root ui). `validate()` accepts both so a definition already stored at 1.0.0 keeps loading; `save()` always writes 1.1.0.
	const CONTRACT_VER    = '1.1.0';
	const CONTRACT_VERSIONS = array( '1.0.0', '1.1.0' );
	const OUTCOMES        = array( 'won', 'lost', 'closed' );

	/** Anchors whose moment lies in the future, and therefore may carry a negative offset (0.63 §2.1). */
	const FUTURE_ANCHORS = array( 'appointment_at' );

	/** @var array<string,array>|null */
	private static $cache = null;

	/* ------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------ */

	/**
	 * Every known definition, keyed by kind. CPT rows override code defaults.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$out = array();

		foreach ( BizCity_CRM_Pipeline_Kind_Registry::all() as $kind => $entry ) {
			$definition = self::definition_from_registration( $entry );
			if ( is_array( $definition ) && true === self::validate( $definition ) ) {
				$out[ $kind ] = $definition;
			}
		}

		foreach ( self::definition_posts() as $post ) {
			$definition = self::decode( (string) $post->post_content );
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$kind = self::sanitize_kind( (string) ( $definition['kind'] ?? '' ) );
			if ( '' === $kind ) {
				continue;
			}
			$out[ $kind ] = $definition;
		}

		self::$cache = $out;
		return $out;
	}

	/** The definition currently in force for one kind. */
	public static function get( string $kind ): ?array {
		$kind = self::sanitize_kind( $kind );
		$all  = self::all();
		return $all[ $kind ] ?? null;
	}

	/**
	 * The definition as it looked at `$version` — what a run pinned to that `_def_version` must read.
	 * Falls back to the current definition when the archive no longer holds that version.
	 */
	public static function get_version( string $kind, int $version ): ?array {
		$kind = self::sanitize_kind( $kind );
		if ( '' === $kind || $version <= 0 ) {
			return self::get( $kind );
		}

		$post = self::post_for_kind( $kind );
		if ( ! $post ) {
			return self::get( $kind );
		}

		if ( (int) self::def_version( (int) $post->ID ) === $version ) {
			return self::decode( (string) $post->post_content );
		}

		foreach ( self::version_archive( (int) $post->ID ) as $entry ) {
			if ( (int) ( $entry['version'] ?? 0 ) === $version && is_array( $entry['definition'] ?? null ) ) {
				return $entry['definition'];
			}
		}

		return self::decode( (string) $post->post_content );
	}

	/** Current `_def_version` of a kind (0 when the kind only exists as a code default). */
	public static function current_version( string $kind ): int {
		$post = self::post_for_kind( self::sanitize_kind( $kind ) );
		return $post ? self::def_version( (int) $post->ID ) : 0;
	}

	/**
	 * Context App keys this deployment offers, in priority order.
	 *
	 * PHASE-0.63B moved the closed list to the Context App registry (lane C0) — the `tools[]` array in a
	 * definition is only a preference. Until that lane lands, fall back to the union of what the
	 * definitions ask for, so nothing depends on a class that does not exist yet.
	 *
	 * @return string[]
	 */
	public static function tools(): array {
		if ( class_exists( 'BizCity_CRM_Context_App_Registry' ) && method_exists( 'BizCity_CRM_Context_App_Registry', 'keys' ) ) {
			return (array) BizCity_CRM_Context_App_Registry::keys();
		}

		$out = array();
		foreach ( self::all() as $definition ) {
			foreach ( (array) ( $definition['tools'] ?? array() ) as $tool ) {
				$tool = self::sanitize_kind( (string) $tool );
				if ( '' !== $tool && ! in_array( $tool, $out, true ) ) {
					$out[] = $tool;
				}
			}
		}
		return $out;
	}

	/**
	 * The `on_miss` actions this site can run. Five core ones, plus whatever an extension registers —
	 * D63-2 keeps the set open on purpose, so a business can add its own escalation without a core patch.
	 *
	 * @return array<string,array>
	 */
	public static function sla_actions(): array {
		$core = self::core_sla_actions();
		if ( ! function_exists( 'apply_filters' ) ) {
			return $core;
		}

		$registered = apply_filters( 'bizcity_crm_register_pipeline_sla_actions', $core );
		if ( ! is_array( $registered ) ) {
			return $core;
		}

		$out = array();
		foreach ( $registered as $action => $entry ) {
			$key = self::sanitize_kind( (string) $action );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			// Same anti-spoof clamp as the Channel Registry: a registration may not rename itself.
			if ( self::sanitize_kind( (string) ( $entry['action'] ?? $key ) ) !== $key ) {
				continue;
			}
			$out[ $key ] = $entry;
		}

		foreach ( $core as $key => $entry ) {
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $entry;
			}
		}

		return $out;
	}

	/** The five actions PHASE-0.63 §2.5 guarantees. Never changes a stage — that is the whole point. */
	public static function core_sla_actions(): array {
		return array(
			'flag'     => array( 'action' => 'flag',     'label' => 'Đổi trạng thái cảnh báo (at_risk / breached)' ),
			'notify'   => array( 'action' => 'notify',   'label' => 'Nhắn cho người/vai trò' ),
			'escalate' => array( 'action' => 'escalate', 'label' => 'Leo thang: đổi người chịu trách nhiệm + nhắn' ),
			'reassign' => array( 'action' => 'reassign', 'label' => 'Phát tín hiệu cần giao lại (framework không tự chọn người)' ),
			'emit'     => array( 'action' => 'emit',     'label' => 'Bắn sự kiện cho automation' ),
		);
	}

	/**
	 * Invalid registrations, surfaced instead of hidden — same contract as
	 * `BizCity_CRM_Channel_Registry::registration_issues()`.
	 *
	 * Structural problems (empty/duplicate key, a `kind` that does not match
	 * the array key it registered under) are `Pipeline_Kind_Registry`'s job
	 * (PHASE-0.71 F71-09 / 0.63C GC-04); this method only adds the semantic
	 * check that a structurally clean kind's definition actually validates.
	 *
	 * @return string[]
	 */
	public static function registration_issues(): array {
		$issues = BizCity_CRM_Pipeline_Kind_Registry::registration_issues();

		foreach ( BizCity_CRM_Pipeline_Kind_Registry::all() as $key => $entry ) {
			$definition = self::definition_from_registration( $entry );
			if ( ! is_array( $definition ) ) {
				$issues[] = $key . ':no_definition';
				continue;
			}
			$valid = self::validate( $definition );
			if ( true !== $valid ) {
				$issues[] = $key . ':invalid_definition:' . implode( ';', self::error_reasons( $valid ) );
			}
		}

		$seen = array();
		foreach ( self::definition_posts() as $post ) {
			$definition = self::decode( (string) $post->post_content );
			$kind       = is_array( $definition ) ? self::sanitize_kind( (string) ( $definition['kind'] ?? '' ) ) : '';
			if ( '' === $kind ) {
				$issues[] = 'post_' . (int) $post->ID . ':unreadable_definition';
				continue;
			}
			if ( isset( $seen[ $kind ] ) ) {
				$issues[] = $kind . ':duplicate_kind:post_' . (int) $post->ID;
				continue;
			}
			$seen[ $kind ] = (int) $post->ID;

			$valid = self::validate( $definition );
			if ( true !== $valid ) {
				$issues[] = $kind . ':invalid_definition:' . implode( ';', self::error_reasons( $valid ) );
			}
			if ( count( self::version_archive( (int) $post->ID ) ) > self::MAX_VERSIONS ) {
				$issues[] = $kind . ':version_archive_overflow';
			}
		}

		return $issues;
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------ */

	/**
	 * Persist a definition, bumping `_def_version` and pushing the previous body into the archive.
	 *
	 * @return int|WP_Error Post id of the definition.
	 */
	public static function save( string $kind, array $definition ) {
		$kind = self::sanitize_kind( $kind );
		if ( '' === $kind ) {
			return new WP_Error( 'pipeline_kind_invalid', 'Mã pipeline không hợp lệ.' );
		}

		$definition['kind']    = $kind;
		$definition['version'] = self::CONTRACT_VER;
		$valid = self::validate( $definition );
		if ( true !== $valid ) {
			return $valid;
		}

		$post     = self::post_for_kind( $kind );
		$previous = $post ? self::decode( (string) $post->post_content ) : null;
		$version  = $post ? self::def_version( (int) $post->ID ) + 1 : 1;
		$label    = (string) ( $definition['label'] ?? $kind );
		$encoded  = self::encode( $definition );

		if ( $post ) {
			$post_id = wp_update_post( array(
				'ID'           => (int) $post->ID,
				'post_title'   => $label,
				'post_content' => $encoded,
			), true );
		} else {
			$post_id = wp_insert_post( array(
				'post_type'    => self::CPT,
				'post_status'  => 'publish',
				'post_title'   => $label,
				'post_content' => $encoded,
			), true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		if ( is_array( $previous ) ) {
			$archive   = self::version_archive( $post_id );
			$archive[] = array(
				'version'    => self::def_version( $post_id ),
				'archived_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
				'definition' => $previous,
			);
			if ( count( $archive ) > self::MAX_VERSIONS ) {
				$archive = array_slice( $archive, -self::MAX_VERSIONS );
			}
			update_post_meta( $post_id, self::META_VERSIONS, $archive );
		}

		update_post_meta( $post_id, self::META_KIND, $kind );
		update_post_meta( $post_id, self::META_VERSION, $version );

		self::flush_cache();
		return $post_id;
	}

	/**
	 * Import a definition document (WP-1.5). Validates before it writes, so a broken file leaves the
	 * site exactly as it was.
	 *
	 * @return int|WP_Error
	 */
	public static function import_definition( array $json, bool $overwrite = false ) {
		$valid = self::validate( $json );
		if ( true !== $valid ) {
			return $valid;
		}

		$kind = self::sanitize_kind( (string) ( $json['kind'] ?? '' ) );
		if ( ! $overwrite && self::post_for_kind( $kind ) ) {
			return new WP_Error(
				'pipeline_kind_exists',
				sprintf( 'Pipeline "%s" đã tồn tại. Chọn ghi đè nếu muốn thay định nghĩa hiện hành.', $kind )
			);
		}

		return self::save( $kind, $json );
	}

	/** Export one definition as the plain contract document, ready to write to a .json file. */
	public static function export_definition( int $post_id ): array {
		$post = $post_id > 0 && function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post || self::CPT !== $post->post_type ) {
			return array();
		}
		$definition = self::decode( (string) $post->post_content );
		return is_array( $definition ) ? $definition : array();
	}

	/** Read one of the three shipped templates from disk. */
	/**
	 * PHASE-0.71 D63C-1 (phương án B, 0.63C GC-3) — `purchase`/`request`/`production`/`service` are
	 * business-specific kinds living in this CORE plugin, the exact anti-pattern R-WORK-PIPE-14 forbids
	 * ("3+ kind nghiệp vụ sống trong core, chưa từng là plugin riêng"). Tách 4 kind này thành plugin
	 * riêng (`bizcity-crm-pipeline-<kind>`, phương án A) chỉ đáng làm khi có đội ngoài thật cần code một
	 * kind mới — chưa có ai ngoài đội core cần điều đó hôm nay. Cho tới lúc đó, nợ này bị khoanh (không
	 * xoá) ở đúng một chỗ dễ thấy: thư mục `_bundled_pending_split/` dưới đây. **Không thêm JSON kind thứ
	 * 5/6/... nào vào thư mục này** — một kind mới thật sự thuộc về một plugin riêng ngay từ đầu, đăng ký
	 * qua filter `bizcity_crm_register_pipeline_kinds` (xem `BizCity_CRM_Pipeline_Kind_Registry`), không
	 * phải thêm file JSON vào đây.
	 */
	const BUNDLED_PENDING_SPLIT_DIR = 'templates/pipelines/_bundled_pending_split';

	public static function template( string $name ): ?array {
		$name = self::sanitize_kind( $name );
		if ( '' === $name || ! defined( 'BIZCITY_CRM_DIR' ) ) {
			return null;
		}
		$path = BIZCITY_CRM_DIR . '/' . self::BUNDLED_PENDING_SPLIT_DIR . '/' . $name . '.json';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$definition = self::decode( (string) file_get_contents( $path ) );
		return is_array( $definition ) ? $definition : null;
	}

	/**
	 * Import-ready library (PHASE-0.63C GC-24): full definitions a lead may load into the Builder or paste into
	 * "Nhập JSON". Deliberately a SEPARATE directory from BUNDLED_PENDING_SPLIT_DIR — that one is what gets seeded
	 * into every site at activation (and is frozen by D63C-1), this one is read-only reference data and never seeds.
	 */
	const LIBRARY_DIR = 'templates/pipeline-library';

	/** @return array<int,array{name:string,label:string,stages:int,sub_steps:int,definition:array}> */
	public static function library(): array {
		if ( ! defined( 'BIZCITY_CRM_DIR' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( BIZCITY_CRM_DIR . '/' . self::LIBRARY_DIR . '/*.json' ) as $path ) {
			$definition = self::decode( (string) file_get_contents( $path ) );
			if ( ! is_array( $definition ) || empty( $definition['stages'] ) ) {
				continue;
			}
			$subs = 0;
			foreach ( (array) $definition['stages'] as $stage ) {
				$subs += is_array( $stage ) ? count( (array) ( $stage['sub_steps'] ?? array() ) ) : 0;
			}
			$out[] = array(
				'name'       => basename( (string) $path, '.json' ),
				'label'      => (string) ( $definition['label'] ?? basename( (string) $path, '.json' ) ),
				'stages'     => count( (array) $definition['stages'] ),
				'sub_steps'  => $subs,
				'definition' => $definition,
			);
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
		return $out;
	}

	/**
	 * Validate WITHOUT writing (Builder live validation). Same rules and same normalisation as save(): the draft is
	 * judged as the 1.1.0 document it would be stored as.
	 *
	 * @return array{valid:bool,reasons:string[]}
	 */
	public static function dry_run( array $definition ): array {
		$definition['version'] = self::CONTRACT_VER;
		$result = self::validate( $definition );
		if ( true === $result ) {
			return array( 'valid' => true, 'reasons' => array() );
		}
		$data    = $result->get_error_data();
		$reasons = is_array( $data ) && isset( $data['reasons'] ) ? array_map( 'strval', (array) $data['reasons'] ) : array( $result->get_error_message() );
		return array( 'valid' => false, 'reasons' => array_slice( $reasons, 0, 50 ) );
	}

	/** @return string[] Template names shipped with the plugin. */
	public static function templates(): array {
		if ( ! defined( 'BIZCITY_CRM_DIR' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( BIZCITY_CRM_DIR . '/' . self::BUNDLED_PENDING_SPLIT_DIR . '/*.json' ) as $path ) {
			$out[] = basename( (string) $path, '.json' );
		}
		sort( $out );
		return $out;
	}

	/**
	 * One `catalogs.services[]` entry by key (PHASE-0.69 D69-5 — service duration is read from HERE, not
	 * re-invented by a caller). Takes the definition array directly (the caller already has it pinned to
	 * the run's `pipeline_def_version` via `get_version()`) rather than re-fetching by kind, so a run stays
	 * consistent with whatever catalog was in force when it was opened.
	 *
	 * @return array{key:string,label:string,duration_minutes:int,required_skill:?string}|null
	 */
	public static function catalog_service( array $definition, string $service_key ): ?array {
		$service_key = self::sanitize_kind( $service_key );
		if ( '' === $service_key ) {
			return null;
		}
		foreach ( (array) ( $definition['catalogs']['services'] ?? array() ) as $service ) {
			if ( is_array( $service ) && (string) ( $service['key'] ?? '' ) === $service_key ) {
				return array(
					'key'              => $service_key,
					'label'            => (string) ( $service['label'] ?? $service_key ),
					'duration_minutes' => (int) ( $service['duration_minutes'] ?? 60 ),
					'required_skill'   => isset( $service['required_skill'] ) ? (string) $service['required_skill'] : null,
				);
			}
		}
		return null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/* ------------------------------------------------------------------
	 * Validation — the PHP mirror of pipeline-definition.schema.json
	 * ------------------------------------------------------------------ */

	/**
	 * @return true|WP_Error `true`, or an error whose `data.reasons` lists every problem found.
	 */
	public static function validate( array $definition ) {
		$reasons = array();

		if ( self::CONTRACT !== ( $definition['contract'] ?? '' ) ) {
			$reasons[] = 'contract must be "' . self::CONTRACT . '"';
		}
		$declared_version = (string) ( $definition['version'] ?? '' );
		if ( ! in_array( $declared_version, self::CONTRACT_VERSIONS, true ) ) {
			$reasons[] = 'version must be one of "' . implode( '", "', self::CONTRACT_VERSIONS ) . '"';
		}
		// A document that declares 1.0.0 may not use anything 1.1.0 added — otherwise 1.0.0 would mean two shapes.
		$v11 = '1.1.0' === $declared_version;
		self::check_ui( $definition['ui'] ?? null, 'root', 'root', $v11, $reasons );

		$kind = (string) ( $definition['kind'] ?? '' );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $kind ) ) {
			$reasons[] = 'kind "' . $kind . '" is not a slug of 2-32 lowercase characters';
		}
		if ( '' === trim( (string) ( $definition['label'] ?? '' ) ) ) {
			$reasons[] = 'label is required';
		}
		if ( isset( $definition['subject'] ) && ! in_array( $definition['subject'], array( 'contact', 'account', 'order', 'asset' ), true ) ) {
			$reasons[] = 'subject "' . (string) $definition['subject'] . '" is not a core entity';
		}

		$calendars = is_array( $definition['calendars'] ?? null ) ? $definition['calendars'] : array();
		$roles     = is_array( $definition['roles'] ?? null ) ? $definition['roles'] : array();

		foreach ( $roles as $role_key => $role ) {
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{0,31}$/', (string) $role_key ) ) {
				$reasons[] = 'role key "' . (string) $role_key . '" is not a valid code';
			}
			if ( ! is_array( $role ) || '' === trim( (string) ( $role['label'] ?? '' ) ) ) {
				$reasons[] = 'role "' . (string) $role_key . '" needs a label';
				continue;
			}
			$resolve = is_array( $role['resolve'] ?? null ) ? $role['resolve'] : array();
			if ( empty( $resolve['team'] ) && empty( $resolve['users'] ) && empty( $resolve['relation'] ) ) {
				$reasons[] = 'role "' . (string) $role_key . '" resolves to nobody (needs team, users or relation)';
			}
			if ( isset( $resolve['relation'] ) && ! in_array( $resolve['relation'], array( 'team_lead_of_assignee', 'owner_of_run', 'creator_of_run', 'assignee_of_stage' ), true ) ) {
				$reasons[] = 'role "' . (string) $role_key . '" uses unknown relation "' . (string) $resolve['relation'] . '"';
			}
		}

		foreach ( $calendars as $calendar_key => $windows ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', (string) $calendar_key ) ) {
				$reasons[] = 'calendar "' . (string) $calendar_key . '" is not a valid name';
			}
			foreach ( (array) $windows as $index => $window ) {
				if ( ! is_array( $window ) || ! isset( $window['dow'], $window['from'], $window['to'] ) ) {
					$reasons[] = 'calendar "' . (string) $calendar_key . '" window #' . (int) $index . ' needs dow, from and to';
					continue;
				}
				foreach ( (array) $window['dow'] as $dow ) {
					if ( ! is_int( $dow ) || $dow < 1 || $dow > 7 ) {
						$reasons[] = 'calendar "' . (string) $calendar_key . '" has a day of week outside 1-7';
					}
				}
				foreach ( array( 'from', 'to' ) as $edge ) {
					if ( ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) $window[ $edge ] ) ) {
						$reasons[] = 'calendar "' . (string) $calendar_key . '" has a malformed ' . $edge . ' time';
					}
				}
			}
		}

		self::check_clock( $definition['clock'] ?? null, $calendars, 'definition', $reasons );
		self::check_catalogs( $definition['catalogs'] ?? null, $reasons );

		$stages = $definition['stages'] ?? null;
		if ( ! is_array( $stages ) || empty( $stages ) ) {
			$reasons[] = 'stages must list at least one step';
			$stages    = array();
		}

		$stage_keys  = array();
		$step_keys   = array();
		$sub_returns = array();
		foreach ( $stages as $index => $stage ) {
			if ( ! is_array( $stage ) ) {
				$reasons[] = 'stage #' . (int) $index . ' is not an object';
				continue;
			}
			$key = (string) ( $stage['key'] ?? '' );
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $key ) ) {
				$reasons[] = 'stage #' . (int) $index . ' has an invalid key "' . $key . '"';
				continue;
			}
			if ( isset( $stage_keys[ $key ] ) ) {
				$reasons[] = 'stage key "' . $key . '" appears twice';
				continue;
			}
			// [2026-09-25 PHASE-0.63C] A stage key must not equal the key of a sub-step listed under an EARLIER stage
			// either: both live in the same run map (custom_json.stages[key]), so the two would share one state slot.
			if ( isset( $step_keys[ $key ] ) ) {
				$reasons[] = 'step key "' . $key . '" appears twice';
				continue;
			}
			$stage_keys[ $key ] = true;
			$step_keys[ $key ]  = true;

			if ( '' === trim( (string) ( $stage['label'] ?? '' ) ) ) {
				$reasons[] = 'stage "' . $key . '" needs a label';
			}
			self::check_role( $stage['role'] ?? null, $roles, 'stage "' . $key . '"', $reasons );
			self::check_requires( $stage['requires'] ?? null, 'stage "' . $key . '"', $reasons );
			self::check_stage_sla( $stage['sla'] ?? null, $calendars, $roles, 'stage "' . $key . '"', $reasons );
			self::check_ui( $stage['ui'] ?? null, 'stage', 'stage "' . $key . '"', $v11, $reasons );
			if ( isset( $stage['outcome'] ) ) {
				if ( ! $v11 ) {
					$reasons[] = 'stage "' . $key . '" uses outcome, which needs version 1.1.0';
				} elseif ( ! in_array( $stage['outcome'], self::OUTCOMES, true ) ) {
					$reasons[] = 'stage "' . $key . '" has unknown outcome "' . (string) $stage['outcome'] . '"';
				} elseif ( empty( $stage['terminal'] ) ) {
					$reasons[] = 'stage "' . $key . '" declares an outcome but is not terminal';
				}
			}

			foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub_index => $sub ) {
				$label = 'sub-step #' . (int) $sub_index . ' of stage "' . $key . '"';
				if ( ! is_array( $sub ) ) {
					$reasons[] = $label . ' is not an object';
					continue;
				}
				$sub_key = (string) ( $sub['key'] ?? '' );
				if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $sub_key ) ) {
					$reasons[] = $label . ' has an invalid key "' . $sub_key . '"';
					continue;
				}
				if ( isset( $step_keys[ $sub_key ] ) ) {
					$reasons[] = 'step key "' . $sub_key . '" appears twice';
					continue;
				}
				$step_keys[ $sub_key ] = true;

				if ( ! in_array( $sub['mode'] ?? '', array( 'task', 'status_only' ), true ) ) {
					$reasons[] = $label . ' must declare mode "task" or "status_only"';
				}
				if ( '' === trim( (string) ( $sub['label'] ?? '' ) ) ) {
					$reasons[] = $label . ' needs a label';
				}
				self::check_role( $sub['role'] ?? null, $roles, $label, $reasons );
				self::check_requires( $sub['requires'] ?? null, $label, $reasons );
				self::check_stage_sla( $sub['sla'] ?? null, $calendars, $roles, $label, $reasons );
				self::check_ui( $sub['ui'] ?? null, 'sub_step', $label, $v11, $reasons );
				if ( isset( $sub['return_to'] ) ) {
					$sub_returns[] = array( 'label' => $label, 'to' => (string) $sub['return_to'] );
					if ( ! $v11 ) {
						$reasons[] = $label . ' uses return_to, which needs version 1.1.0';
					}
				}
			}
		}

		foreach ( $stages as $stage ) {
			if ( ! is_array( $stage ) || ! isset( $stage['return_to'] ) ) {
				continue;
			}
			if ( ! isset( $stage_keys[ (string) $stage['return_to'] ] ) ) {
				$reasons[] = 'stage "' . (string) ( $stage['key'] ?? '?' ) . '" returns to unknown step "' . (string) $stage['return_to'] . '"';
			}
		}

		// A sub-step may return to any step of the run (a sibling sub-step or a stage) — checked once every key is known.
		foreach ( $sub_returns as $return ) {
			if ( ! isset( $step_keys[ $return['to'] ] ) ) {
				$reasons[] = $return['label'] . ' returns to unknown step "' . $return['to'] . '"';
			}
		}

		foreach ( (array) ( $definition['gates'] ?? array() ) as $index => $gate ) {
			$label = 'gate #' . (int) $index;
			if ( ! is_array( $gate ) || ! isset( $gate['stage'], $gate['requires'] ) ) {
				$reasons[] = $label . ' needs a stage and a requires clause';
				continue;
			}
			// A gate can guard a sub-step too: production gates P2.4 behind P2.1..P2.1A (0.63A §4.4).
			if ( ! isset( $step_keys[ (string) $gate['stage'] ] ) ) {
				$reasons[] = $label . ' guards unknown step "' . (string) $gate['stage'] . '"';
			}
			$clauses = is_array( $gate['requires'] ) ? $gate['requires'] : array();
			if ( empty( $clauses['all_of'] ) && empty( $clauses['any_of'] ) ) {
				$reasons[] = $label . ' has neither all_of nor any_of';
			}
			foreach ( array( 'all_of', 'any_of' ) as $clause ) {
				foreach ( (array) ( $clauses[ $clause ] ?? array() ) as $needed ) {
					if ( ! isset( $step_keys[ (string) $needed ] ) ) {
						$reasons[] = $label . ' waits on unknown step "' . (string) $needed . '"';
					}
				}
			}
		}

		foreach ( (array) ( $definition['rules'] ?? array() ) as $index => $rule ) {
			self::check_sla_rule( $rule, $calendars, $roles, $step_keys, 'rule #' . (int) $index, $reasons );
		}
		if ( isset( $definition['process_sla'] ) ) {
			self::check_sla_rule( $definition['process_sla'], $calendars, $roles, $step_keys, 'process_sla', $reasons );
		}

		$exception_keys = array();
		foreach ( (array) ( $definition['exceptions'] ?? array() ) as $index => $exception ) {
			$label = 'exception #' . (int) $index;
			if ( ! is_array( $exception ) ) {
				$reasons[] = $label . ' is not an object';
				continue;
			}
			$key = (string) ( $exception['key'] ?? '' );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) ) {
				$reasons[] = $label . ' has an invalid key "' . $key . '"';
				continue;
			}
			if ( isset( $exception_keys[ $key ] ) ) {
				$reasons[] = 'exception key "' . $key . '" appears twice';
				continue;
			}
			$exception_keys[ $key ] = true;

			self::check_role( $exception['handler_role'] ?? null, $roles, 'exception "' . $key . '"', $reasons );
			if ( ! isset( $exception['handler_role'] ) ) {
				$reasons[] = 'exception "' . $key . '" has no handler_role — nobody would be asked to resolve it';
			}
			foreach ( (array) ( $exception['raised_from'] ?? array() ) as $from ) {
				if ( ! isset( $step_keys[ (string) $from ] ) ) {
					$reasons[] = 'exception "' . $key . '" is raised from unknown step "' . (string) $from . '"';
				}
			}
			foreach ( (array) ( $exception['sla'] ?? array() ) as $sla_index => $rule ) {
				self::check_sla_rule( $rule, $calendars, $roles, $step_keys, 'exception "' . $key . '" rule #' . (int) $sla_index, $reasons );
			}
		}

		foreach ( (array) ( $definition['pause_when'] ?? array() ) as $condition ) {
			if ( ! preg_match( '/^[a-z_]+\([^()]*\)$/', (string) $condition ) ) {
				$reasons[] = 'pause_when condition "' . (string) $condition . '" is not a condition expression';
			}
		}

		if ( $reasons ) {
			return new WP_Error(
				'pipeline_definition_invalid',
				'Định nghĩa pipeline không hợp lệ: ' . implode( ' · ', array_slice( $reasons, 0, 5 ) ),
				array( 'reasons' => $reasons )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Validation helpers
	 * ------------------------------------------------------------------ */

	private static function check_clock( $clock, array $calendars, string $label, array &$reasons ): void {
		if ( null === $clock ) {
			return;
		}
		$clock = (string) $clock;
		if ( '24x7' === $clock ) {
			return;
		}
		if ( ! preg_match( '/^calendar:([a-z][a-z0-9_]{0,63})$/', $clock, $m ) ) {
			$reasons[] = $label . ' has clock "' . $clock . '" — expected "24x7" or "calendar:<name>"';
			return;
		}
		if ( ! isset( $calendars[ $m[1] ] ) ) {
			$reasons[] = $label . ' uses calendar "' . $m[1] . '" which the definition does not declare';
		}
	}

	/** PHASE-0.69 S-04 — mirrors `catalogs` in `pipeline-definition.schema.json`. Optional; a kind that has
	 * no need for a skill/area/service vocabulary simply omits the whole block. */
	private static function check_catalogs( $catalogs, array &$reasons ): void {
		if ( null === $catalogs ) {
			return;
		}
		if ( ! is_array( $catalogs ) ) {
			$reasons[] = 'catalogs must be an object';
			return;
		}
		$slug = '/^[a-z0-9_]{1,64}$/';
		foreach ( array( 'skills', 'areas' ) as $list_key ) {
			foreach ( (array) ( $catalogs[ $list_key ] ?? array() ) as $item ) {
				if ( ! preg_match( $slug, (string) $item ) ) {
					$reasons[] = 'catalogs.' . $list_key . ' has invalid slug "' . (string) $item . '"';
				}
			}
		}
		$skills = array_map( 'strval', (array) ( $catalogs['skills'] ?? array() ) );
		$service_keys = array();
		foreach ( (array) ( $catalogs['services'] ?? array() ) as $index => $service ) {
			$label = 'catalogs.services #' . (int) $index;
			if ( ! is_array( $service ) || ! preg_match( $slug, (string) ( $service['key'] ?? '' ) ) ) {
				$reasons[] = $label . ' needs a valid slug key';
				continue;
			}
			if ( isset( $service_keys[ $service['key'] ] ) ) {
				$reasons[] = 'catalogs.services key "' . $service['key'] . '" appears twice';
			}
			$service_keys[ $service['key'] ] = true;
			if ( '' === trim( (string) ( $service['label'] ?? '' ) ) ) {
				$reasons[] = $label . ' needs a label';
			}
			$duration = $service['duration_minutes'] ?? null;
			if ( ! is_int( $duration ) || $duration < 1 || $duration > 1440 ) {
				$reasons[] = $label . ' needs duration_minutes between 1 and 1440';
			}
			if ( isset( $service['required_skill'] ) && ! in_array( (string) $service['required_skill'], $skills, true ) ) {
				$reasons[] = $label . ' requires skill "' . (string) $service['required_skill'] . '" which catalogs.skills does not declare';
			}
		}
	}

	/**
	 * `ui` blocks (1.1.0) — presentation/suggestion hints only. Nothing here changes what the server enforces,
	 * so the checks only keep values well-formed enough for a renderer to trust.
	 *
	 * @param string $scope 'root' | 'stage' | 'sub_step'
	 */
	private static function check_ui( $ui, string $scope, string $label, bool $v11, array &$reasons ): void {
		if ( null === $ui ) {
			return;
		}
		if ( ! $v11 ) {
			$reasons[] = $label . ' uses ui, which needs version 1.1.0';
			return;
		}
		if ( ! is_array( $ui ) ) {
			$reasons[] = $label . ' ui must be an object';
			return;
		}
		$allowed = array(
			'root'     => array( 'color', 'icon', 'category', 'lost_reasons' ),
			'stage'    => array( 'color', 'icon', 'hint', 'stuck_after', 'reminder', 'next_suggestions', 'note_placeholder' ),
			'sub_step' => array( 'hint' ),
		);
		foreach ( array_keys( $ui ) as $key ) {
			if ( ! in_array( $key, $allowed[ $scope ], true ) ) {
				$reasons[] = $label . ' ui has unknown key "' . (string) $key . '"';
			}
		}
		if ( isset( $ui['color'] ) && ! preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $ui['color'] ) ) {
			$reasons[] = $label . ' ui.color must be a #rrggbb hex';
		}
		if ( isset( $ui['icon'] ) && ! preg_match( '/^[a-z][a-z0-9-]{0,63}$/', (string) $ui['icon'] ) ) {
			$reasons[] = $label . ' ui.icon is not a valid icon name';
		}
		foreach ( array( 'hint', 'note_placeholder' ) as $text_key ) {
			if ( isset( $ui[ $text_key ] ) && ( ! is_string( $ui[ $text_key ] ) || mb_strlen( $ui[ $text_key ] ) > 190 ) ) {
				$reasons[] = $label . ' ui.' . $text_key . ' must be a string of at most 190 characters';
			}
		}
		if ( isset( $ui['category'] ) && ! in_array( $ui['category'], array( 'sales', 'purchase', 'request', 'production', 'service', 'other' ), true ) ) {
			$reasons[] = $label . ' ui.category "' . (string) $ui['category'] . '" is not a known category';
		}
		if ( isset( $ui['stuck_after'] ) && ! preg_match( '/^\+?[0-9]{1,5}(m|h|d|w)$/', (string) $ui['stuck_after'] ) ) {
			$reasons[] = $label . ' ui.stuck_after must be a positive duration such as "+3d"';
		}
		if ( isset( $ui['reminder'] ) ) {
			$reminder = $ui['reminder'];
			if ( ! is_array( $reminder ) ) {
				$reasons[] = $label . ' ui.reminder must be an object';
			} else {
				if ( isset( $reminder['title_from'] ) && ! in_array( $reminder['title_from'], array( 'first_sub_step', 'label' ), true ) ) {
					$reasons[] = $label . ' ui.reminder.title_from must be first_sub_step or label';
				}
				if ( isset( $reminder['default_offset'] ) && ! preg_match( '/^\+?[0-9]{1,5}(m|h|d|w)$/', (string) $reminder['default_offset'] ) ) {
					$reasons[] = $label . ' ui.reminder.default_offset must be a positive duration';
				}
			}
		}
		foreach ( array( 'next_suggestions' => 12, 'lost_reasons' => 12 ) as $list_key => $max ) {
			if ( ! isset( $ui[ $list_key ] ) ) {
				continue;
			}
			if ( ! is_array( $ui[ $list_key ] ) || count( $ui[ $list_key ] ) > $max ) {
				$reasons[] = $label . ' ui.' . $list_key . ' must be a list of at most ' . $max . ' strings';
				continue;
			}
			foreach ( $ui[ $list_key ] as $item ) {
				if ( ! is_string( $item ) || '' === trim( $item ) || mb_strlen( $item ) > 120 ) {
					$reasons[] = $label . ' ui.' . $list_key . ' has an empty or over-long entry';
					break;
				}
			}
		}
	}

	private static function check_role( $role, array $roles, string $label, array &$reasons ): void {
		if ( null === $role || '' === $role ) {
			return;
		}
		if ( ! isset( $roles[ (string) $role ] ) ) {
			$reasons[] = $label . ' names role "' . (string) $role . '" which the definition does not declare';
		}
	}

	private static function check_requires( $requires, string $label, array &$reasons ): void {
		if ( null === $requires ) {
			return;
		}
		if ( ! is_array( $requires ) ) {
			$reasons[] = $label . ' has a malformed requires clause';
			return;
		}
		foreach ( (array) ( $requires['evidence'] ?? array() ) as $evidence ) {
			if ( ! in_array( $evidence, array( 'photo', 'file', 'signature', 'note' ), true ) ) {
				$reasons[] = $label . ' asks for unknown evidence "' . (string) $evidence . '"';
			}
		}
		foreach ( (array) ( $requires['fields'] ?? array() ) as $field ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', (string) $field ) ) {
				$reasons[] = $label . ' requires malformed field "' . (string) $field . '"';
			}
		}
	}

	/** `stages[].sla` — the sugar form: start_within (layer 2) and finish_within (layer 1). */
	private static function check_stage_sla( $sla, array $calendars, array $roles, string $label, array &$reasons ): void {
		if ( null === $sla ) {
			return;
		}
		if ( ! is_array( $sla ) ) {
			$reasons[] = $label . ' has a malformed sla block';
			return;
		}

		self::check_clock( $sla['clock'] ?? null, $calendars, $label, $reasons );
		self::check_ladder( $sla['on_miss'] ?? array(), $roles, $label, $reasons );

		foreach ( array( 'start_within', 'finish_within' ) as $window_key ) {
			if ( ! isset( $sla[ $window_key ] ) ) {
				continue;
			}
			$window = $sla[ $window_key ];
			if ( ! is_array( $window ) || ! isset( $window['within'] ) ) {
				$reasons[] = $label . ' ' . $window_key . ' needs a "within" duration';
				continue;
			}
			if ( ! preg_match( '/^[+-]?[0-9]{1,5}(m|h|d|w)$/', (string) $window['within'] ) ) {
				$reasons[] = $label . ' ' . $window_key . ' has malformed duration "' . (string) $window['within'] . '"';
			}
			if ( 'finish_within' === $window_key && isset( $window['from'] )
				&& ! in_array( $window['from'], array( 'stage_ready', 'stage_started' ), true ) ) {
				// D63-1: the choice is explicit per definition; an unknown value must not silently pick one.
				$reasons[] = $label . ' finish_within.from must be "stage_ready" or "stage_started"';
			}
			self::check_ladder( $window['on_miss'] ?? array(), $roles, $label . ' ' . $window_key, $reasons );
		}
	}

	/** A full quadruple: anchor, offset, target, on_miss. */
	private static function check_sla_rule( $rule, array $calendars, array $roles, array $step_keys, string $label, array &$reasons ): void {
		if ( ! is_array( $rule ) ) {
			$reasons[] = $label . ' is not an object';
			return;
		}

		$anchor = (string) ( $rule['anchor'] ?? '' );
		$offset = (string) ( $rule['offset'] ?? '' );
		$target = (string) ( $rule['target'] ?? '' );

		$anchor_pattern = '/^(run_created|assigned|accepted|last_inbound|appointment_at|checkin|exception_raised'
			. '|stage_ready\(([A-Za-z0-9._-]{1,64})\)|stage_started\(([A-Za-z0-9._-]{1,64})\)'
			. '|exception_raised\(([A-Za-z0-9._-]{1,64})\)|field:[a-z][a-z0-9_]{0,63})$/';
		if ( ! preg_match( $anchor_pattern, $anchor, $anchor_match ) ) {
			$reasons[] = $label . ' has unknown anchor "' . $anchor . '"';
		} else {
			foreach ( array_slice( $anchor_match, 2 ) as $referenced ) {
				if ( '' !== $referenced && ! isset( $step_keys[ $referenced ] ) && 0 === strpos( $anchor, 'stage_' ) ) {
					$reasons[] = $label . ' anchors on unknown step "' . $referenced . '"';
				}
			}
		}

		if ( ! preg_match( '/^[+-]?[0-9]{1,5}(m|h|d|w)$/', $offset ) ) {
			$reasons[] = $label . ' has malformed offset "' . $offset . '"';
		} elseif ( 0 === strpos( $offset, '-' ) && ! self::anchor_is_future( $anchor ) ) {
			// 0.63 §2.1: a negative offset only means something when the anchor is a moment still ahead.
			$reasons[] = $label . ' uses a negative offset on anchor "' . $anchor . '", which is not a future moment';
		}

		$target_pattern = '/^(assigned|accepted|first_outbound|run_terminal|exception_ack|exception_resolved|checkin'
			. '|stage_started\(([A-Za-z0-9._-]{1,64})\)|stage_done\(([A-Za-z0-9._-]{1,64})\)'
			. '|stage_in\(\[[A-Za-z0-9._,\s-]{1,190}\]\))$/';
		if ( ! preg_match( $target_pattern, $target, $target_match ) ) {
			$reasons[] = $label . ' has unknown target "' . $target . '"';
		} else {
			foreach ( array_slice( $target_match, 2 ) as $referenced ) {
				if ( '' !== $referenced && ! isset( $step_keys[ $referenced ] ) ) {
					$reasons[] = $label . ' targets unknown step "' . $referenced . '"';
				}
			}
		}

		if ( isset( $rule['layer'] ) && ! in_array( $rule['layer'], array( 'step', 'wait', 'process', 'exception' ), true ) ) {
			$reasons[] = $label . ' declares unknown layer "' . (string) $rule['layer'] . '"';
		}
		if ( isset( $rule['id'] ) && ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', (string) $rule['id'] ) ) {
			$reasons[] = $label . ' has malformed id "' . (string) $rule['id'] . '"';
		}

		self::check_clock( $rule['clock'] ?? null, $calendars, $label, $reasons );
		self::check_requires( $rule['requires'] ?? null, $label, $reasons );
		self::check_ladder( $rule['on_miss'] ?? array(), $roles, $label, $reasons );

		foreach ( (array) ( $rule['pause_when'] ?? array() ) as $condition ) {
			if ( ! preg_match( '/^[a-z_]+\([^()]*\)$/', (string) $condition ) ) {
				$reasons[] = $label . ' has malformed pause_when "' . (string) $condition . '"';
			}
		}
	}

	private static function check_ladder( $ladder, array $roles, string $label, array &$reasons ): void {
		if ( ! is_array( $ladder ) ) {
			$reasons[] = $label . ' has a malformed on_miss ladder';
			return;
		}

		$actions = self::sla_actions();
		foreach ( $ladder as $index => $rung ) {
			$rung_label = $label . ' on_miss #' . (int) $index;
			if ( ! is_array( $rung ) || ! isset( $rung['at'], $rung['do'] ) ) {
				$reasons[] = $rung_label . ' needs both "at" and "do"';
				continue;
			}
			if ( ! preg_match( '/^(0|[+-][0-9]{1,3}%|[+-][0-9]{1,5}(m|h|d))$/', (string) $rung['at'] ) ) {
				$reasons[] = $rung_label . ' has malformed offset "' . (string) $rung['at'] . '"';
			}
			$expression = (string) $rung['do'];
			if ( ! preg_match( '/^[a-z_]+\([^()]*\)(\s*\+\s*[a-z_]+\([^()]*\))*$/', $expression ) ) {
				$reasons[] = $rung_label . ' has a malformed action "' . $expression . '"';
				continue;
			}
			foreach ( preg_split( '/\s*\+\s*/', $expression ) as $call ) {
				if ( ! preg_match( '/^([a-z_]+)\(([^()]*)\)$/', (string) $call, $m ) ) {
					continue;
				}
				if ( ! isset( $actions[ $m[1] ] ) ) {
					$reasons[] = $rung_label . ' calls unregistered action "' . $m[1] . '"';
					continue;
				}
				if ( 'flag' === $m[1] && ! in_array( trim( $m[2] ), array( 'at_risk', 'breached' ), true ) ) {
					$reasons[] = $rung_label . ' can only flag at_risk or breached';
				}
				if ( 0 === strpos( trim( $m[2] ), 'role:' ) ) {
					self::check_role( substr( trim( $m[2] ), 5 ), $roles, $rung_label, $reasons );
				}
			}
		}
	}

	private static function anchor_is_future( string $anchor ): bool {
		return in_array( $anchor, self::FUTURE_ANCHORS, true ) || 0 === strpos( $anchor, 'field:' );
	}

	/* ------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/** @return array|null */
	private static function definition_from_registration( array $entry ) {
		$definition = $entry['definition'] ?? null;
		if ( is_callable( $definition ) ) {
			$definition = call_user_func( $definition );
		}
		if ( is_string( $definition ) ) {
			$definition = is_readable( $definition ) ? self::decode( (string) file_get_contents( $definition ) ) : null;
		}
		return is_array( $definition ) ? $definition : null;
	}

	/** @return object[] */
	private static function definition_posts(): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'post_type_exists' ) || ! post_type_exists( self::CPT ) ) {
			return array();
		}
		$posts = get_posts( array(
			'post_type'        => self::CPT,
			'post_status'      => array( 'publish', 'draft' ),
			'numberposts'      => 200,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
		) );
		return is_array( $posts ) ? $posts : array();
	}

	/** @return object|null */
	public static function post_for_kind( string $kind ) {
		$kind = self::sanitize_kind( $kind );
		if ( '' === $kind ) {
			return null;
		}
		foreach ( self::definition_posts() as $post ) {
			$definition = self::decode( (string) $post->post_content );
			if ( is_array( $definition ) && self::sanitize_kind( (string) ( $definition['kind'] ?? '' ) ) === $kind ) {
				return $post;
			}
		}
		return null;
	}

	private static function def_version( int $post_id ): int {
		return function_exists( 'get_post_meta' ) ? (int) get_post_meta( $post_id, self::META_VERSION, true ) : 0;
	}

	/** @return array<int,array> */
	private static function version_archive( int $post_id ): array {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$archive = get_post_meta( $post_id, self::META_VERSIONS, true );
		return is_array( $archive ) ? array_values( $archive ) : array();
	}

	/** @return array|null */
	private static function decode( string $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function encode( array $definition ): string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $definition, $flags ) : (string) json_encode( $definition, $flags );
	}

	private static function sanitize_kind( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $value ) ? $value : '';
	}

	/** @param WP_Error|true $error @return string[] */
	private static function error_reasons( $error ): array {
		if ( ! is_object( $error ) || ! method_exists( $error, 'get_error_data' ) ) {
			return array();
		}
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['reasons'] ) ? (array) $data['reasons'] : array();
	}
}
