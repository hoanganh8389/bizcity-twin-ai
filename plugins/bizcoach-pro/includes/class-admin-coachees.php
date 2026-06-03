<?php
/**
 * BizCoach Pro — Admin "Coachees List" submenu.
 *
 * Adds a submenu under the legacy "My AI Profile" (slug `bccm_user_profiles`)
 * that lists all coachees saved through the Coach Builder + legacy paths.
 *
 * Data source: wp_bccm_coachees (profiles) + wp_bccm_action_plans (public_url).
 * Reuses BizCoach_Pro_Artifact_Service::list_for_user() so the list is
 * served from object cache (CACHE-STRATEGY.md §3, group `bcpro_coachee_idx`).
 *
 * @since 0.4.0 (2026-05-16)
 */
defined( 'ABSPATH' ) || exit;
if ( class_exists( 'BizCoach_Pro_Admin_Coachees' ) ) { return; }

class BizCoach_Pro_Admin_Coachees {

	const MENU_SLUG = 'bcpro_coachees_list';
	const PARENT    = 'bccm_user_profiles'; // legacy "My AI Profile" top-level

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 30 );
	}

	public static function register_menu() {
		// Late priority so it lands AFTER legacy submenus.
		add_submenu_page(
			self::PARENT,
			'Coachees',
			'Coachees',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền truy cập trang này.', 'bizcoach-pro' ) );
		}

		$paged    = max( 1, (int) ( $_GET['paged']     ?? 1 ) );
		$per_page = 25;
		$search   = trim( (string) ( $_GET['s']        ?? '' ) );
		$filter_t = sanitize_key( (string) ( $_GET['coach_type'] ?? '' ) );
		$filter_u = (int) ( $_GET['user_id']           ?? 0 );

		global $wpdb;
		$tbl  = $wpdb->prefix . 'bccm_coachees';
		$plan = $wpdb->prefix . 'bccm_action_plans';

		// Build WHERE.
		$where  = array( '1=1' );
		$params = array();
		if ( $search !== '' ) {
			$where[]  = '(c.full_name LIKE %s OR c.phone LIKE %s OR c.id = %d)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = (int) $search;
		}
		if ( $filter_t !== '' ) {
			$where[]  = 'c.coach_type = %s';
			$params[] = $filter_t;
		}
		if ( $filter_u > 0 ) {
			$where[]  = 'c.user_id = %d';
			$params[] = $filter_u;
		}
		$where_sql = implode( ' AND ', $where );

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$tbl} c WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		// Page query — LEFT JOIN plans for public_key.
		$offset = ( $paged - 1 ) * $per_page;
		$rows_sql = "SELECT c.id, c.user_id, c.coach_type, c.full_name, c.phone, c.dob,
		                    c.platform_type, c.created_at, c.updated_at,
		                    p.public_key
		             FROM {$tbl} c
		             LEFT JOIN {$plan} p ON p.coachee_id = c.id AND p.status='active'
		             WHERE {$where_sql}
		             ORDER BY c.id DESC
		             LIMIT %d OFFSET %d";
		$params_p = array_merge( $params, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, $params_p ), ARRAY_A );

		// Coach type list for filter dropdown.
		$types = array();
		if ( function_exists( 'bccm_coach_types' ) ) {
			foreach ( bccm_coach_types() as $slug => $cfg ) {
				$types[ $slug ] = (string) ( $cfg['label'] ?? $slug );
			}
		}

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">📋 Danh sách Coachees</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bccm_step2_coach_template' ) ); ?>" class="page-title-action">+ Thêm Coachee mới</a>
			<hr class="wp-header-end">


			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<p class="search-box" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
					<label for="bcpro-search" class="screen-reader-text">Tìm:</label>
					<input type="search" id="bcpro-search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="Tìm theo tên / phone / id…" style="min-width:240px;" />
					<select name="coach_type">
						<option value="">— Tất cả Coach Type —</option>
						<?php foreach ( $types as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filter_t, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="number" name="user_id" value="<?php echo $filter_u ?: ''; ?>"
						placeholder="user_id" style="width:90px;" />
					<input type="submit" class="button" value="Lọc" />
					<?php if ( $search !== '' || $filter_t !== '' || $filter_u > 0 ) : ?>
						<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>">↺ Reset</a>
					<?php endif; ?>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:60px">ID</th>
						<th>Tên</th>
						<th style="width:140px">Coach Type</th>
						<th style="width:110px">User</th>
						<th style="width:110px">DOB</th>
						<th style="width:110px">Phone</th>
						<th style="width:90px">Platform</th>
						<th style="width:150px">Cập nhật</th>
						<th style="width:240px">Hành động</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9" style="text-align:center;padding:24px;color:#999">— Không có coachee nào phù hợp —</td></tr>
					<?php else : foreach ( $rows as $r ) :
						$cid = (int) $r['id'];
						$pk  = (string) ( $r['public_key'] ?? '' );
						$pub_url = $pk && function_exists( 'bccm_public_map_url' )
							? bccm_public_map_url( $pk )
							: ( $pk ? home_url( '/coach-builder/' . rawurlencode( $pk ) . '/' ) : '' );
						$user_obj = $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
						$type_label = $types[ $r['coach_type'] ] ?? $r['coach_type'];
					?>
						<tr>
							<td><strong>#<?php echo $cid; ?></strong></td>
							<td>
								<strong><?php echo esc_html( $r['full_name'] ?: '(no name)' ); ?></strong>
							</td>
							<td><code><?php echo esc_html( $type_label ); ?></code></td>
							<td>
								<?php if ( $user_obj ) : ?>
									<a href="<?php echo esc_url( get_edit_user_link( $user_obj->ID ) ); ?>">
										<?php echo esc_html( $user_obj->user_login ); ?>
									</a>
									<br><small style="color:#999">uid=<?php echo (int) $user_obj->ID; ?></small>
								<?php else : ?>
									<span style="color:#ccc">—</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $r['dob'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $r['phone'] ?: '—' ); ?></td>
							<td><small><?php echo esc_html( $r['platform_type'] ?: '—' ); ?></small></td>
							<td><small><?php echo esc_html( $r['updated_at'] ?: $r['created_at'] ); ?></small></td>
							<td>
								<a class="button button-small"
									href="<?php echo esc_url( admin_url( 'admin.php?page=bccm_user_profiles&action=view&coachee_id=' . $cid ) ); ?>">View</a>
								<a class="button button-small"
									href="<?php echo esc_url( admin_url( 'admin.php?page=bccm_user_profiles&action=edit&coachee_id=' . $cid ) ); ?>">Edit</a>
								<?php if ( $pub_url ) : ?>
									<a class="button button-small button-primary" target="_blank" rel="noopener"
										href="<?php echo esc_url( $pub_url ); ?>">🔗 Map</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) :
				$base_args = array( 'page' => self::MENU_SLUG );
				if ( $search   !== '' ) { $base_args['s']          = $search; }
				if ( $filter_t !== '' ) { $base_args['coach_type'] = $filter_t; }
				if ( $filter_u > 0 )    { $base_args['user_id']    = $filter_u; }
				$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );
			?>
				<div class="tablenav bottom"><div class="tablenav-pages">
					<span class="displaying-num"><?php echo (int) $total; ?> mục</span>
					<?php
					echo paginate_links( array(
						'base'      => add_query_arg( 'paged', '%#%', $base_url ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					) );
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}

BizCoach_Pro_Admin_Coachees::init();
