<?php
/**
 * BizCity_MCP_Resource_URI — `bizcity-resource-uri@1` parser / builder (PHASE-0.88 L2-1).
 *
 * Logical, tenant-free URIs: the site IS the tenant, so no blog id / tenant / key ever appears in a URI and the same URI
 * means the same thing for every client (ChatGPT, Claude, the zalo-hub cell). Shared fixture:
 * zalo-hub/contracts/fixtures/mcp/resources.json (valid / invalid / legacy_map / templates_list).
 *
 *   bizcity://guru/{ref}                      ref 0 = the default Guru, else the Guru (character) id
 *   bizcity://guru/{ref}/notebook/{id}
 *   bizcity://notebook/{id}
 *   bizcity://notebook/{id}/passage/{pid}
 *   bizcity://customer/{contact_id}/context
 *   bizcity://product/catalog
 *   bizcity://product/{id}
 *   bizcity://pack/{kind}
 *
 * Pure string work: no WordPress call, no DB, no LLM. Which principal may read a URI is BizCity_MCP_Resource_Service's job.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      PHASE-0.88 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-1 — new file, bizcity:// resource URI contract (parse, build, templates).
final class BizCity_MCP_Resource_URI {

	const CONTRACT = 'bizcity-resource-uri@1.0.0';
	const SCHEME   = 'bizcity://';

	const T_GURU          = 'bizcity://guru/{ref}';
	const T_GURU_NOTEBOOK = 'bizcity://guru/{ref}/notebook/{id}';
	const T_NOTEBOOK      = 'bizcity://notebook/{id}';
	const T_PASSAGE       = 'bizcity://notebook/{id}/passage/{pid}';
	const T_CUSTOMER      = 'bizcity://customer/{contact_id}/context';
	const T_CATALOG       = 'bizcity://product/catalog';
	const T_PRODUCT       = 'bizcity://product/{id}';
	const T_PACK          = 'bizcity://pack/{kind}';

	/** Positive id: no sign, no leading zero, ≤ 18 digits. */
	const ID  = '[1-9][0-9]{0,17}';
	/** Guru ref: 0 (default Guru) or a positive id. */
	const REF = '(?:0|[1-9][0-9]{0,17})';
	/** Pack kind: same alphabet as the projection-pack route (`[a-z_]{2,40}`), starting with a letter. */
	const KIND = '[a-z][a-z_]{1,39}';

	/**
	 * The catalog (mirror of BIZCITY-MCP-STANDARD-v1.json → resources.templates; bin/validate-mcp-standard.mjs counts the
	 * resolver markers in BizCity_MCP_Resource_Service).
	 *
	 * @return array<string,array{mimeType:string,mode:?string,name:string,description:string}>
	 */
	public static function templates(): array {
		return array(
			self::T_GURU          => array( 'mimeType' => 'application/json', 'mode' => null, 'name' => 'Guru', 'description' => 'Hồ sơ Guru đang trả lời số của bạn (chỉ dẫn, câu hỏi thường gặp, phạm vi tri thức). 0 = Guru mặc định.' ),
			self::T_GURU_NOTEBOOK => array( 'mimeType' => 'text/markdown', 'mode' => null, 'name' => 'Sổ tay của Guru', 'description' => 'Nội dung một sổ tay gắn với Guru đó.' ),
			self::T_NOTEBOOK      => array( 'mimeType' => 'application/json', 'mode' => 'notebook', 'name' => 'Sổ tay', 'description' => 'Thông tin một sổ tay của chính bạn (tiêu đề, ngày, số nguồn).' ),
			self::T_PASSAGE       => array( 'mimeType' => 'text/markdown', 'mode' => 'notebook', 'name' => 'Đoạn trích sổ tay', 'description' => 'Nguyên văn một đoạn trong sổ tay của bạn.' ),
			self::T_CUSTOMER      => array( 'mimeType' => 'application/json', 'mode' => 'customers', 'name' => 'Ngữ cảnh khách hàng', 'description' => 'Thông tin CRM của một khách (giai đoạn, nhãn, đơn hàng, lần liên hệ cuối). Số điện thoại đã che.' ),
			self::T_CATALOG       => array( 'mimeType' => 'application/json', 'mode' => 'stock', 'name' => 'Danh mục sản phẩm', 'description' => 'Tên, giá, tình trạng còn hàng và mô tả ngắn của sản phẩm đang bán.' ),
			self::T_PRODUCT       => array( 'mimeType' => 'application/json', 'mode' => 'stock', 'name' => 'Sản phẩm', 'description' => 'Thông tin công khai của một sản phẩm.' ),
			self::T_PACK          => array( 'mimeType' => 'application/json', 'mode' => '*pack', 'name' => 'Gói dữ liệu', 'description' => 'Toàn bộ một gói dữ liệu của Agent (doanh số, đơn hàng, tồn kho, khách hàng…), cùng nội dung với gói ở cell.' ),
		);
	}

	/** template => regex with named groups. */
	private static function patterns(): array {
		return array(
			self::T_GURU          => '#^bizcity://guru/(?P<ref>' . self::REF . ')$#',
			self::T_GURU_NOTEBOOK => '#^bizcity://guru/(?P<ref>' . self::REF . ')/notebook/(?P<id>' . self::ID . ')$#',
			self::T_NOTEBOOK      => '#^bizcity://notebook/(?P<id>' . self::ID . ')$#',
			self::T_PASSAGE       => '#^bizcity://notebook/(?P<id>' . self::ID . ')/passage/(?P<pid>' . self::ID . ')$#',
			self::T_CUSTOMER      => '#^bizcity://customer/(?P<contact_id>' . self::ID . ')/context$#',
			self::T_CATALOG       => '#^bizcity://product/catalog$#',
			self::T_PRODUCT       => '#^bizcity://product/(?P<id>' . self::ID . ')$#',
			self::T_PACK          => '#^bizcity://pack/(?P<kind>' . self::KIND . ')$#',
		);
	}

	/**
	 * @return array{template:string,params:array<string,int|string>}|null null = not a bizcity-resource-uri@1 URI
	 */
	public static function parse( $uri ): ?array {
		if ( ! is_string( $uri ) || strlen( $uri ) > 300 || 0 !== strpos( $uri, self::SCHEME ) ) {
			return null;
		}
		foreach ( self::patterns() as $template => $re ) {
			if ( preg_match( $re, $uri, $m ) ) {
				$params = array();
				foreach ( $m as $k => $v ) {
					if ( is_string( $k ) ) {
						$params[ $k ] = 'kind' === $k ? (string) $v : (int) $v;
					}
				}
				return array( 'template' => $template, 'params' => $params );
			}
		}
		return null;
	}

	public static function is_valid( $uri ): bool {
		return null !== self::parse( $uri );
	}

	/**
	 * Fill a template; returns '' when a parameter is missing or the result does not parse back to the same template.
	 *
	 * @param array<string,int|string> $params
	 */
	public static function build( string $template, array $params = array() ): string {
		$uri = preg_replace_callback( '/\{([a-z_]+)\}/', static function ( $m ) use ( $params ) {
			return array_key_exists( $m[1], $params ) ? (string) $params[ $m[1] ] : '{' . $m[1] . '}';
		}, $template );
		$p = self::parse( (string) $uri );
		return ( null !== $p && $p['template'] === $template ) ? (string) $uri : '';
	}

	public static function guru( int $ref ): string {
		return self::build( self::T_GURU, array( 'ref' => $ref ) );
	}

	public static function notebook( int $id ): string {
		return self::build( self::T_NOTEBOOK, array( 'id' => $id ) );
	}

	public static function passage( int $notebook_id, int $passage_id ): string {
		return self::build( self::T_PASSAGE, array( 'id' => $notebook_id, 'pid' => $passage_id ) );
	}

	public static function pack( string $kind ): string {
		return self::build( self::T_PACK, array( 'kind' => $kind ) );
	}

	/** Wire Guru ref (`guru:0` / `guru:<id>`) → URI ref (0 / id); -1 = not a Guru ref. */
	public static function ref_of_wire( string $wire ): int {
		return preg_match( '/^guru:(' . self::REF . ')$/', $wire, $m ) ? (int) $m[1] : -1;
	}

	/**
	 * Legacy forms (legacy_map of the fixture): `twin-source://nb/{nb}/p/{p}` maps 1-1 to a passage URI. `notebook://<slug>`
	 * (citation-pack@1.0) carries no id and has no bizcity:// equivalent: returns '' (citation-pack@1.1 keeps reading it).
	 */
	public static function from_legacy( string $uri ): string {
		if ( preg_match( '#^twin-source://nb/(' . self::ID . ')/p/(' . self::ID . ')$#', $uri, $m ) ) {
			return self::passage( (int) $m[1], (int) $m[2] );
		}
		return self::is_valid( $uri ) ? $uri : '';
	}
}
