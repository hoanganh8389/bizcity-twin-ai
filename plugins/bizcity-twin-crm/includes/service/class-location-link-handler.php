<?php
/**
 * BizCity CRM — public "send your location" landing page (PHASE-0.69 §4.4b, WP-L-05).
 *
 * A distinct query var (`bzloc`, never `bzzalolink`/`zid`) so this never competes with
 * `BizCity_CRM_Magic_Link_Handler`'s WP-user-login flow — this page needs NO login (0.69: *"trang công
 * khai — không cần đăng nhập"*) and never binds a `wp_user_id`, it only records GPS against the
 * `staff_user_id` baked into the token's `meta_json` at issue time (`class-service-rest.php::request_location()`).
 *
 * Security property that must hold (0.69 §4.4b, "chỗ dễ sai nhất"): a browser carries no chat_id, so on
 * an expired/consumed token this handler can never *show* a fresh link — it can only *push* one into the
 * Zalo chat the original token was already bound to. `resend()` is rate-limited per chat_id so an F5 loop
 * on a dead link cannot be turned into a Zalo spam vector.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-23 (PHASE-0.69 WP-L-05)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Location_Link_Handler', false ) ) {
	return;
}

final class BizCity_CRM_Location_Link_Handler {

	const QUERY_VAR = 'bzloc';
	const INTENT = 'location';
	const RESEND_LIMIT = 3;
	const RESEND_WINDOW = 600; // 10 minutes (0.69 §4.4b L-05e).

	public static function register(): void {
		static $registered = false;
		if ( $registered ) { return; }
		$registered = true;
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), -100 );
	}

	public static function maybe_handle(): void {
		static $handled = false;
		if ( $handled ) { return; }
		if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { return; }
		$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		if ( '' === $token || ! class_exists( 'BizCity_CRM_Magic_Link' ) ) { return; }
		$handled = true;
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );

		$result = BizCity_CRM_Magic_Link::verify( $token );
		if ( is_wp_error( $result ) || self::INTENT !== (string) ( $result['intent'] ?? '' ) ) {
			self::handle_unusable( $token );
			exit;
		}
		self::render_form( $result, $token );
		exit;
	}

	/** Expired, already consumed, or simply not a location token — never reveal which. */
	private static function handle_unusable( string $token ): void {
		$row = self::row_for_token( $token );
		if ( is_array( $row ) && self::INTENT === (string) ( $row['intent'] ?? '' ) ) {
			self::maybe_resend( $row );
		}
		// [0.69 §4.4b] Deliberately generic — this page must look identical whether the token belonged to
		// this visitor, a stranger, or nothing at all. Only the chat already bound to the token (if any)
		// ever receives the new link.
		self::render_page( 'Link đã hết hạn hoặc đã dùng. Nếu đúng là bạn, link mới đã được gửi vào Zalo của bạn.' );
	}

	private static function maybe_resend( array $row ): void {
		$platform = (string) ( $row['platform'] ?? '' );
		$chat_id = (string) ( $row['chat_id'] ?? '' );
		if ( '' === $platform || '' === $chat_id || ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) { return; }
		$throttle_key = 'bzc_loc_resend_' . md5( $platform . '|' . $chat_id );
		$count = (int) get_transient( $throttle_key );
		if ( $count >= self::RESEND_LIMIT ) { return; }
		set_transient( $throttle_key, $count + 1, self::RESEND_WINDOW );

		$meta = ! empty( $row['meta_json'] ) ? json_decode( (string) $row['meta_json'], true ) : array();
		$issued = BizCity_CRM_Magic_Link::issue( array(
			'platform'    => $platform,
			'chat_id'     => $chat_id,
			'bot_id'      => (string) ( $row['bot_id'] ?? '' ),
			'blog_id'     => (int) ( $row['blog_id'] ?? 0 ),
			'intent'      => self::INTENT,
			'ttl_seconds' => 600,
			'meta'        => is_array( $meta ) ? $meta : array(),
		) );
		if ( is_wp_error( $issued ) || ! class_exists( 'BizCity_Gateway_Sender' ) ) { return; }
		$url = add_query_arg( self::QUERY_VAR, $issued['token'], home_url( '/' ) );
		$label = self::run_label( is_array( $meta ) ? $meta : array() );
		try {
			BizCity_Gateway_Sender::instance()->send( $chat_id, 'Link chia sẻ vị trí đã hết hạn. Bấm để gửi lại' . $label . ': ' . $url, 'text', array( 'source' => 'crm_service_location_resend' ) );
		} catch ( Throwable $error ) {
			// Best-effort — a failed resend just means the next visit tries again (rate limit still applies).
		}
	}

	private static function row_for_token( string $token ): ?array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_Magic_Link' ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) { return null; }
		$table = BizCity_CRM_Magic_Link::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE token_hash = %s LIMIT 1', hash( 'sha256', $token ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function run_label( array $meta ): string {
		$run_id = (int) ( $meta['run_id'] ?? 0 );
		return $run_id > 0 ? ( ' cho ca #' . $run_id ) : '';
	}

	private static function render_form( array $row, string $token ): void {
		$meta = ! empty( $row['meta_json'] ) ? json_decode( (string) $row['meta_json'], true ) : array();
		$label = self::run_label( is_array( $meta ) ? $meta : array() );
		$rest_url = esc_url_raw( trailingslashit( get_rest_url( null, 'bizcity-crm/v1/service/location' ) ) . rawurlencode( $token ) );
		ob_start();
		?>
<!doctype html><html lang="vi"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Gửi vị trí</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:420px;margin:15vh auto 0;padding:0 20px;text-align:center;color:#1a1a1a}button{font-size:17px;padding:14px 20px;border-radius:10px;border:0;background:#0ea5e9;color:#fff;width:100%;margin-top:16px;cursor:pointer}button:disabled{opacity:.5}p{line-height:1.5}#msg{margin-top:14px;font-size:14px;color:#666}a{color:#0ea5e9}</style>
</head><body>
<p>Bấm nút bên dưới để gửi vị trí bạn đang đứng<?php echo esc_html( $label ); ?>.</p>
<button id="go">Gửi vị trí của tôi</button>
<p id="msg"></p>
<p><a href="#" id="fallback" style="display:none">Không lấy được vị trí? Gửi trực tiếp trong Zalo</a></p>
<script>
(function(){
	var btn = document.getElementById('go');
	var msg = document.getElementById('msg');
	var fb = document.getElementById('fallback');
	btn.addEventListener('click', function(){
		btn.disabled = true; msg.textContent = 'Đang lấy vị trí…';
		if (!navigator.geolocation) { msg.textContent = 'Trình duyệt không hỗ trợ định vị.'; fb.style.display='block'; btn.disabled=false; return; }
		navigator.geolocation.getCurrentPosition(function(pos){
			fetch(<?php echo wp_json_encode( $rest_url ); ?>, {
				method: 'POST', headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy })
			}).then(function(r){ return r.json(); }).then(function(data){
				if (data && data.ok) { msg.textContent = 'Đã gửi vị trí. Cảm ơn bạn!'; btn.style.display='none'; }
				else { msg.textContent = (data && data.message) || 'Không gửi được, thử lại.'; btn.disabled = false; }
			}).catch(function(){ msg.textContent = 'Lỗi kết nối, thử lại.'; btn.disabled = false; });
		}, function(){ msg.textContent = 'Không lấy được vị trí (bị từ chối hoặc trình duyệt chặn).'; fb.style.display='block'; btn.disabled = false; }, { enableHighAccuracy: true, timeout: 15000 });
	});
})();
</script>
</body></html>
		<?php
		echo ob_get_clean(); // phpcs:ignore -- static markup built above, nothing unescaped interpolated.
	}

	private static function render_page( string $message ): void {
		status_header( 200 );
		echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Gửi vị trí</title>'
			. '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:420px;margin:15vh auto 0;padding:0 20px;text-align:center;color:#1a1a1a}</style></head><body><p>'
			. esc_html( $message ) . '</p></body></html>';
	}
}
