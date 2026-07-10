<?php
/**
 * Email SMTP REST Controller (PHASE-CG-SMTP-INTEGRATION v1.0)
 *
 * Namespace: bizcity-channel/v1
 * Routes:
 *   POST /email-smtp/test-send    — send test email to a given address
 *   GET  /email-smtp/contacts     — WP users + CF7 entries with email/phone
 *   GET  /email-smtp/stats        — delivery statistics
 *
 * All routes require manage_options capability (admin-only).
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE-CG-SMTP-INTEGRATION v1.0 (2026-06-10)
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-10 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — Email SMTP REST controller.
class BizCity_Email_SMTP_REST {

	const NS = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		// POST /email-smtp/test-send
		register_rest_route( self::NS, '/email-smtp/test-send', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'test_send' ],
			'permission_callback' => [ __CLASS__, 'require_manage_options' ],
			'args'                => [
				'to'         => [ 'required' => true,  'sanitize_callback' => 'sanitize_email' ],
				'subject'    => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'body'       => [ 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ],
				'uid'        => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				// [2026-06-20 Johnny Chu] R-UNIFY — prefer CRM account_id (int) over CG uid (string)
				'account_id' => [ 'required' => false, 'sanitize_callback' => 'absint', 'default' => 0 ],
			],
		] );

		// [2026-06-20 Johnny Chu] R-UNIFY — GET /email-smtp/crm-accounts
		// Returns Gmail SMTP accounts from Twin CRM table (canonical source).
		register_rest_route( self::NS, '/email-smtp/crm-accounts', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_crm_accounts' ],
			'permission_callback' => [ __CLASS__, 'require_manage_options' ],
		] );

		// GET /email-smtp/contacts
		register_rest_route( self::NS, '/email-smtp/contacts', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_contacts' ],
			'permission_callback' => [ __CLASS__, 'require_manage_options' ],
			'args'                => [
				'limit' => [ 'required' => false, 'sanitize_callback' => 'absint', 'default' => 100 ],
			],
		] );

		// GET /email-smtp/stats
		register_rest_route( self::NS, '/email-smtp/stats', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_stats' ],
			'permission_callback' => [ __CLASS__, 'require_manage_options' ],
		] );

		// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — GET /email-smtp/send-logs (reads from JSONL)
		register_rest_route( self::NS, '/email-smtp/send-logs', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_send_logs' ],
			'permission_callback' => [ __CLASS__, 'require_manage_options' ],
		] );
	}

	/**
	 * POST /email-smtp/test-send
	 *
	 * [2026-06-20 Johnny Chu] R-UNIFY — delegates to BizCity_CRM_Gmail_SMTP_Repo::send_via()
	 * when Twin CRM is loaded (canonical storage). Falls back to CG integration registry.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function test_send( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Email_SMTP_Integration' ) ) {
			return rest_ensure_response( [
				'success' => false,
				'error'   => 'BizCity_Email_SMTP_Integration not loaded.',
			] );
		}

		$to         = (string) $request->get_param( 'to' );
		$uid        = (string) $request->get_param( 'uid' );
		$subject    = (string) $request->get_param( 'subject' );
		$body       = (string) $request->get_param( 'body' );
		// [2026-06-20 Johnny Chu] R-UNIFY — prefer CRM account_id; fall back to uid
		$account_id = (int) $request->get_param( 'account_id' );

		if ( ! is_email( $to ) ) {
			return rest_ensure_response( [
				'success' => false,
				'error'   => 'Địa chỉ email không hợp lệ: ' . esc_html( $to ),
			] );
		}

		if ( ! $subject ) {
			$subject = '[' . get_bloginfo( 'name' ) . '] Test email từ Twin SMTP — ' . current_time( 'Y-m-d H:i:s' );
		}
		if ( ! $body ) {
			$body = "Đây là email test gửi từ Twin SMTP (BizCity Twin AI).\n\nThời gian: " . current_time( 'Y-m-d H:i:s' ) . "\nSite: " . home_url();
		}

		// [2026-06-20 Johnny Chu] R-UNIFY — CRM table is canonical. When available, use
		// BizCity_CRM_Gmail_SMTP_Repo::send_via() directly instead of CG integration.
		if ( class_exists( 'BizCity_CRM_Gmail_SMTP_Repo' ) ) {
			// Resolve account_id: explicit > default > first active
			if ( $account_id <= 0 && $uid !== '' ) {
				$account_id = (int) $uid; // CG uid may be int-string from CRM
			}
			if ( $account_id <= 0 ) {
				$acct = BizCity_CRM_Gmail_SMTP_Repo::get_default( false );
				if ( ! $acct ) {
					$all = BizCity_CRM_Gmail_SMTP_Repo::list_accounts();
					foreach ( $all as $a ) {
						if ( ! empty( $a['is_active'] ) ) { $acct = $a; break; }
					}
				}
				$account_id = $acct ? (int) $acct['id'] : 0;
			}
			if ( $account_id > 0 ) {
				$result = BizCity_CRM_Gmail_SMTP_Repo::send_via( $account_id, array(
					'to'         => array( $to ),
					'subject'    => $subject,
					'body'       => $body,
					'is_html'    => false,
					'debug_smtp' => true,
				) );
				return rest_ensure_response( [
					'success'  => ! empty( $result['ok'] ),
					'error'    => (string) ( $result['error'] ?? '' ),
					'smtp_log' => $result['smtp_log'] ?? array(),
					'source'   => 'crm_table',
				] );
			}
		}

		// Fallback: CG integration registry (uid-based)
		$result = BizCity_Email_SMTP_Integration::api_test_send( $uid, $to, $subject, $body );
		return rest_ensure_response( [
			'success'  => ! empty( $result['ok'] ),
			'error'    => (string) ( $result['error'] ?? '' ),
			'smtp_log' => $result['smtp_log'] ?? array(),
			'source'   => 'cg_registry',
		] );
	}

	/**
	 * GET /email-smtp/crm-accounts
	 *
	 * [2026-06-20 Johnny Chu] R-UNIFY — returns Gmail SMTP accounts from Twin CRM table.
	 * Channel Gateway FE uses this to populate account picker in test-send panel.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_crm_accounts( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_CRM_Gmail_SMTP_Repo' ) ) {
			return rest_ensure_response( [
				'success'  => true,
				'accounts' => array(),
				'source'   => 'none',
				'note'     => 'Twin CRM plugin not loaded.',
			] );
		}
		$accounts = BizCity_CRM_Gmail_SMTP_Repo::list_accounts();
		return rest_ensure_response( [
			'success'  => true,
			'accounts' => $accounts,
			'source'   => 'crm_table',
		] );
	}

	/**
	 * GET /email-smtp/contacts
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_contacts( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Email_SMTP_Integration' ) ) {
			return rest_ensure_response( [
				'success'  => false,
				'contacts' => [],
				'error'    => 'BizCity_Email_SMTP_Integration not loaded.',
			] );
		}

		$limit    = min( 500, max( 1, (int) $request->get_param( 'limit' ) ) );
		$contacts = BizCity_Email_SMTP_Integration::api_get_contacts( $limit );

		return rest_ensure_response( [
			'success'  => true,
			'contacts' => $contacts,
			'count'    => count( $contacts ),
		] );
	}

	/**
	 * GET /email-smtp/stats
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_stats( WP_REST_Request $request ) {
		if ( ! class_exists( 'BizCity_Email_SMTP_Integration' ) ) {
			return rest_ensure_response( [
				'success' => false,
				'stats'   => [],
				'error'   => 'BizCity_Email_SMTP_Integration not loaded.',
			] );
		}

		$stats = BizCity_Email_SMTP_Integration::api_get_stats();

		return rest_ensure_response( [
			'success' => true,
			'stats'   => $stats,
		] );
	}

	/**
	 * GET /email-smtp/send-logs
	 * Reads send history from JSONL channel log files.
	 *
	 * [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_send_logs( WP_REST_Request $request ) {
		$period   = (string) ( $request->get_param( 'period' ) ?: '7d' );
		$per_page = min( 200, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$status   = (string) ( $request->get_param( 'status' ) ?: '' );

		// Delegate to BizCity_CRM_Email_Send_Log if available (already has JSONL-first logic)
		if ( class_exists( 'BizCity_CRM_Email_Send_Log' ) ) {
			$args = array(
				'per_page' => $per_page,
				'page'     => $page,
				'status'   => $status,
				'is_test'  => 0,
			);
			$list  = BizCity_CRM_Email_Send_Log::list_logs( $args );
			$stats = BizCity_CRM_Email_Send_Log::get_stats( $period );
			return rest_ensure_response( [
				'success' => true,
				'rows'    => $list['rows']  ?? [],
				'total'   => $list['total'] ?? 0,
				'stats'   => $stats,
			] );
		}

		// Fallback: read directly from JSONL file
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return rest_ensure_response( [ 'success' => true, 'rows' => [], 'total' => 0, 'stats' => [] ] );
		}

		$entries  = BizCity_Channel_File_Logger::read( BizCity_Channel_File_Logger::CH_EMAIL, '', 200 );
		$rows     = [];
		$id       = 1;
		foreach ( $entries as $entry ) {
			$event = (string) ( $entry['event'] ?? '' );
			if ( ! in_array( $event, [ 'send_ok', 'send_failed', 'send_skipped' ], true ) ) { continue; }
			$ev_status = $event === 'send_ok' ? 'sent' : ( $event === 'send_failed' ? 'failed' : 'skipped' );
			if ( $status !== '' && $status !== $ev_status ) { continue; }
			$ctx = is_array( $entry['ctx'] ?? null ) ? (array) $entry['ctx'] : [];
			$rows[] = [
				'id'              => $id++,
				'sent_at'         => (string) ( $entry['ts'] ?? '' ),
				'rule_id'         => (int)    ( $ctx['rule_id'] ?? 0 ),
				'rule_name'       => (string) ( $ctx['rule_name'] ?? '' ),
				'event_key'       => (string) ( $ctx['event_key'] ?? '' ),
				'recipient_email' => (string) ( $ctx['to'] ?? ( $ctx['recipient_email'] ?? '' ) ),
				'subject'         => (string) ( $ctx['subject'] ?? '' ),
				'status'          => $ev_status,
				'error_message'   => (string) ( $ctx['error'] ?? '' ),
				'smtp_source'     => (string) ( $ctx['smtp_source'] ?? '' ),
				'has_attachment'  => empty( $ctx['has_attachment'] ) ? 0 : 1,
			];
		}
		return rest_ensure_response( [ 'success' => true, 'rows' => $rows, 'total' => count( $rows ), 'stats' => [] ] );
	}

	/**
	 * Permission callback — require manage_options.
	 *
	 * @return bool|WP_Error
	 */
	public static function require_manage_options() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				'Bạn không có quyền truy cập.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}
}
