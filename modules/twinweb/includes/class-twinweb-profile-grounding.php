<?php
/**
 * TwinWeb Profile Grounding REST, admin page and customer shortcode.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-19 (PHASE-TWIN-GPT-PROFILE-GROUNDING)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Profile_Grounding', false ) ) {
	return;
}

final class BizCity_TwinWeb_Profile_Grounding {

	const NS          = 'bizcity-twinweb/v1';
	const ADMIN_SLUG  = 'bizcity-twin-gpt-profile-templates';
	const SHORTCODE   = 'bizcity_twin_profile_grounding';

	/**
	 * Init hooks.
	 */
	public static function init() {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — REST/admin/customer surface for profile templates.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 60 );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'inject_my_astro_bridge_config' ), 30 );
		add_action( 'bizcity_twin_profile_saved', array( __CLASS__, 'sync_profile_to_crm_contact' ), 10, 4 );
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes() {
		register_rest_route( self::NS, '/admin/profile-templates', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_get_templates' ),
				'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_save_template' ),
				'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
			),
		) );

		register_rest_route( self::NS, '/admin/profile-templates/(?P<slug>[A-Za-z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_get_template' ),
				'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'admin_save_template' ),
				'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
			),
		) );

		register_rest_route( self::NS, '/admin/profile-templates/import', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_import_templates' ),
			'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
		) );

		register_rest_route( self::NS, '/admin/profile-template-bindings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_get_bindings' ),
				'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'admin_put_bindings' ),
				'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
			),
		) );

		register_rest_route( self::NS, '/admin/customer-profiles', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'admin_get_customer_profiles' ),
			'permission_callback' => array( __CLASS__, 'admin_cap_check' ),
		) );

		register_rest_route( self::NS, '/me/profile-templates', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_get_templates' ),
			'permission_callback' => array( __CLASS__, 'member_cap_check' ),
		) );

		register_rest_route( self::NS, '/me/profile-answers', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_get_answers' ),
			'permission_callback' => array( __CLASS__, 'member_cap_check' ),
		) );

		register_rest_route( self::NS, '/me/profile-answers/(?P<template_slug>[A-Za-z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'me_get_template_answers' ),
				'permission_callback' => array( __CLASS__, 'member_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'me_put_template_answers' ),
				'permission_callback' => array( __CLASS__, 'member_cap_check' ),
			),
		) );

		register_rest_route( self::NS, '/me/profile-answers/(?P<template_slug>[A-Za-z0-9_-]+)/activate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_activate_template' ),
			'permission_callback' => array( __CLASS__, 'member_cap_check' ),
		) );
	}

	/**
	 * Admin permission callback.
	 *
	 * @return bool
	 */
	public static function admin_cap_check() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Customer permission callback.
	 *
	 * @return bool
	 */
	public static function member_cap_check() {
		return is_user_logged_in();
	}

	/**
	 * GET admin templates.
	 */
	public static function admin_get_templates() {
		$layer = self::layer();
		return rest_ensure_response( array(
			'success'      => true,
			'templates'    => $layer->all_templates( true ),
			'bindings'     => $layer->get_bindings(),
			'seed_dir'     => BizCity_TwinBrain_Subject_Profile_Layer::seed_dir(),
			'generated_at' => gmdate( 'c' ),
		) );
	}

	/**
	 * GET one admin template.
	 */
	public static function admin_get_template( WP_REST_Request $request ) {
		$template = self::layer()->get_template( $request->get_param( 'slug' ) );
		if ( ! is_array( $template ) ) {
			return self::error_response( 'not_found', 'Không tìm thấy mẫu hồ sơ.', 'Chọn mẫu đang tồn tại.', 'not_found', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'template' => $template ) );
	}

	/**
	 * POST/PUT admin template.
	 */
	public static function admin_save_template( WP_REST_Request $request ) {
		$raw = $request->get_param( 'template' );
		if ( ! is_array( $raw ) ) {
			$raw = $request->get_json_params();
		}
		if ( ! is_array( $raw ) ) {
			return self::error_response( 'invalid_param', 'Template hồ sơ không hợp lệ.', 'Gửi template dạng object JSON.', 'invalid_param_generic', 400 );
		}
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		if ( '' !== $slug ) {
			$raw['slug'] = $slug;
		}
		$result = self::layer()->save_template( $raw );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return self::error_response(
				'invalid_param',
				$result->get_error_message(),
				isset( $data['hint'] ) ? (string) $data['hint'] : 'Kiểm tra JSON template.',
				isset( $data['help_code'] ) ? (string) $data['help_code'] : 'invalid_param_generic',
				isset( $data['status'] ) ? (int) $data['status'] : 400,
				array( 'errors' => isset( $data['errors'] ) ? (array) $data['errors'] : array() )
			);
		}
		return rest_ensure_response( array( 'success' => true, 'template' => $result ) );
	}

	/**
	 * POST import seed templates.
	 */
	public static function admin_import_templates( WP_REST_Request $request ) {
		$result = self::layer()->import_seed_templates( ! empty( $request->get_param( 'overwrite' ) ) );
		return rest_ensure_response( $result );
	}

	/**
	 * GET bindings.
	 */
	public static function admin_get_bindings() {
		return rest_ensure_response( array( 'success' => true, 'bindings' => self::layer()->get_bindings() ) );
	}

	/**
	 * GET customer profile summary for operators/admin care.
	 */
	public static function admin_get_customer_profiles( WP_REST_Request $request ) {
		$limit = max( 1, min( 200, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$offset = max( 0, (int) ( $request->get_param( 'offset' ) ?: 0 ) );
		$search = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?: '' ) );
		return rest_ensure_response( array_merge( array( 'success' => true ), self::customer_profile_rows( $limit, $offset, $search ) ) );
	}

	/**
	 * PUT bindings.
	 */
	public static function admin_put_bindings( WP_REST_Request $request ) {
		$bindings = $request->get_param( 'bindings' );
		if ( ! is_array( $bindings ) ) {
			$bindings = $request->get_json_params();
		}
		if ( ! is_array( $bindings ) ) {
			return self::error_response( 'invalid_param', 'Cấu hình binding không hợp lệ.', 'Gửi bindings dạng object JSON.', 'invalid_param_generic', 400 );
		}
		$bindings['updated_at'] = gmdate( 'c' );
		$bindings['updated_by'] = (int) get_current_user_id();
		return rest_ensure_response( array( 'success' => true, 'bindings' => self::layer()->save_bindings( $bindings ) ) );
	}

	/**
	 * GET customer-visible templates.
	 */
	public static function me_get_templates() {
		return rest_ensure_response( array(
			'success'   => true,
			'templates' => self::public_templates( self::layer()->all_templates( false ) ),
			'profile'   => self::layer()->get_user_profile( get_current_user_id() ),
		) );
	}

	/**
	 * GET customer answers root.
	 */
	public static function me_get_answers() {
		return rest_ensure_response( array(
			'success' => true,
			'profile' => self::layer()->get_user_profile( get_current_user_id() ),
		) );
	}

	/**
	 * GET customer answers for one template.
	 */
	public static function me_get_template_answers( WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'template_slug' ) );
		$profile = self::layer()->get_user_profile( get_current_user_id() );
		$template = self::layer()->get_template( $slug );
		if ( ! is_array( $template ) ) {
			return self::error_response( 'not_found', 'Không tìm thấy mẫu hồ sơ.', 'Chọn mẫu đang hoạt động.', 'not_found', 404 );
		}
		$entry = isset( $profile['templates'][ $slug ] ) && is_array( $profile['templates'][ $slug ] ) ? $profile['templates'][ $slug ] : array();
		return rest_ensure_response( array( 'success' => true, 'template' => self::public_template( $template ), 'entry' => $entry ) );
	}

	/**
	 * PUT customer answers.
	 */
	public static function me_put_template_answers( WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'template_slug' ) );
		$answers = $request->get_param( 'answers' );
		if ( ! is_array( $answers ) ) {
			$json = $request->get_json_params();
			$answers = isset( $json['answers'] ) && is_array( $json['answers'] ) ? $json['answers'] : array();
		}
		$result = self::layer()->save_user_answers( get_current_user_id(), $slug, is_array( $answers ) ? $answers : array(), true );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return self::error_response( $result->get_error_code(), $result->get_error_message(), isset( $data['hint'] ) ? (string) $data['hint'] : 'Kiểm tra lại hồ sơ.', isset( $data['help_code'] ) ? (string) $data['help_code'] : 'invalid_param_generic', isset( $data['status'] ) ? (int) $data['status'] : 400 );
		}
		return rest_ensure_response( array( 'success' => true, 'profile' => $result ) );
	}

	/**
	 * POST activate a template for current customer.
	 */
	public static function me_activate_template( WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'template_slug' ) );
		$profile = self::layer()->get_user_profile( get_current_user_id() );
		$answers = isset( $profile['templates'][ $slug ]['answers'] ) && is_array( $profile['templates'][ $slug ]['answers'] ) ? $profile['templates'][ $slug ]['answers'] : array();
		$result = self::layer()->save_user_answers( get_current_user_id(), $slug, $answers, true );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return self::error_response( $result->get_error_code(), $result->get_error_message(), isset( $data['hint'] ) ? (string) $data['hint'] : 'Chọn lại mẫu hồ sơ.', isset( $data['help_code'] ) ? (string) $data['help_code'] : 'not_found', isset( $data['status'] ) ? (int) $data['status'] : 400 );
		}
		return rest_ensure_response( array( 'success' => true, 'profile' => $result ) );
	}

	/**
	 * Register WP admin page.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'tools.php',
			'Twin GPT · Customer Profiles',
			'Twin GPT Profiles',
			'manage_options',
			self::ADMIN_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render simple WP admin page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', 403 );
		}
		$layer = self::layer();
		$message = '';
		$error = '';
		if ( isset( $_POST['bizcity_twin_profile_import'] ) ) {
			check_admin_referer( 'bizcity_twin_profile_templates' );
			$result = $layer->import_seed_templates( ! empty( $_POST['overwrite'] ) );
			$message = sprintf( 'Imported: %d created, %d updated, %d skipped.', (int) $result['created'], (int) $result['updated'], (int) $result['skipped'] );
		}
		if ( isset( $_POST['bizcity_twin_profile_save_template'] ) ) {
			check_admin_referer( 'bizcity_twin_profile_templates' );
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — admin can define one JSON customer profile template from UI, not only via REST.
			$json = isset( $_POST['template_json'] ) ? (string) wp_unslash( $_POST['template_json'] ) : '';
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				$error = 'Template JSON không hợp lệ.';
			} else {
				$saved = $layer->save_template( $decoded );
				if ( is_wp_error( $saved ) ) {
					$error = $saved->get_error_message();
				} else {
					$message = 'Đã lưu template: ' . (string) $saved['slug'];
				}
			}
		}
		if ( isset( $_POST['bizcity_twin_profile_save_bindings'] ) ) {
			check_admin_referer( 'bizcity_twin_profile_templates' );
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — admin can bind vertical/mode to template from JSON UI.
			$json = isset( $_POST['bindings_json'] ) ? (string) wp_unslash( $_POST['bindings_json'] ) : '';
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				$error = 'Bindings JSON không hợp lệ.';
			} else {
				$decoded['updated_at'] = gmdate( 'c' );
				$decoded['updated_by'] = (int) get_current_user_id();
				$layer->save_bindings( $decoded );
				$message = 'Đã lưu bindings profile template.';
			}
		}
		$templates = $layer->all_templates( true );
		$bindings  = $layer->get_bindings();
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — WP admin customer list supports search by name/email for care operators.
		$profile_search = isset( $_GET['profile_search'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['profile_search'] ) ) : '';
		$customer_profiles = self::customer_profile_rows( 50, 0, $profile_search );
		$template_example = ! empty( $templates[0] ) ? $templates[0] : array(
			'slug'        => 'custom_coach',
			'label'       => 'Custom Coach',
			'vertical'    => 'custom',
			'status'      => 'active',
			'questions'   => array(),
		);
		?>
		<div class="wrap">
			<h1>Twin GPT · Customer Profile Grounding</h1>
			<?php if ( $message ) : ?><div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>
			<?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
			<p>Quản lý mẫu hồ sơ customer dùng cho Subject Profile Layer trước khi TwinBrain trả lời Notebook/vertical.</p>
			<form method="post" style="margin:16px 0;">
				<?php wp_nonce_field( 'bizcity_twin_profile_templates' ); ?>
				<input type="hidden" name="bizcity_twin_profile_import" value="1">
				<label><input type="checkbox" name="overwrite" value="1"> Overwrite tenant templates</label>
				<button type="submit" class="button button-primary">Import seed templates</button>
			</form>
			<h2>Define / update template JSON</h2>
			<form method="post" style="margin:16px 0;max-width:960px;">
				<?php wp_nonce_field( 'bizcity_twin_profile_templates' ); ?>
				<input type="hidden" name="bizcity_twin_profile_save_template" value="1">
				<textarea name="template_json" rows="18" style="width:100%;font-family:Consolas,monospace;"><?php echo esc_textarea( wp_json_encode( $template_example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
				<p><button type="submit" class="button button-secondary">Save template JSON</button></p>
			</form>
			<h2>Bind templates to verticals / modes</h2>
			<form method="post" style="margin:16px 0;max-width:960px;">
				<?php wp_nonce_field( 'bizcity_twin_profile_templates' ); ?>
				<input type="hidden" name="bizcity_twin_profile_save_bindings" value="1">
				<textarea name="bindings_json" rows="10" style="width:100%;font-family:Consolas,monospace;"><?php echo esc_textarea( wp_json_encode( $bindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
				<p><button type="submit" class="button button-secondary">Save bindings JSON</button></p>
			</form>
			<h2>REST endpoints</h2>
			<ul>
				<li><code>GET <?php echo esc_html( rest_url( self::NS . '/admin/profile-templates' ) ); ?></code></li>
				<li><code>PUT <?php echo esc_html( rest_url( self::NS . '/admin/profile-template-bindings' ) ); ?></code></li>
				<li><code>GET <?php echo esc_html( rest_url( self::NS . '/me/profile-templates' ) ); ?></code></li>
			</ul>
			<h2>Bindings</h2>
			<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:960px;overflow:auto;"><?php echo esc_html( wp_json_encode( $bindings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
			<h2>Customer Hồ sơ AI</h2>
			<p>Danh sách này đọc tenant-scoped usermeta và được sync summary sang CRM contact khi customer lưu hồ sơ.</p>
			<form method="get" style="margin:0 0 10px;max-width:1200px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::ADMIN_SLUG ); ?>">
				<input type="search" name="profile_search" value="<?php echo esc_attr( $profile_search ); ?>" placeholder="Tìm theo tên hoặc email..." style="width:280px;">
				<button type="submit" class="button">Tìm kiếm</button>
				<?php if ( '' !== $profile_search ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::ADMIN_SLUG ) ); ?>">Xóa lọc</a>
					<span style="margin-left:8px;color:#555;">Kết quả cho: <strong><?php echo esc_html( $profile_search ); ?></strong></span>
				<?php endif; ?>
			</form>
			<table class="widefat striped" style="max-width:1200px;margin-bottom:24px;">
				<thead><tr><th>User</th><th>Email</th><th>Active template</th><th>Templates</th><th>Answered at</th><th>Missing required</th></tr></thead>
				<tbody>
				<?php if ( empty( $customer_profiles['rows'] ) ) : ?>
					<tr><td colspan="6">Chưa có customer nào lưu Hồ sơ AI.</td></tr>
				<?php else : ?>
					<?php foreach ( $customer_profiles['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['display_name'] ); ?> <code>#<?php echo esc_html( (string) $row['user_id'] ); ?></code></td>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><code><?php echo esc_html( $row['active_template_slug'] ); ?></code></td>
							<td><?php echo esc_html( (string) $row['templates_count'] ); ?></td>
							<td><?php echo esc_html( $row['answered_at'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', (array) $row['missing_required'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<h2>Templates</h2>
			<table class="widefat striped" style="max-width:1200px;">
				<thead><tr><th>Slug</th><th>Label</th><th>Vertical</th><th>Status</th><th>Questions</th><th>Source</th></tr></thead>
				<tbody>
				<?php foreach ( $templates as $template ) : ?>
					<tr>
						<td><code><?php echo esc_html( $template['slug'] ); ?></code></td>
						<td><?php echo esc_html( $template['label'] ); ?></td>
						<td><?php echo esc_html( $template['vertical'] ); ?></td>
						<td><?php echo esc_html( $template['status'] ); ?></td>
						<td><?php echo esc_html( count( (array) $template['questions'] ) ); ?>/10</td>
						<td><?php echo esc_html( $template['source'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render customer shortcode form.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>Vui lòng đăng nhập để lưu Hồ sơ AI.</p>';
		}
		$layer = self::layer();
		$templates = $layer->all_templates( false );
		if ( empty( $templates ) ) {
			return '<p>Chưa có mẫu Hồ sơ AI nào được cấu hình.</p>';
		}

		$selected_slug = isset( $_REQUEST['tw_profile_template'] ) ? sanitize_key( (string) $_REQUEST['tw_profile_template'] ) : '';
		if ( '' === $selected_slug ) {
			$profile = $layer->get_user_profile( get_current_user_id() );
			$selected_slug = sanitize_key( (string) ( $profile['active_template_slug'] ?? '' ) );
		}
		$template = $selected_slug ? $layer->get_template( $selected_slug ) : null;
		if ( ! is_array( $template ) ) {
			$template = reset( $templates );
			$selected_slug = (string) $template['slug'];
		}

		$message = '';
		if ( isset( $_POST['bizcity_twin_profile_save'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( (string) $_POST['_wpnonce'] ), 'bizcity_twin_profile_save' ) ) {
			$answers = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? wp_unslash( $_POST['answers'] ) : array();
			$result = $layer->save_user_answers( get_current_user_id(), $selected_slug, $answers, true );
			$message = is_wp_error( $result ) ? $result->get_error_message() : 'Đã lưu Hồ sơ AI.';
		}

		$profile = $layer->get_user_profile( get_current_user_id() );
		$entry = isset( $profile['templates'][ $selected_slug ] ) && is_array( $profile['templates'][ $selected_slug ] ) ? $profile['templates'][ $selected_slug ] : array();
		$answers = isset( $entry['answers'] ) && is_array( $entry['answers'] ) ? $entry['answers'] : array();

		ob_start();
		?>
		<div class="bizcity-twin-profile-grounding" data-twin-profile-grounding="1" style="max-width:880px;margin:24px auto;padding:20px;border:1px solid #d9e2ec;border-radius:8px;background:#fff;">
			<h2 style="margin-top:0;">Hồ sơ AI</h2>
			<p>Hồ sơ này giúp Twin GPT xác định chủ thể trước khi trả lời bằng Notebook doanh nghiệp đã đào tạo.</p>
			<?php if ( $message ) : ?><p><strong><?php echo esc_html( $message ); ?></strong></p><?php endif; ?>
			<form method="get" style="margin-bottom:16px;">
				<label>Mẫu hồ sơ: </label>
				<select name="tw_profile_template" onchange="this.form.submit()">
					<?php foreach ( $templates as $tpl ) : ?>
						<option value="<?php echo esc_attr( $tpl['slug'] ); ?>" <?php selected( $selected_slug, $tpl['slug'] ); ?>><?php echo esc_html( $tpl['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</form>
			<form method="post">
				<?php wp_nonce_field( 'bizcity_twin_profile_save' ); ?>
				<input type="hidden" name="bizcity_twin_profile_save" value="1">
				<input type="hidden" name="tw_profile_template" value="<?php echo esc_attr( $selected_slug ); ?>">
				<?php foreach ( (array) $template['questions'] as $question ) : ?>
					<?php self::render_question_field( $question, isset( $answers[ $question['key'] ] ) ? $answers[ $question['key'] ] : '' ); ?>
				<?php endforeach; ?>
				<button type="submit" class="button button-primary">Lưu Hồ sơ AI</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Inject bridge config for My Astro build.
	 */
	public static function inject_my_astro_bridge_config() {
		if ( ! wp_script_is( 'bcpro-self-service', 'enqueued' ) && ! wp_script_is( 'bcpro-self-service', 'registered' ) ) {
			return;
		}
		$config = array(
			'enabled'   => true,
			'label'     => 'Hồ sơ AI',
			'restBase'  => esc_url_raw( rest_url( self::NS ) ),
			'shortcode' => '[' . self::SHORTCODE . ']',
			'marker'    => 'PHASE-TWIN-GPT-PROFILE-GROUNDING',
		);
		wp_add_inline_script(
			'bcpro-self-service',
			'window.bizcityTwinProfileGrounding = ' . wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	private static function render_question_field( array $question, $value ) {
		$key = (string) $question['key'];
		$type = (string) $question['type'];
		$label = (string) $question['label'];
		$required = ! empty( $question['required'] );
		$name = 'answers[' . esc_attr( $key ) . ']';
		?>
		<p style="margin-bottom:14px;">
			<label style="display:block;font-weight:600;margin-bottom:6px;"><?php echo esc_html( $label ); ?><?php echo $required ? ' *' : ''; ?></label>
			<?php if ( 'textarea' === $type ) : ?>
				<textarea name="<?php echo $name; ?>" rows="4" style="width:100%;max-width:720px;" <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea( is_array( $value ) ? implode( ', ', $value ) : (string) $value ); ?></textarea>
			<?php elseif ( 'select' === $type ) : ?>
				<select name="<?php echo $name; ?>" <?php echo $required ? 'required' : ''; ?>>
					<option value="">-- Chọn --</option>
					<?php foreach ( (array) $question['choices'] as $choice_key => $choice_label ) : ?>
						<option value="<?php echo esc_attr( $choice_key ); ?>" <?php selected( (string) $value, (string) $choice_key ); ?>><?php echo esc_html( $choice_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'multiselect' === $type ) : ?>
				<?php foreach ( (array) $question['choices'] as $choice_key => $choice_label ) : ?>
					<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="answers[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $choice_key ); ?>" <?php checked( in_array( (string) $choice_key, (array) $value, true ) ); ?>> <?php echo esc_html( $choice_label ); ?></label>
				<?php endforeach; ?>
			<?php elseif ( 'boolean' === $type ) : ?>
				<label><input type="checkbox" name="<?php echo $name; ?>" value="1" <?php checked( (string) $value, '1' ); ?>> Có</label>
			<?php else : ?>
				<input type="<?php echo 'number' === $type ? 'number' : ( 'date' === $type ? 'date' : 'text' ); ?>" name="<?php echo $name; ?>" value="<?php echo esc_attr( is_array( $value ) ? implode( ', ', $value ) : (string) $value ); ?>" style="width:100%;max-width:720px;" <?php echo $required ? 'required' : ''; ?>>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function public_templates( array $templates ) {
		$out = array();
		foreach ( $templates as $template ) {
			$out[] = self::public_template( $template );
		}
		return $out;
	}

	private static function public_template( array $template ) {
		return array(
			'slug'        => (string) $template['slug'],
			'version'     => (string) ( $template['version'] ?? '1.0.0' ),
			'label'       => (string) $template['label'],
			'description' => (string) ( $template['description'] ?? '' ),
			'vertical'    => (string) ( $template['vertical'] ?? '' ),
			'domain'      => (string) ( $template['domain'] ?? '' ),
			'format'      => (string) ( $template['format'] ?? 'intake' ),
			'expert_role' => (string) ( $template['expert_role'] ?? '' ),
			'risk_level'  => (string) ( $template['risk_level'] ?? 'standard' ),
			'questions'   => (array) ( $template['questions'] ?? array() ),
			'grounding'   => (array) ( $template['grounding'] ?? array() ),
		);
	}

	private static function customer_profile_rows( $limit = 50, $offset = 0, $search = '' ) {
		$users = get_users( array(
			'number'  => (int) $limit,
			'offset'  => (int) $offset,
			'search'  => $search !== '' ? '*' . $search . '*' : '',
			'fields'  => array( 'ID', 'display_name', 'user_email' ),
			'orderby' => 'ID',
			'order'   => 'DESC',
		) );
		$layer = self::layer();
		$rows = array();
		foreach ( $users as $user ) {
			$profile = $layer->get_user_profile( (int) $user->ID );
			$templates = isset( $profile['templates'] ) && is_array( $profile['templates'] ) ? $profile['templates'] : array();
			if ( empty( $templates ) && '' === (string) ( $profile['active_template_slug'] ?? '' ) ) {
				continue;
			}
			$active = sanitize_key( (string) ( $profile['active_template_slug'] ?? '' ) );
			$entry = $active && isset( $templates[ $active ] ) && is_array( $templates[ $active ] ) ? $templates[ $active ] : array();
			$rows[] = array(
				'user_id'              => (int) $user->ID,
				'display_name'         => (string) $user->display_name,
				'email'                => (string) $user->user_email,
				'active_template_slug' => $active,
				'templates_count'      => count( $templates ),
				'answered_at'          => (string) ( $entry['answered_at'] ?? '' ),
				'missing_required'     => isset( $entry['missing_required'] ) && is_array( $entry['missing_required'] ) ? array_values( $entry['missing_required'] ) : array(),
			);
		}
		return array(
			'rows'   => $rows,
			'count'  => count( $rows ),
			'limit'  => (int) $limit,
			'offset' => (int) $offset,
		);
	}

	public static function sync_profile_to_crm_contact( $user_id, $template_slug, $profile, $template ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — CRM sync is contact enrichment, not a new lead submission.
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_contacts' ) ) {
			return;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		if ( ! self::table_exists( $tbl ) ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}
		$blog_id = (int) get_current_blog_id();
		$entry = isset( $profile['templates'][ $template_slug ] ) && is_array( $profile['templates'][ $template_slug ] ) ? $profile['templates'][ $template_slug ] : array();
		$answers = isset( $entry['answers'] ) && is_array( $entry['answers'] ) ? $entry['answers'] : array();
		$meta = array(
			'blog_id'              => $blog_id,
			'active_template_slug' => sanitize_key( (string) ( $profile['active_template_slug'] ?? '' ) ),
			'last_template_slug'   => sanitize_key( (string) $template_slug ),
			'template_label'       => is_array( $template ) ? sanitize_text_field( (string) ( $template['label'] ?? $template_slug ) ) : sanitize_key( (string) $template_slug ),
			'template_format'      => is_array( $template ) ? sanitize_key( (string) ( $template['format'] ?? 'intake' ) ) : 'intake',
			'template_domain'      => is_array( $template ) ? sanitize_key( (string) ( $template['domain'] ?? ( $template['vertical'] ?? '' ) ) ) : '',
			'risk_level'           => is_array( $template ) ? sanitize_key( (string) ( $template['risk_level'] ?? 'standard' ) ) : 'standard',
			'facts_count'          => count( $answers ),
			'missing_required'     => isset( $entry['missing_required'] ) && is_array( $entry['missing_required'] ) ? array_values( array_map( 'sanitize_key', $entry['missing_required'] ) ) : array(),
			'updated_at'           => gmdate( 'c' ),
		);
		$email = sanitize_email( (string) $user->user_email );
		$phone = sanitize_text_field( (string) ( get_user_meta( (int) $user_id, 'billing_phone', true ) ?: get_user_meta( (int) $user_id, 'phone', true ) ) );
		$name = sanitize_text_field( (string) $user->display_name );
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE wp_user_id = %d AND deleted_at IS NULL LIMIT 1", (int) $user_id ) );
		if ( ! $contact_id && $email !== '' ) {
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email = %s AND deleted_at IS NULL LIMIT 1", $email ) );
		}
		if ( ! $contact_id && $phone !== '' ) {
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone = %s AND deleted_at IS NULL LIMIT 1", $phone ) );
		}

		$now = current_time( 'mysql' );
		if ( $contact_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT additional_attributes FROM `{$tbl}` WHERE id=%d", $contact_id ), ARRAY_A );
			$attrs = json_decode( (string) ( $row['additional_attributes'] ?? '' ), true );
			$attrs = is_array( $attrs ) ? $attrs : array();
			$attrs['twin_gpt_profile'] = $meta;
			$fields = array(
				'wp_user_id'             => (int) $user_id,
				'additional_attributes'  => wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ),
				'updated_at'             => $now,
			);
			if ( $name !== '' ) { $fields['name'] = $name; }
			if ( $email !== '' ) { $fields['email'] = $email; }
			if ( $phone !== '' ) { $fields['phone'] = $phone; }
			$wpdb->update( $tbl, $fields, array( 'id' => $contact_id ) );
		} else {
			$wpdb->insert( $tbl, array(
				'name'                  => $name,
				'email'                 => $email ?: null,
				'phone'                 => $phone ?: null,
				'wp_user_id'            => (int) $user_id,
				'additional_attributes' => wp_json_encode( array( 'twin_gpt_profile' => $meta ), JSON_UNESCAPED_UNICODE ),
				'acquisition_source'    => 'twin_gpt_profile',
				'created_at'            => $now,
				'updated_at'            => $now,
			) );
			$contact_id = (int) $wpdb->insert_id;
		}
		if ( $contact_id ) {
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — record a CRM activity timeline entry so operators see the profile update without opening raw attrs.
			$act_tbl = $wpdb->prefix . 'bizcity_crm_activities';
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — use local information_schema fallback when global table helper is not loaded in Diagnostics/REST context.
			if ( self::table_exists( $act_tbl ) ) {
				$missing = isset( $meta['missing_required'] ) && is_array( $meta['missing_required'] ) ? $meta['missing_required'] : array();
				$body = 'Template: ' . (string) ( $meta['last_template_slug'] ?? '' )
					. ' · Dữ kiện: ' . (int) ( $meta['facts_count'] ?? 0 )
					. ( ! empty( $missing ) ? ' · Còn thiếu: ' . implode( ', ', array_map( 'strval', $missing ) ) : '' );
				$wpdb->insert( $act_tbl, array(
					'entity_type' => 'contact',
					'entity_id'   => $contact_id,
					'type'        => 'note',
					'title'       => 'Hồ sơ AI cập nhật — ' . (string) ( $meta['template_label'] ?? (string) $template_slug ),
					'body'        => $body,
					'user_id'     => (int) $user_id,
					'user_label'  => $name !== '' ? $name : ( 'User#' . (int) $user_id ),
					'created_at'  => $now,
				) );
			}
			do_action( 'bizcity_crm_contact_saved', $contact_id, array( 'wp_user_id' => (int) $user_id, 'twin_gpt_profile' => $meta ) );
		}
	}

	private static function table_exists( $table_name ) {
		static $cache = array();
		$table_name = (string) $table_name;
		if ( isset( $cache[ $table_name ] ) ) {
			return $cache[ $table_name ];
		}
		$ck = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table_name
			) );
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$cache[ $table_name ] = (bool) $present;
		return $cache[ $table_name ];
	}

	private static function layer() {
		if ( ! class_exists( 'BizCity_TwinBrain_Subject_Profile_Layer' ) ) {
			$core_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/twinbrain/includes/class-twinbrain-subject-profile-layer.php' : '';
			if ( $core_file && is_readable( $core_file ) ) {
				require_once $core_file;
			}
		}
		return BizCity_TwinBrain_Subject_Profile_Layer::instance();
	}

	private static function error_response( $code, $message, $hint, $help_code, $status = 400, array $extra = array() ) {
		return new WP_REST_Response( array_merge( array(
			'success'   => false,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'hint'      => (string) $hint,
			'help_code' => (string) $help_code,
		), $extra ), (int) $status );
	}
}
