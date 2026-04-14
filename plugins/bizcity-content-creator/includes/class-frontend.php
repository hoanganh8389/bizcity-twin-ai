<?php
/**
 * Frontend shortcode & page rendering for Content Creator.
 *
 * Shortcode: [bzcc_creator]
 * Template Page: tool-content-creator (declared in plugin header)
 *
 * Views:
 *   1. Browse   — category grid + template cards (default)
 *   2. Form     — dynamic form builder from template.form_fields
 *   3. Result   — output view (future phase)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Frontend {

	private static bool $assets_enqueued = false;

	/* ── Init ── */

	public static function init(): void {
		add_shortcode( 'bzcc_creator', [ __CLASS__, 'render_shortcode' ] );

		/* Template page integration: bizcity-twin-ai may render this via page template */
		add_filter( 'bizcity_template_page_content', [ __CLASS__, 'maybe_render_template_page' ], 10, 2 );

		/* AJAX handler for form submission */
		add_action( 'wp_ajax_bzcc_submit_form', [ __CLASS__, 'ajax_submit_form' ] );

		/* AJAX handler for image upload */
		add_action( 'wp_ajax_bzcc_upload_image', [ __CLASS__, 'ajax_upload_image' ] );

		/* ── Rewrite rules: /creator/ and /creator/{id}/ ── */
		add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_template_redirect' ] );

		/* ── Sidebar nav item ── */
		add_filter( 'bizcity_sidebar_nav', [ __CLASS__, 'add_sidebar_nav' ] );
	}

	/* ── Rewrite rules ── */

	public static function register_rewrite_rules(): void {
		add_rewrite_rule( '^creator/history/(\d+)/?$', 'index.php?bzcc_page=history-detail&bzcc_file_id=$matches[1]', 'top' );
		add_rewrite_rule( '^creator/history/?$', 'index.php?bzcc_page=history', 'top' );
		add_rewrite_rule( '^creator/result/(\d+)/?$', 'index.php?bzcc_page=result&bzcc_file_id=$matches[1]', 'top' );
		add_rewrite_rule( '^creator/(\d+)/?$', 'index.php?bzcc_page=form&bzcc_template_id=$matches[1]', 'top' );
		add_rewrite_rule( '^creator/?$', 'index.php?bzcc_page=browse', 'top' );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'bzcc_page';
		$vars[] = 'bzcc_template_id';
		$vars[] = 'bzcc_file_id';
		return $vars;
	}

	/**
	 * Intercept /creator/ and /creator/{id}/ requests — render full page.
	 */
	public static function handle_template_redirect(): void {
		$page = get_query_var( 'bzcc_page' );
		if ( ! $page ) {
			return;
		}

		self::enqueue_assets();

		// Use a standalone page template (no wp_head/wp_footer — fully isolated)
		include BZCC_DIR . 'views/page-creator.php';
		exit;
	}

	/* ── Sidebar nav item ── */

	public static function add_sidebar_nav( array $nav ): array {
		/*$nav[] = [
			'slug'  => 'creator',
			'label' => 'Content Creator',
			'icon'  => '✨',
			'type'  => 'link',
			'src'   => home_url( 'creator/' ),
		];*/
		$nav[] = [
			'slug'  => 'creator-history',
			'label' => 'Lịch sử nội dung',
			'icon'  => '📋',
			'type'  => 'link',
			'src'   => home_url( 'creator/history/' ),
		];
		return $nav;
	}

	/* ── Template page hook ── */

	public static function maybe_render_template_page( string $content, string $slug ): string {
		if ( $slug === 'tool-content-creator' ) {
			return self::render_shortcode( [] );
		}
		return $content;
	}

	/* ── Shortcode entry ── */

	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts( [
			'view'        => '',
			'template_id' => 0,
			'file_id'     => 0,
			'category'    => '',
		], $atts, 'bzcc_creator' );

		self::enqueue_assets();

		// Determine view from rewrite query vars → shortcode attrs → GET params
		$view        = sanitize_key( $atts['view'] ?: ( get_query_var( 'bzcc_page' ) ?: ( $_GET['bzcc_view'] ?? 'browse' ) ) );
		$template_id = absint( $atts['template_id'] ?: ( get_query_var( 'bzcc_template_id' ) ?: ( $_GET['bzcc_template'] ?? 0 ) ) );
		$category    = sanitize_key( $atts['category'] ?: ( $_GET['bzcc_cat'] ?? '' ) );

		ob_start();
		echo '<div id="bzcc-app" class="bzcc-wrap" data-view="' . esc_attr( $view ) . '">';

		$file_id = absint( $atts['file_id'] ?: ( get_query_var( 'bzcc_file_id' ) ?: ( $_GET['bzcc_file'] ?? 0 ) ) );

		switch ( $view ) {
			case 'form':
				if ( $template_id ) {
					self::render_form_view( $template_id );
				} else {
					self::render_browse_view( $category );
				}
				break;

			case 'result':
				if ( $file_id ) {
					self::render_result_view( $file_id );
				} else {
					self::render_browse_view( $category );
				}
				break;

			case 'history':
				self::render_history_view();
				break;

			case 'history-detail':
				if ( $file_id ) {
					self::render_history_detail_view( $file_id );
				} else {
					self::render_history_view();
				}
				break;

			case 'browse':
			default:
				self::render_browse_view( $category );
				break;
		}

		echo '</div>';
		return ob_get_clean();
	}

	/* ══════════════════════════════════════════════
	 *  VIEW: Browse — Categories + Template Cards
	 * ══════════════════════════════════════════════ */

	private static function render_browse_view( string $active_category = '' ): void {
		$categories = BZCC_Category_Manager::get_all_active();
		$featured   = BZCC_Template_Manager::get_featured( 6 );

		include BZCC_DIR . 'views/frontend-browse.php';
	}

	/* ══════════════════════════════════════════════
	 *  VIEW: History — User's creator file list
	 * ══════════════════════════════════════════════ */

	private static function render_history_view(): void {
		if ( ! is_user_logged_in() ) {
			echo '<div class="bzcc-empty"><p>Vui lòng đăng nhập để xem lịch sử.</p></div>';
			return;
		}

		$user_id = get_current_user_id();
		$files   = BZCC_File_Manager::get_by_user_with_meta( $user_id, 100 );
		$total   = BZCC_File_Manager::count_by_user( $user_id );

		include BZCC_DIR . 'views/frontend-history.php';
	}

	/* ══════════════════════════════════════════════
	 *  VIEW: History Detail — Single file detail
	 * ══════════════════════════════════════════════ */

	private static function render_history_detail_view( int $file_id ): void {
		if ( ! is_user_logged_in() ) {
			echo '<div class="bzcc-empty"><p>Vui lòng đăng nhập.</p></div>';
			return;
		}

		$file = BZCC_File_Manager::get_by_id( $file_id );
		if ( ! $file ) {
			echo '<div class="bzcc-empty"><p>File không tồn tại.</p></div>';
			return;
		}

		if ( (int) $file->user_id !== get_current_user_id() ) {
			echo '<div class="bzcc-empty"><p>Bạn không có quyền xem nội dung này.</p></div>';
			return;
		}

		$template  = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		$chunks    = BZCC_Chunk_Meta_Manager::get_by_file_with_content( $file_id );
		$form_data = json_decode( $file->form_data, true ) ?: [];
		$outline   = json_decode( $file->outline, true ) ?: [];

		include BZCC_DIR . 'views/frontend-history-detail.php';
	}

	/* ══════════════════════════════════════════════
	 *  VIEW: Form — Dynamic form from template
	 * ══════════════════════════════════════════════ */

	/* ══════════════════════════════════════════════
	 *  VIEW: Result — AI-generated content display
	 * ══════════════════════════════════════════════ */

	private static function render_result_view( int $file_id ): void {
		$file = BZCC_File_Manager::get_by_id( $file_id );
		if ( ! $file ) {
			echo '<div class="bzcc-empty"><p>File không tồn tại.</p></div>';
			return;
		}

		// Ownership check
		if ( (int) $file->user_id !== get_current_user_id() ) {
			echo '<div class="bzcc-empty"><p>Bạn không có quyền xem nội dung này.</p></div>';
			return;
		}

		$template = BZCC_Template_Manager::get_by_id( (int) $file->template_id );
		$chunks   = BZCC_Chunk_Meta_Manager::get_by_file_with_content( $file_id );

		include BZCC_DIR . 'views/frontend-result.php';
	}

	/* ══════════════════════════════════════════════
	 *  VIEW: Form — Dynamic form from template
	 * ══════════════════════════════════════════════ */

	private static function render_form_view( int $template_id ): void {
		$template = BZCC_Template_Manager::get_by_id( $template_id );
		if ( ! $template || $template->status !== 'active' ) {
			echo '<div class="bzcc-empty"><p>Template không tồn tại hoặc đã bị vô hiệu hóa.</p></div>';
			return;
		}

		$category    = BZCC_Category_Manager::get_by_id( (int) $template->category_id );
		$form_fields = json_decode( $template->form_fields, true ) ?: [];

		// Sort fields by sort_order
		usort( $form_fields, fn( $a, $b ) => ( $a['sort_order'] ?? 0 ) <=> ( $b['sort_order'] ?? 0 ) );

		include BZCC_DIR . 'views/frontend-form.php';
	}

	/* ══════════════════════════════════════════════
	 *  Assets
	 * ══════════════════════════════════════════════ */

	private static function enqueue_assets(): void {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$ver = BZCC_VERSION;

		wp_enqueue_style(
			'bzcc-frontend',
			BZCC_URL . 'assets/frontend.css',
			[],
			$ver
		);

		wp_enqueue_script(
			'bzcc-frontend',
			BZCC_URL . 'assets/frontend.js',
			[],
			$ver,
			true
		);

		wp_localize_script( 'bzcc-frontend', 'bzccFront', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'restUrl'  => untrailingslashit( rest_url( 'bzcc/v1' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'bvkNonce' => wp_create_nonce( 'bvk_nonce' ),
			'baseUrl'  => self::get_base_url(),
		] );
	}

	/* ── Helper: base URL (always /creator/) ── */

	private static function get_base_url(): string {
		return home_url( 'creator/' );
	}

	/**
	 * Get clean URL for a template form.
	 *
	 * @param int $template_id Template ID.
	 * @return string e.g. https://site.com/creator/123/
	 */
	public static function get_template_url( int $template_id ): string {
		return home_url( 'creator/' . $template_id . '/' );
	}

	/* ══════════════════════════════════════════════
	 *  Helper: Render a single form field
	 * ══════════════════════════════════════════════ */

	public static function render_field( array $field, $value = '' ): void {
		$slug        = esc_attr( $field['slug'] ?? '' );
		$label       = esc_html( $field['label'] ?? '' );
		$type        = $field['type'] ?? 'text';
		$placeholder = esc_attr( $field['placeholder'] ?? '' );
		$required    = ! empty( $field['required'] );
		$grid        = $field['grid'] ?? 'full';
		$options     = $field['options'] ?? [];
		$min         = intval( $field['min'] ?? 1 );
		$max         = intval( $field['max'] ?? 5 );
		$min_label   = esc_html( $field['min_label'] ?? '' );
		$max_label   = esc_html( $field['max_label'] ?? '' );
		$description = esc_html( $field['description'] ?? '' );
		$badge       = esc_html( $field['badge'] ?? '' );

		$grid_class = $grid === 'half' ? 'bzcc-field--half' : 'bzcc-field--full';
		$req_attr   = $required ? 'required' : '';
		$req_star   = $required ? '<span class="bzcc-req">*</span>' : '';

		// ── Layout types don't wrap in .bzcc-field ──
		switch ( $type ) {

			case 'heading':
				echo '<div class="bzcc-heading bzcc-field--full">';
				echo '<div class="bzcc-heading__text">';
				echo '<h3 class="bzcc-heading__title">' . $label . '</h3>';
				if ( $description ) {
					echo '<p class="bzcc-heading__desc">' . $description . '</p>';
				}
				echo '</div>';
				if ( $badge ) {
					echo '<span class="bzcc-heading__badge">' . $badge . '</span>';
				}
				echo '</div>';
				return;

			case 'collapsible':
				$collapsed = ! empty( $field['collapsed_default'] );
				$state     = $collapsed ? 'collapsed' : 'expanded';
				echo '<div class="bzcc-collapsible bzcc-field--full" data-state="' . $state . '">';
				echo '<button type="button" class="bzcc-collapsible__header">';
				echo '<div class="bzcc-collapsible__icon">▶</div>';
				echo '<div class="bzcc-collapsible__info">';
				echo '<span class="bzcc-collapsible__title">' . $label . '</span>';
				if ( $description ) {
					echo '<span class="bzcc-collapsible__desc">' . $description . '</span>';
				}
				echo '</div>';
				if ( $badge ) {
					echo '<span class="bzcc-collapsible__badge">' . $badge . '</span>';
				}
				echo '<svg class="bzcc-collapsible__chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
				echo '</button>';
				echo '<div class="bzcc-collapsible__body"' . ( $collapsed ? ' style="display:none;"' : '' ) . '>';
				// Fields inside collapsible will be rendered by the parent loop
				// We use a marker div that JS will manage
				echo '</div></div>';
				return;

			case 'tab_group':
				$tabs = $field['tabs'] ?? [];
				if ( empty( $tabs ) ) return;
				echo '<div class="bzcc-tab-group bzcc-field--full" data-slug="' . $slug . '">';
				echo '<div class="bzcc-tab-group__nav">';
				foreach ( $tabs as $ti => $tab ) {
					$active = $ti === 0 ? ' bzcc-tab-group__tab--active' : '';
					echo '<button type="button" class="bzcc-tab-group__tab' . $active . '" data-tab-index="' . $ti . '">';
					if ( ! empty( $tab['icon'] ) ) {
						echo '<span class="bzcc-tab-group__icon">' . esc_html( $tab['icon'] ) . '</span>';
					}
					echo esc_html( $tab['label'] ?? 'Tab ' . ( $ti + 1 ) );
					echo '</button>';
				}
				echo '</div>';
				// Tab panes rendered by JS grouping
				foreach ( $tabs as $ti => $tab ) {
					$hidden = $ti > 0 ? ' style="display:none;"' : '';
					echo '<div class="bzcc-tab-group__pane" data-tab-index="' . $ti . '"' . $hidden . '></div>';
				}
				echo '</div>';
				return;

			case 'button_group':
				$is_multi     = $field['multi'] ?? true;
				$checked_vals = is_array( $value ) ? $value : ( $value ? [ $value ] : [] );
				$itype        = $is_multi ? 'checkbox' : 'radio';
				$iname        = $is_multi ? $slug . '[]' : $slug;

				echo '<div class="bzcc-field ' . $grid_class . '">';
				echo '<label class="bzcc-label">' . $label . $req_star . '</label>';
				if ( $description ) {
					echo '<p class="bzcc-field-desc">' . $description . '</p>';
				}
				echo '<div class="bzcc-button-group">';
				foreach ( $options as $opt ) {
					$v   = esc_attr( $opt['value'] ?? '' );
					$chk = in_array( $opt['value'] ?? '', $checked_vals, true ) ? 'checked' : '';
					$sel = $chk ? ' bzcc-pill--selected' : '';
					echo '<label class="bzcc-pill' . $sel . '">';
					echo '<input type="' . $itype . '" name="' . $iname . '" value="' . $v . '" ' . $chk . ' style="display:none;">';
					echo '<span class="bzcc-pill__label">' . esc_html( $opt['label'] ?? '' ) . '</span>';
					echo '</label>';
				}
				echo '</div>';
				echo '</div>';
				return;

			case 'checkbox_grid':
				$columns      = intval( $field['columns'] ?? 3 );
				$checked_vals = is_array( $value ) ? $value : [];

				echo '<div class="bzcc-field ' . $grid_class . '">';
				echo '<label class="bzcc-label">' . $label . $req_star . '</label>';
				if ( $description ) {
					echo '<p class="bzcc-field-desc">' . $description . '</p>';
				}
				echo '<div class="bzcc-checkbox-grid" style="grid-template-columns: repeat(' . $columns . ', 1fr);">';
				foreach ( $options as $opt ) {
					$v   = esc_attr( $opt['value'] ?? '' );
					$chk = in_array( $opt['value'] ?? '', $checked_vals, true ) ? 'checked' : '';
					$sel = $chk ? ' bzcc-grid-check--selected' : '';
					echo '<label class="bzcc-grid-check' . $sel . '">';
					echo '<input type="checkbox" name="' . $slug . '[]" value="' . $v . '" ' . $chk . '>';
					echo '<span class="bzcc-grid-check__mark"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></span>';
					echo '<span class="bzcc-grid-check__label">' . esc_html( $opt['label'] ?? '' ) . '</span>';
					echo '</label>';
				}
				echo '</div>';
				echo '</div>';
				return;
		}

		// ── Standard input field types ──
		echo '<div class="bzcc-field ' . $grid_class . '">';
		echo '<label class="bzcc-label" for="bzcc_' . $slug . '">' . $label . $req_star . '</label>';

		switch ( $type ) {
			case 'textarea':
				echo '<textarea id="bzcc_' . $slug . '" name="' . $slug . '" class="bzcc-textarea" placeholder="' . $placeholder . '" rows="4" ' . $req_attr . '>' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'select':
				echo '<select id="bzcc_' . $slug . '" name="' . $slug . '" class="bzcc-select" ' . $req_attr . '>';
				echo '<option value="">— Chọn —</option>';
				foreach ( $options as $opt ) {
					$sel = selected( $value, $opt['value'] ?? '', false );
					echo '<option value="' . esc_attr( $opt['value'] ?? '' ) . '" ' . $sel . '>' . esc_html( $opt['label'] ?? '' ) . '</option>';
				}
				echo '</select>';
				break;

			case 'radio':
				echo '<div class="bzcc-radio-group">';
				foreach ( $options as $opt ) {
					$v   = esc_attr( $opt['value'] ?? '' );
					$chk = checked( $value, $opt['value'] ?? '', false );
					echo '<label class="bzcc-radio-item">';
					echo '<input type="radio" name="' . $slug . '" value="' . $v . '" ' . $chk . '>';
					echo '<span class="bzcc-radio-label">' . esc_html( $opt['label'] ?? '' ) . '</span>';
					echo '</label>';
				}
				echo '</div>';
				break;

			case 'checkbox':
				echo '<div class="bzcc-checkbox-group">';
				$checked_vals = is_array( $value ) ? $value : [];
				foreach ( $options as $opt ) {
					$v   = esc_attr( $opt['value'] ?? '' );
					$chk = in_array( $opt['value'] ?? '', $checked_vals, true ) ? 'checked' : '';
					echo '<label class="bzcc-checkbox-item">';
					echo '<input type="checkbox" name="' . $slug . '[]" value="' . $v . '" ' . $chk . '>';
					echo '<span class="bzcc-checkbox-label">' . esc_html( $opt['label'] ?? '' ) . '</span>';
					echo '</label>';
				}
				echo '</div>';
				break;

			case 'rating':
				$val_int = intval( $value );
				echo '<div class="bzcc-rating" data-name="' . $slug . '" data-max="' . $max . '">';
				for ( $s = 1; $s <= $max; $s++ ) {
					$active = $s <= $val_int ? ' bzcc-star--active' : '';
					echo '<span class="bzcc-star' . $active . '" data-value="' . $s . '">★</span>';
				}
				echo '<input type="hidden" name="' . $slug . '" value="' . esc_attr( $value ) . '" ' . $req_attr . '>';
				echo '</div>';
				break;

			case 'scale':
				$val_int = intval( $value );
				echo '<div class="bzcc-scale">';
				if ( $min_label ) echo '<span class="bzcc-scale-label">' . $min_label . '</span>';
				echo '<div class="bzcc-scale-options">';
				for ( $s = $min; $s <= $max; $s++ ) {
					$chk = $s === $val_int ? 'checked' : '';
					echo '<label class="bzcc-scale-item">';
					echo '<input type="radio" name="' . $slug . '" value="' . $s . '" ' . $chk . ' ' . $req_attr . '>';
					echo '<span>' . $s . '</span>';
					echo '</label>';
				}
				echo '</div>';
				if ( $max_label ) echo '<span class="bzcc-scale-label">' . $max_label . '</span>';
				echo '</div>';
				break;

			case 'range':
				$val_num = $value !== '' ? intval( $value ) : intval( ( $min + $max ) / 2 );
				echo '<div class="bzcc-range">';
				if ( $min_label ) echo '<span class="bzcc-range-label">' . $min_label . '</span>';
				echo '<input type="range" id="bzcc_' . $slug . '" name="' . $slug . '" min="' . $min . '" max="' . $max . '" value="' . $val_num . '" class="bzcc-range-input">';
				echo '<output class="bzcc-range-output">' . $val_num . '</output>';
				if ( $max_label ) echo '<span class="bzcc-range-label">' . $max_label . '</span>';
				echo '</div>';
				break;

			case 'toggle':
				$chk = $value ? 'checked' : '';
				echo '<label class="bzcc-toggle">';
				echo '<input type="checkbox" name="' . $slug . '" value="1" ' . $chk . '>';
				echo '<span class="bzcc-toggle-slider"></span>';
				echo '<span class="bzcc-toggle-text">' . ( $placeholder ?: 'Có / Không' ) . '</span>';
				echo '</label>';
				break;

			case 'image':
				echo '<div class="bzcc-image-upload">';
				echo '<input type="file" id="bzcc_' . $slug . '" name="' . $slug . '" accept="image/*" class="bzcc-file-input" ' . $req_attr . '>';
				echo '<label for="bzcc_' . $slug . '" class="bzcc-file-label">🖼️ Chọn ảnh</label>';
				echo '</div>';
				break;

			case 'number':
				echo '<input type="number" id="bzcc_' . $slug . '" name="' . $slug . '" class="bzcc-input" placeholder="' . $placeholder . '" value="' . esc_attr( $value ) . '" ' . $req_attr . '>';
				break;

			case 'card_checkbox':
			case 'card_radio':
				$is_multi = $type === 'card_checkbox';
				$multi_class = $is_multi ? ' bzcc-card-options--multi' : '';
				$checked_vals = is_array( $value ) ? $value : ( $value ? [ $value ] : [] );
				echo '<div class="bzcc-card-options' . $multi_class . '">';
				foreach ( $options as $opt ) {
					$v      = esc_attr( $opt['value'] ?? '' );
					$olabel = esc_html( $opt['label'] ?? '' );
					$odesc  = esc_html( $opt['description'] ?? '' );
					$oicon  = $opt['icon'] ?? '';
					$otag   = esc_html( $opt['tag'] ?? '' );
					$chk    = in_array( $opt['value'] ?? '', $checked_vals, true ) ? 'checked' : '';
					$sel    = $chk ? ' bzcc-card-option--selected' : '';
					$itype  = $is_multi ? 'checkbox' : 'radio';
					$iname  = $is_multi ? $slug . '[]' : $slug;

					echo '<div class="bzcc-card-option' . $sel . '">';
					echo '<input type="' . $itype . '" name="' . $iname . '" value="' . $v . '" ' . $chk . ' style="display:none;">';
					if ( $oicon ) {
						echo '<div class="bzcc-card-option__icon">' . esc_html( $oicon ) . '</div>';
					}
					echo '<div class="bzcc-card-option__text">';
					echo '<strong class="bzcc-card-option__title">' . $olabel . '</strong>';
					if ( $odesc ) echo '<p class="bzcc-card-option__desc">' . $odesc . '</p>';
					if ( $otag ) echo '<span class="bzcc-card-option__tag">' . $otag . '</span>';
					echo '</div>';
					echo '<div class="bzcc-card-option__check"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></div>';
					echo '</div>';
				}
				echo '</div>';
				break;

			default: // text
				echo '<input type="text" id="bzcc_' . $slug . '" name="' . $slug . '" class="bzcc-input" placeholder="' . $placeholder . '" value="' . esc_attr( $value ) . '" ' . $req_attr . '>';
				break;
		}

		echo '</div>';
	}

	/* ══════════════════════════════════════════════
	 *  AJAX: Form Submission
	 * ══════════════════════════════════════════════ */

	public static function ajax_submit_form(): void {
		check_ajax_referer( 'bzcc_frontend', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Bạn cần đăng nhập để sử dụng.' ] );
		}

		$template_id = absint( $_POST['template_id'] ?? 0 );
		$form_data   = json_decode( wp_unslash( $_POST['form_data'] ?? '{}' ), true );

		if ( ! $template_id || ! is_array( $form_data ) ) {
			wp_send_json_error( [ 'message' => 'Dữ liệu không hợp lệ.' ] );
		}

		$template = BZCC_Template_Manager::get_by_id( $template_id );
		if ( ! $template || $template->status !== 'active' ) {
			wp_send_json_error( [ 'message' => 'Template không tồn tại.' ] );
		}

		// Sanitize form_data values
		$clean = [];
		foreach ( $form_data as $key => $val ) {
			$key = sanitize_key( $key );
			if ( $key === 'template_id' ) continue;
			if ( is_array( $val ) ) {
				$clean[ $key ] = array_map( 'sanitize_text_field', $val );
			} else {
				$clean[ $key ] = sanitize_textarea_field( $val );
			}
		}

		// Create file record
		$file_id = BZCC_File_Manager::insert( [
			'user_id'     => $user_id,
			'template_id' => $template_id,
			'form_data'   => wp_json_encode( $clean ),
			'title'       => $template->title . ' — ' . wp_date( 'd/m/Y H:i' ),
			'status'      => 'pending',
		] );

		if ( ! $file_id ) {
			wp_send_json_error( [ 'message' => 'Không thể tạo file, vui lòng thử lại.' ] );
		}

		// Increment template use count
		BZCC_Template_Manager::increment_use_count( $template_id );

		wp_send_json_success( [
			'file_id'  => $file_id,
			'redirect' => home_url( 'creator/result/' . $file_id . '/' ),
			'message'  => 'Đã tạo thành công! AI đang xử lý nội dung cho bạn.',
		] );
	}

	/* ══════════════════════════════════════════════
	 *  AJAX: Upload image to WP Media Library
	 * ══════════════════════════════════════════════ */
	public static function ajax_upload_image() {
		check_ajax_referer( 'bzcc_frontend', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => 'Bạn cần đăng nhập.' ] );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( [ 'message' => 'Không có file nào được gửi.' ] );
		}

		// Validate file type
		$allowed = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
		if ( ! in_array( $_FILES['file']['type'], $allowed, true ) ) {
			wp_send_json_error( [ 'message' => 'Chỉ cho phép upload ảnh (JPEG, PNG, GIF, WebP).' ] );
		}

		// Validate file size (max 10MB)
		if ( $_FILES['file']['size'] > 10 * 1024 * 1024 ) {
			wp_send_json_error( [ 'message' => 'Ảnh không được vượt quá 10MB.' ] );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
		}

		$url = wp_get_attachment_url( $attachment_id );

		wp_send_json_success( [
			'id'  => $attachment_id,
			'url' => $url,
		] );
	}
}
