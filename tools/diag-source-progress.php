<?php
/**
 * Diagnostic: why per-source extraction_progress shows 0% even though
 * kg_passages have extraction_status='done' (Wave 10d.5c).
 *
 * Run from WP-CLI:
 *   wp eval-file wp-content/plugins/bizcity-twin-ai/tools/diag-source-progress.php 2
 *
 * Or hit it via browser by an admin (only when WP_DEBUG is on):
 *   /wp-content/plugins/bizcity-twin-ai/tools/diag-source-progress.php?nb=2
 *
 * Pass the notebook id as the first CLI arg or `?nb=` query param.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Browser entry point — bootstrap WP.
	$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		http_response_code( 500 );
		exit( 'wp-load.php not found' );
	}
	require $wp_load;
	if ( ! current_user_can( 'manage_options' ) || ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		http_response_code( 403 );
		exit( 'forbidden' );
	}
	header( 'Content-Type: text/plain; charset=utf-8' );
}

global $wpdb, $argv;
$nb = isset( $argv[1] ) ? (int) $argv[1] : (int) ( $_GET['nb'] ?? 0 );
if ( $nb <= 0 ) { echo "Usage: pass notebook id\n"; return; }

echo "=== DIAG notebook_id={$nb} ===\n\n";

// 1. Webchat sources (legacy local table)
$tbl_w = $wpdb->prefix . 'bizcity_webchat_sources';
$w_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, title, embedding_status FROM {$tbl_w} WHERE project_id=%s ORDER BY id DESC LIMIT 50",
	(string) $nb
), ARRAY_A );
echo "[1] webchat_sources ({$tbl_w}): " . count( $w_rows ?: [] ) . " rows\n";
foreach ( (array) $w_rows as $r ) {
	echo "    id={$r['id']}  emb={$r['embedding_status']}  title=" . substr( $r['title'], 0, 50 ) . "\n";
}
$w_ids = array_map( static fn( $r ) => (int) $r['id'], (array) $w_rows );

echo "\n";

// 2. kg_sources mirror rows
if ( ! class_exists( 'BizCity_KG_Database' ) ) { echo "[!] BizCity_KG_Database not loaded\n"; return; }
$kg = BizCity_KG_Database::instance();
$tbl_s = $kg->tbl_sources();
$tbl_p = $kg->tbl_passages();

$kg_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT id, origin_id, origin_kind, origin_url, title, passage_count
	   FROM {$tbl_s}
	  WHERE scope_type=%s AND scope_id=%s
	  ORDER BY id DESC LIMIT 50",
	'notebook', (string) $nb
), ARRAY_A );
echo "[2] kg_sources ({$tbl_s}): " . count( $kg_rows ?: [] ) . " rows\n";
foreach ( (array) $kg_rows as $r ) {
	echo "    id={$r['id']}  origin_id={$r['origin_id']}  kind={$r['origin_kind']}  passages={$r['passage_count']}  title=" . substr( $r['title'], 0, 50 ) . "\n";
}
$kg_ids = array_map( static fn( $r ) => (int) $r['id'], (array) $kg_rows );

echo "\n";

// 3. kg_passages aggregation by source_id
$probe_ids = array_unique( array_merge( $w_ids, $kg_ids ) );
if ( $probe_ids ) {
	$ph = implode( ',', array_fill( 0, count( $probe_ids ), '%d' ) );
	$agg = $wpdb->get_results( $wpdb->prepare(
		"SELECT source_id, COUNT(*) AS n,
			SUM(extraction_status='done')  AS done_n,
			SUM(extraction_status='error') AS error_n,
			SUM(extraction_status='pending' OR extraction_status IS NULL) AS pending_n
		   FROM {$tbl_p}
		  WHERE notebook_id=%d AND source_id IN ({$ph})
		  GROUP BY source_id",
		array_merge( [ $nb ], $probe_ids )
	), ARRAY_A );
	echo "[3] kg_passages aggregate by source_id (probed: " . implode( ',', $probe_ids ) . ")\n";
	foreach ( (array) $agg as $a ) {
		echo "    source_id={$a['source_id']}  total={$a['n']}  done={$a['done_n']}  err={$a['error_n']}  pending={$a['pending_n']}\n";
	}
} else {
	echo "[3] no source ids to probe\n";
}

// 4. ANY passages on this notebook (catch-all)
$any = $wpdb->get_results( $wpdb->prepare(
	"SELECT source_id, COUNT(*) n, SUM(extraction_status='done') d
	   FROM {$tbl_p} WHERE notebook_id=%d GROUP BY source_id ORDER BY n DESC LIMIT 20",
	$nb
), ARRAY_A );
echo "\n[4] kg_passages — ALL distinct source_id for nb={$nb}:\n";
foreach ( (array) $any as $a ) {
	echo "    source_id={$a['source_id']}  total={$a['n']}  done={$a['d']}\n";
}

// 5. Verdict
echo "\n=== VERDICT ===\n";
$mirror_ok = false;
foreach ( (array) $kg_rows as $r ) {
	if ( (int) $r['origin_id'] > 0 && in_array( (int) $r['origin_id'], $w_ids, true ) ) { $mirror_ok = true; break; }
}
echo "kg_sources.origin_id link → webchat_sources.id : " . ( $mirror_ok ? 'OK ✓' : 'MISSING ✗ (this is why % shows 0)' ) . "\n";
if ( ! $mirror_ok && $w_rows && $kg_rows ) {
	echo "→ Suggested fix: run backfill (see tools/backfill-kg-origin-id.php) or set --backfill flag\n";
}
