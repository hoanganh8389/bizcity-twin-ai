<?php
/**
 * Mutation contract guard and audit adapter for framework controllers.
 *
 * Controllers should call validate() before side effects and record() after
 * the outcome. The helper is deliberately storage-neutral and does not create
 * a new database table.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 1.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Twin_Mutation_Guard' ) ) {
	final class BizCity_Twin_Mutation_Guard {

		/** @var array<string,string> */
		private static $action_permissions = array(
			'create'  => 'content.write',
			'update'  => 'content.write',
			'delete'  => 'content.delete',
			'publish' => 'content.publish',
			'send'    => 'channel.send',
			'pay'     => 'payment.execute',
			'execute' => 'workflow.execute',
		);

		/** @var array<string,string> */
		private static $action_gates = array(
			'delete'  => 'delete_data',
			'publish' => 'publish_content',
			'send'    => 'send_message',
			'pay'     => 'execute_payment',
		);

		/**
		 * Validate the public mutation envelope before a side effect.
		 *
		 * @param array<string,mixed> $mutation
		 * @param array<string,mixed> $context
		 * @return array{allowed:bool,code:string,message:string,hint:string,help_code:string}
		 */
		public static function validate( array $mutation, array $context = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — mandatory mutation preflight.
			$required = array( 'contract', 'version', 'trace_id', 'idempotency_key', 'action', 'resource' );
			foreach ( $required as $field ) {
				if ( ! array_key_exists( $field, $mutation ) || '' === (string) $mutation[ $field ] ) {
					return self::deny( 'mutation_contract_invalid', 'Mutation thiếu trường bắt buộc.', 'Bổ sung contract, trace_id và idempotency_key trước khi thử lại.', 'mutation_contract_invalid' );
				}
			}
			if ( 'mutation-contract' !== (string) $mutation['contract'] || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+/', (string) $mutation['version'] ) ) {
				return self::deny( 'mutation_contract_invalid', 'Mutation dùng contract hoặc version không hợp lệ.', 'Dùng mutation-contract v1 theo public schema.', 'mutation_contract_invalid' );
			}

			$action = (string) $mutation['action'];
			if ( ! isset( self::$action_permissions[ $action ] ) ) {
				return self::deny( 'invalid_param', 'Mutation action không được hỗ trợ.', 'Chọn action đã công bố trong mutation contract.', 'mutation_action_invalid' );
			}
			$permission = self::$action_permissions[ $action ];
			$resource_type = is_array( $mutation['resource'] ?? null ) ? (string) ( $mutation['resource']['type'] ?? '' ) : '';
			// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — finance mutations use a dedicated least-privilege scope.
			if ( 'finance_entry' === $resource_type && 'create' === $action ) {
				$permission = 'finance.write';
			}
			$granted    = isset( $context['permissions'] ) && is_array( $context['permissions'] ) ? array_map( 'strval', $context['permissions'] ) : array();
			$granted    = apply_filters( 'bizcity_twin_mutation_permissions', $granted, $mutation, $context );
			if ( ! in_array( $permission, $granted, true ) ) {
				return self::deny( 'permission_denied', 'Module chưa được cấp quyền cho mutation này.', 'Cấp đúng permission ở manifest và consent UI.', 'permission_required' );
			}

			if ( isset( self::$action_gates[ $action ] ) ) {
				$approved = isset( $context['approved_gates'] ) && is_array( $context['approved_gates'] ) ? array_map( 'strval', $context['approved_gates'] ) : array();
				if ( ! in_array( self::$action_gates[ $action ], $approved, true ) ) {
					return self::deny( 'approval_required', 'Mutation nhạy cảm chưa được phê duyệt.', 'Hiển thị approval gate trước khi thực hiện side effect.', 'approval_required' );
				}
			}

			self::audit( 'mutation_authorized', $mutation, $context, array( 'permission' => $permission, 'status' => 'accepted' ) );
			return array( 'allowed' => true, 'code' => '', 'message' => '', 'hint' => '', 'help_code' => '' );
		}

		/**
		 * Record mutation result metadata after the controller finishes.
		 *
		 * @param array<string,mixed> $mutation
		 * @param string              $status
		 * @param array<string,mixed> $context
		 * @return void
		 */
		public static function record( array $mutation, $status, array $context = array() ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-AUDIT — mutation outcome evidence.
			self::audit( 'mutation_outcome', $mutation, $context, array( 'status' => (string) $status ) );
		}

		private static function audit( $event, array $mutation, array $context, array $extra = array() ) {
			if ( class_exists( 'BizCity_Twin_Runtime_Audit' ) ) {
				BizCity_Twin_Runtime_Audit::record( $event, array_merge( array(
					'trace_id'        => (string) ( $mutation['trace_id'] ?? '' ),
					'idempotency_key' => (string) ( $mutation['idempotency_key'] ?? '' ),
					'action'          => (string) ( $mutation['action'] ?? '' ),
					'resource_type'   => is_array( $mutation['resource'] ?? null ) ? (string) ( $mutation['resource']['type'] ?? '' ) : '',
					'resource_scope'  => is_array( $mutation['resource'] ?? null ) ? (string) ( $mutation['resource']['scope'] ?? '' ) : '',
					'user_id'         => (int) ( $context['user_id'] ?? 0 ),
				), $extra ) );
			}
		}

		private static function deny( $code, $message, $hint, $help_code ) {
			return array(
				'allowed'   => false,
				'code'      => (string) $code,
				'message'   => (string) $message,
				'hint'      => (string) $hint,
				'help_code' => (string) $help_code,
			);
		}
	}
}
