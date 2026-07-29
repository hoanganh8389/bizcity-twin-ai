<?php
/**
 * Action: Learning Share Link
 *
 * Mints a public, no-login share link to the TwinChat learning console log
 * of one notebook/source (cron + admin-ajax both included), via
 * BizCity_TwinChat_Learning_Share_Adapter. Designed to chain right after
 * `action.capture_to_notebook` for the case-study flow:
 *
 *   user sends `@ghichu` + .doc/.xlsx attachment
 *     → action.capture_to_notebook   (ingest → {{capture.notebook_id}})
 *     → action.learning_share_link   (this block → {{share.share_url}})
 *     → action.reply_zalo / action.reply_fb_message
 *         content: "✅ Đã lưu xong, theo dõi tiến trình học tại: {{share.share_url}}"
 *
 * This block does NOT send any message itself — it only mints the link and
 * exposes it as node output, so any existing reply/notify block downstream
 * can consume it via template binding. Keeps one responsibility per node.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-0.48-LEARNING-LOG-SHARE-LINK
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Learning_Share_Link extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.learning_share_link'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Link theo dõi Học (Learning)',
			'short'    => 'learning_share_link',
			'category' => 'state',
			'color'    => '#0ea5e9',
			'icon'     => 'link',
			'defaults' => array(
				'label'       => 'learning_share_link',
				'notebook_id' => '',
				'source_id'   => '',
				'job_id'      => '',
				'ttl_days'    => 30,
			),
			'fields' => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',                       'type' => 'text' ),
				array( 'name' => 'notebook_id', 'label' => 'Notebook ID (template)',             'type' => 'text' ),
				array( 'name' => 'source_id',   'label' => 'Source ID (template, optional)',     'type' => 'text' ),
				array( 'name' => 'job_id',      'label' => 'Job ID (template, optional — pin to one run)', 'type' => 'text' ),
				array( 'name' => 'ttl_days',    'label' => 'Hết hạn sau (ngày)',                 'type' => 'text' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Share_Adapter' ) ) {
			$this->note_event( 'learning_share_link_failed', array(
				'reason' => 'adapter_unavailable',
				'code'   => 'learning_share_adapter_unavailable',
			) );
			return new WP_Error( 'learning_share_adapter_unavailable', 'Learning share adapter chưa sẵn sàng trên site này.' );
		}

		$notebook_id = (int) $this->resolve( $data['notebook_id'] ?? 0, $ctx );
		if ( $notebook_id <= 0 ) {
			$this->note_event( 'learning_share_link_failed', array(
				'reason' => 'notebook_id_missing',
				'code'   => 'invalid_notebook',
			) );
			return new WP_Error( 'invalid_notebook', 'learning_share_link: thiếu notebook_id (thường lấy từ {{capture_to_notebook.notebook_id}}).' );
		}

		$source_id = (int) $this->resolve( $data['source_id'] ?? 0, $ctx );
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — when template leaves
		// source_id empty, auto-pick from upstream capture output so links can
		// point directly to one source learning log without manual wiring.
		if ( $source_id <= 0 ) {
			$source_id = $this->guess_source_id_from_context( $ctx );
		}
		$job_id    = (int) $this->resolve( $data['job_id'] ?? 0, $ctx );
		$ttl_days  = (int) $this->resolve( $data['ttl_days'] ?? 30, $ctx );
		$ttl_s     = $ttl_days > 0 ? $ttl_days * DAY_IN_SECONDS : 0;

		$link = BizCity_TwinChat_Learning_Share_Adapter::instance()->create_link( $notebook_id, $source_id, array(
			'job_id' => $job_id,
			'ttl_s'  => $ttl_s,
		) );
		if ( is_wp_error( $link ) ) {
			$this->note_event( 'learning_share_link_failed', array(
				'reason' => 'create_link_failed',
				'error'  => $link->get_error_message(),
				'code'   => (string) $link->get_error_code(),
			) );
			return $link;
		}

		$this->note_event( 'learning_share_link_ok', array(
			'notebook_id' => $notebook_id,
			'source_id'   => $source_id,
			'expires_at'  => (string) $link['expires_at'],
		) );

		return array(
			'share_url'   => (string) $link['url'],
			'expires_at'  => (string) $link['expires_at'],
			'notebook_id' => $notebook_id,
			'source_id'   => $source_id,
			'job_id'      => (int) $link['job_id'],
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.6 — best-effort source id lookup
	 * from common upstream capture outputs.
	 */
	private function guess_source_id_from_context( array $ctx ): int {
		$candidates = array(
			array( 'cap', 'first_source_id' ),
			array( 'capture_to_notebook', 'first_source_id' ),
			array( 'capture_to_notebook', 'source_id' ),
			array( 'cap', 'source_id' ),
			array( 'trigger', 'source_id' ),
		);
		foreach ( $candidates as $path ) {
			$node = $ctx;
			$ok   = true;
			foreach ( $path as $key ) {
				if ( is_array( $node ) && array_key_exists( $key, $node ) ) {
					$node = $node[ $key ];
				} else {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$source_id = (int) $node;
				if ( $source_id > 0 ) {
					return $source_id;
				}
			}
		}
		return 0;
	}
}
