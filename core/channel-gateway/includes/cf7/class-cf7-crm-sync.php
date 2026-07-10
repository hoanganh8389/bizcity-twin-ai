<?php
/**
 * CF7 Channel — CRM Sync
 *
 * Upserts contacts into bizcity_crm_contacts when bizcity-twin-crm is active.
 * Fails gracefully (returns 'skipped') if CRM not available.
 *
 * @package BizCity_Channel_Gateway
 * @since   2026-06-13
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_CF7_CRM_Sync {

	/**
	 * True if bizcity-twin-crm tables exist and are queryable.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		static $checked = null;
		if ( null !== $checked ) {
			return $checked;
		}
		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — use V2 (actual class name)
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return $checked = false;
		}
		return $checked = BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_contacts() );
	}

	/**
	 * Upsert a contact from CF7 submission data.
	 *
	 * @param  string $email   Sanitised email (may be empty).
	 * @param  string $phone   Sanitised phone (may be empty).
	 * @param  array  $mapped  Mapped field values.
	 * @param  array  $meta    {form_id, form_title, sub_id, auto_tag, owner_id}
	 * @return array {action, contact_id, error}
	 */
	public static function upsert( string $email, string $phone, array $mapped, array $meta ): array {
		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — CRM upsert from CF7 submission
		global $wpdb;
		$contacts_t = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ci_t       = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$inboxes_t  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();

		$contact_id = 0;
		$action     = 'skipped';
		$error      = null;

		try {
			// ── Step 1: find by email ────────────────────────────────────────
			if ( $email ) {
				$contact_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM `{$contacts_t}` WHERE email = %s AND deleted_at IS NULL LIMIT 1",
						$email
					)
				);
			}

			// ── Step 2: find by phone ────────────────────────────────────────
			if ( ! $contact_id && $phone ) {
				$contact_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM `{$contacts_t}` WHERE phone = %s AND deleted_at IS NULL LIMIT 1",
						$phone
					)
				);
			}

			$name       = sanitize_text_field( $mapped['name'] ?? '' );
			$first_name = sanitize_text_field( $mapped['first_name'] ?? '' );
			$last_name  = sanitize_text_field( $mapped['last_name'] ?? '' );
			$now        = current_time( 'mysql', true );

			if ( ! $name && ( $first_name || $last_name ) ) {
				$name = trim( $first_name . ' ' . $last_name );
			}

			// ── Step 3a: UPDATE existing ─────────────────────────────────────
			if ( $contact_id ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT email, phone, name, additional_attributes FROM `{$contacts_t}` WHERE id = %d",
						$contact_id
					)
				);
				$update = array( 'updated_at' => $now );
				if ( $name && ! $existing->name ) {
					$update['name'] = $name;
				}
				if ( $email && ! $existing->email ) {
					$update['email'] = $email;
				}
				if ( $phone && ! $existing->phone ) {
					$update['phone'] = $phone;
				}
				// Merge additional_attributes
				$old_attrs  = ( $existing->additional_attributes ) ? json_decode( $existing->additional_attributes, true ) : array();
				$new_attrs  = self::extract_additional_attributes( $mapped );
				if ( $new_attrs && is_array( $old_attrs ) ) {
					foreach ( $new_attrs as $k => $v ) {
						if ( empty( $old_attrs[ $k ] ) ) {
							$old_attrs[ $k ] = $v;
						}
					}
					$update['additional_attributes'] = wp_json_encode( $old_attrs );
				}
				// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — stamp acquisition_source on update if not yet set
				if ( empty( $existing->acquisition_source ) ) {
					$update['acquisition_source'] = 'cf7';
				}
				$wpdb->update( $contacts_t, $update, array( 'id' => $contact_id ) );
				$action = 'updated';

			// ── Step 3b: INSERT new ──────────────────────────────────────────
			} else {
				$acq_meta = array(
					'form_id'    => (int) $meta['form_id'],
					'form_title' => $meta['form_title'] ?? '',
					'sub_id'     => (int) ( $meta['sub_id'] ?? 0 ),
				);
				$insert = array(
					'name'                  => $name,
					'first_name'            => $first_name,
					'last_name'             => $last_name,
					'email'                 => $email ?: null,
					'phone'                 => $phone ?: null,
					'acquisition_source'    => 'cf7',
					'acquisition_meta_json' => wp_json_encode( $acq_meta ),
					'created_at'            => $now,
					'updated_at'            => $now,
				);
				$attrs = self::extract_additional_attributes( $mapped );
				if ( $attrs ) {
					$insert['additional_attributes'] = wp_json_encode( $attrs );
				}
				if ( ! empty( $meta['owner_id'] ) ) {
					$insert['owner_id'] = (int) $meta['owner_id'];
				}
				// Tags
				if ( ! empty( $meta['auto_tag'] ) && is_array( $meta['auto_tag'] ) ) {
					$insert['tags_json'] = wp_json_encode( array_values( array_map( 'sanitize_text_field', $meta['auto_tag'] ) ) );
				}
				$wpdb->insert( $contacts_t, $insert );
				$contact_id = (int) $wpdb->insert_id;
				$action     = 'created';
			}

			// ── Step 3c: auto-create pipeline Lead on new contact ───────────
			// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — insert bizcity_crm_leads row so
			// new CF7 contacts appear in Sales Pipeline grouped by source='cf7'.
			if ( 'created' === $action && $contact_id ) {
				self::maybe_create_lead( $contact_id, $mapped, $meta, $now );
			}

			// ── Step 4: ensure CRM Inbox row for CF7 ────────────────────────
			$form_id    = (int) $meta['form_id'];
			$channel_ref = 'cf7_' . $form_id;
			$inbox_id   = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$inboxes_t}` WHERE channel_type = 'cf7' AND channel_ref_id = %s LIMIT 1",
					$channel_ref
				)
			);
			if ( ! $inbox_id ) {
				$wpdb->insert( $inboxes_t, array(
					'name'           => sanitize_text_field( 'CF7: ' . ( $meta['form_title'] ?? $channel_ref ) ),
					'channel_type'   => 'cf7',
					'channel_ref_id' => $channel_ref,
					'is_active'      => 1,
					'created_at'     => $now,
					'updated_at'     => $now,
				) );
				$inbox_id = (int) $wpdb->insert_id;
			}

			// ── Step 5: contact_inboxes ──────────────────────────────────────
			if ( $contact_id && $inbox_id ) {
				$source_id = 'sub_' . (int) ( $meta['sub_id'] ?? 0 );
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO `{$ci_t}` (contact_id, inbox_id, source_id, created_at) VALUES (%d, %d, %s, %s)",
						$contact_id,
						$inbox_id,
						$source_id,
						$now
					)
				);
			}

		} catch ( Exception $e ) {
			$action = 'error';
			$error  = $e->getMessage();
			error_log( '[BizCity_CF7_CRM_Sync] Exception: ' . $error );
		}

		return array(
			'action'     => $action,
			'contact_id' => $contact_id,
			'error'      => $error,
		);
	}

	/**
	 * Insert a bizcity_crm_leads row for a newly-created CF7 contact.
	 * Idempotent: skips if a lead already exists with contact_id + source='cf7'.
	 *
	 * [2026-06-13 Johnny Chu] PHASE-CG-CF7
	 *
	 * @param  int    $contact_id
	 * @param  array  $mapped     Mapped CF7 fields.
	 * @param  array  $meta       {form_id, form_title, owner_id, ...}
	 * @param  string $now        MySQL datetime string.
	 */
	private static function maybe_create_lead( int $contact_id, array $mapped, array $meta, string $now ): void {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}
		global $wpdb;
		$leads_t = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $leads_t ) ) {
			return;
		}
		// Idempotency check.
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$leads_t}` WHERE contact_id = %d AND source = 'cf7' AND deleted_at IS NULL LIMIT 1",
				$contact_id
			)
		);
		if ( $existing ) {
			return;
		}
		$form_title = sanitize_text_field( $meta['form_title'] ?? 'CF7 Form' );
		$first      = sanitize_text_field( $mapped['first_name'] ?? $mapped['name'] ?? '' );
		$last       = sanitize_text_field( $mapped['last_name'] ?? '' );
		if ( ! $first && ! $last ) {
			// Try splitting name
			$parts = preg_split( '/\s+/', trim( $mapped['name'] ?? '' ), 2 );
			$first = $parts[0] ?? '';
			$last  = $parts[1] ?? '';
		}
		$wpdb->insert( $leads_t, array(
			'first_name'  => $first,
			'last_name'   => $last,
			'email'       => sanitize_email( $mapped['email'] ?? '' ) ?: null,
			'phone'       => sanitize_text_field( $mapped['phone'] ?? '' ) ?: null,
			'company'     => sanitize_text_field( $mapped['company'] ?? '' ) ?: null,
			'source'      => 'cf7',
			// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — source_ref unique per contact to avoid UNIQUE(source,source_ref) collision when multiple contacts fill the same form
			'source_ref'  => 'contact:' . $contact_id . ':form:' . (int) ( $meta['form_id'] ?? 0 ),
			'status'      => 'new',
			'owner_id'    => ! empty( $meta['owner_id'] ) ? (int) $meta['owner_id'] : null,
			'contact_id'  => $contact_id,
			'notes'       => 'Lead tự động từ form CF7: ' . $form_title,
			'custom_json' => wp_json_encode( array(
				'origin'     => 'cf7_channel',
				'form_id'    => (int) ( $meta['form_id'] ?? 0 ),
				'form_title' => $form_title,
				'sub_id'     => (int) ( $meta['sub_id'] ?? 0 ),
			) ),
			'created_by'  => null,
			'created_at'  => $now,
			'updated_at'  => $now,
		) );
	}

	/**
	 * Extract `additional_attributes.*` keys from mapped data.
	 */
	private static function extract_additional_attributes( array $mapped ): array {
		$attrs = array();
		foreach ( $mapped as $k => $v ) {
			if ( strpos( $k, 'additional_attributes.' ) === 0 ) {
				$sub_key           = substr( $k, strlen( 'additional_attributes.' ) );
				$attrs[ $sub_key ] = sanitize_text_field( $v );
			}
		}
		return $attrs;
	}
}
