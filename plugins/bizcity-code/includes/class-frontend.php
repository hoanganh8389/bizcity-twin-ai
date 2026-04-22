<?php
/**
 * Frontend — registers template page at /tool-code/.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Frontend {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'template_redirect' ] );

		/* ── Published full-page template ── */
		add_filter( 'theme_page_templates', [ __CLASS__, 'register_fullpage_template' ] );
		add_filter( 'template_include', [ __CLASS__, 'load_fullpage_template' ] );
	}

	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^tool-code/?$',
			'index.php?bzcode_page=editor',
			'top'
		);
		add_rewrite_rule(
			'^tool-code/preview/([0-9]+)/?$',
			'index.php?bzcode_page=preview&bzcode_variant_id=$matches[1]',
			'top'
		);
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'bzcode_page';
		$vars[] = 'bzcode_variant_id';
		return $vars;
	}

	public static function template_redirect(): void {
		$page = get_query_var( 'bzcode_page' );
		if ( ! $page ) {
			return;
		}

		if ( $page === 'preview' ) {
			self::render_preview();
			exit;
		}

		if ( ! is_user_logged_in() ) {
			wp_redirect( wp_login_url( home_url( '/tool-code/' ) ) );
			exit;
		}

		self::render_editor();
		exit;
	}

	private static function render_editor(): void {
		// Read ?id= from query string (like bizcity-doc pattern)
		$project_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		/* ── Hide WP admin bar + float widgets on standalone tool page ── */
		show_admin_bar( false );
		add_action( 'wp_head', function () {
			echo '<style>#wpadminbar,#nobi-fe-float-btn,#bizchat-float-btn{display:none!important}</style>';
		}, 999 );

		wp_enqueue_style( 'bztwin-sources-widget', BZCODE_URL . 'assets/bztwin-sources-widget.css', [], BZCODE_VERSION );
		wp_enqueue_style( 'bzcode-editor', BZCODE_URL . 'assets/editor.css', [ 'bztwin-sources-widget' ], BZCODE_VERSION );
		wp_enqueue_script( 'bztwin-sources-widget', BZCODE_URL . 'assets/bztwin-sources-widget.js', [], BZCODE_VERSION, true );
		wp_enqueue_script( 'bzcode-editor', BZCODE_URL . 'assets/editor.js', [ 'bztwin-sources-widget' ], BZCODE_VERSION, true );

		wp_localize_script( 'bzcode-editor', 'bzcode_config', [
			'rest_url'    => rest_url( 'bzcode/v1' ),
			'ajax_url'    => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'sse_nonce'   => wp_create_nonce( 'bzcode_sse' ),
			'project_id'  => $project_id,
			'user_id'     => get_current_user_id(),
			'stacks'      => BZCode_Engine::STACKS,
			'home_url'    => home_url( '/tool-code/' ),
		] );

		include BZCODE_DIR . 'views/page-editor.php';
	}

	private static function render_preview(): void {
		$variant_id = (int) get_query_var( 'bzcode_variant_id', 0 );
		$variant    = BZCode_Variant_Manager::get_by_id( $variant_id );

		// Verify ownership: variant → page → project → user
		if ( $variant ) {
			$page = BZCode_Page_Manager::get_by_id( (int) $variant->page_id );
			if ( $page ) {
				$project = BZCode_Project_Manager::get_by_id( (int) $page->project_id );
				if ( ! $project || (int) $project->user_id !== get_current_user_id() ) {
					$variant = null;
				}
			} else {
				$variant = null;
			}
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		header( "Content-Security-Policy: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; frame-ancestors 'self'" );
		header( 'X-Content-Type-Options: nosniff' );

		if ( ! $variant || empty( $variant->code ) ) {
			echo '<html><body><p style="padding:40px;font-family:sans-serif;color:#888;">No preview available.</p></body></html>';
		} else {
			echo $variant->code;
		}
		exit;
	}

	/* ── Fullpage template (used by publish-page endpoint) ── */

	public static function register_fullpage_template( array $templates ): array {
		$templates['bzcode-fullpage'] = 'BizCity Code — Full Page';
		return $templates;
	}

	public static function load_fullpage_template( string $template ): string {
		if ( is_singular( 'page' ) ) {
			$tpl = get_post_meta( get_the_ID(), '_wp_page_template', true );
			if ( 'bzcode-fullpage' === $tpl ) {
				$html = get_post_meta( get_the_ID(), '_bzcode_page_html', true );
				if ( $html ) {
					// Serve raw HTML — this is admin-published AI-generated content.
					header( 'Content-Type: text/html; charset=utf-8' );
					header( 'X-Content-Type-Options: nosniff' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $html;
					exit;
				}
			}
		}
		return $template;
	}
}
