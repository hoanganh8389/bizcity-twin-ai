<?php
/**
 * TwinBrain Product Source-of-Truth Layer.
 *
 * Canonical builder for products vertical source links:
 * - internal links from Woo matched products
 * - public links from web enrichment citations
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-16
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Product_Source_Layer {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - singleton source-of-truth builder.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - no-op constructor.
	}

	/**
	 * Build canonical source-of-truth links from resolver output arrays.
	 *
	 * @param array<int,array<string,mixed>> $matched
	 * @param array<int,array<string,mixed>> $gaps
	 * @param string                         $query
	 * @return array<int,array<string,mixed>>
	 */
	public function build_source_of_truth_links( array $matched, array $gaps, string $query = '' ): array {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - merge internal+public links and dedupe by URL/product_id.
		$links         = array();
		$internal_seen = array();
		$public_seen   = array();

		foreach ( $matched as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$product = isset( $row['product'] ) && is_array( $row['product'] ) ? $row['product'] : array();
			$url     = trim( (string) ( $product['permalink'] ?? '' ) );
			$pid     = (int) ( $product['id'] ?? 0 );
			if ( $url === '' && $pid <= 0 ) {
				continue;
			}

			$internal_key = $pid > 0 ? 'pid:' . $pid : 'url:' . $this->normalize_url( $url );
			if ( isset( $internal_seen[ $internal_key ] ) ) {
				continue;
			}
			$internal_seen[ $internal_key ] = true;

			$item_term = trim( (string) ( $row['need'] ?? $query ) );
			$title     = trim( (string) ( $product['name'] ?? '' ) );
			$domain    = $this->extract_domain( $url );
			$token     = $pid > 0 ? '[prod:' . $pid . ']' : '[prod:unknown]';
			$citation  = $url !== '' ? $token . '(' . $url . ')' : $token;

			$links[] = array(
				'link_id'    => '',
				'scope'      => 'internal',
				'source'     => 'woo',
				'item_term'  => $item_term,
				'url'        => $url,
				'title'      => $title,
				'domain'     => $domain,
				'product_id' => $pid,
				'citation'   => $citation,
			);
		}

		foreach ( $gaps as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item_term = trim( (string) ( $row['need'] ?? $query ) );
			$title     = trim( (string) ( $row['web_suggestion'] ?? '' ) );
			$citations = isset( $row['citations'] ) && is_array( $row['citations'] ) ? $row['citations'] : array();

			foreach ( $citations as $token ) {
				$token = trim( (string) $token );
				if ( $token === '' ) {
					continue;
				}
				$url = $this->extract_url_from_web_token( $token );
				if ( $url === '' ) {
					continue;
				}

				$public_key = $this->normalize_url( $url );
				if ( isset( $public_seen[ $public_key ] ) ) {
					continue;
				}
				$public_seen[ $public_key ] = true;

				$links[] = array(
					'link_id'   => '',
					'scope'     => 'public',
					'source'    => 'tavily_first_pass',
					'item_term' => $item_term,
					'url'       => $url,
					'title'     => $title,
					'domain'    => $this->extract_domain( $url ),
					'citation'  => $token,
				);
			}
		}

		$seq = 1;
		foreach ( $links as &$row ) {
			$row['link_id'] = 'lnk_' . $seq;
			$seq++;
		}
		unset( $row );

		return $links;
	}

	/**
	 * Build markdown source block from canonical links.
	 *
	 * @param array<int,array<string,mixed>> $links
	 * @return string
	 */
	public function build_source_block_md( array $links ): string {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - render deterministic Source of Truth block.
		if ( empty( $links ) ) {
			return '';
		}

		$internal = array();
		$public   = array();
		foreach ( $links as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$scope = (string) ( $row['scope'] ?? '' );
			if ( $scope === 'internal' ) {
				$internal[] = $row;
			} elseif ( $scope === 'public' ) {
				$public[] = $row;
			}
		}

		$lines   = array();
		$lines[] = '### Source of Truth';
		$lines[] = '**Internal (Woo):** ' . count( $internal ) . ' links';
		foreach ( $internal as $row ) {
			$lines[] = '- ' . (string) ( $row['citation'] ?? '' );
		}
		$lines[] = '';
		$lines[] = '**Public (Super-MRO + Web):** ' . count( $public ) . ' links';
		foreach ( $public as $row ) {
			$lines[] = '- ' . (string) ( $row['citation'] ?? '' );
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Count links by scope.
	 *
	 * @param array<int,array<string,mixed>> $links
	 * @return array{internal:int,public:int,total:int}
	 */
	public function count_scopes( array $links ): array {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - provide stable internal/public counters for FE and diagnostics.
		$internal = 0;
		$public   = 0;
		foreach ( $links as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$scope = (string) ( $row['scope'] ?? '' );
			if ( $scope === 'internal' ) {
				$internal++;
			} elseif ( $scope === 'public' ) {
				$public++;
			}
		}
		return array(
			'internal' => $internal,
			'public'   => $public,
			'total'    => $internal + $public,
		);
	}

	/**
	 * Validate citation token against canonical links list.
	 *
	 * @param string                         $citation_token
	 * @param array<int,array<string,mixed>> $links
	 * @return bool
	 */
	public function validate_citation( string $citation_token, array $links ): bool {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - guard citations to source-of-truth links only.
		$token = trim( $citation_token );
		if ( $token === '' ) {
			return false;
		}

		if ( preg_match( '/^\[prod:(\d+)\]$/', $token, $m ) ) {
			return $this->contains_product_id( (int) $m[1], $links );
		}
		if ( preg_match( '/^\[prod:(\d+|unknown)\]\((https?:\/\/[^)]+)\)$/i', $token, $m ) ) {
			$url = (string) $m[2];
			return $this->contains_url( $url, $links, 'internal' );
		}
		if ( preg_match( '/^\[web:\d+#([^\]]+)\]$/i', $token, $m ) ) {
			$url = (string) $m[1];
			return $this->contains_url( $url, $links, 'public' );
		}

		return false;
	}

	/**
	 * @param string $token
	 * @return string
	 */
	private function extract_url_from_web_token( string $token ): string {
		if ( preg_match( '/^\[web:\d+#([^\]]+)\]$/i', $token, $m ) ) {
			return trim( (string) $m[1] );
		}
		return '';
	}

	/**
	 * @param string $url
	 * @return string
	 */
	private function extract_domain( string $url ): string {
		$host = wp_parse_url( trim( $url ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * @param string $url
	 * @return string
	 */
	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( rtrim( $url, '/' ) );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query  = isset( $parts['query'] ) ? (string) $parts['query'] : '';
		$norm = $scheme . '://' . $host . rtrim( $path, '/' );
		if ( $query !== '' ) {
			$norm .= '?' . $query;
		}
		return $norm;
	}

	/**
	 * @param string                         $url
	 * @param array<int,array<string,mixed>> $links
	 * @param string                         $scope
	 * @return bool
	 */
	private function contains_url( string $url, array $links, string $scope ): bool {
		$needle = $this->normalize_url( $url );
		if ( $needle === '' ) {
			return false;
		}
		foreach ( $links as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (string) ( $row['scope'] ?? '' ) !== $scope ) {
				continue;
			}
			if ( $this->normalize_url( (string) ( $row['url'] ?? '' ) ) === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int                            $product_id
	 * @param array<int,array<string,mixed>> $links
	 * @return bool
	 */
	private function contains_product_id( int $product_id, array $links ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		foreach ( $links as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (string) ( $row['scope'] ?? '' ) !== 'internal' ) {
				continue;
			}
			if ( (int) ( $row['product_id'] ?? 0 ) === $product_id ) {
				return true;
			}
		}
		return false;
	}
}
