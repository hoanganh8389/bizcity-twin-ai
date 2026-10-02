<?php
/**
 * BizCity_MCP_Resource_Service — MCP resources + prompts of the ONE site MCP server (PHASE-0.88 L2-2/3/4/6/7/8).
 *
 *   resources/list            filtered by principal (role → mode → user_id), cursor pagination (nextCursor)
 *   resources/read            contents[] {uri, mimeType, text}; unknown OR not allowed ⇒ JSON-RPC -32002 "Resource not found"
 *                             (never reveals that a resource exists)
 *   resources/templates/list  the bizcity-resource-uri@1 templates
 *   resources/subscribe       session-bound (a delegated request has no session ⇒ empty result, harmless)
 *   prompts/list|get          only the Guru(s) bound to the principal's number(s); instruction as a user message + an
 *                             embedded resource reference; engine rules never travel through prompts (no system role)
 *
 * Principals (same handlers for both):
 *  - delegated (zalo-hub cell, BizCity_MCP_Delegation::context): modes of the person, owned notebooks only
 *    (allowed_notebook_ids), the number of the turn (account_id), CRM customers scope D-TAA-7;
 *  - OAuth / API key: the key's user; a mode counts when the key holds one of its read scopes AND the user has that agent
 *    mode (agent-mode-access@1); notebooks = notebooks the user OWNS (R-TAA-14); Gurus = Gurus bound to numbers the user owns.
 *
 * One source (L2-3): `bizcity://pack/{kind}` and `bizcity://product/*` read through the SAME exporter `page()` registered on
 * `bizcity_twin_agent_pack_exporters` that the projection-pack route serves to the cell, so pack == resource by construction;
 * the pack's `as_of` is the resource's `annotations.lastModified`.
 *
 * Notifications (L2-4): the hooks that fire C-10 (Guru invalidate) and packs/invalidate also append
 * `notifications/resources/updated {uri}` / `notifications/resources/list_changed` / `notifications/prompts/list_changed`
 * into the SSE replay buffer (BizCity_MCP_Session_Store) of sessions that subscribed or listed. The cell never relies on it.
 *
 * Read-only. No LLM, no embedding, no write, no new table (subscriptions live in one non-autoloaded option). No PII in logs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      PHASE-0.88 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-2 — new file, MCP resources/prompts over KG-Hub, Guru, CRM and pack exporters.
final class BizCity_MCP_Resource_Service {

	const NOT_FOUND      = -32002;
	const INVALID_PARAMS = -32602;
	const PAGE           = 50;
	const PACK_PAGE      = 200;
	const PACK_MAX_ITEMS = 2000;
	const TEXT_MAX       = 8000;
	const GURU_NB_MAX    = 60000;
	const PACK_FILTER    = 'bizcity_twin_agent_pack_exporters';
	const SUBS_OPTION    = 'bizcity_mcp_resource_subs';
	const SUBS_MAX       = 200;
	const SUB_URIS_MAX   = 50;
	const PLATFORM       = 'ZALO_PERSONAL';
	/** Read scopes per mode for OAuth/API-key principals (the write scopes of a mode never open a read). */
	const READ_SCOPES    = array(
		'notebook'  => array( 'brain.read' ),
		'sales'     => array( 'business.read', 'report.read' ),
		'orders'    => array( 'order.read' ),
		'customers' => array( 'crm.read' ),
		'stock'     => array( 'business.read', 'commerce.read' ),
	);
	const PACK_NAMES     = array(
		'owner_knowledge' => 'Sổ tay của tôi (gói)',
		'notebook_meta'   => 'Danh sách sổ tay (gói)',
		'sales'           => 'Doanh số (gói)',
		'orders'          => 'Đơn hàng (gói)',
		'customers'       => 'Khách hàng (gói)',
		'stock'           => 'Tồn kho (gói)',
		'catalog'         => 'Danh mục sản phẩm (gói)',
		'astro_self'      => 'Lá số của tôi (gói)',
	);

	/**
	 * Test seams (all optional): can(mode, user_id): bool · notebook_rows(user_id): rows{id, owner_id, name, settings, stats,
	 * updated_at} · passage(pid): ?{id, notebook_id, source_id, content, updated_at} · source_title(sid): string ·
	 * accounts(): list{bridge_id, owner_user_id} · guru_of_account(account_id): int · default_guru(): int ·
	 * guru_profile(cid): array · guru_notebooks(cid): list{id, title, updated_at} · notebook_passages(nb, limit): rows ·
	 * customer(contact_id): ?pipeline row · customer_phone(cid): string · customer_tags(cid): string[] ·
	 * staff_name(user_id): string · customers_scope(user_id): string · exporters(): array · now(): int
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/** @var array<string,bool> per-request dedupe of (session, uri) notifications */
	private static $sent = array();

	public static function reset(): void {
		self::$sent = array();
	}

	/* ================================================================
	 *  JSON-RPC entry points (called by BizCity_MCP_HTTP_Controller::dispatch)
	 * ================================================================ */

	/** Error marker understood by the controller. */
	public static function error( int $code, string $message ): array {
		return array( '__jsonrpc_error' => array( 'code' => $code, 'message' => $message ) );
	}

	public static function is_error( $result ): bool {
		return is_array( $result ) && isset( $result['__jsonrpc_error'] );
	}

	/** resources/templates/list */
	public static function templates_list(): array {
		$out = array();
		foreach ( BizCity_MCP_Resource_URI::templates() as $tpl => $t ) {
			$out[] = array( 'uriTemplate' => $tpl, 'name' => $t['name'], 'description' => $t['description'], 'mimeType' => $t['mimeType'] );
		}
		return array( 'resourceTemplates' => $out );
	}

	/** resources/list */
	public static function list_resources( array $params, array $ctx, string $session_id = '' ): array {
		$p   = self::principal( $ctx );
		$all = array();
		foreach ( self::guru_refs( $p ) as $ref => $cid ) {
			$g = self::read_guru( $ref, $p, false );
			if ( $g ) {
				$all[] = self::entry( BizCity_MCP_Resource_URI::guru( $ref ), $g );
			}
			foreach ( self::guru_notebooks( $cid ) as $nb ) {
				$all[] = self::entry( BizCity_MCP_Resource_URI::build( BizCity_MCP_Resource_URI::T_GURU_NOTEBOOK, array( 'ref' => $ref, 'id' => (int) $nb['id'] ) ), array( 'name' => (string) $nb['title'], 'mimeType' => 'text/markdown', 'lastModified' => self::iso( (string) ( $nb['updated_at'] ?? '' ) ) ) );
			}
		}
		foreach ( self::notebooks( $p ) as $id => $row ) {
			$all[] = self::entry( BizCity_MCP_Resource_URI::notebook( $id ), array( 'name' => (string) $row['name'], 'mimeType' => 'application/json', 'lastModified' => self::iso( (string) ( $row['updated_at'] ?? '' ) ) ) );
		}
		if ( self::has_mode( $p, 'customers' ) ) {
			// One source: the `customers` pack already applies D-TAA-7 (a lead/agent gets only assigned contacts).
			$spec = self::exporter( 'customers' );
			if ( $spec && self::pack_allowed( $spec, $p ) ) {
				foreach ( self::pack_items( $spec, $p ) as $item ) {
					$cid = preg_match( '/^crm:(\d+)$/', (string) ( $item['contact_ref'] ?? '' ), $m ) ? (int) $m[1] : 0;
					$uri = $cid > 0 ? BizCity_MCP_Resource_URI::build( BizCity_MCP_Resource_URI::T_CUSTOMER, array( 'contact_id' => $cid ) ) : '';
					if ( '' !== $uri ) {
						$all[] = self::entry( $uri, array( 'name' => (string) ( $item['name'] ?? '' ), 'mimeType' => 'application/json', 'lastModified' => (string) ( $item['last_contact_at'] ?? '' ) ) );
					}
				}
			}
		}
		$catalog = self::read_catalog( $p, false );
		if ( $catalog ) {
			$all[] = self::entry( BizCity_MCP_Resource_URI::T_CATALOG, $catalog );
		}
		foreach ( self::exporters() as $kind => $spec ) {
			$r = self::read_pack( $kind, $p, false );
			if ( $r ) {
				$all[] = self::entry( BizCity_MCP_Resource_URI::pack( $kind ), $r );
			}
		}
		$offset = self::cursor_offset( $params['cursor'] ?? null );
		if ( $offset < 0 ) {
			return self::error( self::INVALID_PARAMS, 'cursor không hợp lệ.' );
		}
		$page = array_slice( $all, $offset, self::PAGE );
		$next = $offset + count( $page ) < count( $all ) ? self::cursor( $offset + count( $page ) ) : null;
		self::touch_session( $session_id, $ctx, null );
		return array( 'resources' => array_values( $page ), 'nextCursor' => $next );
	}

	/** resources/read */
	public static function read( array $params, array $ctx ): array {
		$uri = isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
		if ( '' === $uri ) {
			return self::error( self::INVALID_PARAMS, 'Thiếu params.uri.' );
		}
		$r = self::resolve( $uri, self::principal( $ctx ), true );
		if ( null === $r ) {
			return self::error( self::NOT_FOUND, 'Resource not found' );
		}
		$content = array( 'uri' => $uri, 'mimeType' => $r['mimeType'], 'text' => (string) $r['text'] );
		return array( 'contents' => array( $content ) );
	}

	/** resources/subscribe — no session (delegated / stateless POST) ⇒ empty result, nothing stored. */
	public static function subscribe( array $params, array $ctx, string $session_id = '' ) {
		$uri = isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
		if ( '' === $uri ) {
			return self::error( self::INVALID_PARAMS, 'Thiếu params.uri.' );
		}
		if ( BizCity_MCP_Delegation::is_delegated( $ctx ) || '' === $session_id ) {
			return (object) array();
		}
		if ( null === self::resolve( $uri, self::principal( $ctx ), false ) ) {
			return self::error( self::NOT_FOUND, 'Resource not found' );
		}
		self::touch_session( $session_id, $ctx, $uri );
		return (object) array();
	}

	/** resources/unsubscribe */
	public static function unsubscribe( array $params, array $ctx, string $session_id = '' ) {
		$uri = isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '';
		if ( '' !== $session_id && ! BizCity_MCP_Delegation::is_delegated( $ctx ) ) {
			$subs = self::subs();
			if ( isset( $subs[ $session_id ] ) ) {
				$subs[ $session_id ]['uris'] = array_values( array_diff( (array) $subs[ $session_id ]['uris'], array( $uri ) ) );
				self::save_subs( $subs );
			}
		}
		return (object) array();
	}

	/** prompts/list — only the Guru(s) bound to the principal's number(s). */
	public static function prompts_list( array $params, array $ctx, string $session_id = '' ): array {
		$p   = self::principal( $ctx );
		$out = array();
		foreach ( self::guru_refs( $p ) as $ref => $cid ) {
			$prof = self::guru_profile( $cid );
			$name = (string) ( $prof['guru']['name'] ?? '' );
			$out[] = array(
				'name'        => 'guru/' . $ref,
				'title'       => '' !== $name ? $name : 'Guru',
				'description' => 'Chỉ dẫn của Guru đang trả lời số của bạn' . ( '' !== $name ? ' (' . $name . ')' : '' ) . '.',
				'arguments'   => array(),
			);
		}
		self::touch_session( $session_id, $ctx, null );
		return array( 'prompts' => $out );
	}

	/** prompts/get {name: 'guru/<ref>'} */
	public static function prompts_get( array $params, array $ctx ): array {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
		$p    = self::principal( $ctx );
		$refs = self::guru_refs( $p );
		if ( ! preg_match( '/^guru\/(0|[1-9][0-9]{0,17})$/', $name, $m ) || ! isset( $refs[ (int) $m[1] ] ) ) {
			return self::error( self::INVALID_PARAMS, 'Prompt not found' );
		}
		$ref  = (int) $m[1];
		$prof = self::guru_profile( $refs[ $ref ] );
		$text = class_exists( 'BizCity_Guru_Context_Resolver' ) && method_exists( 'BizCity_Guru_Context_Resolver', 'instruction_text' )
			? BizCity_Guru_Context_Resolver::instruction_text( $prof )
			: trim( (string) ( $prof['instruction']['text'] ?? '' ) );
		$uri  = BizCity_MCP_Resource_URI::guru( $ref );
		return array(
			'description' => 'Chỉ dẫn của Guru ' . (string) ( $prof['guru']['name'] ?? '' ),
			'messages'    => array(
				array( 'role' => 'user', 'content' => array( 'type' => 'text', 'text' => $text ) ),
				array( 'role' => 'user', 'content' => array( 'type' => 'resource', 'resource' => array( 'uri' => $uri, 'mimeType' => 'application/json', 'text' => (string) wp_json_encode( self::public_profile( $prof ) ) ) ) ),
			),
		);
	}

	/* ================================================================
	 *  Principal
	 * ================================================================ */

	/**
	 * @return array{user_id:int,delegated:bool,modes:string[],notebook_ids:int[]|null,accounts:string[],account_id:string,role:string,user_hash:string}
	 *         notebook_ids null = "owned notebooks of user_id" (OAuth); an array = exactly these (delegated)
	 */
	public static function principal( array $ctx ): array {
		$uid       = (int) ( $ctx['user_id'] ?? 0 );
		$delegated = BizCity_MCP_Delegation::is_delegated( $ctx );
		if ( $delegated ) {
			$account = (string) ( $ctx['account_id'] ?? '' );
			return array(
				'user_id'      => $uid,
				'delegated'    => true,
				'modes'        => array_values( array_map( 'strval', (array) ( $ctx['modes'] ?? array() ) ) ),
				'notebook_ids' => array_values( array_map( 'intval', (array) ( $ctx['allowed_notebook_ids'] ?? array() ) ) ),
				'accounts'     => '' !== $account ? array( $account ) : array(),
				'account_id'   => $account,
				'role'         => (string) ( $ctx['role'] ?? '' ),
				'user_hash'    => (string) ( $ctx['user_hash'] ?? '' ),
			);
		}
		$scopes = array_map( 'strval', (array) ( $ctx['scopes'] ?? array() ) );
		$modes  = array();
		if ( $uid > 0 ) {
			foreach ( self::READ_SCOPES as $mode => $need ) {
				if ( array_intersect( $need, $scopes ) && self::can( $mode, $uid ) ) {
					$modes[] = $mode;
				}
			}
		}
		$accounts = array();
		if ( $uid > 0 ) {
			foreach ( self::accounts() as $acc ) {
				if ( (int) ( $acc['owner_user_id'] ?? 0 ) === $uid ) {
					$accounts[] = (string) $acc['bridge_id'];
				}
			}
		}
		return array(
			'user_id'      => $uid,
			'delegated'    => false,
			'modes'        => $modes,
			'notebook_ids' => null,
			'accounts'     => $accounts,
			'account_id'   => $accounts ? $accounts[0] : '',
			'role'         => 'owner',
			'user_hash'    => '',
		);
	}

	private static function has_mode( array $p, string $mode ): bool {
		return in_array( $mode, $p['modes'], true );
	}

	/* ================================================================
	 *  Resolution — one handler per template (markers counted by bin/validate-mcp-standard.mjs)
	 * ================================================================ */

	/**
	 * @return array{name:string,mimeType:string,lastModified:string,text?:string}|null null = not found or not allowed
	 */
	public static function resolve( string $uri, array $p, bool $with_content ): ?array {
		$parsed = BizCity_MCP_Resource_URI::parse( $uri );
		if ( null === $parsed ) {
			return null;
		}
		$a = $parsed['params'];
		switch ( $parsed['template'] ) {
			case BizCity_MCP_Resource_URI::T_GURU:
				// @mcp bizcity-mcp-standard@1 resource bizcity://guru/{ref}
				return self::read_guru( (int) $a['ref'], $p, $with_content );
			case BizCity_MCP_Resource_URI::T_GURU_NOTEBOOK:
				// @mcp bizcity-mcp-standard@1 resource bizcity://guru/{ref}/notebook/{id}
				return self::read_guru_notebook( (int) $a['ref'], (int) $a['id'], $p, $with_content );
			case BizCity_MCP_Resource_URI::T_NOTEBOOK:
				// @mcp bizcity-mcp-standard@1 resource bizcity://notebook/{id}
				return self::read_notebook( (int) $a['id'], $p, $with_content );
			case BizCity_MCP_Resource_URI::T_PASSAGE:
				// @mcp bizcity-mcp-standard@1 resource bizcity://notebook/{id}/passage/{pid}
				return self::read_passage( (int) $a['id'], (int) $a['pid'], $p, $with_content );
			case BizCity_MCP_Resource_URI::T_CUSTOMER:
				// @mcp bizcity-mcp-standard@1 resource bizcity://customer/{contact_id}/context
				return self::read_customer( (int) $a['contact_id'], $p, $with_content );
			case BizCity_MCP_Resource_URI::T_CATALOG:
				// @mcp bizcity-mcp-standard@1 resource bizcity://product/catalog
				return self::read_catalog( $p, $with_content );
			case BizCity_MCP_Resource_URI::T_PRODUCT:
				// @mcp bizcity-mcp-standard@1 resource bizcity://product/{id}
				return self::read_product( (int) $a['id'], $p, $with_content );
			case BizCity_MCP_Resource_URI::T_PACK:
				// @mcp bizcity-mcp-standard@1 resource bizcity://pack/{kind}
				return self::read_pack( (string) $a['kind'], $p, $with_content );
		}
		return null;
	}

	/* ── guru ────────────────────────────────────────────────────── */

	/** URI ref (0 = default) ⇒ character id, only for Gurus answering the principal's number(s) (R-GP-3: no enumeration). */
	public static function guru_refs( array $p ): array {
		$default = self::default_guru();
		$out     = array();
		foreach ( $p['accounts'] as $acc ) {
			$cid = self::guru_of_account( (string) $acc );
			if ( $cid > 0 ) {
				$out[ $cid === $default ? 0 : $cid ] = $cid;
			}
		}
		ksort( $out );
		return $out;
	}

	private static function read_guru( int $ref, array $p, bool $with_content ): ?array {
		$refs = self::guru_refs( $p );
		if ( ! isset( $refs[ $ref ] ) ) {
			return null;
		}
		$prof = self::guru_profile( $refs[ $ref ] );
		if ( ! $prof ) {
			return null;
		}
		$name = (string) ( $prof['guru']['name'] ?? '' );
		$out  = array( 'name' => 'Guru: ' . ( '' !== $name ? $name : 'mặc định' ), 'mimeType' => 'application/json', 'lastModified' => self::iso( (string) ( $prof['generated_at'] ?? '' ) ) );
		if ( $with_content ) {
			$out['text'] = (string) wp_json_encode( self::public_profile( $prof ) );
		}
		return $out;
	}

	/** Profile without internal fields and without the engine rules text (the cell keeps engine rules; not an MCP prompt). */
	private static function public_profile( array $prof ): array {
		unset( $prof['_character_id'] );
		if ( isset( $prof['compose'] ) && is_array( $prof['compose'] ) ) {
			unset( $prof['compose']['engine_rules_text'] );
		}
		return $prof;
	}

	private static function read_guru_notebook( int $ref, int $nb, array $p, bool $with_content ): ?array {
		$refs = self::guru_refs( $p );
		if ( ! isset( $refs[ $ref ] ) ) {
			return null;
		}
		$found = null;
		foreach ( self::guru_notebooks( $refs[ $ref ] ) as $row ) {
			if ( (int) $row['id'] === $nb ) {
				$found = $row;
				break;
			}
		}
		if ( null === $found ) {
			return null;
		}
		$out = array( 'name' => (string) $found['title'], 'mimeType' => 'text/markdown', 'lastModified' => self::iso( (string) ( $found['updated_at'] ?? '' ) ) );
		if ( $with_content ) {
			$out['text'] = self::markdown_of_notebook( (string) $found['title'], self::notebook_passages( $nb, 500 ), self::GURU_NB_MAX );
		}
		return $out;
	}

	/* ── notebook ────────────────────────────────────────────────── */

	/** Notebooks the principal may read: owned by user_id (R-TAA-14), mode notebook, delegated ⇒ ∩ allowed_notebook_ids. */
	private static function notebooks( array $p ): array {
		if ( $p['user_id'] <= 0 || ! self::has_mode( $p, 'notebook' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::notebook_rows( $p['user_id'] ) as $row ) {
			$row = (array) $row;
			$id  = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 || (int) ( $row['owner_id'] ?? 0 ) !== $p['user_id'] ) {
				continue;
			}
			if ( is_array( $p['notebook_ids'] ) && ! in_array( $id, $p['notebook_ids'], true ) ) {
				continue;
			}
			$out[ $id ] = $row;
		}
		ksort( $out );
		return $out;
	}

	private static function read_notebook( int $id, array $p, bool $with_content ): ?array {
		$nbs = self::notebooks( $p );
		if ( ! isset( $nbs[ $id ] ) ) {
			return null;
		}
		$row  = $nbs[ $id ];
		$st   = is_array( $row['settings'] ?? null ) ? $row['settings'] : (array) json_decode( (string) ( $row['settings'] ?? '' ), true );
		$stat = is_array( $row['stats'] ?? null ) ? $row['stats'] : (array) json_decode( (string) ( $row['stats'] ?? '' ), true );
		$as   = self::iso( (string) ( $row['updated_at'] ?? '' ) );
		$out  = array( 'name' => (string) ( $row['name'] ?? '' ), 'mimeType' => 'application/json', 'lastModified' => $as );
		if ( $with_content ) {
			$out['text'] = (string) wp_json_encode( array( 'id' => $id, 'title' => (string) ( $row['name'] ?? '' ), 'day_key' => (string) ( $st['day_key'] ?? '' ), 'sources' => (int) ( $stat['sources'] ?? 0 ), 'as_of' => $as ) );
		}
		return $out;
	}

	private static function read_passage( int $nb, int $pid, array $p, bool $with_content ): ?array {
		$nbs = self::notebooks( $p );
		if ( ! isset( $nbs[ $nb ] ) ) {
			return null;
		}
		$row = self::passage( $pid );
		if ( ! is_array( $row ) || (int) ( $row['notebook_id'] ?? 0 ) !== $nb ) {
			return null;
		}
		$text = trim( (string) ( $row['content'] ?? '' ) );
		if ( '' === $text ) {
			return null;
		}
		$sid   = (int) ( $row['source_id'] ?? 0 );
		$title = $sid > 0 ? self::source_title( $sid ) : '';
		$out   = array( 'name' => '' !== $title ? $title : (string) ( $nbs[ $nb ]['name'] ?? '' ), 'mimeType' => 'text/markdown', 'lastModified' => self::iso( (string) ( $row['updated_at'] ?? $row['created_at'] ?? '' ) ) );
		if ( $with_content ) {
			$out['text'] = self::cut( $text, self::TEXT_MAX );
		}
		return $out;
	}

	/* ── customer (L2-7) ─────────────────────────────────────────── */

	private static function read_customer( int $contact_id, array $p, bool $with_content ): ?array {
		if ( $p['user_id'] <= 0 || ! self::has_mode( $p, 'customers' ) ) {
			return null;
		}
		$row = self::customer( $contact_id );
		if ( ! is_array( $row ) ) {
			return null;
		}
		// D-TAA-7: a CRM lead/agent reads only the contacts assigned to them (same rule as the `customers` pack).
		if ( 'person' === self::customers_scope( $p['user_id'] ) && (int) ( $row['owner_id'] ?? 0 ) !== $p['user_id'] ) {
			return null;
		}
		$last = max( (int) ( $row['last_activity_ts'] ?? 0 ), (int) ( $row['last_out_ts'] ?? 0 ) );
		$name = self::cut( (string) ( $row['name'] ?? '' ), 120 );
		$out  = array( 'name' => $name, 'mimeType' => 'application/json', 'lastModified' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : '' );
		if ( $with_content ) {
			$stage  = (string) ( $row['stage'] ?? '' );
			$labels = class_exists( 'BizCity_CRM_Customer_Pipeline' ) ? BizCity_CRM_Customer_Pipeline::LABELS : array();
			$owner  = (int) ( $row['owner_id'] ?? 0 );
			$out['text'] = (string) wp_json_encode( array(
				'contact_id'      => $contact_id,
				'name'            => $name,
				'phone_masked'    => self::mask_phone( self::customer_phone( $contact_id ) ),
				'stage'           => (string) ( $labels[ $stage ] ?? $stage ),
				'tags'            => array_values( array_slice( array_map( static function ( $t ) { return BizCity_MCP_Resource_Service::cut( (string) $t, 60 ); }, self::customer_tags( $contact_id ) ), 0, 20 ) ),
				'orders'          => (int) ( $row['ordered'] ?? 0 ),
				'total_spent'     => round( (float) ( $row['revenue'] ?? 0 ), 2 ),
				'last_order_at'   => ! empty( $row['last_order_ts'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $row['last_order_ts'] ) : '',
				'last_contact_at' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : '',
				'assigned_staff'  => $owner > 0 ? self::cut( self::staff_name( $owner ), 80 ) : '',
			) );
		}
		return $out;
	}

	/* ── product (L2-6) ──────────────────────────────────────────── */

	private static function read_catalog( array $p, bool $with_content ): ?array {
		$spec = self::exporter( 'catalog' );
		if ( ! $spec || ! self::pack_allowed( $spec, $p ) ) {
			return null;
		}
		$st  = (array) call_user_func( $spec['stats'], self::pack_ctx( $p ) );
		$out = array( 'name' => 'Danh mục sản phẩm', 'mimeType' => 'application/json', 'lastModified' => (string) ( $st['as_of'] ?? '' ) );
		if ( $with_content ) {
			$out['text'] = (string) wp_json_encode( array( 'version' => (string) ( $st['version'] ?? '' ), 'as_of' => (string) ( $st['as_of'] ?? '' ), 'items' => self::pack_items( $spec, $p ) ) );
		}
		return $out;
	}

	private static function read_product( int $id, array $p, bool $with_content ): ?array {
		$spec = self::exporter( 'catalog' );
		if ( ! $spec || ! self::pack_allowed( $spec, $p ) ) {
			return null;
		}
		foreach ( self::pack_items( $spec, $p ) as $item ) {
			if ( (int) ( $item['product_id'] ?? 0 ) === $id ) {
				$st  = (array) call_user_func( $spec['stats'], self::pack_ctx( $p ) );
				$out = array( 'name' => (string) ( $item['name'] ?? '' ), 'mimeType' => 'application/json', 'lastModified' => (string) ( $st['as_of'] ?? '' ) );
				if ( $with_content ) {
					$out['text'] = (string) wp_json_encode( $item );
				}
				return $out;
			}
		}
		return null;
	}

	/* ── pack (L2-3) ─────────────────────────────────────────────── */

	private static function read_pack( string $kind, array $p, bool $with_content ): ?array {
		$spec = self::exporter( $kind );
		if ( ! $spec || ! self::pack_allowed( $spec, $p ) ) {
			return null;
		}
		$st  = (array) call_user_func( $spec['stats'], self::pack_ctx( $p ) );
		$out = array( 'name' => self::PACK_NAMES[ $kind ] ?? ( $kind . ' (gói)' ), 'mimeType' => 'application/json', 'lastModified' => (string) ( $st['as_of'] ?? '' ) );
		if ( $with_content ) {
			$out['text'] = (string) wp_json_encode( array( 'kind' => $kind, 'version' => (string) ( $st['version'] ?? '' ), 'as_of' => (string) ( $st['as_of'] ?? '' ), 'items' => self::pack_items( $spec, $p ) ) );
		}
		return $out;
	}

	/** Mode of the kind from the exporter registry ('*pack' gate) + source present + a person behind the request. */
	private static function pack_allowed( array $spec, array $p ): bool {
		if ( $p['user_id'] <= 0 || ! self::has_mode( $p, (string) $spec['mode'] ) ) {
			return false;
		}
		return ! isset( $spec['available'] ) || ! is_callable( $spec['available'] ) || (bool) call_user_func( $spec['available'] );
	}

	/** The exporter's ctx shape (same keys the projection-pack route passes: the acting person is owner_user_id). */
	private static function pack_ctx( array $p ): array {
		return array(
			'account_id'    => (string) $p['account_id'],
			'owner_user_id' => (int) $p['user_id'],
			'owner_uid_set' => true,
			'enabled'       => true,
			'user_hash'     => (string) $p['user_hash'],
			'role'          => '' !== $p['role'] ? (string) $p['role'] : 'owner',
		);
	}

	/** Every item of a kind through the exporter's page() — exactly what the cell pulls page by page. */
	public static function pack_items( array $spec, array $p ): array {
		$ctx   = self::pack_ctx( $p );
		$items = array();
		$after = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$page  = (array) call_user_func( $spec['page'], $ctx, $after, self::PACK_PAGE );
			$items = array_merge( $items, array_values( (array) ( $page['items'] ?? array() ) ) );
			if ( empty( $page['more'] ) || count( $items ) >= self::PACK_MAX_ITEMS || (int) ( $page['last_id'] ?? $after ) <= $after ) {
				break;
			}
			$after = (int) $page['last_id'];
		}
		return array_slice( $items, 0, self::PACK_MAX_ITEMS );
	}

	/** Same registry + validation as BizCity_Zalo_Pack_REST::exporters() (one source). */
	public static function exporters(): array {
		$raw = isset( self::$readers['exporters'] ) ? (array) call_user_func( self::$readers['exporters'] ) : (array) apply_filters( self::PACK_FILTER, array() );
		$out = array();
		foreach ( $raw as $kind => $spec ) {
			if ( is_array( $spec ) && isset( $spec['mode'], $spec['stats'], $spec['page'] ) && is_callable( $spec['stats'] ) && is_callable( $spec['page'] ) ) {
				$out[ sanitize_key( (string) $kind ) ] = $spec + array( 'audience' => 'owner_agent' );
			}
		}
		return $out;
	}

	private static function exporter( string $kind ): ?array {
		$all = self::exporters();
		return $all[ $kind ] ?? null;
	}

	/* ================================================================
	 *  Notifications (L2-4)
	 * ================================================================ */

	public static function boot(): void {
		add_action( 'bizcity_kg_after_ingest_central', array( __CLASS__, 'on_ingest_central' ), 30, 2 );
		add_action( 'bizcity_kg_after_extension_ingest', array( __CLASS__, 'on_extension_ingest' ), 30, 3 );
		add_action( 'bizcity_kg_after_source_delete', array( __CLASS__, 'on_source_delete' ), 30, 2 );
		add_action( 'bizcity_kg_notebook_stats_dirty', array( __CLASS__, 'on_notebook' ), 30, 1 );
		add_action( 'bizcity_kg_notebook_deleted', array( __CLASS__, 'on_notebook_deleted' ), 30, 1 );
		add_action( 'bizcity_kg_guru_attached', array( __CLASS__, 'on_binding' ), 30, 2 );
		add_action( 'bizcity_kg_guru_detached', array( __CLASS__, 'on_binding' ), 30, 2 );
		add_action( 'bizcity_knowledge_character_saved', array( __CLASS__, 'on_guru_saved' ), 30, 1 );
		add_action( 'bizcity_knowledge_character_deleted', array( __CLASS__, 'on_guru_deleted' ), 30, 1 );
		add_action( 'bizcity_bot_config_changed', array( __CLASS__, 'on_bot_config' ), 30, 2 );
		add_action( 'bizcity_agent_modes_changed', array( __CLASS__, 'on_modes_changed' ), 30, 0 );
		add_action( 'bizcity_twin_agent_packs_changed', array( __CLASS__, 'on_packs_changed' ), 30, 1 );
	}

	public static function on_ingest_central( $source_id = 0, $scope = array() ): void {
		self::on_notebook( self::notebook_of_scope( $scope ) );
	}

	public static function on_extension_ingest( $source_id = 0, $passage_id = 0, $scope = array() ): void {
		self::on_notebook( self::notebook_of_scope( $scope ) );
	}

	public static function on_source_delete( $source_id = 0, $scope_id = '' ): void {
		if ( is_numeric( $scope_id ) ) {
			self::on_notebook( (int) $scope_id );
		}
	}

	public static function on_notebook( $notebook_id = 0 ): void {
		$nb = (int) $notebook_id;
		if ( $nb > 0 ) {
			self::notify_updated( array( 'bizcity://notebook/' . $nb, 'bizcity://notebook/' . $nb . '/*', 'bizcity://guru/*/notebook/' . $nb, 'bizcity://pack/owner_knowledge', 'bizcity://pack/notebook_meta' ) );
		}
	}

	public static function on_notebook_deleted( $notebook_id = 0 ): void {
		self::on_notebook( $notebook_id );
		self::notify_list_changed( true, false );
	}

	public static function on_binding( $notebook_id = 0, $guru_uuid = '' ): void {
		self::notify_updated( array( 'bizcity://guru/*' ) );
		self::notify_list_changed( true, false );
	}

	public static function on_guru_saved( $character_id = 0 ): void {
		$cid = (int) $character_id;
		if ( $cid <= 0 ) {
			return;
		}
		$ref = $cid === self::default_guru() ? 0 : $cid;
		self::notify_updated( array( 'bizcity://guru/' . $ref, 'bizcity://guru/' . $ref . '/*' ) );
		self::notify_list_changed( false, true );
	}

	public static function on_guru_deleted( $character_id = 0 ): void {
		self::notify_list_changed( true, true );
	}

	public static function on_bot_config( $scope = '', $id = 0 ): void {
		if ( 'character' === $scope && (int) $id > 0 ) {
			self::on_guru_saved( (int) $id );
			return;
		}
		self::notify_updated( array( 'bizcity://guru/*' ) ); // which number → which Guru may have moved
		self::notify_list_changed( true, true );
	}

	public static function on_modes_changed(): void {
		self::notify_list_changed( true, false );
	}

	/** @param string[] $kinds */
	public static function on_packs_changed( $kinds = array() ): void {
		$uris = array();
		foreach ( (array) $kinds as $kind ) {
			$kind = sanitize_key( (string) $kind );
			if ( '' === $kind ) {
				continue;
			}
			$uris[] = 'bizcity://pack/' . $kind;
			if ( 'catalog' === $kind || 'stock' === $kind ) {
				$uris[] = 'bizcity://product/*';
			}
			if ( 'customers' === $kind || 'orders' === $kind ) {
				$uris[] = 'bizcity://customer/*';
			}
		}
		if ( $uris ) {
			self::notify_updated( array_values( array_unique( $uris ) ) );
		}
	}

	/**
	 * Append `notifications/resources/updated {uri}` to every live session subscribed to a matching URI. A pattern may use `*`
	 * for one or more path segments (`bizcity://notebook/77/*`); the notification carries the SUBSCRIBED uri.
	 *
	 * @param string[] $patterns
	 * @return int notifications appended
	 */
	public static function notify_updated( array $patterns ): int {
		$subs = self::subs();
		if ( ! $subs ) {
			return 0;
		}
		$n       = 0;
		$changed = false;
		foreach ( $subs as $sid => $rec ) {
			foreach ( (array) ( $rec['uris'] ?? array() ) as $uri ) {
				if ( ! self::matches_any( (string) $uri, $patterns ) || isset( self::$sent[ $sid . '|' . $uri ] ) ) {
					continue;
				}
				self::$sent[ $sid . '|' . $uri ] = true;
				if ( ! self::append( (string) $sid, array( 'jsonrpc' => '2.0', 'method' => 'notifications/resources/updated', 'params' => array( 'uri' => (string) $uri ) ) ) ) {
					unset( $subs[ $sid ] );
					$changed = true;
					break;
				}
				$n++;
			}
		}
		if ( $changed ) {
			self::save_subs( $subs );
		}
		return $n;
	}

	/** `notifications/resources/list_changed` and/or `notifications/prompts/list_changed` to every live session that listed. */
	public static function notify_list_changed( bool $resources = true, bool $prompts = false ): int {
		$subs    = self::subs();
		$n       = 0;
		$changed = false;
		foreach ( array_keys( $subs ) as $sid ) {
			foreach ( array( 'notifications/resources/list_changed' => $resources, 'notifications/prompts/list_changed' => $prompts ) as $method => $on ) {
				if ( ! $on || isset( self::$sent[ $sid . '|' . $method ] ) ) {
					continue;
				}
				self::$sent[ $sid . '|' . $method ] = true;
				if ( ! self::append( (string) $sid, array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) array() ) ) ) {
					unset( $subs[ $sid ] );
					$changed = true;
					break;
				}
				$n++;
			}
		}
		if ( $changed ) {
			self::save_subs( $subs );
		}
		return $n;
	}

	private static function append( string $sid, array $msg ): bool {
		return class_exists( 'BizCity_MCP_Session_Store' ) && BizCity_MCP_Session_Store::append( $sid, 'message', $msg );
	}

	private static function matches_any( string $uri, array $patterns ): bool {
		foreach ( $patterns as $pat ) {
			$pat = (string) $pat;
			if ( $pat === $uri ) {
				return true;
			}
			if ( false !== strpos( $pat, '*' ) ) {
				$re = '#^' . str_replace( '\*', '[^/]+(?:/[^/]+)*', preg_quote( $pat, '#' ) ) . '$#';
				if ( preg_match( $re, $uri ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Register a session (resources/list, prompts/list, subscribe) so it gets list_changed; `$uri` adds a subscription. */
	private static function touch_session( string $session_id, array $ctx, ?string $uri ): void {
		if ( '' === $session_id || BizCity_MCP_Delegation::is_delegated( $ctx ) ) {
			return;
		}
		$subs = self::subs();
		$rec  = $subs[ $session_id ] ?? array( 'uris' => array() );
		if ( null !== $uri && ! in_array( $uri, (array) $rec['uris'], true ) ) {
			$rec['uris'][] = $uri;
			$rec['uris']   = array_slice( array_values( (array) $rec['uris'] ), -self::SUB_URIS_MAX );
		}
		$rec['exp'] = self::now() + ( class_exists( 'BizCity_MCP_Session_Store' ) ? BizCity_MCP_Session_Store::TTL : 1800 );
		unset( $subs[ $session_id ] );
		$subs[ $session_id ] = $rec; // most recent last; the oldest fall off first
		self::save_subs( array_slice( $subs, -self::SUBS_MAX, null, true ) );
	}

	/** @return array<string,array{uris:string[],exp:int}> live entries only */
	public static function subs(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::SUBS_OPTION, array() ) : array();
		$now = self::now();
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $sid => $rec ) {
			if ( is_array( $rec ) && (int) ( $rec['exp'] ?? 0 ) > $now ) {
				$out[ (string) $sid ] = array( 'uris' => array_values( array_map( 'strval', (array) ( $rec['uris'] ?? array() ) ) ), 'exp' => (int) $rec['exp'] );
			}
		}
		return $out;
	}

	private static function save_subs( array $subs ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::SUBS_OPTION, $subs, false );
		}
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	private static function entry( string $uri, array $r ): array {
		$ann = array( 'audience' => array( 'assistant' ) );
		if ( '' !== (string) ( $r['lastModified'] ?? '' ) ) {
			$ann['lastModified'] = (string) $r['lastModified'];
		}
		return array( 'uri' => $uri, 'name' => (string) $r['name'], 'mimeType' => (string) $r['mimeType'], 'annotations' => $ann );
	}

	private static function cursor( int $offset ): string {
		return rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'o' => $offset ) ) ), '+/', '-_' ), '=' );
	}

	/** -1 = malformed. */
	private static function cursor_offset( $cursor ): int {
		if ( null === $cursor || '' === $cursor ) {
			return 0;
		}
		if ( ! is_string( $cursor ) ) {
			return -1;
		}
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $cursor ) % 4 ) % 4 ), true );
		$d   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $d ) && isset( $d['o'] ) && is_int( $d['o'] ) && $d['o'] >= 0 ? $d['o'] : -1;
	}

	private static function markdown_of_notebook( string $title, array $rows, int $max ): string {
		$md = '# ' . trim( $title ) . "\n";
		foreach ( $rows as $r ) {
			$text = trim( (string) ( $r['content'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$sid   = (int) ( $r['source_id'] ?? 0 );
			$head  = $sid > 0 ? self::source_title( $sid ) : '';
			$block = "\n" . ( '' !== $head ? '## ' . $head . "\n\n" : '' ) . self::cut( $text, 4000 ) . "\n";
			if ( mb_strlen( $md . $block ) > $max ) {
				$md .= "\n…\n";
				break;
			}
			$md .= $block;
		}
		return $md;
	}

	/** "…" + last 3 digits; fewer than 4 digits is not a phone ⇒ '' (same rule as the packs and the cell). */
	public static function mask_phone( string $phone ): string {
		$d = (string) preg_replace( '/\D+/', '', $phone );
		return strlen( $d ) >= 4 ? '…' . substr( $d, -3 ) : '';
	}

	public static function cut( string $s, int $max ): string {
		$s = trim( (string) preg_replace( '/\s+/u', ' ', function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	private static function iso( string $mysql ): string {
		$mysql = trim( $mysql );
		if ( '' === $mysql ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $mysql ) ) {
			return $mysql;
		}
		$ts = strtotime( false !== strpos( $mysql, 'T' ) ? $mysql : $mysql . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
	}

	private static function notebook_of_scope( $scope ): int {
		$scope = is_array( $scope ) ? $scope : ( is_object( $scope ) ? (array) $scope : array() );
		if ( isset( $scope['notebook_id'] ) ) {
			return (int) $scope['notebook_id'];
		}
		return 'notebook' === (string) ( $scope['scope_type'] ?? '' ) ? (int) ( $scope['scope_id'] ?? 0 ) : 0;
	}

	/* ================================================================
	 *  Readers (defaults = live site; tests replace them)
	 * ================================================================ */

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function can( string $mode, int $user_id ): bool {
		if ( isset( self::$readers['can'] ) ) {
			return (bool) call_user_func( self::$readers['can'], $mode, $user_id );
		}
		return ! class_exists( 'BizCity_Agent_Mode_Access' ) || BizCity_Agent_Mode_Access::can( $mode, $user_id );
	}

	private static function notebook_rows( int $user_id ): array {
		if ( isset( self::$readers['notebook_rows'] ) ) {
			return (array) call_user_func( self::$readers['notebook_rows'], $user_id );
		}
		return class_exists( 'BizCity_KG_Notebook_Service' ) ? (array) BizCity_KG_Notebook_Service::instance()->list_for_user( $user_id, array( 'limit' => 500 ) ) : array();
	}

	private static function passage( int $pid ): ?array {
		if ( isset( self::$readers['passage'] ) ) {
			$r = call_user_func( self::$readers['passage'], $pid );
			return is_array( $r ) ? $r : null;
		}
		global $wpdb;
		if ( $pid <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return null;
		}
		$tbl  = BizCity_KG_Database::instance()->tbl_passages();
		$file = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'storage_ver', 'file_shard', 'file_offset', 'file_length' ) )
			? ', storage_ver, file_shard, file_offset, file_length' : '';
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT id, notebook_id, source_id, content, created_at, updated_at{$file} FROM {$tbl} WHERE id = %d", $pid ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if ( '' !== $file && class_exists( 'BizCity_KG_Content_Router' ) ) {
			$rows = array( $row );
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
			$row = $rows[0];
		}
		return $row;
	}

	private static function notebook_passages( int $nb, int $limit ): array {
		if ( isset( self::$readers['notebook_passages'] ) ) {
			return (array) call_user_func( self::$readers['notebook_passages'], $nb, $limit );
		}
		global $wpdb;
		if ( $nb <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$tbl  = BizCity_KG_Database::instance()->tbl_passages();
		$file = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'storage_ver', 'file_shard', 'file_offset', 'file_length' ) )
			? ', storage_ver, file_shard, file_offset, file_length' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, notebook_id, source_id, content, created_at, updated_at{$file} FROM {$tbl} WHERE notebook_id = %d ORDER BY id ASC LIMIT %d", $nb, $limit ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( $rows && '' !== $file && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		return $rows;
	}

	private static function source_title( int $sid ): string {
		if ( isset( self::$readers['source_title'] ) ) {
			return (string) call_user_func( self::$readers['source_title'], $sid );
		}
		global $wpdb;
		if ( $sid <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . BizCity_KG_Database::instance()->tbl_sources() . ' WHERE id = %d', $sid ) );
	}

	/** @return list<array{bridge_id:string,owner_user_id:int}> zalo-hub numbers of this site */
	private static function accounts(): array {
		if ( isset( self::$readers['accounts'] ) ) {
			return (array) call_user_func( self::$readers['accounts'] );
		}
		return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? (array) BizCity_Zalo_Hub_Config_Sync::accounts() : array();
	}

	private static function default_guru(): int {
		if ( isset( self::$readers['default_guru'] ) ) {
			return (int) call_user_func( self::$readers['default_guru'] );
		}
		return class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::default_character_id( false ) : 0;
	}

	/** The Guru answering a number: bound one, else the default Guru when AI is on (gate 0, same rule as the config sync). */
	private static function guru_of_account( string $account ): int {
		if ( isset( self::$readers['guru_of_account'] ) ) {
			return (int) call_user_func( self::$readers['guru_of_account'], $account );
		}
		if ( '' === $account || ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return 0;
		}
		$b    = BizCity_Channel_Binding::resolve( self::PLATFORM, $account );
		$cid  = is_array( $b ) ? (int) ( $b['character_id'] ?? 0 ) : 0;
		$mode = is_array( $b ) ? (string) ( $b['mode'] ?? '' ) : '';
		if ( $cid <= 0 && in_array( $mode, array( 'auto', 'hybrid' ), true ) ) {
			$cid = self::default_guru();
		}
		return $cid;
	}

	private static function guru_profile( int $cid ): array {
		if ( isset( self::$readers['guru_profile'] ) ) {
			return (array) call_user_func( self::$readers['guru_profile'], $cid );
		}
		return class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::profile( $cid ) : array();
	}

	/** Notebooks the Guru actually serves (C-4 rule: knowledge = base+notebooks AND attached). @return list<array{id:int,title:string,updated_at:string}> */
	private static function guru_notebooks( int $cid ): array {
		if ( isset( self::$readers['guru_notebooks'] ) ) {
			return (array) call_user_func( self::$readers['guru_notebooks'], $cid );
		}
		if ( ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) {
			return array();
		}
		$scope = BizCity_Guru_Context_Resolver::scope( $cid );
		if ( 'base+notebooks' !== ( $scope['knowledge'] ?? 'base' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_map( 'intval', (array) ( $scope['notebook_ids'] ?? array() ) ) as $id ) {
			$row   = class_exists( 'BizCity_KG_Notebook_Service' ) ? BizCity_KG_Notebook_Service::instance()->get( $id ) : null;
			$out[] = array( 'id' => $id, 'title' => is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '', 'updated_at' => is_array( $row ) ? (string) ( $row['updated_at'] ?? '' ) : '' );
		}
		return $out;
	}

	private static function customer( int $contact_id ): ?array {
		if ( isset( self::$readers['customer'] ) ) {
			$r = call_user_func( self::$readers['customer'], $contact_id );
			return is_array( $r ) ? $r : null;
		}
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
			return null;
		}
		$rows = BizCity_CRM_Customer_Pipeline::rows( array( $contact_id ) );
		return isset( $rows[ $contact_id ] ) ? $rows[ $contact_id ] : null;
	}

	private static function customers_scope( int $user_id ): string {
		if ( isset( self::$readers['customers_scope'] ) ) {
			return (string) call_user_func( self::$readers['customers_scope'], $user_id );
		}
		return class_exists( 'BizCity_CRM_Agent_Mode_Delegate' ) ? BizCity_CRM_Agent_Mode_Delegate::customers_scope( $user_id ) : 'shop';
	}

	private static function customer_phone( int $contact_id ): string {
		if ( isset( self::$readers['customer_phone'] ) ) {
			return (string) call_user_func( self::$readers['customer_phone'], $contact_id );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT phone FROM `' . BizCity_CRM_DB_Installer_V2::tbl_contacts() . '` WHERE id = %d', $contact_id ) );
	}

	/** Labels on the contact's conversations. @return string[] */
	private static function customer_tags( int $contact_id ): array {
		if ( isset( self::$readers['customer_tags'] ) ) {
			return array_map( 'strval', (array) call_user_func( self::$readers['customer_tags'], $contact_id ) );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array();
		}
		$l   = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$cl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$c   = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$out = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT l.title FROM `{$l}` l INNER JOIN `{$cl}` cl ON cl.label_id = l.id INNER JOIN `{$c}` c ON c.id = cl.conversation_id INNER JOIN `{$ci}` ci ON ci.id = c.contact_inbox_id WHERE ci.contact_id = %d LIMIT 20", $contact_id ) );
		return array_map( 'strval', is_array( $out ) ? $out : array() );
	}

	private static function staff_name( int $user_id ): string {
		if ( isset( self::$readers['staff_name'] ) ) {
			return (string) call_user_func( self::$readers['staff_name'], $user_id );
		}
		$u = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		return is_object( $u ) && isset( $u->display_name ) ? (string) $u->display_name : '';
	}
}
