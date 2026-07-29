<?php
/**
 * Action: Consume pending attachment + clear slot.
 *
 * Đọc `attachment_url` đã lưu từ turn trước (qua `action.capture_attachment`),
 * trả ra ctx output để các block sau dùng (vd `action.publish_wp_post`,
 * `action.publish_fb_post`). Mặc định CLEAR pending slot sau khi đọc — đảm
 * bảo không leak state sang turn sau.
 *
 * Output: { attachment_url, attachment_urls, attachments, attachment_id, attachment_ids, intent, slots, found }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.C (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Consume_Attachment extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.consume_attachment'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Lấy ảnh/file đã lưu',
			'short'    => 'consume_attachment',
			'category' => 'state',
			'color'    => '#0891b2',
			'icon'     => 'download',
			'defaults' => array(
				'label'         => 'consume_attachment',
				'clear_slot'    => 1,
				'sideload_to_wp'=> true,
			),
			'fields' => array(
				array( 'name' => 'label',          'label' => 'Tên hiển thị',        'type' => 'text' ),
				array( 'name' => 'clear_slot',     'label' => 'Xoá slot sau khi đọc','type' => 'checkbox' ),
				array( 'name' => 'sideload_to_wp', 'label' => 'Lưu ảnh vào WP Media', 'type' => 'checkbox' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$chat_id = (string) ( $ctx['trigger']['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			return new WP_Error( 'no_chat_id', 'consume_attachment: trigger.chat_id rỗng.' );
		}

		// Resume payload đã được matcher đặt vào ctx.trigger._resume.
		$resume = $ctx['trigger']['_resume'] ?? array();
		$state  = is_array( $resume ) && ! empty( $resume )
			? $resume
			: BizCity_Automation_Pending_State::get( $chat_id );
		if ( empty( $state['attachment_url'] ) ) {
			// [2026-07-21 Johnny Chu] PHASE-SEEDREAM-45-FIX — partial _resume from listener replay must not hide the real pending attachment.
			$pending = BizCity_Automation_Pending_State::get( $chat_id );
			if ( ! empty( $pending['attachment_url'] ) ) {
				$state = array_merge( $state, $pending );
			}
		}

		$attachments = is_array( $state['attachments'] ?? null ) ? array_values( $state['attachments'] ) : array();
		if ( empty( $attachments ) && ! empty( $state['attachment_url'] ) ) {
			// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — upgrade legacy single URL state to canonical attachments[].
			$attachments[] = array( 'kind' => 'image', 'url' => (string) $state['attachment_url'], 'source_url' => (string) $state['attachment_url'], 'attachment_id' => 0 );
		}
		$url    = (string) ( $state['attachment_url'] ?? ( $attachments[0]['url'] ?? '' ) );
		$source_url = $url;
		$intent = (string) ( $state['intent']         ?? '' );
		$slots  = is_array( $state['slots'] ?? null ) ? $state['slots'] : array();
		$attachment_id = 0;
		$attachment_urls = array();
		$source_urls = array();
		$attachment_ids = array();

		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — Zalo CDN URL có thể hết hạn/không cho Graph fetch; sideload sang WP để publish FB và My Content dùng URL bền vững.
		$sideload_to_wp = array_key_exists( 'sideload_to_wp', $data ) ? (bool) $data['sideload_to_wp'] : true;
		foreach ( $attachments as $idx => $item ) {
			if ( ! is_array( $item ) ) { continue; }
			$item_url = (string) ( $item['wp_url'] ?? $item['url'] ?? $item['source_url'] ?? '' );
			$item_source = (string) ( $item['source_url'] ?? $item_url );
			$item_id = (int) ( $item['attachment_id'] ?? 0 );
			if ( $item_url !== '' && $sideload_to_wp && $item_id <= 0 ) {
				$sideloaded = $this->sideload_url_to_media( $item_url, (string) ( $ctx['trigger']['text'] ?? ( 'attachment-' . ( $idx + 1 ) ) ) );
				if ( ! empty( $sideloaded['url'] ) ) {
					$item_url = (string) $sideloaded['url'];
					$item_id = (int) ( $sideloaded['id'] ?? 0 );
					$item['wp_url'] = $item_url;
					$item['attachment_id'] = $item_id;
				}
			}
			if ( $item_url === '' ) { continue; }
			$item['url'] = $item_url;
			$item['source_url'] = $item_source;
			$attachments[ $idx ] = $item;
			$attachment_urls[] = $item_url;
			$source_urls[] = $item_source;
			$attachment_ids[] = $item_id;
		}
		if ( ! empty( $attachment_urls ) ) {
			$url = (string) $attachment_urls[0];
			$source_url = (string) $source_urls[0];
			$attachment_id = (int) $attachment_ids[0];
		}

		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — create artifact at the first durable image point so /gpt/mycontent/ can show trace before later blocks finish.
		$content_artifact_id = class_exists( 'BizCity_Content_Artifact_Service' )
			? BizCity_Content_Artifact_Service::create_or_get_from_ctx( $ctx, array( 'content_type' => 'fb_post' ) )
			: 0;
		if ( $content_artifact_id > 0 ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, $url !== '' ? 'image_ready' : 'failed', array(
				'_bizcity_image_url' => $url,
				'_bizcity_error_code' => $url !== '' ? '' : 'attachment_missing',
				'_bizcity_error_message' => $url !== '' ? '' : 'Không tìm thấy ảnh đã gửi trước đó.',
			) );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => $url !== '' ? 'image_ready' : 'failed', 'source' => 'automation', 'block_id' => $this->id(), 'status' => $url !== '' ? 'ok' : 'fail',
				'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'error_code' => $url !== '' ? '' : 'attachment_missing', 'message' => $url !== '' ? 'Pending attachment consumed.' : 'Pending attachment missing.',
				'ctx' => array( 'source_url' => $source_url, 'attachment_url' => $url, 'attachment_urls' => $attachment_urls, 'attachment_id' => $attachment_id, 'attachment_ids' => $attachment_ids ),
			) );
		}

		if ( ! empty( $data['clear_slot'] ) ) {
			BizCity_Automation_Pending_State::clear( $chat_id );
		}

		return array(
			'attachment_url' => $url,
			'source_url'      => $source_url,
			'attachment_urls' => $attachment_urls,
			'source_urls'    => $source_urls,
			'attachments'    => $attachments,
			'attachment_id'   => $attachment_id,
			'attachment_ids'  => $attachment_ids,
			'intent'         => $intent,
			'slots'          => $slots,
			'found'          => $url !== '',
		);
	}

	private function sideload_url_to_media( string $url, string $label ): array {
		// [2026-07-21 Johnny Chu] PHASE-IMG-FIRST-FB-FIX — local helper kept here because generate_image helper is private to another block.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array();
		}
		$home = home_url();
		if ( strpos( $url, $home ) === 0 ) {
			return array( 'id' => 0, 'url' => $url );
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_id = media_sideload_image( $url, 0, sanitize_text_field( $label !== '' ? $label : 'attachment' ), 'id' );
		if ( is_wp_error( $attach_id ) ) {
			$this->note_event( 'consume_attachment_sideload_failed', array(
				'reason' => 'http_error',
				'error'  => $attach_id->get_error_message(),
			) );
			return array();
		}
		$wp_url = (string) wp_get_attachment_url( (int) $attach_id );
		return $wp_url !== '' ? array( 'id' => (int) $attach_id, 'url' => $wp_url ) : array();
	}
}
