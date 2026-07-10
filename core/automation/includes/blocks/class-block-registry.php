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
		// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W2 — trigger.skill_intent.
		$this->register( new BizCity_Automation_Trigger_Skill_Intent() );
		$this->register( new BizCity_Automation_Trigger_Cron() );
		$this->register( new BizCity_Automation_Trigger_Webhook() );

		$this->register( new BizCity_Automation_Action_Search_KG() );
		$this->register( new BizCity_Automation_Action_Reply_Zalo() );
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — register per-day Zalo loop action.
		if ( class_exists( 'BizCity_Automation_Action_Reply_Zalo_Each_Day' ) ) {
			$this->register( new BizCity_Automation_Action_Reply_Zalo_Each_Day() );
		}
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
		// [2026-06-03 Johnny Chu] WF-AUTO BRIDGE W1 — action.invoke_skill bridge block.
		$this->register( new BizCity_Automation_Action_Invoke_Skill() );
		// [2026-06-07 Johnny Chu] PHASE-0.38.W1.5 — action.create_woo_order (Order Fulfillment Hub).
		$this->register( new BizCity_Automation_Action_Create_Woo_Order() );
		// [2026-06-07 Johnny Chu] PHASE-0.40 G7.2 — action.notify_discord (Discord webhook notification).
		$this->register( new BizCity_Automation_Action_Notify_Discord() );
		// [2026-06-18 Johnny Chu] PHASE-ZALOBOT-ASTRO — action.run_astro (resolve coachee + natal)
		if ( class_exists( 'BizCity_Automation_Action_Run_Astro' ) ) {
			$this->register( new BizCity_Automation_Action_Run_Astro() );
		}
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — action.run_astro_transit (transit day-by-day)
		if ( class_exists( 'BizCity_Automation_Action_Run_Astro_Transit' ) ) {
			$this->register( new BizCity_Automation_Action_Run_Astro_Transit() );
		}
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — action.run_astro_relation_assessment.
		if ( class_exists( 'BizCity_Automation_Action_Run_Astro_Relation_Assessment' ) ) {
			$this->register( new BizCity_Automation_Action_Run_Astro_Relation_Assessment() );
		}
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — action.pick_best_day_for_intent.
		if ( class_exists( 'BizCity_Automation_Action_Pick_Best_Day_For_Intent' ) ) {
			$this->register( new BizCity_Automation_Action_Pick_Best_Day_For_Intent() );
		}
		// [2026-07-04 Johnny Chu] HOTFIX — register blocks that had require_once but were missing here.
		if ( class_exists( 'BizCity_Automation_Action_Trending_Research' ) ) {
			$this->register( new BizCity_Automation_Action_Trending_Research() );
		}
		if ( class_exists( 'BizCity_Automation_Action_Web_Research' ) ) {
			$this->register( new BizCity_Automation_Action_Web_Research() );
		}
		if ( class_exists( 'BizCity_Automation_Action_Generate_Content' ) ) {
			$this->register( new BizCity_Automation_Action_Generate_Content() );
		}
		if ( class_exists( 'BizCity_Automation_Action_Video_Submit' ) ) {
			$this->register( new BizCity_Automation_Action_Video_Submit() );
		}
		if ( class_exists( 'BizCity_Automation_Action_Reply_FB_Message' ) ) {
			$this->register( new BizCity_Automation_Action_Reply_FB_Message() );
		}
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — action.generate_image
		if ( class_exists( 'BizCity_Automation_Action_Generate_Image' ) ) {
			$this->register( new BizCity_Automation_Action_Generate_Image() );
		}

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
