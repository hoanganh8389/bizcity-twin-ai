<?php
/**
 * Coach Builder — public landing page.
 * Rendered by BizCoach_Pro_Coach_Builder::maybe_render_landing() at /coach-builder/.
 *
 * Self-contained: header() / footer() to use theme chrome, falls back gracefully
 * if theme not present (raw HTML wrapper).
 */
defined( 'ABSPATH' ) || exit;

$rest_url = esc_url_raw( rest_url( BizCoach_Pro_Coach_Builder::REST_NS . '/coach-builder/' ) );
$nonce    = wp_create_nonce( 'wp_rest' );
$is_user  = is_user_logged_in();
$current  = wp_get_current_user();
$css_v    = (string) @filemtime( BCPRO_DIR . 'assets/coach-builder.css' );
$js_v     = (string) @filemtime( BCPRO_DIR . 'assets/coach-builder.js' );
$builder_key = (string) get_query_var( BizCoach_Pro_Coach_Builder::QV_KEY );
$builder_key = preg_match( '/^[A-Za-z0-9\-]{8,}$/', $builder_key ) ? $builder_key : '';

get_header();
?>
<link rel="stylesheet" href="<?php echo esc_url( BCPRO_URL . 'assets/coach-builder.css?v=' . $css_v ); ?>">
<div class="bcpro-builder-wrap">

	<header class="bcpro-builder-header">
		<img class="bcpro-builder-logo" src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Thiet-ke-chua-co-ten.png" alt="BizCoach">
		<h1>🧭 Coach Builder</h1>
		<p>Trả lời nhanh vài câu hỏi cơ bản → AI sẽ giúp bạn điền hết, sau đó tạo bản đồ Coach cá nhân hoá.</p>
		<?php if ( ! $is_user ) : ?>
			<div class="bcpro-builder-note">Bạn đang ở chế độ <strong>khách</strong>. Có thể thử trải nghiệm; <a href="<?php echo esc_url( wp_login_url( home_url( '/' . BizCoach_Pro_Coach_Builder::PAGE_SLUG . '/' ) ) ); ?>">đăng nhập</a> để lưu kết quả lâu dài.</div>
		<?php endif; ?>
	</header>

	<form id="bcpro-builder-form" autocomplete="off" novalidate>

		<!-- ── STEP 1 — Hồ sơ cá nhân ── -->
		<section class="bcpro-card">
			<h2><span class="bcpro-step-num">1</span> Hồ sơ cá nhân</h2>
			<div class="bcpro-grid-2">
				<label>Họ và tên *
					<input type="text" name="profile[full_name]" required value="<?php echo $is_user ? esc_attr( $current->display_name ) : ''; ?>">
				</label>
				<label>Email
					<input type="email" name="profile[email]" value="<?php echo $is_user ? esc_attr( $current->user_email ) : ''; ?>">
				</label>
				<label>Số điện thoại
					<input type="tel" name="profile[phone]">
				</label>
				<label>Ngày sinh
					<input type="date" name="profile[dob]">
				</label>
			</div>
		</section>

		<!-- ── STEP 2 — Chọn loại Coach ── -->
		<section class="bcpro-card">
			<h2><span class="bcpro-step-num">2</span> Chọn loại Coach</h2>
			<div id="bcpro-coach-type-list" class="bcpro-coach-type-list">
				<div class="bcpro-loading">Đang tải danh sách Coach…</div>
			</div>
		</section>

		<!-- ── STEP 2b — Thông tin bổ sung theo loại Coach (dynamic) ── -->
		<section class="bcpro-card" id="bcpro-extra-fields-card" hidden>
			<h2><span class="bcpro-step-num">2b</span> Thông tin bổ sung</h2>
			<div id="bcpro-extra-fields" class="bcpro-grid-2"></div>
		</section>

		<!-- ── AI Quick Fill panel ── -->
		<section class="bcpro-card bcpro-ai-fill" id="bcpro-ai-fill-panel" hidden>
			<h2>✨ AI fill nhanh — để AI điền giúp <span class="bcpro-q-count">20</span> câu hỏi</h2>
			<p class="bcpro-muted">Mô tả tự do về bản thân: nghề nghiệp, kinh nghiệm, mục tiêu, khó khăn hiện tại… càng chi tiết AI trả lời càng sát. Thông tin ở mục <strong>2b Thông tin bổ sung</strong> phía trên sẽ được dùng kèm, không cần nhắc lại.</p>
			<label class="bcpro-ai-fill-label">Mô tả về bạn (càng nhiều càng tốt)
				<textarea name="summary[freeform]" id="bcpro-ai-fill-freeform" rows="6" maxlength="4000"
					placeholder="VD: Mình đang làm Marketing Manager 5 năm, tốt nghiệp ĐH Ngoại Thương. Muốn trong 3 năm tới trở thành Founder một agency content nhỏ, hiện đang loḥc về tài chính cá nhân và cách xây team…"></textarea>
			</label>
			<div class="bcpro-ai-fill-actions">
				<button type="button" class="bcpro-btn bcpro-btn-ai" id="bcpro-ai-fill-btn">✨ Để AI điền hộ</button>
				<span class="bcpro-ai-status" id="bcpro-ai-status"></span>
			</div>
		</section>

		<!-- ── STEP 3 — 20 câu hỏi (lazy populated) ── -->
		<section class="bcpro-card" id="bcpro-questions-card" hidden>
			<h2><span class="bcpro-step-num">3</span> Câu trả lời chi tiết</h2>
			<p class="bcpro-muted">Bạn có thể chỉnh sửa các câu trả lời do AI gợi ý.</p>
			<div id="bcpro-questions-list" class="bcpro-questions-list"></div>
		</section>

		<!-- ── Submit ── -->
		<div class="bcpro-submit-bar">
			<button type="submit" class="bcpro-btn bcpro-btn-primary" id="bcpro-submit-btn" disabled>
				🗺️ Tạo bản đồ Coach của tôi
			</button>
			<span class="bcpro-ai-status" id="bcpro-submit-status"></span>
		</div>

		<input type="hidden" name="coach_type" id="bcpro-coach-type-input" value="">
	</form>

	<!-- ── RESULT PANEL — skeleton + progressive section load (post-save) ── -->
	<div id="bcpro-result-panel" class="bcpro-result-panel" hidden>
		<div class="bcpro-result-head">
			<img class="bcpro-builder-logo" src="https://media.bizcity.vn/uploads/sites/1258/2026/05/Thiet-ke-chua-co-ten.png" alt="BizCoach">
			<h2 id="bcpro-result-title">🗺️ Bản đồ Coach của bạn</h2>
			<div class="bcpro-progress-wrap">
				<div class="bcpro-progress-bar"><div class="bcpro-progress-fill" id="bcpro-progress-fill"></div></div>
				<div class="bcpro-progress-text" id="bcpro-progress-text">⏳ Đang chuẩn bị…</div>
			</div>
		</div>
		<div id="bcpro-section-list" class="bcpro-section-list"></div>
		<div class="bcpro-result-actions" id="bcpro-result-actions" hidden>
			<button type="button" class="bcpro-btn bcpro-btn-primary" id="bcpro-share-btn">🔗 Sao chép link chia sẻ</button>
			<button type="button" class="bcpro-btn bcpro-btn-ai"      id="bcpro-print-btn">🖨️ In / Lưu PDF</button>
			<button type="button" class="bcpro-btn bcpro-btn-rebuild" id="bcpro-rebuild-btn">🔁 Tạo lại tất cả</button>
			<button type="button" class="bcpro-btn bcpro-btn-ghost"   id="bcpro-restart-btn">✏️ Tạo bản đồ khác</button>
		</div>
		<div class="bcpro-share-url" id="bcpro-share-url" hidden></div>
	</div>
</div>

<script>
window.BCPRO_BUILDER = {
	restUrl:  <?php echo wp_json_encode( $rest_url ); ?>,
	nonce:    <?php echo wp_json_encode( $nonce ); ?>,
	mode:     'frontend',
	pageBase: <?php echo wp_json_encode( home_url( '/' . BizCoach_Pro_Coach_Builder::PAGE_SLUG . '/' ) ); ?>,
	mapKey:   <?php echo wp_json_encode( $builder_key ); ?>
};
</script>
<script src="<?php echo esc_url( BCPRO_URL . 'assets/coach-builder.js?v=' . $js_v ); ?>"></script>

<?php
get_footer();
