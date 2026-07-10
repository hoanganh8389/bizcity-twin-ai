<?php
/**
 * Email / SMTP Channel Integration (PHASE-CG-SMTP-INTEGRATION v1.0)
 *
 * Full BizCity_Channel_Integration implementation for outbound email via SMTP.
 * Supports multiple SMTP accounts (multi-label: noreply, support, marketing…).
 * Each account stores credentials encrypted via the parent BizCity_Integration
 * mechanism and can be marked active / default.
 *
 * Inbound (webhook): email has no inbound webhook in this iteration — both
 *   verify_webhook() and normalize_inbound() are no-ops that return safe defaults.
 *
 * Outbound (send_outbound): configures PHPMailer via phpmailer_init at priority
 *   999 (same as core/smtp BizCity_SMTP::bind()) and calls wp_mail().
 *
 * Health / do_test: validates that config is present and sends a 1-line ping
 *   email to the current admin user.
 *
 * REST:
 *   POST /bizcity-channel/v1/email-smtp/test-send   — test send to arbitrary email
 *   GET  /bizcity-channel/v1/email-smtp/contacts    — WP users + CF7 entries with email
 *   GET  /bizcity-channel/v1/email-smtp/stats       — delivery statistics
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE-CG-SMTP-INTEGRATION v1.0 (2026-06-10)
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-10 Johnny Chu] PHASE-CG-SMTP-INTEGRATION — Email SMTP full integration class.
class BizCity_Email_SMTP_Integration extends BizCity_Channel_Integration {

	protected string $code           = 'email_smtp';
	protected string $platform       = 'EMAIL';
	protected string $name           = 'Email SMTP';
	protected string $desc           = 'Gửi email outbound qua SMTP (Gmail, SendGrid, Mailgun…).';
	protected string $logo           = 'mail';
	protected string $default_role   = 'cskh';
	protected string $chat_id_prefix = 'email_';
	protected int    $order          = 30;

	/** @var array Field schema */
	protected array $settings = [
		'label' => [
			'type'     => 'text',
			'label'    => 'Nhãn tài khoản',
			'desc'     => 'VD: Gmail công ty, Support, Noreply…',
			'default'  => '',
			'required' => true,
			'plh'      => 'VD: Gmail công ty',
		],
		'smtp_host' => [
			'type'     => 'text',
			'label'    => 'SMTP Host',
			'desc'     => 'VD: smtp.gmail.com, smtp.sendgrid.net, smtp.mailgun.org',
			'default'  => 'smtp.gmail.com',
			'required' => true,
			'plh'      => 'smtp.gmail.com',
		],
		'smtp_port' => [
			'type'    => 'number',
			'label'   => 'Port',
			'desc'    => '587 (TLS) · 465 (SSL) · 25 (không mã hoá)',
			'default' => 587,
		],
		'smtp_user' => [
			'type'     => 'text',
			'label'    => 'Gmail / Username',
			'desc'     => 'Địa chỉ email hoặc username đăng nhập SMTP.',
			'default'  => '',
			'required' => true,
			'plh'      => 'you@gmail.com',
		],
		'smtp_pass' => [
			'type'     => 'password',
			'label'    => 'App Password',
			'desc'     => '16 ký tự App Password (có thể có khoảng trắng).',
			'default'  => '',
			'encrypt'  => true,
			'required' => true,
			'plh'      => 'xxxx xxxx xxxx xxxx',
		],
		'from_email' => [
			'type'    => 'email',
			'label'   => 'From Email',
			'desc'     => 'Địa chỉ From: (mặc định = smtp_user).',
			'default' => '',
			'plh'     => '(mặc định = username)',
		],
		'from_name' => [
			'type'    => 'text',
			'label'   => 'From Name',
			'desc'    => 'Tên hiển thị trong trường From:.',
			'default' => '',
			'plh'     => 'BizCity',
		],
		'security' => [
			'type'    => 'select',
			'label'   => 'Bảo mật',
			'default' => 'tls',
			'options' => [
				'tls' => 'TLS (587)',
				'ssl' => 'SSL (465)',
				''    => 'Không (25)',
			],
		],
		'auth' => [
			'type'    => 'checkbox',
			'label'   => 'Bật SMTP authentication',
			'default' => 1,
		],
		'is_default' => [
			'type'    => 'checkbox',
			'label'   => 'Đặt làm mặc định',
			'default' => 0,
		],
		'active' => [
			'type'    => 'checkbox',
			'label'   => 'Kích hoạt',
			'default' => 1,
		],
	];

	protected array $private_params = [ 'smtp_pass' ];
	protected array $signal_params  = [ 'smtp_host', 'smtp_user', 'smtp_port' ];

	/** @var array Trigger blocks — email has no inbound, so empty. */
	protected array $trigger_blocks = [];

	/** @var array Action blocks for automation workflows. */
	protected array $action_blocks = [ 'wa_send_email_smtp' ];

	/* ═══════════════════════════════════════════
	 *  Inbound (no-ops — email has no inbound webhook)
	 * ═══════════════════════════════════════════ */

	/**
	 * Email has no inbound webhook — always return true (accept anything).
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function verify_webhook( WP_REST_Request $request ) {
		return true;
	}

	/**
	 * Email has no inbound webhook — return empty array to skip.
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account
	 * @return array
	 */
	public function normalize_inbound( WP_REST_Request $request, array $account ): array {
		return [];
	}

	/* ═══════════════════════════════════════════
	 *  Outbound — send_outbound
	 * ═══════════════════════════════════════════ */

	/**
	 * Send an email via the account's SMTP settings.
	 *
	 * Envelope keys used:
	 *   recipient — To: email address
	 *   text      — Email body (plain text or HTML)
	 *   meta.subject — Subject line (optional, defaults to site name)
	 *   meta.html    — If truthy, Content-Type: text/html
	 *
	 * @param array $msg     Outbound envelope.
	 * @param array $account Decrypted account credentials.
	 * @return array|WP_Error
	 */
	public function send_outbound( array $msg, array $account ) {
		$to = isset( $msg['recipient'] ) ? (string) $msg['recipient'] : '';

		// Special ping for do_test()
		if ( $to === '__ping__' ) {
			return $this->smtp_ping( $account );
		}

		if ( ! is_email( $to ) ) {
			return new WP_Error( 'invalid_recipient', 'Địa chỉ email không hợp lệ: ' . $to );
		}

		$subject = isset( $msg['meta']['subject'] )
			? (string) $msg['meta']['subject']
			: '[' . get_bloginfo( 'name' ) . '] Tin nhắn từ bot';
		$body    = isset( $msg['text'] ) ? (string) $msg['text'] : '';
		$is_html = ! empty( $msg['meta']['html'] );
		$headers = $is_html ? [ 'Content-Type: text/html; charset=UTF-8' ] : [ 'Content-Type: text/plain; charset=UTF-8' ];

		$err  = '';
		$ok   = false;
		$cb   = function ( $wp_error ) use ( &$err ) {
			if ( $wp_error instanceof WP_Error ) {
				$err = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $cb );

		// Temporarily configure phpmailer with this account.
		$init_cb = $this->make_phpmailer_hook( $account );
		add_action( 'phpmailer_init', $init_cb, 999 );
		$ok = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'phpmailer_init', $init_cb, 999 );
		remove_action( 'wp_mail_failed', $cb );

		if ( ! $ok && $err ) {
			return new WP_Error( 'smtp_send_failed', $err );
		}

		return [ 'sent' => $ok, 'error' => $err, 'platform' => 'EMAIL', 'mid' => '' ];
	}

	/* ═══════════════════════════════════════════
	 *  Health
	 * ═══════════════════════════════════════════ */

	/**
	 * Health check: verify required fields are populated.
	 *
	 * @return array
	 */
	public function health(): array {
		$account = $this->get_account();
		$ok      = ! empty( $account['smtp_host'] ) && ! empty( $account['smtp_user'] );
		return [
			'ok'               => $ok,
			'latency_ms'       => 0,
			'last_error'       => (string) ( $account['_status_error'] ?? '' ),
			'last_success_at'  => (string) ( $account['_last_success_at'] ?? '' ),
			'token_expires_at' => '',
		];
	}

	/* ═══════════════════════════════════════════
	 *  Connection test
	 * ═══════════════════════════════════════════ */

	/**
	 * Override do_test() — sends a ping email to the current admin user.
	 */
	public function do_test(): void {
		$account = $this->get_decrypted_params( true );
		$result  = $this->smtp_ping( $account );

		if ( is_wp_error( $result ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $result->get_error_message();
		} else {
			$ok = ! empty( $result['sent'] );
			$this->account['_status']       = $ok ? 1 : 7;
			$this->account['_status_error'] = $ok ? '' : (string) ( $result['error'] ?? '' );
		}
		if ( isset( $ok ) && $ok ) {
			$this->account['_last_success_at'] = gmdate( 'Y-m-d H:i:s' );
		}
	}

	/* ═══════════════════════════════════════════
	 *  Static helpers — public API used by REST
	 * ═══════════════════════════════════════════ */

	/**
	 * Send a test email to $to using the named account (by _uid).
	 *
	 * @param string $uid     Account _uid ('' = default account).
	 * @param string $to      Recipient email.
	 * @param string $subject Subject line.
	 * @param string $body    Email body.
	 * @return array{ok:bool,error:string}
	 */
	public static function api_test_send( string $uid, string $to, string $subject, string $body ): array {
		// [2026-06-20 Johnny Chu] R-UNIFY — CRM table is canonical SMTP account storage.
		// When BizCity_CRM_Gmail_SMTP_Repo is available, resolve account from CRM table first.
		// uid may be (a) int-string CRM account_id, (b) empty → use default/first, (c) CG _uid.
		$account_row = null;

		if ( class_exists( 'BizCity_CRM_Gmail_SMTP_Repo' ) ) {
			$crm_id = (int) $uid;
			if ( $crm_id > 0 ) {
				$account_row = BizCity_CRM_Gmail_SMTP_Repo::get( $crm_id, true );
			}
			if ( ! $account_row ) {
				$account_row = BizCity_CRM_Gmail_SMTP_Repo::get_default( true );
			}
			if ( ! $account_row ) {
				$all = BizCity_CRM_Gmail_SMTP_Repo::list_accounts();
				foreach ( $all as $a ) {
					if ( ! empty( $a['is_active'] ) ) {
						$account_row = BizCity_CRM_Gmail_SMTP_Repo::get( (int) $a['id'], true );
						break;
					}
				}
			}
		}

		// Fallback: CG integration registry (old options-based storage)
		if ( ! $account_row ) {
			$integration = self::_get_instance( $uid );
			if ( ! $integration ) {
				return [ 'ok' => false, 'error' => 'SMTP account not found. Vui lòng cấu hình tài khoản Gmail SMTP trong CRM → Email Client.' ];
			}
			$p = $integration->get_decrypted_params( true );
			// Normalize CG param keys → CRM-compatible keys
			$account_row = array(
				'smtp_host'   => (string) ( $p['smtp_host'] ?? 'smtp.gmail.com' ),
				'smtp_port'   => (int)    ( $p['smtp_port'] ?? 587 ),
				'smtp_secure' => (string) ( $p['security']  ?? 'tls' ),
				'smtp_user'   => (string) ( $p['smtp_user'] ?? '' ),
				'smtp_pass'   => (string) ( $p['smtp_pass'] ?? '' ),
				'from_email'  => (string) ( $p['from_email'] ?? '' ),
				'from_name'   => (string) ( $p['from_name']  ?? '' ),
			);
		}

		$smtp_log = array();

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		try {
			$mail = new \PHPMailer\PHPMailer\PHPMailer( true );
			$mail->isSMTP();
			$mail->SMTPDebug   = 4;
			$mail->Debugoutput = function ( $str ) use ( &$smtp_log ) {
				$smtp_log[] = rtrim( $str );
			};
			$mail->Host       = (string) ( $account_row['smtp_host']   ?? 'smtp.gmail.com' );
			$mail->Port       = (int)    ( $account_row['smtp_port']   ?? 587 );
			$mail->SMTPAuth   = true;
			$mail->Username   = (string) ( $account_row['smtp_user']   ?? '' );
			$mail->Password   = (string) ( $account_row['smtp_pass']   ?? '' );
			$mail->SMTPSecure = (string) ( $account_row['smtp_secure'] ?? 'tls' );
			$mail->CharSet    = 'UTF-8';

			$smtp_user  = $mail->Username;
			$from_email = (string) ( $account_row['from_email'] ?? $smtp_user );
			$from_name  = (string) ( $account_row['from_name']  ?? get_bloginfo( 'name' ) );
			if ( ! $from_email ) { $from_email = $smtp_user; }
			$mail->setFrom( $from_email ?: $smtp_user, $from_name );
			$mail->Sender = $smtp_user;
			if ( $smtp_user && strtolower( $from_email ) !== strtolower( $smtp_user ) ) {
				$mail->addReplyTo( $from_email, $from_name );
			}
			$mail->addAddress( $to );
			$mail->Subject = $subject;
			$mail->Body    = $body;
			$mail->send();
			return [ 'ok' => true, 'smtp_log' => $smtp_log ];
		} catch ( \Throwable $e ) {
			$smtp_log_str = ! empty( $smtp_log ) ? implode( "\n", $smtp_log ) : '';
			$error_msg    = $e->getMessage();
			if ( $smtp_log_str ) {
				$error_msg .= "\n\n[SMTP LOG]\n" . $smtp_log_str;
			}
			return [ 'ok' => false, 'error' => $error_msg, 'smtp_log' => $smtp_log ];
		}
	}

	/**
	 * Get email contacts unified from WP users + CF7 entries.
	 *
	 * Also stores `_bizcity_crm` usermeta for WP users that don't have it.
	 *
	 * @param int $limit Max results.
	 * @return array[] [ {id, email, name, phone, source} ]
	 */
	public static function api_get_contacts( int $limit = 100 ): array {
		$contacts = [];

		// 1) WP users — registered accounts.
		$users = get_users( [
			'number'  => $limit,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => [ 'ID', 'user_email', 'display_name' ],
		] );

		foreach ( $users as $u ) {
			if ( ! is_email( $u->user_email ) ) {
				continue;
			}
			// [2026-06-21 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime per user in loop
			$uid_int = (int) $u->ID;
			$use_cache = class_exists( 'BizCity_User_Meta_Cache' );
			$phone = $use_cache
				? (string) BizCity_User_Meta_Cache::get( $uid_int, 'billing_phone', '' )
				: (string) get_user_meta( $uid_int, 'billing_phone', true );
			if ( ! $phone ) {
				$phone = $use_cache
					? (string) BizCity_User_Meta_Cache::get( $uid_int, 'phone', '' )
					: (string) get_user_meta( $uid_int, 'phone', true );
			}
			if ( ! $phone ) {
				$phone = $use_cache
					? (string) BizCity_User_Meta_Cache::get( $uid_int, 'bizcity_phone', '' )
					: (string) get_user_meta( $uid_int, 'bizcity_phone', true );
			}

			// Unify to _bizcity_crm usermeta (merge, not overwrite).
			self::_unify_crm_meta( (int) $u->ID, $u->user_email, $phone );

			$contacts[] = [
				'id'     => 'wp_' . $u->ID,
				'email'  => $u->user_email,
				'name'   => $u->display_name,
				'phone'  => $phone,
				'source' => 'wp_user',
			];
		}

		// 2) CF7 entries (if plugin active and table exists).
		$contacts = array_merge( $contacts, self::_get_cf7_contacts( $limit ) );

		// De-duplicate by email.
		$seen   = [];
		$result = [];
		foreach ( $contacts as $c ) {
			$key = strtolower( trim( $c['email'] ) );
			if ( $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$result[]     = $c;
			}
		}

		return array_slice( $result, 0, $limit );
	}

	/**
	 * Get SMTP delivery statistics.
	 *
	 * @return array{total:int,ok:int,fail:int,accounts:int}
	 */
	public static function api_get_stats(): array {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return [ 'total' => 0, 'ok' => 0, 'fail' => 0, 'accounts' => 0 ];
		}

		$reg      = BizCity_Integration_Registry::instance();
		// [2026-06-10 Johnny Chu] PHASE-CG-SMTP-INTEGRATION fix — get_accounts() is on registry, not on integration instance.
		$accounts = $reg->get_accounts( 'email_smtp' );
		$ok       = 0;
		$fail     = 0;

		foreach ( $accounts as $acc ) {
			$status = (int) ( $acc['_status'] ?? 0 );
			if ( $status === 1 ) {
				$ok++;
			} elseif ( $status === 7 ) {
				$fail++;
			}
		}

		return [
			'accounts' => count( $accounts ),
			'ok'       => $ok,
			'fail'     => $fail,
			'total'    => count( $accounts ),
		];
	}

	/* ═══════════════════════════════════════════
	 *  Private helpers
	 * ═══════════════════════════════════════════ */

	/**
	 * Build a phpmailer_init closure configured for $account.
	 * Returns a referenceable closure so remove_action() works.
	 *
	 * @param array $account Decrypted account.
	 * @return callable
	 */
	private function make_phpmailer_hook( array $account ): callable {
		return function ( $mailer ) use ( $account ) {
			$mailer->isSMTP();
			$mailer->Host       = (string) ( $account['smtp_host'] ?? '' );
			$mailer->SMTPAuth   = (bool)   ( $account['auth'] ?? true );
			$mailer->Port       = (int)    ( $account['smtp_port'] ?? 587 );
			$mailer->Username   = (string) ( $account['smtp_user'] ?? '' );
			$mailer->Password   = (string) ( $account['smtp_pass'] ?? '' );
			$mailer->SMTPSecure = (string) ( $account['security'] ?? 'tls' );

			$from = (string) ( $account['from_email'] ?? '' );
			if ( ! $from ) {
				$from = $mailer->Username;
			}
			$from_name = (string) ( $account['from_name'] ?? get_bloginfo( 'name' ) );
			if ( is_email( $from ) ) {
				$mailer->From     = $from;
				$mailer->FromName = $from_name;
				$mailer->setFrom( $from, $from_name );
			}
		};
	}

	/**
	 * Send a ping email to the current admin to verify connectivity.
	 *
	 * @param array $account Decrypted account.
	 * @return array|WP_Error
	 */
	private function smtp_ping( array $account ) {
		$to = wp_get_current_user()->user_email;
		if ( ! $to ) {
			$to = get_option( 'admin_email', '' );
		}
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'no_admin_email', 'Không tìm được địa chỉ email admin.' );
		}

		$label  = (string) ( $account['label'] ?? '' );
		$host   = (string) ( $account['smtp_host'] ?? '?' );
		$user   = (string) ( $account['smtp_user'] ?? '?' );
		$body   = sprintf(
			"Đây là email kiểm tra kết nối SMTP.\n\nTài khoản : %s\nHost      : %s\nUser      : %s\nThời gian  : %s\nSite      : %s",
			$label ?: 'email_smtp',
			$host,
			$user,
			current_time( 'Y-m-d H:i:s' ),
			home_url()
		);

		$err  = '';
		$ok   = false;
		$cb   = function ( $wp_error ) use ( &$err ) {
			if ( $wp_error instanceof WP_Error ) {
				$err = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $cb );

		$init_cb = $this->make_phpmailer_hook( $account );
		add_action( 'phpmailer_init', $init_cb, 999 );
		$ok = wp_mail(
			$to,
			'[BizCity Twin SMTP] Test kết nối — ' . current_time( 'Y-m-d H:i:s' ),
			$body,
			[ 'Content-Type: text/plain; charset=UTF-8' ]
		);
		remove_action( 'phpmailer_init', $init_cb, 999 );
		remove_action( 'wp_mail_failed', $cb );

		if ( ! $ok && $err ) {
			return new WP_Error( 'smtp_ping_failed', $err );
		}
		return [ 'sent' => $ok, 'error' => $err, 'platform' => 'EMAIL', 'mid' => '' ];
	}

	/**
	 * Get a loaded integration instance with the given account uid loaded.
	 *
	 * @param string $uid Account _uid, or '' for default.
	 * @return self|null
	 */
	private static function _get_instance( string $uid ) {
		if ( ! class_exists( 'BizCity_Integration_Registry' ) ) {
			return null;
		}
		$reg     = BizCity_Integration_Registry::instance();
		$channel = $reg->get( 'email_smtp' );
		if ( ! ( $channel instanceof self ) ) {
			return null;
		}
		if ( $uid ) {
			// [2026-06-10 Johnny Chu] PHASE-CG-SMTP-INTEGRATION fix — find_account() does not exist;
			// search via registry get_accounts() instead.
			$all_accounts = $reg->get_accounts( 'email_smtp' );
			$found        = null;
			foreach ( $all_accounts as $acc ) {
				if ( isset( $acc['_uid'] ) && $acc['_uid'] === $uid ) {
					$found = $acc;
					break;
				}
			}
			if ( $found ) {
				$channel->set_account( $found );
			}
		} elseif ( ! $channel->get_account() ) {
			// Default: use first account.
			$first = $reg->get_accounts( 'email_smtp' );
			if ( ! empty( $first[0] ) ) {
				$channel->set_account( $first[0] );
			}
		}
		return $channel;
	}

	/**
	 * Extract email contacts from CF7 entry storage (if available).
	 *
	 * Supports: Flamingo (official CF7 companion) + direct CF7 mail storage.
	 *
	 * @param int $limit
	 * @return array[]
	 */
	private static function _get_cf7_contacts( int $limit ): array {
		$out = [];

		// Flamingo inbound channel table (wp_flamingo_inbound_messages).
		if ( class_exists( 'Flamingo_Inbound_Message' ) ) {
			$items = Flamingo_Inbound_Message::find( [
				'post_status' => 'publish',
				'posts_per_page' => $limit,
				'orderby'    => 'date',
				'order'      => 'DESC',
			] );
			foreach ( (array) $items as $item ) {
				$meta   = (array) ( $item->meta ?? [] );
				$fields = (array) ( $meta['fields'] ?? [] );
				$email  = '';
				$phone  = '';
				$name   = '';
				foreach ( $fields as $k => $v ) {
					$kl = strtolower( (string) $k );
					if ( strpos( $kl, 'email' ) !== false || strpos( $kl, 'mail' ) !== false ) {
						$email = (string) $v;
					}
					if ( strpos( $kl, 'phone' ) !== false || strpos( $kl, 'tel' ) !== false || strpos( $kl, 'mobile' ) !== false ) {
						$phone = (string) $v;
					}
					if ( strpos( $kl, 'name' ) !== false ) {
						$name = (string) $v;
					}
				}
				if ( ! $email && is_email( $item->email ?? '' ) ) {
					$email = $item->email;
				}
				if ( ! $name ) {
					$name = (string) ( $item->meta['your-name'] ?? $item->subject ?? '' );
				}
				if ( is_email( $email ) ) {
					// Unify to _bizcity_crm via transient/option (no WP user).
					self::_unify_crm_guest( $email, $name, $phone );
					$out[] = [
						'id'     => 'cf7_' . ( is_object( $item ) && isset( $item->id ) ? $item->id : wp_rand() ),
						'email'  => $email,
						'name'   => $name,
						'phone'  => $phone,
						'source' => 'flamingo_cf7',
					];
				}
			}
		}

		return $out;
	}

	/**
	 * Unify email + phone into _bizcity_crm usermeta for WP users.
	 * Writes once; does NOT overwrite existing data.
	 *
	 * @param int    $user_id
	 * @param string $email
	 * @param string $phone
	 */
	private static function _unify_crm_meta( int $user_id, string $email, string $phone ): void {
		if ( ! $user_id ) {
			return;
		}
		// [2026-06-21 Johnny Chu] R-PERF — use BizCity_User_Meta_Cache to avoid repeated meta prime
		$use_cache = class_exists( 'BizCity_User_Meta_Cache' );
		$crm = $use_cache
			? (array) BizCity_User_Meta_Cache::get( $user_id, '_bizcity_crm', array() )
			: (array) get_user_meta( $user_id, '_bizcity_crm', true );
		$changed = false;
		if ( empty( $crm['email'] ) && $email ) {
			$crm['email'] = $email;
			$changed      = true;
		}
		if ( empty( $crm['phone'] ) && $phone ) {
			$crm['phone'] = $phone;
			$changed      = true;
		}
		if ( $changed ) {
			update_user_meta( $user_id, '_bizcity_crm', $crm );
			if ( $use_cache ) {
				BizCity_User_Meta_Cache::set( $user_id, '_bizcity_crm', $crm );
			}
		}
	}

	/**
	 * Unify guest (no WP user account) email/phone into a transient-backed
	 * option so CRM can later import them.
	 *
	 * Key: `bizcity_crm_guest_contacts` — JSON list, capped at 500 rows.
	 *
	 * @param string $email
	 * @param string $name
	 * @param string $phone
	 */
	private static function _unify_crm_guest( string $email, string $name, string $phone ): void {
		if ( ! is_email( $email ) ) {
			return;
		}
		$opt  = (array) get_option( 'bizcity_crm_guest_contacts', [] );
		$key  = strtolower( trim( $email ) );
		if ( isset( $opt[ $key ] ) ) {
			return; // already known
		}
		$opt[ $key ] = [
			'email'  => $email,
			'name'   => $name,
			'phone'  => $phone,
			'source' => 'cf7',
			'added'  => gmdate( 'Y-m-d H:i:s' ),
		];
		// Cap at 500 to avoid bloating options table.
		if ( count( $opt ) > 500 ) {
			$opt = array_slice( $opt, -500, 500, true );
		}
		update_option( 'bizcity_crm_guest_contacts', $opt, false );
	}
}
