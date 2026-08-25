<?php
/**
 * Submissions Admin UI
 *
 * Admin menu and list table for managing contact submissions
 * 
 * @package    BizCity_Page_Builder
 * @subpackage Submissions_Admin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BZPB_Submissions_Admin {

	/**
	 * Initialize hooks
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 11 );
		add_action( 'admin_post_bzpb_mark_read', [ __CLASS__, 'handle_mark_read' ] );
		add_action( 'admin_post_bzpb_delete_submission', [ __CLASS__, 'handle_delete' ] );
		add_action( 'admin_post_bzpb_export_submissions', [ __CLASS__, 'handle_export_csv' ] );
	}

	/**
	 * Add admin menu
	 */
	public static function add_menu() {
		add_submenu_page(
			'bizcity-twin-plugins',
			'Liên hệ',
			'📩 Liên hệ',
			'manage_options',
			'bzpb-submissions',
			[ __CLASS__, 'render_list_page' ]
		);
	}

	/**
	 * Render submissions list page
	 */
	public static function render_list_page() {
		// Handle single submission detail view
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'view' && isset( $_GET['id'] ) ) {
			self::render_detail_page( intval( $_GET['id'] ) );
			return;
		}

		$list_table = new BZPB_Submissions_List_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Liên hệ</h1>
			
			<?php
			// Get count for status badges
			$all_count = BZPB_Submission_Handler::get_count();
			$unread_count = BZPB_Submission_Handler::get_count( [ 'status' => 'unread' ] );
			$read_count = BZPB_Submission_Handler::get_count( [ 'status' => 'read' ] );
			?>
			
			<ul class="subsubsub">
				<li><a href="?page=bzpb-submissions" <?php if ( empty( $_GET['status'] ) ) echo 'class="current"'; ?>>Tất cả <span class="count">(<?php echo $all_count; ?>)</span></a> |</li>
				<li><a href="?page=bzpb-submissions&status=unread" <?php if ( isset( $_GET['status'] ) && $_GET['status'] === 'unread' ) echo 'class="current"'; ?>>Chưa đọc <span class="count">(<?php echo $unread_count; ?>)</span></a> |</li>
				<li><a href="?page=bzpb-submissions&status=read" <?php if ( isset( $_GET['status'] ) && $_GET['status'] === 'read' ) echo 'class="current"'; ?>>Đã đọc <span class="count">(<?php echo $read_count; ?>)</span></a></li>
			</ul>

			<form method="get" style="margin-top: 20px;">
				<input type="hidden" name="page" value="bzpb-submissions">
				<?php
				$list_table->search_box( 'Tìm kiếm', 'submission' );
				?>
			</form>

			<form method="post">
				<?php
				$list_table->display();
				?>
			</form>

			<p>
				<a href="<?php echo admin_url( 'admin-post.php?action=bzpb_export_submissions' . ( isset( $_GET['status'] ) ? '&status=' . esc_attr( $_GET['status'] ) : '' ) ); ?>" class="button">
					📥 Xuất CSV
				</a>
			</p>
		</div>

		<style>
		.bzpb-submission-status {
			display: inline-block;
			padding: 2px 8px;
			border-radius: 3px;
			font-size: 11px;
			font-weight: 600;
		}
		.bzpb-submission-status.unread {
			background: #dbeafe;
			color: #1e40af;
		}
		.bzpb-submission-status.read {
			background: #d1fae5;
			color: #065f46;
		}
		</style>
		<?php
	}

	/**
	 * Render single submission detail page
	 *
	 * @param int $id Submission ID
	 */
	public static function render_detail_page( $id ) {
		$submission = BZPB_Submission_Handler::get_submission( $id );

		if ( ! $submission ) {
			wp_die( 'Submission not found' );
		}

		// Mark as read if unread
		if ( $submission->status === 'unread' ) {
			BZPB_Submission_Handler::mark_as_read( $id );
			$submission->status = 'read';
		}

		$full_data = json_decode( $submission->full_data, true );
		?>
		<div class="wrap">
			<h1>Chi tiết liên hệ #<?php echo $id; ?></h1>
			
			<p>
				<a href="?page=bzpb-submissions" class="button">← Quay lại danh sách</a>
				<a href="mailto:<?php echo esc_attr( $submission->email ); ?>?subject=Re: <?php echo esc_attr( $submission->subject ?: 'Liên hệ của bạn' ); ?>&body=Xin chào <?php echo esc_attr( $submission->name ); ?>,%0D%0A%0D%0A" class="button">📧 Trả lời</a>
				<a href="<?php echo wp_nonce_url( admin_url( 'admin-post.php?action=bzpb_delete_submission&id=' . $id ), 'bzpb-delete-' . $id ); ?>" class="button" onclick="return confirm('Bạn chắc chắn muốn xóa liên hệ này?');">🗑 Xóa</a>
			</p>

			<div style="background: #fff; padding: 20px; border: 1px solid #ddd; margin-top: 20px;">
				<table class="form-table">
					<tr>
						<th>Tên:</th>
						<td><strong><?php echo esc_html( $submission->name ); ?></strong></td>
					</tr>
					<tr>
						<th>Email:</th>
						<td><a href="mailto:<?php echo esc_attr( $submission->email ); ?>"><?php echo esc_html( $submission->email ); ?></a></td>
					</tr>
					<?php if ( $submission->phone ): ?>
					<tr>
						<th>Điện thoại:</th>
						<td><a href="tel:<?php echo esc_attr( $submission->phone ); ?>"><?php echo esc_html( $submission->phone ); ?></a></td>
					</tr>
					<?php endif; ?>
					<?php if ( $submission->subject ): ?>
					<tr>
						<th>Chủ đề:</th>
						<td><?php echo esc_html( $submission->subject ); ?></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th>Nội dung:</th>
						<td><pre style="white-space: pre-wrap; background: #f9f9f9; padding: 12px; border: 1px solid #ddd;"><?php echo esc_html( $submission->message ); ?></pre></td>
					</tr>
					<tr>
						<th>Trạng thái:</th>
						<td><span class="bzpb-submission-status <?php echo esc_attr( $submission->status ); ?>"><?php echo $submission->status === 'read' ? 'Đã đọc' : 'Chưa đọc'; ?></span></td>
					</tr>
					<tr>
						<th>Thời gian:</th>
						<td><?php echo date_i18n( 'H:i - d/m/Y', strtotime( $submission->submitted_at ) ); ?></td>
					</tr>
					<?php if ( $submission->form_title ): ?>
					<tr>
						<th>Form:</th>
						<td><?php echo esc_html( $submission->form_title ); ?> (<?php echo esc_html( $submission->source ); ?>)</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th>IP Address:</th>
						<td><code><?php echo esc_html( $submission->ip_address ); ?></code></td>
					</tr>
				</table>

				<?php if ( ! empty( $full_data ) && count( $full_data ) > 4 ): ?>
				<h3 style="margin-top: 24px;">Dữ liệu đầy đủ:</h3>
				<details>
					<summary style="cursor: pointer; user-select: none;">Xem JSON</summary>
					<pre style="background: #2d2d2d; color: #f8f8f2; padding: 16px; border-radius: 4px; overflow-x: auto; margin-top: 8px;"><?php echo esc_html( json_encode( $full_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				</details>
				<?php endif; ?>
			</div>
		</div>

		<style>
		.bzpb-submission-status {
			display: inline-block;
			padding: 4px 12px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 600;
		}
		.bzpb-submission-status.unread {
			background: #dbeafe;
			color: #1e40af;
		}
		.bzpb-submission-status.read {
			background: #d1fae5;
			color: #065f46;
		}
		</style>
		<?php
	}

	/**
	 * Handle mark as read action
	 */
	public static function handle_mark_read() {
		check_admin_referer( 'bzpb-mark-read-' . $_GET['id'] );
		
		// Validate user exists before checking capability
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$id = intval( $_GET['id'] );
		BZPB_Submission_Handler::mark_as_read( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=bzpb-submissions&marked=1' ) );
		exit;
	}

	/**
	 * Handle delete action
	 */
	public static function handle_delete() {
		check_admin_referer( 'bzpb-delete-' . $_GET['id'] );
		
		// Validate user exists before checking capability
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$id = intval( $_GET['id'] );
		BZPB_Submission_Handler::delete_submission( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=bzpb-submissions&deleted=1' ) );
		exit;
	}

	/**
	 * Handle CSV export
	 */
	public static function handle_export_csv() {
		// Validate user exists before checking capability
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! get_userdata( $user_id ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$args = [
			'limit'  => 999999,
			'offset' => 0,
		];

		if ( isset( $_GET['status'] ) ) {
			$args['status'] = sanitize_text_field( $_GET['status'] );
		}

		$submissions = BZPB_Submission_Handler::get_submissions( $args );

		// Set headers for CSV download
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="submissions-' . date( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		
		// BOM for Excel UTF-8
		fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );

		// Headers
		fputcsv( $output, [ 'ID', 'Tên', 'Email', 'Điện thoại', 'Chủ đề', 'Nội dung', 'Trạng thái', 'Form', 'IP', 'Thời gian' ] );

		// Data
		foreach ( $submissions as $sub ) {
			fputcsv( $output, [
				$sub->id,
				$sub->name,
				$sub->email,
				$sub->phone,
				$sub->subject,
				$sub->message,
				$sub->status === 'read' ? 'Đã đọc' : 'Chưa đọc',
				$sub->form_title ?: $sub->source,
				$sub->ip_address,
				$sub->submitted_at,
			] );
		}

		fclose( $output );
		exit;
	}
}

/**
 * Submissions List Table
 */
class BZPB_Submissions_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'submission',
			'plural'   => 'submissions',
			'ajax'     => false,
		] );
	}

	public function get_columns() {
		return [
			'cb'           => '<input type="checkbox" />',
			'name'         => 'Tên',
			'email'        => 'Email',
			'message'      => 'Nội dung',
			'status'       => 'Trạng thái',
			'submitted_at' => 'Thời gian',
		];
	}

	public function get_sortable_columns() {
		return [
			'name'         => [ 'name', false ],
			'email'        => [ 'email', false ],
			'submitted_at' => [ 'submitted_at', true ], // true = default sort
		];
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', $item->id );
	}

	public function column_name( $item ) {
		$view_url = admin_url( 'admin.php?page=bzpb-submissions&action=view&id=' . $item->id );
		$title = '<strong><a href="' . esc_url( $view_url ) . '">' . esc_html( $item->name ) . '</a></strong>';
		
		if ( $item->phone ) {
			$title .= '<br><small style="color: #666;">📞 ' . esc_html( $item->phone ) . '</small>';
		}

		return $title;
	}

	public function column_email( $item ) {
		return '<a href="mailto:' . esc_attr( $item->email ) . '">' . esc_html( $item->email ) . '</a>';
	}

	public function column_message( $item ) {
		$message = mb_strlen( $item->message ) > 100 
			? mb_substr( $item->message, 0, 100 ) . '...' 
			: $item->message;
		return '<span style="color: #666;">' . esc_html( $message ) . '</span>';
	}

	public function column_status( $item ) {
		$class = $item->status === 'read' ? 'read' : 'unread';
		$label = $item->status === 'read' ? 'Đã đọc' : 'Chưa đọc';
		return '<span class="bzpb-submission-status ' . $class . '">' . $label . '</span>';
	}

	public function column_submitted_at( $item ) {
		return date_i18n( 'H:i - d/m/Y', strtotime( $item->submitted_at ) );
	}

	public function prepare_items() {
		$per_page = 20;
		$current_page = $this->get_pagenum();

		$args = [
			'limit'  => $per_page,
			'offset' => ( $current_page - 1 ) * $per_page,
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'submitted_at',
			'order'   => isset( $_GET['order'] ) ? sanitize_text_field( $_GET['order'] ) : 'DESC',
		];

		if ( isset( $_GET['status'] ) ) {
			$args['status'] = sanitize_text_field( $_GET['status'] );
		}

		if ( isset( $_GET['s'] ) ) {
			$args['search'] = sanitize_text_field( $_GET['s'] );
		}

		$this->items = BZPB_Submission_Handler::get_submissions( $args );
		$total_items = BZPB_Submission_Handler::get_count( $args );

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}
}
