<?php
/**
 * Canvas Bridge — Tool Image ↔ Canvas Adapter integration.
 *
 * Receives dispatch from BizCity_Canvas_Adapter, resolves which
 * studio page to open (Profile Studio / Product Studio / generic),
 * and returns launch info for the canvas iframe.
 *
 * @package BizCity\ToolImage
 * @since   3.8.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Canvas_Bridge_Image {

	/**
	 * Register Canvas handlers for Tool Image tools.
	 *
	 * @param array $handlers Existing handlers from other plugins.
	 * @return array
	 */
	public static function register_handlers( array $handlers ): array {
		$handlers['generate_image'] = [ __CLASS__, 'handle_dispatch' ];
		return $handlers;
	}

	/**
	 * Handle Canvas dispatch — resolve studio page, return launch info.
	 *
	 * @param string $tool_id Tool identifier (generate_image).
	 * @param array  $params  Entities/slots from intent engine.
	 * @param array  $context {session_id, user_id, conv_id, message, entities, skill}
	 * @return array Job data for Canvas Adapter.
	 */
	public static function handle_dispatch( string $tool_id, array $params, array $context ): array {
		$message  = $context['message'] ?? '';
		$entities = $context['entities'] ?? $params;
		$skill    = $context['skill'] ?? [];

		// ── 1. Resolve which studio to open ──
		$studio = self::resolve_studio( $skill, $entities, $message );

		error_log( '[BZTIMG-Bridge] dispatch: tool=' . $tool_id
			. ' studio=' . $studio['key']
			. ' url=' . $studio['launch_url'] );

		return [
			'workshop'       => $studio['key'],
			'workshop_label' => $studio['label'],
			'tool_type'      => 'image',
			'title'          => $studio['label'],
			'launch_url'     => $studio['launch_url'],
			'auto_execute'   => false,
			'prefill_data'   => $entities,
			'reply'          => sprintf(
				'Đang mở **%s** — %s',
				$studio['label'],
				$studio['description']
			),
			'job_data'       => [
				'studio' => $studio['key'],
				'params' => $entities,
			],
		];
	}

	/**
	 * Resolve which studio page to open based on skill pipeline_json
	 * or entities/message hints.
	 *
	 * @param array  $skill    Skill data (may contain pipeline_json with launch_url).
	 * @param array  $entities Resolved entities.
	 * @param string $message  Original user message.
	 * @return array {key, label, description, launch_url}
	 */
	private static function resolve_studio( array $skill, array $entities, string $message ): array {
		// Check skill's pipeline_json for explicit launch_url
		$pipeline = $skill['pipeline_json'] ?? ( $skill['pipeline'] ?? [] );
		if ( is_string( $pipeline ) ) {
			$pipeline = json_decode( $pipeline, true ) ?: [];
		}

		if ( ! empty( $pipeline['launch_url'] ) ) {
			$url = $pipeline['launch_url'];
			$studio_key = $pipeline['studio'] ?? 'tool-image';

			// Determine label from studio key
			$studios = self::get_studios();
			foreach ( $studios as $s ) {
				if ( $s['key'] === $studio_key || $s['launch_url'] === $url ) {
					return $s;
				}
			}

			// Fallback: use the URL from pipeline
			return [
				'key'         => $studio_key,
				'label'       => 'Image Studio',
				'description' => 'Tạo ảnh AI chuyên nghiệp.',
				'launch_url'  => home_url( $url ),
			];
		}

		// Detect from purpose entity or message keywords
		$purpose = $entities['purpose'] ?? '';
		$msg_lower = mb_strtolower( $message );

		if ( $purpose === 'portrait' || preg_match( '/chân dung|face.?swap|portrait|ảnh đại diện|profile/ui', $msg_lower ) ) {
			return self::get_studios()['profile'] ?? self::get_default_studio();
		}

		if ( $purpose === 'product' || preg_match( '/sản phẩm|product|bán hàng|studio.*sản phẩm/ui', $msg_lower ) ) {
			return self::get_studios()['product'] ?? self::get_default_studio();
		}

		// Default: open the generic image studio
		return self::get_default_studio();
	}

	/**
	 * Get all available studio pages.
	 *
	 * @return array Keyed by studio identifier.
	 */
	private static function get_studios(): array {
		return [
			'profile' => [
				'key'         => 'timg_profile_studio',
				'label'       => 'Profile Studio',
				'description' => 'Face-swap & style-copy: tạo chân dung chuyên nghiệp.',
				'launch_url'  => home_url( '/profile-studio/' ),
			],
			'product' => [
				'key'         => 'timg_product_studio',
				'label'       => 'Product Studio',
				'description' => 'Tạo ảnh sản phẩm chuyên nghiệp: studio lighting, styled scene.',
				'launch_url'  => home_url( '/product-studio/' ),
			],
		];
	}

	/**
	 * Default studio fallback.
	 *
	 * @return array
	 */
	private static function get_default_studio(): array {
		return [
			'key'         => 'tool-image',
			'label'       => 'Image Studio',
			'description' => 'Tạo ảnh AI — chọn mục đích và style.',
			'launch_url'  => home_url( '/tool-image/' ),
		];
	}
}
