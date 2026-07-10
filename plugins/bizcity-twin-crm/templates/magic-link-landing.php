<?php
/**
 * BizCity CRM — Magic Link landing page.
 *
 * Variables in scope:
 *   $ctx = array(
 *     'state'   => 'error' | 'login',
 *     'code'    => string (when error)
 *     'message' => string (when error)
 *     'row'     => array  (when login)
 *     'token'   => string (when login)
 *   )
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

$state   = $ctx['state'] ?? 'error';
$message = (string) ( $ctx['message'] ?? '' );
$code    = (string) ( $ctx['code'] ?? '' );
$row     = isset( $ctx['row'] ) && is_array( $ctx['row'] ) ? $ctx['row'] : array();
$token   = (string) ( $ctx['token'] ?? '' );

// Handle inline POST signon (CASE B).
$signon_error = '';
if ( $state === 'login' && ! empty( $_POST['bzml_signon'] ) ) {
	check_admin_referer( 'bzml_signon_' . substr( hash( 'sha256', $token ), 0, 16 ) );
	$user = wp_signon( array(
		'user_login'    => sanitize_user( wp_unslash( $_POST['user_login'] ?? '' ) ),
		'user_password' => (string) wp_unslash( $_POST['user_pass'] ?? '' ),
		'remember'      => true,
	), is_ssl() );
	if ( is_wp_error( $user ) ) {
		$signon_error = $user->get_error_message();
	} else {
		BizCity_CRM_Magic_Link::consume( (int) $row['id'], (int) $user->ID );
		$redirect = home_url( '/my-account/?welcome=1&platform=' . rawurlencode( strtolower( (string) $row['platform'] ) ) );
		$redirect = (string) apply_filters( 'bizcity_crm_magic_link_redirect', $redirect, $row, (int) $user->ID );
		wp_safe_redirect( $redirect );
		exit;
	}
}

$sso_url = '';
if ( $state === 'login' ) {
	$here    = ( is_ssl() ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
	$sso_url = site_url( '?auth=sso&redirect_to=' . rawurlencode( $here ) );
}

status_header( $state === 'error' ? 410 : 200 );
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $state === 'error' ? 'Link không hợp lệ' : 'Xác thực Zalo' ); ?></title>
<style>
	*{box-sizing:border-box}
	body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f7fb;color:#1f2937;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}
	.card{background:#fff;max-width:440px;width:100%;border-radius:14px;box-shadow:0 8px 32px rgba(15,23,42,.08);padding:28px;text-align:center}
	.icon{width:56px;height:56px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:12px}
	.icon.err{background:#fee2e2;color:#b91c1c}
	.icon.ok{background:#dcfce7;color:#15803d}
	h1{margin:0 0 8px;font-size:20px}
	p.lead{margin:0 0 20px;color:#475569;font-size:14px;line-height:1.5}
	.btn{display:inline-block;padding:11px 22px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;font-size:14px;border:0;cursor:pointer}
	.btn.secondary{background:#fff;color:#2563eb;border:1px solid #cbd5e1}
	.btn + .btn{margin-left:8px}
	form{margin-top:16px;text-align:left}
	form label{display:block;font-size:13px;font-weight:600;margin:10px 0 4px;color:#334155}
	form input[type=text],form input[type=password]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px}
	.divider{display:flex;align-items:center;gap:8px;margin:18px 0;color:#94a3b8;font-size:12px}
	.divider::before,.divider::after{content:"";flex:1;height:1px;background:#e2e8f0}
	.alert{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:8px;font-size:13px;margin-top:12px}
	footer{margin-top:18px;font-size:11px;color:#94a3b8}
</style>
</head>
<body>
<div class="card">
<?php if ( $state === 'error' ) : ?>
	<div class="icon err">⚠️</div>
	<h1>Link không hợp lệ</h1>
	<p class="lead"><?php echo esc_html( $message ); ?></p>
	<p class="lead" style="font-size:12px;color:#94a3b8">Vui lòng quay lại Zalo và yêu cầu link mới từ trợ lý.</p>
	<a class="btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">Về trang chủ</a>
<?php else : ?>
	<div class="icon ok">🔐</div>
	<h1>Xác thực để liên kết Zalo</h1>
	<p class="lead">Đăng nhập để liên kết tài khoản Zalo với website. Link sẽ tự huỷ sau khi sử dụng.</p>

	<?php if ( $sso_url ) : ?>
		<a class="btn" href="<?php echo esc_url( $sso_url ); ?>">Đăng nhập bằng Google</a>
	<?php endif; ?>

	<div class="divider">hoặc</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'bzml_signon_' . substr( hash( 'sha256', $token ), 0, 16 ) ); ?>
		<input type="hidden" name="bzml_signon" value="1">
		<label for="bzml_user">Tên đăng nhập / Email</label>
		<input id="bzml_user" type="text" name="user_login" required autocomplete="username">
		<label for="bzml_pass">Mật khẩu</label>
		<input id="bzml_pass" type="password" name="user_pass" required autocomplete="current-password">
		<?php if ( $signon_error ) : ?>
			<div class="alert"><?php echo esc_html( $signon_error ); ?></div>
		<?php endif; ?>
		<p style="margin-top:14px;text-align:center"><button type="submit" class="btn">Đăng nhập</button></p>
	</form>
<?php endif; ?>
	<footer>BizCity Twin · PHASE 3.5</footer>
</div>
</body>
</html>
