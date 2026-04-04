<?php
/**
 * Channel Role System — Role resolver + definitions.
 *
 * Resolves the appropriate role (cskh/admin/user) for a given
 * platform + bot instance, then returns the role definition
 * that controls Focus Router overrides, KCI locking, and tools gating.
 *
 * Storage: wp_options (no new DB table).
 *   - bizcity_channel_role_definitions  → role definitions (builtin + custom)
 *   - bizcity_channel_roles             → channel→role assignments
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_Role {

	/**
	 * Platform defaults when no assignment exists.
	 */
	const PLATFORM_DEFAULTS = [
		'WEBCHAT'        => 'cskh',
		'ADMINCHAT'      => 'auto',
		'ZALO_BOT'       => 'zalo_bot',  // dedicated: full context (via user_id link) + knowledge + skill
		'ZALO_PERSONAL'  => 'user',
		'TELEGRAM'       => 'user',
		'FACEBOOK'       => 'facebook',  // dedicated role: knowledge + skill, no memory
		'NOTEBOOK'       => 'admin',
	];

	/**
	 * Resolve channel role for a platform + bot instance.
	 *
	 * @param string $platform   Platform type (WEBCHAT, ADMINCHAT, ZALO_BOT, etc.)
	 * @param mixed  $bot_id     Bot instance ID (null for single-instance platforms).
	 * @param int    $wp_user_id WP user ID (used for 'auto' role on ADMINCHAT).
	 * @return array {
	 *   slug:       string  Role slug (cskh|admin|user|custom)
	 *   definition: array   Full role definition
	 * }
	 */
	public static function resolve( string $platform, $bot_id = null, int $wp_user_id = 0 ): array {
		$platform  = strtoupper( $platform );
		$assignments = self::get_assignments();
		$definitions = self::get_definitions();

		// Lookup key: "{platform_lower}_{bot_id}" or "{platform_lower}"
		$platform_lower = strtolower( $platform );
		$lookup_key     = ( $bot_id !== null && $bot_id !== '' )
			? $platform_lower . '_' . $bot_id
			: $platform_lower;

		// 1. Specific assignment (platform + bot_id)
		$role_slug = $assignments[ $lookup_key ] ?? null;

		// 2. Platform-level assignment
		if ( $role_slug === null ) {
			$role_slug = $assignments[ $platform_lower ] ?? null;
		}

		// 3. Hardcoded platform default
		if ( $role_slug === null ) {
			$role_slug = self::PLATFORM_DEFAULTS[ $platform ] ?? 'user';
		}

		// 4. Auto-detect for ADMINCHAT: admin if manage_options, else user
		if ( $role_slug === 'auto' ) {
			$role_slug = self::resolve_auto_role( $wp_user_id );
		}

		// Load definition (fallback to built-in 'user')
		$definition = $definitions[ $role_slug ] ?? $definitions['user'] ?? self::get_builtin_definitions()['user'];

		error_log( sprintf(
			'[ChannelRole] resolve: platform=%s | bot_id=%s | lookup=%s | role=%s | kci=%d | locked=%s',
			$platform,
			$bot_id ?? '(none)',
			$lookup_key,
			$role_slug,
			$definition['kci_ratio'] ?? 80,
			! empty( $definition['kci_locked'] ) ? 'yes' : 'no'
		) );

		return [
			'slug'       => $role_slug,
			'definition' => $definition,
		];
	}

	/**
	 * Auto-detect role from WP user capabilities.
	 */
	private static function resolve_auto_role( int $wp_user_id ): string {
		if ( $wp_user_id > 0 ) {
			$user = get_userdata( $wp_user_id );
			if ( $user && $user->has_cap( 'manage_options' ) ) {
				return 'admin';
			}
		} elseif ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return 'admin';
		}
		return 'user';
	}

	/**
	 * Get all role definitions (builtin + custom from wp_options).
	 */
	public static function get_definitions(): array {
		$stored = get_option( 'bizcity_channel_role_definitions', [] );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::get_builtin_definitions();
		}
		// Ensure builtins always present (merge stored over builtins)
		return array_merge( self::get_builtin_definitions(), $stored );
	}

	/**
	 * Get channel→role assignments from wp_options.
	 */
	public static function get_assignments(): array {
		$stored = get_option( 'bizcity_channel_roles', [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Get focus_override array for a role slug.
	 */
	public static function get_focus_override( string $role_slug ): array {
		$definitions = self::get_definitions();
		$def = $definitions[ $role_slug ] ?? null;
		if ( ! $def ) {
			return [];
		}
		return $def['focus_override'] ?? [];
	}

	/**
	 * Seed default definitions + assignments into wp_options.
	 * Called on plugin activation. Will not overwrite existing data.
	 */
	public static function seed_defaults(): void {
		if ( ! get_option( 'bizcity_channel_role_definitions' ) ) {
			update_option( 'bizcity_channel_role_definitions', self::get_builtin_definitions(), false );
		}
		if ( ! get_option( 'bizcity_channel_roles' ) ) {
			update_option( 'bizcity_channel_roles', [
				'webchat'   => 'cskh',
				'adminchat' => 'auto',
			], false );
		}
	}

	/**
	 * Three built-in role definitions.
	 */
	public static function get_builtin_definitions(): array {
		return [
			'cskh' => [
				'label'          => 'Chăm sóc khách hàng',
				'kci_ratio'      => 100,
				'kci_locked'     => true,
				'max_tokens'     => 500,
				'focus_override' => [
					'knowledge'         => true,
					'notes'             => true,
					'astro'             => false,
					'transit'           => false,
					'coaching'          => false,
					'companion'         => false,
					'relationship'      => false,
					'emotional_threads' => false,
					'cross_session'     => false,
					'project'           => false,
					'open_loops'        => false,
					'journeys'          => false,
					'token_budget'      => 2000,
				],
				'tools_enabled'  => false,
				'role_block'     => false,
				'builtin'        => true,
			],
			'admin' => [
				'label'          => 'Quản trị viên',
				'kci_ratio'      => 80,
				'kci_locked'     => false,
				'focus_override' => [],
				'tools_enabled'  => true,
				'role_block'     => true,
				'builtin'        => true,
			],
			'user' => [
				'label'          => 'Người dùng',
				'kci_ratio'      => 80,
				'kci_locked'     => false,
				'focus_override' => [
					'companion'    => 'light',
					'project'      => false,
					'notes'        => false,
					'token_budget' => 4000,
				],
				'tools_enabled'  => 'limited',
				'role_block'     => false,
				'builtin'        => true,
			],
			'facebook' => [
				'label'          => 'Facebook',
				'kci_ratio'      => 100,
				'kci_locked'     => true,
				'max_tokens'     => 600,
				'focus_override' => [
					// ✅ Knowledge RAG + Skills — đọc KB và skill instructions
					'knowledge'         => true,
					'skill'             => true,
					// ❌ Memory — tắt hoàn toàn (Messenger/comment không cần user memory)
					'memory'            => false,
					// ❌ Tắt các layer cá nhân hóa không liên quan kênh public
					'notes'             => false,
					'astro'             => false,
					'transit'           => false,
					'coaching'          => false,
					'companion'         => false,
					'relationship'      => false,
					'emotional_threads' => false,
					'cross_session'     => false,
					'project'           => false,
					'open_loops'        => false,
					'journeys'          => false,
					'token_budget'      => 2000,
				],
				'tools_enabled'  => false,
				'role_block'     => false,
				'builtin'        => true,
			],
			'zalo_bot' => [
				'label'          => 'Zalo Bot',
				'kci_ratio'      => 80,
				'kci_locked'     => false,
				'focus_override' => [
					// ✅ Full context cho user đã link WP account
					'knowledge'         => true,           // Kiến thức KB
					'skill'             => true,           // Skill instructions
					'memory'            => 'relevant',     // User memory theo wp_user_id
					'notes'             => true,           // Ghi chú cá nhân
					'companion'         => 'light',        // Companion nhẹ
					// ❌ Tắt layer nặng không phù hợp kênh chat bot
					'astro'             => false,
					'transit'           => false,
					'coaching'          => false,
					'relationship'      => false,
					'emotional_threads' => false,
					'cross_session'     => false,
					'project'           => false,
					'open_loops'        => false,
					'journeys'          => false,
					'token_budget'      => 4000,
				],
				'tools_enabled'  => 'limited',
				'role_block'     => false,
				'builtin'        => true,
			],
		];
	}

	/**
	 * All available context layer keys with display labels and possible values.
	 * Used by admin UI to render the focus override editor.
	 */
	public static function get_all_context_layers(): array {
		return [
			'identity'          => [ 'label' => 'Hồ sơ cá nhân (Identity)',       'values' => [ true, 'light', 'minimal', false ] ],
			'relationship'      => [ 'label' => 'Quan hệ (Relationship)',          'values' => [ true, false ] ],
			'emotional_threads' => [ 'label' => 'Cảm xúc (Emotional Threads)',     'values' => [ true, false ] ],
			'astro'             => [ 'label' => 'Chiêm tinh (Astro)',              'values' => [ true, 'topic', false ] ],
			'transit'           => [ 'label' => 'Transit chiêm tinh',              'values' => [ true, 'topic', false ] ],
			'coaching'          => [ 'label' => 'Coaching',                        'values' => [ true, 'topic', false ] ],
			'memory'            => [ 'label' => 'Bộ nhớ (Memory)',                 'values' => [ 'relevant', 'explicit', false ] ],
			'skill'             => [ 'label' => 'Skills (hướng dẫn kỹ năng)',      'values' => [ true, false ] ],
			'companion'         => [ 'label' => 'Companion',                       'values' => [ true, 'light', false ] ],
			'knowledge'         => [ 'label' => 'Kiến thức (Knowledge RAG)',       'values' => [ true, 'if_needed', 'sources', false ] ],
			'session'           => [ 'label' => 'Session context',                 'values' => [ true, 'compact', false ] ],
			'cross_session'     => [ 'label' => 'Cross-session',                   'values' => [ true, false ] ],
			'project'           => [ 'label' => 'Project / Notebook',              'values' => [ true, 'if_needed', false ] ],
			'notes'             => [ 'label' => 'Ghi chú (Notes)',                 'values' => [ true, 'light', false ] ],
			'focus_current'     => [ 'label' => 'Focus hiện tại',                  'values' => [ true, 'light', false ] ],
			'open_loops'        => [ 'label' => 'Open loops',                      'values' => [ true, 'light', false ] ],
			'journeys'          => [ 'label' => 'Hành trình (Journeys)',           'values' => [ true, 'alignment', false ] ],
			'response_rules'    => [ 'label' => 'Response rules',                  'values' => [ 'general', 'planner', 'tool', 'studio' ] ],
			'token_budget'      => [ 'label' => 'Token budget',                    'values' => [ 2000, 3000, 4000, 5000, 6000 ] ],
		];
	}

	/**
	 * Save role definitions (merge with builtins).
	 */
	public static function save_definitions( array $defs ): void {
		$merged = array_merge( self::get_builtin_definitions(), $defs );
		update_option( 'bizcity_channel_role_definitions', $merged, false );
	}

	/**
	 * Save channel→role assignments.
	 */
	public static function save_assignments( array $assignments ): void {
		// Sanitize keys and values
		$clean = [];
		foreach ( $assignments as $key => $slug ) {
			$k = sanitize_key( $key );
			$v = sanitize_key( $slug );
			if ( $k && $v ) {
				$clean[ $k ] = $v;
			}
		}
		update_option( 'bizcity_channel_roles', $clean, false );
	}

	/**
	 * Get all discovered bot instances for the admin UI.
	 * Queries Zalo Bot DB + Telegram/Facebook options.
	 */
	public static function get_bot_instances(): array {
		$instances = [];

		// Zalo Bots from DB
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_zalo_bots';
			$bots  = $wpdb->get_results( "SELECT id, bot_name, status FROM {$table} ORDER BY id ASC" );
			if ( $bots ) {
				foreach ( $bots as $bot ) {
					$instances[] = [
						'platform'    => 'ZALO_BOT',
						'instance_id' => (int) $bot->id,
						'label'       => $bot->bot_name ?: ( 'Bot #' . $bot->id ),
						'status'      => $bot->status === 'active',
						'key'         => 'zalo_bot_' . $bot->id,
					];
				}
			}
		}

		// Telegram (single instance per blog)
		$tg_token = get_option( 'twf_bot_token', '' );
		if ( $tg_token ) {
			$instances[] = [
				'platform'    => 'TELEGRAM',
				'instance_id' => null,
				'label'       => 'Telegram Bot',
				'status'      => true,
				'key'         => 'telegram',
			];
		}

		// Facebook (single instance per blog)
		$fb_token = get_option( 'fbm_page_access_token', '' );
		if ( $fb_token ) {
			$instances[] = [
				'platform'    => 'FACEBOOK',
				'instance_id' => null,
				'label'       => 'Facebook Page',
				'status'      => true,
				'key'         => 'facebook',
			];
		}

		// Zalo Personal (single instance per blog)
		$instances[] = [
			'platform'    => 'ZALO_PERSONAL',
			'instance_id' => null,
			'label'       => 'Zalo Cá nhân',
			'status'      => function_exists( 'send_zalo_botbanhang' ),
			'key'         => 'zalo_personal',
		];

		// Fixed platform entries
		$instances[] = [
			'platform'    => 'WEBCHAT',
			'instance_id' => null,
			'label'       => 'WebChat Widget',
			'status'      => true,
			'key'         => 'webchat',
			'locked'      => true,
		];
		$instances[] = [
			'platform'    => 'ADMINCHAT',
			'instance_id' => null,
			'label'       => 'Admin Chat',
			'status'      => true,
			'key'         => 'adminchat',
			'locked'      => true,
		];

		return $instances;
	}
}
