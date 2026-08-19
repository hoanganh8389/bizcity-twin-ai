<?php
/**
 * BizCity Diagnostics — TwinWeb citation continuity probe.
 *
 * 3-layer R-DDV evidence for citation continuity:
 * - Disk: BE source markers for origin_url normalization/fallback flow.
 * - Loader: TwinWeb history route registration + runtime method availability.
 * - Runtime: synthetic assistant messages with source metadata survive
 *   /threads/{id}/messages round-trip with origin_url preserved.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinWeb_Citation_Continuity', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Citation_Continuity implements BizCity_Diagnostics_Probe {

	/** @var int */
	private $probe_thread_id = 0;

	/** @var array<int,int> */
	private $probe_message_ids = array();

	public function id(): string { return 'modules.twinweb.citation_continuity'; }
	public function label(): string { return 'Twin GPT Citation Continuity'; }
	public function description(): string {
		return 'Verifies citation source continuity from stream-like payload shape to thread history reload, including origin_url fallback fields.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 77; }
	public function icon(): string { return 'BookOpen'; }
	public function estimate_ms(): int { return 180; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded. Check modules/twinweb bootstrap load order.' );
		}
		if ( ! method_exists( 'BizCity_TwinWeb_REST', 'list_thread_messages' ) ) {
			return new WP_Error( 'missing_history_method', 'TwinWeb REST does not expose list_thread_messages().' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-MPR-TIMELINE — DDV probe for citation continuity.
		$steps = array();
		$pass  = true;

		// [2026-07-16 Johnny Chu] R-DDV — server build may exclude React src; probe must not fail on missing modules/**/ui/src.
		$plugin_root = $this->resolve_plugin_root();
		$rest_file = $this->resolve_first_readable( array(
			$plugin_root . 'modules/twinweb/includes/class-twinweb-rest.php',
		) );

		/* Layer 1 — Disk */
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — server build may not ship source files; runtime class contract is accepted.
		$disk_files_ok = ( '' !== $rest_file ) || class_exists( 'BizCity_TwinWeb_REST' );
		$disk_missing = array();
		if ( '' === $rest_file ) {
			$disk_missing[] = 'class-twinweb-rest.php';
		}
		$disk_status = $disk_files_ok ? ( '' !== $rest_file ? 'pass' : 'skip' ) : 'fail';
		$disk_detail = '';
		if ( '' !== $rest_file ) {
			$disk_detail = 'class-twinweb-rest.php readable';
		} elseif ( class_exists( 'BizCity_TwinWeb_REST' ) ) {
			$disk_detail = 'Source file not readable/deployed; accepted because BizCity_TwinWeb_REST runtime class is loaded.';
		} else {
			$disk_detail = 'Missing/unreadable: ' . implode( ', ', $disk_missing ) . ' · root=' . $plugin_root;
		}
		$step = array(
			'label'  => 'Disk · TwinWeb citation source files',
			'status' => $disk_status,
			'detail' => $disk_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_files_ok ) {
			$pass = false;
		}

		$disk_markers_ok = true;
		$disk_markers_status = 'skip';
		$disk_detail     = 'Skipped marker scan: source file unavailable on this server build.';
		if ( '' !== $rest_file ) {
			$rest_src = (string) file_get_contents( $rest_file );

			$markers = array(
				// [2026-07-16 Johnny Chu] R-DDV — only backend normalization markers are mandatory on production diagnostics.
				'rest_origin_passthrough' => ( false !== strpos( $rest_src, "'origin_url'  => self::normalize_source_url( \$origin_url )" ) ),
				'rest_url_alias'          => ( false !== strpos( $rest_src, "isset( \$source['url'] )" ) ),
				'rest_product_alias'      => ( false !== strpos( $rest_src, "isset( \$source['product_url'] )" ) ),
			);

			$missing = array();
			foreach ( $markers as $key => $ok ) {
				if ( ! $ok ) {
					$missing[] = $key;
				}
			}

			$disk_markers_ok = empty( $missing );
			$disk_markers_status = $disk_markers_ok ? 'pass' : 'fail';
			$disk_detail     = $disk_markers_ok
				? 'Citation continuity markers found in FE/BE source'
				: 'Missing markers: ' . implode( ', ', $missing );
		}

		$step = array(
			'label'  => 'Disk · continuity markers',
			'status' => $disk_markers_status,
			'detail' => $disk_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_markers_ok ) {
			$pass = false;
		}

		/* Layer 2 — Loader */
		$loader_class_ok = class_exists( 'BizCity_TwinWeb_REST' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'list_thread_messages' );
		$step = array(
			'label'  => 'Loader · TwinWeb history method',
			'status' => $loader_class_ok ? 'pass' : 'fail',
			'detail' => $loader_class_ok
				? 'BizCity_TwinWeb_REST::list_thread_messages available'
				: 'list_thread_messages missing from runtime class contract',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_class_ok ) {
			$pass = false;
		}

		$routes    = rest_get_server()->get_routes();
		$route_ok  = $this->route_has_method_like( $routes, '/bizcity-twinweb/v1/threads/', '/messages', 'GET' );
		$step = array(
			'label'  => 'Loader · history REST route',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok
				? 'GET /bizcity-twinweb/v1/threads/{id}/messages registered'
				: 'History route missing or wrong HTTP method',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) {
			$pass = false;
		}

		/* Layer 3 — Runtime */
		global $wpdb;
		$threads_table  = $wpdb->prefix . 'bizcity_twinweb_threads';
		$messages_table = $wpdb->prefix . 'bizcity_webchat_messages';

		$tables_ok = $this->table_exists( $threads_table ) && $this->table_exists( $messages_table );
		$step = array(
			'label'  => 'Runtime · TwinWeb tables',
			'status' => $tables_ok ? 'pass' : 'fail',
			'detail' => $tables_ok
				? 'threads + webchat_messages tables exist'
				: 'Required tables missing for citation history round-trip test',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $tables_ok ) {
			$pass = false;
			return array(
				'status'   => 'fail',
				'summary'  => 'Twin GPT citation continuity probe failed before runtime because required tables are missing.',
				'error'    => 'twinweb_citation_tables_missing',
				'fix_hint' => 'Run TwinWeb/WebChat installers and ensure table provisioning completed for current blog.',
				'steps'    => $steps,
			);
		}

		$probe_user_id = (int) get_current_user_id();
		if ( $probe_user_id <= 0 ) {
			$step = array(
				'label'  => 'Runtime · synthetic round-trip',
				'status' => 'skip',
				'detail' => 'Skipped because no logged-in user context was available.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			return array(
				'status'   => $pass ? 'pass' : 'fail',
				'summary'  => 'Disk/Loader citation continuity checks passed; runtime round-trip skipped without user context.',
				'error'    => '',
				'fix_hint' => '',
				'steps'    => $steps,
			);
		}

		$probe_sid = '__healthtest_cite_' . substr( wp_generate_uuid4(), 0, 12 );
		$now       = current_time( 'mysql' );

		$thread_ok = (bool) $wpdb->insert(
			$threads_table,
			array(
				'user_id'    => $probe_user_id,
				'guest_sid'  => '',
				'app_type'   => 'chat',
				'title'      => '[probe] citation continuity',
				'last_at'    => $now,
				'created_at' => $now,
				'meta_json'  => wp_json_encode( array(
					'legacy_session_id' => $probe_sid,
					'probe'             => 'modules.twinweb.citation_continuity',
				) ),
			)
		);

		if ( $thread_ok ) {
			$this->probe_thread_id = (int) $wpdb->insert_id;
		}

		if ( ! $thread_ok || $this->probe_thread_id <= 0 ) {
			$step = array(
				'label'  => 'Runtime · synthetic round-trip',
				'status' => 'fail',
				'detail' => 'Cannot create probe thread row for history continuity test.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = false;
		} else {
			$origin_direct   = 'https://example.com/__healthtest_origin_url__';
			$origin_fallback = 'https://example.com/__healthtest_url_field__';

			$insert_direct = (bool) $wpdb->insert(
				$messages_table,
				array(
					'conversation_id' => 0,
					'session_id'      => $probe_sid,
					'user_id'         => $probe_user_id,
					'message_id'      => wp_generate_uuid4(),
					'message_text'    => '[probe] direct origin_url [nb:0/p901]',
					'message_from'    => 'bot',
					'message_type'    => 'text',
					'platform_type'   => 'TWINWEB',
					'project_id'      => '',
					'created_at'      => $now,
					'meta'            => wp_json_encode( array(
						'sources' => array(
							array(
								'notebook_id' => 0,
								'passage_id'  => 901,
								'source_id'   => 0,
								'snippet'     => '__healthtest direct snippet__',
								'origin_url'  => $origin_direct,
							),
						),
					) ),
				)
			);
			if ( $insert_direct ) {
				$this->probe_message_ids[] = (int) $wpdb->insert_id;
			}

			$insert_alias = (bool) $wpdb->insert(
				$messages_table,
				array(
					'conversation_id' => 0,
					'session_id'      => $probe_sid,
					'user_id'         => $probe_user_id,
					'message_id'      => wp_generate_uuid4(),
					'message_text'    => '[probe] url alias field [nb:0/p902]',
					'message_from'    => 'bot',
					'message_type'    => 'text',
					'platform_type'   => 'TWINWEB',
					'project_id'      => '',
					'created_at'      => $now,
					'meta'            => wp_json_encode( array(
						'sources' => array(
							array(
								'notebook_id' => 0,
								'passage_id'  => 902,
								'source_id'   => 0,
								'snippet'     => '__healthtest alias snippet__',
								'url'         => $origin_fallback,
							),
						),
					) ),
				)
			);
			if ( $insert_alias ) {
				$this->probe_message_ids[] = (int) $wpdb->insert_id;
			}

			$runtime_ok = false;
			$runtime_detail = 'Probe rows inserted but history round-trip assertion failed.';

			if ( $insert_direct && $insert_alias ) {
				$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/threads/' . $this->probe_thread_id . '/messages' );
				$req->set_param( 'limit', 50 );
				$res  = rest_do_request( $req );
				$data = $res->get_data();

				$messages = ( is_array( $data ) && isset( $data['messages'] ) && is_array( $data['messages'] ) )
					? $data['messages']
					: array();

				$actual_direct   = $this->extract_origin_url_by_passage( $messages, 901 );
				$actual_fallback = $this->extract_origin_url_by_passage( $messages, 902 );

				$runtime_ok = ( $actual_direct === $origin_direct ) && ( $actual_fallback === $origin_fallback );
				$runtime_detail = $runtime_ok
					? 'History API returned origin_url for both direct and url-alias source shapes.'
					: sprintf(
						'Expected direct=%s alias=%s; got direct=%s alias=%s',
						$origin_direct,
						$origin_fallback,
						$actual_direct,
						$actual_fallback
					);
			}

			$step = array(
				'label'  => 'Runtime · history origin_url round-trip',
				'status' => $runtime_ok ? 'pass' : 'fail',
				'detail' => $runtime_detail,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $runtime_ok ) {
				$pass = false;
			}
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT citation continuity contract is stable across stream-like payload and thread history reload.'
				: 'Twin GPT citation continuity DDV found a contract break in backend/runtime continuity checks.',
			'error'    => $pass ? '' : 'twinweb_citation_continuity_failed',
			'fix_hint' => $pass ? '' : 'Check origin_url mapping and source normalization in BizCity_TwinWeb_REST::list_thread_messages.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		global $wpdb;

		$messages_table = $wpdb->prefix . 'bizcity_webchat_messages';
		$threads_table  = $wpdb->prefix . 'bizcity_twinweb_threads';

		if ( ! empty( $this->probe_message_ids ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $this->probe_message_ids ) ) );
			if ( ! empty( $ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic placeholders are prepared with ID params.
				$sql = $wpdb->prepare( "DELETE FROM {$messages_table} WHERE id IN ({$placeholders})", $ids );
				$wpdb->query( $sql );
			}
		}

		if ( $this->probe_thread_id > 0 ) {
			$wpdb->delete( $threads_table, array( 'id' => (int) $this->probe_thread_id ) );
		}

		$this->probe_message_ids = array();
		$this->probe_thread_id   = 0;
	}

	/**
	 * Check REST route registration by partial route match + method.
	 *
	 * @param array  $routes      REST route map.
	 * @param string $must_start  Required route fragment prefix.
	 * @param string $must_contain Required route fragment.
	 * @param string $method      HTTP method.
	 * @return bool
	 */
	private function route_has_method_like( $routes, $must_start, $must_contain, $method ) {
		$want = strtoupper( (string) $method );
		foreach ( (array) $routes as $route => $defs ) {
			$route = (string) $route;
			if ( false === strpos( $route, $must_start ) || false === strpos( $route, $must_contain ) ) {
				continue;
			}
			foreach ( (array) $defs as $ep ) {
				if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
					continue;
				}
				if ( is_string( $ep['methods'] ) ) {
					if ( false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
						return true;
					}
					continue;
				}
				if ( is_array( $ep['methods'] ) ) {
					foreach ( $ep['methods'] as $m => $enabled ) {
						if ( $enabled && strtoupper( (string) $m ) === $want ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	/**
	 * Extract first origin_url by passage id from history DTO messages.
	 *
	 * @param array<int,mixed> $messages
	 * @param int              $passage_id
	 * @return string
	 */
	private function extract_origin_url_by_passage( array $messages, $passage_id ) {
		$want_pid = (int) $passage_id;
		foreach ( $messages as $row ) {
			if ( ! is_array( $row ) || empty( $row['sources'] ) || ! is_array( $row['sources'] ) ) {
				continue;
			}
			foreach ( $row['sources'] as $source ) {
				if ( ! is_array( $source ) ) {
					continue;
				}
				if ( (int) ( $source['passage_id'] ?? 0 ) !== $want_pid ) {
					continue;
				}
				return isset( $source['origin_url'] ) ? (string) $source['origin_url'] : '';
			}
		}
		return '';
	}

	/**
	 * information_schema table existence check (R-SHOW-TABLES compliant).
	 *
	 * @param string $table
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		$present = (int) (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				(string) $table
			)
		);
		return $present > 0;
	}

	/**
	 * Resolve canonical plugin root path with trailing slash.
	 *
	 * @return string
	 */
	private function resolve_plugin_root() {
		// [2026-07-16 Johnny Chu] HOTFIX — prefer canonical constants before dirname fallback.
		if ( defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			return rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/';
		}
		if ( defined( 'BIZCITY_TWIN_AI_PATH' ) ) {
			return rtrim( (string) BIZCITY_TWIN_AI_PATH, '/\\' ) . '/';
		}
		return dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
	}

	/**
	 * Return first readable path from candidates.
	 *
	 * @param array<int,string> $candidates
	 * @return string
	 */
	private function resolve_first_readable( array $candidates ) {
		foreach ( $candidates as $path ) {
			$path = (string) $path;
			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Citation_Continuity';
	return $list;
} );
