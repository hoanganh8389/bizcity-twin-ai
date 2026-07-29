<?php
/**
 * TwinBrain Subject Profile Layer.
 *
 * Resolves the customer subject context before Notebook / vertical compose.
 * Customer answers are stored in global wp_usermeta using a tenant-scoped key:
 * bizcity_twin_profile_{blog_id}.
 *
 * ## Cache Contract (R-CACHE)
 *
 * Group: chat
 *
 * | Key pattern                                | Covers                         | TTL       | Invalidations |
 * |--------------------------------------------|--------------------------------|-----------|---------------|
 * | subject_templates_{blog_id}_{all_flag}      | merged profile templates       | TTL_SHORT | template save/import |
 * | subject_bindings_{blog_id}                 | vertical -> template bindings  | TTL_SHORT | binding save |
 * | subject_profile_{blog_id}_{user_id}         | tenant-scoped user answers     | TTL_SHORT | customer answer save |
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-19 (PHASE-TWIN-GPT-PROFILE-GROUNDING)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Subject_Profile_Layer', false ) ) {
	return;
}

final class BizCity_TwinBrain_Subject_Profile_Layer {

	const CACHE_GROUP      = 'chat';
	const CACHE_TTL        = 60;
	const OPTION_TEMPLATES = 'bizcity_twin_profile_templates_v1';
	const OPTION_BINDINGS  = 'bizcity_twin_profile_template_bindings_v1';
	const META_PREFIX      = 'bizcity_twin_profile_';
	const TEMPLATE_SCHEMA  = 'bizcity.twin.profile_template.v1';
	const ANSWER_SCHEMA    = 'bizcity.twin.profile_answers.v1';
	const VERSION          = '20260719_v1';

	/** @var self|null */
	private static $instance = null;

	/**
	 * Singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return tenant-scoped usermeta key for customer profile answers.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	public static function meta_key_for_blog( $blog_id = 0 ) {
		$blog_id = $blog_id > 0 ? (int) $blog_id : (int) get_current_blog_id();
		return self::META_PREFIX . $blog_id;
	}

	/**
	 * Seed directory for built-in templates.
	 *
	 * @return string
	 */
	public static function seed_dir() {
		if ( defined( 'BIZCITY_TWINWEB_DIR' ) ) {
			return BIZCITY_TWINWEB_DIR . 'profile-templates/';
		}
		if ( defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			return BIZCITY_TWIN_AI_DIR . 'modules/twinweb/profile-templates/';
		}
		return dirname( __DIR__, 3 ) . '/modules/twinweb/profile-templates/';
	}

	/**
	 * List effective templates, merging disk seeds with tenant overrides.
	 *
	 * @param bool $include_inactive Include draft/archived templates.
	 * @return array<int,array>
	 */
	public function all_templates( $include_inactive = false ) {
		$blog_id = (int) get_current_blog_id();
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — auto-import bundled seed templates when a tenant registry is still empty.
		$this->ensure_seed_templates_imported_if_empty( $blog_id );
		$key     = 'subject_templates_' . $blog_id . '_' . ( $include_inactive ? 'all' : 'active' );
		$cached  = $this->cache_get( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$templates = array();
		foreach ( $this->load_seed_templates() as $template ) {
			$templates[ (string) $template['slug'] ] = $template;
		}

		$stored = get_option( self::OPTION_TEMPLATES, array() );
		$stored_templates = isset( $stored['templates'] ) && is_array( $stored['templates'] ) ? $stored['templates'] : array();
		foreach ( $stored_templates as $row ) {
			$errors = array();
			$template = $this->normalize_template( is_array( $row ) ? $row : array(), $errors );
			if ( is_array( $template ) ) {
				$template['source'] = 'tenant';
				$templates[ (string) $template['slug'] ] = $template;
			}
		}

		$out = array();
		foreach ( $templates as $template ) {
			$status = isset( $template['status'] ) ? (string) $template['status'] : 'active';
			if ( $include_inactive || 'active' === $status ) {
				$out[] = $template;
			}
		}
		usort( $out, static function ( $a, $b ) {
			$ao = isset( $a['order'] ) ? (int) $a['order'] : 100;
			$bo = isset( $b['order'] ) ? (int) $b['order'] : 100;
			if ( $ao === $bo ) {
				return strcmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
			}
			return $ao < $bo ? -1 : 1;
		} );

		$this->cache_set( $key, $out );
		return $out;
	}

	/**
	 * Get one effective template by slug.
	 *
	 * @param string $slug Template slug.
	 * @return array|null
	 */
	public function get_template( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $this->all_templates( true ) as $template ) {
			if ( (string) $template['slug'] === $slug ) {
				return $template;
			}
		}
		return null;
	}

	/**
	 * Save a tenant template.
	 *
	 * @param array $raw Raw template.
	 * @return array|WP_Error
	 */
	public function save_template( array $raw ) {
		$errors = array();
		$template = $this->normalize_template( $raw, $errors );
		if ( ! is_array( $template ) || ! empty( $errors ) ) {
			return new WP_Error( 'invalid_param', 'Template hồ sơ không hợp lệ.', array(
				'status'    => 400,
				'errors'    => $errors,
				'hint'      => 'Kiểm tra slug, số câu hỏi tối đa 10 và kiểu field hợp lệ.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		$payload = get_option( self::OPTION_TEMPLATES, array() );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		if ( empty( $payload['templates'] ) || ! is_array( $payload['templates'] ) ) {
			$payload['templates'] = array();
		}
		$template['source'] = 'tenant';
		$template['updated_at'] = gmdate( 'c' );
		$template['updated_by'] = (int) get_current_user_id();
		$payload['schema'] = self::TEMPLATE_SCHEMA;
		$payload['version'] = self::VERSION;
		$payload['templates'][ (string) $template['slug'] ] = $template;
		update_option( self::OPTION_TEMPLATES, $payload, false );
		$this->flush_cache();

		return $template;
	}

	/**
	 * Import disk seed templates into tenant option.
	 *
	 * @param bool $overwrite Whether to overwrite existing tenant templates.
	 * @return array
	 */
	public function import_seed_templates( $overwrite = false ) {
		$payload = get_option( self::OPTION_TEMPLATES, array() );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}
		if ( empty( $payload['templates'] ) || ! is_array( $payload['templates'] ) ) {
			$payload['templates'] = array();
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		foreach ( $this->load_seed_templates() as $template ) {
			$slug = (string) $template['slug'];
			if ( isset( $payload['templates'][ $slug ] ) && ! $overwrite ) {
				$skipped++;
				continue;
			}
			$template['source'] = 'tenant';
			$template['updated_at'] = gmdate( 'c' );
			$template['updated_by'] = (int) get_current_user_id();
			if ( isset( $payload['templates'][ $slug ] ) ) {
				$updated++;
			} else {
				$created++;
			}
			$payload['templates'][ $slug ] = $template;
		}

		$payload['schema'] = self::TEMPLATE_SCHEMA;
		$payload['version'] = self::VERSION;
		update_option( self::OPTION_TEMPLATES, $payload, false );
		$this->flush_cache();

		return array(
			'success' => true,
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'total'   => count( $payload['templates'] ),
		);
	}

	private function ensure_seed_templates_imported_if_empty( $blog_id ) {
		static $checked = array();
		$blog_id = (int) $blog_id;
		if ( isset( $checked[ $blog_id ] ) ) {
			return;
		}
		$checked[ $blog_id ] = true;

		$payload = get_option( self::OPTION_TEMPLATES, array() );
		$templates = is_array( $payload ) && isset( $payload['templates'] ) && is_array( $payload['templates'] ) ? $payload['templates'] : array();
		if ( ! empty( $templates ) ) {
			return;
		}

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — avoid writing an empty option when seed files are missing in standalone/dev deploys.
		$seeds = $this->load_seed_templates();
		if ( empty( $seeds ) ) {
			return;
		}

		$payload = array(
			'schema'    => self::TEMPLATE_SCHEMA,
			'version'   => self::VERSION,
			'templates' => array(),
		);
		foreach ( $seeds as $template ) {
			$template['source'] = 'tenant';
			$template['updated_at'] = gmdate( 'c' );
			$template['updated_by'] = (int) get_current_user_id();
			$payload['templates'][ (string) $template['slug'] ] = $template;
		}
		update_option( self::OPTION_TEMPLATES, $payload, false );
		$this->flush_cache();
	}

	/**
	 * Get vertical/mode template bindings.
	 *
	 * @return array
	 */
	public function get_bindings() {
		$blog_id = (int) get_current_blog_id();
		$key     = 'subject_bindings_' . $blog_id;
		$cached  = $this->cache_get( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$raw = get_option( self::OPTION_BINDINGS, array() );
		$bindings = $this->normalize_bindings( is_array( $raw ) ? $raw : array() );
		$this->cache_set( $key, $bindings );
		return $bindings;
	}

	/**
	 * Save vertical/mode bindings.
	 *
	 * @param array $raw Raw bindings.
	 * @return array
	 */
	public function save_bindings( array $raw ) {
		$bindings = $this->normalize_bindings( $raw );
		update_option( self::OPTION_BINDINGS, $bindings, false );
		$this->flush_cache();
		return $bindings;
	}

	/**
	 * Read tenant-scoped customer profile answers.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	public function get_user_profile( $user_id, $blog_id = 0 ) {
		$user_id = (int) $user_id;
		$blog_id = $blog_id > 0 ? (int) $blog_id : (int) get_current_blog_id();
		if ( $user_id <= 0 || $blog_id <= 0 ) {
			return $this->empty_profile_root( $blog_id );
		}

		$key = 'subject_profile_' . $blog_id . '_' . $user_id;
		$cached = $this->cache_get( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$meta_key = self::meta_key_for_blog( $blog_id );
		// [2026-07-27 Johnny Chu] R-PERF — profile storage read uses one direct-SQL user-meta cache lookup.
		$raw = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, $meta_key, array() )
			: get_user_meta( $user_id, $meta_key, true );
		$profile = is_array( $raw ) ? $raw : array();
		$profile = $this->normalize_profile_root( $profile, $blog_id );
		$this->cache_set( $key, $profile );
		return $profile;
	}

	/**
	 * Save answers for one template into tenant-scoped usermeta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $template_slug Template slug.
	 * @param array  $answers Raw answers.
	 * @param bool   $activate Mark as active template.
	 * @param int    $blog_id Blog ID.
	 * @return array|WP_Error
	 */
	public function save_user_answers( $user_id, $template_slug, array $answers, $activate = true, $blog_id = 0 ) {
		$user_id = (int) $user_id;
		$blog_id = $blog_id > 0 ? (int) $blog_id : (int) get_current_blog_id();
		$template_slug = sanitize_key( (string) $template_slug );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'auth_required', 'Bạn cần đăng nhập để lưu hồ sơ.', array( 'status' => 401, 'hint' => 'Đăng nhập rồi thử lại.', 'help_code' => 'auth_required' ) );
		}
		$template = $this->get_template( $template_slug );
		if ( ! is_array( $template ) ) {
			return new WP_Error( 'not_found', 'Không tìm thấy mẫu hồ sơ.', array( 'status' => 404, 'hint' => 'Chọn lại mẫu hồ sơ đang hoạt động.', 'help_code' => 'not_found' ) );
		}

		$normalized = $this->normalize_answers_for_template( $template, $answers );
		$root = $this->get_user_profile( $user_id, $blog_id );
		$root['schema'] = self::ANSWER_SCHEMA;
		$root['blog_id'] = $blog_id;
		if ( empty( $root['templates'] ) || ! is_array( $root['templates'] ) ) {
			$root['templates'] = array();
		}
		$root['templates'][ $template_slug ] = array(
			'template_slug'    => $template_slug,
			'template_version' => (string) ( $template['version'] ?? '1.0.0' ),
			'answered_at'      => gmdate( 'c' ),
			'answers'          => $normalized['answers'],
			'missing_required' => $normalized['missing_required'],
		);
		if ( $activate ) {
			$root['active_template_slug'] = $template_slug;
		}

		$meta_key = self::meta_key_for_blog( $blog_id );
		// [2026-07-27 Johnny Chu] R-PERF — profile storage write updates DB and in-request user-meta cache together.
		if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
			BizCity_User_Meta_Cache::set( $user_id, $meta_key, $root );
		} else {
			update_user_meta( $user_id, $meta_key, $root );
		}
		clean_user_cache( $user_id );
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — delete exact profile cache before same-request reload after usermeta write.
		$this->cache_delete( 'subject_profile_' . $blog_id . '_' . $user_id );
		$this->flush_cache();
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — let CRM/admin care surfaces enrich their contact view without coupling storage to CRM submissions.
		do_action( 'bizcity_twin_profile_saved', $user_id, $template_slug, $root, $template );

		return $this->get_user_profile( $user_id, $blog_id );
	}

	/**
	 * Collect subject context for a TwinBrain turn.
	 *
	 * @param int    $user_id User ID.
	 * @param string $prompt User prompt.
	 * @param array  $opts Runtime options.
	 * @return array
	 */
	public function collect_for_user( $user_id, $prompt = '', array $opts = array() ) {
		$t0 = microtime( true );
		$user_id = (int) $user_id;
		$blog_id = (int) get_current_blog_id();
		if ( $user_id <= 0 ) {
			return $this->degraded_result( 'identity_missing', 'Không có user_id để xác định chủ thể.', $t0 );
		}

		$template_slug = $this->resolve_template_slug( $user_id, $opts );
		$template = $template_slug !== '' ? $this->get_template( $template_slug ) : null;
		$profile = $this->get_user_profile( $user_id, $blog_id );
		$entry = ( is_array( $template ) && isset( $profile['templates'][ $template_slug ] ) && is_array( $profile['templates'][ $template_slug ] ) )
			? $profile['templates'][ $template_slug ]
			: array();

		$wp_facts = $this->collect_wp_user_facts( $user_id, is_array( $template ) ? $template : array() );
		$answers = isset( $entry['answers'] ) && is_array( $entry['answers'] ) ? $entry['answers'] : array();
		$missing_required = is_array( $template ) ? $this->missing_required_for_template( $template, $answers ) : array();

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — do not treat WP user facts or an empty template shell as the real conversation subject.
		$has_template = is_array( $template );
		$has_profile_answers = ! empty( $answers );
		$effective_wp_facts = ( $has_template && ! $has_profile_answers ) ? array() : $wp_facts;
		$effective_facts = array_merge( $effective_wp_facts, $answers );
		$context_md = $this->render_context_md(
			$has_template ? $template : array(),
			$answers,
			$effective_wp_facts,
			$missing_required
		);
		$active = $has_template ? $has_profile_answers : trim( $context_md ) !== '';
		$degraded_reason = '';
		if ( $has_template && empty( $answers ) ) {
			$degraded_reason = 'profile_missing_required';
		} elseif ( ! $has_template && empty( $wp_facts ) ) {
			$degraded_reason = 'template_missing';
		}

		return array(
			'active'             => $active,
			'subject_type'       => 'wp_user',
			'user_id'            => $user_id,
			'blog_id'            => $blog_id,
			'template_slug'      => is_array( $template ) ? (string) $template['slug'] : '',
			'template_version'   => is_array( $template ) ? (string) ( $template['version'] ?? '1.0.0' ) : '',
			'context_md'         => $context_md,
			'facts'              => $effective_facts,
			'facts_count'        => count( $effective_facts ),
			'missing_required'   => $missing_required,
			'degraded_reason'    => $degraded_reason,
			'latency_ms'         => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
			'prompt_hash'        => substr( hash( 'sha256', (string) $prompt ), 0, 12 ),
		);
	}

	/**
	 * Normalize one template.
	 *
	 * @param array $raw Raw template.
	 * @param array $errors Error collector.
	 * @return array|null
	 */
	public function normalize_template( array $raw, array &$errors = array() ) {
		$slug = sanitize_key( (string) ( $raw['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$errors[] = 'missing_slug';
			return null;
		}
		$questions_raw = isset( $raw['questions'] ) && is_array( $raw['questions'] ) ? $raw['questions'] : array();
		if ( count( $questions_raw ) > 10 ) {
			$errors[] = 'too_many_questions';
		}

		$questions = array();
		$seen = array();
		$allowed_types = array( 'text', 'textarea', 'select', 'multiselect', 'number', 'date', 'boolean' );
		foreach ( $questions_raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$errors[] = 'invalid_question_key';
				continue;
			}
			$type = sanitize_key( (string) ( $row['type'] ?? 'text' ) );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$errors[] = 'invalid_question_type_' . $key;
				$type = 'text';
			}
			$choices = array();
			if ( isset( $row['choices'] ) && is_array( $row['choices'] ) ) {
				foreach ( $row['choices'] as $choice_key => $choice_label ) {
					$ck = sanitize_key( (string) $choice_key );
					if ( '' !== $ck ) {
						$choices[ $ck ] = sanitize_text_field( (string) $choice_label );
					}
				}
			}
			$seen[ $key ] = true;
			$questions[] = array(
				'key'        => $key,
				'label'      => sanitize_text_field( (string) ( $row['label'] ?? $key ) ),
				'type'       => $type,
				'required'   => ! empty( $row['required'] ),
				'max_length' => isset( $row['max_length'] ) ? max( 20, min( 1200, (int) $row['max_length'] ) ) : ( 'textarea' === $type ? 600 : 160 ),
				'choices'    => $choices,
			);
		}

		$status = sanitize_key( (string) ( $raw['status'] ?? 'active' ) );
		if ( ! in_array( $status, array( 'active', 'draft', 'archived' ), true ) ) {
			$status = 'draft';
		}

		$allowlist = array();
		$safe_user_meta = $this->safe_user_meta_keys();
		foreach ( (array) ( $raw['wp_user_meta_allowlist'] ?? array() ) as $meta_key ) {
			$mk = sanitize_key( (string) $meta_key );
			if ( in_array( $mk, $safe_user_meta, true ) ) {
				$allowlist[] = $mk;
			}
		}

		$grounding = isset( $raw['grounding'] ) && is_array( $raw['grounding'] ) ? $raw['grounding'] : array();
		$format = sanitize_key( (string) ( $raw['format'] ?? 'intake' ) );
		if ( ! in_array( $format, array( 'intake', 'casefile', 'assessment', 'checklist', 'followup', 'product_advisor' ), true ) ) {
			$format = 'intake';
		}
		$risk_level = sanitize_key( (string) ( $raw['risk_level'] ?? 'standard' ) );
		if ( ! in_array( $risk_level, array( 'low', 'standard', 'sensitive' ), true ) ) {
			$risk_level = 'standard';
		}
		return array(
			'schema'                 => self::TEMPLATE_SCHEMA,
			'slug'                   => $slug,
			'version'                => sanitize_text_field( (string) ( $raw['version'] ?? '1.0.0' ) ),
			'label'                  => sanitize_text_field( (string) ( $raw['label'] ?? $slug ) ),
			'description'            => sanitize_textarea_field( (string) ( $raw['description'] ?? '' ) ),
			'vertical'               => sanitize_key( (string) ( $raw['vertical'] ?? $slug ) ),
			'domain'                 => sanitize_key( (string) ( $raw['domain'] ?? ( $raw['vertical'] ?? $slug ) ) ),
			'format'                 => $format,
			'expert_role'            => sanitize_text_field( (string) ( $raw['expert_role'] ?? '' ) ),
			'risk_level'             => $risk_level,
			'status'                 => $status,
			'order'                  => isset( $raw['order'] ) ? (int) $raw['order'] : 100,
			'max_questions'          => 10,
			'question_limit_policy'  => 'reject_over_10',
			'wp_user_meta_allowlist' => array_values( array_unique( $allowlist ) ),
			'questions'              => $questions,
			'grounding'              => array(
				'context_title'        => sanitize_text_field( (string) ( $grounding['context_title'] ?? 'HỒ SƠ CUSTOMER' ) ),
				'composer_rule'        => sanitize_textarea_field( (string) ( $grounding['composer_rule'] ?? 'Cá nhân hóa câu trả lời theo hồ sơ customer; không bịa dữ kiện ngoài hồ sơ.' ) ),
				'missing_profile_hint' => sanitize_text_field( (string) ( $grounding['missing_profile_hint'] ?? 'Hoàn tất hồ sơ để câu trả lời cá nhân hóa hơn.' ) ),
			),
			'source'                 => sanitize_key( (string) ( $raw['source'] ?? 'seed' ) ),
		);
	}

	private function load_seed_templates() {
		$dir = self::seed_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = glob( $dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		$out = array();
		foreach ( $files as $path ) {
			$raw = json_decode( (string) file_get_contents( $path ), true );
			$errors = array();
			$template = is_array( $raw ) ? $this->normalize_template( $raw, $errors ) : null;
			if ( is_array( $template ) && empty( $errors ) ) {
				$template['source'] = 'seed';
				$out[] = $template;
			}
		}
		return $out;
	}

	private function normalize_bindings( array $raw ) {
		$bindings = array(
			'schema'     => 'bizcity.twin.profile_bindings.v1',
			'version'    => self::VERSION,
			'by_vertical'=> array(),
			'by_mode'    => array(),
			'default'    => '',
			'updated_at' => isset( $raw['updated_at'] ) ? (string) $raw['updated_at'] : '',
			'updated_by' => isset( $raw['updated_by'] ) ? (int) $raw['updated_by'] : 0,
		);

		foreach ( array( 'by_vertical', 'by_mode' ) as $bucket ) {
			$rows = isset( $raw[ $bucket ] ) && is_array( $raw[ $bucket ] ) ? $raw[ $bucket ] : array();
			foreach ( $rows as $key => $slug ) {
				$k = sanitize_key( (string) $key );
				$s = sanitize_key( (string) $slug );
				if ( '' !== $k && '' !== $s && $this->get_template( $s ) ) {
					$bindings[ $bucket ][ $k ] = $s;
				}
			}
		}
		$default = sanitize_key( (string) ( $raw['default'] ?? '' ) );
		if ( '' !== $default && $this->get_template( $default ) ) {
			$bindings['default'] = $default;
		}
		return $bindings;
	}

	private function resolve_template_slug( $user_id, array $opts ) {
		$explicit = sanitize_key( (string) ( $opts['profile_template_slug'] ?? '' ) );
		if ( '' !== $explicit && $this->get_template( $explicit ) ) {
			return $explicit;
		}
		$bindings = $this->get_bindings();
		$vertical = sanitize_key( (string) ( $opts['vertical'] ?? '' ) );
		$mode = sanitize_key( (string) ( $opts['web_mode'] ?? ( $opts['mode'] ?? '' ) ) );
		if ( '' !== $vertical && ! empty( $bindings['by_vertical'][ $vertical ] ) ) {
			return (string) $bindings['by_vertical'][ $vertical ];
		}
		if ( '' !== $mode && ! empty( $bindings['by_mode'][ $mode ] ) ) {
			return (string) $bindings['by_mode'][ $mode ];
		}
		$profile = $this->get_user_profile( $user_id );
		$active = sanitize_key( (string) ( $profile['active_template_slug'] ?? '' ) );
		if ( '' !== $active && $this->get_template( $active ) ) {
			return $active;
		}
		return ! empty( $bindings['default'] ) ? (string) $bindings['default'] : '';
	}

	private function normalize_profile_root( array $profile, $blog_id ) {
		$out = $this->empty_profile_root( $blog_id );
		$out['active_template_slug'] = sanitize_key( (string) ( $profile['active_template_slug'] ?? '' ) );
		$templates = isset( $profile['templates'] ) && is_array( $profile['templates'] ) ? $profile['templates'] : array();
		foreach ( $templates as $slug => $entry ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $entry ) ) {
				continue;
			}
			$out['templates'][ $slug ] = array(
				'template_slug'    => $slug,
				'template_version' => sanitize_text_field( (string) ( $entry['template_version'] ?? '1.0.0' ) ),
				'answered_at'      => sanitize_text_field( (string) ( $entry['answered_at'] ?? '' ) ),
				'answers'          => isset( $entry['answers'] ) && is_array( $entry['answers'] ) ? $entry['answers'] : array(),
				'missing_required' => isset( $entry['missing_required'] ) && is_array( $entry['missing_required'] ) ? array_values( array_map( 'sanitize_key', $entry['missing_required'] ) ) : array(),
			);
		}
		return $out;
	}

	private function empty_profile_root( $blog_id ) {
		return array(
			'schema'               => self::ANSWER_SCHEMA,
			'blog_id'              => (int) $blog_id,
			'active_template_slug' => '',
			'templates'            => array(),
		);
	}

	private function normalize_answers_for_template( array $template, array $answers ) {
		$out = array();
		foreach ( (array) ( $template['questions'] ?? array() ) as $question ) {
			$key = (string) ( $question['key'] ?? '' );
			if ( '' === $key || ! array_key_exists( $key, $answers ) ) {
				continue;
			}
			$value = $this->sanitize_answer_value( $answers[ $key ], $question );
			if ( '' !== $value && array() !== $value && null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return array(
			'answers'          => $out,
			'missing_required' => $this->missing_required_for_template( $template, $out ),
		);
	}

	private function sanitize_answer_value( $value, array $question ) {
		$type = (string) ( $question['type'] ?? 'text' );
		$max  = isset( $question['max_length'] ) ? (int) $question['max_length'] : 300;
		if ( 'boolean' === $type ) {
			return ! empty( $value ) ? '1' : '0';
		}
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? (string) ( 0 + $value ) : '';
		}
		if ( 'date' === $type ) {
			$value = sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		}
		if ( 'select' === $type ) {
			$key = sanitize_key( (string) $value );
			$choices = isset( $question['choices'] ) && is_array( $question['choices'] ) ? $question['choices'] : array();
			return isset( $choices[ $key ] ) ? $key : '';
		}
		if ( 'multiselect' === $type ) {
			$choices = isset( $question['choices'] ) && is_array( $question['choices'] ) ? $question['choices'] : array();
			$out = array();
			foreach ( (array) $value as $one ) {
				$key = sanitize_key( (string) $one );
				if ( isset( $choices[ $key ] ) ) {
					$out[] = $key;
				}
			}
			return array_values( array_unique( $out ) );
		}
		$text = 'textarea' === $type ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
		return mb_substr( $text, 0, max( 20, $max ) );
	}

	private function missing_required_for_template( array $template, array $answers ) {
		$missing = array();
		foreach ( (array) ( $template['questions'] ?? array() ) as $question ) {
			$key = (string) ( $question['key'] ?? '' );
			if ( ! empty( $question['required'] ) && ( '' === $key || ! isset( $answers[ $key ] ) || '' === $answers[ $key ] || array() === $answers[ $key ] ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	private function collect_wp_user_facts( $user_id, array $template ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return array();
		}
		$allow = isset( $template['wp_user_meta_allowlist'] ) && is_array( $template['wp_user_meta_allowlist'] )
			? $template['wp_user_meta_allowlist']
			: array( 'first_name', 'last_name', 'nickname' );
		$facts = array();
		if ( in_array( 'display_name', $allow, true ) && '' !== (string) $user->display_name ) {
			$facts['display_name'] = sanitize_text_field( (string) $user->display_name );
		}
		foreach ( $allow as $meta_key ) {
			$meta_key = sanitize_key( (string) $meta_key );
			if ( in_array( $meta_key, array( 'display_name', 'user_login' ), true ) ) {
				continue;
			}
			if ( ! in_array( $meta_key, $this->safe_user_meta_keys(), true ) ) {
				continue;
			}
			// [2026-07-27 Johnny Chu] R-PERF — allowlisted profile facts use the unified user-meta cache.
			$value = class_exists( 'BizCity_User_Meta_Cache' )
				? BizCity_User_Meta_Cache::get( (int) $user_id, $meta_key, '' )
				: get_user_meta( (int) $user_id, $meta_key, true );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$facts[ $meta_key ] = 'phone' === $meta_key ? $this->mask_phone( (string) $value ) : sanitize_text_field( (string) $value );
			}
		}
		return $facts;
	}

	private function render_context_md( array $template, array $answers, array $wp_facts, array $missing_required ) {
		$lines = array();
		$title = isset( $template['grounding']['context_title'] ) ? (string) $template['grounding']['context_title'] : 'HỒ SƠ CUSTOMER';
		if ( ! empty( $template ) ) {
			$lines[] = '## ' . $title;
			$lines[] = '- Template: ' . (string) ( $template['label'] ?? $template['slug'] ?? '' );
			$rule = trim( (string) ( $template['grounding']['composer_rule'] ?? '' ) );
			if ( '' !== $rule ) {
				$lines[] = '- Quy tắc cá nhân hóa: ' . $rule;
			}
		} else {
			$lines[] = '## HỒ SƠ WP USER CỦA CUSTOMER';
		}

		if ( ! empty( $wp_facts ) ) {
			$lines[] = '';
			$lines[] = '### Thông tin WordPress an toàn';
			foreach ( $wp_facts as $key => $value ) {
				$lines[] = '- ' . $this->label_from_key( $key ) . ': ' . $this->render_value( $value );
			}
		}

		if ( ! empty( $answers ) && ! empty( $template['questions'] ) ) {
			$lines[] = '';
			$lines[] = '### Câu trả lời hồ sơ';
			$labels = array();
			foreach ( (array) $template['questions'] as $question ) {
				$labels[ (string) $question['key'] ] = (string) $question['label'];
			}
			foreach ( $answers as $key => $value ) {
				$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $this->label_from_key( $key );
				$lines[] = '- ' . $label . ': ' . $this->render_value( $value );
			}
		}

		if ( ! empty( $missing_required ) ) {
			$lines[] = '';
			$lines[] = '### Hồ sơ còn thiếu';
			foreach ( $missing_required as $key ) {
				$lines[] = '- ' . $this->label_from_key( $key );
			}
		}

		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	private function render_value( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
		}
		return sanitize_text_field( (string) $value );
	}

	private function label_from_key( $key ) {
		$key = str_replace( '_', ' ', (string) $key );
		return ucwords( $key );
	}

	private function mask_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $digits ) <= 4 ) {
			return '***';
		}
		return '***' . substr( $digits, -4 );
	}

	private function safe_user_meta_keys() {
		return array( 'first_name', 'last_name', 'nickname', 'description', 'locale', 'phone', 'billing_city', 'billing_country', 'billing_company', 'billing_state' );
	}

	private function degraded_result( $reason, $hint, $t0 ) {
		return array(
			'active'           => false,
			'subject_type'     => 'wp_user',
			'user_id'          => 0,
			'blog_id'          => (int) get_current_blog_id(),
			'template_slug'    => '',
			'template_version' => '',
			'context_md'       => '',
			'facts'            => array(),
			'facts_count'      => 0,
			'missing_required' => array(),
			'degraded_reason'  => (string) $reason,
			'hint'             => (string) $hint,
			'latency_ms'       => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		);
	}

	private function cache_get( $key ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			return BizCity_Cache::get( self::CACHE_GROUP, (string) $key );
		}
		return wp_cache_get( (string) $key, self::CACHE_GROUP );
	}

	private function cache_set( $key, $value ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, (string) $key, $value, self::CACHE_TTL );
			return;
		}
		wp_cache_set( (string) $key, $value, self::CACHE_GROUP, self::CACHE_TTL );
	}

	private function cache_delete( $key ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::delete( self::CACHE_GROUP, (string) $key );
			return;
		}
		wp_cache_delete( (string) $key, self::CACHE_GROUP );
	}

	private function flush_cache() {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
			return;
		}
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — support environments where only WP object-cache group flush is loaded.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'chat', 'core.twinbrain.subject_profile', array(
		'subject_templates_{blog_id}_{all_flag}' => array( 'ttl' => 60, 'desc' => 'Merged Twin GPT customer profile templates' ),
		'subject_bindings_{blog_id}'             => array( 'ttl' => 60, 'desc' => 'Twin GPT vertical to profile template bindings' ),
		'subject_profile_{blog_id}_{user_id}'    => array( 'ttl' => 60, 'desc' => 'Tenant-scoped customer profile answers from wp_usermeta' ),
	) );
}
