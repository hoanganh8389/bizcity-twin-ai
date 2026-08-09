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

	private static $snapshots = array();
	private static $registered = false;
	private static $previous_files = null;
	private static $previous_classes = null;

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
				self::$snapshots['pre_plugins_loaded']['memory_delta_kb'] = round(
					( memory_get_usage( true ) - (int) $early_baseline['memory'] ) / 1024,
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
							(<?php echo (int) $snapshot['hook_count']; ?> hooks · <?php echo (int) $snapshot['callback_count']; ?>/<?php echo (int) $snapshot['callbacks_total']; ?> callbacks · +<?php echo (float) $snapshot['memory_delta_kb']; ?> KB · <?php echo (int) $snapshot['memory_mb']; ?> MB)
						</span>
					</summary>
					<p class="description" style="margin:8px 0">
						<?php echo (int) $snapshot['new_file_count']; ?> new files · <?php echo (int) $snapshot['new_class_count']; ?> new classes
					</p>
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
					<?php if ( ! empty( $snapshot['source_summary'] ) ) : ?>
					<table class="widefat" style="margin-top:8px;max-width:720px">
						<thead><tr><th>Module bucket</th><th style="text-align:right">Sampled callbacks</th></tr></thead>
						<tbody>
						<?php
						$source_summary = $snapshot['source_summary'];
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
						<thead><tr><th>Hook</th><th>Priority</th><th>Callback owner</th><th>Source group</th><th>Fired</th></tr></thead>
						<tbody>
						<?php foreach ( $snapshot['hooks'] as $hook ) : ?>
							<tr>
								<td><code><?php echo esc_html( $hook['hook'] ); ?></code></td>
								<td><?php echo esc_html( $hook['priority'] ); ?></td>
								<td><code><?php echo esc_html( $hook['callback'] ); ?></code></td>
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

		$memory_before = memory_get_usage( true );
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
		$rows = array();
		$callback_count = 0;
		$callbacks_total = 0;
		$source_summary = array();
		$new_file_groups = array();
		foreach ( $new_files as $new_file ) {
			$group = self::source_group_from_file( $new_file );
			if ( ! isset( $new_file_groups[ $group ] ) ) {
				$new_file_groups[ $group ] = 0;
			}
			$new_file_groups[ $group ]++;
		}
		foreach ( $wp_filter as $hook_name => $hook ) {
			if ( ! is_object( $hook ) || empty( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				$per_hook = 0;
				foreach ( (array) $callbacks as $callback_data ) {
					$callbacks_total++;
					if ( $per_hook >= self::MAX_CALLBACKS_PER_HOOK || count( $rows ) >= self::MAX_HOOKS ) {
						continue;
					}
					$callback = isset( $callback_data['function'] ) ? $callback_data['function'] : null;
					$source_group = self::source_group( $callback );
					$rows[] = array(
						'hook'        => (string) $hook_name,
						'priority'    => (string) $priority,
						'callback'    => self::describe_callback( $callback ),
						'source_group' => $source_group,
						'did_action'  => function_exists( 'did_action' ) ? (int) did_action( (string) $hook_name ) : 0,
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

		self::$snapshots[ $phase ] = array(
			'hook_count'     => count( $wp_filter ),
			'callback_count' => $callback_count,
			'callbacks_total' => $callbacks_total,
			'memory_mb'      => round( memory_get_usage( true ) / 1048576, 2 ),
			'memory_delta_kb' => round( ( memory_get_usage( true ) - $memory_before ) / 1024, 1 ),
			'source_summary' => $source_summary,
			'new_file_count' => count( $new_files ),
			'new_class_count' => count( $new_classes ),
			'new_file_groups' => $new_file_groups,
			'hooks'          => $rows,
		);
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
		$normalized_file = strtolower( str_replace( '\\', '/', $file ) );
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
		if ( $parts[0] === 'wp-includes' ) {
			return 'wp-core';
		}
		if ( $parts[0] === 'wp-admin' ) {
			return 'wp-admin';
		}
		return 'external/unknown';
	}
}