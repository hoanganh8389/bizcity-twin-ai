<?php
/**
 * Adapter: fb_post (Channel Gateway Facebook publisher).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-06-03 (SCH-NC W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/class-scheduler-adapter-base.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_FB_Post' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_FB_Post extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'fb_post';
	}

	public function label() {
		return 'Đăng Facebook';
	}

	public function metadata_schema() {
		return [
			'fb_page_id'        => [ 'type' => 'string', 'required' => true ],
			'fb_content'        => [ 'type' => 'string', 'required' => true ],
			'fb_image_url'      => [ 'type' => 'string', 'required' => false ],
			'fb_publish_status' => [ 'type' => 'string', 'required' => false ],
			'fb_post_id'        => [ 'type' => 'string', 'required' => false ],
		];
	}
}
