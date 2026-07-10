<?php
/**
 * BizCoach Pro — Self-Service Astrology Profile Shortcode
 *
 * Usage: [bcpro_my_astro_profile]
 *
 * Renders the Alpine.js self-service UI for logged-in subscribers to manage
 * their own astrology profiles. Non-logged-in visitors see a login prompt.
 *
 * @package BizCoach_Pro
 * @since   0.5.0 (PHASE-A A-FE-1 · 2026-06-05)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Self_Service_Shortcode' ) ) { return; }

class BizCoach_Pro_Self_Service_Shortcode {

	// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — shortcode + asset init
	public static function init() {
		add_shortcode( 'bcpro_my_astro_profile', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Only enqueue assets on pages that actually contain the shortcode.
	 */
	public static function maybe_enqueue() {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) ) {
			return;
		}
		if ( strpos( $post->post_content, '[bcpro_my_astro_profile' ) === false ) {
			return;
		}
		self::enqueue_assets();
	}

	public static function enqueue_assets() {
		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — prefer Vite React build over Alpine.js fallback
		$fe_dir  = BCPRO_DIR . 'assets/fe-self-service/';
		$fe_url  = BCPRO_URL . 'assets/fe-self-service/';

		$manifest_path = $fe_dir . '.vite/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			$manifest_path = $fe_dir . 'manifest.json';
		}

		if ( file_exists( $manifest_path ) ) {
			// ── React (Vite) build found ─────────────────────────────────────────
			$json = json_decode( (string) file_get_contents( $manifest_path ), true );
			if ( is_array( $json ) ) {
				$ver       = BCPRO_VERSION . '.' . filemtime( $manifest_path );
				$entry_js  = '';
				$entry_css = array();

				foreach ( $json as $entry ) {
					if ( isset( $entry['file'] ) && substr( (string) $entry['file'], -3 ) === '.js' ) {
						if ( ! empty( $entry['isEntry'] ) ) {
							$entry_js = $fe_url . $entry['file'];
						}
					}
					if ( isset( $entry['css'] ) && is_array( $entry['css'] ) ) {
						foreach ( $entry['css'] as $css_file ) {
							$entry_css[] = $fe_url . $css_file;
						}
					}
				}

				// [2026-07-10 Johnny Chu] HOTFIX — support manifests that emit standalone CSS entries (e.g. style.css) without entry['css'].
				if ( empty( $entry_css ) ) {
					foreach ( $json as $entry ) {
						if ( isset( $entry['file'] ) && substr( (string) $entry['file'], -4 ) === '.css' ) {
							$entry_css[] = $fe_url . $entry['file'];
						}
					}
				}
				$entry_css = array_values( array_unique( $entry_css ) );

				if ( $entry_js ) {
					foreach ( $entry_css as $i => $css_url ) {
						wp_enqueue_style( 'bcpro-self-service-' . $i, $css_url, array(), $ver );
					}

					wp_enqueue_script( 'bcpro-self-service', $entry_js, array(), $ver, true );
					// [2026-06-08 Johnny Chu] PHASE-A A-FE-1 — fix: WP may output type='text/javascript'
					// which takes precedence over a later type="module". Strip the legacy attribute
					// first, then prepend type="module" on <script so Vite ESM import works.
					add_filter( 'script_loader_tag', static function ( $tag, $handle ) {
						if ( $handle === 'bcpro-self-service' ) {
							// Remove legacy type attr (WP <= 5.6 still adds it)
							$tag = str_replace( " type='text/javascript'", '', $tag );
							$tag = str_replace( ' type="text/javascript"', '', $tag );
							// Set ESM type
							$tag = str_replace( '<script ', '<script type="module" ', $tag );
						}
						return $tag;
					}, 10, 2 );

					// [2026-06-07 Johnny Chu] PHASE-C C-1 — inject auth + membership config.
					$uid = (int) get_current_user_id();
					wp_localize_script( 'bcpro-self-service', 'bcproSS', array(
						'restBase'       => esc_url_raw( rest_url( 'bizcity-bizcoach/v1' ) ),
						'nonce'          => wp_create_nonce( 'wp_rest' ),
						'loginUrl'       => wp_login_url( get_permalink() ),
						'currentUserId'  => $uid,
						'assetsUrl'      => esc_url_raw( BCPRO_URL . 'assets/' ),
						// auth (share bizcity_ajax_* AJAX with webchat)
						'isLoggedIn'     => $uid > 0,
						'ajaxUrl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
						'webchatNonce'   => wp_create_nonce( 'bizcity_webchat' ),
						'logoutUrl'      => $uid > 0 ? wp_logout_url( get_permalink() ) : '',
						'myAccountUrl'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ),
						'currentUser'    => $uid > 0 ? self::build_current_user( $uid ) : null,
						'ssoGoogleUrl'   => esc_url_raw( site_url( '?auth=sso' ) ),
						// membership same-origin REST
						'membershipBase' => esc_url_raw( rest_url( 'bizcity-membership/v1' ) ),
						// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — same-origin billing proxy base for FE pricing flow.
						'clientBase'     => esc_url_raw( rest_url( 'bizcity-client/v1' ) ),
						'siteTitle'      => get_bloginfo( 'name' ),
						'siteIcon'       => esc_url_raw( (string) get_site_icon_url( 64 ) ),
						// [2026-06-07 Johnny Chu] PHASE-C C-FE-5 — PayPal in-place Buttons config.
						'paypalEnabled'  => class_exists( 'BizCity_Membership_PayPal_Gateway' ) && BizCity_Membership_PayPal_Gateway::instance()->is_ready(),
						'paypalClientId' => class_exists( 'BizCity_Membership_PayPal_Gateway' ) ? (string) BizCity_Membership_PayPal_Gateway::instance()->settings()['client_id'] : '',
						'paypalMode'     => class_exists( 'BizCity_Membership_PayPal_Gateway' ) ? (string) BizCity_Membership_PayPal_Gateway::instance()->settings()['mode'] : 'sandbox',
					) );
					return; // Vite build loaded — skip Alpine fallback.
				} // close if ( $entry_js )
			} // close if ( is_array( $json ) )
		} // close if ( file_exists( $manifest_path ) )

		// ── Alpine.js fallback (dev / before first `npm run build`) ─────────────
		$base_url = BCPRO_URL . 'assets/';
		$ver      = BCPRO_VERSION;

		wp_enqueue_script(
			'alpinejs',
			'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js',
			array(),
			'3.14.1',
			true
		);

		wp_enqueue_style(
			'bcpro-self-service',
			$base_url . 'css/bcpro-self-service.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'bcpro-self-service',
			$base_url . 'js/bcpro-self-service.js',
			array( 'alpinejs' ),
			$ver,
			true
		);

		// [2026-06-07 Johnny Chu] PHASE-C C-1 — auth fields for Alpine fallback too.
		$uid = (int) get_current_user_id();
		wp_localize_script( 'bcpro-self-service', 'bcproSS', array(
			'restBase'       => esc_url_raw( rest_url( 'bizcity-bizcoach/v1' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'loginUrl'       => wp_login_url( get_permalink() ),
			'currentUserId'  => $uid,
			'isLoggedIn'     => $uid > 0,
			'ajaxUrl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'webchatNonce'   => wp_create_nonce( 'bizcity_webchat' ),
			'logoutUrl'      => $uid > 0 ? wp_logout_url( get_permalink() ) : '',
			'currentUser'    => $uid > 0 ? self::build_current_user( $uid ) : null,
			'ssoGoogleUrl'   => esc_url_raw( site_url( '?auth=sso' ) ),
			'membershipBase' => esc_url_raw( rest_url( 'bizcity-membership/v1' ) ),
			// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — same-origin billing proxy base for FE pricing flow.
			'clientBase'     => esc_url_raw( rest_url( 'bizcity-client/v1' ) ),
			'siteTitle'      => get_bloginfo( 'name' ),
			'siteIcon'       => esc_url_raw( (string) get_site_icon_url( 64 ) ),
			// [2026-06-07 Johnny Chu] PHASE-C C-FE-5 — PayPal in-place Buttons config.
			'paypalEnabled'  => class_exists( 'BizCity_Membership_PayPal_Gateway' ) && BizCity_Membership_PayPal_Gateway::instance()->is_ready(),
			'paypalClientId' => class_exists( 'BizCity_Membership_PayPal_Gateway' ) ? (string) BizCity_Membership_PayPal_Gateway::instance()->settings()['client_id'] : '',
			'paypalMode'     => class_exists( 'BizCity_Membership_PayPal_Gateway' ) ? (string) BizCity_Membership_PayPal_Gateway::instance()->settings()['mode'] : 'sandbox',
		) );
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-C C-1 — build current user payload for bcproSS.
	 * Mirror of twinchat build_current_user() pattern.
	 */
	private static function build_current_user( $user_id ) {
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return null;
		}
		return array(
			'id'     => (int) $user_id,
			'name'   => $u->display_name,
			'email'  => $u->user_email,
			'avatar' => get_avatar_url( $user_id, array( 'size' => 96 ) ),
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — output clean mount div when React build exists;
	 * keep full Alpine HTML as fallback (pre-build / dev mode).
	 * [2026-06-07 Johnny Chu] PHASE-C C-1 — React build handles guest gating internally
	 * via AuthModal; only gate on Alpine fallback path.
	 */
	public static function render( $atts ) {
		// React build present — allow guests through (AuthModal gates inline).
		$fe_dir        = BCPRO_DIR . 'assets/fe-self-service/';
		$manifest_path = $fe_dir . '.vite/manifest.json';
		if ( ! file_exists( $manifest_path ) ) {
			$manifest_path = $fe_dir . 'manifest.json';
		}
		if ( file_exists( $manifest_path ) ) {
			return '<div id="bcpro-self-service"></div>';
		}

		// Alpine fallback — keep original login gate.
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( get_permalink() );
			return '<p class="bcpro-login-prompt">Vui lòng <a href="' . esc_url( $login_url ) . '">đăng nhập</a> để xem và quản lý hồ sơ chiêm tinh của bạn.</p>';
		}

		$user = wp_get_current_user();

		ob_start();
		?>
		<div id="bcpro-self-service"
		     x-data="bcproSelfService()"
		     x-init="init()"
		     class="bcpro-ss-wrap">

			<!-- Loading skeleton -->
			<div x-show="loading && view === 'list'" class="bcpro-ss-loading">
				<div class="bcpro-ss-spinner"></div>
				<p>Đang tải hồ sơ...</p>
			</div>

			<!-- Error banner -->
			<div x-show="error" class="bcpro-ss-error" x-cloak>
				<strong x-text="error ? '⚠️ ' + error.message : ''"></strong>
				<span x-text="error ? (error.hint || '') : ''" style="display:block;font-size:13px;margin-top:4px;opacity:.8"></span>
				<button @click="error = null" class="bcpro-ss-btn-sm">✕ Đóng</button>
			</div>

			<!-- ── LIST VIEW ──────────────────────────────────── -->
			<div x-show="view === 'list' && !loading" x-cloak>
				<div class="bcpro-ss-header">
					<h2>⭐ Hồ sơ chiêm tinh của tôi</h2>
					<button @click="openCreate()" class="bcpro-ss-btn-primary">+ Tạo hồ sơ mới</button>
				</div>

				<!-- Empty state -->
				<template x-if="profiles.length === 0">
					<div class="bcpro-ss-empty">
						<p>Bạn chưa có hồ sơ nào. Tạo hồ sơ đầu tiên để xem biểu đồ chiêm tinh!</p>
						<button @click="openCreate()" class="bcpro-ss-btn-primary">✨ Tạo hồ sơ ngay</button>
					</div>
				</template>

				<!-- Profile cards -->
				<div class="bcpro-ss-cards">
					<template x-for="p in profiles" :key="p.coachee_id + '_' + p.chart_type">
						<div class="bcpro-ss-card">
							<div class="bcpro-ss-card-top">
								<div>
									<strong x-text="p.full_name"></strong>
									<span class="bcpro-ss-tag" x-text="chartLabel(p.chart_type)"></span>
								</div>
								<div class="bcpro-ss-card-dob" x-text="p.dob || '—'"></div>
							</div>
							<div class="bcpro-ss-card-meta">
								<span x-text="p.birth_time ? '🕐 ' + p.birth_time : ''"></span>
								<span x-text="p.birth_place ? '📍 ' + p.birth_place : ''"></span>
							</div>
							<div class="bcpro-ss-card-status">
								<template x-if="p.has_chart">
									<span class="bcpro-ss-badge-ok">✅ Đã có biểu đồ</span>
								</template>
								<template x-if="!p.has_chart">
									<span class="bcpro-ss-badge-warn">⚠️ Chưa có biểu đồ</span>
								</template>
							</div>
							<div class="bcpro-ss-card-actions">
								<template x-if="p.has_chart">
									<button @click="viewChart(p)" class="bcpro-ss-btn-sm bcpro-ss-btn-primary">📊 Xem biểu đồ</button>
								</template>
								<template x-if="!p.has_chart">
									<button @click="generateChart(p)" class="bcpro-ss-btn-sm" :disabled="generating === p.coachee_id">
										<span x-show="generating !== p.coachee_id">⚡ Tạo biểu đồ</span>
										<span x-show="generating === p.coachee_id">⏳ Đang tạo...</span>
									</button>
								</template>
								<template x-if="p.share_url">
									<button @click="copyShare(p)" class="bcpro-ss-btn-sm">🔗 Chia sẻ</button>
								</template>
								<button @click="openEdit(p)" class="bcpro-ss-btn-sm">✏️</button>
								<button @click="confirmDelete(p)" class="bcpro-ss-btn-sm bcpro-ss-btn-danger">🗑️</button>
							</div>
						</div>
					</template>
				</div>
			</div>

			<!-- ── CREATE / EDIT FORM ─────────────────────────── -->
			<div x-show="view === 'form'" x-cloak>
				<div class="bcpro-ss-header">
					<h2 x-text="editing ? '✏️ Sửa hồ sơ' : '✨ Tạo hồ sơ mới'"></h2>
					<button @click="goList()" class="bcpro-ss-btn-sm">← Quay lại</button>
				</div>
				<form @submit.prevent="submitForm()" class="bcpro-ss-form">
					<label>Tên hiển thị *
						<input type="text" x-model="form.full_name" placeholder="Nguyễn Thị Hương" required />
					</label>
					<label>Ngày sinh *
						<input type="date" x-model="form.dob" required />
					</label>
					<label>Giờ sinh
						<input type="time" x-model="form.birth_time" />
						<small>Điền giờ sinh giúp biểu đồ chính xác hơn</small>
					</label>
					<label>Nơi sinh
						<input type="text" x-model="form.birth_place" placeholder="Hà Nội, Việt Nam" />
					</label>
					<label>Số điện thoại
						<input type="tel" x-model="form.phone" placeholder="0901234567" />
					</label>
					<!-- [2026-07-07 Johnny Chu] HOTFIX — force profile form chart type to western only. -->
					<input type="hidden" x-model="form.chart_type" value="western" />
					<small>Hệ thống chiêm tinh mặc định: 🌟 Western (Tropical).</small>
					<div class="bcpro-ss-form-actions">
						<button type="button" @click="goList()" class="bcpro-ss-btn-sm">Huỷ</button>
						<button type="submit" class="bcpro-ss-btn-primary" :disabled="saving">
							<span x-text="saving ? 'Đang lưu...' : (editing ? 'Cập nhật hồ sơ' : 'Tạo hồ sơ →')"></span>
						</button>
					</div>
				</form>
			</div>

			<!-- ── CHART VIEW ─────────────────────────────────── -->
			<div x-show="view === 'chart'" x-cloak>
				<div class="bcpro-ss-header">
					<h2>📊 Biểu đồ chiêm tinh</h2>
					<button @click="goList()" class="bcpro-ss-btn-sm">← Quay lại</button>
				</div>
				<div class="bcpro-ss-chart-wrap">
					<div class="bcpro-ss-chart-meta" x-show="currentProfile">
						<strong x-text="currentProfile ? currentProfile.full_name : ''"></strong>
						<span x-text="currentProfile ? chartLabel(currentProfile.chart_type) : ''"></span>
						<span x-text="currentProfile ? (currentProfile.dob || '') : ''"></span>
					</div>
					<div class="bcpro-ss-chart-summary" x-html="chartSummaryHtml"></div>
					<div class="bcpro-ss-chart-share" x-show="currentProfile && currentProfile.share_url">
						<a :href="currentProfile ? currentProfile.share_url : '#'" target="_blank" class="bcpro-ss-btn-primary">
							🔗 Mở trang chia sẻ
						</a>
						<button @click="copyShare(currentProfile)" class="bcpro-ss-btn-sm">📋 Copy link</button>
					</div>
				</div>
			</div>

			<!-- Copy toast -->
			<div x-show="copyToast" x-cloak class="bcpro-ss-toast">✅ Đã copy link chia sẻ!</div>

		</div><!-- #bcpro-self-service -->
		<?php
		return ob_get_clean();
	}
}
