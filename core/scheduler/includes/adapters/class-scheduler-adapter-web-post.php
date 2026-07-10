<?php
/**
 * Adapter: web_post (Channel Gateway Web Post Publisher).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-06-03 (SCH-NC W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/class-scheduler-adapter-base.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Web_Post' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Web_Post extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'web_post';
	}

	public function label() {
		return 'Đăng web';
	}

	public function metadata_schema() {
		return [
			'web_title'           => [ 'type' => 'string', 'required' => true ],
			'web_content'         => [ 'type' => 'string', 'required' => true ],
			'web_status'          => [ 'type' => 'string', 'required' => false ],
			'web_category_ids'    => [ 'type' => 'array',  'required' => false ],
			'web_tag_names'       => [ 'type' => 'array',  'required' => false ],
			'web_image_url'       => [ 'type' => 'string', 'required' => false ],
			'web_publish_status'  => [ 'type' => 'string', 'required' => false ],
			'web_post_id'         => [ 'type' => 'int',    'required' => false ],
		];
	}
}
