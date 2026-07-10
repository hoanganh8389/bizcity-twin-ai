<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Membership_Admin_Page — PHASE-MEMBERSHIP M5.
 *
 * Top-level admin page "Membership" with 5 tabs:
 *   Overview  — member / subscriber / revenue counters.
 *   Plans     — CRUD the local plan registry (option bizcity_membership_plans).
 *   Members   — list users + their plan, manual assign / cancel.
 *   Payments  — PayPal one-time capture ledger.
 *   Settings  — client's own PayPal app (client id / secret / mode / enabled).
 *
 * All mutations go through admin-post.php with a capability check
 * (manage_options) + nonce — never GET side-effects.
 *
 * @since 2026-06-04 (PHASE-MEMBERSHIP M5)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Admin_Page {

	const MENU_SLUG = 'bizcity-membership';
	const CAP       = 'manage_options';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_bizcity_membership_save_plan',     array( __CLASS__, 'handle_save_plan' ) );
		add_action( 'admin_post_bizcity_membership_delete_plan',   array( __CLASS__, 'handle_delete_plan' ) );
		add_action( 'admin_post_bizcity_membership_assign',        array( __CLASS__, 'handle_assign' ) );
		add_action( 'admin_post_bizcity_membership_cancel',        array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_bizcity_membership_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — provision PayPal subscription plans.
		add_action( 'admin_post_bizcity_membership_provision_paypal', array( __CLASS__, 'handle_provision_paypal' ) );
		// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — refund action for admin Payments tab.
		add_action( 'admin_post_bizcity_membership_refund', array( __CLASS__, 'handle_refund' ) );
	}

	public static function add_menu() {
		// [2026-06-09 Johnny Chu] HOTFIX — rename brand to "Twin Membership".
		add_menu_page(
			__( 'Twin Membership', 'bizcity-twin-ai' ),
			__( 'Twin Membership', 'bizcity-twin-ai' ),
			self::CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-id-alt',
			58
		);
	}

	/* ── Router ─────────────────────────────────────────────────────────── */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tabs = array(
			'overview'  => __( 'Overview', 'bizcity-twin-ai' ),
			// [2026-06-07 Johnny Chu] PHASE-C C-3 — Revenue + Usage admin tabs.
			'dashboard' => __( 'Revenue', 'bizcity-twin-ai' ),
			'usage'     => __( 'Usage', 'bizcity-twin-ai' ),
			'plans'     => __( 'Plans', 'bizcity-twin-ai' ),
			'members'   => __( 'Members', 'bizcity-twin-ai' ),
			'payments'  => __( 'Payments', 'bizcity-twin-ai' ),
			'settings'  => __( 'Settings', 'bizcity-twin-ai' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap bizm-wrap">';
		// [2026-06-09 Johnny Chu] HOTFIX — brand H1 → Twin Membership.
		echo '<h1 class="bizm-title">💳 ' . esc_html__( 'Twin Membership', 'bizcity-twin-ai' ) . '</h1>';
		self::render_notice();

		echo '<nav class="nav-tab-wrapper bizm-tabs">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$tab === $slug ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		self::styles();

		echo '<div class="bizm-body">';
		switch ( $tab ) {
			// [2026-06-07 Johnny Chu] PHASE-C C-3 — Revenue + Usage tabs.
			case 'dashboard':
				self::tab_dashboard();
				break;
			case 'usage':
				self::tab_usage();
				break;
			case 'plans':
				self::tab_plans();
				break;
			case 'members':
				self::tab_members();
				break;
			case 'payments':
				self::tab_payments();
				break;
			case 'settings':
				self::tab_settings();
				break;
			default:
				self::tab_overview();
		}
		echo '</div></div>';
	}

	/* ── Twin Master Guide ──────────────────────────────────────────────── */

	/**
	 * Render the "Twin Master" guide panel on the Overview tab.
	 *
	 * Explains the 3-tier model (Admin / Site-shared / Member) and links to
	 * Twin Settings for API key configuration and usage monitoring.
	 *
	 * [2026-06-09 Johnny Chu] HOTFIX — master guide panel.
	 */
	private static function render_master_guide() {
		$settings_url  = esc_url( admin_url( 'admin.php?page=bizcity-twinchat-settings' ) );
		$settings_url_usage = esc_url( admin_url( 'admin.php?page=bizcity-twinchat-settings&tab=usage' ) );
		// [2026-06-10 Johnny Chu] HOTFIX — per-site option
		$hub_level     = esc_html( (string) get_option( 'bizcity_hub_master_level', '—' ) );

		?>
		<div class="bizm-master-guide">
			<div class="bizm-master-guide__header">
				<span class="bizm-master-guide__icon">🧠</span>
				<div>
					<h2 class="bizm-master-guide__title">Twin Master &amp; Twin Membership</h2>
					<p class="bizm-master-guide__sub">Hướng dẫn kiến trúc phân tầng — dành cho Admin</p>
				</div>
				<div class="bizm-master-guide__links">
					<a href="<?php echo $settings_url; ?>" class="button button-primary">⚙️ Cài đặt Twin API (Master key)</a>
					<a href="<?php echo $settings_url_usage; ?>" class="button">📊 Theo dõi Usage tổng</a>
				</div>
			</div>

			<div class="bizm-master-guide__current">
				<?php
				$badge_class = 'free' === $hub_level ? 'bizm-badge--free' : 'bizm-badge--pro';
				?>
				Master level hiện tại:
				<span class="bizm-badge <?php echo esc_attr( $badge_class ); ?>">
					<?php echo $hub_level; ?>
				</span>
				<?php if ( '—' === $hub_level || 'free' === $hub_level ) : ?>
				<span class="bizm-guide-warn">
					⚠️ Chưa có API key — <a href="<?php echo $settings_url; ?>">Cài đặt ngay</a>
				</span>
				<?php endif; ?>
			</div>

			<div class="bizm-master-guide__tiers">

				<div class="bizm-tier bizm-tier--admin">
					<div class="bizm-tier__header">
						<span class="bizm-tier__icon">👑</span>
						<strong>Twin Master (Admin)</strong>
						<span class="bizm-badge bizm-badge--admin">manage_options</span>
					</div>
					<ul class="bizm-tier__list">
						<li>🔑 Đăng ký API key tại <strong>bizcity.vn</strong> → cài vào <a href="<?php echo $settings_url; ?>">Twin Settings</a>.</li>
						<li>🏆 Gói <strong>Master</strong> là gói cao nhất ở cấp client — admin dùng <strong>toàn bộ capacity</strong> của site (master_level).</li>
						<li>🚫 Admin <strong>không bị</strong> tính quota, không bị giới hạn chat / KG / image.</li>
						<li>🔀 Master là <strong>layer filter</strong> đứng trước <code>core/bizcity-llm</code> — mọi call AI đều đi qua đây trước khi LLM Client gọi về hub gateway.</li>
						<li>💰 Trong gói Master có <strong>Twin Membership</strong> — quản lý accounts chia sẻ, nhận doanh thu từ member (Free / Pro / Plus).</li>
						<li>💳 Cài PayPal để nhận tiền tự động, hoặc nâng cấp thủ công trong tab <strong>Members</strong>.</li>
					</ul>
				</div>

				<div class="bizm-tier bizm-tier--shared">
					<div class="bizm-tier__header">
						<span class="bizm-tier__icon">🌐</span>
						<strong>Site Tier — Shared capacity cho Members</strong>
						<span class="bizm-badge bizm-badge--info">hub tier</span>
					</div>
					<ul class="bizm-tier__list">
						<li>⚡ Hub chia sẻ <strong>một phần capacity</strong> từ site's credit pool xuống cho member.</li>
						<li>📉 Đây là <strong>trần (ceiling)</strong>: member không thể dùng quá mức này dù có plan cao hơn.</li>
						<li>🔢 Ceiling theo hub <code>tier</code>: <code>free</code>=50/day · <code>pro</code>=1000/day · <code>plus+</code>=không giới hạn.</li>
						<li>� <strong>Định dạng file KG</strong> cũng theo license hub: <code>master_premium</code> → pdf + av đầy đủ · <code>master_pro</code> → pdf + office · <code>free</code> → text-only. Plan local <strong>mặc định kế thừa toàn bộ</strong> — chỉ cấu hình <em>Hạn chế tùy chỉnh</em> trong tab Plans khi muốn bỏ bớt.</li>
						<li>�📊 Xem usage tổng của site → <a href="<?php echo $settings_url_usage; ?>">Tab Usage</a>.</li>
					</ul>
				</div>

				<div class="bizm-tier bizm-tier--member">
					<div class="bizm-tier__header">
						<span class="bizm-tier__icon">👤</span>
						<strong>Twin Member — End-user subscription</strong>
						<span class="bizm-badge bizm-badge--free">subscriber / editor / author</span>
					</div>
					<ul class="bizm-tier__list">
						<li>🆓 <strong>Free</strong>: 30 chat/day · 20 KB/day · không có image/video.</li>
						<li>💎 <strong>Pro</strong>: 500 chat/day · 200 KB/day · 20 image/day · web search.</li>
						<li>✨ <strong>Plus</strong>: 3000 chat/day · 1000 KB/day · 100 image · 10 video/day · astrology.</li>
						<li>💵 Quota thực tế = <code>min(local_plan_limit, site_tier_ceiling)</code>.</li>
						<li>👤 Quản lý member → tab <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'members' ), admin_url( 'admin.php' ) ) ); ?>">Members</a> · Cấu hình gói → tab <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'plans' ), admin_url( 'admin.php' ) ) ); ?>">Plans</a>.</li>
					</ul>
				</div>

			</div><!-- /.bizm-master-guide__tiers -->

			<div class="bizm-master-guide__flow">
				<strong>Luồng xử lý một request AI:</strong>
				<code>WP User → Twin Master filter → core/bizcity-llm (LLM Client) → Hub Gateway (bizcity.vn) → Provider API</code>
			</div>
		</div><!-- /.bizm-master-guide -->
		<?php
	}

	/* ── Tab: Overview ──────────────────────────────────────────────────── */

	private static function tab_overview() {
		// [2026-06-09 Johnny Chu] HOTFIX — show Twin Master guide panel before stats.
		self::render_master_guide();

		global $wpdb;
		$registry = BizCity_Membership_Plan_Registry::instance();
		$plans    = $registry->all();

		$sub_table = $wpdb->prefix . 'bizcity_member_subscriptions';
		$active    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$sub_table} WHERE status = %s", 'active' )
		);

		// Per-plan active counts.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT plan_slug, COUNT(DISTINCT user_id) AS c FROM {$sub_table} WHERE status = %s GROUP BY plan_slug", 'active' ),
			ARRAY_A
		);
		$by_plan = array();
		foreach ( (array) $rows as $r ) {
			$by_plan[ $r['plan_slug'] ] = (int) $r['c'];
		}

		$totals = class_exists( 'BizCity_Membership_Payments' )
			? BizCity_Membership_Payments::instance()->totals()
			: array( 'total_usd' => 0, 'count' => 0, 'paying_members' => 0 );

		$total_users = count_users();
		$total_users = isset( $total_users['total_users'] ) ? (int) $total_users['total_users'] : 0;

		echo '<div class="bizm-cards">';
		self::card( __( 'Total WP users', 'bizcity-twin-ai' ), number_format_i18n( $total_users ), 'admin-users' );
		self::card( __( 'Active subscriptions', 'bizcity-twin-ai' ), number_format_i18n( $active ), 'yes-alt' );
		self::card( __( 'Paying members', 'bizcity-twin-ai' ), number_format_i18n( $totals['paying_members'] ), 'money-alt' );
		self::card( __( 'Total revenue', 'bizcity-twin-ai' ), '$' . number_format( (float) $totals['total_usd'], 2 ), 'chart-bar' );
		self::card( __( 'Payments captured', 'bizcity-twin-ai' ), number_format_i18n( $totals['count'] ), 'tickets-alt' );
		echo '</div>';

		echo '<h2>' . esc_html__( 'Plans breakdown', 'bizcity-twin-ai' ) . '</h2>';
		echo '<table class="widefat striped bizm-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Plan', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Active members', 'bizcity-twin-ai' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $plans as $slug => $plan ) {
			printf(
				'<tr><td><strong>%s</strong> <code>%s</code></td><td>%s</td><td>%s</td></tr>',
				esc_html( $plan['label'] ),
				esc_html( $slug ),
				esc_html( $registry->price_label( $slug ) ),
				esc_html( number_format_i18n( isset( $by_plan[ $slug ] ) ? $by_plan[ $slug ] : 0 ) )
			);
		}
		echo '</tbody></table>';
	}

	/* ── Tab: Plans ─────────────────────────────────────────────────────── */

	// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP M1 — Plans tab: card-based UI per plan
	// (Group 1: feature checkboxes; Group 2: quota inputs). Matches master plans admin UI.
	private static function tab_plans() {
		// Feature catalog — all possible feature keys + display labels.
		$feature_catalog = array(
			'chat'      => '💬 Chat AI',
			'kg.text'   => '📄 Knowledge Base (Text/Markdown)',
			'kg.office' => '📊 Knowledge Base (Office/PDF)',
			'kg.av'     => '🎬 Knowledge Base (Audio/Video)',
			'image'     => '🖼 Tạo ảnh AI',
			'video'     => '🎥 Tạo video AI',
			'search'    => '🔍 Web Search (Tavily)',
			'astrology' => '🔮 Tử vi / Tarot',
		);
		// Model catalog.
		$model_catalog = array(
			'fast'      => '⚡ Fast (DeepSeek, Gemini Flash)',
			'reasoning' => '🧠 Reasoning (Claude, GPT-4o)',
			'vision'    => '👁 Vision (Ảnh → Text)',
		);
		// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — file type groups for accepted-file-types checkboxes.
		$file_type_groups = array(
			'Văn bản'       => array( 'txt', 'md', 'rtf', 'csv', 'tsv' ),
			'Office OOXML'  => array( 'docx', 'xlsx', 'pptx' ),
			'Office Legacy' => array( 'doc', 'xls', 'ppt' ),
			'OpenDocument'  => array( 'odt', 'ods', 'odp' ),
			'PDF'           => array( 'pdf' ),
			'Audio / Video' => array( 'mp3', 'mp4', 'm4a', 'wav', 'ogg' ),
		);

		$quota_fields = array(
			'chat_msgs_per_day'   => array( __( 'Tin nhắn chat / ngày', 'bizcity-twin-ai' ),   __( 'số tin nhắn tối đa mỗi ngày', 'bizcity-twin-ai' ) ),
			'kg_passages_per_day' => array( __( 'KG passages / ngày', 'bizcity-twin-ai' ),     __( 'đoạn knowledge base LLM có thể truy vấn/ngày', 'bizcity-twin-ai' ) ),
			'image_per_day'       => array( __( 'Tạo ảnh / ngày', 'bizcity-twin-ai' ),         __( 'số lần gọi API image generation', 'bizcity-twin-ai' ) ),
			'video_per_day'       => array( __( 'Tạo video / ngày', 'bizcity-twin-ai' ),        __( 'số lần gọi API video generation', 'bizcity-twin-ai' ) ),
		);

		$registry = BizCity_Membership_Plan_Registry::instance();
		$plans    = $registry->all();

		$tier_borders = array(
			'free' => '#888',
			'pro'  => '#2271b1',
			'plus' => '#d97706',
		);
		$tier_badge_styles = array(
			'free' => 'background:#888;color:#fff',
			'pro'  => 'background:#2271b1;color:#fff',
			'plus' => 'background:#d97706;color:#fff',
		);

		// ── Summary row at top (all plan cards) ────────────────────────────
		echo '<p style="color:#646970;margin:0 0 12px;">' . esc_html__( 'Mỗi gói định nghĩa quota và tính năng cho site khách sử dụng plugin này.', 'bizcity-twin-ai' ) . '</p>';
		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:28px;">';
		foreach ( $plans as $slug => $plan ) {
			$bs = isset( $tier_badge_styles[ $slug ] ) ? $tier_badge_styles[ $slug ] : 'background:#444;color:#fff';
			$price = (float) $plan['price'];
			$price_label = $price > 0
				? '$' . number_format( $price, 0 ) . '/' . esc_html( $plan['billing_cycle'] )
				: esc_html__( 'Miễn phí', 'bizcity-twin-ai' );
			printf(
				'<div style="%s;border-radius:8px;padding:16px 28px;min-width:150px;text-align:center;cursor:pointer;" onclick="document.getElementById(\'bmp-card-%s\').scrollIntoView({behavior:\'smooth\'})">
					<div style="font-weight:700;font-size:16px;">%s</div>
					<div style="font-size:12px;opacity:0.8;margin-top:2px;"><code style="background:transparent;color:inherit;">%s</code></div>
					<div style="font-size:22px;font-weight:700;margin-top:8px;">%s</div>
				</div>',
				esc_attr( $bs ),
				esc_attr( $slug ),
				esc_html( $plan['label'] ),
				esc_html( $slug ),
				$price_label
			);
		}
		echo '</div>';

		// ── Per-plan editor cards ──────────────────────────────────────────
		foreach ( $plans as $slug => $plan ) {
			$border  = isset( $tier_borders[ $slug ] ) ? $tier_borders[ $slug ] : '#444';
			$bs      = isset( $tier_badge_styles[ $slug ] ) ? $tier_badge_styles[ $slug ] : 'background:#444;color:#fff';
			$is_free = ( $slug === 'free' );
			$price   = (float) $plan['price'];
			$limits  = $plan['limits'];
			$enabled_features = is_array( $plan['features'] ) ? $plan['features'] : array();
			$enabled_models   = is_array( $plan['models'] )   ? $plan['models']   : array();

			echo '<div id="bmp-card-' . esc_attr( $slug ) . '" class="postbox" style="max-width:1100px;margin-bottom:28px;border-left:4px solid ' . esc_attr( $border ) . ';">';
			echo '<div class="inside" style="padding:16px 20px;">';

			// Card header badge row.
			echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;">';
			printf( '<span style="%s;padding:5px 18px;border-radius:20px;font-weight:700;font-size:14px;">%s</span>', esc_attr( $bs ), esc_html( $plan['label'] ) );
			printf( '<code style="color:#646970;background:#f0f0f0;padding:2px 8px;border-radius:4px;">%s</code>', esc_html( $slug ) );
			echo '<span style="margin-left:auto;display:flex;align-items:center;gap:6px;">';
			echo '<input type="checkbox" checked disabled> <span style="font-size:12px;color:#646970;">' . esc_html__( 'Gói đang hoạt động', 'bizcity-twin-ai' ) . '</span>';
			echo '</span>';
			echo '</div>';

			// Form — POST to admin-post.php (no GET side-effects).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'bizcity_membership_save_plan' );
			echo '<input type="hidden" name="action" value="bizcity_membership_save_plan">';
			echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';

			// Row 1: label / price / billing cycle.
			echo '<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:16px;margin-bottom:20px;">';
			printf( '<div><label style="font-size:12px;color:#646970;display:block;margin-bottom:4px;">%s</label><input type="text" name="label" value="%s" class="large-text" required></div>',
				esc_html__( 'Tên gói', 'bizcity-twin-ai' ),
				esc_attr( $plan['label'] )
			);
			printf( '<div><label style="font-size:12px;color:#646970;display:block;margin-bottom:4px;">%s</label><div style="display:flex;align-items:center;gap:4px;"><span>$</span><input type="number" step="0.01" min="0" name="price" value="%s" style="width:90px;"></div></div>',
				esc_html__( 'Giá (USD/tháng)', 'bizcity-twin-ai' ),
				esc_attr( number_format( $price, 2 ) )
			);
			$cycle = $plan['billing_cycle'];
			echo '<div><label style="font-size:12px;color:#646970;display:block;margin-bottom:4px;">' . esc_html__( 'Chu kỳ thanh toán', 'bizcity-twin-ai' ) . '</label>';
			echo '<select name="billing_cycle">';
			foreach ( array( 'lifetime' => 'lifetime', 'month' => 'month', 'year' => 'year' ) as $v => $l ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $cycle, $v, false ), esc_html( $l ) );
			}
			echo '</select></div>';
			echo '</div>'; // end row 1.

			// Row 2: Group 1 features + Group 2 quotas.
			echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px;">';

			// Group 1 — Features.
			echo '<div style="background:#f0f8ff;border:1px solid #c3d9f0;border-radius:6px;padding:14px 16px;">';
			echo '<div style="font-size:13px;font-weight:600;color:#2271b1;margin-bottom:10px;">💡 ' . esc_html__( 'Nhóm 1 — Tính năng được bật', 'bizcity-twin-ai' ) . ' <small style="font-weight:normal;color:#646970;">' . esc_html__( 'Tick để bật cho gói này', 'bizcity-twin-ai' ) . '</small></div>';
			echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">';
			foreach ( $feature_catalog as $fkey => $flabel ) {
				$checked = in_array( $fkey, $enabled_features, true ) ? ' checked' : '';
				printf(
					'<label style="display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:4px;background:#fff;cursor:pointer;border:1px solid #e8e8e8;">
						<input type="checkbox" name="features[]" value="%s"%s>
						<span style="font-size:12px;">%s</span>
					</label>',
					esc_attr( $fkey ),
					$checked,
					esc_html( $flabel )
				);
			}
			echo '</div></div>'; // end Group 1.

			// Group 2 — Quota.
			echo '<div style="background:#fffbeb;border:1px solid #f5d87a;border-radius:6px;padding:14px 16px;">';
			echo '<div style="font-size:13px;font-weight:600;color:#92400e;margin-bottom:10px;">📊 ' . esc_html__( 'Nhóm 2 — Quota & Giới hạn sử dụng', 'bizcity-twin-ai' ) . ' <small style="font-weight:normal;color:#646970;">' . esc_html__( '(0 = không giới hạn)', 'bizcity-twin-ai' ) . '</small></div>';
			foreach ( $quota_fields as $qkey => $qinfo ) {
				$val = isset( $limits[ $qkey ] ) ? (int) $limits[ $qkey ] : 0;
				echo '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">';
				echo '<div><div style="font-size:13px;color:#3c3c3c;font-weight:500;">' . esc_html( $qinfo[0] ) . '</div>';
				echo '<div style="font-size:11px;color:#999;">' . esc_html( $qinfo[1] ) . '</div></div>';
				echo '<input type="number" min="0" name="' . esc_attr( $qkey ) . '" value="' . esc_attr( $val ) . '" style="width:90px;text-align:right;">';
				echo '</div>';
			}
			echo '</div>'; // end Group 2.
			echo '</div>'; // end row 2 grid.

			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — max file size for KG uploads.
			$max_mb = isset( $plan['kg_max_file_size_mb'] ) && (int) $plan['kg_max_file_size_mb'] > 0 ? (int) $plan['kg_max_file_size_mb'] : 5;
			echo '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:16px;">';
			echo '<div style="font-size:13px;font-weight:600;color:#9a3412;">📦 ' . esc_html__( 'Dung lượng file tối đa cho KG upload', 'bizcity-twin-ai' ) . '</div>';
			echo '<div style="display:flex;align-items:center;gap:6px;">';
			echo '<input type="number" min="1" max="500" name="kg_max_file_size_mb" value="' . esc_attr( $max_mb ) . '" style="width:80px;text-align:right;">';
			echo '<span style="font-size:13px;color:#9a3412;">MB</span>';
			echo '</div>';
			echo '<div style="font-size:11px;color:#999;flex:1;">' . esc_html__( 'Áp dụng cho tất cả định dạng. Free: 5 MB, Pro: 20 MB, Plus: 50 MB.', 'bizcity-twin-ai' ) . '</div>';
			echo '</div>'; // end file size row.

			// Models row.
			echo '<div style="background:#f0fff4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;margin-bottom:16px;">';
			echo '<div style="font-size:13px;font-weight:600;color:#166534;margin-bottom:8px;">🤖 ' . esc_html__( 'Models được phép dùng', 'bizcity-twin-ai' ) . '</div>';
			echo '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
			foreach ( $model_catalog as $mkey => $mlabel ) {
				$checked = in_array( $mkey, $enabled_models, true ) ? ' checked' : '';
				printf(
					'<label style="display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:4px;background:#fff;cursor:pointer;border:1px solid #e8e8e8;">
						<input type="checkbox" name="models[]" value="%s"%s>
						<span style="font-size:12px;">%s</span>
					</label>',
					esc_attr( $mkey ),
					$checked,
					esc_html( $mlabel )
				);
			}
			echo '</div></div>'; // end models.

			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — file types allowed section.
			// [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — mode toggle + hub preview added.
			$enabled_file_types = isset( $plan['kg_accepted_file_types'] ) && is_array( $plan['kg_accepted_file_types'] )
				? $plan['kg_accepted_file_types']
				: array( 'txt', 'md', 'docx', 'xlsx', 'pptx', 'rtf' );
			$ft_mode = isset( $plan['kg_file_types_mode'] ) ? $plan['kg_file_types_mode'] : 'inherit';
			// Resolve hub-granted types to display in the banner.
			$site_master_level = (string) get_option( 'bizcity_hub_master_level', 'free' );
			$hub_granted_types = class_exists( 'BizCity_Membership_Plan_Registry' )
				? BizCity_Membership_Plan_Registry::hub_file_types_for_level( $site_master_level )
				: array();
			echo '<div style="background:#fdf4ff;border:1px solid #e9d5ff;border-radius:6px;padding:12px 16px;margin-bottom:16px;">';
			echo '<div style="font-size:13px;font-weight:600;color:#6b21a8;margin-bottom:6px;">📎 ' . esc_html__( 'Định dạng file được phép upload vào KG', 'bizcity-twin-ai' ) . '</div>';

			// ── Hub license banner ──────────────────────────────────────────────
			echo '<div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:4px;padding:8px 12px;margin-bottom:8px;font-size:12px;color:#5b21b6;line-height:1.8;">';
			echo '<strong>🎫 License hub hiện tại: <code style="background:#fff;padding:1px 6px;border-radius:3px;border:1px solid #c4b5fd;">'
				. esc_html( strtoupper( $site_master_level ) )
				. '</code></strong> — '
				. esc_html__( 'Định dạng được cấp phép:', 'bizcity-twin-ai' ) . ' ';
			if ( ! empty( $hub_granted_types ) ) {
				foreach ( $hub_granted_types as $ht ) {
					echo '<code style="margin-right:3px;background:#fff;padding:1px 5px;border-radius:3px;border:1px solid #c4b5fd;">' . esc_html( $ht ) . '</code>';
				}
			} else {
				echo '<em>' . esc_html__( '(chưa có API key — mặc định free)', 'bizcity-twin-ai' ) . '</em>';
			}
			echo '</div>';

			// ── Explanation note ───────────────────────────────────────────────
			echo '<div style="font-size:11px;color:#6d28d9;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:4px;padding:7px 10px;margin-bottom:10px;line-height:1.7;">';
			echo '<strong>ℹ️ Nguyên tắc Hub-First:</strong> ';
			echo esc_html__( 'License hub = trần tuyệt đối. Plan local chỉ có thể giới hạn bớp, không thể mở rộng hơn hub. ', 'bizcity-twin-ai' );
			echo '<br>• <strong>' . esc_html__( 'Chế độ mặc định (Kế thừa hub):', 'bizcity-twin-ai' ) . '</strong> '
				. esc_html__( 'member trong plan này được dùng toàn bộ định dạng hub đã cấp — không cần cấu hình thêm.', 'bizcity-twin-ai' );
			echo '<br>• <strong>' . esc_html__( 'Chế độ Hạn chế tùy chỉnh:', 'bizcity-twin-ai' ) . '</strong> '
				. esc_html__( 'chỉ chọn khi muốn ngăn plan này dùng một số định dạng cụ thể (ví dụ plan Free không cho pdf dù hub có). Kết quả = giao giữa danh sách bên dưới và license hub.', 'bizcity-twin-ai' );
			echo '</div>';

			// ── Mode toggle ────────────────────────────────────────────────────
			echo '<div style="display:flex;gap:0;flex-direction:column;margin-bottom:12px;border:1px solid #ddd6fe;border-radius:6px;overflow:hidden;">';
			// Option 1: inherit
			$inherit_bg  = $ft_mode === 'inherit'  ? 'background:#f0fdf4;border-left:4px solid #22c55e;' : 'background:#fff;border-left:4px solid transparent;';
			$restrict_bg = $ft_mode === 'restrict' ? 'background:#fff7ed;border-left:4px solid #f97316;' : 'background:#fff;border-left:4px solid transparent;';
			echo '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 14px;' . $inherit_bg . '">';
			printf( '<input type="radio" name="kg_file_types_mode" value="inherit"%s style="margin-top:3px;">', $ft_mode === 'inherit' ? ' checked' : '' );
			echo '<div>'
				. '<div style="font-size:12px;font-weight:600;color:#15803d;">🔓 ' . esc_html__( 'Kế thừa license hub — Mặc định (khuyến nghị)', 'bizcity-twin-ai' ) . '</div>'
				. '<div style="font-size:11px;color:#555;margin-top:2px;">'
				. esc_html__( 'Member được dùng tất cả định dạng mà license hub đã cấp (hiển thị ở banner trên). Khi nâng cấp license hub, plan tự động mở rộng theo — không cần sửa lại.', 'bizcity-twin-ai' )
				. '</div></div></label>';
			echo '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 14px;border-top:1px solid #e5e7eb;' . $restrict_bg . '">';
			printf( '<input type="radio" name="kg_file_types_mode" value="restrict"%s style="margin-top:3px;">', $ft_mode === 'restrict' ? ' checked' : '' );
			echo '<div>'
				. '<div style="font-size:12px;font-weight:600;color:#c2410c;">🔒 ' . esc_html__( 'Hạn chế tùy chỉnh — Chỉ dùng khi muốn giới hạn bớt', 'bizcity-twin-ai' ) . '</div>'
				. '<div style="font-size:11px;color:#555;margin-top:2px;">'
				. esc_html__( 'Chỉ cho phép định dạng được tích bên dưới. Kết quả thực tế = giao giữa tích chọn và license hub — không thể vượt trần hub.', 'bizcity-twin-ai' )
				. '</div></div></label>';
			echo '</div>'; // end mode toggle

			// ── Checkboxes (restrict list) ─────────────────────────────────────
			$restrict_opacity = $ft_mode === 'restrict' ? '1' : '0.4';
			$pointer_events   = $ft_mode === 'restrict' ? 'auto' : 'none';
			echo '<div id="kg_ft_restrict_' . esc_attr( $slug ) . '" style="opacity:' . $restrict_opacity . ';pointer-events:' . $pointer_events . ';transition:opacity .25s;">';
			echo '<div style="font-size:11px;color:#92400e;margin-bottom:7px;padding:6px 10px;background:#fef3c7;border:1px solid #fde68a;border-radius:4px;">';
			echo '⚠️ ' . esc_html__( 'Chỉ có hiệu lực khi chế độ "Hạn chế tùy chỉnh" được chọn. Định dạng tích nhưng hub không cấp sẽ tự động bị loại.', 'bizcity-twin-ai' );
			echo '</div>';
			foreach ( $file_type_groups as $group_label => $exts ) {
				echo '<div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">';
				echo '<span style="font-size:11px;color:#6b21a8;min-width:100px;">' . esc_html( $group_label ) . '</span>';
				echo '<div style="display:flex;flex-wrap:wrap;gap:5px;">';
				foreach ( $exts as $ext ) {
					$checked = in_array( $ext, $enabled_file_types, true ) ? ' checked' : '';
					printf(
						'<label style="display:flex;align-items:center;gap:4px;padding:3px 8px;border-radius:4px;background:#fff;cursor:pointer;border:1px solid #e8e8e8;">
							<input type="checkbox" name="kg_accepted_file_types[]" value="%s"%s>
							<code style="font-size:11px;">%s</code>
						</label>',
						esc_attr( $ext ),
						$checked,
						esc_html( $ext )
					);
				}
				echo '</div></div>';
			}
			echo '</div>'; // end restrict div
			// Inline JS to toggle opacity + pointer-events when mode radio changes.
			?>
			<script>
			(function(){
				var radios = document.querySelectorAll('[name="kg_file_types_mode"]');
				var box    = document.getElementById('kg_ft_restrict_<?php echo esc_js( $slug ); ?>');
				if (!box || !radios.length) return;
				function update() {
					var v = document.querySelector('[name="kg_file_types_mode"]:checked');
					var on = (v && v.value === 'restrict');
					box.style.opacity       = on ? '1' : '0.4';
					box.style.pointerEvents = on ? 'auto' : 'none';
					// highlight selected option
					radios.forEach(function(r){
						var lbl = r.closest('label');
						if (!lbl) return;
						if (r.value === 'inherit') {
							lbl.style.background   = (!on) ? '#f0fdf4' : '#fff';
							lbl.style.borderLeft   = (!on) ? '4px solid #22c55e' : '4px solid transparent';
						} else {
							lbl.style.background   = on ? '#fff7ed' : '#fff';
							lbl.style.borderLeft   = on ? '4px solid #f97316' : '4px solid transparent';
						}
					});
				}
				radios.forEach(function(r){ r.addEventListener('change', update); });
				update();
			})();
			</script>
			<?php
			echo '</div>'; // end file types.

			// Action buttons.
			echo '<div style="display:flex;align-items:center;gap:12px;">';
			printf(
				'<button type="submit" class="button button-primary">💾 %s</button>',
				esc_html( sprintf( __( 'Lưu gói %s', 'bizcity-twin-ai' ), $plan['label'] ) )
			);
			if ( ! $is_free ) {
				echo '<span style="margin-left:auto;">';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Xóa gói này? Không thể hoàn tác.', 'bizcity-twin-ai' ) ) . '\')">';
				wp_nonce_field( 'bizcity_membership_delete_plan', '_wpnonce_del' );
				echo '<input type="hidden" name="action" value="bizcity_membership_delete_plan">';
				echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';
				echo '<button type="submit" class="button" style="color:#d63638;border-color:#d63638;">🗑 ' . esc_html__( 'Xóa gói', 'bizcity-twin-ai' ) . '</button>';
				echo '</form></span>';
			}
			echo '</div>'; // end actions.

			echo '</form>';
			echo '</div></div>'; // .inside + .postbox
		}

		// ── Add new plan card ──────────────────────────────────────────────
		$show_add = isset( $_GET['add'] ) && (int) $_GET['add'] === 1;
		printf(
			'<p><a href="%s" class="button">+ %s</a></p>',
			esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'plans', 'add' => '1' ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Thêm gói mới', 'bizcity-twin-ai' )
		);

		if ( $show_add ) {
			echo '<div class="postbox" style="max-width:600px;border-left:4px solid #646970;margin-top:8px;">';
			echo '<div class="inside" style="padding:16px 20px;">';
			echo '<h3 style="margin:0 0 14px;">+ ' . esc_html__( 'Tạo gói mới', 'bizcity-twin-ai' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'bizcity_membership_save_plan' );
			echo '<input type="hidden" name="action" value="bizcity_membership_save_plan">';
			echo '<table class="form-table"><tbody>';
			self::field( __( 'Slug', 'bizcity-twin-ai' ), '<input type="text" name="slug" value="" required pattern="[a-z0-9_\\-]+" placeholder="vd: vip">' );
			self::field( __( 'Tên gói', 'bizcity-twin-ai' ), '<input type="text" name="label" value="" required>' );
			self::field( __( 'Giá (USD)', 'bizcity-twin-ai' ), '<input type="number" step="0.01" min="0" name="price" value="0">' );
			self::field( __( 'Chu kỳ', 'bizcity-twin-ai' ), '<select name="billing_cycle"><option value="month">month</option><option value="year">year</option><option value="lifetime">lifetime</option></select>' );
			echo '</tbody></table>';
			submit_button( __( 'Tạo gói', 'bizcity-twin-ai' ) );
			echo '</form></div></div>';
		}
	}

	/* ── Tab: Members ───────────────────────────────────────────────────── */

	private static function tab_members() {
		$manager  = BizCity_Membership_Manager::instance();
		$registry = BizCity_Membership_Plan_Registry::instance();
		$plans    = $registry->all();

		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 20;
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$args = array( 'number' => $per, 'offset' => ( $paged - 1 ) * $per, 'fields' => array( 'ID', 'user_login', 'user_email', 'display_name' ) );
		if ( $search !== '' ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$query = new WP_User_Query( $args );
		$users = $query->get_results();
		$total = (int) $query->get_total();

		// Search box.
		echo '<form method="get" class="bizm-search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
		echo '<input type="hidden" name="tab" value="members">';
		printf(
			'<input type="search" name="s" value="%s" placeholder="%s"> ',
			esc_attr( $search ),
			esc_attr__( 'Search user…', 'bizcity-twin-ai' )
		);
		submit_button( __( 'Search', 'bizcity-twin-ai' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped bizm-table"><thead><tr>';
		echo '<th>' . esc_html__( 'User', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Plan', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Assign', 'bizcity-twin-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $users as $u ) {
			$uid  = (int) $u->ID;
			$slug = $manager->plan_for_user( $uid );
			$lbl  = isset( $plans[ $slug ]['label'] ) ? $plans[ $slug ]['label'] : ucfirst( $slug );

			echo '<tr>';
			printf( '<td><strong>%s</strong><br><small>#%d %s</small></td>', esc_html( $u->display_name ), $uid, esc_html( $u->user_login ) );
			printf( '<td>%s</td>', esc_html( $u->user_email ) );
			printf( '<td><span class="bizm-badge bizm-badge-%s">%s</span></td>', esc_attr( $slug ), esc_html( $lbl ) );

			// Inline assign form.
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bizm-inline">';
			wp_nonce_field( 'bizcity_membership_assign_' . $uid );
			echo '<input type="hidden" name="action" value="bizcity_membership_assign">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( $uid ) . '">';
			echo '<select name="plan_slug">';
			foreach ( $plans as $ps => $p ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $ps ), selected( $ps, $slug, false ), esc_html( $p['label'] ) );
			}
			echo '</select> ';
			submit_button( __( 'Set', 'bizcity-twin-ai' ), 'small', '', false );
			echo ' <button type="submit" class="button button-small button-link-delete" name="do_cancel" value="1">' . esc_html__( 'Cancel', 'bizcity-twin-ai' ) . '</button>';
			echo '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::pagination( $total, $per, $paged, array( 'tab' => 'members', 's' => $search ) );
	}

	/* ── Tab: Payments ──────────────────────────────────────────────────── */

	private static function tab_payments() {
		if ( ! class_exists( 'BizCity_Membership_Payments' ) ) {
			echo '<p>' . esc_html__( 'Payments module not loaded.', 'bizcity-twin-ai' ) . '</p>';
			return;
		}
		$payments = BizCity_Membership_Payments::instance();
		$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per      = 30;
		$rows     = $payments->recent( array( 'limit' => $per, 'offset' => ( $paged - 1 ) * $per ) );

		echo '<table class="widefat striped bizm-table"><thead><tr>';
		echo '<th>#</th>';
		echo '<th>' . esc_html__( 'User', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Plan', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Gateway', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Transaction', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Paid at', 'bizcity-twin-ai' ) . '</th>';
		// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — refund action column.
		echo '<th>' . esc_html__( 'Actions', 'bizcity-twin-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No payments yet.', 'bizcity-twin-ai' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$user = get_userdata( (int) $r['user_id'] );
			$refund_btn = '';
			// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — show Refund button for completed PayPal rows only.
			if ( (string) $r['status'] === 'completed' && (string) $r['gateway'] === 'paypal' ) {
				$refund_url = wp_nonce_url(
					add_query_arg(
						array( 'action' => 'bizcity_membership_refund', 'payment_id' => (int) $r['id'] ),
						admin_url( 'admin-post.php' )
					),
					'bizm_refund_' . (int) $r['id']
				);
				$refund_btn = '<a href="' . esc_url( $refund_url ) . '" class="button button-small" onclick="return confirm(\'' . esc_js( __( 'Hoàn tiền PayPal cho payment này? Không thể hoàn tác.', 'bizcity-twin-ai' ) ) . '\')">'
					. esc_html__( 'Refund', 'bizcity-twin-ai' ) . '</a>';
			}
			printf(
				'<tr><td>%d</td><td>%s</td><td><code>%s</code></td><td>%s %s</td><td><span class="bizm-badge bizm-badge-%s">%s</span></td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
				(int) $r['id'],
				$user ? esc_html( $user->display_name ) : ( '#' . (int) $r['user_id'] ),
				esc_html( $r['plan_slug'] ),
				esc_html( number_format( (float) $r['amount'], 2 ) ),
				esc_html( $r['currency'] ),
				esc_attr( $r['status'] ),
				esc_html( $r['status'] ),
				esc_html( $r['gateway'] ),
				esc_html( $r['transaction_id'] ),
				esc_html( $r['paid_at'] ? $r['paid_at'] : $r['created_at'] ),
				$refund_btn
			);
		}
		echo '</tbody></table>';

		if ( count( $rows ) >= $per || $paged > 1 ) {
			self::pagination_simple( $paged, count( $rows ) >= $per, array( 'tab' => 'payments' ) );
		}
	}

	/* ── Tab: Settings ──────────────────────────────────────────────────── */

	private static function tab_settings() {
		$gw = class_exists( 'BizCity_Membership_PayPal_Gateway' )
			? BizCity_Membership_PayPal_Gateway::instance()->settings()
			: array( 'client_id' => '', 'client_secret' => '', 'mode' => 'sandbox', 'enabled' => false );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bizm-form">';
		wp_nonce_field( 'bizcity_membership_save_settings' );
		echo '<input type="hidden" name="action" value="bizcity_membership_save_settings">';

		echo '<h2>' . esc_html__( 'PayPal (client account)', 'bizcity-twin-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use your OWN PayPal app credentials. Membership revenue goes directly to your PayPal — it is separate from the BizCity LLM credit.', 'bizcity-twin-ai' ) . '</p>';

		echo '<table class="form-table"><tbody>';
		self::field( __( 'Enabled', 'bizcity-twin-ai' ), sprintf(
			'<label><input type="checkbox" name="enabled" value="1"%s> %s</label>',
			checked( $gw['enabled'], true, false ),
			esc_html__( 'Allow members to pay via PayPal', 'bizcity-twin-ai' )
		) );
		$mode = $gw['mode'];
		self::field( __( 'Mode', 'bizcity-twin-ai' ), sprintf(
			'<select name="mode"><option value="sandbox"%s>Sandbox</option><option value="live"%s>Live</option></select>',
			selected( $mode, 'sandbox', false ),
			selected( $mode, 'live', false )
		) );
		self::field( __( 'Client ID', 'bizcity-twin-ai' ), sprintf( '<input type="text" name="client_id" value="%s" class="large-text" autocomplete="off">', esc_attr( $gw['client_id'] ) ) );
		// Secret: show masked placeholder; only overwrite when a new value is typed.
		$secret_set = $gw['client_secret'] !== '';
		self::field( __( 'Client Secret', 'bizcity-twin-ai' ), sprintf(
			'<input type="password" name="client_secret" value="" class="large-text" autocomplete="off" placeholder="%s">',
			$secret_set ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'bizcity-twin-ai' ) : esc_attr__( 'Paste secret', 'bizcity-twin-ai' )
		) );
		echo '</tbody></table>';

		$webhook = home_url( '/wp-json/bizcity-membership/v1/webhook' );
		echo '<p class="description">' . esc_html__( 'PayPal Webhook URL:', 'bizcity-twin-ai' ) . ' <code>' . esc_html( $webhook ) . '</code></p>';

		submit_button( __( 'Save settings', 'bizcity-twin-ai' ) );
		echo '</form>';

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — provision recurring
		// billing plans on PayPal (Subscriptions v2) so paid plans auto-charge.
		self::tab_settings_provision();
	}

	private static function tab_settings_provision() {
		$plans = class_exists( 'BizCity_Membership_Plan_Registry' )
			? BizCity_Membership_Plan_Registry::instance()->all()
			: array();

		$recurring = array();
		foreach ( $plans as $slug => $plan ) {
			$price = isset( $plan['price'] ) ? (float) $plan['price'] : 0.0;
			$cycle = isset( $plan['billing_cycle'] ) ? (string) $plan['billing_cycle'] : '';
			if ( $price > 0 && $cycle !== '' && $cycle !== 'once' ) {
				$recurring[ $slug ] = $plan;
			}
		}
		if ( empty( $recurring ) ) {
			return;
		}

		echo '<hr>';
		echo '<h2>' . esc_html__( 'Recurring auto-charge (PayPal Subscriptions)', 'bizcity-twin-ai' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Provision PayPal billing plans so members are auto-charged each cycle. Re-run after changing a plan price or cycle.', 'bizcity-twin-ai' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:680px;margin:8px 0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Plan', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'Cycle', 'bizcity-twin-ai' ) . '</th>';
		echo '<th>' . esc_html__( 'PayPal plan ID', 'bizcity-twin-ai' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $recurring as $slug => $plan ) {
			$ppid = isset( $plan['paypal_plan_id'] ) ? (string) $plan['paypal_plan_id'] : '';
			echo '<tr>';
			echo '<td>' . esc_html( isset( $plan['label'] ) ? $plan['label'] : $slug ) . '</td>';
			echo '<td>' . esc_html( isset( $plan['billing_cycle'] ) ? $plan['billing_cycle'] : '' ) . '</td>';
			echo '<td>' . ( $ppid !== '' ? '<code>' . esc_html( $ppid ) . '</code>' : '<span style="color:#b32d2e;">' . esc_html__( 'not provisioned', 'bizcity-twin-ai' ) . '</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'bizcity_membership_provision_paypal' );
		echo '<input type="hidden" name="action" value="bizcity_membership_provision_paypal">';
		submit_button( __( 'Provision / sync PayPal plans', 'bizcity-twin-ai' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/* ── Handlers ───────────────────────────────────────────────────────── */

	// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP M1 — updated: features[] as checkboxes, video_per_day added.
	public static function handle_save_plan() {
		self::guard( 'bizcity_membership_save_plan' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( $slug === '' ) {
			self::redirect( 'plans', 'err', 'bad_slug' );
		}

		$registry = BizCity_Membership_Plan_Registry::instance();
		$plans    = $registry->all();

		// features[] and models[] come from checkboxes (may be absent when all unchecked).
		$raw_features = isset( $_POST['features'] ) && is_array( $_POST['features'] ) ? $_POST['features'] : array();
		$raw_models   = isset( $_POST['models'] ) && is_array( $_POST['models'] ) ? $_POST['models'] : array();
		$features     = array_values( array_unique( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $raw_features ) ) ) );
		$models       = array_values( array_unique( array_map( 'sanitize_key',        array_map( 'wp_unslash', $raw_models ) ) ) );

		$plans[ $slug ] = array(
			'label'                  => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ucfirst( $slug ),
			'price'                  => isset( $_POST['price'] ) ? (float) $_POST['price'] : 0.0,
			'currency'               => 'USD',
			'billing_cycle'          => isset( $_POST['billing_cycle'] ) ? sanitize_key( wp_unslash( $_POST['billing_cycle'] ) ) : 'month',
			'paypal_plan_id'         => isset( $plans[ $slug ]['paypal_plan_id'] ) ? $plans[ $slug ]['paypal_plan_id'] : '',
			'limits'                 => array(
				'chat_msgs_per_day'   => isset( $_POST['chat_msgs_per_day'] )   ? max( 0, (int) $_POST['chat_msgs_per_day'] )   : 0,
				'kg_passages_per_day' => isset( $_POST['kg_passages_per_day'] ) ? max( 0, (int) $_POST['kg_passages_per_day'] ) : 0,
				'image_per_day'       => isset( $_POST['image_per_day'] )       ? max( 0, (int) $_POST['image_per_day'] )       : 0,
				'video_per_day'       => isset( $_POST['video_per_day'] )       ? max( 0, (int) $_POST['video_per_day'] )       : 0,
			),
			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — sanitize kg_accepted_file_types checkbox array.
			'kg_accepted_file_types' => self::sanitize_file_types(
				isset( $_POST['kg_accepted_file_types'] ) && is_array( $_POST['kg_accepted_file_types'] ) ? $_POST['kg_accepted_file_types'] : array()
			),
			// [2026-07-02 Johnny Chu] HOTFIX R-KG-HUB-FIRST — persist mode: 'inherit'=hub-first; 'restrict'=custom.
			'kg_file_types_mode'     => ( isset( $_POST['kg_file_types_mode'] ) && $_POST['kg_file_types_mode'] === 'restrict' )
				? 'restrict'
				: 'inherit',
			// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — max file size MB per plan.
			'kg_max_file_size_mb'    => max( 1, min( 500, (int) ( isset( $_POST['kg_max_file_size_mb'] ) ? $_POST['kg_max_file_size_mb'] : 5 ) ) ),
			'features'               => $features,
			'models'                 => $models,
		);

		$registry->save( $plans );
		self::redirect( 'plans', 'ok', 'plan_saved' );
	}

	public static function handle_delete_plan() {
		self::guard( 'bizcity_membership_delete_plan' );

		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( $slug === '' || $slug === 'free' ) {
			self::redirect( 'plans', 'err', 'cannot_delete' );
		}

		$registry = BizCity_Membership_Plan_Registry::instance();
		$plans    = $registry->all();
		if ( isset( $plans[ $slug ] ) ) {
			unset( $plans[ $slug ] );
			$registry->save( $plans );
		}
		self::redirect( 'plans', 'ok', 'plan_deleted' );
	}

	public static function handle_assign() {
		$uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		self::guard( 'bizcity_membership_assign_' . $uid );

		$manager = BizCity_Membership_Manager::instance();

		if ( ! empty( $_POST['do_cancel'] ) ) {
			$manager->clear_plan( $uid );
			self::redirect( 'members', 'ok', 'cancelled' );
		}

		$slug = isset( $_POST['plan_slug'] ) ? sanitize_key( wp_unslash( $_POST['plan_slug'] ) ) : '';
		if ( $uid <= 0 || $slug === '' ) {
			self::redirect( 'members', 'err', 'bad_input' );
		}
		$manager->set_plan( $uid, $slug, '', BizCity_Membership_Manager::SOURCE_ADMIN );
		self::redirect( 'members', 'ok', 'assigned' );
	}

	public static function handle_cancel() {
		$uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		self::guard( 'bizcity_membership_assign_' . $uid );
		BizCity_Membership_Manager::instance()->clear_plan( $uid );
		self::redirect( 'members', 'ok', 'cancelled' );
	}

	public static function handle_save_settings() {
		self::guard( 'bizcity_membership_save_settings' );

		$existing = get_option( BizCity_Membership_PayPal_Gateway::OPT_SETTINGS, array() );
		$existing = is_array( $existing ) ? $existing : array();

		$secret_in = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';

		$settings = array(
			'client_id'     => isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
			// Keep existing secret if the field was left blank.
			'client_secret' => $secret_in !== '' ? $secret_in : ( isset( $existing['client_secret'] ) ? (string) $existing['client_secret'] : '' ),
			'mode'          => ( isset( $_POST['mode'] ) && $_POST['mode'] === 'live' ) ? 'live' : 'sandbox',
			'enabled'       => ! empty( $_POST['enabled'] ),
		);

		update_option( BizCity_Membership_PayPal_Gateway::OPT_SETTINGS, $settings );
		delete_transient( 'bizcity_membership_paypal_token' );
		self::redirect( 'settings', 'ok', 'settings_saved' );
	}

	// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — provision PayPal billing plans.
	public static function handle_provision_paypal() {
		self::guard( 'bizcity_membership_provision_paypal' );

		if ( ! class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			self::redirect( 'settings', 'err', 'provision_failed' );
		}
		$res = BizCity_Membership_PayPal_Gateway::instance()->provision_all();
		if ( is_wp_error( $res ) ) {
			self::redirect( 'settings', 'err', 'provision_failed' );
		}
		self::redirect( 'settings', 'ok', 'provisioned' );
	}

	// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — refund action handler (admin-post).
	public static function handle_refund() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}
		$payment_id = isset( $_GET['payment_id'] ) ? (int) $_GET['payment_id'] : 0;
		check_admin_referer( 'bizm_refund_' . $payment_id );

		if ( $payment_id <= 0 ) {
			self::redirect( 'payments', 'err', 'bad_input' );
		}
		if ( ! class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			self::redirect( 'payments', 'err', 'refund_failed' );
		}
		$result = BizCity_Membership_PayPal_Gateway::instance()->refund_payment( $payment_id );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'payments', 'err', 'refund_failed' );
		}
		self::redirect( 'payments', 'ok', 'refunded' );
	}

	/* ── Helpers ────────────────────────────────────────────────────────── */

	// [2026-06-11 Johnny Chu] R-KG-FILE-TYPES — sanitize raw checkbox array to allowed extensions only.
	private static function sanitize_file_types( array $raw ) {
		static $allowed = array( 'txt', 'md', 'rtf', 'csv', 'tsv', 'docx', 'xlsx', 'pptx', 'doc', 'xls', 'ppt', 'odt', 'ods', 'odp', 'pdf', 'mp3', 'mp4', 'm4a', 'wav', 'ogg' );
		$clean = array();
		foreach ( $raw as $val ) {
			$ext = sanitize_key( wp_unslash( (string) $val ) );
			if ( in_array( $ext, $allowed, true ) && ! in_array( $ext, $clean, true ) ) {
				$clean[] = $ext;
			}
		}
		return $clean;
	}

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( $tab, $type, $code ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => self::MENU_SLUG, 'tab' => $tab, 'bizm_' . $type => $code ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	private static function render_notice() {
		if ( isset( $_GET['bizm_ok'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['bizm_ok'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::msg( $code ) ) . '</p></div>';
		}
		if ( isset( $_GET['bizm_err'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['bizm_err'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( self::msg( $code ) ) . '</p></div>';
		}
	}

	private static function msg( $code ) {
		$map = array(
			'plan_saved'       => __( 'Plan saved.', 'bizcity-twin-ai' ),
			'plan_deleted'     => __( 'Plan deleted.', 'bizcity-twin-ai' ),
			'assigned'         => __( 'Plan assigned.', 'bizcity-twin-ai' ),
			'cancelled'        => __( 'Membership cancelled.', 'bizcity-twin-ai' ),
			'settings_saved'   => __( 'Settings saved.', 'bizcity-twin-ai' ),
			'provisioned'      => __( 'PayPal subscription plans provisioned.', 'bizcity-twin-ai' ),
			'provision_failed' => __( 'Could not provision PayPal plans. Check credentials.', 'bizcity-twin-ai' ),
			'bad_slug'         => __( 'Invalid plan slug.', 'bizcity-twin-ai' ),
			'cannot_delete'    => __( 'This plan cannot be deleted.', 'bizcity-twin-ai' ),
			'bad_input'        => __( 'Invalid input.', 'bizcity-twin-ai' ),
			// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — refund messages.
			'refunded'         => __( 'Hoàn tiền PayPal thành công.', 'bizcity-twin-ai' ),
			'refund_failed'    => __( 'Hoàn tiền thất bại. Kiểm tra PayPal credentials hoặc trạng thái giao dịch.', 'bizcity-twin-ai' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : $code;
	}

	private static function card( $label, $value, $icon ) {
		printf(
			'<div class="bizm-card"><span class="dashicons dashicons-%s"></span><div><div class="bizm-card-value">%s</div><div class="bizm-card-label">%s</div></div></div>',
			esc_attr( $icon ),
			esc_html( $value ),
			esc_html( $label )
		);
	}

	private static function field( $label, $control ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $control . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $control is pre-built trusted markup.
	}

	private static function pagination( $total, $per, $paged, $extra ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$args = array_merge( array( 'page' => self::MENU_SLUG, 'paged' => $i ), $extra );
			$url  = add_query_arg( $args, admin_url( 'admin.php' ) );
			printf(
				'<a class="button%s" href="%s">%d</a> ',
				$i === $paged ? ' button-primary' : '',
				esc_url( $url ),
				$i
			);
		}
		echo '</div></div>';
	}

	private static function pagination_simple( $paged, $has_next, $extra ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		if ( $paged > 1 ) {
			$url = add_query_arg( array_merge( array( 'page' => self::MENU_SLUG, 'paged' => $paged - 1 ), $extra ), admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $url ) . '">‹ ' . esc_html__( 'Prev', 'bizcity-twin-ai' ) . '</a> ';
		}
		if ( $has_next ) {
			$url = add_query_arg( array_merge( array( 'page' => self::MENU_SLUG, 'paged' => $paged + 1 ), $extra ), admin_url( 'admin.php' ) );
			echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Next', 'bizcity-twin-ai' ) . ' ›</a>';
		}
		echo '</div></div>';
	}

	/* ── Tab: Revenue Dashboard ─────────────────────────────────────────── */
	// [2026-06-07 Johnny Chu] PHASE-C C-3 — Revenue admin tab (read-only, bizcity_member_payments only).

	private static function tab_dashboard() {
		if ( ! class_exists( 'BizCity_Membership_Revenue_Report' ) ) {
			echo '<p>' . esc_html__( 'Revenue report module chưa load.', 'bizcity-twin-ai' ) . '</p>';
			return;
		}
		$hl    = BizCity_Membership_Revenue_Report::instance()->headline();
		$daily = BizCity_Membership_Revenue_Report::instance()->daily_series( 30 );
		$plans = BizCity_Membership_Revenue_Report::instance()->by_plan( date( 'Y-m-01' ) );

		echo '<div class="bizm-cards">';
		self::card_stat( '📅 Hôm nay', self::money( $hl['today_usd'] ), '' );
		self::card_stat( '📆 Tuần này', self::money( $hl['week_usd'] ), '' );
		self::card_stat( '🗓 Tháng này', self::money( $hl['month_usd'] ), esc_html( $hl['completed_count'] ) . ' giao dịch' );
		self::card_stat( '🔴 Hoàn tiền', self::money( $hl['refunded_month_usd'] ), 'trong tháng' );
		self::card_stat( '📦 MRR', self::money( $hl['mrr_usd'] ), '' );
		self::card_stat( '👥 Paying', (string) $hl['paying_members'], 'thành viên đang trả phí' );
		echo '</div>';

		// By-plan table (this month)
		if ( ! empty( $plans ) ) {
			echo '<h3>' . esc_html__( 'Theo gói (tháng này)', 'bizcity-twin-ai' ) . '</h3>';
			echo '<table class="widefat striped bizm-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Gói', 'bizcity-twin-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Doanh thu', 'bizcity-twin-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Giao dịch', 'bizcity-twin-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Thành viên', 'bizcity-twin-ai' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $plans as $p ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( strtoupper( $p['plan_slug'] ) ) . '</strong></td>';
				echo '<td>' . esc_html( self::money( $p['usd'] ) ) . '</td>';
				echo '<td>' . (int) $p['count'] . '</td>';
				echo '<td>' . (int) $p['members'] . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// 30-day history
		if ( ! empty( $daily ) ) {
			echo '<h3>' . esc_html__( '30 ngày gần nhất', 'bizcity-twin-ai' ) . '</h3>';
			echo '<table class="widefat striped bizm-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Ngày', 'bizcity-twin-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Doanh thu', 'bizcity-twin-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Giao dịch', 'bizcity-twin-ai' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( array_reverse( $daily ) as $d ) {
				echo '<tr>';
				echo '<td>' . esc_html( $d['date'] ) . '</td>';
				echo '<td>' . esc_html( self::money( $d['usd'] ) ) . '</td>';
				echo '<td>' . (int) $d['count'] . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/* ── Tab: Usage Trace ───────────────────────────────────────────────── */
	// [2026-06-07 Johnny Chu] PHASE-C C-3 — Usage trace admin tab (per-user).

	private static function tab_usage() {
		if ( ! class_exists( 'BizCity_Membership_Usage_Report' ) ) {
			echo '<p>' . esc_html__( 'Usage report module chưa load.', 'bizcity-twin-ai' ) . '</p>';
			return;
		}

		$period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '7d';
		if ( ! in_array( $period, array( '7d', '30d', '90d' ), true ) ) {
			$period = '7d';
		}

		// Period switcher
		echo '<div style="margin:12px 0 16px;">';
		foreach ( array( '7d' => '7 ngày', '30d' => '30 ngày', '90d' => '90 ngày' ) as $p => $label ) {
			$url = add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'usage', 'period' => $p ), admin_url( 'admin.php' ) );
			echo '<a href="' . esc_url( $url ) . '" class="button' . ( $period === $p ? ' button-primary' : '' ) . '" style="margin-right:4px">' . esc_html( $label ) . '</a>';
		}
		echo '</div>';

		// Top users
		$top = BizCity_Membership_Usage_Report::instance()->top_users( $period, 20 );
		echo '<h3>' . esc_html__( 'Top 20 user sử dụng nhiều nhất', 'bizcity-twin-ai' ) . '</h3>';
		if ( empty( $top ) ) {
			echo '<p>' . esc_html__( 'Chưa có dữ liệu.', 'bizcity-twin-ai' ) . '</p>';
		} else {
			echo '<table class="widefat striped bizm-table"><thead><tr>';
			echo '<th>User</th><th>Email</th><th>Lượt gọi</th><th>Token</th><th>Chi tiết</th>';
			echo '</tr></thead><tbody>';
			foreach ( $top as $u ) {
				$detail_url = add_query_arg(
					array( 'page' => self::MENU_SLUG, 'tab' => 'usage', 'period' => $period, 'uid' => (int) $u['user_id'] ),
					admin_url( 'admin.php' )
				);
				echo '<tr>';
				echo '<td>' . esc_html( $u['display_name'] ) . '</td>';
				echo '<td>' . esc_html( $u['email'] ) . '</td>';
				echo '<td>' . number_format( (int) $u['calls'] ) . '</td>';
				echo '<td>' . number_format( (int) $u['tokens'] ) . '</td>';
				echo '<td><a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Xem', 'bizcity-twin-ai' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// Per-user detail
		if ( isset( $_GET['uid'] ) && (int) $_GET['uid'] > 0 ) {
			$uid    = (int) $_GET['uid'];
			$detail = BizCity_Membership_Usage_Report::instance()->user_detail( $uid, $period );
			echo '<hr style="margin:24px 0">';
			$uname = isset( $detail['user']['display_name'] ) ? $detail['user']['display_name'] : '#' . $uid;
			echo '<h3>' . sprintf( esc_html__( 'Chi tiết: %s', 'bizcity-twin-ai' ), esc_html( $uname ) ) . '</h3>';

			// Token summary
			$tok = isset( $detail['tokens'] ) ? $detail['tokens'] : array();
			echo '<div class="bizm-cards">';
			self::card_stat( '🔤 Prompt', number_format( isset( $tok['prompt'] ) ? (int) $tok['prompt'] : 0 ), '' );
			self::card_stat( '💬 Completion', number_format( isset( $tok['completion'] ) ? (int) $tok['completion'] : 0 ), '' );
			self::card_stat( '💰 KG Cost (USD)', '$' . number_format( isset( $detail['kg_cost_usd'] ) ? (float) $detail['kg_cost_usd'] : 0, 4 ), '' );
			echo '</div>';

			// By service
			if ( ! empty( $detail['by_service'] ) ) {
				echo '<table class="widefat striped bizm-table"><thead><tr>';
				echo '<th>Dịch vụ</th><th>Lượt gọi</th><th>Token</th>';
				echo '</tr></thead><tbody>';
				foreach ( $detail['by_service'] as $s ) {
					echo '<tr>';
					echo '<td>' . esc_html( isset( $s['service'] ) ? $s['service'] : '' ) . '</td>';
					echo '<td>' . number_format( isset( $s['calls'] ) ? (int) $s['calls'] : 0 ) . '</td>';
					echo '<td>' . number_format( isset( $s['tokens'] ) ? (int) $s['tokens'] : 0 ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}
	}

	/* ── Shared helpers ─────────────────────────────────────────────────── */
	// [2026-06-07 Johnny Chu] PHASE-C C-3 — card_stat + money helpers for Revenue/Usage tabs.

	private static function card_stat( $label, $value, $sub = '' ) {
		echo '<div class="bizm-card">';
		echo '<div><div class="bizm-card-value">' . esc_html( $value ) . '</div>';
		echo '<div class="bizm-card-label">' . esc_html( $label ) . '</div>';
		if ( $sub !== '' ) {
			echo '<div style="font-size:11px;color:#646970;margin-top:2px">' . esc_html( $sub ) . '</div>';
		}
		echo '</div></div>';
	}

	private static function money( $usd ) {
		return '$' . number_format( (float) $usd, 2 );
	}

	private static function styles() {
		echo '<style>
			.bizm-cards{display:flex;flex-wrap:wrap;gap:16px;margin:20px 0}
			.bizm-card{flex:1 1 180px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
			.bizm-card .dashicons{font-size:30px;width:30px;height:30px;color:#2271b1}
			.bizm-card-value{font-size:24px;font-weight:700;line-height:1.1}
			.bizm-card-label{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
			.bizm-table{margin-top:12px}
			.bizm-form{max-width:760px}
			.bizm-inline{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
			.bizm-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:#f0f0f1;color:#3c434a}
			.bizm-badge-free,.bizm-badge--free{background:#f0f0f1;color:#50575e}
			.bizm-badge-pro,.bizm-badge--pro{background:#e6f0fb;color:#1d5fa6}
			.bizm-badge-plus,.bizm-badge--plus{background:#efe6fb;color:#6b2fb3}
			.bizm-badge--admin{background:#fff5cc;color:#7a5a00}
			.bizm-badge--info{background:#e6f4fb;color:#1477a1}
			.bizm-badge-completed{background:#e5f5ea;color:#1a7f37}
			.bizm-badge-pending{background:#fcf3e6;color:#996300}
			.bizm-badge-failed,.bizm-badge-refunded{background:#fbeaea;color:#b32d2e}
			.bizm-search{margin:12px 0}
			.bizm-title{margin-bottom:4px}

			/* [2026-06-09 Johnny Chu] HOTFIX — Twin Master guide panel */
			.bizm-master-guide{background:#f8f9fa;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:8px;padding:20px 24px;margin:16px 0 24px}
			.bizm-master-guide__header{display:flex;align-items:flex-start;gap:14px;margin-bottom:16px;flex-wrap:wrap}
			.bizm-master-guide__icon{font-size:32px;line-height:1}
			.bizm-master-guide__title{margin:0 0 2px;font-size:16px;font-weight:700;color:#1d2327}
			.bizm-master-guide__sub{margin:0;color:#646970;font-size:13px}
			.bizm-master-guide__links{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
			.bizm-master-guide__current{margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
			.bizm-guide-warn{color:#996300;background:#fcf3e6;padding:2px 10px;border-radius:4px;font-size:12px}
			.bizm-master-guide__tiers{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin-bottom:16px}
			.bizm-tier{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}
			.bizm-tier--admin{border-top:3px solid #f0b429}
			.bizm-tier--shared{border-top:3px solid #2271b1}
			.bizm-tier--member{border-top:3px solid #8b5cf6}
			.bizm-tier__header{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
			.bizm-tier__icon{font-size:18px}
			.bizm-tier__list{margin:0;padding-left:18px}
			.bizm-tier__list li{margin-bottom:5px;font-size:13px;line-height:1.5;color:#3c434a}
			.bizm-master-guide__flow{background:#e8f0fe;border:1px solid #b4c7f4;border-radius:6px;padding:10px 14px;font-size:13px;color:#1d2327}
			.bizm-master-guide__flow code{background:transparent;font-size:12px;color:#1467b3;font-weight:600}
		</style>';
	}
}
