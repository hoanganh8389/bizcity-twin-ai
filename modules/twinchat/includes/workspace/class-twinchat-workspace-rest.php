<?php
/**
 * Bizcity Twin AI — TwinChat Workspace REST Controller (Wave 9)
 *
 * Aggregator endpoints powering the Brain Workspace tabs:
 *   GET /bizcity-twinchat/v1/history                  → unified activity stream
 *   GET /bizcity-twinchat/v1/integrations/summary     → channel health snapshot
 *   GET /bizcity-twinchat/v1/plans/summary            → token usage + recent LLM calls
 *
 * The Files tab reuses bzdoc /bzdoc/v1/list directly, so no proxy is needed
 * here. We deliberately keep all 3 endpoints in a single class so the wave
 * adds exactly one PHP file.
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Workspace
 * @since      2026-04-15 (Wave 9)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Workspace_REST {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ═══════════════════════════════════════════
	 *  Routing
	 * ═══════════════════════════════════════════ */

	public function register_routes() {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' )
			? BIZCITY_TWINCHAT_REST_NS
			: 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/history', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_history' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'default' => 0 ],
				'kinds'       => [ 'type' => 'string',  'default' => '' ],
				'since'       => [ 'type' => 'string',  'default' => '' ],
				'until'       => [ 'type' => 'string',  'default' => '' ],
				'paged'       => [ 'type' => 'integer', 'default' => 1 ],
				'per_page'    => [ 'type' => 'integer', 'default' => 30 ],
			],
		] );

		register_rest_route( $ns, '/integrations/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_integrations_summary' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/plans/summary', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_plans_summary' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
	}

	public function check_logged_in() {
		return is_user_logged_in();
	}

	/* ═══════════════════════════════════════════
	 *  W9.1 — History aggregator
	 *
	 *  Sources unioned in PHP (max ~per_page * 4 rows pulled per source):
	 *    • bizcity_webchat_messages   (kinds: assistant, tool_call, studio_output)
	 *    • {prefix}bzdoc_documents    (kinds: doc_created, doc_updated, doc_generated)
	 *
	 *  session_id naming convention:
	 *    • TwinChat notebooks  → "tc_<notebook_id>"  or  "twinchat_<notebook_id>"
	 *    • bzdoc generations   → "bzdoc_<doc_id>"
	 * ═══════════════════════════════════════════ */
	public function get_history( WP_REST_Request $req ) {
		global $wpdb;

		$user_id     = get_current_user_id();
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$kinds_csv   = (string) $req->get_param( 'kinds' );
		$since       = (string) $req->get_param( 'since' );
		$until       = (string) $req->get_param( 'until' );
		$paged       = max( 1, (int) $req->get_param( 'paged' ) );
		$per_page    = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ) );

		$kinds = array_filter( array_map( 'trim', explode( ',', $kinds_csv ) ) );
		if ( empty( $kinds ) ) {
			$kinds = [ 'assistant', 'tool_call', 'studio_output', 'doc_created', 'doc_generated' ];
		}
		$kind_set = array_flip( $kinds );

		$items = [];

		// ── Webchat messages (TwinChat assistant + tool calls + studio output) ──
		if ( isset( $kind_set['assistant'] ) || isset( $kind_set['tool_call'] ) || isset( $kind_set['studio_output'] ) ) {
			$items = array_merge( $items, $this->fetch_webchat_messages(
				$user_id, $notebook_id, $kind_set, $since, $until, $per_page * 2
			) );
		}

		// ── bzdoc documents (created / updated / generated) ──
		if ( isset( $kind_set['doc_created'] ) || isset( $kind_set['doc_updated'] ) || isset( $kind_set['doc_generated'] ) ) {
			$items = array_merge( $items, $this->fetch_bzdoc_events(
				$user_id, $notebook_id, $kind_set, $since, $until, $per_page * 2
			) );
		}

		// Sort union desc by occurred_at, paginate.
		usort( $items, function ( $a, $b ) {
			return strcmp( (string) $b['occurred_at'], (string) $a['occurred_at'] );
		} );
		$total       = count( $items );
		$total_pages = (int) ceil( $total / $per_page );
		$offset      = ( $paged - 1 ) * $per_page;
		$slice       = array_slice( $items, $offset, $per_page );

		return rest_ensure_response( [
			'items'       => $slice,
			'total'       => $total,
			'total_pages' => max( 1, $total_pages ),
			'paged'       => $paged,
			'per_page'    => $per_page,
		] );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_webchat_messages( $user_id, $notebook_id, array $kind_set, $since, $until, $limit ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_webchat_messages';

		// Confirm table exists (multisite safety).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		if ( ! $exists ) {
			return [];
		}

		$where  = [ 'user_id = %d', "message_from = 'bot'" ];
		$params = [ (int) $user_id ];

		if ( $notebook_id > 0 ) {
			$where[]  = 'session_id IN (%s, %s)';
			$params[] = 'tc_' . $notebook_id;
			$params[] = 'twinchat_' . $notebook_id;
		}

		if ( $since !== '' ) {
			$where[]  = 'created_at >= %s';
			$params[] = $since;
		}
		if ( $until !== '' ) {
			$where[]  = 'created_at <= %s';
			$params[] = $until;
		}

		$sql = "SELECT id, session_id, message_text, message_type, plugin_slug, tool_name,
				       attachments, created_at
				FROM {$tbl}
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY created_at DESC
				LIMIT %d";
		$params[] = (int) $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		if ( ! $rows ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $r ) {
			$kind = $this->classify_webchat_kind( $r );
			if ( ! isset( $kind_set[ $kind ] ) ) {
				continue;
			}
			$nb_id = $this->session_to_notebook( $r->session_id );
			$summary = (string) $r->message_text;
			if ( strlen( $summary ) > 240 ) {
				$summary = mb_substr( $summary, 0, 237 ) . '…';
			}
			$out[] = [
				'id'             => 'msg_' . (int) $r->id,
				'kind'           => $kind,
				'occurred_at'    => $r->created_at,
				'notebook_id'    => $nb_id,
				'notebook_title' => $this->resolve_notebook_title( $nb_id ),
				'actor_id'       => (int) $user_id,
				'actor_name'     => $this->resolve_user_name( $user_id ),
				'summary'        => $summary,
				'refs'           => [
					'message_id' => (int) $r->id,
					'tool_name'  => $r->tool_name ?: null,
					'plugin'     => $r->plugin_slug ?: null,
				],
				'deeplink'       => $nb_id > 0
					? add_query_arg( [ 'page' => 'bizcity-twinchat', 'notebook_id' => $nb_id ], admin_url( 'admin.php' ) )
					: '',
			];
		}
		return $out;
	}

	/**
	 * Map a webchat message row to one of the supported history kinds.
	 */
	private function classify_webchat_kind( $row ) {
		$type = (string) ( $row->message_type ?? '' );
		$tool = (string) ( $row->tool_name ?? '' );
		if ( $type === 'studio_output' || $type === 'studio' || strpos( $tool, 'studio' ) === 0 ) {
			return 'studio_output';
		}
		if ( $tool !== '' || $type === 'tool_call' || $type === 'tool_result' ) {
			return 'tool_call';
		}
		return 'assistant';
	}

	private function session_to_notebook( $session_id ) {
		$sid = (string) $session_id;
		if ( strpos( $sid, 'tc_' ) === 0 ) {
			return (int) substr( $sid, 3 );
		}
		if ( strpos( $sid, 'twinchat_' ) === 0 ) {
			return (int) substr( $sid, 9 );
		}
		if ( strpos( $sid, 'bzdoc_' ) === 0 ) {
			return 0; // bzdoc messages live in the doc generation stream, not a notebook
		}
		return 0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_bzdoc_events( $user_id, $notebook_id, array $kind_set, $since, $until, $limit ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bzdoc_documents';

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		if ( ! $exists ) {
			return [];
		}

		$where  = [ 'user_id = %d' ];
		$params = [ (int) $user_id ];

		if ( $notebook_id > 0 ) {
			$where[]  = 'notebook_id = %d';
			$params[] = $notebook_id;
		}
		if ( $since !== '' ) {
			$where[]  = 'updated_at >= %s';
			$params[] = $since;
		}
		if ( $until !== '' ) {
			$where[]  = 'updated_at <= %s';
			$params[] = $until;
		}

		$sql = "SELECT id, doc_type, title, status, notebook_id, created_at, updated_at
				FROM {$tbl}
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY updated_at DESC
				LIMIT %d";
		$params[] = (int) $limit;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		if ( ! $rows ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $r ) {
			$nb_id     = (int) $r->notebook_id;
			$nb_title  = $this->resolve_notebook_title( $nb_id );
			$actor     = $this->resolve_user_name( $user_id );
			$doc_type  = (string) ( $r->doc_type ?: 'document' );
			$title     = (string) ( $r->title ?: '(Untitled)' );
			$deeplink  = add_query_arg(
				[ 'page' => 'bizcity-doc', 'doc_id' => (int) $r->id ],
				admin_url( 'admin.php' )
			);

			// Emit "doc_created" if created_at == updated_at (initial save), else "doc_updated".
			$is_new = ( $r->created_at === $r->updated_at );
			$kind   = $is_new ? 'doc_created' : 'doc_updated';

			// Status "ready"/"published" → also synthesize a "doc_generated" entry.
			if ( in_array( (string) $r->status, [ 'ready', 'published', 'final' ], true )
				&& isset( $kind_set['doc_generated'] ) ) {
				$out[] = [
					'id'             => 'doc_g_' . (int) $r->id,
					'kind'           => 'doc_generated',
					'occurred_at'    => $r->updated_at,
					'notebook_id'    => $nb_id,
					'notebook_title' => $nb_title,
					'actor_id'       => (int) $user_id,
					'actor_name'     => $actor,
					'summary'        => sprintf( '%s — %s', strtoupper( $doc_type ), $title ),
					'refs'           => [ 'doc_id' => (int) $r->id ],
					'deeplink'       => $deeplink,
				];
			}

			if ( ! isset( $kind_set[ $kind ] ) ) {
				continue;
			}

			$out[] = [
				'id'             => 'doc_' . $kind . '_' . (int) $r->id,
				'kind'           => $kind,
				'occurred_at'    => $is_new ? $r->created_at : $r->updated_at,
				'notebook_id'    => $nb_id,
				'notebook_title' => $nb_title,
				'actor_id'       => (int) $user_id,
				'actor_name'     => $actor,
				'summary'        => sprintf( '%s — %s', strtoupper( $doc_type ), $title ),
				'refs'           => [ 'doc_id' => (int) $r->id ],
				'deeplink'       => $deeplink,
			];
		}
		return $out;
	}

	/* ═══════════════════════════════════════════
	 *  W9.4 — Integrations summary
	 * ═══════════════════════════════════════════ */
	public function get_integrations_summary( WP_REST_Request $req ) {
		$channels  = [];
		$admin_url = add_query_arg(
			[ 'page' => 'bizcity-channels' ],
			admin_url( 'admin.php' )
		);

		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			$registry     = BizCity_Integration_Registry::instance();
			$integrations = $registry->get_all();

			foreach ( $integrations as $code => $integ ) {
				$accounts  = $registry->get_accounts( (string) $code );
				$total     = is_array( $accounts ) ? count( $accounts ) : 0;
				$ok        = 0;
				$error     = 0;
				if ( $total > 0 ) {
					foreach ( $accounts as $acc ) {
						$status = (int) ( $acc['_status'] ?? 0 );
						if ( $status === 1 ) {
							$ok++;
						} else {
							$error++;
						}
					}
				}
				$channels[] = [
					'code'           => (string) $code,
					'name'           => method_exists( $integ, 'get_name' ) ? (string) $integ->get_name() : (string) $code,
					'category'       => method_exists( $integ, 'get_category' ) ? (string) $integ->get_category() : 'other',
					'inboxes_total'  => $total,
					'inboxes_ok'     => $ok,
					'inboxes_error'  => $error,
				];
			}
		}

		// Sort: most-connected first.
		usort( $channels, function ( $a, $b ) {
			return $b['inboxes_total'] <=> $a['inboxes_total'];
		} );

		return rest_ensure_response( [
			'channels'      => $channels,
			'last_check_at' => current_time( 'mysql' ),
			'admin_url'     => $admin_url,
		] );
	}

	/* ═══════════════════════════════════════════
	 *  W9.5 — Plans summary
	 * ═══════════════════════════════════════════ */
	public function get_plans_summary( WP_REST_Request $req ) {
		$user_id   = get_current_user_id();
		$admin_url = add_query_arg(
			[ 'page' => 'bizcity-llm' ],
			admin_url( 'admin.php' )
		);

		$tokens_used  = 0;
		$cost_month   = 0.0;
		$recent_calls = [];

		if ( class_exists( 'BizCity_Router_Usage' ) ) {
			// Recent calls (last 20).
			$logs = BizCity_Router_Usage::get_logs( (int) $user_id, 20, 0 );
			foreach ( (array) $logs as $row ) {
				$recent_calls[] = [
					'id'                => (int) $row->id,
					'service'           => (string) ( $row->service ?? '' ),
					'model'             => (string) ( $row->model ?? '' ),
					'purpose'           => (string) ( $row->purpose ?? '' ),
					'plugin'            => (string) ( $row->plugin_name ?? '' ),
					'tokens_prompt'     => (int) ( $row->tokens_prompt ?? 0 ),
					'tokens_completion' => (int) ( $row->tokens_completion ?? 0 ),
					'total_tokens'      => (int) ( $row->total_tokens ?? 0 ),
					'cost_usd'          => (float) ( $row->cost_usd ?? 0 ),
					'fallback_used'     => (int) ( $row->fallback_used ?? 0 ) === 1,
					'is_stream'         => (int) ( $row->is_stream ?? 0 ) === 1,
					'created_at'        => (string) ( $row->created_at ?? '' ),
				];
			}

			// Month-to-date token sum (direct query — cheaper than paging all logs).
			global $wpdb;
			$tbl = $wpdb->base_prefix . 'bizcity_llm_usage_logs';
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( $exists ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT COALESCE(SUM(total_tokens),0) AS tokens,
					        COALESCE(SUM(cost_usd),0)     AS cost
					 FROM {$tbl}
					 WHERE user_id = %d
					   AND created_at >= DATE_FORMAT(CURDATE(), '%%Y-%%m-01')",
					(int) $user_id
				) );
				if ( $row ) {
					$tokens_used = (int) $row->tokens;
					$cost_month  = (float) $row->cost;
				}
			}
		}

		// Quota — read from option/filter; default 0 means "unlimited / not metered".
		$quota = (int) apply_filters( 'bizcity_twinchat_user_token_quota', 0, (int) $user_id );

		return rest_ensure_response( [
			'plan'         => (string) apply_filters( 'bizcity_twinchat_user_plan', 'free', (int) $user_id ),
			'tokens_month' => [
				'used'  => $tokens_used,
				'quota' => $quota,
				'cost'  => $cost_month,
			],
			'recent_calls' => $recent_calls,
			'admin_url'    => $admin_url,
		] );
	}

	/* ═══════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════ */

	/** @var array<int,string> */
	private $nb_title_cache = [];

	private function resolve_notebook_title( $notebook_id ) {
		$nb_id = (int) $notebook_id;
		if ( $nb_id <= 0 ) {
			return '';
		}
		if ( isset( $this->nb_title_cache[ $nb_id ] ) ) {
			return $this->nb_title_cache[ $nb_id ];
		}
		global $wpdb;
		$tbl   = $wpdb->prefix . 'bizcity_kg_notebooks';
		$title = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM {$tbl} WHERE id = %d LIMIT 1",
			$nb_id
		) );
		$this->nb_title_cache[ $nb_id ] = $title;
		return $title;
	}

	/** @var array<int,string> */
	private $user_name_cache = [];

	private function resolve_user_name( $user_id ) {
		$uid = (int) $user_id;
		if ( $uid <= 0 ) {
			return '';
		}
		if ( isset( $this->user_name_cache[ $uid ] ) ) {
			return $this->user_name_cache[ $uid ];
		}
		$user = get_userdata( $uid );
		$name = $user ? ( $user->display_name ?: $user->user_login ) : '';
		$this->user_name_cache[ $uid ] = $name;
		return $name;
	}
}
