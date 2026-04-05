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

	/** @var string User meta key storing the dismiss timestamp. */
	private const DISMISS_META = 'bizcity_support_link_dismissed';

	/** @var int Seconds before the widget reappears after dismiss (3 days). */
	private const DISMISS_TTL = 3 * DAY_IN_SECONDS;

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'wp_ajax_bizcity_dismiss_support_link', [ __CLASS__, 'ajax_dismiss' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_footer', [ __CLASS__, 'render' ], 100 );
	}

	/**
	 * AJAX handler — store dismiss timestamp in user meta.
	 */
	public static function ajax_dismiss(): void {
		check_ajax_referer( 'bizcity_dismiss_support', '_nonce' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, time() );
		wp_send_json_success();
	}

	/**
	 * Whether the current user dismissed the widget and it's still within the cooldown window.
	 */
	private static function is_dismissed(): bool {
		$dismissed_at = (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
		if ( ! $dismissed_at ) {
			return false;
		}
		return ( time() - $dismissed_at ) < self::DISMISS_TTL;
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! self::should_render( $hook ) || self::is_dismissed() ) {
			return;
		}

		wp_register_style( 'bizcity-admin-support-link', false, [], BIZCITY_TWIN_AI_VERSION );
		wp_enqueue_style( 'bizcity-admin-support-link' );
		wp_add_inline_style( 'bizcity-admin-support-link', self::get_styles() );
	}

	public static function render(): void {
		if ( ! self::should_render() || self::is_dismissed() ) {
			return;
		}

		$nonce = wp_create_nonce( 'bizcity_dismiss_support' );

		echo '<div class="bizcity-admin-support-link" id="bizcity-support-link">';
		echo '<button class="bizcity-admin-support-link__close" id="bizcity-support-close" type="button" aria-label="Dismiss">&times;</button>';
		echo '<a class="bizcity-admin-support-link__anchor" href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">';
		echo '<span class="bizcity-admin-support-link__icon" aria-hidden="true">☕</span>';
		echo '<span class="bizcity-admin-support-link__text">';
		echo '<strong>Buy Me a Coffee</strong>';
		echo '<span>Support Johnny Chu</span>';
		echo '</span>';
		echo '</a>';
		echo '</div>';
		echo '<script>';
		echo 'document.getElementById("bizcity-support-close").addEventListener("click",function(e){';
		echo 'e.preventDefault();';
		echo 'document.getElementById("bizcity-support-link").style.display="none";';
		echo 'fetch(ajaxurl+"?action=bizcity_dismiss_support_link&_nonce=' . esc_js( $nonce ) . '");';
		echo '});';
		echo '</script>';
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
	bottom: 32px;
	right: 0;
	z-index: 9999;
	pointer-events: none;
}

.bizcity-admin-support-link__close {
	position: absolute;
	top: -8px;
	left: -8px;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	border: 2px solid #fff;
	background: #1d2327;
	color: #fff;
	font-size: 14px;
	line-height: 1;
	cursor: pointer;
	pointer-events: auto;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	transition: background 0.15s;
	z-index: 1;
}

.bizcity-admin-support-link__close:hover {
	background: #d63638;
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