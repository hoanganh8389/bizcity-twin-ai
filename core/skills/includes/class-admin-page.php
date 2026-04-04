<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skills — Admin Page
 *
 * Registers submenu under Knowledge + enqueues the Vite-built React SPA.
 *
 * @package  BizCity_Skills
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Admin_Page {

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 20 ); // after knowledge
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu(): void {
		$td = 'bizcity-twin-ai';

		add_submenu_page(
			'bizcity-knowledge',
			__( 'Skill Library', $td ),
			'⚡ ' . __( 'Skill Library', $td ),
			'manage_options',
			'bizcity-skills',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		echo '<div class="wrap" style="margin:0;padding:0;">';
		echo '<div id="skill-app" style="min-height:calc(100vh - 32px);"></div>';
		echo '</div>';
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'bizcity-skills' ) === false ) {
			return;
		}

		$dist = BIZCITY_SKILLS_DIR . 'assets/dist/';
		$url  = plugins_url( 'assets/dist/', BIZCITY_SKILLS_DIR . 'bootstrap.php' );

		// Main CSS (skill-app.css — our custom styles + RFM)
		$css_path = $dist . 'skill-app.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'bizcity-skill-app',
				$url . 'skill-app.css',
				[],
				(string) filemtime( $css_path )
			);
		}

		// Main JS entry (skill-app.js — lazy chunks loaded automatically)
		$js_path = $dist . 'skill-app.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'bizcity-skill-app',
				$url . 'skill-app.js',
				[],
				(string) filemtime( $js_path ),
				true
			);
		}

		// Pass config to React app
		$config = [
			'restBase'     => esc_url_raw( rest_url( 'bizcity-skill/v1' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'toolsCatalog' => $this->build_tools_catalog(),
		];
		wp_add_inline_script( 'bizcity-skill-app', 'window.skillAppConfig = ' . wp_json_encode( $config ) . ';', 'before' );

		// Dequeue WooCommerce & other unnecessary scripts on our page
		wp_dequeue_script( 'wc-settings' );
		wp_dequeue_script( 'wc-entities' );
		wp_dequeue_script( 'svg-painter' );
		wp_dequeue_style( 'woocommerce_admin_styles' );
	}

	/**
	 * Build tools catalog for the Slash Dialog in the Skill editor.
	 * Reuses BizCity_Intent_Tool_Index to get all active tools, grouped by plugin.
	 *
	 * @return array{totalTools: int, groups: array}
	 */
	private function build_tools_catalog(): array {
		if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
			return [ 'totalTools' => 0, 'groups' => [] ];
		}

		$all_tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
		if ( empty( $all_tools ) ) {
			return [ 'totalTools' => 0, 'groups' => [] ];
		}

		// Only show content atomic tools (accepts_skill = 1) in the Skill editor
		$all_tools = array_filter( $all_tools, static function ( $tool ) {
			return ! empty( $tool['accepts_skill'] );
		} );
		if ( empty( $all_tools ) ) {
			return [ 'totalTools' => 0, 'groups' => [] ];
		}

		// Group by plugin
		$grouped = [];
		foreach ( $all_tools as $tool ) {
			$plugin = $tool['plugin'] ?: 'other';
			$grouped[ $plugin ][] = $tool;
		}

		// Gradient palette
		$palette = [
			['#059669','#34D399'], ['#4F46E5','#818CF8'],
			['#2563EB','#60A5FA'], ['#7C3AED','#A78BFA'],
			['#DB2777','#F472B6'], ['#D97706','#FBBF24'],
			['#DC2626','#F87171'], ['#0891B2','#22D3EE'],
			['#9333EA','#C084FC'], ['#1D4ED8','#3B82F6'],
			['#EA580C','#FB923C'], ['#16A34A','#4ADE80'],
		];
		$palette_count = count( $palette );

		$groups_out = [];
		foreach ( $grouped as $plugin_id => $tools ) {
			$idx = abs( crc32( $plugin_id ) ) % $palette_count;
			$gradient = "linear-gradient(135deg, {$palette[$idx][0]}, {$palette[$idx][1]})";
			$name = ucfirst( str_replace( ['-','_'], ' ', $plugin_id ) );

			$tools_out = [];
			foreach ( $tools as $t ) {
				$label = $t['goal_label'] ?? '';
				if ( ! $label || preg_match( '/^[a-z0-9_]+$/i', $label ) ) {
					$label = $t['title'] ?? '';
					if ( ! $label || preg_match( '/^[a-z0-9_]+$/i', $label ) ) {
						$label = $t['goal_description'] ?? '';
						if ( $label ) {
							$label = mb_strimwidth( $label, 0, 50, '…', 'UTF-8' );
						} else {
							$label = ucfirst( str_replace( '_', ' ', $t['tool_name'] ?? 'Tool' ) );
						}
					}
				}

				$slots = [];
				$req_json = $t['required_slots'] ?? '';
				if ( $req_json && $req_json !== '[]' ) {
					$decoded = json_decode( $req_json, true );
					if ( is_array( $decoded ) ) {
						foreach ( $decoded as $k => $v ) {
							if ( ! is_numeric( $k ) ) {
								$slots[] = str_replace( '_', ' ', $k );
							}
						}
					}
				}

				$tools_out[] = [
					'toolName' => $t['tool_name'] ?? '',
					'label'    => $label,
					'desc'     => $t['goal_description'] ?? '',
					'slots'    => $slots,
				];
			}

			$groups_out[] = [
				'plugin'    => $plugin_id,
				'name'      => $name,
				'gradient'  => $gradient,
				'toolCount' => count( $tools ),
				'tools'     => $tools_out,
			];
		}

		return [
			'totalTools' => count( $all_tools ),
			'groups'     => $groups_out,
		];
	}

}
