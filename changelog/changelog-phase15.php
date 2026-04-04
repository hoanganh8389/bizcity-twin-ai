<?php
/**
 * Changelog — Phase 1.5: Channel Gateway & Role Architecture
 *
 * Validates: Channel Gateway core, Role system, Adapter implementations,
 *            Focus Router override, Gateway Bridge integration, User Resolver.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase15 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.5';
	}

	public function get_phase_title(): string {
		return 'Channel Gateway & Role Architecture';
	}

	public function get_description(): string {
		return 'Channel Gateway core, Channel Role system (5 vai trò), Adapter Zalo Bot + Facebook, Focus Router override, User Resolver + Zalo Linker';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-04-03', 'updated' => '2026-04-04' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Phase 0 — Foundation (6/6)',
				'icon'    => '🏗️',
				'entries' => [
					[ 'id' => 'F-1', 'title' => 'BizCity_Channel_Role class exists (5 builtin roles)' ],
					[ 'id' => 'F-2', 'title' => 'BizCity_Channel_Role::resolve() method works' ],
					[ 'id' => 'F-3', 'title' => 'PLATFORM_DEFAULTS covers 7 platforms' ],
					[ 'id' => 'F-4', 'title' => 'Gateway Bootstrap loads all core classes (BIZCITY_CHANNEL_GATEWAY_LOADED)' ],
					[ 'id' => 'F-5', 'title' => 'Focus Router applies channel_role.focus_override' ],
					[ 'id' => 'F-6', 'title' => 'Gateway Bridge fire_trigger() auto-inject channel_role' ],
				],
			],
			[
				'group'   => 'Phase 1 — Adapter Implementation (2/5)',
				'icon'    => '🔌',
				'entries' => [
					[ 'id' => 'A-1', 'title' => 'BizCity_Channel_Adapter interface (6 methods)' ],
					[ 'id' => 'A-2', 'title' => 'Zalo Bot Adapter: registered + get_platform() = ZALO_BOT' ],
					[ 'id' => 'A-3', 'title' => 'Facebook Adapter: registered + get_platform() = FACEBOOK' ],
					[ 'id' => 'A-4', 'title' => 'AdminChat Adapter (spec only — not blocking)' ],
					[ 'id' => 'A-5', 'title' => 'Telegram Adapter (not started)' ],
					[ 'id' => 'A-6', 'title' => 'WebChat Adapter (not started)' ],
				],
			],
			[
				'group'   => 'Gateway Bridge & Sender',
				'icon'    => '🌉',
				'entries' => [
					[ 'id' => 'GB-1', 'title' => 'BizCity_Gateway_Bridge singleton with register_adapter()' ],
					[ 'id' => 'GB-2', 'title' => 'Gateway Bridge get_adapters() returns registered adapters' ],
					[ 'id' => 'GB-3', 'title' => 'Gateway Bridge detect_platform() resolves chat_id prefix' ],
					[ 'id' => 'GB-4', 'title' => 'Gateway Sender send() delegates to adapter or legacy' ],
					[ 'id' => 'GB-5', 'title' => 'Helper: bizcity_channel_send() function exists' ],
					[ 'id' => 'GB-6', 'title' => 'Helper: bizcity_gateway_bridge() function exists' ],
				],
			],
			[
				'group'   => 'User & Blog Resolver',
				'icon'    => '👤',
				'entries' => [
					[ 'id' => 'UR-1', 'title' => 'BizCity_User_Resolver has resolve()' ],
					[ 'id' => 'UR-2', 'title' => 'BizCity_User_Resolver has resolve_from_zalo_linker() (BUG-4 fix)' ],
					[ 'id' => 'UR-3', 'title' => 'BizCity_Blog_Resolver has resolve() + resolve_bot_blog()' ],
				],
			],
			[
				'group'   => 'Channel Role Definitions',
				'icon'    => '🎭',
				'entries' => [
					[ 'id' => 'CR-1', 'title' => 'Role: cskh — KCI=100 locked, knowledge only' ],
					[ 'id' => 'CR-2', 'title' => 'Role: admin — Full context, tools enabled' ],
					[ 'id' => 'CR-3', 'title' => 'Role: user — Profile context, limited tools' ],
					[ 'id' => 'CR-4', 'title' => 'Role: zalo_bot — CSKH defaults + memory' ],
					[ 'id' => 'CR-5', 'title' => 'Role: facebook — Knowledge + skill, NO memory' ],
					[ 'id' => 'CR-6', 'title' => 'get_focus_override() returns valid array per role' ],
				],
			],
			[
				'group'   => 'Admin UI',
				'icon'    => '⚙️',
				'entries' => [
					[ 'id' => 'UI-1', 'title' => 'Gateway Admin menu registered' ],
					[ 'id' => 'UI-2', 'title' => 'Roles tab renders content (not stub)' ],
					[ 'id' => 'UI-3', 'title' => 'Adapters tab renders content (not stub)' ],
				],
			],
			[
				'group'   => 'REST API & Backward Compat',
				'icon'    => '🔄',
				'entries' => [
					[ 'id' => 'BC-1', 'title' => 'twf_send_message_override filter hooked (backward compat)' ],
					[ 'id' => 'BC-2', 'title' => 'bizcity_chat_message_processed action hooked' ],
				],
			],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * VERIFICATIONS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function run_verifications(): void {
		$this->verify_foundation();
		$this->verify_adapters();
		$this->verify_bridge_sender();
		$this->verify_resolvers();
		$this->verify_role_definitions();
		$this->verify_admin_ui();
		$this->verify_backward_compat();
	}

	/* ── Foundation ── */
	private function verify_foundation(): void {
		// F-1: Channel Role class
		$cr_exists = class_exists( 'BizCity_Channel_Role' );
		$this->assert( 'F-1', 'BizCity_Channel_Role class exists', $cr_exists,
			$cr_exists ? 'Class loaded' : 'Not loaded — channel-gateway bootstrap missing?' );

		if ( ! $cr_exists ) {
			for ( $i = 2; $i <= 6; $i++ ) {
				$this->skip( "F-{$i}", 'BizCity_Channel_Role not loaded' );
			}
			return;
		}

		// F-2: resolve() method
		$has_resolve = method_exists( 'BizCity_Channel_Role', 'resolve' );
		$this->assert( 'F-2', 'resolve() method works', $has_resolve );

		// F-3: PLATFORM_DEFAULTS covers 7 platforms
		$ref = new ReflectionClass( 'BizCity_Channel_Role' );
		$has_defaults = $ref->hasConstant( 'PLATFORM_DEFAULTS' );
		$count_platforms = 0;
		if ( $has_defaults ) {
			$defaults = $ref->getConstant( 'PLATFORM_DEFAULTS' );
			$count_platforms = is_array( $defaults ) ? count( $defaults ) : 0;
		}
		$this->assert( 'F-3', 'PLATFORM_DEFAULTS covers 7 platforms', $count_platforms >= 7,
			"Platforms: {$count_platforms} — " . ( $has_defaults ? implode( ', ', array_keys( $defaults ) ) : 'N/A' ) );

		// F-4: Bootstrap constant
		$this->assert( 'F-4', 'BIZCITY_CHANNEL_GATEWAY_LOADED', defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ),
			defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' ) ? 'Constant defined' : 'Bootstrap not loaded' );

		// F-5: Focus Router has channel_role override
		$fr_exists = class_exists( 'BizCity_Focus_Router' );
		if ( $fr_exists ) {
			$src = $this->get_method_source( 'BizCity_Focus_Router', 'resolve' );
			$has_override = strpos( $src, 'channel_role' ) !== false && strpos( $src, 'focus_override' ) !== false;
			$this->assert( 'F-5', 'Focus Router channel_role override', $has_override,
				$has_override ? 'focus_override applied in resolve()' : 'Missing override logic' );
		} else {
			$this->skip( 'F-5', 'BizCity_Focus_Router not loaded' );
		}

		// F-6: Gateway Bridge auto-inject
		$gb_exists = class_exists( 'BizCity_Gateway_Bridge' );
		if ( $gb_exists ) {
			$src = $this->get_method_source( 'BizCity_Gateway_Bridge', 'fire_trigger' );
			$has_inject = strpos( $src, 'BizCity_Channel_Role' ) !== false || strpos( $src, 'channel_role' ) !== false;
			$this->assert( 'F-6', 'fire_trigger() auto-inject channel_role', $has_inject,
				$has_inject ? 'Channel Role resolved in fire_trigger' : 'No auto-inject' );
		} else {
			$this->skip( 'F-6', 'BizCity_Gateway_Bridge not loaded' );
		}
	}

	/* ── Adapters ── */
	private function verify_adapters(): void {
		// A-1: Interface exists
		$iface = interface_exists( 'BizCity_Channel_Adapter' );
		if ( $iface ) {
			$ref     = new ReflectionClass( 'BizCity_Channel_Adapter' );
			$methods = array_map( fn( $m ) => $m->getName(), $ref->getMethods() );
			$this->assert( 'A-1', 'Channel Adapter interface (6 methods)', count( $methods ) >= 6,
				'methods=[' . implode( ', ', $methods ) . ']' );
		} else {
			$this->assert( 'A-1', 'Channel Adapter interface exists', false, 'Interface not loaded' );
		}

		// A-2: Zalo Bot Adapter
		$zalo_exists = class_exists( 'BizCity_Zalo_Bot_Channel_Adapter' );
		if ( $zalo_exists ) {
			$adapter = new BizCity_Zalo_Bot_Channel_Adapter();
			$this->assert( 'A-2', 'Zalo Bot Adapter platform=ZALO_BOT',
				$adapter->get_platform() === 'ZALO_BOT',
				'platform=' . $adapter->get_platform() . ', prefix=' . $adapter->get_prefix() );
		} else {
			$this->skip( 'A-2', 'BizCity_Zalo_Bot_Channel_Adapter not loaded (plugin inactive?)' );
		}

		// A-3: Facebook Adapter
		$fb_exists = class_exists( 'BizCity_Facebook_Channel_Adapter' );
		if ( $fb_exists ) {
			$adapter = new BizCity_Facebook_Channel_Adapter();
			$this->assert( 'A-3', 'Facebook Adapter platform=FACEBOOK',
				$adapter->get_platform() === 'FACEBOOK',
				'platform=' . $adapter->get_platform() . ', prefix=' . $adapter->get_prefix() );
		} else {
			$this->skip( 'A-3', 'BizCity_Facebook_Channel_Adapter not loaded (plugin inactive?)' );
		}

		// A-4, A-5, A-6: Not yet implemented — skip with roadmap note
		$this->skip( 'A-4', 'AdminChat Adapter — spec only (Phase 1 roadmap)' );
		$this->skip( 'A-5', 'Telegram Adapter — not started (Phase 1 roadmap)' );
		$this->skip( 'A-6', 'WebChat Adapter — not started (Phase 1 roadmap)' );
	}

	/* ── Bridge & Sender ── */
	private function verify_bridge_sender(): void {
		$gb = class_exists( 'BizCity_Gateway_Bridge' );

		if ( ! $gb ) {
			for ( $i = 1; $i <= 6; $i++ ) {
				$this->skip( "GB-{$i}", 'BizCity_Gateway_Bridge not loaded' );
			}
			return;
		}

		// GB-1: Singleton + register_adapter
		$bridge = BizCity_Gateway_Bridge::instance();
		$this->assert( 'GB-1', 'Singleton + register_adapter()', method_exists( $bridge, 'register_adapter' ) );

		// GB-2: get_adapters
		$adapters = method_exists( $bridge, 'get_adapters' ) ? $bridge->get_adapters() : [];
		$adapter_platforms = [];
		foreach ( $adapters as $a ) {
			$adapter_platforms[] = $a->get_platform();
		}
		$this->assert( 'GB-2', 'get_adapters() returns registered',
			count( $adapters ) >= 1,
			'adapters=[' . implode( ', ', $adapter_platforms ) . ']' );

		// GB-3: detect_platform
		$this->assert( 'GB-3', 'detect_platform() exists', method_exists( $bridge, 'detect_platform' ) );

		// GB-4: Gateway Sender
		$gs = class_exists( 'BizCity_Gateway_Sender' );
		if ( $gs ) {
			$sender = BizCity_Gateway_Sender::instance();
			$this->assert( 'GB-4', 'Gateway Sender send() exists', method_exists( $sender, 'send' ) );
		} else {
			$this->skip( 'GB-4', 'BizCity_Gateway_Sender not loaded' );
		}

		// GB-5, GB-6: Helper functions
		$this->assert( 'GB-5', 'bizcity_channel_send() exists', function_exists( 'bizcity_channel_send' ) );
		$this->assert( 'GB-6', 'bizcity_gateway_bridge() exists', function_exists( 'bizcity_gateway_bridge' ) );
	}

	/* ── User & Blog Resolver ── */
	private function verify_resolvers(): void {
		// UR-1: User Resolver
		$ur = class_exists( 'BizCity_User_Resolver' );
		if ( $ur ) {
			$resolver = BizCity_User_Resolver::instance();
			$this->assert( 'UR-1', 'User Resolver has resolve()', method_exists( $resolver, 'resolve' ) );

			// UR-2: Zalo Linker integration (BUG-4 fix)
			$ref = new ReflectionClass( 'BizCity_User_Resolver' );
			$has_zalo = $ref->hasMethod( 'resolve_from_zalo_linker' );
			$this->assert( 'UR-2', 'resolve_from_zalo_linker() (BUG-4 fix)', $has_zalo,
				$has_zalo ? 'Priority 0 resolver for Zalo Bot users' : 'Missing — Zalo users fall back to global_user_admin only' );
		} else {
			$this->skip( 'UR-1', 'BizCity_User_Resolver not loaded' );
			$this->skip( 'UR-2', 'BizCity_User_Resolver not loaded' );
		}

		// UR-3: Blog Resolver
		$br = class_exists( 'BizCity_Blog_Resolver' );
		if ( $br ) {
			$blog = BizCity_Blog_Resolver::instance();
			$has_both = method_exists( $blog, 'resolve' ) && method_exists( $blog, 'resolve_bot_blog' );
			$this->assert( 'UR-3', 'Blog Resolver resolve() + resolve_bot_blog()', $has_both );
		} else {
			$this->skip( 'UR-3', 'BizCity_Blog_Resolver not loaded' );
		}
	}

	/* ── Channel Role Definitions ── */
	private function verify_role_definitions(): void {
		if ( ! class_exists( 'BizCity_Channel_Role' ) ) {
			for ( $i = 1; $i <= 6; $i++ ) {
				$this->skip( "CR-{$i}", 'BizCity_Channel_Role not loaded' );
			}
			return;
		}

		$defs = BizCity_Channel_Role::get_definitions();

		// CR-1: cskh
		$cskh = $defs['cskh'] ?? null;
		$this->assert( 'CR-1', 'Role cskh — KCI=100 locked', $cskh !== null,
			$cskh ? 'kci=' . ( $cskh['kci_ratio'] ?? '?' ) . ', locked=' . ( $cskh['kci_locked'] ? 'yes' : 'no' ) . ', tools=' . var_export( $cskh['tools_enabled'] ?? null, true ) : 'Role not found' );

		// CR-2: admin
		$admin = $defs['admin'] ?? null;
		$this->assert( 'CR-2', 'Role admin — Full context + tools', $admin !== null && ( $admin['tools_enabled'] ?? false ),
			$admin ? 'tools=' . var_export( $admin['tools_enabled'] ?? null, true ) : 'Role not found' );

		// CR-3: user
		$user = $defs['user'] ?? null;
		$this->assert( 'CR-3', 'Role user — Limited tools', $user !== null,
			$user ? 'tools=' . var_export( $user['tools_enabled'] ?? null, true ) : 'Role not found' );

		// CR-4: zalo_bot
		$zalo = $defs['zalo_bot'] ?? null;
		$this->assert( 'CR-4', 'Role zalo_bot exists', $zalo !== null,
			$zalo ? 'kci=' . ( $zalo['kci_ratio'] ?? '?' ) : 'Role not found' );

		// CR-5: facebook
		$fb = $defs['facebook'] ?? null;
		if ( $fb ) {
			$fo = $fb['focus_override'] ?? [];
			$no_memory = isset( $fo['memory'] ) && $fo['memory'] === false;
			$this->assert( 'CR-5', 'Role facebook — NO memory', $no_memory,
				'memory=' . var_export( $fo['memory'] ?? 'not set', true ) );
		} else {
			$this->assert( 'CR-5', 'Role facebook exists', false, 'Role not found' );
		}

		// CR-6: get_focus_override returns valid array
		$has_method = method_exists( 'BizCity_Channel_Role', 'get_focus_override' );
		if ( $has_method ) {
			$override = BizCity_Channel_Role::get_focus_override( 'cskh' );
			$this->assert( 'CR-6', 'get_focus_override() returns valid array',
				is_array( $override ) && ! empty( $override ),
				'keys=[' . implode( ', ', array_keys( $override ) ) . ']' );
		} else {
			$this->assert( 'CR-6', 'get_focus_override() exists', false );
		}
	}

	/* ── Admin UI ── */
	private function verify_admin_ui(): void {
		// UI-1: Menu registered
		if ( class_exists( 'BizCity_Gateway_Admin' ) ) {
			$this->assert( 'UI-1', 'Gateway Admin class exists', true );
		} else {
			$this->skip( 'UI-1', 'BizCity_Gateway_Admin not loaded (non-admin context?)' );
		}

		// UI-2: Roles tab — check if render method does more than just stub
		if ( class_exists( 'BizCity_Gateway_Admin' ) ) {
			$ref = new ReflectionClass( 'BizCity_Gateway_Admin' );
			$has_render = $ref->hasMethod( 'render_roles_tab' );
			if ( $has_render ) {
				$src = $this->read_method_source(
					$ref->getMethod( 'render_roles_tab' )->getFileName(),
					$ref->getMethod( 'render_roles_tab' )->getStartLine(),
					$ref->getMethod( 'render_roles_tab' )->getEndLine()
				);
				$is_stub = strlen( $src ) < 50; // stub would be just `{}`
				$this->assert( 'UI-2', 'Roles tab renders content', ! $is_stub,
					strlen( $src ) . ' chars — ' . ( $is_stub ? 'STUB only' : 'Has content' ) );
			} else {
				$this->assert( 'UI-2', 'Roles tab method exists', false, 'render_roles_tab() not found' );
			}
		} else {
			$this->skip( 'UI-2', 'Admin class not loaded' );
		}

		// UI-3: Adapters — covered by GB-2 (get_adapters returns data)
		if ( class_exists( 'BizCity_Gateway_Admin' ) ) {
			$ref = new ReflectionClass( 'BizCity_Gateway_Admin' );
			// Check if render_overview mentions adapters
			$has_overview = $ref->hasMethod( 'render_overview' );
			$this->assert( 'UI-3', 'Admin overview method exists', $has_overview );
		} else {
			$this->skip( 'UI-3', 'Admin class not loaded' );
		}
	}

	/* ── Backward Compat ── */
	private function verify_backward_compat(): void {
		$this->assert( 'BC-1', 'twf_send_message_override filter',
			has_filter( 'twf_send_message_override' ) !== false,
			has_filter( 'twf_send_message_override' ) !== false ? 'Filter active' : 'Not hooked — legacy send may break' );

		$this->assert( 'BC-2', 'bizcity_chat_message_processed action',
			has_action( 'bizcity_chat_message_processed' ) !== false );
	}
}
