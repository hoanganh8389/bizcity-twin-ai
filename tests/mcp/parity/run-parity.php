<?php
/**
 * Offline MCP Claude/ChatGPT structural parity harness.
 *
 * Usage:
 *   php run-parity.php fixture-canonical.json claude.json chatgpt.json
 *
 * Adapter files may contain prose freely. Only the canonical retrieval and
 * citation contract is compared; generated wording is intentionally ignored.
 */

declare( strict_types=1 );

// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — deterministic offline parity contract for external LLM adapters.
$fixture_path = $argv[1] ?? __DIR__ . '/fixture-canonical.json';
$claude_path  = $argv[2] ?? '';
$chatgpt_path = $argv[3] ?? '';

function read_json_file( string $path ): array {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Unreadable JSON file: {$path}\n" );
		exit( 2 );
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
		fwrite( STDERR, "Invalid JSON file: {$path}\n" );
		exit( 2 );
	}
	return $data;
}

function structural_view( array $data ): array {
	$passages = [];
	foreach ( (array) ( $data['passages'] ?? [] ) as $passage ) {
		$passages[] = [
			'source_id'  => (int) ( $passage['source_id'] ?? 0 ),
			'passage_id' => (int) ( $passage['passage_id'] ?? 0 ),
			'citation_id'=> (string) ( $passage['citation_id'] ?? '' ),
			'score'      => [
				'final'  => round( (float) ( $passage['score']['final'] ?? 0 ), 6 ),
				'source' => (string) ( $passage['score']['source'] ?? 'unscored' ),
			],
		];
	}
	return [
		'retrieval_snapshot_id' => (string) ( $data['retrieval_snapshot_id'] ?? '' ),
		'kg_revision'           => (string) ( $data['kg_revision'] ?? '' ),
		'passages'              => $passages,
		'allowed_citations'     => array_values( array_map( 'strval', (array) ( $data['allowed_citations'] ?? [] ) ) ),
	];
}

function diff_view( array $expected, array $actual ): array {
	$issues = [];
	foreach ( [ 'retrieval_snapshot_id', 'kg_revision', 'passages', 'allowed_citations' ] as $key ) {
		if ( $expected[ $key ] !== $actual[ $key ] ) {
			$issues[] = $key . '_mismatch';
		}
	}
	$allowed = array_flip( $actual['allowed_citations'] );
	foreach ( $actual['passages'] as $passage ) {
		if ( $passage['citation_id'] === '' || ! isset( $allowed[ $passage['citation_id'] ] ) ) {
			$issues[] = 'passage_citation_not_allowed:' . $passage['citation_id'];
		}
	}
	return array_values( array_unique( $issues ) );
}

$fixture = structural_view( read_json_file( $fixture_path ) );
$adapters = [];
foreach ( [ 'claude' => $claude_path, 'chatgpt' => $chatgpt_path ] as $name => $path ) {
	if ( $path === '' ) {
		$adapters[ $name ] = [ 'status' => 'not_provided', 'issues' => [] ];
		continue;
	}
	$view = structural_view( read_json_file( $path ) );
	$issues = diff_view( $fixture, $view );
	$adapters[ $name ] = [ 'status' => empty( $issues ) ? 'pass' : 'fail', 'issues' => $issues ];
}
$both_provided = $adapters['claude']['status'] !== 'not_provided' && $adapters['chatgpt']['status'] !== 'not_provided';
$parity = $both_provided && $adapters['claude']['status'] === 'pass' && $adapters['chatgpt']['status'] === 'pass';

$result = [
	'contract'    => 'mcp-claude-chatgpt-structural-parity-v1',
	'fixture_id'  => (string) ( read_json_file( $fixture_path )['fixture_id'] ?? '' ),
	'status'      => $parity ? 'pass' : ( $both_provided ? 'fail' : 'incomplete' ),
	'prose_equal' => null,
	'checks'      => [
		'retrieval_snapshot_identity' => true,
		'ordered_source_passage_ids'  => true,
		'score_and_score_source'      => true,
		'allowed_citation_subset'     => true,
	],
	'adapters'    => $adapters,
];

echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( $parity ? 0 : ( $both_provided ? 1 : 2 ) );
