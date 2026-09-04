<?php
/**
 * Shared admin explorer for registered JSONL log contracts.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Log_Explorer', false ) ) {
	return;
}

final class BizCity_Log_Explorer {

	const PAGE_SLUG = 'bizcity-log-explorer';

	/**
	 * Register the one shared log menu and export endpoint.
	 */
	public static function init() {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-EXPLORER — one shared admin reader for every registered JSONL contract.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bizcity_log_export', array( __CLASS__, 'export' ) );
	}

	public static function register_menu() {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-EXPLORER — expose the canonical viewer below Tools.
		add_management_page(
			__( 'BizCity Logs', 'bizcity-twin-ai' ),
			__( 'BizCity Logs', 'bizcity-twin-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-EXPLORER — render only registry-approved sources and bounded rows.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền xem log.', 'bizcity-twin-ai' ) );
		}

		$contracts = class_exists( 'BizCity_Log_Contract_Registry' )
			? BizCity_Log_Contract_Registry::all()
			: array();
		$contract_id = isset( $_GET['contract'] ) ? sanitize_text_field( wp_unslash( $_GET['contract'] ) ) : '';
		if ( $contract_id === '' && ! empty( $contracts ) ) {
			$keys        = array_keys( $contracts );
			$contract_id = (string) $keys[0];
		}
		$contract = isset( $contracts[ $contract_id ] ) ? $contracts[ $contract_id ] : null;
		$date     = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$level    = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$view_all  = isset( $_GET['view'] ) && sanitize_key( wp_unslash( $_GET['view'] ) ) === 'all';
		$index_event = isset( $_GET['index_event'] ) ? sanitize_key( wp_unslash( $_GET['index_event'] ) ) : '';
		$index_ref_id = isset( $_GET['index_ref_id'] ) ? sanitize_text_field( wp_unslash( $_GET['index_ref_id'] ) ) : '';
		$page     = isset( $_GET['log_page'] ) ? max( 1, (int) $_GET['log_page'] ) : 1;
		$per_page = 50;
		$rows     = $contract ? self::query_rows( $contract_id, $contract, $date, $level, $search ) : array();
		$total    = count( $rows );
		$rows     = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		$index_rows = $view_all && class_exists( 'BizCity_Log_Index' )
			? BizCity_Log_Index::search( array( 'event' => $index_event, 'ref_id' => $index_ref_id, 'limit' => 200 ) )
			: array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BizCity Logs', 'bizcity-twin-ai' ); ?></h1>
			<?php if ( empty( $contracts ) ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Chưa có log contract nào được đăng ký.', 'bizcity-twin-ai' ); ?></p></div>
			<?php return; endif; ?>
			<p>
				<a class="button <?php echo $view_all ? 'button-primary' : 'button-secondary'; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'view' => 'all' ), admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Search across all logs', 'bizcity-twin-ai' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Contract view', 'bizcity-twin-ai' ); ?></a>
			</p>
			<?php if ( $view_all ) : ?>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<input type="hidden" name="view" value="all">
					<label for="bizcity-index-event"><?php esc_html_e( 'Event', 'bizcity-twin-ai' ); ?></label>
					<input id="bizcity-index-event" type="search" name="index_event" value="<?php echo esc_attr( $index_event ); ?>">
					<label for="bizcity-index-ref"><?php esc_html_e( 'Correlation ID', 'bizcity-twin-ai' ); ?></label>
					<input id="bizcity-index-ref" type="search" name="index_ref_id" value="<?php echo esc_attr( $index_ref_id ); ?>">
					<?php submit_button( __( 'Search index', 'bizcity-twin-ai' ), 'secondary', '', false ); ?>
				</form>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Time', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Contract', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Event', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Correlation', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Pointer', 'bizcity-twin-ai' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $index_rows as $index_row ) : ?>
						<?php
						$pointer_check = class_exists( 'BizCity_JSONL_File_Logger' )
							? BizCity_JSONL_File_Logger::verify_pointer( $index_row['jsonl_folder'], $index_row['jsonl_module'], $index_row['relative_file'], $index_row['byte_offset'], $index_row['row_hash'] )
							: array( 'valid' => false );
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $index_row['ts'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $index_row['contract_id'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $index_row['event'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $index_row['ref_id'] ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $index_row['relative_file'] ?? '' ) ); ?></code><br><?php if ( ! empty( $pointer_check['valid'] ) ) : ?><strong style="color:#00674e">&#10003; verified</strong><?php else : ?><strong style="color:#b32d2e">&#10007; pointer mismatch</strong><?php endif; ?><br><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'contract' => (string) ( $index_row['contract_id'] ?? '' ), 'date' => (string) ( $index_row['log_date'] ?? '' ) ), admin_url( 'tools.php' ) ) ); ?>"><?php esc_html_e( 'Open source', 'bizcity-twin-ai' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $index_rows ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No indexed pointer matched.', 'bizcity-twin-ai' ); ?></td></tr><?php endif; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label for="bizcity-log-contract"><?php esc_html_e( 'Nguồn log', 'bizcity-twin-ai' ); ?></label>
				<select id="bizcity-log-contract" name="contract">
					<?php foreach ( $contracts as $id => $item ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $contract_id, $id ); ?>><?php echo esc_html( $item['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="bizcity-log-date"><?php esc_html_e( 'Ngày', 'bizcity-twin-ai' ); ?></label>
				<input id="bizcity-log-date" type="date" name="date" value="<?php echo esc_attr( $date ); ?>">
				<label for="bizcity-log-level"><?php esc_html_e( 'Mức', 'bizcity-twin-ai' ); ?></label>
				<select id="bizcity-log-level" name="level">
					<option value=""><?php esc_html_e( 'Tất cả', 'bizcity-twin-ai' ); ?></option>
					<?php foreach ( array( 'debug', 'info', 'warn', 'error' ) as $item_level ) : ?>
						<option value="<?php echo esc_attr( $item_level ); ?>" <?php selected( $level, $item_level ); ?>><?php echo esc_html( $item_level ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Tìm event hoặc message', 'bizcity-twin-ai' ); ?>">
				<?php submit_button( __( 'Lọc', 'bizcity-twin-ai' ), 'secondary', '', false ); ?>
			</form>
			<?php if ( $contract ) : ?>
				<p>
					<?php echo esc_html( $contract['owner_module'] . ' · ' . $contract['jsonl_folder'] . '/' . $contract['jsonl_module'] ); ?>
					<?php self::render_export_links( $contract_id, $date, $level, $search ); ?>
				</p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Thời gian', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Mức', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Event', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Message', 'bizcity-twin-ai' ); ?></th><th><?php esc_html_e( 'Chi tiết', 'bizcity-twin-ai' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : $raw = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['ts'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['level'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['event'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['msg'] ?? '' ) ); ?></td>
						<td><details><summary><?php esc_html_e( 'Mở rộng', 'bizcity-twin-ai' ); ?></summary><pre><?php echo esc_html( self::pretty_json( $row ) ); ?></pre><button type="button" class="button bizcity-log-copy" data-json="<?php echo esc_attr( $raw ); ?>"><?php esc_html_e( 'Copy JSON', 'bizcity-twin-ai' ); ?></button></details></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $rows ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'Không có dòng log phù hợp.', 'bizcity-twin-ai' ); ?></td></tr><?php endif; ?>
				</tbody>
			</table>
			<?php self::render_pagination( $contract_id, $date, $level, $search, $page, $total, $per_page ); ?>
		</div>
		<script>
		 document.querySelectorAll('.bizcity-log-copy').forEach(function(button) {
			button.addEventListener('click', function() {
				navigator.clipboard.writeText(button.getAttribute('data-json') || '');
			});
		 });
		</script>
		<?php
	}

	private static function query_rows( $contract_id, array $contract, $date, $level, $search ) {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-EXPLORER — keep reads bounded and use the shared logger only.
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return array();
		}
		$args = array( 'days' => 30, 'limit' => 5000, 'level' => $level );
		if ( $date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$rows = BizCity_JSONL_File_Logger::read_contract( $contract_id, $date, 5000, $level );
		} else {
			$rows = BizCity_JSONL_File_Logger::query_contract( $contract_id, $args );
		}
		if ( $search === '' ) {
			return (array) $rows;
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$haystack = wp_json_encode( $row );
			if ( false !== stripos( (string) $haystack, $search ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	private static function pretty_json( array $row ) {
		$json = wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	private static function render_export_links( $contract_id, $date, $level, $search ) {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-EXPLORER — export only selected registry contract rows.
		$args = array( 'action' => 'bizcity_log_export', 'contract' => $contract_id, 'date' => $date, 'level' => $level, 'search' => $search, '_wpnonce' => wp_create_nonce( 'bizcity_log_export' ) );
		echo ' <a href="' . esc_url( add_query_arg( array_merge( $args, array( 'format' => 'jsonl' ) ), admin_url( 'admin-post.php' ) ) ) . '">' . esc_html__( 'Export JSONL', 'bizcity-twin-ai' ) . '</a>';
		echo ' · <a href="' . esc_url( add_query_arg( array_merge( $args, array( 'format' => 'csv' ) ), admin_url( 'admin-post.php' ) ) ) . '">' . esc_html__( 'Export CSV', 'bizcity-twin-ai' ) . '</a>';
	}

	private static function render_pagination( $contract_id, $date, $level, $search, $page, $total, $per_page ) {
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		$base = add_query_arg( array( 'page' => self::PAGE_SLUG, 'contract' => $contract_id, 'date' => $date, 'level' => $level, 'search' => $search, 'log_page' => '%#%' ), admin_url( 'tools.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $pages, 'type' => 'plain' ) ) ) . '</div></div>';
	}

	public static function export() {
		// [2026-08-25 Johnny Chu] PHASE-1.29-LOG-EXPLORER — enforce capability, nonce, contract allowlist, and bounded export.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền xuất log.', 'bizcity-twin-ai' ) );
		}
		check_admin_referer( 'bizcity_log_export' );
		$id        = isset( $_GET['contract'] ) ? sanitize_text_field( wp_unslash( $_GET['contract'] ) ) : '';
		$contracts = class_exists( 'BizCity_Log_Contract_Registry' ) ? BizCity_Log_Contract_Registry::all() : array();
		if ( ! isset( $contracts[ $id ] ) ) {
			wp_die( esc_html__( 'Log contract không hợp lệ.', 'bizcity-twin-ai' ) );
		}
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$level  = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
		$rows   = self::query_rows( $id, $contracts[ $id ], $date, $level, $search );
		$format = isset( $_GET['format'] ) && sanitize_key( wp_unslash( $_GET['format'] ) ) === 'csv' ? 'csv' : 'jsonl';
		header( 'Content-Disposition: attachment; filename=bizcity-log-' . sanitize_key( $id ) . '.' . $format );
		header( 'Content-Type: ' . ( $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/x-ndjson; charset=utf-8' ) );
		if ( $format === 'csv' ) {
			$out = fopen( 'php://output', 'w' );
			fputcsv( $out, array( 'ts', 'blog_id', 'module', 'level', 'event', 'msg', 'ctx' ) );
			foreach ( $rows as $row ) {
				fputcsv( $out, array( $row['ts'] ?? '', $row['blog_id'] ?? '', $row['module'] ?? '', $row['level'] ?? '', $row['event'] ?? '', $row['msg'] ?? '', wp_json_encode( $row['ctx'] ?? array() ) ) );
			}
			fclose( $out );
		} else {
			foreach ( $rows as $row ) {
				echo wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
			}
		}
		exit;
	}
}

BizCity_Log_Explorer::init();
