<?php
/**
 * PHASE-0.71 F71-13 / 0.63C GC-9 — auto-linking a message's attachments to the contact's
 * single open pipeline run (D71-3's heuristic: no new "selected run" persistence).
 *
 * Run: php tests/unit/CrmPipelineDocumentLinkTest.php
 * No WordPress required — a minimal in-memory `$wpdb` stub stands in for
 * `bizcity_crm_documents`/`bizcity_crm_attachments`, and `Pipeline_Run_Service::runs_for_contact()`
 * is stubbed directly (this test is about the linking heuristic, not the pipeline run service,
 * which already has its own coverage in `CrmPipelineRegistryTest.php`).
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

function current_time( $type ) { return '2026-09-23 12:00:00'; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }

/** In-memory stand-in for `wpdb`, just enough surface for `on_message_persisted()`. */
final class Fake_Doclink_WPDB {
	public $documents = array();
	public $attachments_by_message = array();
	public $insert_id = 0;
	private $next_id = 1;
	private $last_args = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$this->last_args = $args;
		return $sql;
	}

	/** Dedup check: `SELECT id FROM documents WHERE message_id = %d`. */
	public function get_var( $prepared_sql ) {
		$message_id = (int) ( $this->last_args[0] ?? 0 );
		foreach ( $this->documents as $id => $row ) {
			if ( (int) ( $row['message_id'] ?? 0 ) === $message_id ) {
				return $id;
			}
		}
		return null;
	}

	/** Attachments lookup: `SELECT * FROM attachments WHERE message_id = %d`. */
	public function get_results( $prepared_sql, $output = null ) {
		$message_id = (int) ( $this->last_args[0] ?? 0 );
		return $this->attachments_by_message[ $message_id ] ?? array();
	}

	public function insert( $table, array $data ) {
		$id = $this->next_id++;
		$this->documents[ $id ] = $data;
		$this->insert_id = $id;
		return 1;
	}
}

final class BizCity_CRM_DB_Installer_V2 {
	public static function tbl_crm_documents() { return 'wp_bizcity_crm_documents'; }
	public static function tbl_attachments() { return 'wp_bizcity_crm_attachments'; }
}

/** Stub — this test is about the linking heuristic, not the run service itself. */
final class BizCity_CRM_Pipeline_Run_Service {
	public static $runs_by_contact = array();
	public static function runs_for_contact( int $contact_id ) {
		return self::$runs_by_contact[ $contact_id ] ?? array();
	}
}

require dirname( __DIR__, 2 ) . '/includes/inbox/class-pipeline-document-link.php';

/* ---- Harness --------------------------------------------------------------------- */

$pass = 0;
$fail = 0;
function check_link( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL: {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

function fresh_wpdb(): Fake_Doclink_WPDB {
	$w = new Fake_Doclink_WPDB();
	$GLOBALS['wpdb'] = $w;
	return $w;
}

/* ---- 1. Missing message_id/contact_id — no-op ------------------------------------ */

$wpdb = fresh_wpdb();
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 0, 'contact_id' => 501 ) );
check_link( 'missing message_id is a no-op', array() === $wpdb->documents );
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 900, 'contact_id' => 0 ) );
check_link( 'missing contact_id is a no-op', array() === $wpdb->documents );

/* ---- 2. Exactly one open run — attachments get linked ----------------------------- */

$wpdb = fresh_wpdb();
$wpdb->attachments_by_message[ 900 ] = array(
	array( 'id' => 1, 'message_id' => 900, 'file_type' => 'photo', 'data_url' => 'https://cdn.example/inbox/qc-01.jpg' ),
	array( 'id' => 2, 'message_id' => 900, 'file_type' => 'file', 'data_url' => 'https://cdn.example/inbox/report.pdf' ),
);
BizCity_CRM_Pipeline_Run_Service::$runs_by_contact[ 501 ] = array(
	array( 'id' => 42, 'status' => 'open' ),
);
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 900, 'contact_id' => 501 ) );

check_link( 'exactly one open run: both attachments become documents', 2 === count( $wpdb->documents ) );
foreach ( $wpdb->documents as $doc ) {
	check_link( 'each document links to the one open run', 'pipeline_run' === $doc['related_entity_type'] && 42 === $doc['related_entity_id'] );
	check_link( 'each document keeps the message_id back-pointer', 900 === $doc['message_id'] );
}
$names = array_column( $wpdb->documents, 'name' );
check_link( 'document name is derived from the attachment path', in_array( 'qc-01.jpg', $names, true ) && in_array( 'report.pdf', $names, true ), implode( ',', $names ) );

/* ---- 3. Idempotency — a message already linked is never reprocessed -------------- */

$before_count = count( $wpdb->documents );
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 900, 'contact_id' => 501 ) );
check_link( 'a second fire for the same message_id inserts nothing new', $before_count === count( $wpdb->documents ) );

/* ---- 4. Zero open runs — skip silently -------------------------------------------- */

$wpdb = fresh_wpdb();
$wpdb->attachments_by_message[ 901 ] = array(
	array( 'id' => 3, 'message_id' => 901, 'file_type' => 'photo', 'data_url' => 'https://cdn.example/inbox/x.jpg' ),
);
BizCity_CRM_Pipeline_Run_Service::$runs_by_contact[ 502 ] = array(); // no runs at all
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 901, 'contact_id' => 502 ) );
check_link( 'zero open runs: nothing is linked', array() === $wpdb->documents );

/* ---- 5. Two-or-more open runs — ambiguous, skip rather than guess (D71-3) --------- */

$wpdb = fresh_wpdb();
$wpdb->attachments_by_message[ 902 ] = array(
	array( 'id' => 4, 'message_id' => 902, 'file_type' => 'photo', 'data_url' => 'https://cdn.example/inbox/y.jpg' ),
);
BizCity_CRM_Pipeline_Run_Service::$runs_by_contact[ 503 ] = array(
	array( 'id' => 10, 'status' => 'open' ),
	array( 'id' => 11, 'status' => 'open' ),
);
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 902, 'contact_id' => 503 ) );
check_link( 'two open runs at once: nothing is linked (never guesses)', array() === $wpdb->documents );

/* ---- 6. A terminal run does not count as "open" ----------------------------------- */

$wpdb = fresh_wpdb();
$wpdb->attachments_by_message[ 903 ] = array(
	array( 'id' => 5, 'message_id' => 903, 'file_type' => 'photo', 'data_url' => 'https://cdn.example/inbox/z.jpg' ),
);
BizCity_CRM_Pipeline_Run_Service::$runs_by_contact[ 504 ] = array(
	array( 'id' => 20, 'status' => 'won' ),
	array( 'id' => 21, 'status' => 'open' ),
);
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 903, 'contact_id' => 504 ) );
check_link( 'a won run is excluded — exactly one OPEN run still links', 1 === count( $wpdb->documents ) );
check_link( 'links to the open run, not the terminal one', 21 === ( array_values( $wpdb->documents )[0]['related_entity_id'] ?? null ) );

/* ---- 7. No attachments on the message — skip -------------------------------------- */

$wpdb = fresh_wpdb();
BizCity_CRM_Pipeline_Run_Service::$runs_by_contact[ 505 ] = array( array( 'id' => 30, 'status' => 'open' ) );
BizCity_CRM_Pipeline_Document_Link::on_message_persisted( array( 'message_id' => 904, 'contact_id' => 505 ) );
check_link( 'a message with no attachments links nothing', array() === $wpdb->documents );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
