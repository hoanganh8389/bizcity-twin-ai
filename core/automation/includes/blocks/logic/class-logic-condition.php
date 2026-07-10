<?php
/**
 * Logic: Condition (IF) — eval safe expression chọn branch true/false.
 *
 * Eval KHÔNG dùng PHP eval(). Chỉ support:
 *   - tokens `a.b.c` (resolve từ ctx)
 *   - operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `contains`
 *   - boolean ops: `&&`, `||` — hỗ trợ multi-chain (a || b || c),
 *     KHÔNG mix `&&` và `||` trong cùng expression — chỉ 1 loại op / expression.
 *     Infix syntax bắt buộc: `trigger.text contains 'từ khoá'`.
 *     KHÔNG hỗ trợ function-call style `contains(a, b)` hay keyword `AND`/`OR`.
 * Phức tạp hơn → user dùng action.http hoặc block custom.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Logic
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Logic_Condition extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'logic.condition'; }
	public function kind(): string { return 'condition'; }
	public function meta(): array {
		return array(
			'label'    => 'Điều kiện rẽ nhánh',
			'short'    => 'if',
			'category' => 'logic',
			'color'    => '#b45309',
			'icon'     => 'git-branch',
			'defaults' => array( 'label' => 'IF', 'expression' => 'kg.hits > 0' ),
			'fields'   => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'expression', 'label' => 'Biểu thức',    'type' => 'textarea', 'hint' => 'vd: kg.hits > 0' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$expr = trim( (string) ( $data['expression'] ?? '' ) );
		if ( $expr === '' ) {
			return array( 'branch' => 'true', 'matched' => true );
		}

		// Tokenize on && / || keeping order.
		// [2026-06-07 Johnny Chu] SEED-DEPLAO — remove limit-2 from explode to support
		// multi-chain: a || b || c now evaluates all 3 atoms correctly.
		$op = null;
		foreach ( array( '&&', '||' ) as $candidate ) {
			if ( strpos( $expr, $candidate ) !== false ) { $op = $candidate; break; }
		}
		$parts = $op ? array_map( 'trim', explode( $op, $expr ) ) : array( $expr );
		$results = array();
		foreach ( $parts as $part ) {
			$results[] = $this->evaluate_atom( $part, $ctx );
		}
		if ( $op === '||' ) {
			$matched = in_array( true, $results, true );
		} elseif ( $op === '&&' ) {
			$matched = ! in_array( false, $results, true );
		} else {
			$matched = (bool) $results[0];
		}
		return array(
			'branch'  => $matched ? 'true' : 'false',
			'matched' => $matched,
		);
	}

	private function evaluate_atom( string $atom, array $ctx ): bool {
		// Pattern: <lhs> <op> <rhs>
		if ( ! preg_match( '/^([a-z0-9_.\-\'"\s]+?)\s*(==|!=|>=|<=|>|<|contains)\s*(.+)$/i', $atom, $m ) ) {
			return (bool) $this->lookup( trim( $atom ), $ctx );
		}
		$lhs = $this->lookup( trim( $m[1] ), $ctx );
		$op  = $m[2];
		$rhs = $this->normalise_literal( trim( $m[3] ), $ctx );

		switch ( $op ) {
			case '==': return $lhs == $rhs; // phpcs:ignore WordPress.PHP.StrictComparisons
			case '!=': return $lhs != $rhs; // phpcs:ignore WordPress.PHP.StrictComparisons
			case '>':  return (float) $lhs >  (float) $rhs;
			case '<':  return (float) $lhs <  (float) $rhs;
			case '>=': return (float) $lhs >= (float) $rhs;
			case '<=': return (float) $lhs <= (float) $rhs;
			case 'contains':
				return is_string( $lhs ) && is_string( $rhs ) && $rhs !== '' && stripos( $lhs, $rhs ) !== false;
		}
		return false;
	}

	private function lookup( string $token, array $ctx ) {
		// Quoted literal?
		if ( preg_match( '/^[\'"](.*)[\'"]$/', $token, $m ) ) { return $m[1]; }
		if ( is_numeric( $token ) ) { return $token + 0; }
		$parts = explode( '.', $token );
		$node  = $ctx;
		foreach ( $parts as $p ) {
			if ( is_array( $node ) && array_key_exists( $p, $node ) ) {
				$node = $node[ $p ];
			} else {
				return null;
			}
		}
		return $node;
	}

	private function normalise_literal( string $raw, array $ctx ) {
		// Try literal first then lookup.
		if ( preg_match( '/^[\'"](.*)[\'"]$/', $raw, $m ) ) { return $m[1]; }
		if ( is_numeric( $raw ) ) { return $raw + 0; }
		return $this->lookup( $raw, $ctx );
	}
}
