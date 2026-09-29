<?php
/**
 * Zalo transport capability descriptor — `zalo-transport-capability@1.1.0`.
 *
 * PHASE-0.82 doc 07 §5. The Zalo Personal channel has more than one transport underneath it
 * (`zca`, `zalo_hub`, and a third-party API branch later). They do NOT have the same abilities,
 * and until now that difference was hardcoded in core as `if ( 'zalo_hub' === $provider )`
 * (class-bot-zalo-actions.php) — core knowing transport names by heart.
 *
 * This class turns that difference into declared, versioned DATA that core queries instead:
 * core asks "does this account's transport support X", the transport answers, and it also
 * answers WHY not, so the user-facing wording stays specific without core knowing who it is.
 *
 * Two rules make version upgrades safe, and both are deliberate:
 *
 *   1. An absent or unrecognized capability key means FALSE / not supported. Fail closed in both
 *      directions — an old consumer meeting a new transport ignores what it does not know, and a
 *      new consumer meeting an old transport sees absence and degrades instead of assuming.
 *      Adding a key is therefore always additive and never breaking.
 *   2. An unregistered transport id supports NOTHING. A transport that has not published a
 *      descriptor must not inherit another transport's abilities by accident.
 *
 * Version 1.1.0 adds the transport-port capability keys and a limits map. An empty limits map
 * means the transport has not published a limit; it does not mean unlimited.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 */

	// [2026-09-28 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.82-A0 — publish the transport capability contract used by the port consumers.
defined( 'ABSPATH' ) || exit;

final class BizCity_Zalo_Transport_Capability {

	const CONTRACT = 'zalo-transport-capability';
	const VERSION  = '1.1.0';

	/** @var array|null test seam: descriptor map override (null = built-ins + filter). */
	public static $descriptors = null;

	/**
	 * transport id => descriptor. A transport publishes its own row; core never edits this to
	 * describe someone else. `capabilities` is a flat key => bool map (rule 1 above);
	 * `hints` carries the transport's own explanation for a capability it does not have, so the
	 * message a user reads stays specific while core stays ignorant of transport names.
	 */
	public static function descriptors(): array {
		if ( is_array( self::$descriptors ) ) {
			return self::$descriptors;
		}
		$built_in = array(
			'zca'      => array(
				'label'        => 'zca-bridge',
				'capabilities' => array(
					// Existing Personal bridge composer and Bot Studio paths support these operations.
					'can_initiate_thread'         => true,
					'can_send_text'               => true,
					'can_send_image'              => true,
					'can_send_file'               => true,
					'can_quote_reply'             => true,
					'delivers_owner_app_messages' => true,
					'guru_projection'             => true,
					'config_sync_check'           => false,
					// The sidecar advertises its own action list (GET /wp/actions); whether a SPECIFIC
					// action is available is still resolved there. This says only that the transport
					// has an action surface at all.
					'group_actions' => true,
				),
				'hints'        => array(),
				'limits'       => array(),
			),
			'zalo_hub' => array(
				'label'        => 'zalo-hub',
				'capabilities' => array(
					// Existing managed Hub paths preserve the current Personal composer behaviour.
					'can_initiate_thread'         => true,
					'can_send_text'               => true,
					'can_send_image'              => true,
					'can_send_file'               => true,
					'can_quote_reply'             => true,
					'delivers_owner_app_messages' => true,
					'guru_projection'             => true,
					'config_sync_check'           => true,
					'group_actions' => false,
				),
				'hints'        => array(
					// Verbatim from class-bot-zalo-actions.php before PHASE-0.82 moved it here: the
					// wording is the transport's own, and moving it must not change what a user reads.
					'group_actions' => 'Số này đang chạy zalo-hub — nhóm công cụ hành động Zalo (kick, bổ nhiệm, bình chọn, đổi tên nhóm…) hiện CHƯA phát triển cho zalo-hub, chỉ có ở zca-bridge (legacy). Không phải do thiếu cấu hình hay cần build lại — cần làm thêm mã cho zalo-hub trước.',
				),
				'limits'       => array(),
			),
		);

		/**
		 * A transport registers or overrides its own descriptor row here. This is the registry that
		 * replaces core's hardcoded transport list (doc 07 §3 rule 2).
		 *
		 * @param array $built_in transport id => descriptor
		 */
		$all = function_exists( 'apply_filters' ) ? apply_filters( 'bizcity_zalo_transport_capabilities', $built_in ) : $built_in;
		return is_array( $all ) ? $all : $built_in;
	}

	/** Registered transport ids. Callers enumerate this instead of hardcoding a provider list. */
	public static function transport_ids(): array {
		return array_keys( self::descriptors() );
	}

	/**
	 * Descriptor for one transport. An unregistered id gets a fail-closed descriptor that supports
	 * nothing (rule 2) rather than null, so callers never have to special-case "unknown".
	 */
	public static function descriptor( string $transport_id ): array {
		$all = self::descriptors();
		if ( '' !== $transport_id && isset( $all[ $transport_id ] ) && is_array( $all[ $transport_id ] ) ) {
			$row = $all[ $transport_id ];
			return array(
				'transport_id' => $transport_id,
				'label'        => (string) ( $row['label'] ?? $transport_id ),
				'capabilities' => is_array( $row['capabilities'] ?? null ) ? $row['capabilities'] : array(),
				'hints'        => is_array( $row['hints'] ?? null ) ? $row['hints'] : array(),
				'limits'       => is_array( $row['limits'] ?? null ) ? $row['limits'] : array(),
				'contract_version' => self::VERSION,
			);
		}
		return array(
			'transport_id' => $transport_id,
			'label'        => '' === $transport_id ? '' : $transport_id,
			'capabilities' => array(),
			'hints'        => array(),
			'limits'       => array(),
			'contract_version' => self::VERSION,
		);
	}

	/**
	 * Descriptor for the transport answering ONE account, or null when no account is in scope.
	 *
	 * null is not "unsupported": it means the caller is looking at a surface with no single number
	 * in view (e.g. a multi-account panel), where per-transport gating must not apply at all. That
	 * distinction is why this returns null instead of the fail-closed descriptor.
	 */
	public static function for_account( string $account_id ): ?array {
		$account_id = trim( $account_id );
		if ( '' === $account_id || ! class_exists( 'BizCity_Zalo_Account_Flags' ) ) {
			return null;
		}
		return self::descriptor( (string) BizCity_Zalo_Account_Flags::provider( $account_id ) );
	}

	/** Rule 1: absent or non-true key = not supported. */
	public static function supports( ?array $descriptor, string $key ): bool {
		return is_array( $descriptor ) && true === ( $descriptor['capabilities'][ $key ] ?? null );
	}

	/** The transport's own explanation for a capability it lacks; '' when it published none. */
	public static function hint( ?array $descriptor, string $key ): string {
		return is_array( $descriptor ) ? (string) ( $descriptor['hints'][ $key ] ?? '' ) : '';
	}

	/** Return a published integer limit, or null when the transport did not publish one. */
	public static function limit( ?array $descriptor, string $key ): ?int {
		if ( ! is_array( $descriptor ) || ! array_key_exists( $key, $descriptor['limits'] ?? array() ) ) {
			return null;
		}
		$value = $descriptor['limits'][ $key ];
		return is_int( $value ) ? $value : null;
	}
}
