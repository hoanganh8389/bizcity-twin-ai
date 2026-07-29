<?php
/**
 * TwinBrain Product Composer.
 *
 * Shared deterministic composer used by Ask Brain products mode and
 * automation product action blocks.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Product_Composer {

	private static $instance = null;

	public static function instance(): self {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - singleton composer shared across surfaces.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - no-op constructor.
	}

	/**
	 * @param string $query
	 * @param string $intent
	 * @param array  $detected_products
	 * @param array  $matched
	 * @param array  $gaps
	 * @param string $degraded
	 * @param array  $meta
	 * @return array<string,string>
	 */
	public function compose( string $query, string $intent, array $detected_products, array $matched, array $gaps, string $degraded = '', array $meta = array() ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — compose canonical Super-MRO answer with explicit detected items.
		$detected_md = $this->detected_markdown( $detected_products );
		$catalog_md = $this->catalog_markdown( $matched );
		$gaps_md    = $this->gaps_markdown( $gaps );
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — consume resolver meta for missing constraints and sheet handoff.
		$missing_constraints = isset( $meta['missing_constraints'] ) && is_array( $meta['missing_constraints'] )
			? $meta['missing_constraints']
			: array();
		$sheet_recommended  = ! empty( $meta['sheet_recommended'] );
		$sheet_seed         = isset( $meta['sheet_seed'] ) && is_array( $meta['sheet_seed'] ) ? $meta['sheet_seed'] : array();
		$sheet_handoff      = isset( $meta['sheet_handoff'] ) && is_array( $meta['sheet_handoff'] ) ? $meta['sheet_handoff'] : array();

		$lines   = array();
		$header  = $this->intent_header( $intent, $query );
		if ( $header !== '' ) {
			$lines[] = $header;
		}

		if ( $detected_md !== '' ) {
			$lines[] = 'Danh sách vật tư Super-MRO phát hiện từ yêu cầu:';
			$lines[] = $detected_md;
		}

		if ( ! empty( $matched ) ) {
			$lines[] = 'Vật tư/SKU shop đang có:';
			$lines[] = $catalog_md;
		}

		if ( ! empty( $gaps ) ) {
			$lines[] = 'Hạng mục shop chưa có (nguồn Super-MRO/kỹ thuật tham khảo):';
			$lines[] = $gaps_md;
		}

		if ( ! empty( $missing_constraints ) ) {
			$lines[] = 'Cần bổ sung thông tin để chốt số lượng/spec:';
			$lines[] = '- ' . implode( "\n- ", array_map( 'strval', array_slice( $missing_constraints, 0, 8 ) ) );
		}

		if ( $sheet_recommended ) {
			$lines[] = 'Yêu cầu này phù hợp để tạo BOQ/BOM dạng sheet.';
			$lines[] = $this->sheet_seed_markdown( $sheet_seed );

			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — surface auto handoff outcome in final markdown answer.
			$sheet_status = (string) ( $sheet_handoff['status'] ?? '' );
			$sheet_token  = trim( (string) ( $sheet_handoff['token'] ?? '' ) );
			if ( $sheet_status === 'ok' && $sheet_token !== '' ) {
				$lines[] = 'Da tao sheet tu dong: ' . $sheet_token;
			} elseif ( $sheet_status !== '' && $sheet_status !== 'not_needed' && $sheet_status !== 'disabled' ) {
				$lines[] = 'Sheet tu dong chua hoan tat (' . $sheet_status . ').';
			}
		}

		if ( empty( $matched ) && empty( $gaps ) ) {
			$lines[] = 'Chưa tìm thấy vật tư phù hợp. Hãy bổ sung model/SKU, thông số, số lượng hoặc mục đích thi công.';
		}

		if ( $degraded !== '' ) {
			$lines[] = '_Super-MRO đang ở chế độ suy giảm (' . $degraded . '); kết quả có thể chưa đầy đủ._';
		}

		return array(
			'detected_md'     => $detected_md,
			'catalog_md'      => $catalog_md,
			'gaps_md'         => $gaps_md,
			'final_answer_md' => trim( implode( "\n\n", array_filter( $lines ) ) ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $detected_products
	 * @return string
	 */
	private function detected_markdown( array $detected_products ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - render explicit detected products list for intent step evidence.
		if ( empty( $detected_products ) ) {
			return '- (khong phat hien term nao)';
		}

		$rows = array();
		foreach ( $detected_products as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$term = trim( (string) ( $row['term'] ?? '' ) );
			if ( $term === '' ) {
				continue;
			}
			$source = (string) ( $row['source'] ?? '' );
			$aliases = isset( $row['aliases'] ) && is_array( $row['aliases'] ) ? array_filter( array_map( 'strval', $row['aliases'] ) ) : array();
			$alias_md = ! empty( $aliases ) ? ' | aliases: ' . implode( ', ', array_slice( $aliases, 0, 4 ) ) : '';
			$src_md   = $source !== '' ? ' | source: ' . $source : '';
			$rows[]   = '- ' . $term . $alias_md . $src_md;
		}

		if ( empty( $rows ) ) {
			return '- (khong phat hien term nao)';
		}

		return implode( "\n", $rows );
	}

	/**
	 * @param array<int,array<string,mixed>> $matched
	 * @return string
	 */
	public function catalog_markdown( array $matched ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — every matched Super-MRO SKU includes a clickable citation.
		if ( empty( $matched ) ) {
			return '';
		}

		$rows = array();
		foreach ( $matched as $row ) {
			$need    = (string) ( $row['need'] ?? '' );
			$product = isset( $row['product'] ) && is_array( $row['product'] ) ? $row['product'] : array();
			$id      = (int) ( $product['id'] ?? 0 );
			$name    = (string) ( $product['name'] ?? '' );
			$price   = $this->format_price( (string) ( $product['price'] ?? '' ), (string) ( $product['currency'] ?? 'VND' ) );
			$stock   = $this->stock_label( (string) ( $product['stock_status'] ?? '' ) );
			$citation_link = $this->product_citation_link( $id, (string) ( $product['permalink'] ?? '' ) );
			$rows[]  = '- ' . $need . ' → ' . $name . ' | Giá: ' . $price . ' | Tồn kho: ' . $stock
				. ' | Citation: ' . $citation_link;
		}

		return implode( "\n", $rows );
	}

	private function product_citation_link( int $product_id, string $permalink ): string {
		$token = $product_id > 0 ? '[prod:' . $product_id . ']' : '[prod:unknown]';
		$link  = trim( $permalink );
		if ( $link === '' ) {
			return $token . '(no_link)';
		}
		return $token . '(' . $link . ')';
	}

	/**
	 * @param array<int,array<string,mixed>> $gaps
	 * @return string
	 */
	public function gaps_markdown( array $gaps ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — Super-MRO list for unresolved catalog needs.
		if ( empty( $gaps ) ) {
			return '';
		}

		$rows = array();
		foreach ( $gaps as $row ) {
			$need = (string) ( $row['need'] ?? '' );
			$sug  = (string) ( $row['web_suggestion'] ?? '' );
			$cits = isset( $row['citations'] ) && is_array( $row['citations'] ) ? $row['citations'] : array();
			$cit  = ! empty( $cits ) ? ' ' . implode( ' ', array_map( 'strval', $cits ) ) : '';
			if ( $sug === '' ) {
				$rows[] = '- ' . $need . ' → Shop chưa có vật tư tương ứng.' . $cit;
			} else {
				$rows[] = '- ' . $need . ' → Shop chưa có; nguồn tham khảo: ' . $sug . $cit;
			}
		}
		return implode( "\n", $rows );
	}

	private function intent_header( string $intent, string $query ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — concise Super-MRO intro by compatibility intent.
		switch ( $intent ) {
			case 'stock_price':
				return 'Giá và tồn kho Super-MRO cho: "' . $query . '"';
			case 'product_learn':
				return 'Thông số, công dụng và compatibility cho: "' . $query . '"';
			case 'need_solution':
				return 'Phương án vật tư, công cụ và PPE Super-MRO cho: "' . $query . '"';
			case 'product_lookup':
			default:
				return 'Kết quả tra cứu Super-MRO cho: "' . $query . '"';
		}
	}

	private function format_price( string $price, string $currency ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - normalize display price in deterministic output.
		if ( $price === '' ) {
			return 'Lien he';
		}
		if ( is_numeric( $price ) ) {
			$val = (float) $price;
			if ( strtoupper( $currency ) === 'VND' ) {
				return number_format_i18n( $val, 0 ) . ' VND';
			}
			return number_format_i18n( $val, 2 ) . ' ' . strtoupper( $currency );
		}
		return $price . ' ' . strtoupper( $currency );
	}

	private function stock_label( string $status ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - map Woo stock status to user-facing labels.
		$status = strtolower( $status );
		if ( $status === 'instock' ) {
			return 'Con hang';
		}
		if ( $status === 'outofstock' ) {
			return 'Het hang';
		}
		if ( $status === 'onbackorder' ) {
			return 'Dat truoc';
		}
		return $status !== '' ? $status : 'Khong ro';
	}

	/**
	 * @param array<string,mixed> $seed
	 * @return string
	 */
	private function sheet_seed_markdown( array $seed ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — concise BOQ/sheet handoff preview from resolver seed.
		$title = isset( $seed['title'] ) ? trim( (string) $seed['title'] ) : '';
		$headers = isset( $seed['headers'] ) && is_array( $seed['headers'] ) ? $seed['headers'] : array();
		$rows = isset( $seed['rows'] ) && is_array( $seed['rows'] ) ? $seed['rows'] : array();

		$lines = array();
		if ( $title !== '' ) {
			$lines[] = '- Ten sheet goi y: ' . $title;
		}
		if ( ! empty( $headers ) ) {
			$lines[] = '- Cot: ' . implode( ' | ', array_map( 'strval', array_slice( $headers, 0, 8 ) ) );
		}
		if ( ! empty( $rows ) ) {
			$preview = array_slice( $rows, 0, 3 );
			foreach ( $preview as $idx => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$lines[] = '- Dong ' . ( (int) $idx + 1 ) . ': ' . implode( ' | ', array_map( 'strval', array_slice( $row, 0, 8 ) ) );
			}
		}
		$lines[] = '- Co the tao sheet qua tool `sheet_enrich` de tiep tuc bo sung du lieu.';

		return implode( "\n", $lines );
	}
}
