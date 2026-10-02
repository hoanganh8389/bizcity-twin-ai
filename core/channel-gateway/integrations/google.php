<?php
/**
 * Integration: Google Workspace — the Google connection in integrations/google/ ([2026-09-30 Claude Opus 5.5] CORE-REDUCTION WP-16 B-3b-G; was the bundled plugin bizgpt-tool-google).
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
	protected string $desc     = 'Gmail, Calendar, Drive, Contacts (kết nối Google của Channel Gateway)';
	protected int    $order    = 5;

	public function get_settings(): array {
		$manage_url = admin_url( 'admin.php?page=bizgpt-tool-google' );
		return [
			'_info' => [
				'type'    => 'html',
				'label'   => 'Trạng thái',
				'content' => $this->is_plugin_active()
					? '✅ Kết nối <strong>Google</strong> đang hoạt động. <a href="' . esc_url( $manage_url ) . '" target="_blank">Quản lý →</a>'
					: '⚠️ Kết nối <strong>Google</strong> chưa được nạp (core/channel-gateway/integrations/google).',
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
