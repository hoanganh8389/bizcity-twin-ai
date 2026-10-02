<?php
/**
 * BizCity_Automation_Action_MCP_Service — automation.run of the one MCP standard (PHASE-0.88 L1-11, lane CL-B;
 * mode `automation`, new in 0.88).
 *
 * The workflow engine lives in the sibling add-on (bizcity-twin-brain-addon/automation). This tool is registered only
 * when that add-on is loaded (BizCity_Automation_Repo_Workflows + BizCity_Automation_Repo_Runs); without it the tool is
 * not in tools/list and a call answers MCP_TOOL_NOT_FOUND. Commit = the add-on's own queue: Repo_Runs::enqueue() +
 * the `bizcity_automation_run_async` loopback event, exactly like `POST bizcity-automation/v1/workflows/{id}/run?async=1`
 * — so a long workflow never runs inside the MCP request (8 s write budget). Site administrators only.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-11 — new file, automation.run on BizCity_MCP_Tool_Registry.
final class BizCity_Automation_Action_MCP_Service {

	const INPUT_MAX_BYTES = 8192;

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function available() {
		return class_exists( 'BizCity_Automation_Repo_Workflows' ) && class_exists( 'BizCity_Automation_Repo_Runs' );
	}

	public static function register_tools() {
		if ( ! self::available() ) {
			return; // add-on absent: no tool (never a fake success)
		}

		// @mcp bizcity-mcp-standard@1 tool automation.run
		BizCity_MCP_Tool_Registry::register( 'automation.run', array(
			'title'          => 'Chạy kịch bản tự động',
			'description'    => 'Chạy một kịch bản Automation (workflow_id) của cửa hàng, có thể kèm dữ liệu đầu vào (input). Chỉ quản trị viên dùng được. Kịch bản chạy nền; kết quả trả run_id để theo dõi. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'workflow_id' ), 'properties' => array(
				'workflow_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'input'         => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array(
				'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'expires_at'    => array( 'type' => 'string' ),
				'run_id'        => array( 'type' => 'string' ),
				'workflow'      => array( 'type' => 'object' ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'open_world'     => true, // a workflow may message customers or call external services
			'required_scope' => 'automation.run',
			'handler'        => array( __CLASS__, 'run' ),
			'preview'        => array( __CLASS__, 'preview' ),
			'mode'           => 'automation',
			'scopes'         => array( 'automation.run' ),
			'confirm'        => 'always',
			'llm_alias'      => 'automation_run',
			'fallback_pack'  => null,
			'since'          => '0.88.4',
		) );
	}

	public static function preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return array(
				'summary'  => 'Chạy kịch bản "' . $plan['workflow']['name'] . '"' . ( $plan['input'] ? ' với ' . count( $plan['input'] ) . ' trường dữ liệu' : '' ) . '.',
				'workflow' => $plan['workflow'],
			);
		} );
	}

	public static function run( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$payload = array_merge( $plan['input'], array(
				'_owner_user_id' => (int) $uid,
				'wp_user_id'     => (int) $uid,
				'source'         => 'mcp.automation_run',
			) );
			$run_id = BizCity_Automation_Repo_Runs::enqueue( (int) $plan['workflow']['id'], $payload );
			if ( is_wp_error( $run_id ) ) {
				return BizCity_MCP_Action_Support::from_business( $run_id );
			}
			do_action( 'bizcity_automation_run_enqueued', $run_id, (int) $plan['workflow']['id'], $payload );
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'bizcity_automation_run_async', array( $run_id ) ) ) {
				wp_schedule_single_event( time(), 'bizcity_automation_run_async', array( $run_id ) );
			}
			return array( 'run_id' => (string) $run_id, 'workflow' => $plan['workflow'] );
		} );
	}

	private static function plan( array $args, $uid ) {
		if ( ! BizCity_MCP_Action_Support::is_admin( $uid ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ quản trị viên mới chạy được kịch bản tự động.', 403 );
		}
		if ( ! self::available() ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::TOOL_NOT_FOUND, 'Add-on Automation chưa được cài trên site này.', 404 );
		}
		$id = (int) ( $args['workflow_id'] ?? 0 );
		$wf = $id > 0 ? BizCity_Automation_Repo_Workflows::find( $id ) : null;
		if ( ! is_array( $wf ) || ! empty( $wf['deleted_at'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy kịch bản #' . $id . '.', 404 );
		}
		if ( empty( $wf['enabled'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Kịch bản "' . (string) ( $wf['name'] ?? '#' . $id ) . '" đang tắt. Bật nó trong Automation rồi thử lại.', 409 );
		}
		$input = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();
		foreach ( array_keys( $input ) as $k ) {
			if ( '_' === substr( (string) $k, 0, 1 ) || in_array( (string) $k, array( 'wp_user_id', 'source' ), true ) ) {
				unset( $input[ $k ] ); // identity / engine keys are set by the server, never by the caller
			}
		}
		if ( strlen( (string) wp_json_encode( $input ) ) > self::INPUT_MAX_BYTES ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Dữ liệu đầu vào quá lớn (tối đa 8 KB).', 413 );
		}
		return array( 'workflow' => array( 'id' => (int) $wf['id'], 'name' => (string) ( $wf['name'] ?? '' ) ), 'input' => $input );
	}
}

BizCity_Automation_Action_MCP_Service::init();
