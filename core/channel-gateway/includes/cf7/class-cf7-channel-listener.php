<?php
/**
 * CF7 Channel — Channel Listener
 *
 * Hooks wpcf7_mail_sent → extract fields → log → CRM sync → Listener Bus trace.
 *
 * @package BizCity_Channel_Gateway
 * @since   2026-06-13
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_CF7_Channel_Listener {

	/** Option key for per-form field mapping config. */
	const MAPPING_OPTION = 'bizcity_cg_cf7_mappings';

	/** Max submissions to sync per form per hour (rate limit). */
	const RATE_LIMIT_PER_HOUR = 50;

	public static function init(): void {
		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — hook CF7 mail_sent
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_submit' ), 10, 1 );
	}

	/**
	 * Main handler: fires on every successful CF7 submission.
	 *
	 * @param WPCF7_ContactForm $cf7
	 */
	public static function on_submit( $cf7 ): void {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}

		$form_id    = (int) $cf7->id();
		$form_title = (string) $cf7->title();
		$posted     = $submission->get_posted_data();

		if ( ! is_array( $posted ) ) {
			return;
		}

		// ── Load field mapping ────────────────────────────────────────────
		$mapping = self::get_form_mapping( $form_id );

		// ── Apply mapping ─────────────────────────────────────────────────
		$mapped = self::apply_mapping( $posted, $mapping['field_map'] ?? array() );

		// ── Extract identity ──────────────────────────────────────────────
		$email = sanitize_email( $mapped['email'] ?? '' );
		$phone = preg_replace( '/[^0-9+\-() ]/', '', $mapped['phone'] ?? '' );
		$phone = substr( $phone, 0, 32 );

		// Guard: need at least one identity
		if ( empty( $email ) && empty( $phone ) ) {
			return;
		}

		// ── Rate limit ────────────────────────────────────────────────────
		if ( self::is_rate_limited( $form_id ) ) {
			error_log( "[BizCity_CF7] Rate limit hit for form {$form_id} — skipping CRM sync." );
			// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — file-log rate limit
			if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
				BizCity_Channel_File_Logger::write(
					BizCity_Channel_File_Logger::CH_CF7,
					BizCity_Channel_File_Logger::LEVEL_WARN,
					'cf7_rate_limited',
					'Rate limit hit for form #' . $form_id,
					array( 'form_id' => $form_id, 'form_title' => $form_title )
				);
			}
			// Still log the submission as rate_limited
			$email_raw = $email;
			$phone_raw = $phone;
			$email     = '';
			$phone     = '';
		} else {
			$email_raw = $email;
			$phone_raw = $phone;
		}

		// ── Collect source meta ───────────────────────────────────────────
		$source_url = '';
		if ( method_exists( $submission, 'get_meta' ) ) {
			$source_url = (string) $submission->get_meta( 'url' );
		}
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$ip_address = self::get_client_ip();

		// ── Log submission ────────────────────────────────────────────────		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — file-log before any DB (R-CH-FILE-LOG)
		if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_CF7,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'cf7_form_received',
				'CF7 form #' . $form_id . ' submitted',
				array(
					'form_id'    => $form_id,
					'form_title' => $form_title,
					'has_email'  => ! empty( $email_raw ),
					'has_phone'  => ! empty( $phone_raw ),
				)
			);
		}
		$sub_id = BizCity_CF7_Submissions_Log::insert( array(
			'form_id'    => $form_id,
			'form_title' => $form_title,
			'raw_data'   => $posted,
			'mapped_data'=> $mapped,
			'email'      => $email_raw ?: null,
			'phone'      => $phone_raw ?: null,
			'source_url' => $source_url,
			'user_agent' => $user_agent,
			'ip_address' => $ip_address,
		) );

		// ── CRM Sync ──────────────────────────────────────────────────────
		$crm_result = array( 'action' => 'skipped', 'contact_id' => 0, 'error' => null );

		if ( $email || $phone ) {
			if ( BizCity_CF7_CRM_Sync::is_available() ) {
				$crm_result = BizCity_CF7_CRM_Sync::upsert(
					$email,
					$phone,
					$mapped,
					array(
						'form_id'    => $form_id,
						'form_title' => $form_title,
						'sub_id'     => $sub_id,
						'auto_tag'   => $mapping['auto_tag'] ?? array(),
						'owner_id'   => $mapping['default_owner_id'] ?? 0,
					)
				);
			}
		} else {
			$crm_result['action'] = 'rate_limited';
		}

		// ── Update submission with CRM result ─────────────────────────────
		if ( $sub_id ) {
			BizCity_CF7_Submissions_Log::update_crm_result( $sub_id, $crm_result );
		}

		// ── M1.7: Sync to unified bizcity_crm_submissions ─────────────────
		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — create/update unified lead row.
		// Enriches source_meta_json with UTM/referrer/device from JS tracker.
		if ( $sub_id && class_exists( 'BizCity_CRM_Submissions_Repo' ) ) {
			$_src_meta = array( 'form_id' => $form_id, 'form_title' => $form_title );
			if ( class_exists( 'BizCity_Lead_Source_Tracker' ) ) {
				$_src_meta = BizCity_Lead_Source_Tracker::capture_from_request( $_src_meta );
			}
			BizCity_CRM_Submissions_Repo::sync_from_cf7(
				$sub_id,
				array(
					'email'        => $email_raw ?: '',
					'phone'        => $phone_raw ?: '',
					'name'         => $mapped['name'] ?? '',
					'form_id'      => $form_id,
					'form_title'   => $form_title,
					'contact_id'   => (int) ( $crm_result['contact_id'] ?? 0 ),
					'submitted_at' => current_time( 'mysql' ),
					'source_meta'  => $_src_meta,
				)
			);
			unset( $_src_meta );
		}

		// ── Zalo ZNS auto-reply ───────────────────────────────────────────
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — dispatch ZNS after CRM sync
		// IMPORTANT: pass $posted (raw CF7 field names like 'parent-name', 'your-phone')
		// NOT $mapped (CRM-mapped keys like 'email', 'phone') — temp_vars config references raw CF7 field names.
		if ( class_exists( 'BizCity_CF7_ZNS_Sender', false ) && ! empty( $phone_raw ) ) {
			// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS DEBUG — log before dispatch
			error_log( '[bizcity-zns] on_submit: dispatching ZNS for form_id=' . $form_id . ' phone=' . self::mask_phone( $phone_raw ) . ' posted_keys=' . implode( ',', array_keys( $posted ) ) );
			BizCity_CF7_ZNS_Sender::dispatch( $form_id, $phone_raw, $posted );
		} else {
			// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS DEBUG — log why ZNS skipped
			error_log( '[bizcity-zns] on_submit: ZNS skipped — sender_loaded=' . ( class_exists( 'BizCity_CF7_ZNS_Sender', false ) ? '1' : '0' ) . ' phone_raw=' . ( ! empty( $phone_raw ) ? 'non-empty' : 'EMPTY' ) );
		}

		// ── Emit trace to Listener Bus ────────────────────────────────────
		// [2026-06-13 Johnny Chu] HOTFIX — emit() takes single array arg; was called with (string, array) → TypeError fatal.
		if ( class_exists( 'BizCity_Listener_Bus' ) ) {
			BizCity_Listener_Bus::emit( array(
				'kind'       => 'system',
				'platform'   => 'CF7',
				'account_id' => (string) $form_id,
				'user_id'    => '',
				'chat_id'    => 'cf7_' . $form_id,
				'event_type' => 'cf7_submit',
				'direction'  => 'inbound',
				'message'    => $form_title,
				'meta'       => array(
					'form_id'    => $form_id,
					'form_title' => $form_title,
					// Mask email/phone in trace (OWASP PII protection)
					'email'      => $email_raw ? self::mask_email( $email_raw ) : '',
					'phone'      => $phone_raw ? self::mask_phone( $phone_raw ) : '',
					'crm_action' => $crm_result['action'],
					'contact_id' => (int) $crm_result['contact_id'],
					'sub_id'     => $sub_id,
				),
			) );
		}

		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 — server-side tracking event evidence
		// BUGFIX: check actual pixel values, not just array non-empty (all-empty-string array passes !empty())
		$page_tracking = isset( $mapping['page_tracking'] ) && is_array( $mapping['page_tracking'] )
			? $mapping['page_tracking']
			: array();
		$has_any_pixel = ! empty( $page_tracking['fb_pixel_id'] )
			|| ! empty( $page_tracking['ga_id'] )
			|| ! empty( $page_tracking['gtm_id'] );
		if ( $has_any_pixel && class_exists( 'BizCity_Channel_File_Logger', false ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_CF7,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'cf7_tracking_fired',
				'PB tracking event for form #' . $form_id,
				array(
					'form_id'        => $form_id,
					'tracking_event' => $page_tracking['tracking_event'] ?? '',
					'has_fb_pixel'   => ! empty( $page_tracking['fb_pixel_id'] ),
					'has_ga4'        => ! empty( $page_tracking['ga_id'] ),
					'has_gtm'        => ! empty( $page_tracking['gtm_id'] ),
				)
			);
		}
	}

	// ── Mapping helpers ───────────────────────────────────────────────────

	/**
	 * Load field mapping config for a form. Falls back to auto-suggest.
	 *
	 * @param  int $form_id
	 * @return array {field_map, auto_tag, default_owner_id, enabled, auto_suggested}
	 */
	public static function get_form_mapping( int $form_id ): array {
		$all = get_option( self::MAPPING_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$key = (string) $form_id;
		if ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		// Auto-suggest from CF7 field names
		return array(
			'field_map'       => array(),
			'auto_tag'        => array( 'cf7', 'lead' ),
			'default_owner_id'=> 0,
			'enabled'         => true,
			'auto_suggested'  => true,
		);
	}

	/**
	 * Save field mapping config for a form.
	 *
	 * @param  int   $form_id
	 * @param  array $config
	 */
	public static function save_form_mapping( int $form_id, array $config ): void {
		$all = get_option( self::MAPPING_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$key        = (string) $form_id;
		$all[ $key ] = array(
			'form_id'          => $form_id,
			'form_title'       => sanitize_text_field( $config['form_title'] ?? '' ),
			'enabled'          => ! empty( $config['enabled'] ),
			'field_map'        => is_array( $config['field_map'] ?? null ) ? array_map( 'sanitize_text_field', $config['field_map'] ) : array(),
			'auto_tag'         => isset( $config['auto_tag'] ) && is_array( $config['auto_tag'] ) ? array_map( 'sanitize_text_field', $config['auto_tag'] ) : array(),
			'default_owner_id' => (int) ( $config['default_owner_id'] ?? 0 ),
			'updated_at'       => current_time( 'c' ),
		);
		update_option( self::MAPPING_OPTION, $all, false );
	}

	/**
	 * Apply field_map to posted data.
	 * field_map: { 'cf7-field-name' => 'crm_field_path' }
	 * Returns flat array with CRM field paths as keys.
	 *
	 * @param  array $posted    CF7 raw posted data.
	 * @param  array $field_map Mapping config.
	 * @return array
	 */
	public static function apply_mapping( array $posted, array $field_map ): array {
		if ( empty( $field_map ) ) {
			// Auto-detect smart defaults
			$field_map = self::auto_detect_mapping( $posted );
		}

		$result = array();
		foreach ( $field_map as $cf7_field => $crm_path ) {
			if ( '_skip_' === $crm_path || empty( $crm_path ) ) {
				continue;
			}
			$raw_value = $posted[ $cf7_field ] ?? '';
			if ( is_array( $raw_value ) ) {
				$raw_value = implode( ', ', $raw_value );
			}
			$result[ $crm_path ] = sanitize_text_field( (string) $raw_value );
		}
		return $result;
	}

	/**
	 * Auto-detect CRM field from CF7 field name patterns.
	 */
	public static function auto_detect_mapping( array $posted ): array {
		$map = array();
		foreach ( array_keys( $posted ) as $cf7_field ) {
			$lower = strtolower( $cf7_field );
			if ( strpos( $lower, 'email' ) !== false || strpos( $lower, 'mail' ) !== false ) {
				$map[ $cf7_field ] = 'email';
			} elseif ( strpos( $lower, 'phone' ) !== false || strpos( $lower, 'tel' ) !== false || strpos( $lower, 'mobile' ) !== false || strpos( $lower, 'sdt' ) !== false ) {
				$map[ $cf7_field ] = 'phone';
			} elseif ( strpos( $lower, 'firstname' ) !== false || ( strpos( $lower, 'first' ) !== false && strpos( $lower, 'name' ) !== false ) ) {
				$map[ $cf7_field ] = 'first_name';
			} elseif ( strpos( $lower, 'lastname' ) !== false || ( strpos( $lower, 'last' ) !== false && strpos( $lower, 'name' ) !== false ) ) {
				$map[ $cf7_field ] = 'last_name';
			} elseif ( strpos( $lower, 'name' ) !== false || strpos( $lower, 'ten' ) !== false ) {
				$map[ $cf7_field ] = 'name';
			} elseif ( strpos( $lower, 'company' ) !== false || strpos( $lower, 'cong-ty' ) !== false || strpos( $lower, 'congty' ) !== false ) {
				$map[ $cf7_field ] = 'additional_attributes.company';
			} elseif ( strpos( $lower, 'message' ) !== false || strpos( $lower, 'noi-dung' ) !== false || strpos( $lower, 'noidung' ) !== false ) {
				$map[ $cf7_field ] = 'additional_attributes.message';
			} else {
				$map[ $cf7_field ] = '_skip_';
			}
		}
		return $map;
	}

	/**
	 * Get all CF7 form posts with stats.
	 *
	 * @return array
	 */
	public static function get_all_forms(): array {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}
		$posts = get_posts( array(
			'post_type'      => 'wpcf7_contact_form',
			'posts_per_page' => 100,
			'post_status'    => 'publish',
		) );
		if ( ! is_array( $posts ) ) {
			return array();
		}

		$all_mappings = get_option( self::MAPPING_OPTION, array() );
		if ( ! is_array( $all_mappings ) ) {
			$all_mappings = array();
		}

		$forms = array();
		foreach ( $posts as $p ) {
			$form_id = (int) $p->ID;
			$config  = $all_mappings[ (string) $form_id ] ?? null;
			$count   = BizCity_CF7_Submissions_Log::count( $form_id );

			$fields = array();
			if ( class_exists( 'WPCF7_ContactForm' ) ) {
				$cf7 = WPCF7_ContactForm::get_instance( $form_id );
				if ( $cf7 ) {
					foreach ( $cf7->scan_form_tags() as $tag ) {
						if ( $tag->name ) {
							$fields[] = $tag->name;
						}
					}
				}
			}

			$forms[] = array(
				'form_id'              => $form_id,
				'form_title'           => $p->post_title,
				'enabled'              => $config ? ! empty( $config['enabled'] ) : true,
				'submission_count'     => $count,
				'last_submitted_at'    => null, // expensive — omit in list
				'field_map_configured' => $config && ! empty( $config['field_map'] ),
				'fields'               => $fields,
			);
		}
		return $forms;
	}

	// ── Rate limiting ─────────────────────────────────────────────────────

	private static function is_rate_limited( int $form_id ): bool {
		$key = 'bizcity_cf7_rl_' . $form_id;
		$cnt = (int) get_transient( $key );
		if ( $cnt >= self::RATE_LIMIT_PER_HOUR ) {
			return true;
		}
		set_transient( $key, $cnt + 1, HOUR_IN_SECONDS );
		return false;
	}

	// ── Privacy helpers ───────────────────────────────────────────────────

	private static function mask_email( string $email ): string {
		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}
		$local  = $parts[0];
		$domain = $parts[1];
		return substr( $local, 0, min( 3, strlen( $local ) ) ) . '***@' . $domain;
	}

	private static function mask_phone( string $phone ): string {
		$clean = preg_replace( '/\D/', '', $phone );
		$len   = strlen( $clean );
		if ( $len <= 4 ) {
			return '***';
		}
		return substr( $clean, 0, 3 ) . str_repeat( '*', $len - 4 ) . substr( $clean, -1 );
	}

	private static function get_client_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $hdr ) {
			if ( ! empty( $_SERVER[ $hdr ] ) ) {
				$ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER[ $hdr ] )[0] ) );
				return substr( trim( $ip ), 0, 45 );
			}
		}
		return '';
	}
}
