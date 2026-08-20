<?php
/**
 * CF7 BigLead integration.
 *
 * Sends mapped Contact Form 7 submissions to the BigLead customer endpoint.
 * Credentials are configured from the admin page and the bearer token is
 * encrypted before it is stored in the WordPress options table.
 *
 * @package BizCity_Channel_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CF7_BigLead' ) ) {
	return;
}

class BizCity_CF7_BigLead {

	const OPTION_KEY = 'bizcity_cg_cf7_biglead';
	const ENDPOINT   = 'https://partner.shopf1.net/customer';
	const TIMEOUT    = 12;

	/**
	 * Register the small admin page.
	 */
	public static function init(): void {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — register BigLead admin page.
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 99 );
		add_action( 'admin_post_bizcity_cf7_biglead_save', array( __CLASS__, 'handle_admin_save' ) );
	}

	/**
	 * Add BigLead as a small submenu under Channel Gateway.
	 */
	public static function register_admin_page(): void {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — register submenu when admin menus load.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_submenu_page(
			'bizchat-gateway',
			'BigLead · CF7',
			'BigLead · CF7',
			'manage_options',
			'bizcity-cf7-biglead',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render settings and per-form switches.
	 */
	public static function render_admin_page(): void {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — render global and per-form settings.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'bizcity-twin-ai' ) );
		}

		$settings = self::get_settings();
		$forms    = self::get_cf7_forms();
		?>
		<div class="wrap">
			<h1>BigLead · CF7</h1>
			<p>Gửi lead từ Contact Form 7 sang BigLead sau mỗi lần submit. Endpoint cố định: <code><?php echo esc_html( self::ENDPOINT ); ?></code></p>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Đã lưu cấu hình BigLead.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bizcity_cf7_biglead_save" />
				<?php wp_nonce_field( 'bizcity_cf7_biglead_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Bật gửi BigLead</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> /> Gửi các form đã bật bên dưới</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="biglead-token">Bearer token</label></th>
						<td>
							<input id="biglead-token" type="password" class="regular-text" name="token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $settings['token_present'] ? 'Đã lưu · nhập token mới để thay thế' : 'Dán token BigLead tại đây' ); ?>" />
							<p class="description">Token không được hiển thị lại và không ghi vào log. Để trống nếu giữ token hiện tại.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="biglead-shop-id">Shop ID</label></th>
						<td><input id="biglead-shop-id" type="text" class="regular-text" name="shop_id" value="<?php echo esc_attr( $settings['shop_id'] ); ?>" placeholder="136680" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="biglead-page-id">Page ID</label></th>
						<td><input id="biglead-page-id" type="text" class="regular-text" name="page_id" value="<?php echo esc_attr( $settings['page_id'] ); ?>" placeholder="landingPage@hismartmilkvietnam" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="biglead-tag-id">Tag ID mặc định</label></th>
						<td><input id="biglead-tag-id" type="text" class="regular-text" name="tag_id" value="<?php echo esc_attr( $settings['tag_id'] ); ?>" placeholder="ID thẻ trên BigLead" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="biglead-add-tags">Add tags mặc định</label></th>
						<td><input id="biglead-add-tags" type="text" class="regular-text" name="add_tags" value="<?php echo esc_attr( implode( ', ', $settings['add_tags'] ) ); ?>" placeholder="1, 2, 3" /><p class="description">Nhập các tag ID cách nhau bằng dấu phẩy. Tag ID riêng của form sẽ được ưu tiên.</p></td>
					</tr>
				</table>

			<h2>Form CF7</h2>
			<p>Chỉ các form được bật mới gửi sang BigLead. Tag ID và add tags để trống sẽ dùng giá trị mặc định ở trên.</p>
			<table class="widefat striped" style="max-width: 980px">
				<thead><tr><th>Bật</th><th>Form</th><th>Tag ID riêng</th><th>Add tags riêng</th></tr></thead>
				<tbody>
				<?php if ( empty( $forms ) ) : ?>
					<tr><td colspan="4">Chưa tìm thấy Contact Form 7.</td></tr>
				<?php else : ?>
					<?php foreach ( $forms as $form ) : ?>
						<?php $form_cfg = self::get_form_settings( $form['id'], $settings ); ?>
						<tr>
							<td><input type="checkbox" name="forms[<?php echo (int) $form['id']; ?>][enabled]" value="1" <?php checked( ! empty( $form_cfg['enabled'] ) ); ?> /></td>
							<td><strong><?php echo esc_html( $form['title'] ); ?></strong> <code>#<?php echo (int) $form['id']; ?></code></td>
							<td><input type="text" name="forms[<?php echo (int) $form['id']; ?>][tag_id]" value="<?php echo esc_attr( $form_cfg['tag_id'] ); ?>" placeholder="Mặc định" /></td>
							<td><input type="text" name="forms[<?php echo (int) $form['id']; ?>][add_tags]" value="<?php echo esc_attr( implode( ', ', $form_cfg['add_tags'] ) ); ?>" placeholder="Mặc định" /></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php submit_button( 'Lưu cấu hình BigLead' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the settings form without exposing the token in the URL.
	 */
	public static function handle_admin_save(): void {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — persist sanitized settings.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'bizcity-twin-ai' ) );
		}
		check_admin_referer( 'bizcity_cf7_biglead_save' );
		$input = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		self::save_settings( $input );

		wp_safe_redirect( add_query_arg( array( 'page' => 'bizcity-cf7-biglead', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Return settings safe for an admin UI. The bearer token is never returned.
	 */
	public static function get_settings_safe(): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — expose masked settings to SPA.
		$settings = self::get_settings();
		return array(
			'enabled'       => ! empty( $settings['enabled'] ),
			'token_present' => ! empty( $settings['token_present'] ),
			'shop_id'       => (string) $settings['shop_id'],
			'page_id'       => (string) $settings['page_id'],
			'tag_id'        => (string) $settings['tag_id'],
			'add_tags'      => array_values( (array) $settings['add_tags'] ),
			'forms'         => is_array( $settings['forms'] ) ? $settings['forms'] : array(),
		);
	}

	/**
	 * Save settings received from the admin page or REST API.
	 */
	public static function save_settings( array $input ): void {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — centralize sanitized settings writes.
		$current   = self::get_settings();
		$token     = sanitize_text_field( (string) ( $input['token'] ?? '' ) );
		$raw_forms = isset( $input['forms'] ) && is_array( $input['forms'] ) ? $input['forms'] : array();
		$forms   = array();
		foreach ( $raw_forms as $form_id => $form_cfg ) {
			$form_id = (int) $form_id;
			if ( $form_id < 1 || ! is_array( $form_cfg ) ) {
				continue;
			}
			$forms[ (string) $form_id ] = array(
				'enabled' => ! empty( $form_cfg['enabled'] ),
				'tag_id'  => sanitize_text_field( (string) ( $form_cfg['tag_id'] ?? '' ) ),
				'add_tags'=> self::parse_tag_ids( $form_cfg['add_tags'] ?? '' ),
			);
		}

		$next = array(
			'enabled'    => ! empty( $input['enabled'] ),
			'token_enc'  => $token !== '' ? self::encrypt( $token ) : (string) ( $current['token_enc'] ?? '' ),
			'shop_id'    => sanitize_text_field( (string) ( $input['shop_id'] ?? '' ) ),
			'page_id'    => sanitize_text_field( (string) ( $input['page_id'] ?? '' ) ),
			'tag_id'     => sanitize_text_field( (string) ( $input['tag_id'] ?? '' ) ),
			'add_tags'   => self::parse_tag_ids( $input['add_tags'] ?? '' ),
			'forms'      => $forms,
			'updated_at' => current_time( 'c' ),
		);
		update_option( self::OPTION_KEY, $next, false );
	}

	/**
	 * Check BigLead reachability without creating a customer.
	 *
	 * HEAD is intentionally used instead of POST /customer. Some servers may
	 * answer 405 for HEAD; that still proves DNS/TLS/HTTP reachability.
	 *
	 * @return array {ok: bool, reachable: bool, accepted: bool, configured: bool, http_code: int, latency_ms: int, message: string}
	 */
	public static function ping(): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — non-mutating connectivity ping with safe response diagnostics.
		$started = microtime( true );
		$settings = self::get_settings();
		$result = array(
			'success'     => false,
			'ok'         => false,
			'reachable'  => false,
			'accepted'   => false,
			'configured' => ! empty( $settings['token'] ) && ! empty( $settings['shop_id'] ) && ! empty( $settings['page_id'] ),
			'http_code'  => 0,
			'latency_ms' => 0,
			'code'        => 'biglead_ping_failed',
			'message'    => '',
			'hint'        => 'Kiểm tra Bearer token, Shop ID, Page ID và kết nối máy chủ.',
			'help_code'   => 'invalid_param_generic',
			'response_json' => null,
			'response_text' => '',
		);

		if ( ! $result['configured'] ) {
			$result['code'] = 'biglead_config_missing';
			$result['message'] = 'Thiếu token, Shop ID hoặc Page ID.';
			$result['hint'] = 'Lưu đầy đủ Bearer token, Shop ID và Page ID rồi ping lại.';
			self::log( 'biglead_ping_skipped', 'warn', 'BigLead ping skipped because configuration is incomplete.', 0, array( 'configured' => false ) );
			return $result;
		}

		try {
			// [2026-08-04 Johnny Chu] R-CH-FILE-LOG — evidence before external HTTP.
			self::log( 'biglead_ping_attempt', 'info', 'BigLead connectivity ping started.', 0, array( 'method' => 'HEAD' ) );
			$response = wp_remote_head( self::ENDPOINT, array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $settings['token'],
					'shopid'        => (string) $settings['shop_id'],
					'pageid'        => (string) $settings['page_id'],
				),
			) );
			$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$result['message'] = 'Không kết nối được tới BigLead.';
				$result['response_text'] = self::truncate_response_text( $response->get_error_message() );
				self::log( 'biglead_ping_failed', 'error', 'BigLead connectivity ping failed.', 0, array( 'latency_ms' => $result['latency_ms'], 'error_present' => true ) );
				return $result;
			}

			$result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
			$parsed = self::parse_response_body( wp_remote_retrieve_body( $response ) );
			$result['response_json'] = $parsed['json'];
			$result['response_text'] = $parsed['text'];
			$result['reachable'] = $result['http_code'] > 0;
			$result['accepted']  = $result['http_code'] >= 200 && $result['http_code'] < 300;
			$result['success']   = $result['accepted'];
			$result['ok']        = $result['accepted'];
			$result['code']      = $result['accepted'] ? 'biglead_ping_success' : ( $result['reachable'] ? 'biglead_ping_reachable' : 'biglead_ping_failed' );
			$result['message']    = $result['accepted']
				? 'BigLead đã phản hồi HEAD thành công.'
				: 'Endpoint đã phản hồi HTTP ' . $result['http_code'] . '. HEAD không tạo customer; mã 405 vẫn xác nhận server reachable.';
			self::log( $result['reachable'] ? 'biglead_ping_ok' : 'biglead_ping_failed', $result['reachable'] ? 'info' : 'error', 'BigLead connectivity ping completed.', 0, array(
				'http_code'  => $result['http_code'],
				'latency_ms' => $result['latency_ms'],
				'reachable'  => $result['reachable'],
				'accepted'   => $result['accepted'],
			) );
		} catch ( \Throwable $e ) {
			$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			$result['message'] = 'Ping BigLead gặp lỗi hệ thống.';
			$result['response_text'] = self::truncate_response_text( $e->getMessage() );
			self::log( 'biglead_ping_exception', 'error', 'BigLead connectivity ping raised an exception.', 0, array( 'latency_ms' => $result['latency_ms'], 'exception_class' => get_class( $e ) ) );
		}

		return $result;
	}

	/**
	 * Send a real test customer and return BigLead's safe JSON response.
	 *
	 * This bypasses the global/per-form enabled switches intentionally so an
	 * administrator can validate credentials before enabling automatic sync.
	 *
	 * @param array $input Test lead fields.
	 * @return array
	 */
	public static function test( array $input ): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — submit an explicit admin test and return provider JSON.
		$started  = microtime( true );
		$settings = self::get_settings();
		$result   = array(
			'success'       => false,
			'sent'          => false,
			'configured'    => ! empty( $settings['token'] ) && ! empty( $settings['shop_id'] ) && ! empty( $settings['page_id'] ),
			'http_code'     => 0,
			'latency_ms'    => 0,
			'code'          => 'biglead_test_failed',
			'message'       => 'BigLead chưa nhận lead test.',
			'hint'          => 'Kiểm tra Bearer token, Shop ID, Page ID và dữ liệu thử.',
			'help_code'     => 'invalid_param_generic',
			'response_json' => null,
			'response_text' => '',
		);

		if ( ! $result['configured'] ) {
			$result['code']    = 'biglead_config_missing';
			$result['message'] = 'Thiếu token, Shop ID hoặc Page ID.';
			$result['hint']    = 'Lưu đầy đủ cấu hình BigLead trước khi gửi lead test.';
			self::log( 'biglead_test_skipped', 'warn', 'BigLead test skipped because configuration is incomplete.', 0, array( 'configured' => false ) );
			return $result;
		}

		$phone = preg_replace( '/[^0-9+]/', '', (string) ( $input['phone'] ?? '' ) );
		$phone = substr( (string) $phone, 0, 32 );
		if ( $phone === '' ) {
			$result['code']    = 'invalid_param';
			$result['message'] = 'Số điện thoại test đang để trống.';
			$result['hint']    = 'Nhập số điện thoại test để BigLead tạo hoặc cập nhật customer.';
			return $result;
		}

		$payload = array(
			'add_tags'            => array_values( (array) $settings['add_tags'] ),
			'email'               => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'full_name'           => sanitize_text_field( (string) ( $input['full_name'] ?? '' ) ),
			'phone'               => $phone,
			'note'                => sanitize_text_field( (string) ( $input['note'] ?? '' ) ),
			'update_by_extra_key' => 'phone',
			'extra_data'          => array(
				'bizcity_test'    => 1,
				'tested_at_gmt'   => current_time( 'mysql', true ),
			),
		);
		$payload = array_filter( $payload, static function ( $value, $key ) {
			return $key === 'add_tags' || $key === 'extra_data' || $value !== '' && $value !== null;
		}, ARRAY_FILTER_USE_BOTH );

		$headers = array(
			'Authorization' => 'Bearer ' . $settings['token'],
			'shopid'        => (string) $settings['shop_id'],
			'pageid'        => (string) $settings['page_id'],
			'Content-Type'  => 'application/json',
		);
		if ( $settings['tag_id'] !== '' ) {
			$headers['tagid'] = (string) $settings['tag_id'];
		}

		// [2026-08-04 Johnny Chu] R-CH-FILE-LOG — evidence before the real test POST.
		self::log( 'biglead_test_attempt', 'info', 'Sending an explicit test customer to BigLead.', 0, array(
			'has_email' => ! empty( $payload['email'] ),
			'has_phone' => true,
		) );

		try {
			$response = wp_remote_post( self::ENDPOINT, array(
				'timeout'     => self::TIMEOUT,
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			) );
			$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$result['message']       = 'Không gửi được lead test tới BigLead.';
				$result['response_text'] = self::truncate_response_text( $response->get_error_message() );
				self::log( 'biglead_test_failed', 'error', 'BigLead test HTTP request failed.', 0, array( 'latency_ms' => $result['latency_ms'], 'error_present' => true ) );
				return $result;
			}

			$result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
			$parsed = self::parse_response_body( wp_remote_retrieve_body( $response ) );
			$result['response_json'] = $parsed['json'];
			$result['response_text'] = $parsed['text'];
			$result['sent'] = self::provider_response_is_success( $result['http_code'], $parsed['json'] );
			$result['success'] = $result['sent'];
			$result['code'] = $result['sent'] ? 'biglead_test_success' : 'biglead_test_failed';
			$result['message'] = $result['sent'] ? 'BigLead đã nhận lead test.' : 'BigLead từ chối lead test.';

			self::log( $result['sent'] ? 'biglead_test_ok' : 'biglead_test_failed', $result['sent'] ? 'info' : 'error', 'BigLead test completed.', 0, array(
				'http_code'  => $result['http_code'],
				'latency_ms' => $result['latency_ms'],
				'accepted'   => $result['sent'],
				'body_len'   => strlen( (string) wp_remote_retrieve_body( $response ) ),
			) );
		} catch ( \Throwable $e ) {
			$result['latency_ms']    = (int) round( ( microtime( true ) - $started ) * 1000 );
			$result['message']       = 'Gửi lead test gặp lỗi hệ thống.';
			$result['response_text'] = self::truncate_response_text( $e->getMessage() );
			self::log( 'biglead_test_exception', 'error', 'BigLead test raised an exception.', 0, array( 'latency_ms' => $result['latency_ms'], 'exception_class' => get_class( $e ) ) );
		}

		return $result;
	}

	/**
	 * Send one CF7 submission to BigLead.
	 *
	 * @param int    $form_id
	 * @param string $form_title
	 * @param array  $mapped
	 * @param array  $posted
	 * @return array {sent: bool, http_code: int, error: string, response_json: mixed, response_text: string}
	 */
	public static function dispatch( int $form_id, string $form_title, array $mapped, array $posted = array() ): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — send one configured CF7 lead.
		$result = array( 'sent' => false, 'http_code' => 0, 'error' => '', 'response_json' => null, 'response_text' => '', 'latency_ms' => 0 );
		$settings = self::get_settings();
		$form_cfg = self::get_form_settings( $form_id, $settings );

		if ( empty( $settings['enabled'] ) || empty( $form_cfg['enabled'] ) ) {
			// [2026-08-05 Johnny Chu] PHASE-CG-CF7-BIGLEAD — expose the exact enable gate that skipped automatic dispatch.
			$reason = empty( $settings['enabled'] ) ? 'global_disabled' : 'form_disabled';
			$result['code']  = 'biglead_' . $reason;
			$result['error'] = $reason === 'global_disabled'
				? 'BigLead automatic dispatch is disabled globally.'
				: 'BigLead automatic dispatch is disabled for this form.';
			self::log( 'biglead_dispatch_skipped', 'info', 'BigLead automatic dispatch skipped by enable settings.', $form_id, array(
				'reason'         => $reason,
				'global_enabled' => ! empty( $settings['enabled'] ),
				'form_enabled'   => ! empty( $form_cfg['enabled'] ),
			) );
			return $result;
		}
		if ( empty( $settings['token'] ) || empty( $settings['shop_id'] ) || empty( $settings['page_id'] ) ) {
			self::log( 'biglead_dispatch_skipped', 'warn', 'BigLead is enabled but credentials are incomplete.', $form_id, array( 'form_title' => $form_title ) );
			return $result;
		}

		$payload = self::build_payload( $mapped, $posted, $form_id, $form_title, $form_cfg['add_tags'] ?: $settings['add_tags'] );
		$headers = array(
			'Authorization' => 'Bearer ' . $settings['token'],
			'shopid'        => (string) $settings['shop_id'],
			'pageid'        => (string) $settings['page_id'],
			'Content-Type'  => 'application/json',
		);
		$tag_id = $form_cfg['tag_id'] ?: $settings['tag_id'];
		if ( $tag_id !== '' ) {
			$headers['tagid'] = $tag_id;
		}

		// [2026-08-04 Johnny Chu] R-CH-FILE-LOG — write evidence before external HTTP.
		self::log( 'biglead_send_attempt', 'info', 'Sending CF7 customer to BigLead.', $form_id, array(
			'form_title'       => $form_title,
			'has_email'        => ! empty( $payload['email'] ),
			'has_phone'        => ! empty( $payload['phone'] ),
			'extra_data_count' => count( $payload['extra_data'] ),
			'add_tags_count'   => count( $payload['add_tags'] ),
		) );

		try {
			$started = microtime( true );
			$response = wp_remote_post( self::ENDPOINT, array(
				'timeout'     => self::TIMEOUT,
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			) );
			$result['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
				self::log( 'biglead_send_failed', 'error', 'BigLead HTTP request failed.', $form_id, array( 'error_present' => true ) );
				return $result;
			}

			$result['http_code'] = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			$parsed = self::parse_response_body( $body );
			$result['response_json'] = $parsed['json'];
			$result['response_text'] = $parsed['text'];
			$result['sent'] = self::provider_response_is_success( $result['http_code'], $parsed['json'] );
			if ( ! $result['sent'] ) {
				$result['error'] = 'BigLead returned HTTP ' . $result['http_code'];
			}
			self::log( $result['sent'] ? 'biglead_send_ok' : 'biglead_send_failed', $result['sent'] ? 'info' : 'error', $result['sent'] ? 'BigLead customer sent successfully.' : 'BigLead rejected the customer.', $form_id, array(
				'http_code' => $result['http_code'],
				'latency_ms'=> $result['latency_ms'],
				'body_len'  => strlen( (string) $body ),
			) );
		} catch ( \Throwable $e ) {
			$result['error'] = $e->getMessage();
			self::log( 'biglead_send_exception', 'error', 'BigLead dispatch raised an exception.', $form_id, array( 'exception_class' => get_class( $e ) ) );
		}

		return $result;
	}

	/**
	 * Simulate or send a lead using the selected CF7 form's mapped data.
	 *
	 * @param int    $form_id
	 * @param string $form_title
	 * @param array  $mapped
	 * @param array  $posted
	 * @param bool   $send
	 * @return array
	 */
	public static function playground( int $form_id, string $form_title, array $mapped, array $posted, bool $send = false ): array {
		// [2026-08-05 Johnny Chu] PHASE-CG-CF7-BIGLEAD — run exact CF7 mapping and dispatch logic from the admin playground.
		$settings = self::get_settings();
		$form_cfg = self::get_form_settings( $form_id, $settings );
		$payload   = self::build_payload( $mapped, $posted, $form_id, $form_title, $form_cfg['add_tags'] ?: $settings['add_tags'] );
		$gate      = array(
			'global_enabled' => ! empty( $settings['enabled'] ),
			'form_enabled'   => ! empty( $form_cfg['enabled'] ),
			'configured'     => ! empty( $settings['token'] ) && ! empty( $settings['shop_id'] ) && ! empty( $settings['page_id'] ),
		);
		$result = array(
			'success'   => true,
			'sent'      => false,
			'code'      => 'biglead_playground_preview',
			'message'   => 'Đã dựng payload theo mapping của form, chưa gửi tới BigLead.',
			'http_code' => 0,
			'error'     => '',
		);

		if ( $send ) {
			// [2026-08-05 Johnny Chu] R-CH-FILE-LOG — send mode delegates to the same production dispatch path.
			$result = self::dispatch( $form_id, $form_title, $mapped, $posted );
		}

		return array(
			'mode'    => $send ? 'send' : 'preview',
			'gate'    => $gate,
			'payload' => $payload,
			'result'  => $result,
		);
	}

	/**
	 * Build the BigLead body from mapped CF7 data and preserve unmapped values.
	 */
	public static function build_payload( array $mapped, array $posted, int $form_id, string $form_title, array $add_tags = array() ): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — translate CF7 data to BigLead schema.
		$name = self::value( $mapped, 'name' );
		if ( $name === '' ) {
			$name = trim( self::value( $mapped, 'first_name' ) . ' ' . self::value( $mapped, 'last_name' ) );
		}
		$payload = array(
			'add_tags'            => array_values( array_map( 'intval', $add_tags ) ),
			'email'               => sanitize_email( self::value( $mapped, 'email' ) ),
			'full_name'           => sanitize_text_field( $name ),
			'phone'               => sanitize_text_field( self::value( $mapped, 'phone' ) ),
			'address'             => sanitize_text_field( self::value( $mapped, 'address' ) ),
			'address_full'        => sanitize_text_field( self::value( $mapped, 'address_full' ) ),
			'birth'               => sanitize_text_field( self::value( $mapped, 'birth' ) ),
			'last_care'           => self::numeric_or_empty( self::value( $mapped, 'last_care' ) ),
			'note'                => sanitize_text_field( self::value( $mapped, 'note' ) ?: self::value( $mapped, 'additional_attributes.message' ) ),
			'sex'                 => self::numeric_or_empty( self::value( $mapped, 'sex' ) ),
			'update_by_extra_key' => 'phone',
			'extra_data'          => array(
				'cf7_form_id'    => $form_id,
				'cf7_form_title' => $form_title,
			),
		);

		$known = array( 'email', 'name', 'first_name', 'last_name', 'phone', 'address', 'address_full', 'birth', 'last_care', 'note', 'sex' );
		foreach ( $mapped as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, $known, true ) || $key === 'update_by_extra_key' ) {
				continue;
			}
			$extra_key = strpos( $key, 'additional_attributes.' ) === 0 ? substr( $key, 22 ) : $key;
			if ( $extra_key !== '' ) {
				$payload['extra_data'][ sanitize_key( $extra_key ) ] = self::scalar_value( $value );
			}
		}
		foreach ( $posted as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' || isset( $payload['extra_data'][ $key ] ) ) {
				continue;
			}
			$payload['extra_data'][ $key ] = self::scalar_value( $value );
		}

		return array_filter( $payload, static function ( $value, $key ) {
			return $key === 'add_tags' || $key === 'extra_data' || $value !== '' && $value !== null;
		}, ARRAY_FILTER_USE_BOTH );
	}

	public static function get_settings(): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — read decrypted runtime settings.
		$raw = get_option( self::OPTION_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'token'        => self::decrypt( (string) ( $raw['token_enc'] ?? '' ) ),
			'token_enc'    => (string) ( $raw['token_enc'] ?? '' ),
			'token_present'=> ! empty( $raw['token_enc'] ),
			'shop_id'      => (string) ( $raw['shop_id'] ?? '' ),
			'page_id'      => (string) ( $raw['page_id'] ?? '' ),
			'tag_id'       => (string) ( $raw['tag_id'] ?? '' ),
			'add_tags'     => self::parse_tag_ids( $raw['add_tags'] ?? array() ),
			'forms'        => is_array( $raw['forms'] ?? null ) ? $raw['forms'] : array(),
		);
	}

	private static function get_form_settings( int $form_id, array $settings ): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — resolve per-form overrides.
		$cfg = is_array( $settings['forms'][ (string) $form_id ] ?? null ) ? $settings['forms'][ (string) $form_id ] : array();
		return array(
			'enabled' => ! empty( $cfg['enabled'] ),
			'tag_id'  => sanitize_text_field( (string) ( $cfg['tag_id'] ?? '' ) ),
			'add_tags'=> self::parse_tag_ids( $cfg['add_tags'] ?? array() ),
		);
	}

	private static function get_cf7_forms(): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — list published CF7 forms for admin config.
		$posts = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => 100, 'post_status' => 'publish' ) );
		$forms = array();
		foreach ( (array) $posts as $post ) {
			$forms[] = array( 'id' => (int) $post->ID, 'title' => (string) $post->post_title );
		}
		return $forms;
	}

	private static function parse_tag_ids( $raw ): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — normalize comma-separated tag IDs.
		$values = is_array( $raw ) ? $raw : preg_split( '/[,\s]+/', (string) $raw );
		$out = array();
		foreach ( (array) $values as $value ) {
			$value = (int) $value;
			if ( $value > 0 ) {
				$out[ $value ] = $value;
			}
		}
		return array_values( $out );
	}

	private static function value( array $mapped, string $key ): string {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — resolve flat and mapped attribute paths.
		if ( isset( $mapped[ $key ] ) ) {
			return self::scalar_value( $mapped[ $key ] );
		}
		if ( strpos( $key, 'additional_attributes.' ) !== 0 ) {
			$nested = 'additional_attributes.' . $key;
			if ( isset( $mapped[ $nested ] ) ) {
				return self::scalar_value( $mapped[ $nested ] );
			}
		}
		return '';
	}

	private static function scalar_value( $value ): string {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — flatten CF7 checkbox values.
		return is_array( $value )
			? implode( ', ', array_map( static function ( $item ) { return sanitize_text_field( (string) $item ); }, $value ) )
			: sanitize_text_field( (string) $value );
	}

	private static function numeric_or_empty( string $value ) {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — keep optional numeric fields empty-safe.
		return $value === '' ? '' : (int) $value;
	}

	/**
	 * Decode and redact a provider response before returning it to admin UI.
	 *
	 * @param string $body Raw response body.
	 * @return array {json: mixed, text: string}
	 */
	private static function parse_response_body( string $body ): array {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — parse provider JSON without exposing credential-like fields.
		$body = self::truncate_response_text( $body );
		if ( $body === '' ) {
			return array( 'json' => null, 'text' => '' );
		}
		$decoded = json_decode( $body, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return array( 'json' => self::redact_response_value( $decoded ), 'text' => '' );
		}
		return array( 'json' => null, 'text' => sanitize_textarea_field( $body ) );
	}

	/**
	 * Determine success from HTTP plus explicit top-level provider flags.
	 *
	 * @param int   $http_code HTTP status.
	 * @param mixed $json      Decoded response.
	 */
	private static function provider_response_is_success( int $http_code, $json ): bool {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — reject explicit false/error JSON even when upstream returns 2xx.
		if ( $http_code < 200 || $http_code >= 300 ) {
			return false;
		}
		if ( ! is_array( $json ) ) {
			return true;
		}
		foreach ( array( 'success', 'status', 'ok' ) as $key ) {
			if ( ! array_key_exists( $key, $json ) ) {
				continue;
			}
			$value = $json[ $key ];
			// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — avoid scalar casts when providers return nested status metadata.
			if ( false === $value || 0 === $value ) {
				return false;
			}
			if ( is_string( $value ) && in_array( strtolower( $value ), array( 'false', 'failed', 'error' ), true ) ) {
				return false;
			}
		}
		return empty( $json['error'] );
	}

	/**
	 * Redact secret-looking response fields recursively.
	 *
	 * @param mixed $value Provider response value.
	 * @param int   $depth Recursion depth.
	 * @return mixed
	 */
	private static function redact_response_value( $value, int $depth = 0 ) {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — prevent provider credentials from reaching UI diagnostics.
		if ( $depth > 8 ) {
			return '[truncated]';
		}
		if ( ! is_array( $value ) ) {
			return is_string( $value ) ? self::truncate_response_text( $value, 4000 ) : $value;
		}
		$out = array();
		$count = 0;
		foreach ( $value as $key => $item ) {
			if ( ++$count > 100 ) {
				$out['_truncated'] = true;
				break;
			}
			$key_text = strtolower( (string) $key );
			if ( preg_match( '/token|authorization|password|secret|api[_-]?key/', $key_text ) ) {
				$out[ $key ] = '[redacted]';
				continue;
			}
			$out[ $key ] = self::redact_response_value( $item, $depth + 1 );
		}
		return $out;
	}

	/**
	 * Bound provider output included in an admin-only REST response.
	 */
	private static function truncate_response_text( string $text, int $max_len = 20000 ): string {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — cap diagnostic response size.
		$text = trim( $text );
		return strlen( $text ) > $max_len ? substr( $text, 0, $max_len ) . '...' : $text;
	}

	private static function log( string $event, string $level, string $message, int $form_id, array $context = array() ): void {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — write operational evidence without secrets.
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			$context['form_id'] = $form_id;
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CF7, $level, $event, $message, $context );
		} elseif ( class_exists( 'BizCity_JSONL_File_Logger', false ) ) {
			$context['form_id'] = $form_id;
			BizCity_JSONL_File_Logger::write( BizCity_JSONL_File_Logger::CRM_FOLDER, 'cf7', $level, $event, $message, $context );
		}
	}

	private static function encrypt( string $plain ): string {
		// [2026-08-04 Johnny Chu] PHASE-CG-CF7-BIGLEAD — encrypt bearer token at rest.
		if ( $plain === '' ) {
			return '';
		}
		$key = substr( hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' ), true ), 0, 32 );
		$iv  = openssl_random_pseudo_bytes( 16 );
		// [2026-08-20 Johnny Chu] CODEC-CORE — delegate legacy BigLead raw encryption.
		return BizCity_Codec::encrypt_raw_payload( $plain, $key, $iv );
	}

	private static function decrypt( string $encoded ): string {
		// [2026-08-04 Johnny Chu] PHASE-CG-BIGLEAD — decrypt bearer token for runtime only.
		if ( $encoded === '' ) {
			return $encoded;
		}
		$key = substr( hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' ), true ), 0, 32 );
		// [2026-08-20 Johnny Chu] CODEC-CORE — delegate legacy BigLead raw decryption.
		$plain = BizCity_Codec::decrypt_raw_payload( $encoded, $key );
		return '' === $plain ? $encoded : $plain;
	}
}