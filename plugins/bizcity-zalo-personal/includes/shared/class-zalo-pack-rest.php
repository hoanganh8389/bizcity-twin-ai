<?php
/**
 * BizCity Zalo Personal — projection packs of a zalo-hub number (PHASE-0.87 CL-1, contract projection-pack@1.2).
 *
 *   GET bizcity-channel/v1/zalo-bridge/packs?account_id=&principal=                        → list (ETag / 304)
 *   GET bizcity-channel/v1/zalo-bridge/packs/{kind}?account_id=&principal=&cursor=&limit=  → one page of a kind (full:true)
 *
 * Wave 2 (W2-3, doc 50 §5.3): `principal=<user_hash>` picks WHOSE packs — the owner (absent / owner hash) or an active
 * staff member of THIS number (BizCity_Zalo_Agent_Principals::by_hash, computed here, never trusted from the cell). The
 * exporters keep reading `ctx.owner_user_id` as "the acting person". Unknown hash ⇒ 403 not_own_notebook.
 *
 * Hub → site only (HB-1), the same per-account callback Bearer as /inbound. The data comes from exporters registered by
 * their owners on `bizcity_twin_agent_pack_exporters` (kind => {mode, audience, stats(ctx), page(ctx, after_id, limit)});
 * this class only gates and shapes. Owner packs are served for the number's WordPress owner (`owner_user_id`, first
 * user_id) and only when: the number has a "UID chủ tài khoản", Agent của chủ is on, and that owner has the kind's mode
 * (agent-mode-access@1). Read-only; no LLM, no embedding, no write (R-TAA-6).
 *
 * // @axis twin-agent-axis@1 seam SEAM-2
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 (2026-09-30)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Pack_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Pack_REST {

	const NS        = 'bizcity-channel/v1';
	const CONTRACT  = 'projection-pack@1.2.0';
	const FILTER    = 'bizcity_twin_agent_pack_exporters';
	const LIMIT_MAX = 200;
	const PLATFORM  = 'ZALO_PERSONAL';

	/** [2026-10-01 Claude Opus 5.5] PHASE-0.88 R-MCP-OAUTH-ID §0b.5 — audience-base kinds, never role-group filtered (cell BASE_AUDIENCE_PACK_KINDS + Guru C-4). */
	const BASE_AUDIENCE_KINDS = array( 'catalog', 'knowledge' );

	/**
	 * Test seams: token(account_id): string · account(account_id): ?{owner_user_id} · policy(account_id): array ·
	 * can(mode, user_id): bool · user_hash(user_id): string · registry(): array<mode,{packs}>.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-bridge/packs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as /inbound.
		) );
		register_rest_route( self::NS, '/zalo-bridge/packs/(?P<kind>[a-z_]{2,40})', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_page' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle_list( WP_REST_Request $request ): WP_REST_Response {
		$account = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		if ( ! self::authorized( $request, $account ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$ctx = self::context( $account, (string) $request->get_param( 'principal' ) );
		if ( null === $ctx ) {
			return self::unknown_principal();
		}
		$packs = array();
		foreach ( self::exporters() as $kind => $spec ) {
			$row = array( 'kind' => $kind, 'audience' => (string) $spec['audience'], 'mode' => (string) $spec['mode'] );
			if ( ! self::source_available( $spec ) ) {
				$packs[] = $row + array( 'enabled' => false, 'reason' => 'source_plugin_missing' );
				continue;
			}
			$why = self::refusal( $ctx, (string) $spec['mode'] );
			if ( null !== $why ) {
				$packs[] = $row + array( 'enabled' => false, 'reason' => $why['reason'] );
				continue;
			}
			// [2026-10-01 Claude Opus 5.5] PHASE-0.88 R-MCP-OAUTH-ID §0b.5 — the pack is the cache of bizcity://pack/{kind}: a kind
			// whose MCP role group the principal's WP roles lack is listed enabled:false so the cell deletes its old copy.
			if ( ! self::role_group_allows( $ctx, $kind, $spec ) ) {
				$packs[] = $row + array( 'enabled' => false, 'reason' => 'role_group_denied' );
				continue;
			}
			$st = (array) call_user_func( $spec['stats'], $ctx );
			// CL-15 (D-TAA-7, taa/packs.customers.person.json): `scope: person` ⇒ the cell keeps this kind per user_hash and never
			// falls back to the shop copy. Only on the list; pages stay as before.
			$scope   = isset( $st['scope'] ) ? array( 'scope' => (string) $st['scope'] ) : array();
			$packs[] = $row + $scope + array( 'version' => (string) ( $st['version'] ?? '' ), 'as_of' => (string) ( $st['as_of'] ?? '' ), 'bytes' => (int) ( $st['bytes'] ?? 0 ), 'items' => (int) ( $st['items'] ?? 0 ), 'enabled' => true );
		}
		// Kinds a registered mode names but no exporter serves yet: listed, never served.
		foreach ( self::registry() as $mode => $m ) {
			foreach ( (array) ( $m['packs'] ?? array() ) as $kind ) {
				if ( ! isset( self::exporters()[ $kind ] ) ) {
					$packs[] = array( 'kind' => (string) $kind, 'audience' => 'owner_agent', 'mode' => (string) $mode, 'enabled' => false, 'reason' => 'not_in_wave' );
				}
			}
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-3 — additive: each kind names the MCP resource it caches (pack = cache of the
		// resource; bizcity://pack/{kind} reads the same exporter page()).
		foreach ( $packs as $i => $row ) {
			$packs[ $i ]['uri'] = 'bizcity://pack/' . $row['kind'];
		}
		$body = array( 'contract' => self::CONTRACT, 'account_id' => $account, 'owner_user_id_hash' => (string) $ctx['user_hash'], 'principal_role' => (string) $ctx['role'], 'packs' => $packs );
		$etag = 'p-' . substr( md5( (string) wp_json_encode( $body ) ), 0, 8 );
		if ( trim( (string) $request->get_header( 'if_none_match' ), " \"" ) === $etag ) {
			$r = new WP_REST_Response( null, 304 );
			$r->header( 'ETag', '"' . $etag . '"' );
			return $r;
		}
		$body['etag'] = '"' . $etag . '"';
		$r = new WP_REST_Response( $body, 200 );
		$r->header( 'ETag', '"' . $etag . '"' );
		return $r;
	}

	public static function handle_page( WP_REST_Request $request ): WP_REST_Response {
		$account = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		if ( ! self::authorized( $request, $account ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'unauthorized' ), 401 );
		}
		$kind = sanitize_key( (string) $request->get_param( 'kind' ) );
		$spec = self::exporters()[ $kind ] ?? null;
		if ( null === $spec ) {
			return self::error( 'pack_kind_unknown', 'Loại gói không tồn tại.', 'Dùng owner_knowledge hoặc notebook_meta.', 'S87-PACK-404', 404 );
		}
		if ( ! self::source_available( $spec ) ) {
			return self::error( 'source_unavailable', 'Site chưa có nguồn dữ liệu cho gói này.', 'Cài/bật plugin nguồn (WooCommerce, CRM hoặc chiêm tinh) rồi đồng bộ lại.', 'S87-PACK-404', 404 );
		}
		$ctx = self::context( $account, (string) $request->get_param( 'principal' ) );
		if ( null === $ctx ) {
			return self::unknown_principal();
		}
		$why = self::refusal( $ctx, (string) $spec['mode'] );
		if ( null !== $why ) {
			return self::error( $why['code'], $why['message'], $why['hint'], 'S87-PACK-403', 403 );
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 R-MCP-OAUTH-ID §0b.5 — same role-group rule as resources/read of bizcity://pack/{kind}.
		if ( ! self::role_group_allows( $ctx, $kind, $spec ) ) {
			return self::error( 'role_group_denied', 'Vai trò WordPress của người này chưa được mở nhóm dữ liệu cho gói này.', 'Quản trị viên bật nhóm "' . self::role_group_of( $kind ) . '" cho vai trò ở Channel Gateway → MCP Access → "Quyền MCP theo vai trò", rồi đồng bộ lại.', 'S88-PACK-403', 403 );
		}
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? min( self::LIMIT_MAX, $limit ) : self::LIMIT_MAX;
		$after = self::cursor_id( (string) $request->get_param( 'cursor' ) );
		$st    = (array) call_user_func( $spec['stats'], $ctx );
		$page  = (array) call_user_func( $spec['page'], $ctx, $after, $limit );
		return new WP_REST_Response( array(
			'contract'    => self::CONTRACT,
			'kind'        => $kind,
			'version'     => (string) ( $st['version'] ?? '' ),
			'as_of'       => (string) ( $st['as_of'] ?? '' ),
			'full'        => true, // the cell replaces the whole kind after the last page (C-4 rule)
			'next_cursor' => ! empty( $page['more'] ) ? rtrim( base64_encode( (string) wp_json_encode( array( 'id' => (int) $page['last_id'] ) ) ), '=' ) : null,
			'items'       => array_values( (array) ( $page['items'] ?? array() ) ),
		), 200 );
	}

	/* ── gate ────────────────────────────────────────────────────── */

	/**
	 * Whose packs: the owner (no principal / the owner's hash) or an active staff member; null = hash not on this number.
	 * `owner_user_id` is the acting person (exporters read it); `role` says which.
	 *
	 * @return array{account_id:string,owner_user_id:int,owner_uid_set:bool,enabled:bool,user_hash:string,role:string}|null
	 */
	public static function context( string $account, string $principal = '' ): ?array {
		$acc    = isset( self::$readers['account'] ) ? call_user_func( self::$readers['account'], $account ) : ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account ) : null );
		$owner  = is_array( $acc ) ? (int) ( $acc['owner_user_id'] ?? 0 ) : 0;
		$policy = self::policy( $account );
		$hash   = $owner > 0 ? self::user_hash( $owner ) : '';
		$ctx    = array(
			'account_id'    => $account,
			'owner_user_id' => $owner,
			'owner_uid_set' => '' !== trim( (string) ( $policy['owner_uid'] ?? '' ) ),
			'enabled'       => ! isset( $policy['owner_agent_enabled'] ) || (bool) $policy['owner_agent_enabled'],
			'user_hash'     => $hash,
			'role'          => 'owner',
		);
		$principal = strtolower( trim( $principal ) );
		if ( '' === $principal || ( '' !== $hash && hash_equals( $hash, $principal ) ) ) {
			return $ctx;
		}
		$p = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $account, $principal ) : null;
		if ( null === $p || 'staff' !== $p['role'] ) {
			return null;
		}
		return array(
			'account_id'    => $account,
			'owner_user_id' => (int) $p['user_id'],
			'owner_uid_set' => true,  // the person's own UID is on the staff list
			'enabled'       => true,  // by_hash() returns active people only
			'user_hash'     => (string) $p['user_hash'],
			'role'          => 'staff',
		);
	}

	private static function unknown_principal(): WP_REST_Response {
		return self::error( 'not_own_notebook', 'Người này không thuộc danh sách dùng Agent của số.', 'Đồng bộ lại cấu hình số (Bot Studio) rồi kéo lại gói.', 'S87-PACK-403', 403 );
	}

	/** null = serve; else why not (list reason + page error). */
	public static function refusal( array $ctx, string $mode ): ?array {
		if ( $ctx['owner_user_id'] <= 0 || ! $ctx['owner_uid_set'] ) {
			return array( 'reason' => 'no_owner_verified', 'code' => 'owner_not_verified', 'message' => 'Số này chưa có chủ tài khoản.', 'hint' => 'Điền "UID chủ tài khoản" ở Bot Studio → số → Agent của chủ, và gán chủ WordPress cho số.' );
		}
		if ( ! $ctx['enabled'] ) {
			return array( 'reason' => 'turned_off', 'code' => 'pack_disabled', 'message' => 'Agent của chủ đang tắt cho số này.', 'hint' => 'Bật lại ở Bot Studio → số → Agent của chủ.' );
		}
		if ( ! self::can( $mode, (int) $ctx['owner_user_id'] ) ) {
			$who = 'staff' === ( $ctx['role'] ?? 'owner' ) ? 'Người này' : 'Chủ số';
			return array( 'reason' => 'mode_not_allowed', 'code' => 'mode_not_allowed', 'message' => $who . ' chưa có quyền dùng mục này qua Agent.', 'hint' => 'Cấp quyền "' . $mode . '" (vai trò WordPress hoặc vai trò CRM).' );
		}
		return null;
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 R-MCP-OAUTH-ID §0b.5 — may the resolved principal (owner or staff user_id) hold
	 * this kind in the cell cache? Audience-base kinds (L2-6 `catalog`, C-4 Guru `knowledge`) are public shop facts for
	 * customer turns, not principal data ⇒ always allowed. Others: BizCity_MCP_Tool_Policy::pack_group() (the one map the
	 * resource service uses) vs the user's role groups. core/mcp not loaded (MCP off) ⇒ no filter, as before.
	 */
	private static function role_group_allows( array $ctx, string $kind, array $spec ): bool {
		if ( 'base' === (string) ( $spec['audience'] ?? '' ) || in_array( $kind, self::BASE_AUDIENCE_KINDS, true ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_MCP_Tool_Policy' ) ) {
			return true;
		}
		$groups = BizCity_MCP_Tool_Policy::groups_for_user( (int) $ctx['owner_user_id'] );
		return ! empty( $groups[ BizCity_MCP_Tool_Policy::pack_group( $kind ) ] );
	}

	private static function role_group_of( string $kind ): string {
		return class_exists( 'BizCity_MCP_Tool_Policy' ) && BizCity_MCP_Tool_Policy::GROUP_KNOWLEDGE === BizCity_MCP_Tool_Policy::pack_group( $kind ) ? 'Tri thức' : 'Hành động';
	}

	private static function authorized( WP_REST_Request $request, string $account ): bool {
		if ( '' === $account ) {
			return false;
		}
		$token = isset( self::$readers['token'] ) ? (string) call_user_func( self::$readers['token'], $account ) : ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '' );
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		return '' !== $token && '' !== $bearer && hash_equals( $token, $bearer );
	}

	/** @return array<string,array{mode:string,audience:string,stats:callable,page:callable}> */
	public static function exporters(): array {
		$out = array();
		foreach ( (array) apply_filters( self::FILTER, array() ) as $kind => $spec ) {
			if ( is_array( $spec ) && isset( $spec['mode'], $spec['stats'], $spec['page'] ) && is_callable( $spec['stats'] ) && is_callable( $spec['page'] ) ) {
				$out[ sanitize_key( (string) $kind ) ] = $spec + array( 'audience' => 'owner_agent' );
			}
		}
		return $out;
	}

	/** An exporter may say its source plugin is absent (`available` callable); absent ⇒ listed, never served. */
	private static function source_available( array $spec ): bool {
		return ! isset( $spec['available'] ) || ! is_callable( $spec['available'] ) || (bool) call_user_func( $spec['available'] );
	}

	private static function registry(): array {
		if ( isset( self::$readers['registry'] ) ) {
			return (array) call_user_func( self::$readers['registry'] );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::modes() : array();
	}

	private static function can( string $mode, int $user_id ): bool {
		if ( isset( self::$readers['can'] ) ) {
			return (bool) call_user_func( self::$readers['can'], $mode, $user_id );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) && BizCity_Agent_Mode_Access::can( $mode, $user_id );
	}

	private static function user_hash( int $user_id ): string {
		if ( isset( self::$readers['user_hash'] ) ) {
			return (string) call_user_func( self::$readers['user_hash'], $user_id );
		}
		return class_exists( 'BizCity_Agent_Mode_Access' ) ? BizCity_Agent_Mode_Access::user_hash( $user_id ) : '';
	}

	private static function policy( string $account ): array {
		if ( isset( self::$readers['policy'] ) ) {
			return (array) call_user_func( self::$readers['policy'], $account );
		}
		$b = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( self::PLATFORM, $account ) : null;
		$raw = is_array( $b ) ? ( $b['policy_json'] ?? '' ) : '';
		$d = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $d ) ? $d : array();
	}

	private static function cursor_id( string $cursor ): int {
		if ( '' === $cursor ) {
			return 0;
		}
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $cursor ) % 4 ) % 4 ), true );
		$d   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $d ) && isset( $d['id'] ) ? max( 0, (int) $d['id'] ) : 0;
	}

	private static function error( string $code, string $message, string $hint, string $help, int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help ), $status );
	}
}
