<?php
/**
 * BizCity_Automation_Block_Registry — singleton catalog of blocks.
 *
 * Bootstrap flow:
 *   1. core/automation/bootstrap.php gọi `instance()` (lazy).
 *   2. Constructor register tất cả block built-in qua `register()`.
 *   3. Plugin ngoài hook `bizcity_automation_register_blocks` để
 *      thêm block custom: `$registry->register( new MyBlock() );`.
 *
 * Runtime usage (BE-3 runner):
 *   $block = BizCity_Automation_Block_Registry::instance()->get( $node['data']['blockId'] );
 *   if ( ! $block ) { return new WP_Error( 'unknown_block', $blockId ); }
 *   $output = $block->execute( $ctx, $node['data'] );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Block_Registry {

	private static $instance = null;

	/** @var array<string, BizCity_Automation_Block> */
	private $blocks = array();

	/** @var bool */
	private $bootstrapped = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->bootstrap();
	}

	private function bootstrap(): void {
		if ( $this->bootstrapped ) { return; }
		$this->bootstrapped = true;

		// Built-in blocks (mirror FE registry order).
		$this->register( new BizCity_Automation_Trigger_Manual() );
		$this->register( new BizCity_Automation_Trigger_Zalo() );
		$this->register( new BizCity_Automation_Trigger_FB_Comment() );
		$this->register( new BizCity_Automation_Trigger_FB_Message() );        // BE-6.D
		$this->register( new BizCity_Automation_Trigger_Telegram() );          // BE-6.D
		$this->register( new BizCity_Automation_Trigger_TwinBrain_Intent() );  // BE-6.E
		$this->register( new BizCity_Automation_Trigger_TwinBrain_Turn_Completed() ); // BE-7.A
		$this->register( new BizCity_Automation_Trigger_TwinBrain_Tool_Decided() );   // BE-7.A
		$this->register( new BizCity_Automation_Trigger_Cron() );
		$this->register( new BizCity_Automation_Trigger_Webhook() );

		$this->register( new BizCity_Automation_Action_Search_KG() );
		$this->register( new BizCity_Automation_Action_Reply_Zalo() );
		$this->register( new BizCity_Automation_Action_Send_Email() );
		$this->register( new BizCity_Automation_Action_HTTP() );
		$this->register( new BizCity_Automation_Action_DB_Write() );
		$this->register( new BizCity_Automation_Action_Log() );
		$this->register( new BizCity_Automation_Action_Create_CRM_Event() );
		$this->register( new BizCity_Automation_Action_Capture_Attachment() );    // BE-7.C
		$this->register( new BizCity_Automation_Action_Set_Pending_Intent() );    // BE-7.C
		$this->register( new BizCity_Automation_Action_Consume_Attachment() );    // BE-7.C
		$this->register( new BizCity_Automation_Action_Publish_WP_Post() );       // BE-7.C
		$this->register( new BizCity_Automation_Action_Publish_FB_Post() );       // BE-7.C
		$this->register( new BizCity_Automation_Action_Schedule_Event() );        // BE-7.D

		$this->register( new BizCity_Automation_LLM_Compose() );
		$this->register( new BizCity_Automation_LLM_MPR_Think() );             // BE-6.E

		$this->register( new BizCity_Automation_Logic_Condition() );

		/**
		 * Allow third-party plugins to register custom blocks.
		 * Hook: bizcity_automation_register_blocks
		 *
		 * @param BizCity_Automation_Block_Registry $registry
		 */
		do_action( 'bizcity_automation_register_blocks', $this );
	}

	public function register( BizCity_Automation_Block $block ): void {
		$id = $block->id();
		if ( ! preg_match( '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-automation] invalid block id: ' . $id );
			}
			return;
		}
		$this->blocks[ $id ] = $block;
	}

	public function get( string $id ) {
		return $this->blocks[ $id ] ?? null;
	}

	public function has( string $id ): bool {
		return isset( $this->blocks[ $id ] );
	}

	/** @return array<string, BizCity_Automation_Block> */
	public function all(): array {
		return $this->blocks;
	}

	/**
	 * Export catalog cho FE REST `/blocks`:
	 *
	 * [
	 *   { id, kind, label, category, short, defaults, fields, color, icon },
	 *   ...
	 * ]
	 *
	 * @return array<int, array>
	 */
	public function export_catalog(): array {
		$out = array();
		foreach ( $this->blocks as $id => $block ) {
			$meta = $block->meta();
			$out[] = array_merge(
				array( 'id' => $id, 'kind' => $block->kind() ),
				$meta
			);
		}
		return $out;
	}
}
