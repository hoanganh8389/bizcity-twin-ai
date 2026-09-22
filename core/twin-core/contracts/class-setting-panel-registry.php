<?php
/**
 * BizCity Twin AI - Setting Panel metadata registry.
 *
 * The registry owns normalized discovery metadata only. It does not register
 * WordPress menus, resolve renderers, query storage or make provider calls.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 2026-09-13
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Setting_Panel_Registry' ) ) {

	final class BizCity_Setting_Panel_Registry {

		const CONTRACT = 'setting-panel-registration';
		const VERSION  = '1.0.0';

		private static $items = array();
		private static $errors = array();
		private static $renderer_owners = array();

		/** @var string[] */
		private static $destinations = array(
			'workspace',
			'settings',
			'control-panel',
			'channel-settings',
			'crm-inbox',
			'plugins-store',
		);

		/** @var string[] */
		private static $origins = array( 'core', 'module', 'bundle', 'extension', 'legacy_adapter' );

		/** @var string[] */
		private static $scopes = array( 'site', 'network', 'user', 'site_user' );

		/** @var string[] */
		private static $surfaces = array( 'admin_shell', 'admin_page', 'network_admin', 'external' );

		/** @var string[] */
		private static $renderer_types = array( 'coreui', 'route', 'embed', 'deep_link', 'external' );

		/** @var string[] */
		private static $availability_policies = array( 'registered-owner', 'all', 'any', 'always' );

		/** @var string[] */
		private static $zones = array( 'customer', 'admin', 'system' );

		/**
		 * Resolve one admitted item into a render-ready row.
		 *
		 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — resolve labels, URLs and availability
		 * server-side so the frontend never has to guess a label or build a link.
		 * The resolver is still metadata-only: it never loads a renderer, reads
		 * an option, calls a provider or mutates owner data.
		 *
		 * @param array<string,mixed> $item Normalized registry item.
		 * @return array<string,mixed> Item plus resolved `label`, `description`, `url`, `availability_state`.
		 */
		public static function resolve( array $item ) {
			$item['label']              = self::resolve_label( $item, 'label_key' );
			$item['description']        = self::resolve_label( $item, 'description_key' );
			$item['url']                = self::resolve_url( $item );
			$item['availability_state'] = self::resolve_availability( $item );
			return $item;
		}

		/**
		 * Resolve every admitted item for the current request context.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function resolved_all(): array {
			return array_map( static function ( $item ) {
				return self::resolve( $item );
			}, self::all() );
		}

		/**
		 * Human-readable label from a translation key, with a deterministic
		 * fallback so a missing translation never renders an empty row.
		 *
		 * @param array<string,mixed> $item
		 * @param string              $key
		 * @return string
		 */
		private static function resolve_label( array $item, $key ) {
			$raw = isset( $item[ $key ] ) ? (string) $item[ $key ] : '';
			if ( '' === $raw ) {
				return '';
			}

			$translated = self::translate( $raw );
			if ( '' !== $translated && $translated !== $raw ) {
				return $translated;
			}

			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — ship a real
			// catalog so operators see real strings before any .mo exists. The gettext lookup
			// above still wins, so a translator can override every entry here.
			$catalog = self::default_catalog();
			if ( isset( $catalog[ $raw ] ) && '' !== $catalog[ $raw ] ) {
				return (string) $catalog[ $raw ];
			}

			return self::humanize_key( $raw );
		}

		/**
		 * Derive a readable label from a registration key.
		 *
		 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — keys are
		 * `<scope>.<name>.<label|description>` by contract, so the trailing segment is a type
		 * marker carrying no meaning. The previous fallback took that last segment, which
		 * rendered every unmapped row as the literal word "Label" / "Description" instead of
		 * the owner name.
		 *
		 * @param string $raw
		 * @return string
		 */
		private static function humanize_key( $raw ) {
			$segments = array_values(
				array_filter(
					explode( '.', (string) $raw ),
					static function ( $segment ) {
						return '' !== trim( (string) $segment );
					}
				)
			);
			if ( empty( $segments ) ) {
				return '';
			}

			$type_suffixes = array( 'label', 'description', 'desc', 'title', 'name' );
			if ( count( $segments ) > 1 && in_array( strtolower( (string) end( $segments ) ), $type_suffixes, true ) ) {
				array_pop( $segments );
			}

			$segment = trim( str_replace( array( '_', '-' ), ' ', (string) end( $segments ) ) );
			if ( '' === $segment ) {
				return '';
			}
			return ucwords( $segment );
		}

		/**
		 * Operator-facing strings for every key the built-in owners register.
		 *
		 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — the phase plan
		 * shipped the i18n mechanism with an empty catalog, so every row fell through to the
		 * key-derived fallback. This is that catalog. It is metadata only: no renderer, option
		 * or provider is touched here.
		 *
		 * @return array<string,string>
		 */
		private static function default_catalog(): array {
			$locale = '';
			if ( function_exists( 'determine_locale' ) ) {
				$locale = (string) determine_locale();
			} elseif ( function_exists( 'get_locale' ) ) {
				$locale = (string) get_locale();
			}
			$vi = 0 === strpos( strtolower( $locale ), 'vi' );

			$catalog = array(
				'settings.api_gateway' => array( 'API Gateway', 'Cổng API' ),
				'settings.api_gateway.description' => array( 'Keys, providers and model routing', 'Khóa, nhà cung cấp và định tuyến mô hình' ),
				'settings.master_plan' => array( 'Master Plan', 'Gói Master' ),
				'settings.master_plan.description' => array( 'Current plan, quota and entitlements (read-only)', 'Gói hiện tại, hạn mức và quyền lợi (chỉ đọc)' ),
				'settings.appearance' => array( 'Appearance', 'Giao diện' ),
				'settings.appearance.description' => array( 'Theme, layout and TwinShell chrome', 'Chủ đề, bố cục và khung hiển thị TwinShell' ),
				'settings.user_preferences' => array( 'User Preferences', 'Tùy chọn cá nhân' ),
				'settings.user_preferences.description' => array( 'Language, notifications and per-user defaults', 'Ngôn ngữ, thông báo và mặc định theo người dùng' ),
				'settings.workspace_brain' => array( 'Twin Workspace', 'Không gian Twin' ),
				'settings.workspace_brain.description' => array( 'Brain and daily work', 'Bộ não và công việc hằng ngày' ),
				'settings.channels_zone1' => array( 'Customer channels (Zone 1)', 'Kênh khách hàng (Zone 1)' ),
				'settings.channels_zone1.description' => array( 'Customer-facing conversation channels', 'Kênh hội thoại hướng khách hàng' ),
				'settings.channels_zone2' => array( 'Admin channels (Zone 2)', 'Kênh quản trị (Zone 2)' ),
				'settings.channels_zone2.description' => array( 'Operator-side admin channels', 'Kênh vận hành dành cho quản trị viên' ),
				'settings.crm_inbox' => array( 'CRM Inbox', 'Hộp thư CRM' ),
				'settings.crm_inbox.description' => array( 'Unified customer conversations', 'Hội thoại khách hàng hợp nhất' ),
				'settings.profile' => array( 'Profile', 'Hồ sơ' ),
				'settings.profile.description' => array( 'Profile, sessions and linked accounts', 'Hồ sơ, phiên đăng nhập và tài khoản liên kết' ),
				'settings.diagnostics' => array( 'Diagnostics', 'Chẩn đoán' ),
				'settings.diagnostics.description' => array( 'Health probes and runtime evidence', 'Probe sức khỏe và bằng chứng vận hành' ),
				'settings.zalo_bot' => array( 'Zalo Bot', 'Zalo Bot' ),
				'settings.zalo_bot.description' => array( 'Zalo channel connection and automation', 'Kết nối và tự động hóa kênh Zalo' ),
				'settings.facebook_bot' => array( 'Facebook Bot', 'Facebook Bot' ),
				'settings.facebook_bot.description' => array( 'Page and Messenger connection', 'Kết nối Trang và Messenger' ),
				'settings.pagebuilder' => array( 'PageBuilder Studio', 'PageBuilder Studio' ),
				'settings.pagebuilder.description' => array( 'Page and content builder', 'Trình dựng trang và nội dung' ),
				'reference.settings' => array( 'Reference plugin', 'Plugin tham chiếu' ),
				'reference.settings.description' => array( 'Setting Panel registration example', 'Ví dụ đăng ký Setting Panel' ),
			);

			$out = array();
			foreach ( $catalog as $key => $pair ) {
				$value = $vi && isset( $pair[1] ) && '' !== $pair[1] ? $pair[1] : $pair[0];
				$out[ $key ] = (string) $value;
				// Label keys are registered as `<name>.label`; description keys carry their own
				// `.description` suffix already, so only labels need the alias.
				if ( false === strpos( $key, '.description' ) ) {
					$out[ $key . '.label' ] = (string) $value;
				}
			}
			return $out;
		}

		/**
		 * Translate through the canonical WordPress i18n function.
		 *
		 * @param string $key
		 * @return string
		 */
		private static function translate( $key ) {
			if ( ! function_exists( '__' ) ) {
				return '';
			}
			$domain = defined( 'BIZCITY_TWIN_SETTING_PANEL_DOMAIN' ) ? (string) BIZCITY_TWIN_SETTING_PANEL_DOMAIN : 'bizcity-twin-ai';
			return (string) __( $key, $domain ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain -- domain is a controlled constant.
		}

		/**
		 * Resolve the renderer target into a safe URL for the current operator.
		 *
		 * Registry output is trusted admin data, but it is still escaped at the
		 * boundary: only https external targets survive normalize(), routes are
		 * relative paths and deep links are admin URLs.
		 *
		 * @param array<string,mixed> $item
		 * @return string
		 */
		private static function resolve_url( array $item ) {
			$renderer = isset( $item['renderer'] ) && is_array( $item['renderer'] ) ? $item['renderer'] : array();
			$type     = isset( $renderer['type'] ) ? (string) $renderer['type'] : '';

			if ( 'external' === $type ) {
				$url = isset( $renderer['target_url'] ) ? (string) $renderer['target_url'] : '';
				return self::safe_url( $url );
			}

			if ( in_array( $type, array( 'coreui', 'route' ), true ) ) {
				$route = isset( $renderer['route'] ) ? (string) $renderer['route'] : '';
				return '' === $route ? '' : $route;
			}

			if ( 'deep_link' === $type ) {
				$slug = isset( $renderer['canonical_slug'] ) ? (string) $renderer['canonical_slug'] : '';
				if ( '' === $slug ) {
					return '';
				}
				if ( function_exists( 'admin_url' ) ) {
					return self::safe_url( admin_url( 'admin.php?page=' . rawurlencode( $slug ) ) );
				}
				return 'admin.php?page=' . rawurlencode( $slug );
			}

			return '';
		}

		/**
		 * @param string $url
		 * @return string
		 */
		private static function safe_url( $url ) {
			if ( '' === $url ) {
				return '';
			}
			if ( ! function_exists( 'esc_url_raw' ) ) {
				return $url;
			}
			return (string) esc_url_raw( $url );
		}

		/**
		 * Evaluate `availability` for the current request.
		 *
		 * Returns a display state, never a boolean gate: authorization is still
		 * enforced by the REST permission callback and each owner's own
		 * capability check. States are `available`, `unavailable`,
		 * `incompatible` or `update_required`.
		 *
		 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — closes the availability gap behind G8-09/G8-10.
		 *
		 * @param array<string,mixed> $item
		 * @return string
		 */
		private static function resolve_availability( array $item ) {
			$availability = isset( $item['availability'] ) && is_array( $item['availability'] ) ? $item['availability'] : array();
			$policy       = isset( $availability['policy'] ) ? (string) $availability['policy'] : 'registered-owner';

			// Version floors are checked before dependency presence: a satisfied
			// dependency on an old framework is still a blocked surface.
			if ( ! self::meets_version_floor( isset( $availability['min_framework'] ) ? (string) $availability['min_framework'] : '', defined( 'BIZCITY_TWIN_AI_VERSION' ) ? (string) BIZCITY_TWIN_AI_VERSION : '' ) ) {
				return 'update_required';
			}
			if ( ! self::meets_version_floor( isset( $availability['min_php'] ) ? (string) $availability['min_php'] : '', PHP_VERSION ) ) {
				return 'incompatible';
			}
			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — only read the WordPress version when a floor is actually declared.
			$min_wp = isset( $availability['min_wp'] ) ? (string) $availability['min_wp'] : '';
			if ( '' !== $min_wp && ! self::meets_version_floor( $min_wp, function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '' ) ) {
				return 'update_required';
			}

			if ( 'always' === $policy ) {
				return 'available';
			}

			if ( 'registered-owner' === $policy || 'all' === $policy || 'any' === $policy ) {
				$dependencies = isset( $availability['dependency_ids'] ) && is_array( $availability['dependency_ids'] )
					? $availability['dependency_ids']
					: array();
				if ( empty( $dependencies ) ) {
					return 'available';
				}

				$missing = array();
				foreach ( $dependencies as $dependency_id ) {
					if ( ! self::dependency_present( (string) $dependency_id ) ) {
						$missing[] = (string) $dependency_id;
					}
				}

				if ( 'any' === $policy ) {
					return count( $missing ) < count( $dependencies ) ? 'available' : 'unavailable';
				}
				return empty( $missing ) ? 'available' : 'unavailable';
			}

			return 'available';
		}

		/**
		 * Is one declared dependency present in this request?
		 *
		 * Resolution is intentionally conservative and read-only: it checks the
		 * declared identifier against files already included in this request
		 * and, for plugins, the WordPress activation list. It never activates a
		 * plugin, boots a module or reads an option.
		 *
		 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — resolve by declared owner path, not by a
		 * guessed class name. Bundled plugins may ship a main file whose name
		 * differs from its directory (`bizcity-profile` → `bizcity-personal.php`)
		 * and core packages publish varying class names, so a class-name map
		 * produced false `unavailable` rows.
		 *
		 * @param string $dependency_id
		 * @return bool
		 */
		private static function dependency_present( $dependency_id ) {
			$dependency_id = strtolower( trim( (string) $dependency_id ) );
			if ( '' === $dependency_id ) {
				return false;
			}

			// `plugins.vendor-slug` → plugin directory + WordPress activation state.
			if ( 0 === strpos( $dependency_id, 'plugins.' ) ) {
				return self::plugin_present( substr( $dependency_id, 8 ) );
			}

			// The registry is executing, so its own contract is by definition present.
			if ( 'core.setting-panel.registry' === $dependency_id ) {
				return class_exists( 'BizCity_Setting_Panel_Registry' );
			}

			// `core.x` / `modules.x` → canonical owner directory from the id itself.
			$path = self::owner_path_fragment( $dependency_id );
			if ( '' === $path ) {
				return false;
			}

			if ( function_exists( 'get_included_files' ) ) {
				foreach ( get_included_files() as $file ) {
					if ( false !== strpos( str_replace( '\\', '/', (string) $file ), $path ) ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Derive the canonical owner directory fragment from a dependency id.
		 *
		 * `core.channel-gateway` → `/core/channel-gateway/`
		 * `modules.twinchat`     → `/modules/twinchat/`
		 *
		 * @param string $dependency_id
		 * @return string Empty when the id does not map to a supported owner root.
		 */
		private static function owner_path_fragment( $dependency_id ) {
			if ( ! preg_match( '#^(core|modules)\.([a-z0-9][a-z0-9._-]*)$#', $dependency_id, $matches ) ) {
				return '';
			}
			$root = $matches[1];
			// Only the first segment is a directory; the remainder is a feature.
			$parts = explode( '.', $matches[2] );
			$slug  = preg_replace( '/[^a-z0-9-]/', '', (string) $parts[0] );
			if ( '' === $slug ) {
				return '';
			}
			return '/' . $root . '/' . $slug . '/';
		}

		/**
		 * @param string $slug Plugin directory slug, e.g. `bizcity-twin-crm`.
		 * @return bool
		 */
		private static function plugin_present( $slug ) {
			$slug = trim( (string) $slug );
			if ( '' === $slug || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
				return false;
			}

			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — match the plugin DIRECTORY, not a guessed main
			// file name. Bundled plugins load through `bootstrap.php` and some
			// ship a main file whose name differs from the directory
			// (`bizcity-profile` → `bizcity-personal.php`), so an exact
			// `/slug/slug.php` probe produced false `unavailable` rows.
			$dir_fragment = '/' . $slug . '/';
			if ( function_exists( 'get_included_files' ) ) {
				foreach ( get_included_files() as $file ) {
					$normalized = str_replace( '\\', '/', (string) $file );
					if ( false !== strpos( $normalized, $dir_fragment ) ) {
						return true;
					}
				}
			}

			// Fall back to the standard WordPress activation check when the
			// plugin is active but has not included a file in this request yet.
			if ( function_exists( 'is_plugin_active' ) ) {
				return (bool) is_plugin_active( $slug . '/' . $slug . '.php' );
			}
			if ( function_exists( 'is_plugin_active_for_network' ) ) {
				return (bool) is_plugin_active_for_network( $slug . '/' . $slug . '.php' );
			}

			return false;
		}

		/**
		 * @param string $required Version floor, '' when unset.
		 * @param string $actual   Actual version, '' when unknown.
		 * @return bool
		 */
		private static function meets_version_floor( $required, $actual ) {
			$required = trim( (string) $required );
			if ( '' === $required ) {
				return true;
			}
			$actual = trim( (string) $actual );
			if ( '' === $actual || ! function_exists( 'version_compare' ) ) {
				return false;
			}
			return version_compare( $actual, $required, '>=' );
		}

		/**
		 * Register one native or adapted metadata item.
		 *
		 * @param array<string,mixed> $item
		 * @return bool
		 */
		public static function register_item( array $item ): bool {
			// [2026-09-13 09:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — register metadata without loading renderers or querying runtime state.
			$normalized = self::normalize( $item );
			if ( false === $normalized ) {
				return false;
			}

			$id = $normalized['id'];
			if ( isset( self::$items[ $id ] ) ) {
				self::$errors[] = 'duplicate_id:' . $id;
				return false;
			}

			$renderer_id = $normalized['renderer']['id'];
			if ( isset( self::$renderer_owners[ $renderer_id ] )
				&& 'legacy_adapter' !== $normalized['origin']
				&& 'legacy_adapter' !== self::$renderer_owners[ $renderer_id ]['origin'] ) {
				self::$errors[] = 'renderer_collision:' . $renderer_id;
				return false;
			}

			self::$items[ $id ] = $normalized;
			self::$renderer_owners[ $renderer_id ] = $normalized;
			return true;
		}

		/**
		 * Adapt one existing admin-navigation item without broadening its scope.
		 *
		 * @param array<string,mixed> $item
		 * @param string               $migration_owner
		 * @param string               $sunset_after
		 * @return bool
		 */
		public static function register_legacy_navigation( array $item, $migration_owner, $sunset_after ): bool {
			// [2026-09-13 09:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — adapt legacy navigation as explicit migration debt.
			$destination = self::destination_for_navigation( $item );
			$renderer_id = isset( $item['renderer'] ) ? (string) $item['renderer'] : '';
			if ( '' === $renderer_id ) {
				self::$errors[] = 'legacy_renderer_missing';
				return false;
			}

			$legacy = array(
				'contract'         => self::CONTRACT,
				'version'          => self::VERSION,
				'id'               => 'legacy.' . sanitize_key( (string) $item['id'] ),
				'owner'            => (string) $migration_owner,
				'origin'           => 'legacy_adapter',
				'destination'      => $destination,
				'group'            => isset( $item['slot'] ) ? sanitize_key( (string) $item['slot'] ) : 'legacy',
				'label_key'        => 'legacy.' . sanitize_key( (string) $item['id'] ) . '.label',
				'icon'             => isset( $item['icon'] ) ? (string) $item['icon'] : 'cil-puzzle',
				'capability'       => isset( $item['capability'] ) ? (string) $item['capability'] : 'read',
				'scope'            => isset( $item['scope'] ) ? (string) $item['scope'] : 'site',
				'surface'          => isset( $item['surface'] ) ? (string) $item['surface'] : 'admin_page',
				'renderer'         => array(
					'type'           => 'deep_link',
					'id'             => $renderer_id,
					'canonical_slug' => isset( $item['slug'] ) ? (string) $item['slug'] : '',
				),
				'availability'     => array( 'policy' => 'registered-owner' ),
				'position'         => isset( $item['position'] ) ? (int) $item['position'] : 9000,
				'aliases'          => isset( $item['aliases'] ) && is_array( $item['aliases'] ) ? $item['aliases'] : array(),
				'native_contract'  => false,
				'migration_owner'  => (string) $migration_owner,
				'sunset_after'     => (string) $sunset_after,
			);

			if ( 'channel-settings' === $destination && isset( $item['zone'] ) ) {
				$legacy['zone'] = (string) $item['zone'];
			}
			return self::register_item( $legacy );
		}

		/**
		 * Return normalized entries in deterministic display order.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function all(): array {
			// [2026-09-13 09:32 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — sort only in memory for deterministic registry output.
			$items = array_values( self::$items );
			usort( $items, static function ( $left, $right ) {
				$position_compare = (int) $left['position'] <=> (int) $right['position'];
				return 0 !== $position_compare ? $position_compare : strcmp( $left['id'], $right['id'] );
			} );
			return $items;
		}

		/**
		 * @return array<int,string>
		 */
		public static function errors(): array {
			return self::$errors;
		}

		/**
		 * Capture the full registry state for a diagnostics fixture.
		 *
		 * Diagnostics-only: R-DDV requires a probe that registers temporary
		 * fixtures to roll back before the process ends. No runtime caller may
		 * use this to inject or erase production metadata.
		 *
		 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — allow probe-level rollback.
		 *
		 * @return array<string,array<mixed>>
		 */
		public static function diagnostics_snapshot(): array {
			return array(
				'items'           => self::$items,
				'errors'          => self::$errors,
				'renderer_owners' => self::$renderer_owners,
			);
		}

		/**
		 * Restore a snapshot previously returned by diagnostics_snapshot().
		 *
		 * Only the keys produced by diagnostics_snapshot() are honoured; any
		 * other input is ignored so a malformed argument cannot clear state.
		 *
		 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — roll back probe fixtures exactly.
		 *
		 * @param array<string,mixed> $state
		 * @return void
		 */
		public static function diagnostics_restore( array $state ): void {
			if ( isset( $state['items'] ) && is_array( $state['items'] ) ) {
				self::$items = $state['items'];
			}
			if ( isset( $state['errors'] ) && is_array( $state['errors'] ) ) {
				self::$errors = $state['errors'];
			}
			if ( isset( $state['renderer_owners'] ) && is_array( $state['renderer_owners'] ) ) {
				self::$renderer_owners = $state['renderer_owners'];
			}
		}

		/**
		 * @return array<string,mixed>|null
		 */
		public static function get( $id ) {
			// [2026-09-13 09:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — preserve contract dot-notation IDs during lookup.
			$id = strtolower( trim( (string) $id ) );
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $id ) ) {
				return null;
			}
			return isset( self::$items[ $id ] ) ? self::$items[ $id ] : null;
		}

		/**
		 * @param array<string,mixed> $item
		 * @return array<string,mixed>|false
		 */
		private static function normalize( array $item ) {
			// [2026-09-13 09:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3 — enforce contract enums before admission.
			$required = array( 'id', 'owner', 'origin', 'destination', 'group', 'label_key', 'icon', 'capability', 'scope', 'surface', 'renderer', 'availability', 'position' );
			foreach ( $required as $key ) {
				if ( ! array_key_exists( $key, $item ) ) {
					self::$errors[] = 'missing:' . $key;
					return false;
				}
			}
			if ( isset( $item['contract'] ) && self::CONTRACT !== (string) $item['contract'] ) {
				self::$errors[] = 'contract_invalid';
				return false;
			}
			if ( ! is_string( $item['id'] ) || ! preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $item['id'] ) ) {
				self::$errors[] = 'id_invalid';
				return false;
			}
			if ( ! in_array( (string) $item['origin'], self::$origins, true ) || ! in_array( (string) $item['destination'], self::$destinations, true ) ) {
				self::$errors[] = 'enum_invalid:' . $item['id'];
				return false;
			}
			if ( ! in_array( (string) $item['scope'], self::$scopes, true ) || ! in_array( (string) $item['surface'], self::$surfaces, true ) ) {
				self::$errors[] = 'scope_or_surface_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_int( $item['position'] ) || $item['position'] < 0 || $item['position'] > 9999 ) {
				self::$errors[] = 'position_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_array( $item['renderer'] ) || empty( $item['renderer']['id'] ) || empty( $item['renderer']['type'] ) ) {
				self::$errors[] = 'renderer_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_array( $item['availability'] ) || empty( $item['availability']['policy'] ) ) {
				self::$errors[] = 'availability_invalid:' . $item['id'];
				return false;
			}

			// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-04 — close the enum gaps the schema already enforces.
			$renderer_type = (string) $item['renderer']['type'];
			if ( ! in_array( $renderer_type, self::$renderer_types, true ) ) {
				self::$errors[] = 'renderer_type_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_string( $item['renderer']['id'] ) || ! preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $item['renderer']['id'] ) ) {
				self::$errors[] = 'renderer_id_invalid:' . $item['id'];
				return false;
			}
			if ( ! in_array( (string) $item['availability']['policy'], self::$availability_policies, true ) ) {
				self::$errors[] = 'availability_policy_invalid:' . $item['id'];
				return false;
			}
			if ( ! is_string( $item['capability'] ) || ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){0,4}$/', $item['capability'] ) ) {
				self::$errors[] = 'capability_invalid:' . $item['id'];
				return false;
			}
			if ( isset( $item['zone'] ) && ! in_array( (string) $item['zone'], self::$zones, true ) ) {
				self::$errors[] = 'zone_invalid:' . $item['id'];
				return false;
			}

			// Renderer shape rules from the v1 schema.
			if ( in_array( $renderer_type, array( 'coreui', 'route' ), true )
				&& empty( $item['renderer']['route'] ) ) {
				self::$errors[] = 'renderer_route_missing:' . $item['id'];
				return false;
			}
			if ( 'deep_link' === $renderer_type && empty( $item['renderer']['canonical_slug'] ) ) {
				self::$errors[] = 'renderer_slug_missing:' . $item['id'];
				return false;
			}
			if ( ! empty( $item['renderer']['route'] ) && ! preg_match( '#^/[A-Za-z0-9._/-]*$#', (string) $item['renderer']['route'] ) ) {
				self::$errors[] = 'renderer_route_invalid:' . $item['id'];
				return false;
			}

			if ( 'external' === $renderer_type ) {
				$url = isset( $item['renderer']['target_url'] ) ? (string) $item['renderer']['target_url'] : '';
				if ( 0 !== strpos( strtolower( $url ), 'https://' ) ) {
					self::$errors[] = 'external_url_invalid:' . $item['id'];
					return false;
				}
			}
			if ( 'legacy_adapter' === (string) $item['origin']
				&& ( ! isset( $item['native_contract'] ) || ! isset( $item['migration_owner'] ) || ! isset( $item['sunset_after'] ) ) ) {
				self::$errors[] = 'legacy_metadata_missing:' . $item['id'];
				return false;
			}
			if ( 'channel-settings' === $item['destination'] && empty( $item['zone'] ) ) {
				self::$errors[] = 'channel_zone_missing:' . $item['id'];
				return false;
			}
			return $item;
		}

		private static function destination_for_navigation( array $item ): string {
			$slot = isset( $item['slot'] ) ? (string) $item['slot'] : '';
			if ( 0 === strpos( $slot, 'workspace.channels' ) ) {
				return 'channel-settings';
			}
			if ( 0 === strpos( $slot, 'workspace.crm' ) ) {
				return 'crm-inbox';
			}
			if ( 0 === strpos( $slot, 'workspace.extensions' ) ) {
				return 'plugins-store';
			}
			if ( 0 === strpos( $slot, 'workspace.' ) ) {
				return 'workspace';
			}
			if ( 0 === strpos( $slot, 'diagnostics.' ) ) {
				return 'settings';
			}
			return 'settings';
		}
	}
}
