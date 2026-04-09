<?php
/**
 * Integration: Google Workspace — Bundled bizgpt-tool-google bridge.
 *
 * Bridges the existing bizgpt-tool-google plugin into the integration registry
 * so its OAuth status appears in the unified Gateway → Tích hợp tab.
 *
 * @package BizCity_Twin_AI
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Google extends BizCity_Integration {

	protected string $code     = 'google_workspace';
	protected string $category = 'other';
	protected string $logo     = 'GW';
	protected string $name     = 'Google Workspace';
	protected string $desc     = 'Gmail, Calendar, Drive, Contacts (via bizgpt-tool-google)';
	protected int    $order    = 5;

	public function get_settings(): array {
		$manage_url = admin_url( 'admin.php?page=bizgpt-tool-google' );
		return [
			'_info' => [
				'type'    => 'html',
				'label'   => 'Trạng thái',
				'content' => $this->is_plugin_active()
					? '✅ Plugin <strong>bizgpt-tool-google</strong> đang hoạt động. <a href="' . esc_url( $manage_url ) . '" target="_blank">Quản lý →</a>'
					: '⚠️ Plugin <strong>bizgpt-tool-google</strong> chưa hoạt động. Kích hoạt trong bizcity-twin-ai bundled plugins.',
			],
		];
	}

	public function do_test(): void {
		if ( $this->is_plugin_active() ) {
			$this->account['_status']       = 1;
			$this->account['_status_error'] = '';
		} else {
			$this->account['_status']       = 0;
			$this->account['_status_error'] = 'Plugin chưa active';
		}
	}

	private function is_plugin_active(): bool {
		return defined( 'BZGOOGLE_VERSION' ) && class_exists( 'BZGoogle_Google_Service' );
	}
}
