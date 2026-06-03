<?php
/**
 * BizCoach Pro — Phase 0.3 H.3 · Dual-tab add/edit form
 *
 * Two flows, one schema upsert:
 *   Tab 1 "Chọn user có sẵn"   → H.2 user-picker (multisite-safe blog scope).
 *   Tab 2 "Tạo user mới"       → legacy phone+password flow (wp_create_user).
 *
 * Both converge on:
 *   - upsert `wp_bccm_coachees` (helper `bccm_upsert_profile` if available).
 *   - upsert `wp_bccm_astro` keyed on UNIQUE (coachee_id, chart_type=$system).
 *
 * Western remains owned by legacy `bccm_admin_user_profile_add_new()` —
 * this controller services **vedic** + **chinese** only (H.1 menus).
 * For Western it bounces to the legacy form for zero-regression.
 *
 * @package BizCoach_Pro
 * @since   0.3.0 (PHASE-0.3-ASTRO-MULTI-SYSTEM-ADMIN — H.3)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Admin_Form' ) ) { return; }

final class BizCoach_Pro_Astro_Admin_Form {

	const NONCE_ACTION = 'bcpro_astro_admin_form';

	/**
	 * Entry point — called by BizCoach_Pro_Astro_Admin_List::render_add_new().
	 *
	 * @param string $system  'vedic' | 'chinese' (western handled by legacy).
	 * @param array  $def     System definition row (slug/label/icon/...).
	 */
	public static function render( string $system, array $def ): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		if ( $system === 'western' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=bccm_user_profiles&action=add_new' ) );
			exit;
		}
		if ( ! function_exists( 'bccm_astro_supports_chart_type' ) || ! bccm_astro_supports_chart_type() ) {
			echo '<div class="notice notice-error"><p>Schema chưa migrate cột <code>chart_type</code> — mở trang Western 1 lần để installer chạy.</p></div>';
			return;
		}

		$result = self::handle_post( $system );

		// Sticky state.
		$state = $result['state'];
		$msg   = $result['message'];
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : ( $state['mode'] ?: 'pick' );
		if ( ! in_array( $tab, array( 'pick', 'create' ), true ) ) { $tab = 'pick'; }

		// Enqueue user picker (H.2).
		if ( class_exists( 'BizCoach_Pro_User_Picker' ) ) {
			BizCoach_Pro_User_Picker::enqueue();
		}

		echo '<div class="wrap">';
		self::render_header( $system, $def );

		if ( $msg ) {
			$cls = $msg['type'] === 'error' ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $cls ) . '" style="margin:12px 0;border-radius:8px"><p>' . wp_kses_post( $msg['text'] ) . '</p></div>';
		}

		self::render_tabs( $system, $def['slug'], $tab );

		echo '<form method="post" style="max-width:980px;background:#fff;padding:24px;border:1px solid #e5e7eb;border-radius:12px;margin-top:-1px">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="bcpro_form_mode" value="' . esc_attr( $tab ) . '">';
		echo '<input type="hidden" name="bcpro_chart_system" value="' . esc_attr( $system ) . '">';

		if ( $tab === 'pick' ) {
			self::render_pick_panel( $system, $state );
		} else {
			self::render_create_panel( $system, $state );
		}

		self::render_birth_block( $state );

		echo '<p style="margin-top:20px;display:flex;gap:10px;align-items:center">';
		echo '<button type="submit" class="button button-primary" style="background:#7c3aed;border-color:#6d28d9;border-radius:8px;padding:8px 20px;font-size:14px">💾 Lưu hồ sơ ' . esc_html( $def['label'] ) . '</button>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $def['slug'] ) ) . '">Hủy</a>';
		echo '<span style="color:#64748b;font-size:12px;margin-left:auto">UPSERT keyed on <code>(coachee_id, chart_type=' . esc_html( $system ) . ')</code> — tạo nhiều lần không trùng row.</span>';
		echo '</p>';
		echo '</form>';

		echo '</div>';
	}

	/* ============================================================
	 * HEADER + TABS
	 * ============================================================ */
	private static function render_header( string $system, array $def ): void {
		echo '<h1 style="display:flex;align-items:center;gap:10px">';
		echo esc_html( $def['icon'] ) . ' ' . esc_html( $def['label'] ) . ' — Tạo / Cập nhật hồ sơ';
		echo ' <span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600">PHASE-0.3 H.3</span>';
		echo ' <a class="button" style="margin-left:auto" href="' . esc_url( admin_url( 'admin.php?page=' . $def['slug'] ) ) . '">← Quay lại danh sách</a>';
		echo '</h1>';
	}

	private static function render_tabs( string $system, string $slug, string $active ): void {
		$url_pick   = add_query_arg( array( 'page' => $slug, 'action' => 'add_new', 'tab' => 'pick' ), admin_url( 'admin.php' ) );
		$url_create = add_query_arg( array( 'page' => $slug, 'action' => 'add_new', 'tab' => 'create' ), admin_url( 'admin.php' ) );

		echo '<h2 class="nav-tab-wrapper" style="margin-top:16px;border-bottom:1px solid #e5e7eb">';
		echo '<a href="' . esc_url( $url_pick ) . '" class="nav-tab' . ( $active === 'pick' ? ' nav-tab-active' : '' ) . '">👥 Chọn user có sẵn</a>';
		echo '<a href="' . esc_url( $url_create ) . '" class="nav-tab' . ( $active === 'create' ? ' nav-tab-active' : '' ) . '">➕ Tạo user mới</a>';
		echo '</h2>';
	}

	/* ============================================================
	 * PANELS
	 * ============================================================ */
	private static function render_pick_panel( string $system, array $state ): void {
		echo '<div style="background:#f8fafc;padding:20px;border-radius:10px;border:1px solid #e5e7eb">';
		echo '<h3 style="margin:0 0 12px;font-size:15px;color:#1e40af">👥 Chọn user</h3>';
		echo '<p style="color:#64748b;font-size:13px;margin:0 0 12px">Gõ tên, email hoặc số điện thoại.</p>';

		echo '<input type="text" data-bcpro-user-picker data-system="' . esc_attr( $system ) . '" data-target="#bcpro_form_user_id" data-target-display="#bcpro_form_user_display" value="' . esc_attr( $state['user_display'] ) . '" style="max-width:520px">';
		echo '<input type="hidden" name="user_id" id="bcpro_form_user_id" value="' . (int) $state['user_id'] . '">';
		echo '<input type="hidden" name="user_display" id="bcpro_form_user_display" value="' . esc_attr( $state['user_display'] ) . '">';

		echo '<p style="margin:12px 0 0;font-size:12px;color:#64748b">';
		echo 'Nếu user đã có hồ sơ coachee + birth data → form sẽ <strong>cập nhật</strong> (không trùng row). ';
		echo 'Nếu user CHƯA có hồ sơ coachee → tự tạo coachee row liên kết user_id này.';
		echo '</p>';
		echo '</div>';
	}

	private static function render_create_panel( string $system, array $state ): void {
		echo '<div style="background:#fffbeb;padding:20px;border-radius:10px;border:1px solid #fde68a">';
		echo '<h3 style="margin:0 0 12px;font-size:15px;color:#92400e">➕ Tạo user WordPress mới + gắn chart ' . esc_html( $system ) . '</h3>';

		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">';

		echo '<p style="margin:0">';
		echo '<label style="display:block;font-weight:700;margin-bottom:4px">Số điện thoại <span style="color:#dc2626">*</span></label>';
		echo '<input type="text" name="new_phone" value="' . esc_attr( $state['new_phone'] ) . '" placeholder="VD: 0901234567" inputmode="numeric" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px">';
		echo '<small style="color:#64748b">Sẽ là username đăng nhập.</small></p>';

		echo '<p style="margin:0">';
		echo '<label style="display:block;font-weight:700;margin-bottom:4px">Mật khẩu <span style="color:#dc2626">*</span></label>';
		echo '<input type="text" name="new_password" value="' . esc_attr( $state['new_password'] ) . '" placeholder="≥ 6 ký tự" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px">';
		echo '<small style="color:#64748b">Hiện rõ để admin lưu / gửi khách.</small></p>';

		echo '</div>';

		echo '<p style="margin:14px 0 0">';
		echo '<label style="display:block;font-weight:700;margin-bottom:4px">Họ tên</label>';
		echo '<input type="text" name="new_full_name" value="' . esc_attr( $state['new_full_name'] ) . '" placeholder="VD: Nguyễn Văn A" style="width:100%;max-width:520px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px">';
		echo '</p>';

		echo '<p style="margin:14px 0 0;font-size:12px;color:#92400e">⚠️ Khi user đã tồn tại với SĐT này → form sẽ KHÔNG tạo trùng, mà bounce sang chế độ cập nhật.</p>';

		echo '</div>';
	}

	private static function render_birth_block( array $state ): void {
		echo '<div style="background:#eff6ff;padding:20px;border-radius:10px;border:1px solid #bfdbfe;margin-top:16px">';
		echo '<h3 style="margin:0 0 12px;font-size:15px;color:#7c3aed">🌟 Dữ liệu sinh (dùng cho mọi system)</h3>';

		echo '<div style="display:grid;grid-template-columns:1fr 1fr 2fr;gap:16px">';

		echo '<p style="margin:0">';
		echo '<label style="display:block;font-weight:700;margin-bottom:4px">Ngày sinh <span style="color:#dc2626">*</span></label>';
		echo '<input type="date" name="dob" value="' . esc_attr( $state['dob'] ) . '" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px">';
		echo '</p>';

		echo '<p style="margin:0">';
		echo '<label style="display:block;font-weight:700;margin-bottom:4px">Giờ sinh <span style="color:#dc2626">*</span></label>';
		echo '<input type="time" name="birth_time" value="' . esc_attr( $state['birth_time'] ) . '" step="60" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px">';
		echo '</p>';

		echo '<p style="margin:0">';
		echo '<label style="display:block;font-weight:700;margin-bottom:4px">Nơi sinh <span style="color:#dc2626">*</span></label>';
		echo '<input type="text" name="birth_place" value="' . esc_attr( $state['birth_place'] ) . '" placeholder="VD: Hà Nội, Việt Nam" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px">';
		echo '</p>';

		echo '</div>';
		echo '</div>';
	}

	/* ============================================================
	 * POST HANDLER
	 * ============================================================ */
	private static function handle_post( string $system ): array {
		$state = array(
			'mode'          => '',
			'user_id'       => 0,
			'user_display'  => '',
			'new_phone'     => '',
			'new_password'  => '',
			'new_full_name' => '',
			'dob'           => '',
			'birth_time'    => '',
			'birth_place'   => '',
		);
		$msg = null;

		if ( empty( $_POST['bcpro_form_mode'] ) ) {
			return array( 'state' => $state, 'message' => null );
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( (string) $_POST['_wpnonce'], self::NONCE_ACTION ) ) {
			return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => 'Nonce verification failed.' ) );
		}

		$state['mode']          = sanitize_key( (string) $_POST['bcpro_form_mode'] );
		$state['user_id']       = (int) ( $_POST['user_id'] ?? 0 );
		$state['user_display']  = sanitize_text_field( (string) ( $_POST['user_display'] ?? '' ) );
		$state['new_phone']     = preg_replace( '/[^0-9]/', '', sanitize_text_field( (string) ( $_POST['new_phone'] ?? '' ) ) );
		$state['new_password']  = (string) ( $_POST['new_password'] ?? '' );
		$state['new_full_name'] = sanitize_text_field( (string) ( $_POST['new_full_name'] ?? '' ) );
		$state['dob']           = sanitize_text_field( (string) ( $_POST['dob'] ?? '' ) );
		$state['birth_time']    = sanitize_text_field( (string) ( $_POST['birth_time'] ?? '' ) );
		$state['birth_place']   = sanitize_text_field( (string) ( $_POST['birth_place'] ?? '' ) );

		// Validate birth.
		if ( ! $state['dob'] || ! $state['birth_time'] || ! $state['birth_place'] ) {
			return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '⚠️ Vui lòng nhập đầy đủ Ngày sinh + Giờ sinh + Nơi sinh.' ) );
		}

		// Resolve user.
		$user_id = 0;
		$full    = '';
		if ( $state['mode'] === 'pick' ) {
			$user_id = $state['user_id'];
			$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
			if ( ! $user ) {
				return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '⚠️ Chưa chọn user. Gõ vào ô tìm và chọn 1 user trong dropdown.' ) );
			}
			// Multisite-safe: ensure picked user actually belongs to this blog.
			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '⚠️ User này không thuộc blog hiện tại. Multisite isolation guard chặn.' ) );
			}
			$full = $user->display_name ?: $user->user_login;
		} else {
			$phone = $state['new_phone'];
			if ( substr( $phone, 0, 2 ) === '84' && strlen( $phone ) > 9 ) {
				$phone = '0' . substr( $phone, 2 );
				$state['new_phone'] = $phone;
			}
			if ( strlen( $phone ) < 10 ) {
				return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '⚠️ SĐT không hợp lệ (≥ 10 số).' ) );
			}
			$existing = username_exists( $phone );
			if ( $existing ) {
				// Bounce to update mode instead of duplicate.
				$user_id = (int) $existing;
				if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
					add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
				}
				$full = $state['new_full_name'] ?: $phone;
			} else {
				if ( strlen( $state['new_password'] ) < 6 ) {
					return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '⚠️ Mật khẩu ≥ 6 ký tự.' ) );
				}
				$email   = $phone . '@bizcity.vn';
				$user_id = wp_create_user( $phone, $state['new_password'], $email );
				if ( is_wp_error( $user_id ) ) {
					return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '❌ Lỗi tạo user: ' . esc_html( $user_id->get_error_message() ) ) );
				}
				$user_id = (int) $user_id;
				$full    = $state['new_full_name'] ?: $phone;
				wp_update_user( array( 'ID' => $user_id, 'display_name' => $full, 'first_name' => $state['new_full_name'] ) );
				update_user_meta( $user_id, 'billing_phone', $phone );
				if ( is_multisite() ) {
					add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
				}
			}
		}

		// Upsert coachee.
		$coachee_id = self::upsert_coachee( $user_id, $full, $state );
		if ( ! $coachee_id ) {
			return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '❌ Không tạo được coachee profile.' ) );
		}

		// Upsert astro row keyed on (coachee_id, chart_type=$system).
		$astro_id = self::upsert_astro_row( $coachee_id, $user_id, $system, $state );
		if ( ! $astro_id ) {
			return array( 'state' => $state, 'message' => array( 'type' => 'error', 'text' => '❌ Lỗi lưu astro row.' ) );
		}

		// Optional: kick off chart fetch (H.4 will route to system-aware).
		// We DO NOT block on this — fetch on first detail-page view (H.4).
		do_action( 'bcpro/astro/chart_birth_saved', $coachee_id, $user_id, $system, array(
			'dob'         => $state['dob'],
			'birth_time'  => $state['birth_time'],
			'birth_place' => $state['birth_place'],
		) );

		// Redirect → list (so refresh-on-back doesn't resubmit).
		$slug = ( $system === 'vedic' ) ? 'bccm_vedic_profiles' : 'bccm_bazi_profiles';
		$url  = add_query_arg( array( 'page' => $slug, 'action' => 'view', 'user_id' => $user_id, 'saved' => 1 ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Upsert wp_bccm_coachees. Prefers legacy helper `bccm_upsert_profile`
	 * when available; falls back to manual SELECT-or-INSERT.
	 */
	private static function upsert_coachee( int $user_id, string $full, array $state ): int {
		global $wpdb;
		$t_prof = $wpdb->prefix . 'bccm_coachees';

		// Existing coachee for this user_id?
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t_prof} WHERE user_id=%d LIMIT 1", $user_id ) );

		if ( function_exists( 'bccm_upsert_profile' ) ) {
			$id = bccm_upsert_profile( array(
				'coach_type'    => 'astro_coach',
				'full_name'     => $full,
				'phone'         => get_user_meta( $user_id, 'billing_phone', true ) ?: '',
				'dob'           => $state['dob'],
				'user_id'       => $user_id,
				'platform_type' => 'WEBCHAT',
			), $existing_id );
			return (int) $id;
		}

		// Manual fallback.
		$row = array(
			'user_id'    => $user_id,
			'full_name'  => $full,
			'phone'      => get_user_meta( $user_id, 'billing_phone', true ) ?: '',
			'dob'        => $state['dob'],
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $existing_id ) {
			$wpdb->update( $t_prof, $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $t_prof, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Upsert wp_bccm_astro keyed on UNIQUE (coachee_id, chart_type).
	 */
	private static function upsert_astro_row( int $coachee_id, int $user_id, string $system, array $state ): int {
		global $wpdb;
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$geo = function_exists( 'bccm_admin_simple_geocode' )
			? bccm_admin_simple_geocode( $state['birth_place'] )
			: array( 'lat' => null, 'lon' => null, 'tz' => null );

		$row = array(
			'coachee_id'  => $coachee_id,
			'user_id'     => $user_id,
			'chart_type'  => $system,
			'birth_time'  => $state['birth_time'],
			'birth_place' => $state['birth_place'],
			'latitude'    => $geo['lat'] ?? null,
			'longitude'   => $geo['lon'] ?? null,
			'timezone'    => $geo['tz']  ?? null,
			'updated_at'  => current_time( 'mysql' ),
		);

		// Existing row?
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t_astro} WHERE coachee_id=%d AND chart_type=%s LIMIT 1",
			$coachee_id, $system
		) );

		if ( function_exists( 'bccm_astro_filter_row_to_existing_columns' ) ) {
			$row = bccm_astro_filter_row_to_existing_columns( $row );
		}

		if ( $existing_id ) {
			$wpdb->update( $t_astro, $row, array( 'id' => $existing_id ) );
			return $existing_id;
		}
		$insert = $row;
		$insert['created_at'] = current_time( 'mysql' );
		if ( function_exists( 'bccm_astro_filter_row_to_existing_columns' ) ) {
			$insert = bccm_astro_filter_row_to_existing_columns( $insert );
		}
		$wpdb->insert( $t_astro, $insert );
		return (int) $wpdb->insert_id;
	}
}
