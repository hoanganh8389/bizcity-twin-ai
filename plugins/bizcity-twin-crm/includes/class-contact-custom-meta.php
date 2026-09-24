<?php
/**
 * Contact custom metadata (JSON) — PHASE-0.60J BG-6 / R-BOTSTUDIO-6b.
 *
 * Staff-entered free-form metadata for one CRM contact, stored under the ONE reserved key
 * `additional_attributes.custom_meta` of the existing `bizcity_crm_contacts` row (no new table, no new owner).
 * This class is PURE (no WordPress, no database) so the rules below are unit-testable:
 *
 *   · keys are `[a-z][a-z0-9_]{0,39}`, at most 20 keys, whole object ≤ 8 KB of JSON;
 *   · a value is a scalar (string ≤ 500 chars, number, bool) or a small array/object of scalars (≤ 1 KB as JSON);
 *   · `null` deletes a key;
 *   · secret-looking keys (token, key, secret, password, authorization, cookie…) are refused — metadata is
 *     readable by every staff member who can see the contact, so it must never carry a credential;
 *   · it can never overwrite a system key: the writer only ever touches `custom_meta`.
 *
 * @package BizCity_Twin_CRM
 * @since PHASE-0.60J (2026-09-24)
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60J BG-6 — pure validator/merger for contact custom metadata.
defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Contact_Custom_Meta {

	const ATTR_KEY      = 'custom_meta';
	const MAX_KEYS      = 20;
	const MAX_TOTAL     = 8192;
	const MAX_VALUE     = 1024;
	const MAX_STRING    = 500;

	/**
	 * Decode the text a staff member typed into the JSON box. Accepts a JSON object only.
	 *
	 * @return array{ok:bool,patch:array,code:string,message:string,hint:string}
	 */
	public static function parse_input( $raw ): array {
		if ( is_array( $raw ) ) {
			return self::ok_patch( $raw );
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return self::fail( 'empty_metadata', 'Chưa nhập metadata.', 'Nhập một đối tượng JSON, ví dụ {"nguon":"zalo_ads","vip":true}.' );
		}
		$decoded = json_decode( $text, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || array() === $decoded || array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
			return self::fail( 'invalid_json', 'Metadata phải là một đối tượng JSON hợp lệ.', 'Ví dụ: {"nguon":"zalo_ads","vip":true}. Không dùng mảng trần hoặc chuỗi.' );
		}
		return self::ok_patch( $decoded );
	}

	/**
	 * Merge a validated patch into the current custom_meta. `null` deletes a key.
	 *
	 * @param array $current existing custom_meta (may be empty)
	 * @param array $patch   key => value | null
	 * @return array{ok:bool,meta:array,code:string,message:string,hint:string}
	 */
	public static function apply( array $current, array $patch ): array {
		$meta = $current;
		foreach ( $patch as $key => $value ) {
			$key = is_string( $key ) ? $key : (string) $key;
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $key ) ) {
				return self::result_fail( 'invalid_key', sprintf( 'Khoá "%s" không hợp lệ.', mb_substr( $key, 0, 40 ) ), 'Khoá chỉ gồm chữ thường, số, gạch dưới; bắt đầu bằng chữ, tối đa 40 ký tự.' );
			}
			if ( preg_match( '/(api[_-]?key|token|secret|passw|authoriz|cookie|bearer|credential|private)/i', $key ) ) {
				return self::result_fail( 'secret_key_refused', sprintf( 'Khoá "%s" trông như thông tin bí mật.', $key ), 'Metadata hiển thị cho mọi nhân viên xem được contact — không lưu khoá/token/mật khẩu ở đây.' );
			}
			if ( null === $value ) {
				unset( $meta[ $key ] );
				continue;
			}
			$checked = self::check_value( $value );
			if ( null !== $checked ) {
				return self::result_fail( $checked[0], sprintf( 'Giá trị của "%s" không hợp lệ: %s', $key, $checked[1] ), $checked[2] );
			}
			$meta[ $key ] = is_string( $value ) ? trim( $value ) : $value;
		}
		if ( count( $meta ) > self::MAX_KEYS ) {
			return self::result_fail( 'too_many_keys', sprintf( 'Tối đa %d khoá metadata cho mỗi contact.', self::MAX_KEYS ), 'Xoá bớt khoá cũ (đặt giá trị null) rồi thử lại.' );
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) : json_encode( $meta, JSON_UNESCAPED_UNICODE );
		if ( strlen( (string) $encoded ) > self::MAX_TOTAL ) {
			return self::result_fail( 'metadata_too_large', 'Metadata quá lớn (tối đa 8 KB).', 'Rút gọn giá trị hoặc xoá bớt khoá.' );
		}
		return array( 'ok' => true, 'meta' => $meta, 'code' => '', 'message' => '', 'hint' => '' );
	}

	/** Read the custom_meta object out of a contact's raw `additional_attributes` JSON (or already-decoded array). */
	public static function extract( $additional_attributes ): array {
		$attrs = is_array( $additional_attributes ) ? $additional_attributes : json_decode( (string) $additional_attributes, true );
		$meta  = is_array( $attrs ) && isset( $attrs[ self::ATTR_KEY ] ) && is_array( $attrs[ self::ATTR_KEY ] ) ? $attrs[ self::ATTR_KEY ] : array();
		return $meta;
	}

	/** @return array{0:string,1:string,2:string}|null */
	private static function check_value( $value ): ?array {
		if ( is_string( $value ) ) {
			return mb_strlen( $value ) > self::MAX_STRING
				? array( 'value_too_long', sprintf( 'chuỗi dài hơn %d ký tự', self::MAX_STRING ), 'Rút gọn giá trị.' )
				: null;
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return null;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $inner ) {
				if ( is_array( $inner ) || is_object( $inner ) ) {
					return array( 'value_too_deep', 'chỉ cho phép lồng một cấp', 'Trải phẳng giá trị hoặc tách thành nhiều khoá.' );
				}
				if ( is_string( $inner ) && mb_strlen( $inner ) > self::MAX_STRING ) {
					return array( 'value_too_long', sprintf( 'một phần tử dài hơn %d ký tự', self::MAX_STRING ), 'Rút gọn giá trị.' );
				}
			}
			$size = strlen( (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE ) ) );
			return $size > self::MAX_VALUE
				? array( 'value_too_large', 'vượt 1 KB', 'Rút gọn giá trị.' )
				: null;
		}
		return array( 'value_type', 'kiểu dữ liệu không được hỗ trợ', 'Dùng chuỗi, số, true/false hoặc mảng đơn giản.' );
	}

	private static function ok_patch( array $patch ): array {
		return array( 'ok' => true, 'patch' => $patch, 'code' => '', 'message' => '', 'hint' => '' );
	}

	private static function fail( string $code, string $message, string $hint ): array {
		return array( 'ok' => false, 'patch' => array(), 'code' => $code, 'message' => $message, 'hint' => $hint );
	}

	private static function result_fail( string $code, string $message, string $hint ): array {
		return array( 'ok' => false, 'meta' => array(), 'code' => $code, 'message' => $message, 'hint' => $hint );
	}
}
