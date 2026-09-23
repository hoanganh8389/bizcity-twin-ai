<?php
/**
 * Bizcity Twin AI — Automation Provider
 * Cầu nối WebChat với Automation Engine / Bridge WebChat with Automation workflow engine
 *
 * Registers:
 *   1. External blocks path → bizcity-bot-webchat/blocks/
 *   2. bc_ category for triggers (BizCity Chat Agent Trigger)
 *   3. bc_ category for actions (BizCity Chat Agent Action)
 *   4. it_ category for actions (Intent Tools — universal tool caller)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.3.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_WebChat_Automation_Provider {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // mu-plugins load before regular plugins, so WaicDispatcher
        // (from bizcity-automation) won't exist yet at this point.
        // Defer registration to plugins_loaded when all plugins are available.
        add_action( 'plugins_loaded', [ $this, 'register_filters' ] );
    }

    /**
     * Register filters with bizcity-automation after all plugins are loaded.
     */
    public function register_filters() {
        if ( ! class_exists( 'WaicDispatcher' ) ) {
            return;
        }

        WaicDispatcher::addFilter( 'getExternalBlocksPaths', [ $this, 'register_blocks_path' ] );
        WaicDispatcher::addFilter( 'getBlocksCategories', [ $this, 'register_categories' ] );

        // ── Scheduler Workflow cron hook ──
        add_action( 'bizcity_scheduler_workflow_fire', [ $this, 'handle_scheduler_cron' ], 10, 3 );

        // ── Publish/Unpublish hooks → scheduler event sync ──
        add_action( 'waic_workflow_published', [ $this, 'on_workflow_published' ], 10, 2 );
        add_action( 'waic_workflow_unpublished', [ $this, 'on_workflow_unpublished' ], 10, 2 );
    }

    /**
     * When workflow is published: if trigger is bc_scheduler_run,
     * save event to bizcity_scheduler_events + register WP cron.
     *
     * @param int   $task_id  Workflow task ID.
     * @param array $task     Task row data.
     */
    public function on_workflow_published( int $task_id, array $task ): void {
        $params = ! empty( $task['params'] ) ? json_decode( $task['params'], true ) : [];
        $nodes  = $params['nodes'] ?? [];

        foreach ( $nodes as $node ) {
            if ( ( $node['type'] ?? '' ) !== 'trigger' ) {
                continue;
            }
            $code = $node['data']['code'] ?? '';
            if ( $code !== 'bc_scheduler_run' ) {
                continue;
            }

            // Load trigger class + instantiate with block data
            $trigger_file = BIZCITY_WEBCHAT_DIR . 'blocks/triggers/bc_scheduler_run.php';
            if ( file_exists( $trigger_file ) ) {
                require_once $trigger_file;
            }
            if ( ! class_exists( 'WaicTrigger_bc_scheduler_run' ) ) {
                return;
            }

            $trigger = new \WaicTrigger_bc_scheduler_run( $node );
            $title   = $task['title'] ?? sprintf( 'Workflow #%d', $task_id );
            $result  = $trigger->publish_schedule( $task_id, $title );

            if ( ! is_wp_error( $result ) ) {
                // Store event ID in task meta for unpublish
                update_option( "_bizcity_wf_sched_event_{$task_id}", (int) $result, false );
            }
            return; // Only first trigger
        }
    }

    /**
     * When workflow is unpublished: cancel scheduler event + WP cron.
     *
     * @param int   $task_id  Workflow task ID.
     * @param array $task     Task row data.
     */
    public function on_workflow_unpublished( int $task_id, array $task ): void {
        $event_id = (int) get_option( "_bizcity_wf_sched_event_{$task_id}", 0 );
        if ( ! $event_id ) {
            return;
        }

        $trigger_file = BIZCITY_WEBCHAT_DIR . 'blocks/triggers/bc_scheduler_run.php';
        if ( file_exists( $trigger_file ) ) {
            require_once $trigger_file;
        }
        if ( class_exists( 'WaicTrigger_bc_scheduler_run' ) ) {
            $trigger = new \WaicTrigger_bc_scheduler_run();
            $trigger->unpublish_schedule( $task_id, $event_id );
        }

        delete_option( "_bizcity_wf_sched_event_{$task_id}" );
    }

    /**
     * WP Cron callback: fire scheduled workflow via bc_scheduler_run trigger.
     *
     * @param int $task_id   Workflow task ID.
     * @param int $event_id  Scheduler event ID.
     * @param int $user_id   Owner user ID.
     */
    public function handle_scheduler_cron( int $task_id, int $event_id, int $user_id ): void {
        $trigger_file = BIZCITY_WEBCHAT_DIR . 'blocks/triggers/bc_scheduler_run.php';
        if ( file_exists( $trigger_file ) ) {
            require_once $trigger_file;
        }
        if ( class_exists( 'WaicTrigger_bc_scheduler_run' ) ) {
            WaicTrigger_bc_scheduler_run::cron_fire( $task_id, $event_id, $user_id );
        }
    }

    /**
     * Provide bizcity-bot-webchat/blocks/ as external blocks directory.
     *
     * @param array $paths Existing external paths.
     * @return array
     */
    public function register_blocks_path( $paths ) {
        $blocks_dir = BIZCITY_WEBCHAT_DIR . 'blocks/';
        if ( is_dir( $blocks_dir ) ) {
            $paths[] = $blocks_dir;
        }
        return $paths;
    }

    /**
     * Register bc_ (BizCity Chat Agent) and it_ (Intent Tools) categories.
     *
     * @param array $cats Existing categories.
     * @return array
     */
    public function register_categories( $cats ) {
        // ── Triggers: bc_ = BizCity Chat Agent Triggers ──
        if ( ! isset( $cats['triggers']['bc'] ) ) {
            $cats['triggers']['bc'] = [
                'name' => __( 'BizCity Chat Agent', 'bizcity-twin-ai' ),
                'desc' => __( 'Trigger from Admin Chat / WebChat — receive messages, commands, images', 'bizcity-twin-ai' ),
            ];
        }

        // ── Actions: bc_ = BizCity Chat Agent Actions ──
        if ( ! isset( $cats['actions']['bc'] ) ) {
            $cats['actions']['bc'] = [
                'name' => __( 'BizCity Chat Agent', 'bizcity-twin-ai' ),
                'desc' => __( 'Send reply messages to Admin Chat / WebChat', 'bizcity-twin-ai' ),
            ];
        }

        // ── Actions: it_ = Agent (universal tool caller) ──
        if ( ! isset( $cats['actions']['it'] ) ) {
            $cats['actions']['it'] = [
                'name' => __( 'Agent & AI Tools', 'bizcity-twin-ai' ),
                'desc' => __( 'Call any AI tool registered in BizCity Intent Engine', 'bizcity-twin-ai' ),
            ];
        }

        return $cats;
    }
}
