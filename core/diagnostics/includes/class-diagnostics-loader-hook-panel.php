<?php
/**
 * Diagnostics Loader Hook Panel - bounded WP_Hook observability.
 *
 * Captures hook metadata at lifecycle boundaries so operators can see which
 * loader phase added callbacks without retaining callback objects or payloads.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-08-09
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Diagnostics_Loader_Hook_Panel', false ) ) {
	return;
}

final class BizCity_Diagnostics_Loader_Hook_Panel {

	const MAX_HOOKS = 300;
	const MAX_CALLBACKS_PER_HOOK = 4;
	const MAX_FILE_PATHS_PER_GROUP = 4;
	const MAX_REGISTRATION_KEYS = 5000;

	private static $snapshots = array();
	private static $registered = false;
	private static $previous_files = null;
	private static $previous_classes = null;
	private static $previous_registration_keys = null;

	/**
	 * Register bounded lifecycle snapshots. No-op outside diagnostics context.
	 */
	public static function init(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// [2026-08-09 Johnny Chu] R-PERF-LOADER-EARLY - import the gap between
		// compat/plugin preload and QM collector creation as an explicit phase.
		$early_baseline = isset( $GLOBALS['bizcity_qm_loader_early_baseline'] )
			? $GLOBALS['bizcity_qm_loader_early_baseline']
			: null;
		if ( is_array( $early_baseline )
			&& isset( $early_baseline['files'], $early_baseline['classes'], $early_baseline['memory'] ) ) {
			self::$previous_files   = $early_baseline['files'];
			self::$previous_classes = $early_baseline['classes'];
			self::capture( 'pre_plugins_loaded' );
			if ( isset( self::$snapshots['pre_plugins_loaded'] ) ) {
				self::$snapshots['pre_plugins_loaded']['capture_overhead_delta_kb'] = round(
					( memory_get_usage( false ) - (int) $early_baseline['memory'] ) / 1024,
					1
				);
			}
		}

		// [2026-08-09 Johnny Chu] R-PERF-LOADER-HOOK - capture only compact hook metadata at lifecycle boundaries.
		foreach ( array( 'plugins_loaded', 'init', 'rest_api_init', 'current_screen' ) as $phase ) {
			add_action( $phase, array( __CLASS__, 'capture_' . $phase ), PHP_INT_MAX, 1 );
		}
		add_action( 'admin_footer', array( __CLASS__, 'capture_admin_footer' ), PHP_INT_MAX - 1, 1 );
	}

	public static function capture_plugins_loaded(): void { self::capture( 'plugins_loaded' ); }
	public static function capture_init(): void { self::capture( 'init' ); }
	public static function capture_rest_api_init(): void { self::capture( 'rest_api_init' ); }
	public static function capture_current_screen(): void { self::capture( 'current_screen' ); }
	public static function capture_admin_footer(): void { self::capture( 'admin_footer' ); }

	/**
	 * Return compact snapshots for the current request.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function snapshots(): array {
		return self::$snapshots;
	}

	/**
	 * Render the operator panel. The full callback list is capped and sorted.
	 */
	public static function render(): void {
		$snapshots = self::snapshots();
		if ( empty( $snapshots ) ) {
			return;
		}
		?>
		<section class="bzdiag-loader-hooks" style="margin:20px 0">
			<h2><?php esc_html_e( 'Loader Hook Observability', 'bizcity-twin-ai' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Bounded snapshot cua WP_Hook theo lifecycle phase. Khong luu payload hoac callback object.', 'bizcity-twin-ai' ); ?>
			</p>
			<?php foreach ( $snapshots as $phase => $snapshot ) : ?>
				<details style="margin:8px 0;border:1px solid #c3c4c7;background:#fff;padding:8px 12px">
					<summary style="cursor:pointer;font-weight:600">
						<?php echo esc_html( $phase ); ?>
						<span style="font-weight:normal;color:#666">
							(<?php echo (int) $snapshot['hook_count']; ?> hooks · <?php echo (int) $snapshot['callback_count']; ?>/<?php echo (int) $snapshot['callbacks_total']; ?> callbacks · capture +<?php echo (float) ( $snapshot['capture_overhead_delta_kb'] ?? 0 ); ?> KB · used <?php echo (float) ( $snapshot['memory_current_mb'] ?? $snapshot['memory_mb'] ?? 0 ); ?> MB · peak <?php echo (float) ( $snapshot['memory_peak_mb'] ?? 0 ); ?> MB)
						</span>
					</summary>
					<p class="description" style="margin:8px 0">
						<?php echo (int) $snapshot['new_file_count']; ?> new files · <?php echo (int) $snapshot['new_class_count']; ?> new classes
					</p>
					<?php if ( ! empty( $snapshot['context'] ) ) : ?>
					<p class="description" style="margin:8px 0">
						<strong>Context:</strong>
						<?php echo esc_html( (string) ( $snapshot['context']['surface'] ?? '' ) ); ?>
						· <?php echo esc_html( (string) ( $snapshot['context']['route_or_screen'] ?? '' ) ); ?>
						<?php if ( ! empty( $snapshot['context']['ajax_action'] ) ) : ?>
						· action=<?php echo esc_html( (string) $snapshot['context']['ajax_action'] ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $snapshot['context']['webhook_kind'] ) ) : ?>
						· webhook=<?php echo esc_html( (string) $snapshot['context']['webhook_kind'] ); ?>
						<?php endif; ?>
					</p>
					<?php endif; ?>
					<?php if ( ! empty( $snapshot['new_file_groups'] ) ) : ?>
					<p style="margin:8px 0"><strong>New file buckets:</strong>
						<?php
						$new_file_groups = $snapshot['new_file_groups'];
						arsort( $new_file_groups );
						$new_file_text = array();
						foreach ( array_slice( $new_file_groups, 0, 12, true ) as $new_group => $new_count ) {
							$new_file_text[] = esc_html( $new_group ) . ': ' . (int) $new_count;
						}
						echo implode( ', ', $new_file_text );
						if ( count( $new_file_groups ) > 12 ) {
							echo ' ...';
						}
						?>
					</p>
					<?php endif; ?>
					<?php if ( ! empty( $snapshot['first_new_file_by_group'] ) ) : ?>
					<p style="margin:8px 0"><strong>First new files:</strong>
						<?php
						$first_new_files = array();
						foreach ( $snapshot['first_new_file_by_group'] as $file_group => $file_paths ) {
							$first_new_files[] = esc_html( $file_group ) . ': ' . esc_html( implode( ', ', (array) $file_paths ) );
						}
						echo implode( '; ', $first_new_files );
						?>
					</p>
					<?php endif; ?>
					<?php if ( ! empty( $snapshot['last_new_file_by_group'] ) ) : ?>
					<p style="margin:8px 0"><strong>Last new files:</strong>
						<?php
						$last_new_files = array();
						foreach ( $snapshot['last_new_file_by_group'] as $file_group => $file_paths ) {
							$last_new_files[] = esc_html( $file_group ) . ': ' . esc_html( implode( ', ', (array) $file_paths ) );
						}
						echo implode( '; ', $last_new_files );
						?>
					</p>
					<?php endif; ?>
					<?php if ( isset( $snapshot['registration_delta'] ) ) : ?>
					<p class="description" style="margin:8px 0">
						<strong>Registration:</strong>
						+<?php echo (int) ( $snapshot['registration_delta']['added'] ?? 0 ); ?> /
						-<?php echo (int) ( $snapshot['registration_delta']['removed'] ?? 0 ); ?> ·
						duplicates=<?php echo (int) ( $snapshot['registration_delta']['duplicate'] ?? 0 ); ?> ·
						<?php echo ! empty( $snapshot['registration_delta']['truncated'] ) ? 'truncated' : 'complete'; ?>
					</p>
					<?php endif; ?>
					<?php if ( ! empty( $snapshot['source_summary'] ) ) : ?>
					<table class="widefat" style="margin-top:8px;max-width:720px">
						<thead><tr><th>Module bucket</th><th style="text-align:right">Callbacks</th></tr></thead>
						<tbody>
						<?php
						$source_summary = ! empty( $snapshot['source_summary_all'] )
							? $snapshot['source_summary_all']
							: $snapshot['source_summary'];
						arsort( $source_summary );
						foreach ( array_slice( $source_summary, 0, 12, true ) as $source_group => $source_count ) : ?>
							<tr><td><code><?php echo esc_html( $source_group ); ?></code></td><td style="text-align:right"><?php echo (int) $source_count; ?></td></tr>
						<?php endforeach; ?>
						<?php if ( count( $source_summary ) > 12 ) : ?><tr><td colspan="2">... <?php echo count( $source_summary ) - 12; ?> more buckets</td></tr><?php endif; ?>
						<?php unset( $source_summary ); ?>
						</tbody>
					</table>
					<?php endif; ?>
					<table class="widefat striped" style="margin-top:8px">
						<thead><tr><th>Hook</th><th>Priority</th><th>Callback owner</th><th>Callback file</th><th>Line</th><th>Source group</th><th>Fired</th></tr></thead>
						<tbody>
						<?php foreach ( $snapshot['hooks'] as $hook ) : ?>
							<tr>
								<td><code><?php echo esc_html( $hook['hook'] ); ?></code></td>
								<td><?php echo esc_html( $hook['priority'] ); ?></td>
								<td><code><?php echo esc_html( $hook['callback'] ); ?></code></td>
								<td><code><?php echo esc_html( $hook['callback_file_relative'] ?? '' ); ?></code></td>
								<td><?php echo (int) ( $hook['callback_start_line'] ?? 0 ); ?></td>
								<td><?php echo esc_html( $hook['source_group'] ); ?></td>
								<td><?php echo (int) $hook['did_action']; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Capture a bounded, normalized view of $wp_filter.
	 */
	private static function capture( string $phase ): void {
		global $wp_filter;
		if ( ! is_array( $wp_filter ) ) {
			return;
		}

		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - keep the delta
		// on the same `used` basis as memory_current_mb; do not subtract an
		// allocated baseline from current used memory.
		$memory_before = memory_get_usage( false );
		$current_files = get_included_files();
		$current_classes = get_declared_classes();
		$new_files = self::$previous_files === null
			? array()
			: array_values( array_diff( $current_files, array_keys( self::$previous_files ) ) );
		$new_classes = self::$previous_classes === null
			? array()
			: array_values( array_diff( $current_classes, array_keys( self::$previous_classes ) ) );
		self::$previous_files = array_fill_keys( $current_files, true );
		self::$previous_classes = array_fill_keys( $current_classes, true );
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - retain only bounded
		// request context and file anchors; this is observe-only and does not alter loading.
		$request_context = self::request_context();
		$detailed_mode = self::detailed_mode();
		$rows = array();
		$callback_count = 0;
		$callbacks_total = 0;
		$source_summary = array();
		$source_summary_all = array();
		$new_file_groups = array();
		$first_new_file_by_group = array();
		$last_new_file_by_group = array();
		foreach ( $new_files as $new_file ) {
			$group = self::source_group_from_file( $new_file );
			if ( ! isset( $new_file_groups[ $group ] ) ) {
				$new_file_groups[ $group ] = 0;
			}
			$new_file_groups[ $group ]++;
			$relative_file = self::relative_file( $new_file );
			if ( ! isset( $first_new_file_by_group[ $group ] ) ) {
				$first_new_file_by_group[ $group ] = array();
			}
			if ( count( $first_new_file_by_group[ $group ] ) < self::MAX_FILE_PATHS_PER_GROUP ) {
				$first_new_file_by_group[ $group ][] = $relative_file;
			}
			if ( ! isset( $last_new_file_by_group[ $group ] ) ) {
				$last_new_file_by_group[ $group ] = array();
			}
			$last_new_file_by_group[ $group ][] = $relative_file;
			if ( count( $last_new_file_by_group[ $group ] ) > self::MAX_FILE_PATHS_PER_GROUP ) {
				$last_new_file_by_group[ $group ] = array_slice( $last_new_file_by_group[ $group ], -self::MAX_FILE_PATHS_PER_GROUP );
			}
		}
		$registration_counts = array();
		$registration_total = 0;
		$registration_truncated = false;
		foreach ( $wp_filter as $hook_name => $hook ) {
			if ( ! is_object( $hook ) || empty( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				$per_hook = 0;
				foreach ( (array) $callbacks as $callback_data ) {
					$callbacks_total++;
					$callback = isset( $callback_data['function'] ) ? $callback_data['function'] : null;
					$callback_location = $detailed_mode
						? self::callback_location( $callback )
						: array(
							'file_absolute' => '',
							'file_relative' => '',
							'start_line'    => 0,
							'status'        => 'summary_only',
						);
					$source_group = '' !== $callback_location['file_absolute']
						? self::source_group_from_file( $callback_location['file_absolute'] )
						: ( $detailed_mode ? self::source_group( $callback ) : 'not_sampled' );
					if ( $detailed_mode ) {
						if ( ! isset( $source_summary_all[ $source_group ] ) ) {
							$source_summary_all[ $source_group ] = 0;
						}
						$source_summary_all[ $source_group ]++;
					}
					$registration_key = self::registration_key(
						(string) $hook_name,
						(string) $priority,
						$callback,
						$callback_location
					);
					$registration_total++;
					if ( isset( $registration_counts[ $registration_key ] ) ) {
						$registration_counts[ $registration_key ]++;
					} elseif ( count( $registration_counts ) < self::MAX_REGISTRATION_KEYS ) {
						$registration_counts[ $registration_key ] = 1;
					} else {
						$registration_truncated = true;
					}
					if ( ! $detailed_mode || $per_hook >= self::MAX_CALLBACKS_PER_HOOK || count( $rows ) >= self::MAX_HOOKS ) {
						continue;
					}
					$rows[] = array(
						'hook'                 => (string) $hook_name,
						'priority'             => (string) $priority,
						'callback'             => self::describe_callback( $callback ),
						'callback_file_relative' => $callback_location['file_relative'],
						'callback_start_line'  => $callback_location['start_line'],
						'callback_file_status' => $callback_location['status'],
						'source_group'        => $source_group,
						'did_action'           => function_exists( 'did_action' ) ? (int) did_action( (string) $hook_name ) : 0,
					);
					$per_hook++;
					$callback_count++;
					if ( ! isset( $source_summary[ $source_group ] ) ) {
						$source_summary[ $source_group ] = 0;
					}
					$source_summary[ $source_group ]++;
				}
			}
		}
		$current_registration_keys = array_fill_keys( array_keys( $registration_counts ), true );
		$registration_added = 0;
		$registration_removed = 0;
		if ( self::$previous_registration_keys !== null ) {
			$registration_added = count( array_diff_key( $current_registration_keys, self::$previous_registration_keys ) );
			$registration_removed = count( array_diff_key( self::$previous_registration_keys, $current_registration_keys ) );
		}
		$registration_duplicate = 0;
		foreach ( $registration_counts as $registration_count ) {
			if ( $registration_count > 1 ) {
				$registration_duplicate += $registration_count - 1;
			}
		}
		self::$previous_registration_keys = $current_registration_keys;
		$boot_state_delta = array();
		$boot_state_status = 'not_instrumented';
		if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
			// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W2 - expose registry
			// claims/events without enforcing ownership or retaining runtime objects.
			$boot_state_delta = BizCity_Loader_Ownership_Registry::snapshot();
			$boot_state_status = 'observe_only';
		}

		$memory_peak_used_bytes = memory_get_peak_usage( false );
		$memory_peak_allocated_raw_bytes = memory_get_peak_usage( true );
		$memory_peak_raw_consistent = $memory_peak_allocated_raw_bytes >= $memory_peak_used_bytes;
		$memory_peak_allocated_bytes = max( $memory_peak_allocated_raw_bytes, $memory_peak_used_bytes );
		self::$snapshots[ $phase ] = array(
			'schema'                    => 'bizcity.loader.v2',
			'phase'                     => $phase,
			'context'                   => $request_context,
			'detail_mode'               => $detailed_mode ? 'full' : 'summary',
			'hook_count'                => count( $wp_filter ),
			'callback_count'            => $callback_count,
			'callbacks_total'           => $callbacks_total,
			// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - expose the
			// PHP current/allocated/peak values separately. QM top-bar commonly
			// shows peak, so one ambiguous "memory now" value caused false comparison.
			'memory_mb'                 => round( memory_get_usage( false ) / 1048576, 2 ),
			'memory_current_mb'         => round( memory_get_usage( false ) / 1048576, 2 ),
			'memory_allocated_mb'       => round( memory_get_usage( true ) / 1048576, 2 ),
			'memory_peak_mb'            => round( $memory_peak_used_bytes / 1048576, 2 ),
			'memory_peak_allocated_mb'  => round( $memory_peak_allocated_bytes / 1048576, 2 ),
			'memory_peak_allocated_raw_mb' => round( $memory_peak_allocated_raw_bytes / 1048576, 2 ),
			'memory_metric_raw_consistent' => $memory_peak_raw_consistent,
			'capture_overhead_delta_kb' => round( ( memory_get_usage( false ) - $memory_before ) / 1024, 1 ),
			'source_summary'            => $source_summary,
			'source_summary_all'        => $source_summary_all,
			'new_file_count'            => count( $new_files ),
			'new_class_count'           => count( $new_classes ),
			'new_file_groups'           => $new_file_groups,
			'first_new_file_by_group'   => $first_new_file_by_group,
			'last_new_file_by_group'    => $last_new_file_by_group,
			'registration_delta'        => array(
				'total'       => $registration_total,
				'unique'      => count( $registration_counts ),
				'added'       => $registration_added,
				'removed'     => $registration_removed,
				'duplicate'   => $registration_duplicate,
				'truncated'   => $registration_truncated,
			),
			'boot_state_delta'          => $boot_state_delta,
			'boot_state_status'         => $boot_state_status,
			'require_parent_status'     => 'unknown_parent',
			'hooks'                     => $rows,
		);
	}

	private static function detailed_mode(): bool {
		if ( ! empty( $_GET['bizcity_qm_probe'] ) && '1' === (string) $_GET['bizcity_qm_probe'] ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( false !== strpos( $uri, '/bizcity-diagnostics/' ) ) {
			return true;
		}
		return function_exists( 'is_admin' ) && is_admin()
			&& isset( $_GET['page'] )
			&& 'bizcity-diagnostics' === sanitize_key( (string) $_GET['page'] );
	}

	// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - expose callback
	// provenance without retaining callable objects or arbitrary backtraces.
	private static function callback_location( $callback ): array {
		$file = '';
		$line = 0;
		$status = 'unknown';
		try {
			$reflection = null;
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof Closure ) {
				$reflection = new ReflectionFunction( $callback );
			}
			if ( $reflection instanceof ReflectionFunctionAbstract ) {
				$file = (string) $reflection->getFileName();
				$line = (int) $reflection->getStartLine();
				$status = $file !== '' ? 'known' : 'reflection_failed';
			}
		} catch ( Throwable $e ) {
			$status = 'reflection_failed';
		}
		return array(
			'file_absolute'  => $file,
			'file_relative'  => $file !== '' ? self::relative_file( $file ) : '',
			'start_line'     => $line,
			'status'         => $status,
		);
	}

	private static function registration_key( string $hook, string $priority, $callback, array $location ): string {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W4 - distinguish two
		// legitimate object instances using the same method from one callable
		// registered twice; object hashes are request-local and contain no payload.
		$identity = '';
		if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ) {
			$identity = 'object:' . spl_object_hash( $callback[0] );
		} elseif ( $callback instanceof Closure ) {
			$identity = 'closure:' . spl_object_hash( $callback );
		} elseif ( is_object( $callback ) ) {
			$identity = 'object:' . spl_object_hash( $callback );
		} else {
			$identity = 'callable:' . self::describe_callback( $callback );
		}
		return $hook . '|' . $priority . '|' . $identity . '|'
			. $location['file_relative'] . '|' . (int) $location['start_line'];
	}

	private static function relative_file( string $file ): string {
		$normalized_file = '/' . ltrim( str_replace( '\\', '/', $file ), '/' );
		$lower_file = strtolower( $normalized_file );
		$marker = strpos( $lower_file, '/wp-content/' );
		if ( false !== $marker ) {
			return 'wp-content/' . ltrim( substr( $normalized_file, $marker + 12 ), '/' );
		}
		return 'external/' . substr( sha1( $normalized_file ), 0, 12 );
	}

	private static function request_context(): array {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = function_exists( 'wp_parse_url' )
			? (string) wp_parse_url( $uri, PHP_URL_PATH )
			: (string) parse_url( $uri, PHP_URL_PATH );
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] )
			? sanitize_key( (string) $_GET['page'] )
			: '';
		$action = isset( $_REQUEST['action'] ) && is_scalar( $_REQUEST['action'] )
			? sanitize_key( (string) $_REQUEST['action'] )
			: '';
		$surface = 'public_html';
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$surface = 'cli';
		} elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$surface = 'cron_hook';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$surface = 'rest_route';
		} elseif ( function_exists( 'is_admin' ) && is_admin() ) {
			$surface = $page === 'bizcity-twinchat' ? 'admin_shell' : 'admin_page';
		} elseif ( false !== strpos( $path, '/bizhook/' ) || false !== strpos( $path, '/bizfbhook' ) || false !== strpos( $path, '/zalohook/' ) ) {
			$surface = 'webhook';
		}
		$webhook = '';
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		if ( false !== strpos( $query, 'fbhook=1' ) || false !== strpos( $query, 'fb_callback=1' ) ) {
			$webhook = 'facebook';
		} elseif ( false !== strpos( $path, '/bizhook/' ) || false !== strpos( $path, '/zalohook/' ) ) {
			$webhook = 'zalo';
		}
		return array(
			'surface'        => $surface,
			'route_or_screen' => $page !== '' ? $page : $path,
			'admin_page'     => $page,
			'ajax_action'    => $action,
			'webhook_kind'   => $webhook,
			'cron_hook'      => $surface === 'cron_hook' ? 'unknown_cron_hook' : '',
			'runtime_identity' => self::runtime_identity(),
		);
	}

	private static function runtime_identity(): array {
		static $identity = null;
		if ( is_array( $identity ) ) {
			return $identity;
		}

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$source_compat = $root . 'mu-plugin/bizcity-twin-compat.php';
		$deployed_compat = defined( 'WP_CONTENT_DIR' ) ? trailingslashit( WP_CONTENT_DIR ) . 'mu-plugins/bizcity-twin-compat.php' : '';
		$artifacts = array(
			'plugin'          => $root . 'bizcity-twin-ai.php',
			'loader_panel'    => __FILE__,
			'qm_collector'    => dirname( __FILE__ ) . '/class-qm-loader-collector.php',
			'ownership'       => $root . 'core/runtime/class-loader-ownership-registry.php',
			'compat_source'   => $source_compat,
			'compat_deployed' => $deployed_compat,
		);
		$hashes = array();
		foreach ( $artifacts as $name => $file ) {
			$hashes[ $name ] = ( $file !== '' && is_readable( $file ) && function_exists( 'sha1_file' ) )
				? substr( (string) @sha1_file( $file ), 0, 16 )
				: 'unknown';
		}
		$release_material = defined( 'BIZCITY_TWIN_AI_VERSION' ) ? BIZCITY_TWIN_AI_VERSION : 'unknown';
		$release_material .= '|' . implode( '|', $hashes );
		$identity = array(
			'plugin_version' => defined( 'BIZCITY_TWIN_AI_VERSION' ) ? (string) BIZCITY_TWIN_AI_VERSION : 'unknown',
			'version_source' => defined( 'BIZCITY_TWIN_AI_VERSION_SOURCE' ) ? (string) BIZCITY_TWIN_AI_VERSION_SOURCE : 'unknown',
			'php_version'    => function_exists( 'phpversion' ) ? (string) phpversion() : 'unknown',
			'opcache_status' => function_exists( 'opcache_get_status' ) && false !== @opcache_get_status( false ) ? 'available' : 'unavailable',
			'release_hash'   => substr( sha1( $release_material ), 0, 16 ),
			'artifact_hashes' => $hashes,
		);
		return $identity;
	}

	private static function describe_callback( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) ) {
			$owner = isset( $callback[0] )
				? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] )
				: '(missing owner)';
			$method = isset( $callback[1] ) ? (string) $callback[1] : '(missing method)';
			return $owner . '::' . $method;
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback );
		}
		return gettype( $callback );
	}

	private static function source_group( $callback ): string {
		$owner = self::describe_callback( $callback );
		$file = '';
		try {
			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$file = (string) ( new ReflectionFunction( $callback ) )->getFileName();
			} elseif ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$file = (string) ( new ReflectionMethod( $callback[0], (string) $callback[1] ) )->getFileName();
			} elseif ( $callback instanceof Closure ) {
				$file = (string) ( new ReflectionFunction( $callback ) )->getFileName();
			}
		} catch ( Throwable $e ) {
			$file = '';
		}

		if ( $file !== '' ) {
			return self::source_group_from_file( $file );
		}
		if ( false !== strpos( strtolower( $owner ), 'wp_' ) ) {
			return 'wordpress';
		}
		return $owner !== '' ? 'external/unknown' : 'unknown';
	}

	private static function source_group_from_file( string $file ): string {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - normalize absolute,
		// UNC and relative paths before classifying wp-content owners.
		$normalized_file = '/' . ltrim( strtolower( str_replace( '\\', '/', $file ) ), '/' );
		$marker = strpos( $normalized_file, '/wp-content/' );
		$relative = false !== $marker ? substr( $normalized_file, $marker + 12 ) : ltrim( $normalized_file, '/' );
		$parts = array_values( array_filter( explode( '/', $relative ), 'strlen' ) );
		if ( empty( $parts ) ) {
			return 'external/unknown';
		}
		if ( $parts[0] === 'plugins' ) {
			if ( isset( $parts[1], $parts[2] ) && $parts[1] === 'bizcity-twin-ai' && $parts[2] === 'plugins' ) {
				return 'bundle:' . (string) ( $parts[3] ?? 'unknown' );
			}
			if ( isset( $parts[1], $parts[2] ) && $parts[1] === 'bizcity-twin-ai' && $parts[2] === 'core' ) {
				return 'core:' . (string) ( $parts[3] ?? 'unknown' );
			}
			if ( isset( $parts[1], $parts[2] ) && $parts[1] === 'bizcity-twin-ai' && $parts[2] === 'modules' ) {
				return 'module:' . (string) ( $parts[3] ?? 'unknown' );
			}
			return 'plugin:' . (string) ( $parts[1] ?? 'unknown' );
		}
		if ( $parts[0] === 'mu-plugins' ) {
			return 'mu:' . (string) ( $parts[1] ?? 'unknown' );
		}
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W1 - classify active
		// theme callbacks separately so theme load is not hidden in external/unknown.
		if ( $parts[0] === 'themes' ) {
			return 'theme:' . (string) ( $parts[1] ?? 'unknown' );
		}
		if ( $parts[0] === 'wp-includes' ) {
			return 'wp-core';
		}
		if ( $parts[0] === 'wp-admin' ) {
			return 'wp-admin';
		}
		return 'external/unknown';
	}
}