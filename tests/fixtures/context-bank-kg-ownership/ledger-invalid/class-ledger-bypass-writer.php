<?php
/**
 * Broken fixture: a second writer to the Context Bank pointer ledger.
 *
 * Must report `R-CB.ledger_write_outside_owner`.
 *
 * PHASE-0-RULE-CONTEXT-BANK names exactly one admission path,
 * `BizCity_Context_Bank_Ledger::record()`. A direct write skips receipt
 * validation — `receipt_is_valid()` requires `contract_id`, `record_id`,
 * `event_uuid`, `relative_file`, `byte_offset`, `row_hash`, `content_hash`,
 * `occurred_at`, `operation` and `blog_id`, and checks the row hashes are
 * sha256 and the blog matches the current tenant. A pointer admitted without
 * those checks can reference a file that was never durably written, which is
 * the failure the durable-write-before-pointer ordering exists to prevent.
 *
 * The write goes through a class constant plus an accessor, which is the shape
 * the real ledger uses (`const TABLE_BASE` + `$this->table()`); a rule that only
 * matched the inline literal would inspect zero writers and pass vacuously.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Ledger_Bypass_Writer {

	const TABLE_BASE = 'bizcity_context_bank';

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}

	public function admit( array $reference ): bool {
		global $wpdb;

		$table = $this->table();

		return false !== $wpdb->insert( $table, array(
			'record_id'     => (string) $reference['record_id'],
			'relative_file' => (string) $reference['relative_file'],
			'byte_offset'   => (int) $reference['byte_offset'],
		) );
	}
}
