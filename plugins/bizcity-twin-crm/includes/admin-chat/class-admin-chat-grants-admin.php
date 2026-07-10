<?php
/**
 * BizCity CRM — Admin Chat Grants management screen (Wave B UI).
 *
 * Submenu under CRM root. Lists all grants with filter by status, allows:
 *   - Approve pending grant (issued by auto-grant heuristic for non-solo blogs).
 *   - Toggle Producer / Retriever / Distributor flags.
 *   - Edit per-tool overrides (deny / confirm / allow) as JSON textarea.
 *   - Revoke active grant.
 *   - Reset daily quota counter.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Admin_Chat_Grants_Admin {

	const SLUG = 'bizcity-crm-admin-chat-grants';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 65 );
		add_action( 'admin_post_bizcity_crm_admin_chat_grant_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bizcity_crm_admin_chat_grant_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_bizcity_crm_admin_chat_grant_approve',array( __CLASS__, 'handle_approve' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'bizcity-crm',
			'Admin Chat Grants',
			'Admin Chat Grants',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ─────────── Actions ─────────── */

	public static function handle_save(): void {
		self::guard( 'bizcity_crm_admin_chat_grant_save' );
		$id = (int) ( $_POST['grant_id'] ?? 0 );
		if ( $id <= 0 ) { self::redirect_back( 'invalid' ); }

		$overrides_raw = trim( (string) wp_unslash( $_POST['tool_overrides_json'] ?? '' ) );
		$overrides     = null;
		if ( $overrides_raw !== '' ) {
			$decoded = json_decode( $overrides_raw, true );
			if ( ! is_array( $decoded ) ) { self::redirect_back( 'json_invalid' ); }
			// Normalize values to enum {allow|confirm|deny}.
			$clean = array();
			foreach ( $decoded as $tool_id => $verb ) {
				$verb = strtolower( (string) $verb );
				if ( ! in_array( $verb, array( 'allow', 'confirm', 'deny' ), true ) ) { continue; }
				$clean[ sanitize_key( (string) $tool_id ) ] = $verb;
			}
			$overrides = $clean ? wp_json_encode( $clean ) : null;
		}

		global $wpdb;
		$wpdb->update(
			BizCity_CRM_Admin_Chat_Grants::table(),
			array(
				'allow_producer'      => ! empty( $_POST['allow_producer'] ) ? 1 : 0,
				'allow_retriever'     => ! empty( $_POST['allow_retriever'] ) ? 1 : 0,
				'allow_distributor'   => ! empty( $_POST['allow_distributor'] ) ? 1 : 0,
				'tool_overrides_json' => $overrides,
				'quota_per_day'       => max( 0, (int) ( $_POST['quota_per_day'] ?? 50 ) ),
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);
		self::redirect_back( 'saved', $id );
	}

	public static function handle_revoke(): void {
		self::guard( 'bizcity_crm_admin_chat_grant_revoke' );
		$id = (int) ( $_POST['grant_id'] ?? 0 );
		if ( $id > 0 ) {
			BizCity_CRM_Admin_Chat_Grants::revoke( $id, get_current_user_id() );
		}
		self::redirect_back( 'revoked', $id );
	}

	public static function handle_approve(): void {
		self::guard( 'bizcity_crm_admin_chat_grant_approve' );
		$id = (int) ( $_POST['grant_id'] ?? 0 );
		if ( $id > 0 ) {
			global $wpdb;
			$wpdb->update(
				BizCity_CRM_Admin_Chat_Grants::table(),
				array(
					'status'             => BizCity_CRM_Admin_Chat_Grants::STATUS_ACTIVE,
					'granted_by_user_id' => get_current_user_id(),
					'granted_at'         => current_time( 'mysql' ),
					'updated_at'         => current_time( 'mysql' ),
				),
				array( 'id' => $id ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			do_action( 'bizcity_crm_admin_chat_grant_approved', $id, get_current_user_id() );
		}
		self::redirect_back( 'approved', $id );
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		check_admin_referer( $nonce_action );
	}

	private static function redirect_back( string $msg, int $id = 0 ): void {
		$ref = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG );
		wp_safe_redirect( add_query_arg( array( 'msg' => $msg, 'gid' => $id ), $ref ) );
		exit;
	}

	/* ─────────── Render ─────────── */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		global $wpdb;
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : '';
		$where = '1=1';
		if ( in_array( $status_filter, array( 'active', 'pending', 'revoked' ), true ) ) {
			$where = $wpdb->prepare( 'status = %s', $status_filter );
		}
		$rows = $wpdb->get_results(
			'SELECT * FROM ' . BizCity_CRM_Admin_Chat_Grants::table()
			. ' WHERE ' . $where . ' ORDER BY status ASC, created_at DESC LIMIT 500',
			ARRAY_A
		);

		$counts = $wpdb->get_results(
			'SELECT status, COUNT(*) AS n FROM ' . BizCity_CRM_Admin_Chat_Grants::table() . ' GROUP BY status',
			ARRAY_A
		);
		$count_map = array( 'active' => 0, 'pending' => 0, 'revoked' => 0 );
		foreach ( (array) $counts as $r ) { $count_map[ $r['status'] ] = (int) $r['n']; }
		?>
		<div class="wrap">
			<h1>Admin Chat Grants <span class="title-count theme-count"><?php echo (int) array_sum( $count_map ); ?></span></h1>
			<p class="description">
				Phân quyền 3 trục cho user truy cập <b>Twin Guru</b> qua channel ngoài (Zalo / FB / Telegram).
				Mỗi grant = (user × guru × channel binding) + flag <b>Producer / Retriever / Distributor</b>.
				Mặc định: P = on, R = on (chỉ khi solo administrator), D = off (phải bật tay).
			</p>

			<?php if ( isset( $_GET['msg'] ) ): self::render_notice( (string) $_GET['msg'], (int) ( $_GET['gid'] ?? 0 ) ); endif; ?>

			<ul class="subsubsub">
				<?php
				$base = admin_url( 'admin.php?page=' . self::SLUG );
				$tabs = array(
					''        => array( 'Tất cả', array_sum( $count_map ) ),
					'pending' => array( 'Chờ duyệt', $count_map['pending'] ),
					'active'  => array( 'Đang hiệu lực', $count_map['active'] ),
					'revoked' => array( 'Đã thu hồi', $count_map['revoked'] ),
				);
				$last = array_key_last( $tabs );
				foreach ( $tabs as $key => $info ):
					$url = $key ? add_query_arg( 'status', $key, $base ) : $base;
					$cur = ( $status_filter === $key ) ? ' class="current"' : '';
				?>
				<li><a href="<?php echo esc_url( $url ); ?>"<?php echo $cur; ?>><?php echo esc_html( $info[0] ); ?> <span class="count">(<?php echo (int) $info[1]; ?>)</span></a><?php echo $key === $last ? '' : ' |'; ?> </li>
				<?php endforeach; ?>
			</ul>
			<br class="clear">

			<?php if ( empty( $rows ) ): ?>
				<p style="margin-top:24px;padding:24px;background:#fff;border:1px solid #e1e5eb;border-radius:8px;text-align:center;color:#64748b;">
					Chưa có grant nào. Grant được tạo tự động khi user click magic-link Zalo
					(xem <code>BizCity_CRM_Admin_Chat_Grants::on_magic_link_consumed</code>).
				</p>
				<?php return; endif; ?>

			<table class="widefat striped" style="margin-top:12px;">
				<thead>
					<tr>
						<th style="width:48px;">#</th>
						<th>WHO (User)</th>
						<th>WHAT (Guru)</th>
						<th>WHERE (Channel)</th>
						<th style="width:90px;">Status</th>
						<th style="width:170px;">P / R / D</th>
						<th style="width:110px;">Quota</th>
						<th style="width:130px;">Granted</th>
						<th style="width:200px;"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $g ):
					$user      = get_user_by( 'id', (int) $g['user_id'] );
					$guru_name = self::guru_name( (int) $g['character_id'] );
					$expanded  = ( (int) ( $_GET['expand'] ?? 0 ) === (int) $g['id'] );
				?>
					<tr>
						<td><?php echo (int) $g['id']; ?></td>
						<td>
							<?php if ( $user ): ?>
								<strong><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></strong><br>
								<span style="font-size:11px;color:#64748b;"><?php echo esc_html( $user->user_email ); ?></span>
							<?php else: ?>
								<em>user #<?php echo (int) $g['user_id']; ?> (deleted)</em>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( $guru_name ?: '—' ); ?></strong><br>
							<span style="font-size:11px;color:#64748b;">char #<?php echo (int) $g['character_id']; ?></span>
						</td>
						<td>
							<code><?php echo esc_html( $g['platform'] ); ?></code><br>
							<span style="font-size:11px;color:#64748b;"><?php echo esc_html( mb_strimwidth( (string) $g['chat_id'], 0, 24, '…' ) ); ?></span>
							<?php if ( ! empty( $g['channel_binding_id'] ) ): ?>
								<br><span style="font-size:10px;color:#94a3b8;">binding #<?php echo (int) $g['channel_binding_id']; ?></span>
							<?php endif; ?>
						</td>
						<td><?php self::render_status_badge( (string) $g['status'] ); ?></td>
						<td>
							<?php self::render_prd_pills( $g ); ?>
						</td>
						<td>
							<?php echo (int) $g['quota_used_today']; ?> / <?php echo (int) $g['quota_per_day']; ?>
							<?php if ( $g['quota_reset_at'] ): ?>
								<br><span style="font-size:10px;color:#94a3b8;">reset <?php echo esc_html( substr( (string) $g['quota_reset_at'], 0, 16 ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span style="font-size:11px;color:#64748b;"><?php echo esc_html( substr( (string) $g['granted_at'], 0, 16 ) ); ?></span>
							<?php if ( $g['granted_by_user_id'] ):
								$by = get_user_by( 'id', (int) $g['granted_by_user_id'] ); ?>
								<br><span style="font-size:10px;color:#94a3b8;">by <?php echo esc_html( $by ? $by->user_login : '#' . (int) $g['granted_by_user_id'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php self::render_row_actions( $g, $expanded ); ?>
						</td>
					</tr>
					<?php if ( $expanded ): ?>
						<tr><td colspan="9" style="background:#f8fafc;padding:16px;">
							<?php self::render_edit_form( $g ); ?>
						</td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:24px;padding:16px;background:#fef3c7;border-left:4px solid #f59e0b;border-radius:4px;font-size:13px;">
				⚠️ <strong>Distributor (D)</strong> = post FB / send email / schedule outbound — <em>không reversible</em>.
				Bật toggle này = ủy quyền user gửi tin/đăng bài qua Guru. Cân nhắc kỹ.
			</p>
		</div>
		<?php
	}

	/* ─────────── Render helpers ─────────── */

	private static function render_notice( string $msg, int $id ): void {
		$map = array(
			'saved'     => array( 'success', 'Đã lưu grant #' . $id ),
			'revoked'   => array( 'warning', 'Đã thu hồi grant #' . $id ),
			'approved'  => array( 'success', 'Đã duyệt grant #' . $id ),
			'invalid'   => array( 'error',   'Grant id không hợp lệ' ),
			'json_invalid' => array( 'error', 'Tool overrides JSON không hợp lệ' ),
		);
		if ( ! isset( $map[ $msg ] ) ) { return; }
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
	}

	private static function render_status_badge( string $status ): void {
		$map = array(
			'active'  => array( '#15803d', '#dcfce7', '✓ Active' ),
			'pending' => array( '#a16207', '#fef9c3', '⏳ Pending' ),
			'revoked' => array( '#b91c1c', '#fee2e2', '✕ Revoked' ),
		);
		$m = $map[ $status ] ?? array( '#475569', '#f1f5f9', $status );
		printf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:%s;background:%s;">%s</span>',
			esc_attr( $m[0] ), esc_attr( $m[1] ), esc_html( $m[2] )
		);
	}

	private static function render_prd_pills( array $g ): void {
		$pills = array(
			'P' => (int) $g['allow_producer'],
			'R' => (int) $g['allow_retriever'],
			'D' => (int) $g['allow_distributor'],
		);
		foreach ( $pills as $label => $on ) {
			$bg = $on ? '#22c55e' : '#cbd5e1';
			$fg = $on ? '#fff'    : '#475569';
			printf(
				'<span style="display:inline-block;width:26px;height:26px;line-height:26px;text-align:center;border-radius:50%%;font-weight:700;font-size:12px;color:%s;background:%s;margin-right:4px;" title="%s">%s</span>',
				esc_attr( $fg ), esc_attr( $bg ),
				esc_attr( $label === 'P' ? 'Producer' : ( $label === 'R' ? 'Retriever' : 'Distributor' ) ),
				esc_html( $label )
			);
		}
		if ( ! empty( $g['tool_overrides_json'] ) ) {
			$n = count( (array) json_decode( (string) $g['tool_overrides_json'], true ) );
			printf( ' <span style="font-size:10px;color:#64748b;">+%d override</span>', $n );
		}
	}

	private static function render_row_actions( array $g, bool $expanded ): void {
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		if ( $g['status'] === 'pending' ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<?php wp_nonce_field( 'bizcity_crm_admin_chat_grant_approve' ); ?>
				<input type="hidden" name="action" value="bizcity_crm_admin_chat_grant_approve">
				<input type="hidden" name="grant_id" value="<?php echo (int) $g['id']; ?>">
				<button type="submit" class="button button-primary button-small">✓ Duyệt</button>
			</form>
			<?php
		}
		if ( $g['status'] !== 'revoked' ) {
			$url = $expanded ? remove_query_arg( 'expand', $base ) : add_query_arg( 'expand', (int) $g['id'], $base );
			printf( ' <a href="%s" class="button button-small">%s</a>', esc_url( $url ), $expanded ? 'Đóng' : 'Sửa' );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('Thu hồi grant này?');">
				<?php wp_nonce_field( 'bizcity_crm_admin_chat_grant_revoke' ); ?>
				<input type="hidden" name="action" value="bizcity_crm_admin_chat_grant_revoke">
				<input type="hidden" name="grant_id" value="<?php echo (int) $g['id']; ?>">
				<button type="submit" class="button button-small button-link-delete">Revoke</button>
			</form>
			<?php
		}
	}

	private static function render_edit_form( array $g ): void {
		$overrides_pretty = '';
		if ( ! empty( $g['tool_overrides_json'] ) ) {
			$overrides_pretty = (string) wp_json_encode(
				json_decode( (string) $g['tool_overrides_json'], true ),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:780px;">
			<?php wp_nonce_field( 'bizcity_crm_admin_chat_grant_save' ); ?>
			<input type="hidden" name="action" value="bizcity_crm_admin_chat_grant_save">
			<input type="hidden" name="grant_id" value="<?php echo (int) $g['id']; ?>">

			<table class="form-table">
				<tr>
					<th>Tool classes</th>
					<td>
						<label style="margin-right:18px;"><input type="checkbox" name="allow_producer" value="1" <?php checked( $g['allow_producer'], 1 ); ?>> <strong>Producer</strong> <span style="color:#64748b;font-size:12px;">(tarot, draft article, summarize — reversible)</span></label><br>
						<label style="margin-right:18px;"><input type="checkbox" name="allow_retriever" value="1" <?php checked( $g['allow_retriever'], 1 ); ?>> <strong>Retriever</strong> <span style="color:#64748b;font-size:12px;">(tavily, crawl, OCR — costs money)</span></label><br>
						<label><input type="checkbox" name="allow_distributor" value="1" <?php checked( $g['allow_distributor'], 1 ); ?>> <strong style="color:#b91c1c;">Distributor ⚠️</strong> <span style="color:#64748b;font-size:12px;">(post FB, send email — irreversible)</span></label>
					</td>
				</tr>
				<tr>
					<th>Quota / day</th>
					<td>
						<input type="number" name="quota_per_day" value="<?php echo (int) $g['quota_per_day']; ?>" min="0" max="9999" class="small-text">
						<span style="color:#64748b;font-size:12px;">Áp cho Retriever class. Đặt 0 = chặn hoàn toàn.</span>
					</td>
				</tr>
				<tr>
					<th>Tool overrides (JSON)</th>
					<td>
						<textarea name="tool_overrides_json" rows="6" style="width:100%;font-family:monospace;font-size:12px;" placeholder='{"post_facebook":"deny","send_email":"confirm","gen_image":"allow"}'><?php echo esc_textarea( $overrides_pretty ); ?></textarea>
						<p class="description">Per-tool override thắng class toggle. Verb hợp lệ: <code>allow</code> | <code>confirm</code> | <code>deny</code>.</p>
					</td>
				</tr>
			</table>

			<p><button type="submit" class="button button-primary">Lưu grant</button></p>
		</form>
		<?php
	}

	private static function guru_name( int $cid ): string {
		if ( $cid <= 0 ) { return ''; }
		static $cache = array();
		if ( isset( $cache[ $cid ] ) ) { return $cache[ $cid ]; }
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) { return $cache[ $cid ] = ''; }
		try {
			$kdb = BizCity_Knowledge_Database::instance();
			if ( method_exists( $kdb, 'get_character' ) ) {
				$c = $kdb->get_character( $cid );
				if ( is_object( $c ) ) { return $cache[ $cid ] = (string) ( $c->name ?? '' ); }
			}
		} catch ( Throwable $e ) {}
		return $cache[ $cid ] = '';
	}
}
