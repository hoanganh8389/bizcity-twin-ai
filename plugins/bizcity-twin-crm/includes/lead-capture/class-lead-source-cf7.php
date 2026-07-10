<?php
/**
 * PHASE 0.37.2 — Contact Form 7 adapter.
 *
 * CF7 ko cố định tên field → ta dò bằng:
 *   - email: field type `email*` hoặc tên chứa "email"
 *   - phone: tên chứa "phone|tel|sdt|so-dien-thoai"
 *   - name : tên chứa "name|ho-ten|fullname|your-name"
 *   - msg  : type textarea hoặc tên chứa "message|noi-dung|content"
 *
 * Hook: `wpcf7_mail_sent` (fires sau khi CF7 validate & gửi mail).
 *
 * @package BizCity\CRM\LeadCapture
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BizCity_CRM_Lead_Source_CF7 {

	public static function register(): void {
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_mail_sent' ), 20, 1 );
	}

	public static function on_mail_sent( $contact_form ): void {
		if ( ! class_exists( 'WPCF7_Submission' ) ) { return; }
		$sub = WPCF7_Submission::get_instance();
		if ( ! $sub ) { return; }

		$posted = (array) $sub->get_posted_data();
		$tags   = method_exists( $contact_form, 'scan_form_tags' ) ? (array) $contact_form->scan_form_tags() : array();

		$mapped = self::map_fields( $posted, $tags );
		if ( empty( $mapped['email'] ) && empty( $mapped['phone'] ) ) { return; }

		$form_id   = method_exists( $contact_form, 'id' )    ? (int) $contact_form->id()    : 0;
		$form_slug = method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '';
		$source    = 'cf7:' . ( $form_id ?: ( sanitize_title( $form_slug ) ?: 'unknown' ) );

		// [2026-07-02 Johnny Chu] PHASE-0.47 W2 — read _bzref_data referral cookie data from posted CF7 fields
		$referral = array();
		if ( class_exists( 'BizCity_CRM_CF7_Referral_Tracker' ) ) {
			$bzref_raw = isset( $posted[ '_bzref_data' ] ) ? (string) $posted[ '_bzref_data' ] : '';
			if ( $bzref_raw === '' ) {
				// Fallback: check array variant CF7 sometimes wraps values in
				$bzref_raw = isset( $posted[ '_bzref_data' ][0] ) ? (string) $posted[ '_bzref_data' ][0] : '';
			}
			if ( $bzref_raw !== '' ) {
				$referral = BizCity_CRM_CF7_Referral_Tracker::sanitize( $bzref_raw );
			}
		}

		$payload = array_merge( $mapped, array(
			'meta' => array(
				'form_id'   => $form_id,
				'form_name' => $form_slug,
				'ip'        => method_exists( $sub, 'get_meta' ) ? (string) $sub->get_meta( 'remote_ip' ) : '',
				'url'       => method_exists( $sub, 'get_meta' ) ? (string) $sub->get_meta( 'url' )       : '',
				'ua'        => method_exists( $sub, 'get_meta' ) ? (string) $sub->get_meta( 'user_agent' ) : '',
				'raw'       => $posted, // full posted data for audit
				'referral'  => $referral, // [2026-07-02 Johnny Chu] PHASE-0.47 W2 — referral session data
			),
		) );

		$res = BizCity_CRM_Lead_Capture_Engine::capture( $payload, $source );
		if ( is_wp_error( $res ) && function_exists( 'error_log' ) ) {
			error_log( '[BizCity CRM] CF7 capture failed: ' . $res->get_error_message() );
		}
	}

	private static function map_fields( array $posted, array $tags ): array {
		$email = '';
		$phone = '';
		$name  = '';
		$msg   = '';

		// First pass via tag types.
		foreach ( $tags as $t ) {
			$type = strtolower( (string) ( $t->basetype ?? $t->type ?? '' ) );
			$key  = (string) ( $t->name ?? '' );
			$val  = isset( $posted[ $key ] ) ? self::flatten( $posted[ $key ] ) : '';
			if ( $val === '' ) { continue; }
			if ( $type === 'email' && ! $email ) { $email = $val; continue; }
			if ( $type === 'tel'   && ! $phone ) { $phone = $val; continue; }
			if ( $type === 'textarea' && ! $msg ) { $msg = $val; }
		}

		// Second pass via name heuristics.
		foreach ( $posted as $k => $v ) {
			$flat = self::flatten( $v );
			if ( $flat === '' ) { continue; }
			$lk = strtolower( (string) $k );
			if ( ! $email && ( $lk === 'your-email' || $lk === 'email' || strpos( $lk, 'email' ) !== false ) && is_email( $flat ) ) {
				$email = $flat; continue;
			}
			if ( ! $phone && preg_match( '/(phone|tel|sdt|so[-_]?dien[-_]?thoai|mobile)/', $lk ) ) {
				$phone = $flat; continue;
			}
			if ( ! $name && preg_match( '/(your-?name|fullname|full[-_]?name|ho[-_]?ten|name)$/', $lk ) ) {
				$name = $flat; continue;
			}
			if ( ! $msg && preg_match( '/(message|noi[-_]?dung|content|your-?message)/', $lk ) ) {
				$msg = $flat;
			}
		}

		return array(
			'email'     => $email,
			'phone'     => $phone,
			'full_name' => $name,
			'message'   => $msg,
		);
	}

	private static function flatten( $v ): string {
		if ( is_array( $v ) ) { return implode( ', ', array_map( 'strval', $v ) ); }
		return trim( (string) $v );
	}
}
