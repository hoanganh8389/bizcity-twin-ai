<?php
/**
 * WaicIntegration + WaicChannelIntegration — Standalone Compat Layer
 *
 * [2026-06-10 Johnny Chu] PHASE-0.31 T-S1.1 — Standalone abstract classes so
 * channel plugins can extend WaicChannelIntegration WITHOUT depending on the
 * archived bizcity-automation plugin.
 *
 * Design:
 *   WaicIntegration            — abstract base (mirrors WaicIntegration from archived plugin)
 *   WaicChannelIntegration     — abstract channel layer, extends WaicIntegration
 *
 * These classes are registered ONCE from core/channel-gateway/bootstrap.php
 * (before any channel plugin boots). Provider classes (e.g. WaicChannelIntegration_facebook
 * in plugins/bizcity-facebook-bot) extend WaicChannelIntegration.
 *
 * Guard: if the archived bizcity-automation plugin is already loaded and has
 * defined these classes, we skip redefinition to avoid fatal.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.31 T-S1.1 (2026-06-10)
 */

defined( 'ABSPATH' ) || exit;

/* -----------------------------------------------------------------------
 * WaicIntegration — abstract base
 * --------------------------------------------------------------------- */

if ( ! class_exists( 'WaicIntegration', false ) ) {

	/**
	 * Minimal abstract base for WAIC-style integrations.
	 *
	 * Only declares the interface contract used by channel gateway probes
	 * (T-S1.1) and WaicChannelIntegration subclasses. Full implementation
	 * lives in the archived bizcity-automation plugin; this stub is used
	 * on sites where that plugin is NOT loaded.
	 */
	abstract class WaicIntegration {

		/** @var string Integration code (machine-readable). */
		protected $_code     = '';

		/** @var string Category (e.g. 'channel'). */
		protected $_category = '';

		/** @var string Human-readable name. */
		protected $_name     = '';

		/** @var string Human-readable description. */
		protected $_desc     = '';

		/** @var string Logo identifier or URL. */
		protected $_logo     = '';

		/** @var int Sort order in the integrations hub. */
		protected $_order    = 50;

		/** @var array Settings schema. */
		protected $_settings = array();

		/** @var array|null Stored params (credentials, config). */
		protected $_params = null;

		/** @var mixed Integration account reference. */
		protected $_integration = false;

		/** @var string Optional config page URL. */
		protected $_config_url = '';

		/* --- Getters ------------------------------------------------- */

		public function getCode()     { return $this->_code; }
		public function getCategory() { return $this->_category; }
		public function getName()     { return $this->_name; }
		public function getDesc()     { return $this->_desc; }
		public function getLogo()     { return $this->_logo; }
		public function getOrder()    { return $this->_order; }

		public function setIntegration( $integration ) {
			$this->_integration = $integration;
		}

		/* --- Settings ----------------------------------------------- */

		public function getSettings() {
			return $this->_settings;
		}

		public function setSettings() {}

		/* --- Params storage (stub — real crypto in archived plugin) - */

		/**
		 * Set a param key.
		 *
		 * @param string $key
		 * @param mixed  $value
		 */
		public function addParam( $key, $value ) {
			if ( ! is_array( $this->_params ) ) {
				$this->_params = array();
			}
			$this->_params[ $key ] = $value;
		}

		/**
		 * Get stored params (plain array, no decryption in stub).
		 *
		 * @param bool $decrypt Ignored in stub.
		 * @return array
		 */
		public function getParams( $decrypt = false ) {
			return is_array( $this->_params ) ? $this->_params : array();
		}

		/**
		 * Get decrypted params (stub — same as getParams() here).
		 *
		 * @param bool $need Ignored.
		 * @return array
		 */
		public function getDecryptedParams( $need = true ) {
			return $this->getParams();
		}

		/* --- Connectivity test -------------------------------------- */

		/**
		 * Test integration connectivity.
		 *
		 * @param bool $need Force re-test.
		 * @return bool
		 */
		public function doTest( $need = false ) {
			return true;
		}
	}
}

/* -----------------------------------------------------------------------
 * WaicChannelIntegration — abstract channel layer
 * --------------------------------------------------------------------- */

if ( ! class_exists( 'WaicChannelIntegration', false ) ) {

	/**
	 * Abstract base for "Channel" integrations.
	 *
	 * Subclasses register via:
	 *   add_filter( 'bizcity_register_channel_integrations', function ( $list ) {
	 *       $list['facebook'] = [
	 *           'class' => 'WaicChannelIntegration_facebook',
	 *           'file'  => __DIR__ . '/integration-facebook.php',
	 *       ];
	 *       return $list;
	 *   } );
	 *
	 * Subclass MUST override $_platform (e.g. 'FACEBOOK') and $_prefix (e.g. 'fb_').
	 *
	 * @since PHASE 0.31 T-S1.1 (2026-06-10 standalone compat)
	 */
	abstract class WaicChannelIntegration extends WaicIntegration {

		/**
		 * Category is always 'channel'.
		 */
		protected $_category = 'channel';

		/**
		 * Platform code matching BizCity_Channel_Adapter::get_platform().
		 * E.g. 'FACEBOOK', 'ZALO_BOT', 'WEBCHAT'.
		 * Subclass MUST set.
		 */
		protected $_platform = '';

		/**
		 * Routing prefix matching BizCity_Channel_Adapter::get_prefix().
		 * E.g. 'fb_', 'zalo_', 'web_'.
		 * Subclass MUST set.
		 */
		protected $_prefix = '';

		/** @var BizCity_Channel_Adapter|false|null Cached adapter. */
		protected $_adapter = null;

		/* --- Getters ----------------------------------------------- */

		public function getPlatform() { return $this->_platform; }
		public function getPrefix()   { return $this->_prefix; }

		/* --- Adapter ----------------------------------------------- */

		/**
		 * Return the BizCity_Channel_Adapter for this channel.
		 * Looks up by platform in the gateway bridge registry.
		 *
		 * @return BizCity_Channel_Adapter|false
		 */
		public function getAdapter() {
			if ( null !== $this->_adapter ) {
				return $this->_adapter;
			}
			$this->_adapter = false;
			if ( $this->_platform !== '' && class_exists( 'BizCity_Gateway_Bridge', false ) ) {
				$bridge  = BizCity_Gateway_Bridge::instance();
				$adapter = $bridge->get_adapter( $this->_platform );
				if ( $adapter ) {
					$this->_adapter = $adapter;
				}
			}
			return $this->_adapter;
		}

		/* --- Workflow block declarations (for automation builder) --- */

		/**
		 * Trigger blocks contributed by this channel (inbound events).
		 *
		 * Each entry: [ 'code' => string, 'class' => string, 'file' => string ]
		 *
		 * @return array
		 */
		public function getTriggerBlocks() {
			return array();
		}

		/**
		 * Action blocks contributed by this channel (outbound side-effects).
		 * Same shape as getTriggerBlocks().
		 *
		 * @return array
		 */
		public function getActionBlocks() {
			return array();
		}

		/* --- Outbound helper --------------------------------------- */

		/**
		 * Convenience send wrapper. Routes through the adapter.
		 *
		 * @param string $chat_id  Platform-specific recipient ID.
		 * @param string $message  Plain-text message body.
		 * @param array  $opts     Adapter-specific options.
		 * @return array { ok: bool, error?: string }
		 */
		public function sendOutbound( $chat_id, $message, $opts = array() ) {
			$adapter = $this->getAdapter();
			if ( ! $adapter ) {
				return array(
					'ok'    => false,
					'error' => 'No adapter for platform ' . $this->_platform,
				);
			}
			if ( ! method_exists( $adapter, 'send_outbound' ) ) {
				return array(
					'ok'    => false,
					'error' => 'Adapter missing send_outbound()',
				);
			}
			$opts = array_merge(
				array( '_account' => $this->getDecryptedParams( false ) ),
				is_array( $opts ) ? $opts : array()
			);
			$ok = (bool) call_user_func(
				array( $adapter, 'send_outbound' ),
				(string) $chat_id,
				(string) $message,
				$opts
			);
			return array( 'ok' => $ok );
		}

		/* --- Connectivity test (delegates to adapter) -------------- */

		/**
		 * Test channel connectivity via the registered adapter.
		 * Subclasses with a dedicated ping endpoint should override.
		 *
		 * @param bool $need Force re-test.
		 * @return bool
		 */
		public function doTest( $need = false ) {
			$params = $this->getParams();
			if ( ! $need && ! empty( $params['_status'] ) ) {
				return true;
			}
			$adapter = $this->getAdapter();
			if ( ! $adapter ) {
				$this->addParam( '_status', 7 );
				$this->addParam( '_status_error', 'No adapter for ' . $this->_platform );
				return false;
			}
			$this->addParam( '_status', 1 );
			$this->addParam( '_status_error', '' );
			return true;
		}
	}
}
