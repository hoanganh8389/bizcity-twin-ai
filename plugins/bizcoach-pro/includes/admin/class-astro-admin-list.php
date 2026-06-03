<?php
/**
 * BizCoach Pro — Phase 0.3 H.1 · Multi-system astrology list pages
 *
 * Registers two NEW admin submenus that sit BESIDE the legacy Western
 * page (`bccm_user_profiles`, owned by `legacy/includes/admin-user-profiles.php`).
 * Western slug is left untouched (R-NO-CONFLICT / zero-regression).
 *
 *   - bccm_vedic_profiles  → Vedic Astrology  list  (chart_type='vedic')
 *   - bccm_bazi_profiles   → Chinese BaZi     list  (chart_type='chinese')
 *
 * Both pages share a single controller class `BizCoach_Pro_Astro_Admin_List`
 * which is parameterised by `$system`. H.3 will plug in the
 * choose-or-create form; H.4 the system-aware fetch/render. For now H.1
 * delivers navigation + filtered listing + CTAs.
 *
 * @package BizCoach_Pro
 * @since   0.3.0 (PHASE-0.3-ASTRO-MULTI-SYSTEM-ADMIN — H.1)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Admin_List' ) ) { return; }

final class BizCoach_Pro_Astro_Admin_List {

	/** Parent admin slug — Western page (do NOT change). */
	const PARENT_SLUG = 'bccm_user_profiles';

	/** Submenu definitions. Keep order: Western (legacy) → Vedic → BaZi. */
	public static function systems(): array {
		return array(
			'vedic'   => array(
				'slug'      => 'bccm_vedic_profiles',
				'label'     => 'Vedic Astrology',
				'icon'      => '🕉️',
				'position'  => 36,
			),
			'chinese' => array(
				'slug'      => 'bccm_bazi_profiles',
				'label'     => 'Chinese BaZi',
				'icon'      => '☯️',
				'position'  => 37,
			),
		);
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
	}

	public static function register_menus(): void {
		foreach ( self::systems() as $system => $def ) {
			add_submenu_page(
				self::PARENT_SLUG,
				$def['icon'] . ' ' . $def['label'],
				$def['icon'] . ' ' . $def['label'],
				'manage_options',
				$def['slug'],
				function() use ( $system ) { self::render_page( $system ); },
				$def['position']
			);
		}
	}

	/* ============================================================
	 * Routing
	 * ============================================================ */
	public static function render_page( string $system ): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

		if ( $action === 'view' && ! empty( $_GET['user_id'] ) ) {
			self::render_detail( $system, (int) $_GET['user_id'] );
			return;
		}
		if ( $action === 'add_new' ) {
			self::render_add_new( $system );
			return;
		}
		self::render_list( $system );
	}

	/* ============================================================
	 * LIST view
	 * ============================================================ */
	public static function render_list( string $system ): void {
		global $wpdb;
		$def     = self::systems()[ $system ];
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_prof  = $wpdb->prefix . 'bccm_coachees';
		$slug    = $def['slug'];

		echo '<div class="wrap">';
		self::render_header( $system, $def, 'list' );

		if ( ! function_exists( 'bccm_astro_supports_chart_type' ) || ! bccm_astro_supports_chart_type() ) {
			echo '<div class="notice notice-warning"><p><strong>Schema chưa có cột <code>chart_type</code></strong> — subsite này chưa migrate. Mở trang Western <a href="' . esc_url( admin_url( 'admin.php?page=bccm_user_profiles' ) ) . '">bccm_user_profiles</a> một lần để installer chạy migration, sau đó tải lại trang này.</p></div></div>';
			return;
		}

		// Search + paginate.
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$per_page = 30;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$where = $wpdb->prepare( 'WHERE a.chart_type = %s', $system );
		if ( $search !== '' ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (p.full_name LIKE %s OR p.phone LIKE %s)', $like, $like );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT a.coachee_id) FROM {$t_astro} a INNER JOIN {$t_prof} p ON p.id = a.coachee_id {$where}" );
		$pages = $total ? (int) ceil( $total / $per_page ) : 1;

		$rows = $wpdb->get_results( "
			SELECT a.id          AS astro_id,
			       a.coachee_id  AS coachee_id,
			       a.user_id     AS user_id,
			       a.birth_place AS birth_place,
			       a.birth_time  AS birth_time,
			       a.updated_at  AS updated_at,
			       a.summary     AS summary,
			       p.full_name   AS full_name,
			       p.phone       AS phone
			FROM {$t_astro} a
			INNER JOIN {$t_prof} p ON p.id = a.coachee_id
			{$where}
			ORDER BY a.updated_at DESC
			LIMIT {$per_page} OFFSET {$offset}
		", ARRAY_A );

		// Toolbar.
		echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $slug ) . '">';
		echo '<input type="text" name="s" value="' . esc_attr( $search ) . '" placeholder="Tìm tên / SĐT" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;width:240px">';
		echo '<button class="button" type="submit">Lọc</button>';
		if ( $search !== '' ) {
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">✕ Xóa lọc</a>';
		}
		echo '<span style="margin-left:auto;color:#64748b">Tổng: <strong>' . (int) $total . '</strong></span>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<div style="padding:40px 20px;text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:10px">';
			echo '<p style="color:#64748b;font-size:14px">Chưa có hồ sơ <strong>' . esc_html( $def['label'] ) . '</strong> nào.</p>';
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . $slug . '&action=add_new' ) ) . '">➕ Tạo hồ sơ ' . esc_html( $def['label'] ) . ' đầu tiên</a>';
			echo '</div></div>';
			return;
		}

		echo '<table class="widefat striped" style="margin-top:8px">';
		echo '<thead><tr>';
		echo '<th style="width:60px">#</th>';
		echo '<th>Họ tên</th>';
		echo '<th>SĐT</th>';
		echo '<th>Giờ sinh</th>';
		echo '<th>Nơi sinh</th>';
		echo '<th>Cập nhật</th>';
		echo '<th style="width:160px">Hành động</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$view_url = admin_url( 'admin.php?page=' . $slug . '&action=view&user_id=' . (int) $r['user_id'] );
			echo '<tr>';
			echo '<td>' . (int) $r['coachee_id'] . '</td>';
			echo '<td><a href="' . esc_url( $view_url ) . '"><strong>' . esc_html( (string) $r['full_name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( (string) $r['phone'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['birth_time'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['birth_place'] ) . '</td>';
			echo '<td><small style="color:#64748b">' . esc_html( (string) $r['updated_at'] ) . '</small></td>';
			echo '<td><a class="button button-small" href="' . esc_url( $view_url ) . '">Xem</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages" style="margin-top:10px">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg( array( 'page' => $slug, 'paged' => $i, 's' => $search ), admin_url( 'admin.php' ) );
				if ( $i === $paged ) {
					echo '<span class="button button-primary" style="margin-right:4px">' . $i . '</span>';
				} else {
					echo '<a class="button" style="margin-right:4px" href="' . esc_url( $url ) . '">' . $i . '</a>';
				}
			}
			echo '</div></div>';
		}

		echo '</div>';
	}

	/* ============================================================
	 * DETAIL view — temporary bridge.
	 *
	 * H.1 reuses the legacy Western detail page for now (which already
	 * displays both Western + Vedic data). H.4 will replace this with a
	 * system-aware renderer.
	 * ============================================================ */
	public static function render_detail( string $system, int $user_id ): void {
		$def = self::systems()[ $system ];
		echo '<div class="wrap">';
		self::render_header( $system, $def, 'detail', $user_id );

		echo '<div class="notice notice-info" style="margin:12px 0"><p>';
		echo '<strong>H.1 stub:</strong> Trang chi tiết riêng cho ' . esc_html( $def['label'] ) . ' sẽ được build ở sprint H.4 ';
		echo '(renderer ' . ( $system === 'vedic' ? 'SVG 12-Rashi' : 'bảng 4 trụ BaZi' ) . '). ';
		echo 'Tạm thời mở trang Western dùng chung để xem dữ liệu chiêm tinh hiện có:';
		echo '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=bccm_user_profiles&action=view&user_id=' . $user_id ) ) . '">Mở trang chi tiết Western (dùng chung)</a></p></div>';

		echo '</div>';
	}

	/* ============================================================
	 * ADD-NEW view — H.3 placeholder.
	 * ============================================================ */
	public static function render_add_new( string $system ): void {
		$def = self::systems()[ $system ];
		if ( class_exists( 'BizCoach_Pro_Astro_Admin_Form' ) ) {
			BizCoach_Pro_Astro_Admin_Form::render( $system, $def );
			return;
		}
		// Fallback (should not happen — H.3 loaded in bootstrap).
		echo '<div class="wrap">';
		self::render_header( $system, $def, 'add_new' );
		echo '<div class="notice notice-error"><p>H.3 form class not loaded.</p></div>';
		echo '</div>';
	}

	/* ============================================================
	 * Header / breadcrumb
	 * ============================================================ */
	private static function render_header( string $system, array $def, string $context, int $user_id = 0 ): void {
		$slug = $def['slug'];
		echo '<h1 style="display:flex;align-items:center;gap:10px">';
		echo esc_html( $def['icon'] ) . ' ' . esc_html( $def['label'] );
		echo ' <span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600">PHASE-0.3 H.1</span>';
		if ( $context === 'list' ) {
			echo ' <a class="button button-primary" style="margin-left:auto;background:#059669;border-color:#047857;border-radius:8px;padding:6px 16px;font-size:13px" href="' . esc_url( admin_url( 'admin.php?page=' . $slug . '&action=add_new' ) ) . '">➕ Tạo mới</a>';
		} elseif ( $context === 'detail' || $context === 'add_new' ) {
			echo ' <a class="button" style="margin-left:auto" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">← Quay lại danh sách</a>';
		}
		echo '</h1>';

		echo '<p style="color:#64748b;margin:4px 0 0">Spec: <code>plugins/bizcoach-pro/PHASE-0.3-ASTRO-MULTI-SYSTEM-ADMIN.md §H.1</code> · cùng schema <code>bccm_astro.chart_type=' . esc_html( $system ) . '</code> với Western (zero-regression, R-NO-CONFLICT).</p>';
	}
}
