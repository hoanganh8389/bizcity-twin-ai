<?php
/**
 * Public Phase 0.41 framework registration facade.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Framework_Handle' ) ) {
	final class BizCity_Framework_Service_Handle {

		private $name;
		private $manifest;

		public function __construct( $name, array $manifest ) {
			$this->name = (string) $name;
			$this->manifest = $manifest;
		}

		public function name(): string {
			return $this->name;
		}

		public function describe(): array {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W5 — expose only redacted contract metadata through bounded service handles.
			$base = array(
				'name' => $this->name,
				'extension_id' => (string) ( $this->manifest['extension_id'] ?? '' ),
				'extension_version' => (string) ( $this->manifest['extension_version'] ?? '' ),
				'framework_range' => (string) ( $this->manifest['requires_framework'] ?? '' ),
				'capabilities' => array_values( array_map( 'strval', (array) ( $this->manifest['capabilities'] ?? array() ) ) ),
			);
			if ( 'channels' === $this->name ) {
				$base['channels'] = $this->public_channels();
			} elseif ( 'context' === $this->name ) {
				$base['context_policies'] = $this->policy_values( 'context_policy' );
			} elseif ( 'brain' === $this->name ) {
				$base['brain_policies'] = $this->policy_values( 'brain_policy' );
			} elseif ( 'actions' === $this->name ) {
				$base['action_capabilities'] = array_values( array_filter( $base['capabilities'], function ( $capability ) {
					return strpos( $capability, 'action.' ) === 0;
				} ) );
			}
			return $base;
		}

		private function public_channels(): array {
			$channels = array();
			foreach ( (array) ( $this->manifest['channels'] ?? array() ) as $channel ) {
				if ( ! is_array( $channel ) ) {
					continue;
				}
				$allowed = array( 'slug', 'platform', 'zone', 'identity_policy', 'account_scope', 'crm_policy', 'brain_policy', 'context_policy', 'surface_policy' );
				$public = array();
				foreach ( $allowed as $field ) {
					if ( array_key_exists( $field, $channel ) ) {
						$public[ $field ] = $channel[ $field ];
					}
				}
				$channels[] = $public;
			}
			return $channels;
		}

		private function policy_values( $field ): array {
			$values = array();
			foreach ( $this->public_channels() as $channel ) {
				$value = (string) ( $channel[ $field ] ?? '' );
				if ( $value !== '' ) {
					$values[] = $value;
				}
			}
			return array_values( array_unique( $values ) );
		}
	}

	final class BizCity_Framework_Handle {

		private $manifest;
		private $extension;

		public function __construct( array $manifest, $extension = null ) {
			$this->manifest  = $manifest;
			$this->extension = is_object( $extension ) ? $extension : null;
		}

		public function manifest(): array {
			return $this->manifest;
		}

		public function extension() {
			return $this->extension;
		}

		public function services(): array {
			return array( 'contracts', 'channels', 'context', 'brain', 'actions' );
		}

		public function service( $name ) {
			$name = strtolower( preg_replace( '/[^a-z0-9_]/', '', (string) $name ) );
			return in_array( $name, $this->services(), true ) ? $name : false;
		}

		public function service_handle( $name ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W5 — add an additive bounded service-object API while preserving the legacy service-name API.
			$name = $this->service( $name );
			return false === $name ? false : new BizCity_Framework_Service_Handle( $name, $this->public_manifest() );
		}

		public function public_manifest(): array {
			$allowed = array( 'contract', 'version', 'extension_id', 'extension_version', 'requires_framework', 'capabilities', 'channels', 'diagnostics' );
			$public = array();
			foreach ( $allowed as $field ) {
				if ( array_key_exists( $field, $this->manifest ) ) {
					$public[ $field ] = $this->manifest[ $field ];
				}
			}
			return $public;
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_SDK' ) ) {
	final class BizCity_Framework_SDK {

		public static function register( array $manifest, $extension = null ) {
			// [2026-09-02 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — expose one bounded manifest registration entry point and keep runtime services behind the returned handle.
			if ( ! class_exists( 'BizCity_Framework_Manifest_Registry' ) ) {
				return false;
			}
			return BizCity_Framework_Manifest_Registry::register( $manifest, $extension );
		}

		public static function channel_policy( $slug ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W5 — let consumers resolve manifest channel policy through the public facade while keeping legacy descriptors as compatibility fallback.
			if ( ! class_exists( 'BizCity_Framework_Manifest_Registry' ) ) {
				return false;
			}
			$channel = BizCity_Framework_Manifest_Registry::channel( sanitize_key( (string) $slug ) );
			return is_array( $channel ) ? $channel : false;
		}
	}
}
