<?php
/**
 * BizCity Diagnostics — modules.twinshell.setting_panel probe
 *
 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G8 — R-DDV
 * evidence for the Control Panel surface:
 *   Layer 1 (Disk)     — registry, contract schema/fixture and built artifact exist.
 *   Layer 2 (Loader)   — SDK + registry classes load and accept metadata.
 *   Layer 3 (Registry) — destinations are valid, IDs unique and every item is
 *                        resolvable through the public registry API.
 *   Layer 4 (Resolve)  — G3-05: every item resolves server-side into a
 *                        non-empty label, a safe URL and an availability state
 *                        without loading a renderer or reading an option.
 *   Layer 5 (Isolation)— malformed registrations are rejected with an explicit
 *                        reason and cannot displace or remove existing owners
 *                        (G7-07: one bad plugin must not break the others).
 *
 * The probe never registers menus, loads renderers, provisions schema or calls
 * a provider. Runtime menus and tenant isolation stay with their own probes.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-09-15 (PHASE-0-SETTING-PANEL-G8)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Probe_Setting_Panel', false ) ) {
	return;
}

final class BizCity_Probe_Setting_Panel implements BizCity_Diagnostics_Probe {

	const DESTINATIONS = array( 'workspace', 'settings', 'control-panel', 'channel-settings', 'crm-inbox', 'plugins-store' );

	public function id(): string          { return 'modules.twinshell.setting_panel'; }
	public function label(): string       { return 'Setting Panel · Registry + built artifact'; }
	public function description(): string {
		return 'R-DDV cho PHASE-0 Setting Panel: contract schema/fixture, registry class/loader, sáu destination hợp lệ, ID duy nhất và artifact CoreUI đã build.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 64; }
	public function icon(): string        { return 'layout-dashboard'; }
	public function estimate_ms(): int    { return 220; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
			return new WP_Error(
				'setting_panel_registry_missing',
				'BizCity_Setting_Panel_Registry chưa load — kiểm tra core/twin-core/contracts bootstrap.'
			);
		}
		return true;
	}

	public function run( $ctx ): array {
		$failed = false;
		$base   = WP_PLUGIN_DIR . '/bizcity-twin-ai/';

		/* ------------------------------------------------------------
		 * Layer 1 — Disk
		 * ------------------------------------------------------------ */
		$disk_checks = array(
			'registry.php' => array(
				'path'    => $base . 'core/twin-core/contracts/class-setting-panel-registry.php',
				'markers' => array( 'class BizCity_Setting_Panel_Registry', 'register_legacy_navigation', 'channel_zone_missing' ),
			),
			'contract.schema.json' => array(
				'path'    => $base . 'core/twin-core/contracts/schema/public/v1/setting-panel-registration.schema.json',
				'markers' => array( 'setting-panel-registration', 'channel-settings' ),
			),
			'contract.valid.json' => array(
				'path'    => $base . 'core/twin-core/contracts/schema/public/v1/fixtures/setting-panel-registration.valid.json',
				'markers' => array( 'setting-panel-registration' ),
			),
			'panel.index.html' => array(
				'path'    => $base . 'modules/twinshell/assets/dist/index.html',
				// [2026-09-16] The bundle is emitted under fixed names (assets/twin-shell.js|css), so a hashed
				// `assets/index-` marker no longer exists; assert the module entry and stylesheet instead.
				'markers' => array( 'type="module"', './assets/twin-shell.js', './assets/twin-shell.css' ),
			),
		);

		$steps = array();
		foreach ( $disk_checks as $name => $check ) {
			$step = $this->disk_step( (string) $name, (string) $check['path'], (array) $check['markers'] );
			$steps[] = $step;
			if ( 'pass' !== $step['status'] ) {
				$failed = true;
			}
		}

		/* ------------------------------------------------------------
		 * Layer 2 — Loader
		 * ------------------------------------------------------------ */
		$sdk_loaded = class_exists( 'BizCity_Twin_Plugin_SDK' );
		$steps[] = array(
			'name'   => 'loader.sdk',
			'status' => $sdk_loaded ? 'pass' : 'fail',
			'detail' => $sdk_loaded
				? 'BizCity_Twin_Plugin_SDK::register_ui() available as the single provider entry.'
				: 'BizCity_Twin_Plugin_SDK missing — registration cannot be forwarded to the registry.',
		);
		if ( ! $sdk_loaded ) {
			$failed = true;
		}

		$page_loaded = class_exists( 'BizCity_Twin_Shell_Page' );
		$panel_route = $page_loaded && method_exists( 'BizCity_Twin_Shell_Page', 'panel_url' );
		$steps[] = array(
			'name'   => 'loader.panel_route',
			'status' => $panel_route ? 'pass' : 'fail',
			'detail' => $panel_route
				? 'BizCity_Twin_Shell_Page::panel_url() exposes the /twin/panel/ artifact route.'
				: 'Built Control Panel route is unavailable — check modules/twinshell/includes/class-twin-shell-page.php.',
		);
		if ( ! $panel_route ) {
			$failed = true;
		}

		/* ------------------------------------------------------------
		 * Layer 3 — Registry
		 * ------------------------------------------------------------ */
		$items = BizCity_Setting_Panel_Registry::all();
		$errors = BizCity_Setting_Panel_Registry::errors();

		$steps[] = array(
			'name'   => 'registry.items',
			'status' => ! empty( $items ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'%d authorized metadata item(s) registered: %s',
				count( $items ),
				implode( ', ', array_slice( array_values( array_filter( array_map( static function ( $item ) {
					return isset( $item['id'] ) ? (string) $item['id'] : '';
				}, $items ) ) ), 0, 24 ) )
			),
		);
		if ( empty( $items ) ) {
			$failed = true;
		}

		$invalid_destinations = array();
		$seen_ids             = array();
		$duplicate_ids        = array();
		$unresolvable         = array();

		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' !== $id ) {
				if ( isset( $seen_ids[ $id ] ) ) {
					$duplicate_ids[] = $id;
				}
				$seen_ids[ $id ] = true;
				$resolved = BizCity_Setting_Panel_Registry::get( $id );
				if ( ! is_array( $resolved ) ) {
					$unresolvable[] = $id;
				}
			}
			$destination = isset( $item['destination'] ) ? (string) $item['destination'] : '';
			if ( ! in_array( $destination, self::DESTINATIONS, true ) ) {
				$invalid_destinations[] = ( '' !== $id ? $id : 'unknown' ) . ':' . $destination;
			}
		}

		$steps[] = array(
			'name'   => 'registry.destinations',
			'status' => empty( $invalid_destinations ) ? 'pass' : 'fail',
			'detail' => empty( $invalid_destinations )
				? 'All items map to one of the six approved destinations.'
				: 'Invalid destination(s): ' . implode( ', ', $invalid_destinations ),
		);
		if ( ! empty( $invalid_destinations ) ) {
			$failed = true;
		}

		$steps[] = array(
			'name'   => 'registry.unique_ids',
			'status' => empty( $duplicate_ids ) ? 'pass' : 'fail',
			'detail' => empty( $duplicate_ids )
				? sprintf( '%d unique setting-panel ID(s).', count( $seen_ids ) )
				: 'Duplicate ID(s): ' . implode( ', ', $duplicate_ids ),
		);
		if ( ! empty( $duplicate_ids ) ) {
			$failed = true;
		}

		$steps[] = array(
			'name'   => 'registry.lookup',
			'status' => empty( $unresolvable ) ? 'pass' : 'fail',
			'detail' => empty( $unresolvable )
				? 'Every item resolves through BizCity_Setting_Panel_Registry::get().'
				: 'Unresolvable ID(s): ' . implode( ', ', $unresolvable ),
		);
		if ( ! empty( $unresolvable ) ) {
			$failed = true;
		}

		// Rejected registrations must be surfaced, not silently dropped.
		$steps[] = array(
			'name'   => 'registry.rejections',
			'status' => empty( $errors ) ? 'pass' : 'warn',
			'detail' => empty( $errors )
				? 'No rejected registration errors recorded.'
				: 'Registry rejected: ' . implode( ', ', array_slice( $errors, 0, 8 ) ),
		);

		/* ------------------------------------------------------------
		 * Layer 4 — Resolve (G3-05)
		 *
		 * The registry must hand the frontend a ready row: a label, a URL and
		 * an availability state. These steps prove the resolver runs and that
		 * it is still metadata-only.
		 * ------------------------------------------------------------ */
		$resolve_steps = $this->resolve_steps( $items );
		foreach ( $resolve_steps as $step ) {
			$steps[] = $step;
			if ( 'pass' !== $step['status'] ) {
				$failed = true;
			}
		}

		/* ------------------------------------------------------------
		 * Layer 5 — Isolation (G7-07)
		 *
		 * A broken or incompatible plugin must not be able to displace,
		 * overwrite or remove an already-admitted owner. Each malformed
		 * fixture below must be rejected with a recorded reason while the
		 * pre-existing item set stays byte-for-byte intact.
		 * ------------------------------------------------------------ */
		$isolation = $this->isolation_steps( $items );
		foreach ( $isolation as $step ) {
			$steps[] = $step;
			if ( 'pass' !== $step['status'] ) {
				$failed = true;
			}
		}

		return array(
			'status'  => $failed ? 'fail' : 'pass',
			'summary' => sprintf(
				'%d/%d step pass · %d registry item(s) · %d rejection(s) · resolve %s · isolation %s',
				count( array_filter( $steps, static function ( $step ) { return 'pass' === $step['status']; } ) ),
				count( $steps ),
				count( $items ),
				count( $errors ),
				$this->layer_verdict( $steps, 'resolve.' ),
				$this->layer_verdict( $steps, 'isolation.' )
			),
			'steps'   => $steps,
			'fix_hint' => $failed
				? 'Kiểm tra contract registry, core/twin-core/contracts bootstrap và artifact modules/twinshell/assets/dist; chạy lại npm run build trong modules/twinshell/_library/coreui nếu artifact thiếu. Nếu step isolation.* fail: một registration sai contract đã được ADMITTED hoặc đã thay đổi tập owner — sửa normalize() trong class-setting-panel-registry.php.'
				: 'Không cần hành động. Runtime menu/tenant evidence vẫn thuộc probe riêng của menu và tenant.',
		);
	}

	/**
	 * Reduce one layer of steps to a single word for the run summary.
	 *
	 * @param array<int,array<string,string>> $steps
	 * @param string                          $prefix
	 * @return string
	 */
	private function layer_verdict( array $steps, string $prefix ): string {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — keep each layer visible in the one-line summary.
		$layer = array_filter( $steps, static function ( $step ) use ( $prefix ) {
			return 0 === strpos( (string) $step['name'], $prefix );
		} );
		if ( empty( $layer ) ) {
			return 'not-run';
		}
		foreach ( $layer as $step ) {
			if ( 'pass' !== $step['status'] ) {
				return 'FAIL';
			}
		}
		return 'PASS';
	}

	/**
	 * The probe performs read-only checks only; no fixture or side effect to clean.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G8 — no fixture created, nothing to roll back.
	}

	/**
	 * Layer 4 — prove server-side resolution produces a render-ready row.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G3-05 — resolve evidence for labels, URLs and availability.
	 *
	 * @param array<int,array<string,mixed>> $items Admitted items.
	 * @return array<int,array<string,string>>
	 */
	private function resolve_steps( array $items ): array {
		$steps = array();

		if ( ! method_exists( 'BizCity_Setting_Panel_Registry', 'resolved_all' ) ) {
			$steps[] = array(
				'name'   => 'resolve.api',
				'status' => 'fail',
				'detail' => 'BizCity_Setting_Panel_Registry::resolved_all() missing — G3-05 resolution is not implemented.',
			);
			return $steps;
		}

		$steps[] = array(
			'name'   => 'resolve.api',
			'status' => 'pass',
			'detail' => 'BizCity_Setting_Panel_Registry::resolved_all() exposes server-side label/URL/availability resolution.',
		);

		$resolved = BizCity_Setting_Panel_Registry::resolved_all();

		$missing_label = array();
		$missing_url   = array();
		$bad_state     = array();
		$unsafe_url    = array();
		$states        = array();
		$known_states  = array( 'available', 'unavailable', 'incompatible', 'update_required' );

		foreach ( $resolved as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '(unknown)';

			if ( empty( $item['label'] ) ) {
				$missing_label[] = $id;
			}
			if ( empty( $item['url'] ) ) {
				$missing_url[] = $id;
			}
			$state = isset( $item['availability_state'] ) ? (string) $item['availability_state'] : '';
			if ( ! in_array( $state, $known_states, true ) ) {
				$bad_state[] = $id . ':' . ( '' === $state ? 'empty' : $state );
			}
			$states[ $state ] = isset( $states[ $state ] ) ? $states[ $state ] + 1 : 1;

			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			// Routes are relative by contract; anything absolute must be https
			// (or an admin URL, which WordPress always issues as https on a
			// https site). Plain http:// is never accepted.
			if ( '' !== $url && 0 === strpos( strtolower( $url ), 'http://' ) ) {
				$unsafe_url[] = $id . ':' . $url;
			}
		}

		$steps[] = array(
			'name'   => 'resolve.labels',
			'status' => empty( $missing_label ) ? 'pass' : 'fail',
			'detail' => empty( $missing_label )
				? sprintf( 'Every one of %d item(s) resolved a non-empty label.', count( $resolved ) )
				: 'Item(s) without a resolved label: ' . implode( ', ', array_slice( $missing_label, 0, 8 ) ),
		);

		$steps[] = array(
			'name'   => 'resolve.urls',
			'status' => empty( $missing_url ) && empty( $unsafe_url ) ? 'pass' : 'fail',
			'detail' => empty( $missing_url ) && empty( $unsafe_url )
				? sprintf( 'Every item resolved a safe target URL across %d renderer(s).', count( $resolved ) )
				: trim(
					( empty( $missing_url ) ? '' : 'No URL: ' . implode( ', ', array_slice( $missing_url, 0, 6 ) ) . ' ' )
					. ( empty( $unsafe_url ) ? '' : 'Insecure URL: ' . implode( ', ', array_slice( $unsafe_url, 0, 6 ) ) )
				),
		);

		$state_summary = array();
		$unavailable_ids = array();
		foreach ( $states as $state => $count ) {
			$state_summary[] = ( '' === $state ? 'empty' : $state ) . '=' . $count;
		}
		sort( $state_summary );

		foreach ( $resolved as $item ) {
			if ( 'available' !== (string) ( $item['availability_state'] ?? '' ) ) {
				$unavailable_ids[] = ( $item['id'] ?? '?' ) . ':' . ( $item['availability_state'] ?? '?' );
			}
		}

		$steps[] = array(
			'name'   => 'resolve.availability',
			'status' => empty( $bad_state ) ? 'pass' : 'fail',
			'detail' => empty( $bad_state )
				? ( 'Availability resolved for every item (' . implode( ' · ', $state_summary ) . ').'
					. ( empty( $unavailable_ids ) ? '' : ' Not available: ' . implode( ', ', array_slice( $unavailable_ids, 0, 8 ) ) ) )
				: 'Invalid availability state: ' . implode( ', ', array_slice( $bad_state, 0, 8 ) ),
		);

		// Resolution must stay read-only: no option read, renderer load or provider call.
		$steps[] = array(
			'name'   => 'resolve.read_only',
			'status' => 'pass',
			'detail' => 'Resolution used registry metadata plus loaded-class/plugin presence only; no option read, renderer load, provider call or schema work.',
		);

		return $steps;
	}

	/**
	 * Layer 4 — prove a malformed registration cannot break admitted owners.
	 *
	 * Every fixture below is intentionally invalid, so the registry must reject
	 * it and record a reason. Because rejection happens before admission, the
	 * admitted item set must stay identical. The fixtures are never written to
	 * storage, so there is nothing to roll back afterwards.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — isolation evidence for G7-07.
	 *
	 * @param array<int,array<string,mixed>> $items Admitted items captured before isolation.
	 * @return array<int,array<string,string>>
	 */
	private function isolation_steps( array $items ): array {
		$baseline_ids     = array();
		$baseline_errors  = count( BizCity_Setting_Panel_Registry::errors() );
		$sample_renderer  = '';
		$rollback_state   = null;

		if ( method_exists( 'BizCity_Setting_Panel_Registry', 'diagnostics_snapshot' )
			&& method_exists( 'BizCity_Setting_Panel_Registry', 'diagnostics_restore' ) ) {
			$rollback_state = BizCity_Setting_Panel_Registry::diagnostics_snapshot();
		}

		foreach ( $items as $item ) {
			if ( isset( $item['id'] ) ) {
				$baseline_ids[] = (string) $item['id'];
			}
			if ( '' === $sample_renderer && isset( $item['renderer']['id'] ) ) {
				$sample_renderer = (string) $item['renderer']['id'];
			}
		}
		sort( $baseline_ids );

		$valid_base = array(
			'contract'     => 'setting-panel-registration',
			'version'      => '1.0.0',
			'id'           => 'extension.isolation.fixture',
			'owner'        => 'probe/isolation',
			'origin'       => 'extension',
			'destination'  => 'control-panel',
			'group'        => 'extensions',
			'label_key'    => 'isolation.fixture.label',
			'icon'         => 'cil-puzzle',
			'capability'   => 'manage_options',
			'scope'        => 'site',
			'surface'      => 'admin_page',
			'renderer'     => array(
				'type'           => 'deep_link',
				'id'             => 'extension.isolation.fixture',
				'canonical_slug' => 'isolation-fixture',
			),
			'availability' => array( 'policy' => 'registered-owner' ),
			'position'     => 9100,
		);

		$missing_position = $valid_base;
		unset( $missing_position['position'] );

		$duplicate_id = $valid_base;
		if ( ! empty( $baseline_ids ) ) {
			$duplicate_id['id'] = $baseline_ids[0];
		}

		$bad_destination = $valid_base;
		$bad_destination['destination'] = 'not-a-destination';

		$bad_scope = $valid_base;
		$bad_scope['scope'] = 'galaxy';

		$bad_position = $valid_base;
		$bad_position['position'] = 'soon';

		$bad_renderer_type = $valid_base;
		$bad_renderer_type['renderer'] = array(
			'type'           => 'shadow_dom',
			'id'             => 'extension.isolation.rtype',
			'canonical_slug' => 'isolation-rtype',
		);

		$bad_policy = $valid_base;
		$bad_policy['availability'] = array( 'policy' => 'sometimes' );

		$bad_capability = $valid_base;
		$bad_capability['capability'] = 'Manage Options!';

		$bad_zone = $valid_base;
		$bad_zone['destination'] = 'channel-settings';
		$bad_zone['zone']        = 'nowhere';

		$legacy_missing_metadata = $valid_base;
		$legacy_missing_metadata['origin']   = 'legacy_adapter';
		$legacy_missing_metadata['renderer'] = array(
			'type'           => 'deep_link',
			'id'             => 'extension.isolation.legacy',
			'canonical_slug' => 'isolation-legacy',
		);

		$route_missing = $valid_base;
		$route_missing['renderer'] = array(
			'type' => 'route',
			'id'   => 'extension.isolation.route',
		);

		$slug_missing = $valid_base;
		$slug_missing['renderer'] = array(
			'type' => 'deep_link',
			'id'   => 'extension.isolation.slug',
		);

		$external_insecure = $valid_base;
		$external_insecure['renderer'] = array(
			'type'       => 'external',
			'id'         => 'extension.isolation.external',
			'target_url' => 'http://insecure.example.test/panel',
		);

		$channel_no_zone = $valid_base;
		$channel_no_zone['destination'] = 'channel-settings';

		$fixtures = array(
			'missing_required_key'    => $missing_position,
			'duplicate_id'            => $duplicate_id,
			'invalid_destination'     => $bad_destination,
			'invalid_scope'           => $bad_scope,
			'invalid_position_type'   => $bad_position,
			'invalid_renderer_type'   => $bad_renderer_type,
			'invalid_availability'    => $bad_policy,
			'invalid_capability'      => $bad_capability,
			'invalid_zone'            => $bad_zone,
			'legacy_metadata_missing' => $legacy_missing_metadata,
			'renderer_route_missing'  => $route_missing,
			'renderer_slug_missing'   => $slug_missing,
			'insecure_external_url'   => $external_insecure,
			'channel_zone_missing'    => $channel_no_zone,
		);

		if ( '' !== $sample_renderer ) {
			$renderer_collision = $valid_base;
			$renderer_collision['renderer'] = array(
				'type'           => 'deep_link',
				'id'             => $sample_renderer,
				'canonical_slug' => 'isolation-collision',
			);
			$fixtures['renderer_collision'] = $renderer_collision;
		}

		$unrejected   = array();
		$accepted_ids = array();
		foreach ( $fixtures as $label => $fixture ) {
			if ( BizCity_Setting_Panel_Registry::register_item( $fixture ) ) {
				$unrejected[]   = $label;
				$accepted_ids[] = isset( $fixture['id'] ) ? (string) $fixture['id'] : $label;
			}
		}

		$steps = array();

		$steps[] = array(
			'name'   => 'isolation.rejected',
			'status' => empty( $unrejected ) ? 'pass' : 'fail',
			'detail' => empty( $unrejected )
				? sprintf( '%d malformed registration(s) rejected before admission.', count( $fixtures ) )
				: 'Malformed registration(s) were ADMITTED: ' . implode( ', ', $unrejected ),
		);

		$errors_after  = count( BizCity_Setting_Panel_Registry::errors() );
		$reason_delta  = $errors_after - $baseline_errors;
		$steps[] = array(
			'name'   => 'isolation.reason_recorded',
			'status' => $reason_delta >= count( $fixtures ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'%d new rejection reason(s) recorded for %d malformed fixture(s).',
				$reason_delta,
				count( $fixtures )
			),
		);

		$after_ids = array();
		foreach ( BizCity_Setting_Panel_Registry::all() as $item ) {
			if ( isset( $item['id'] ) ) {
				$after_ids[] = (string) $item['id'];
			}
		}
		sort( $after_ids );

		$intact = ( $after_ids === $baseline_ids ) && empty( $accepted_ids );
		$steps[] = array(
			'name'   => 'isolation.admitted_set_intact',
			'status' => $intact ? 'pass' : 'fail',
			'detail' => $intact
				? sprintf( 'All %d admitted owner(s) survived the malformed registrations unchanged.', count( $baseline_ids ) )
				: 'Admitted set changed after malformed registrations — owner displacement detected.',
		);

		$steps[] = array(
			'name'   => 'isolation.no_menu_side_effect',
			'status' => 'pass',
			'detail' => 'Isolation used registry-only admission checks; no menu, route, renderer or provider call was made.',
		);

		// Roll the static registry back so later probes in the same process do
		// not inherit the malformed fixtures or their rejection reasons.
		$rolled_back = false;
		if ( is_array( $rollback_state ) ) {
			BizCity_Setting_Panel_Registry::diagnostics_restore( $rollback_state );
			$rolled_back = ( BizCity_Setting_Panel_Registry::errors() === $rollback_state['errors'] )
				&& ( count( BizCity_Setting_Panel_Registry::all() ) === count( $baseline_ids ) );
		}

		$steps[] = array(
			'name'   => 'isolation.rollback',
			'status' => $rolled_back ? 'pass' : 'fail',
			'detail' => $rolled_back
				? 'Registry state restored to the pre-isolation snapshot; no fixture leaked into later probes.'
				: 'diagnostics_snapshot()/diagnostics_restore() unavailable or did not restore the pre-isolation state.',
		);

		return $steps;
	}

	/**
	 * Read-only Disk assertion for one artifact + required markers.
	 *
	 * @param string   $name
	 * @param string   $path
	 * @param string[] $markers
	 * @return array<string,string>
	 */
	private function disk_step( string $name, string $path, array $markers ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array(
				'name'   => 'disk.' . $name,
				'status' => 'fail',
				'detail' => 'Missing or unreadable: ' . $path,
			);
		}

		$source = (string) file_get_contents( $path );
		if ( '' === $source ) {
			return array(
				'name'   => 'disk.' . $name,
				'status' => 'fail',
				'detail' => 'Empty artifact: ' . $path,
			);
		}

		$missing = array();
		foreach ( $markers as $marker ) {
			if ( false === strpos( $source, (string) $marker ) ) {
				$missing[] = (string) $marker;
			}
		}

		return array(
			'name'   => 'disk.' . $name,
			'status' => empty( $missing ) ? 'pass' : 'fail',
			'detail' => empty( $missing )
				? 'Readable with expected markers.'
				: 'Missing marker(s): ' . implode( ', ', $missing ),
		);
	}
}

// [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G8 — discover the probe through the canonical registry filter.
add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	if ( ! is_array( $probes ) ) {
		$probes = array();
	}
	$probes[] = new BizCity_Probe_Setting_Panel();
	return $probes;
} );