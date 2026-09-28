<?php
/**
 * BizCity Error Reporter — records user-facing errors to JSONL and maps codes to repair hints.
 *
 * [2026-09-28 Claude Opus 5.5] CORE-REDUCTION WP-13 DL-3 (D-35, Q-3) — owner moved from core/diagnostics to
 * core/helper. Errors are production facts, so they are recorded on every request type, not only where the
 * Diagnostics bootstrap happened to load. Class name and public API are unchanged.
 *
 * Responsibilities:
 *   1. record()      — append one error row to the JSONL contract `core.helper.error_reports`
 *                      (R-LOG-HYBRID; readable in Tools → BizCity Logs). Fires `bizcity_error_recorded` and,
 *                      for critical codes, `bizcity_critical_error` (emailed by default, 1 h throttle per code).
 *                      Replaces the capped wp_option `bizcity_error_reports` (one DB write per error).
 *   2. suggest_fix() — map an error code to a repair entry point for the R-ERROR-UX `fix` field. Links into the
 *                      Diagnostics page are omitted when Diagnostics is not installed (dev-only, D-35).
 *   3. Admin viewer helpers used by the dev Diagnostics page: get_reports(), clear_reports(), rate_check().
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper
 * @since      2026-05-21 (PHASE-0.41 Error Reporter); moved 2026-09-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Error_Reporter', false ) ) {
	return;
}

final class BizCity_Error_Reporter {

	/** JSONL contract registered in core/helper/bootstrap.php. */
	const CONTRACT       = 'core.helper.error_reports';
	/** Pre-2026-09-28 storage; read-only now (get_reports) and cleared by clear_reports(). */
	const OPTION         = 'bizcity_error_reports';
	const MAX_ROWS       = 200;
	const FIELD_CAP      = 4096;          // bytes per text field
	const RATE_TTL       = 3600;          // 1h window
	const RATE_MAX       = 20;            // per IP per window
	const ALERT_TTL      = 3600;          // critical-email throttle per code
	const CRITICAL_CODES = [
		'table_missing',
		'scheduler_unavailable',
		'llm_quota_exhausted',
		'smtp_fail',
		'database_unavailable',
	];

	/** @var bool guards register_hooks() from double-binding. */
	private static $booted = false;

	public static function register_hooks(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'bizcity_critical_error', [ __CLASS__, 'on_critical_error' ], 10, 1 );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Record API
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Record an error report.
	 *
	 * @param array $report Keys: code (required), module, http_status, title,
	 *                      detail, context (array), url, user_agent, source.
	 * @return string  Generated report id (uuid4).
	 */
	public static function record( array $report ): string {
		$code = isset( $report['code'] ) ? self::clean_code( (string) $report['code'] ) : '';
		if ( $code === '' ) {
			$code = 'unknown_error';
		}

		$row = [
			'id'          => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( 'bzerr_', true ) ),
			'ts'          => gmdate( 'Y-m-d H:i:s' ),
			'code'        => $code,
			'module'      => self::clean_text( $report['module']      ?? '', 64 ),
			'http_status' => isset( $report['http_status'] ) ? (int) $report['http_status'] : 0,
			'title'       => self::clean_text( $report['title']       ?? '', 200 ),
			'detail'      => self::clean_text( $report['detail']      ?? '', self::FIELD_CAP ),
			'url'         => self::clean_text( $report['url']         ?? '', 500 ),
			'user_agent'  => self::clean_text( $report['user_agent']  ?? '', 300 ),
			'source'      => self::clean_text( $report['source']      ?? 'fe', 16 ),  // fe | be | rest
			'context'     => self::clean_context( $report['context']  ?? [] ),
			'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'user_id'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'ip_hash'     => self::client_ip_hash(),
			'fix'         => self::suggest_fix( $code ),
		];

		/**
		 * Allow modules to redact PII before persistence.
		 *
		 * @param array $row
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$row = apply_filters( 'bizcity_error_report_redact', $row );
		}

		self::persist( $row );

		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fired after every report is persisted. Useful for webhook bindings.
			 *
			 * @param array $row
			 */
			do_action( 'bizcity_error_recorded', $row );

			if ( in_array( $code, self::CRITICAL_CODES, true ) ) {
				self::register_hooks();
				/**
				 * Fired only for critical codes. Default handler emails admin.
				 *
				 * @param array $row
				 */
				do_action( 'bizcity_critical_error', $row );
			}
		}

		return (string) $row['id'];
	}

	/** Append one row to the JSONL contract; fall back to a compact error_log line if the logger is unavailable. */
	private static function persist( array $row ): void {
		$level = in_array( (string) $row['code'], self::CRITICAL_CODES, true ) ? 'critical' : 'error';
		$ctx   = $row;
		$ctx['event_uuid'] = (string) $row['id'];
		$written = false;
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			$written = (bool) BizCity_JSONL_File_Logger::write_contract( self::CONTRACT, $level, (string) $row['code'], (string) ( $row['title'] !== '' ? $row['title'] : $row['code'] ), $ctx );
		}
		if ( ! $written ) {
			error_log( '[BizCity_Error_Reporter] ' . $level . ' code=' . $row['code'] . ' module=' . $row['module'] . ' id=' . $row['id'] );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Repair-hint mapper
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Map error code → suggested repair entry-point.
	 *
	 * [2026-06-05 Johnny Chu] R-ERROR-UX — extended with all 24 catalog codes.
	 *
	 * @return array{url:string,label:string,kind:string}|array{}
	 */
	public static function suggest_fix( string $code ): array {
		$diag     = admin_url( 'tools.php?page=bizcity-diagnostics' );
		$settings = admin_url( 'admin.php?page=bizcity-twinchat-settings' );
		$channels = admin_url( 'admin.php?page=bizcity-channel-gateway' );
		$cron     = admin_url( 'tools.php?page=bizcity-cron' );
		$upgrade  = admin_url( 'admin.php?page=bizcity-upgrade' );
		$auto     = admin_url( 'admin.php?page=bizcity-automation' );

		$map = [
			// ── Legacy (pre-R-ERROR-UX) ──────────────────────────────────
			'table_missing'               => [ 'url' => add_query_arg( 'bizcity_provision', '1', $diag ), 'label' => '🔧 Repair tables',        'kind' => 'provision' ],
			'database_unavailable'        => [ 'url' => add_query_arg( 'bizcity_provision', '1', $diag ), 'label' => '🔧 Provision database',   'kind' => 'provision' ],
			'scheduler_unavailable'       => [ 'url' => add_query_arg( 'bizcity_provision', '1', $diag ), 'label' => '🔧 Provision scheduler',  'kind' => 'provision' ],
			'table_orphan'                => [ 'url' => add_query_arg( 'bzdiag_force_clean', '1', $diag ), 'label' => '🧹 Clean orphans',        'kind' => 'orphan'    ],
			'llm_quota_exhausted'         => [ 'url' => $upgrade,                                         'label' => '⚡ Top up credits',       'kind' => 'billing'   ],
			'search_insufficient_credits' => [ 'url' => $upgrade,                                         'label' => '⚡ Top up credits',       'kind' => 'billing'   ],
			'smtp_fail'                   => [ 'url' => admin_url( 'admin.php?page=bizcity-smtp' ),       'label' => '✉ Check SMTP',           'kind' => 'smtp'      ],
			'tier_required'               => [ 'url' => $upgrade,                                         'label' => '⬆ Upgrade plan',          'kind' => 'upgrade'   ],

			// ── R-ERROR-UX catalog — Auth / AuthZ ────────────────────────
			'auth_required'     => [ 'url' => wp_login_url(),                   'label' => '🔐 Đăng nhập lại',               'kind' => 'auth'      ],
			'permission_denied' => [ 'url' => admin_url( 'users.php' ),         'label' => '👤 Quản lý quyền user',          'kind' => 'auth'      ],
			'nonce_invalid'     => [ 'url' => '',                               'label' => '🔄 Tải lại trang',               'kind' => 'reload'    ],
			'api_key_missing'   => [ 'url' => $settings,                        'label' => '⚙ Cấu hình API Key',            'kind' => 'settings'  ],
			'api_key_invalid'   => [ 'url' => $settings,                        'label' => '⚙ Cập nhật API Key',            'kind' => 'settings'  ],

			// ── Quota ─────────────────────────────────────────────────────
			'rate_limited'               => [ 'url' => '',        'label' => '⏱ Đợi và thử lại',     'kind' => 'wait'    ],
			'quota_exceeded'             => [ 'url' => $upgrade,  'label' => '⬆ Nâng gói',            'kind' => 'upgrade' ],
			'quota_messages_exceeded'    => [ 'url' => $upgrade,  'label' => '⬆ Nâng gói',            'kind' => 'upgrade' ],

			// ── Channel / Token ───────────────────────────────────────────
			'token_invalid'          => [ 'url' => $channels, 'label' => '🔗 Cấp quyền lại kênh',   'kind' => 'channel' ],
			'token_scope_missing'    => [ 'url' => $channels, 'label' => '🔗 Re-auth đầy đủ scope',  'kind' => 'channel' ],
			'page_not_connected'     => [ 'url' => $channels, 'label' => '🔗 Kết nối Facebook Page', 'kind' => 'channel' ],
			'channel_not_configured' => [ 'url' => $channels, 'label' => '⚙ Thiết lập kênh',        'kind' => 'channel' ],

			// ── Module / Service ──────────────────────────────────────────
			'module_not_loaded'  => [ 'url' => $diag,     'label' => '🔍 Xem Diagnostics',        'kind' => 'diagnostics' ],
			'gateway_degraded'   => [ 'url' => $settings, 'label' => '⚙ Kiểm tra API Key',        'kind' => 'settings'   ],
			'llm_error'          => [ 'url' => $diag,     'label' => '🔍 Xem Diagnostics',        'kind' => 'diagnostics' ],
			'kg_empty'           => [ 'url' => admin_url( 'admin.php?page=bizcity-twinchat#sources' ), 'label' => '📚 Thêm nguồn', 'kind' => 'kg' ],
			'retrieval_error'    => [ 'url' => $diag,     'label' => '🔍 Xem Diagnostics',        'kind' => 'diagnostics' ],
			'skill_db_missing'   => [ 'url' => admin_url( 'admin.php?page=bizcity-guru#skills' ),     'label' => '🧠 Cấu hình Skill', 'kind' => 'guru' ],

			// ── Data / Validation ─────────────────────────────────────────
			'invalid_param'    => [ 'url' => '',      'label' => '🔄 Tải lại trang',     'kind' => 'reload' ],
			'invalid_metadata' => [ 'url' => $diag,   'label' => '🔍 Xem Diagnostics',   'kind' => 'diagnostics' ],
			'not_found'        => [ 'url' => '',      'label' => '🔄 Kiểm tra lại ID',   'kind' => 'reload' ],
			'duplicate'        => [ 'url' => '',      'label' => '🔍 Tìm bản ghi trùng', 'kind' => 'reload' ],

			// ── Agent / Automation ────────────────────────────────────────
			'twin_agent_exception'   => [ 'url' => $diag, 'label' => '🔍 Xem Diagnostics',           'kind' => 'diagnostics' ],
			'automation_run_failed'  => [ 'url' => $auto, 'label' => '⚙ Xem lịch sử Automation',    'kind' => 'automation'  ],
			'cron_failed'            => [ 'url' => $cron, 'label' => '⏱ Xem BizCity Cron',           'kind' => 'cron'        ],
			'workflow_not_found'     => [ 'url' => $auto, 'label' => '⚙ Kiểm tra Automation',        'kind' => 'automation'  ],
		];

		// [2026-09-28 Claude Opus 5.5] WP-13 DL-3 — no link to a page that is not installed (Diagnostics is dev-only, D-35).
		if ( ! ( function_exists( 'bizcity_diagnostics_available' ) && bizcity_diagnostics_available() ) ) {
			foreach ( $map as $map_code => $entry ) {
				if ( false !== strpos( (string) $entry['url'], 'page=bizcity-diagnostics' ) ) {
					unset( $map[ $map_code ] );
				}
			}
		}

		/**
		 * Filter fix-map to add/override entries per environment.
		 *
		 * @param array $map  code => [url,label,kind]
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$map = apply_filters( 'bizcity_error_fix_map', $map );
		}

		return isset( $map[ $code ] ) && is_array( $map[ $code ] ) ? $map[ $code ] : [];
	}

	// ─────────────────────────────────────────────────────────────────
	// Rate-limit (for public REST endpoint)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Returns true if caller is allowed (counter < RATE_MAX). Increments
	 * counter as a side-effect.
	 */
	public static function rate_check(): bool {
		$key   = 'bizcity_err_rate_' . self::client_ip_hash();
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_TTL );
		return true;
	}

	// ─────────────────────────────────────────────────────────────────
	// Critical-error email handler (default; can be unhooked)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Default `bizcity_critical_error` handler — wp_mail() via core/smtp.
	 * Throttled per-code via transient `bizcity_alert_<code>` (1h).
	 */
	public static function on_critical_error( array $row ): void {
		$code  = $row['code'] ?? 'unknown';
		$trans = 'bizcity_alert_' . md5( $code . '_' . ( $row['blog_id'] ?? 0 ) );
		if ( get_transient( $trans ) ) {
			return;  // already alerted recently
		}
		set_transient( $trans, 1, self::ALERT_TTL );

		$to = self::resolve_alert_recipient();
		if ( ! $to ) {
			return;
		}

		$subject = sprintf(
			'[BizCity Alert] %s · blog %d',
			$code,
			(int) ( $row['blog_id'] ?? 0 )
		);

		$fix_line = '';
		if ( ! empty( $row['fix']['url'] ) ) {
			$fix_line = "\nSuggested fix: " . $row['fix']['label'] . "\n→ " . $row['fix']['url'] . "\n";
		}

		$ctx_dump = '';
		if ( ! empty( $row['context'] ) && is_array( $row['context'] ) ) {
			$ctx_dump = "\nContext:\n" . wp_json_encode( $row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
		}

		$body  = "BizCity critical-error alert\n";
		$body .= str_repeat( '─', 50 ) . "\n";
		$body .= "Code     : {$row['code']}\n";
		$body .= "Module   : " . ( $row['module']      ?? '' ) . "\n";
		$body .= "Title    : " . ( $row['title']       ?? '' ) . "\n";
		$body .= "Detail   : " . ( $row['detail']      ?? '' ) . "\n";
		$body .= "URL      : " . ( $row['url']         ?? '' ) . "\n";
		$body .= "Blog ID  : " . ( $row['blog_id']     ?? 0  ) . "\n";
		$body .= "User ID  : " . ( $row['user_id']     ?? 0  ) . "\n";
		$body .= "Time UTC : " . ( $row['ts']          ?? '' ) . "\n";
		$body .= "Report   : " . ( $row['id']          ?? '' ) . "\n";
		$body .= $fix_line;
		$body .= $ctx_dump;
		// [2026-09-28 Claude Opus 5.5] WP-13 DL-3 — reports live in the JSONL log viewer (Tools → BizCity Logs).
		$body .= "\nView all reports: " . admin_url( 'tools.php?page=bizcity-log-explorer' ) . "\n";

		wp_mail( $to, $subject, $body );
	}

	/** Resolve alert recipient (filter > constant > admin_email). */
	private static function resolve_alert_recipient(): string {
		$to = get_option( 'admin_email' );
		if ( defined( 'BIZCITY_ALERT_EMAIL' ) && BIZCITY_ALERT_EMAIL ) {
			$to = (string) BIZCITY_ALERT_EMAIL;
		}
		/** Override recipient (single email or comma-list). */
		$to = (string) apply_filters( 'bizcity_alert_email_to', $to );
		return is_email( $to ) || ( strpos( $to, ',' ) !== false ) ? $to : '';
	}

	// ─────────────────────────────────────────────────────────────────
	// Admin viewer helpers
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Stored reports, oldest first: rows left in the pre-2026-09-28 option, then today's JSONL rows.
	 * Older JSONL days are in Tools → BizCity Logs.
	 */
	public static function get_reports(): array {
		$legacy = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		$rows   = is_array( $legacy ) ? array_values( $legacy ) : [];
		if ( class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			foreach ( (array) BizCity_JSONL_File_Logger::read_contract( self::CONTRACT, '', self::MAX_ROWS ) as $line ) {
				if ( is_array( $line ) && isset( $line['ctx'] ) && is_array( $line['ctx'] ) ) {
					$row = $line['ctx'];
					unset( $row['event_uuid'] );
					$rows[] = $row;
				}
			}
		}
		return array_slice( $rows, -self::MAX_ROWS );
	}

	/** Clear the legacy option rows (JSONL rows age out through the logger's retention). Returns count removed. */
	public static function clear_reports(): int {
		$legacy = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		$count  = is_array( $legacy ) ? count( $legacy ) : 0;
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION );
		}
		return $count;
	}

	// ─────────────────────────────────────────────────────────────────
	// Sanitizers
	// ─────────────────────────────────────────────────────────────────

	private static function clean_code( string $c ): string {
		$c = strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $c ) );
		return substr( $c, 0, 64 );
	}

	private static function clean_text( $v, int $max ): string {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$v = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $v ) : strip_tags( (string) $v );
		if ( strlen( $v ) > $max ) {
			$v = substr( $v, 0, $max );
		}
		return $v;
	}

	private static function clean_context( $ctx ): array {
		if ( ! is_array( $ctx ) ) {
			return [];
		}
		// Drop obvious secret keys, cap depth+size.
		$drop = [ 'password', 'pass', 'token', 'api_key', 'apikey', 'secret', 'authorization', 'cookie' ];
		$out  = [];
		$i    = 0;
		foreach ( $ctx as $k => $v ) {
			if ( $i++ >= 32 ) {
				break;   // cap keys
			}
			$k = is_string( $k ) ? substr( $k, 0, 64 ) : (string) $k;
			if ( in_array( strtolower( $k ), $drop, true ) ) {
				continue;
			}
			if ( is_scalar( $v ) ) {
				$out[ $k ] = self::clean_text( $v, 1024 );
			} elseif ( is_array( $v ) ) {
				$enc       = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : json_encode( $v, JSON_UNESCAPED_UNICODE );
				$out[ $k ] = is_string( $enc ) && strlen( $enc ) > 2048 ? substr( $enc, 0, 2048 ) . '…' : $enc;
			}
		}
		return $out;
	}

	private static function client_ip_hash(): string {
		$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'bizcity';
		return substr( md5( $ip . '|' . $salt ), 0, 16 );
	}
}

BizCity_Error_Reporter::register_hooks();
