<?php
/**
 * BizCity CRM — Email Event Registry (PHASE 0.37.1)
 *
 * Registry of events that can trigger automated emails. Each event declares:
 *   - key:          unique slug (matches WP action hook name)
 *   - label:        human-readable
 *   - hook_args:    int (number of args passed by do_action)
 *   - placeholders: list of {{var}} tokens available in templates
 *   - normalize:    callable($hook_args[]) → array<string,scalar>
 *
 * 3rd-party plugins can register more via `apply_filters( 'bizcity_crm_email_events', $events )`.
 *
 * @package BizCity_Twin_CRM
 * @since   0.37.1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Email_Event_Registry {

	/** @return array<string,array<string,mixed>> map keyed by event_key */
	public static function events(): array {
		$events = array();

		/* ── WooCommerce — checkout / order completed ── */
		$events['woocommerce_order_completed'] = array(
			'key'          => 'woocommerce_order_completed',
			'label'        => 'WooCommerce: Đơn hàng hoàn tất (checkout success)',
			'hook'         => 'woocommerce_order_status_completed',
			'hook_args'    => 2,
			'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_email', 'customer_name', 'site_name' ),
			'normalize'    => static function ( $args ) {
				$order_id = (int) ( $args[0] ?? 0 );
				$ctx = array(
					'order_id'       => $order_id,
					'order_number'   => $order_id,
					'order_total'    => '',
					'customer_email' => '',
					'customer_name'  => '',
					'site_name'      => get_bloginfo( 'name' ),
				);
				if ( $order_id && function_exists( 'wc_get_order' ) ) {
					$o = wc_get_order( $order_id );
					if ( $o ) {
						$ctx['order_number']   = $o->get_order_number();
						$ctx['order_total']    = (string) $o->get_total();
						$ctx['customer_email'] = $o->get_billing_email();
						$ctx['customer_name']  = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
					}
				}
				return $ctx;
			},
		);

		/* ── WooCommerce — payment complete (invoice paid proxy) ── */
		$events['woocommerce_payment_complete'] = array(
			'key'          => 'woocommerce_payment_complete',
			'label'        => 'WooCommerce: Thanh toán thành công',
			'hook'         => 'woocommerce_payment_complete',
			'hook_args'    => 1,
			'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_email', 'customer_name', 'site_name' ),
			'normalize'    => static function ( $args ) {
				$order_id = (int) ( $args[0] ?? 0 );
				$ctx = array( 'order_id' => $order_id, 'order_number' => $order_id, 'site_name' => get_bloginfo( 'name' ) );
				if ( $order_id && function_exists( 'wc_get_order' ) ) {
					$o = wc_get_order( $order_id );
					if ( $o ) {
						$ctx['order_number']   = $o->get_order_number();
						$ctx['order_total']    = (string) $o->get_total();
						$ctx['customer_email'] = $o->get_billing_email();
						$ctx['customer_name']  = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
					}
				}
				return $ctx;
			},
		);

		/* ── CRM — Contact saved (created or updated) ── */
		$events['bizcity_crm_contact_saved'] = array(
			'key'          => 'bizcity_crm_contact_saved',
			'label'        => 'CRM: Tạo / cập nhật Contact',
			'hook'         => 'bizcity_crm_contact_saved',
			'hook_args'    => 2,
			'placeholders' => array( 'contact_id', 'contact_email', 'contact_name', 'contact_phone', 'site_name' ),
			'normalize'    => static function ( $args ) {
				$row = is_array( $args[1] ?? null ) ? $args[1] : array();
				return array(
					'contact_id'    => (int) ( $args[0] ?? ( $row['id'] ?? 0 ) ),
					'contact_email' => (string) ( $row['email'] ?? '' ),
					'contact_name'  => (string) ( $row['name']  ?? '' ),
					'contact_phone' => (string) ( $row['phone'] ?? '' ),
					'site_name'     => get_bloginfo( 'name' ),
				);
			},
		);

		/* ── CRM — Lead created ── */
		$events['bizcity_crm_lead_created'] = array(
			'key'          => 'bizcity_crm_lead_created',
			'label'        => 'CRM: Tạo Lead mới (sales pipeline)',
			'hook'         => 'bizcity_crm_lead_created',
			'hook_args'    => 2,
			'placeholders' => array( 'lead_id', 'lead_name', 'lead_email', 'lead_phone', 'lead_source', 'site_name' ),
			'normalize'    => static function ( $args ) {
				$row = is_array( $args[1] ?? null ) ? $args[1] : array();
				return array(
					'lead_id'     => (int) ( $args[0] ?? ( $row['id'] ?? 0 ) ),
					'lead_name'   => (string) ( $row['name']  ?? '' ),
					'lead_email'  => (string) ( $row['email'] ?? '' ),
					'lead_phone'  => (string) ( $row['phone'] ?? '' ),
					'lead_source' => (string) ( $row['source'] ?? '' ),
					'site_name'   => get_bloginfo( 'name' ),
				);
			},
		);

		/* ── CRM — Invoice paid ── */
		$events['bizcity_crm_invoice_paid'] = array(
			'key'          => 'bizcity_crm_invoice_paid',
			'label'        => 'CRM: Invoice thanh toán xong',
			'hook'         => 'bizcity_crm_invoice_paid',
			'hook_args'    => 2,
			'placeholders' => array( 'invoice_id', 'invoice_number', 'invoice_total', 'customer_email', 'site_name' ),
			'normalize'    => static function ( $args ) {
				$row = is_array( $args[1] ?? null ) ? $args[1] : array();
				return array(
					'invoice_id'     => (int)    ( $args[0] ?? ( $row['id']            ?? 0 ) ),
					'invoice_number' => (string) ( $row['number']           ?? '' ),
					'invoice_total'  => (string) ( $row['total']            ?? '' ),
					'customer_email' => (string) ( $row['customer_email']   ?? '' ),
					'site_name'      => get_bloginfo( 'name' ),
				);
			},
		);

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — CF7 form submit event for email auto-reply
		/* ── Contact Form 7 — form submitted (lead capture) ── */
		$events['cf7_form_submitted'] = array(
			'key'          => 'cf7_form_submitted',
			'label'        => 'CF7: Form submit (Tự động reply ebook/thông tin)',
			'hook'         => 'wpcf7_mail_sent',
			'hook_args'    => 1,
			'placeholders' => array( 'form_id', 'form_title', 'email', 'phone', 'name', 'address', 'birthday', 'site_name' ),
			'normalize'    => static function ( $args ) {
				return BizCity_CRM_Email_Event_Registry::normalize_cf7_ctx( $args, 0 );
			},
		);

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — Register one event per CF7 form that has saved field mapping.
		// This lets email rules target a specific form (e.g. "CF7: Form thông tin (#6050)")
		// and ensures {{name}}, {{email}}, {{phone}}, etc. use the configured field map.
		$_cf7_all_mappings = get_option( 'bizcity_cg_cf7_mappings', array() );
		if ( is_array( $_cf7_all_mappings ) ) {
			foreach ( $_cf7_all_mappings as $_fid_key => $_fconfig ) {
				if ( ! is_array( $_fconfig ) ) { continue; }
				$_fid   = (int) $_fid_key;
				if ( $_fid <= 0 ) { continue; }
				$_fname = sanitize_text_field( $_fconfig['form_title'] ?? "Form #{$_fid}" );
				$_fmap  = is_array( $_fconfig['field_map'] ?? null ) ? $_fconfig['field_map'] : array();

				// Build placeholder list from mapped CRM fields + standard ones
				$_mapped_keys = array_values( array_filter( array_unique( array_values( $_fmap ) ), static function( $v ) {
					return ! empty( $v ) && $v !== '_skip_';
				} ) );
				$_placeholders = array_unique( array_merge(
					array( 'form_id', 'form_title', 'email', 'phone', 'name', 'address', 'birthday', 'site_name' ),
					$_mapped_keys
				) );

				$events[ 'cf7_form_' . $_fid ] = array(
					'key'          => 'cf7_form_' . $_fid,
					'label'        => 'CF7: ' . $_fname . ' (Form #' . $_fid . ')',
					'hook'         => 'wpcf7_mail_sent',
					'hook_args'    => 1,
					'form_id'      => $_fid,
					'placeholders' => $_placeholders,
					'normalize'    => static function ( $args ) use ( $_fid ) {
						// Returns null for wrong form → dispatcher will skip (no email fired)
						return BizCity_CRM_Email_Event_Registry::normalize_cf7_ctx( $args, $_fid );
					},
				);
			}
		}
		unset( $_cf7_all_mappings, $_fid_key, $_fconfig, $_fid, $_fname, $_fmap, $_mapped_keys, $_placeholders );

		return (array) apply_filters( 'bizcity_crm_email_events', $events );
	}

	public static function get( string $event_key ): ?array {
		$all = self::events();
		return $all[ $event_key ] ?? null;
	}

	/**
	 * Normalize CF7 form submission into email template context.
	 *
	 * Uses the saved CF7 field mapping (bizcity_cg_cf7_mappings option) so that
	 * custom CF7 field names like 'parent-name' are resolved to {{name}}, etc.
	 *
	 * @param array $args      Hook args from wpcf7_mail_sent ([$cf7_form_object]).
	 * @param int   $target_id Form ID filter. 0 = accept any form (generic event).
	 *                         Non-zero = return null if form_id doesn't match (per-form event).
	 * @return array|null  null signals dispatcher to skip all rules for this invocation.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7 — CF7 field-mapping aware normalize
	 */
	public static function normalize_cf7_ctx( array $args, int $target_id ) {
		$form = $args[0] ?? null;

		// Per-form events must match the specific form_id.
		if ( $target_id > 0 ) {
			if ( ! ( $form instanceof WPCF7_ContactForm ) || (int) $form->id() !== $target_id ) {
				return null; // Wrong form — skip silently.
			}
		}

		$form_id    = ( $form instanceof WPCF7_ContactForm ) ? (int) $form->id() : 0;
		$form_title = ( $form instanceof WPCF7_ContactForm ) ? (string) $form->title() : '';

		// Load the saved CF7→CRM field mapping for this form.
		$all_mappings = get_option( 'bizcity_cg_cf7_mappings', array() );
		$fmap         = array();
		if ( is_array( $all_mappings ) && $form_id > 0 ) {
			$fmap = is_array( $all_mappings[ (string) $form_id ]['field_map'] ?? null )
				? $all_mappings[ (string) $form_id ]['field_map']
				: array();
		}

		// Get CF7 submission data.
		$sub    = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		$posted = $sub ? (array) $sub->get_posted_data() : array();

		// Apply the configured mapping: { cf7_field => crm_field } → { crm_field => value }.
		$mapped = array();
		if ( ! empty( $fmap ) ) {
			foreach ( $fmap as $cf7_field => $crm_path ) {
				if ( empty( $crm_path ) || $crm_path === '_skip_' ) { continue; }
				$raw = $posted[ (string) $cf7_field ] ?? '';
				if ( is_array( $raw ) ) { $raw = implode( ', ', $raw ); }
				$mapped[ $crm_path ] = sanitize_text_field( (string) $raw );
			}
		} else {
			// No saved mapping: fallback to common CF7 field name heuristics.
			foreach ( array( 'your-email', 'email', 'Email', 'e-mail', 'mail' ) as $k ) {
				if ( ! empty( $posted[ $k ] ) ) { $mapped['email'] = sanitize_email( (string) $posted[ $k ] ); break; }
			}
			foreach ( array( 'your-phone', 'phone', 'tel', 'Phone', 'dien-thoai', 'sdt', 'so-dien-thoai' ) as $k ) {
				if ( ! empty( $posted[ $k ] ) ) { $mapped['phone'] = sanitize_text_field( (string) $posted[ $k ] ); break; }
			}
			foreach ( array( 'your-name', 'name', 'full-name', 'parent-name', 'ho-ten', 'fullname', 'ten' ) as $k ) {
				if ( ! empty( $posted[ $k ] ) ) { $mapped['name'] = sanitize_text_field( (string) $posted[ $k ] ); break; }
			}
		}

		// Build context: standard keys first, then all mapped CRM fields.
		$ctx = array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'email'      => '',
			'phone'      => '',
			'name'       => '',
			'address'    => '',
			'birthday'   => '',
			'site_name'  => get_bloginfo( 'name' ),
		);
		// Merge mapped values (overrides defaults).
		foreach ( $mapped as $crm_field => $value ) {
			$ctx[ $crm_field ] = $value;
		}

		// Also expose all CF7 posted values as {{cf7_fieldname}} with underscores
		// (allows templates like {{cf7_child_age}} for unmapped CF7 fields).
		foreach ( $posted as $cf7_field => $raw_val ) {
			if ( ! is_string( $cf7_field ) ) { continue; }
			$safe_key = preg_replace( '/[^a-zA-Z0-9_]/', '_', $cf7_field );
			if ( $safe_key === '' ) { continue; }
			$ctx_key = 'cf7_' . $safe_key;
			if ( ! isset( $ctx[ $ctx_key ] ) ) {
				$raw_val          = is_array( $raw_val ) ? implode( ', ', $raw_val ) : (string) $raw_val;
				$ctx[ $ctx_key ] = sanitize_text_field( $raw_val );
			}
		}

		return $ctx;
	}

	/** Render template with simple {{key}} substitution. */
	public static function render( string $tpl, array $ctx ): string {
		if ( $tpl === '' ) { return ''; }
		return preg_replace_callback( '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ( $m ) use ( $ctx ) {
			$k = $m[1];
			return isset( $ctx[ $k ] ) ? (string) $ctx[ $k ] : '';
		}, $tpl );
	}
}
