<?php
/**
 * BizCity Zalo Hotline — Workflow Integration (PHASE 0.31 T-S2.2)
 *
 * Registers Zalo Hotline (ZNS) as a first-class channel via the discovery
 * filter. Outbound is template-based (ZNS); inbound is shared with the
 * Zalo Bot pipeline. See `class-channel-adapter.php` for the live runtime.
 *
 * @package BizCity\AdminHookZalo
 * @since   PHASE 0.31 Sprint 2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'bizcity_register_channel_integrations', function ( $list ) {
	$list['zalo_hotline'] = array(
		'class' => 'WaicChannelIntegration_zalo_hotline',
		'file'  => __FILE__,
	);
	return $list;
} );

if ( ! class_exists( 'WaicChannelIntegration' ) ) {
	return;
}

class WaicChannelIntegration_zalo_hotline extends WaicChannelIntegration {

	protected $_code     = 'zalo_hotline';
	protected $_logo     = 'ZH';
	protected $_order    = 30;
	protected $_platform = 'ZALO_HOTLINE';
	protected $_prefix   = 'hotline_';

	public function __construct( $integration = false ) {
		$this->_name = 'Zalo Hotline (ZNS)';
		$this->_desc = __( 'Send Zalo Notification Service (ZNS) template messages via the channel-gateway.', 'ai-copilot-content-generator' );
		// PHASE 0.31 Sprint 6 follow-up — placeholder shortcut to native settings
		// page. Frontend slug `/tool-zalo-bizcity/` sẽ được tạo ở Bước 1 (clone
		// pattern từ bizcity-tool-facebook). Khi chưa có route, link 404 → user
		// quay về dialog. Khi route ready, không cần đổi gì ở đây nữa.
		$this->_config_url = home_url( '/tool-zalo-bizcity/?tab=settings' );
		$this->setIntegration( $integration );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$this->_settings = array(
			'name' => array(
				'type'    => 'input',
				'label'   => __( 'Profile name', 'ai-copilot-content-generator' ),
				'default' => '',
			),
			'app_id' => array(
				'type'    => 'input',
				'label'   => __( 'Zalo App ID', 'ai-copilot-content-generator' ),
				'plh'     => __( 'ZNS app id (numeric)', 'ai-copilot-content-generator' ),
				'default' => '',
			),
			'access_token' => array(
				'type'    => 'input',
				'label'   => __( 'ZNS access token', 'ai-copilot-content-generator' ),
				'plh'     => __( 'OA access token (rotated by ZNS)', 'ai-copilot-content-generator' ),
				'default' => '',
				'encrypt' => true,
			),
			'default_template_id' => array(
				'type'    => 'input',
				'label'   => __( 'Default template ID', 'ai-copilot-content-generator' ),
				'plh'     => __( 'Used when block does not specify one', 'ai-copilot-content-generator' ),
				'default' => '',
			),
			'phone_default' => array(
				'type'    => 'input',
				'label'   => __( 'Default sender phone', 'ai-copilot-content-generator' ),
				'plh'     => '0562608899',
				'default' => '',
			),
		);
	}

	public function doTest( $need = false ) {
		$params = $this->getParams();
		if ( ! $need && ! empty( $params['_status'] ) && (int) $params['_status'] === 1 ) {
			return true;
		}

		$token = (string) $this->getParam( 'access_token' );
		if ( $token === '' ) {
			$this->addParam( '_status', 4 );
			$this->addParam( '_status_error', 'access_token empty — paste OA access token from ZNS console.' );
			return false;
		}
		if ( ! class_exists( 'BizCity_Zalo_Hotline_Channel_Adapter' ) ) {
			$this->addParam( '_status', 4 );
			$this->addParam( '_status_error', 'Channel adapter class missing (mu-plugin not loaded).' );
			return false;
		}

		$res = BizCity_Zalo_Hotline_Channel_Adapter::verify_credentials( $token );
		if ( ! empty( $res['ok'] ) ) {
			$this->addParam( '_status', 1 );
			$this->addParam( '_status_error', '' );
			return true;
		}
		$this->addParam( '_status', 7 );
		$this->addParam( '_status_error', sprintf(
			'ZNS verify failed (http=%d): %s',
			(int) ( $res['status'] ?? 0 ),
			(string) ( $res['error'] ?? 'unknown' )
		) );
		return false;
	}

	public function getTriggerBlocks() {
		// Inbound shares the Zalo Bot trigger surface; no dedicated triggers yet.
		return array();
	}

	public function getActionBlocks() {
		return array(
			array( 'code' => 'wp_send_zalo' ),
		);
	}
}
