<?php
/**
 * Diagnostics probe: retired ZaloBot memory builder removal.
 *
 * Verifies the retired builder is no longer loaded or referenced while the
 * canonical TwinBrain memory writer remains available:
 *   Disk   — legacy ZaloBot memory source and hooks are absent.
 *   Loader — canonical writer and channel-context helper are loaded.
 *   Runtime — channel aliases normalize to the writer context contract.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-07-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Zalobot_Memory_Unify', false ) ) {
	return;
}

final class BizCity_Probe_Zalobot_Memory_Unify implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.zalobot.memory_unify'; }
	public function label(): string { return 'ZaloBot · retired memory builder removed'; }
	public function description(): string {
		return 'Verify the obsolete ZaloBot memory builder, cron, admin page, and legacy SQL path are removed while canonical TwinBrain memory remains available.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 64; }
	public function icon(): string { return 'workflow'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! function_exists( 'bizcity_memory_writer_ctx_from_channel' ) ) {
			return 'Canonical channel memory context helper chưa load.';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			return 'BizCity_TwinBrain_Memory_Writer chưa load.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) || ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return 'Canonical user-memory filestore owner/contract chưa load.';
		}
		if ( ! BizCity_File_Contract_Registry::has( 'core.knowledge.user_memory' ) ) {
			return 'Contract core.knowledge.user_memory chưa được đăng ký.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login để chứng minh user-bound memory owner.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — verify legacy Zalo memory runtime surfaces are absent before exercising canonical memory.
		$root = dirname( __DIR__, 4 );
		$bootstrap_file = $root . '/plugins/bizcity-zalo-bot/bootstrap.php';
		$database_file = $root . '/plugins/bizcity-zalo-bot/includes/class-database.php';
		$bootstrap_source = is_readable( $bootstrap_file ) ? (string) file_get_contents( $bootstrap_file ) : '';
		$database_source = is_readable( $database_file ) ? (string) file_get_contents( $database_file ) : '';
		$legacy_class_absent = ! is_file( $root . '/plugins/bizcity-zalo-bot/includes/class-memory.php' )
			&& ! class_exists( 'BizCity_Zalo_Bot_Memory' );
		$legacy_hooks_absent = strpos( $bootstrap_source, 'BizCity_Zalo_Bot_Memory' ) === false
			&& strpos( $bootstrap_source, 'bizcity_zalo_bot_daily_memory' ) === false
			&& strpos( $database_source, 'bizcity_zalo_bot_memory' ) === false;
		$disk_ok = $bootstrap_source !== '' && $database_source !== '' && $legacy_class_absent && $legacy_hooks_absent;
		$ctx->emit_step( array(
			'label'  => 'Disk · legacy Zalo memory builder is removed',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'Legacy class file, bootstrap hooks, cron hook, and memory-table migration marker are absent.'
				: sprintf( 'Legacy removal check failed: bootstrap_readable=%s database_readable=%s legacy_class_absent=%s legacy_hooks_absent=%s.', is_readable( $bootstrap_file ) ? 'yes' : 'no', is_readable( $database_file ) ? 'yes' : 'no', $legacy_class_absent ? 'yes' : 'no', $legacy_hooks_absent ? 'yes' : 'no' ),
		) );

		$loader_ok = class_exists( 'BizCity_TwinBrain_Memory_Writer' )
			&& function_exists( 'bizcity_memory_writer_ctx_from_channel' );
		$ctx->emit_step( array(
			'label'  => 'Loader · canonical writer context is available',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Memory_Writer and channel context helper are loaded.' : 'Required canonical memory classes are not loaded.',
		) );

		$normalized = bizcity_memory_writer_ctx_from_channel( array(
			'blog_id'        => get_current_blog_id(),
			'wp_user_id'     => 0,
			'platform'       => 'ZALO_BOT',
			'account_id'     => 'healthtest-bot',
			'from_user_id'   => 'healthtest-user',
			'conversation_chat_id' => 'zalobot_healthtest_chat',
			'identity_uuid'  => 'healthtest-uuid',
		) );
		$required = array( 'blog_id', 'user_id', 'wp_user_id', 'platform', 'channel', 'account_id', 'external_user_id', 'chat_id', 'identity_uuid' );
		$missing = array();
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $normalized ) ) {
				$missing[] = $key;
			}
		}
		$runtime_ok = empty( $missing )
			&& $normalized['platform'] === 'zalo_bot'
			&& $normalized['channel'] === 'zalo_bot'
			&& $normalized['account_id'] === 'healthtest-bot'
			&& $normalized['external_user_id'] === 'healthtest-user'
			&& $normalized['chat_id'] === 'zalobot_healthtest_chat'
			&& $normalized['identity_uuid'] === 'healthtest-uuid';
		$ctx->emit_step( array(
			'label'  => 'Runtime · Zalo aliases normalize to canonical context',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok ? 'platform/account/external user/chat/UUID mapping is stable; no DB or LLM call executed.' : 'Missing or mismatched normalized fields: ' . implode( ', ', $missing ),
		) );

		// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — exercise canonical writer in explicit-only mode; no provider call and no legacy SQL write.
		$trace_id = 'diag-zalobot-memory-' . substr( md5( (string) microtime( true ) . '|' . wp_rand() ), 0, 16 );
		$sentinel = '__healthtest_zalobot_memory_filestore_lark21';
		$write_result = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
			$trace_id,
			'Hãy nhớ ' . $sentinel,
			'',
			array(
				'user_id'            => get_current_user_id(),
				'platform'           => 'zalo_bot',
				'channel'            => 'zalo_bot',
				'account_id'         => 'diag-zalobot',
				'external_user_id'   => 'diag-user',
				'chat_id'            => 'diag-zalobot-chat',
				'enable_llm'         => false,
				'identity_guest_bind'=> false,
			)
		);
		$writer_ok = is_array( $write_result ) && (int) ( $write_result['persisted'] ?? 0 ) > 0;
		$ctx->emit_step( array(
			'label'  => 'Runtime · Memory_Writer persists Zalo memory to filestore',
			'status' => $writer_ok ? 'pass' : 'fail',
			'detail' => $writer_ok ? 'explicit-only writer persisted without LLM/provider call.' : 'Memory_Writer did not persist the explicit-only sentinel.',
		) );

		$file_rows = BizCity_Business_JSONL_File_Store::query( 'core.knowledge.user_memory', array(
			'blog_id' => get_current_blog_id(),
			'user_id' => get_current_user_id(),
			'limit'   => 100,
			'days'    => 2,
			'filter'  => static function ( $row ) use ( $sentinel, $trace_id ) {
				$metadata = (string) ( $row['metadata'] ?? '' );
				return strpos( (string) ( $row['memory_text'] ?? '' ), $sentinel ) !== false
					&& strpos( $metadata, $trace_id ) !== false;
			},
		) );
		$file_ok = ! empty( $file_rows ) && ! empty( $file_rows[0]['record_id'] );
		$ctx->emit_step( array(
			'label'  => 'Runtime · Zalo memory filestore row',
			'status' => $file_ok ? 'pass' : 'fail',
			'detail' => $file_ok ? 'user-memory contract returned the scoped sentinel row.' : 'user-memory contract did not return the scoped sentinel row.',
		) );

		if ( $file_ok ) {
			foreach ( $file_rows as $file_row ) {
				$record_id = (string) ( $file_row['record_id'] ?? '' );
				if ( $record_id !== '' ) {
					BizCity_Business_JSONL_File_Store::delete( 'core.knowledge.user_memory', $record_id, array( 'blog_id' => get_current_blog_id(), 'user_id' => get_current_user_id() ) );
				}
			}
		}
		$runtime_ok = $runtime_ok && $writer_ok && $file_ok;

		$pass = $disk_ok && $loader_ok && $runtime_ok;
		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'ZaloBot legacy memory builder is removed; canonical TwinBrain memory remains available.' : 'ZaloBot legacy memory removal or canonical memory evidence is incomplete.',
			'fix_hint' => $pass ? '' : 'Remove the legacy Zalo memory class, cron, admin hooks, and database migration, then rerun the canonical memory probe.',
		);
	}

	public function cleanup(): void {
		// Static contract probe creates no persistent artifact.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalobot_Memory_Unify';
	return $list;
} );
