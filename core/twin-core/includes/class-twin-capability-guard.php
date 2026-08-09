<?php
/**
 * Least-privilege capability and approval guard for Twin tool execution.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Capability_Guard' ) ) {
	final class BizCity_Twin_Capability_Guard {

		/** @var array<string,string> */
		private static $default_permissions = array(
			'search_kg'    => 'kg.read',
			'list_sources' => 'kg.read',
			'query_entity' => 'kg.read',
			'fetch_url'    => 'network.fetch',
		);

		/** @var array<string,string> */
		private static $sensitive_permissions = array(
			'content.publish'          => 'publish_content',
			'channel.zalo.send'        => 'send_message',
			'channel.telegram.send'    => 'send_message',
			'woocommerce.order.create' => 'create_order',
			'memory.delete'            => 'delete_data',
			'payment.execute'          => 'execute_payment',
		);

		/**
		 * Authorize one tool call before the tool receives its arguments.
		 *
		 * @param string              $tool_name
		 * @param object|null         $tool
		 * @param array<string,mixed> $context
		 * @return array{allowed:bool,permission:string,approval_gate:string,code:string,message:string,hint:string,help_code:string}
		 */
		public static function authorize( $tool_name, $tool, array $context = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — enforce least privilege before execute().
			$permission = self::permission_for( $tool_name, $tool );
			$granted    = isset( $context['permissions'] ) && is_array( $context['permissions'] )
				? array_map( 'strval', $context['permissions'] )
				: array();
			$extension_id = isset( $context['extension_id'] ) ? (string) $context['extension_id'] : '';
			if ( '' !== $extension_id && class_exists( 'BizCity_Twin_Capability_Consent' ) ) {
				// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — a registered manifest cannot self-grant through caller context.
				$consented = BizCity_Twin_Capability_Consent::permissions_for( $extension_id, $context );
				$granted   = BizCity_Twin_Capability_Consent::has_manifest( $extension_id ) ? $consented : array_merge( $granted, $consented );
			}
			$granted    = apply_filters( 'bizcity_twin_tool_granted_permissions', $granted, $tool_name, $tool, $context );
			$granted    = array_values( array_unique( array_map( 'strval', $granted ) ) );

			if ( '' === $permission || ! in_array( $permission, $granted, true ) ) {
				return self::denied(
					'permission_denied',
					$permission,
					'',
					'Tool chưa được cấp quyền ' . ( '' !== $permission ? $permission : 'không xác định' ) . '.',
					'Cấp đúng permission cho module trước khi chạy tool.',
					'permission_required'
				);
			}

			$approval_gate = self::approval_gate_for( $permission, $tool );
			if ( '' !== $approval_gate ) {
				$approved = isset( $context['approved_gates'] ) && is_array( $context['approved_gates'] )
					? array_map( 'strval', $context['approved_gates'] )
					: array();
				if ( ! in_array( $approval_gate, $approved, true ) ) {
					return self::denied(
						'approval_required',
						$permission,
						$approval_gate,
						'Hành động này cần được phê duyệt trước khi thực hiện.',
						'Phê duyệt hành động trong màn hình xác nhận rồi thử lại.',
						'approval_required'
					);
				}
			}

			$scope_level = self::scope_level_for( $permission, $tool );
			$context_scope = isset( $context['scope_level'] ) ? (string) $context['scope_level'] : '';
			if ( '' !== $context_scope && $context_scope !== $scope_level ) {
				return self::denied(
					'scope_mismatch',
					$permission,
					$approval_gate,
					'Tool không được phép chạy ngoài phạm vi dữ liệu đã cấp.',
					'Chọn tenant, site hoặc user đúng với permission của tool.',
					'scope_mismatch'
				);
			}
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — user-scoped permissions require explicit verified identity context.
			if ( 'user' === $scope_level && ( 'user' !== $context_scope || (int) ( $context['user_id'] ?? 0 ) <= 0 ) ) {
				return self::denied(
					'scope_mismatch',
					$permission,
					$approval_gate,
					'Không xác định được người dùng trong phạm vi cấp quyền của tool.',
					'Cung cấp user scope và user ID hợp lệ trước khi chạy tool.',
					'scope_mismatch'
				);
			}

			return array(
				'allowed'       => true,
				'permission'    => $permission,
				'approval_gate' => $approval_gate,
				'code'          => '',
				'message'       => '',
				'hint'          => '',
				'help_code'     => '',
			);
		}

		/**
		 * @param string      $tool_name
		 * @param object|null $tool
		 * @return string
		 */
		public static function permission_for( $tool_name, $tool = null ) {
			if ( $tool && method_exists( $tool, 'permissions' ) ) {
				$permissions = $tool->permissions();
				if ( is_array( $permissions ) && ! empty( $permissions[0] ) ) {
					return (string) $permissions[0];
				}
			}
			$permission = isset( self::$default_permissions[ $tool_name ] ) ? self::$default_permissions[ $tool_name ] : '';
			return (string) apply_filters( 'bizcity_twin_tool_required_permission', $permission, $tool_name, $tool );
		}

		/**
		 * @param string      $permission
		 * @param object|null $tool
		 * @return string
		 */
		public static function approval_gate_for( $permission, $tool = null ) {
			if ( $tool && method_exists( $tool, 'approval_gate' ) ) {
				return (string) $tool->approval_gate();
			}
			return isset( self::$sensitive_permissions[ $permission ] ) ? self::$sensitive_permissions[ $permission] : '';
		}

		/**
		 * @param string      $permission
		 * @param object|null $tool
		 * @return string
		 */
		public static function scope_level_for( $permission, $tool = null ) {
			if ( $tool && method_exists( $tool, 'scope_level' ) ) {
				return (string) $tool->scope_level();
			}
			if ( 0 === strpos( $permission, 'memory.' ) || 0 === strpos( $permission, 'content.' ) || 0 === strpos( $permission, 'channel.' ) ) {
				return 'user';
			}
			return 'tenant';
		}

		private static function denied( $code, $permission, $approval_gate, $message, $hint, $help_code ) {
			return array(
				'allowed'       => false,
				'permission'    => (string) $permission,
				'approval_gate' => (string) $approval_gate,
				'code'          => (string) $code,
				'message'       => (string) $message,
				'hint'          => (string) $hint,
				'help_code'     => (string) $help_code,
			);
		}
	}
}
