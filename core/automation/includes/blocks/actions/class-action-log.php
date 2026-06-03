<?php
/**
 * Action: log debug message (no side effect ngoài error_log + return).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Log extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'action.log'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'Ghi log debug',
			'short'    => 'log',
			'category' => 'action',
			'color'    => '#525252',
			'icon'     => 'file-text',
			'defaults' => array( 'label' => 'log', 'message' => '{{*}}' ),
			'fields'   => array(
				array( 'name' => 'label',   'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'message', 'label' => 'Nội dung log', 'type' => 'textarea' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		$msg = (string) ( $data['message'] ?? '' );
		// Special token {{*}} = full ctx snapshot.
		if ( strpos( $msg, '{{*}}' ) !== false ) {
			$msg = str_replace( '{{*}}', wp_json_encode( $ctx ), $msg );
		}
		$msg = (string) $this->resolve( $msg, $ctx );
		$this->debug( $msg );
		return array( 'logged' => true, 'message' => $msg );
	}
}
