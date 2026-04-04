<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Shared Admin Support Link — floating Buy Me a Coffee CTA for BizCity admin pages.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Admin_Support_Link {

	private const SUPPORT_URL = 'http://buymeacoffee.com/chuhoanganh';

	/**
	 * Admin page markers that belong to the BizCity Twin AI ecosystem.
	 *
	 * @var string[]
	 */
	private static array $page_markers = [
		'bizcity-',
		'bizchat-',
		'bizgpt-',
		'bizcoach-',
		'bccm_',
		'bccm-',
		'bzgk',
		'bzck',
		'bzcalo',
	];

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ __CLASS__, 'render' ], 100 );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! self::should_render( $hook ) ) {
			return;
		}

		wp_register_style( 'bizcity-admin-support-link', false, [], BIZCITY_TWIN_AI_VERSION );
		wp_enqueue_style( 'bizcity-admin-support-link' );
		wp_add_inline_style( 'bizcity-admin-support-link', self::get_styles() );
	}

	public static function render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		echo '<div class="bizcity-admin-support-link">';
		echo '<a class="bizcity-admin-support-link__anchor" href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">';
		echo '<span class="bizcity-admin-support-link__icon" aria-hidden="true">☕</span>';
		echo '<span class="bizcity-admin-support-link__text">';
		echo '<strong>Buy Me a Coffee</strong>';
		echo '<span>Support Johnny Chu</span>';
		echo '</span>';
		echo '</a>';
		echo '</div>';
	}

	private static function should_render( string $hook = '' ): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = ( $screen && ! empty( $screen->id ) ) ? (string) $screen->id : '';

		$haystacks = array_filter( [ $page, $hook, $screen_id ] );
		if ( empty( $haystacks ) ) {
			return false;
		}

		foreach ( $haystacks as $value ) {
			foreach ( self::$page_markers as $marker ) {
				if ( strpos( $value, $marker ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function get_styles(): string {
		return <<<'CSS'
.bizcity-admin-support-link {
	position: fixed;
	top: 92px;
	right: 0;
	z-index: 9999;
	pointer-events: none;
}

.bizcity-admin-support-link__anchor {
	display: inline-flex;
	align-items: center;
	gap: 10px;
	padding: 12px 16px 12px 14px;
	border-radius: 14px 0 0 14px;
	background: linear-gradient(135deg, #ffd54f 0%, #ff9f43 100%);
	box-shadow: 0 10px 24px rgba(17, 24, 39, 0.18);
	color: #1d2327;
	text-decoration: none;
	pointer-events: auto;
	transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.bizcity-admin-support-link__anchor:hover,
.bizcity-admin-support-link__anchor:focus {
	transform: translateX(-4px);
	box-shadow: 0 14px 28px rgba(17, 24, 39, 0.24);
	color: #1d2327;
	outline: none;
}

.bizcity-admin-support-link__icon {
	width: 38px;
	height: 38px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: 999px;
	background: rgba(255, 255, 255, 0.7);
	font-size: 20px;
	flex: 0 0 auto;
}

.bizcity-admin-support-link__text {
	display: flex;
	flex-direction: column;
	line-height: 1.15;
	font-size: 12px;
	white-space: nowrap;
}

.bizcity-admin-support-link__text strong {
	font-size: 13px;
	font-weight: 700;
	margin-bottom: 2px;
}

@media screen and (max-width: 960px) {
	.bizcity-admin-support-link {
		top: auto;
		bottom: 20px;
	}

	.bizcity-admin-support-link__anchor {
		border-radius: 999px;
		margin-right: 16px;
	}

	.bizcity-admin-support-link__text span {
		display: none;
	}
}
CSS;
	}
}