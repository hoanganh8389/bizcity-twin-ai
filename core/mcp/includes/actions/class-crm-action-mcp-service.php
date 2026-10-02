<?php
/**
 * BizCity_CRM_Action_MCP_Service — CRM tools of the one MCP standard (PHASE-0.88 L1-8 / L1-9, lane CL-B):
 * crm.customer.lookup, crm.customer.update, staff.notify, staff.assign.
 *
 * Thin handlers over the CRM owners (no business logic here):
 *  - scope: BizCity_CRM_Agent_Mode_Delegate::customers_scope() (D-TAA-7: lead/agent only see contacts assigned to them)
 *    + BizCity_CRM_REST_Controller::can_{read,write}_contact_scope() (BizCity_MCP_Action_Support::contact_access()).
 *  - writes: BizCity_CRM_Pipeline_Stage_Service::change() (note + stage), BizCity_CRM_REST_Controller::put_crm_contact()
 *    (tags), BizCity_CRM_Contact_Enrichment::set_birthday(), BizCity_CRM_Task_Handoff::create() (assign; its hook drives
 *    BizCity_CRM_Task_Handoff_Notify), bizcity_channel_send() to the staff member's own Zalo Bot binding (notify).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-8 — new file, CRM tools on BizCity_MCP_Tool_Registry.
final class BizCity_CRM_Action_MCP_Service {

	const LOOKUP_MAX  = 20;
	const NOTE_MAX    = 2000;
	const NOTIFY_MAX  = 500;
	const NOTIFY_TTL  = 600; // same message to the same person within 10 min is sent once
	const STAGES      = array( 'target', 'contacted', 'consult', 'quote', 'won', 'repeat', 'lost' );

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return; // CRM plugin not loaded on this site: the tools do not exist (never a fake success).
		}
		$S = 'BizCity_MCP_Tool_Registry';

		// @mcp bizcity-mcp-standard@1 tool crm.customer.lookup
		BizCity_MCP_Tool_Registry::register( 'crm.customer.lookup', array(
			'title'          => 'Tra cứu khách hàng',
			'description'    => 'Tìm khách trong CRM theo tên, số điện thoại hoặc mã khách (contact_ref "crm:123"). Trả tối đa 20 khách: tên, SĐT che còn 3 số cuối, giai đoạn, số đơn, lần mua và lần liên hệ gần nhất. Lead/nhân viên chỉ thấy khách được giao cho mình. Không có query thì trả các khách hoạt động gần đây.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'query'       => array( 'type' => 'string', 'maxLength' => 120 ),
				'contact_ref' => array( 'type' => 'string' ),
				'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::LOOKUP_MAX, 'default' => 10 ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'customers' => array( 'type' => 'array' ), 'scope' => array( 'type' => 'string', 'enum' => array( 'shop', 'person' ) ), 'total' => array( 'type' => 'integer' ) ), array( 'customers' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'crm.read',
			'handler'        => array( __CLASS__, 'lookup' ),
			'mode'           => 'customers',
			'scopes'         => array( 'crm.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'biz_customer_find',
			'fallback_pack'  => 'customers',
			'since'          => '0.88.2',
		) );

		// @mcp bizcity-mcp-standard@1 tool crm.customer.update
		BizCity_MCP_Tool_Registry::register( 'crm.customer.update', array(
			'title'          => 'Cập nhật khách hàng',
			'description'    => 'Ghi chú, nhãn, ngày sinh hoặc giai đoạn của một khách CRM. Lead/agent chỉ sửa được khách được giao cho mình.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'contact_ref' ), 'properties' => array(
				'contact_ref' => array( 'type' => 'string' ),
				'note'        => array( 'type' => 'string' ),
				'tags_add'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'birthday'    => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'stage'       => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'contact_ref' => array( 'type' => 'string' ), 'changed' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ), array( 'contact_ref', 'changed' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'crm.write',
			'handler'        => array( __CLASS__, 'update' ),
			'mode'           => 'customers',
			'scopes'         => array( 'crm.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'crm_customer_update',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool staff.notify
		BizCity_MCP_Tool_Registry::register( 'staff.notify', array(
			'title'          => 'Nhắn nhân viên',
			'description'    => 'Gửi một tin ngắn (tối đa 500 ký tự) tới một nhân viên CRM của cửa hàng qua Zalo Bot mà người đó đã liên kết. Chỉ định người nhận bằng user_id hoặc tên. Không đưa SĐT/địa chỉ khách vào tin.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'message' ), 'properties' => array(
				'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'name'    => array( 'type' => 'string', 'maxLength' => 120 ),
				'message' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => self::NOTIFY_MAX ),
			) ),
			'output_schema'  => $S::envelope_schema( array( 'sent' => array( 'type' => 'boolean' ), 'recipient' => array( 'type' => 'object' ), 'duplicate' => array( 'type' => 'boolean' ) ), array( 'sent' ) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => true,
			'required_scope' => 'staff.notify',
			'handler'        => array( __CLASS__, 'notify' ),
			'mode'           => '*business',
			'scopes'         => array( 'staff.notify' ),
			'confirm'        => 'never',
			'llm_alias'      => 'staff_notify',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool staff.assign
		BizCity_MCP_Tool_Registry::register( 'staff.assign', array(
			'title'          => 'Giao khách cho nhân viên',
			'description'    => 'Giao việc chăm sóc một khách (contact_ref) hoặc một hội thoại (conversation_id) cho một nhân viên (user_id hoặc tên). Chỉ quản lý (supervisor) hoặc quản trị viên dùng được. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'contact_ref'      => array( 'type' => 'string' ),
				'conversation_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
				'assignee_user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'assignee_name'    => array( 'type' => 'string', 'maxLength' => 120 ),
				'title'            => array( 'type' => 'string', 'maxLength' => 180 ),
				'note'             => array( 'type' => 'string', 'maxLength' => self::NOTE_MAX ),
				'due_date'         => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'confirm_token'    => array( 'type' => 'string' ),
			) ),
			'output_schema'  => $S::envelope_schema( array(
				'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
				'preview'       => array( 'type' => 'object' ),
				'confirm_token' => array( 'type' => 'string' ),
				'expires_at'    => array( 'type' => 'string' ),
				'task_ids'      => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'assignee'      => array( 'type' => 'object' ),
			) ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'staff.write',
			'handler'        => array( __CLASS__, 'assign' ),
			'preview'        => array( __CLASS__, 'assign_preview' ),
			'mode'           => 'customers',
			'scopes'         => array( 'staff.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'staff_assign',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );
	}

	/* ── crm.customer.lookup ─────────────────────────────────────── */

	public static function lookup( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$scope = BizCity_MCP_Action_Support::customers_scope( $uid );
			if ( '' === $scope ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa được xem khách hàng qua Agent.', 403 );
			}
			$limit = max( 1, min( self::LOOKUP_MAX, (int) ( $args['limit'] ?? 10 ) ) );
			$ref   = BizCity_MCP_Action_Support::contact_id( $args['contact_ref'] ?? '' );
			if ( $ref > 0 ) {
				$c = BizCity_MCP_Action_Support::contact_access( $ref, false, $uid );
				if ( is_wp_error( $c ) ) {
					return $c;
				}
				return array( 'scope' => $scope, 'total' => 1, 'customers' => array( self::present( $c, $uid ) ) );
			}
			$query = function_exists( 'mb_substr' ) ? mb_substr( trim( (string) ( $args['query'] ?? '' ) ), 0, 120 ) : substr( trim( (string) ( $args['query'] ?? '' ) ), 0, 120 );
			$out   = array();
			foreach ( BizCity_MCP_Action_Support::candidate_ids( $query, BizCity_MCP_Action_Support::inbox_ids( $uid ) ) as $id ) {
				$c = BizCity_MCP_Action_Support::contact( $id );
				if ( null === $c || ( 'person' === $scope && (int) $c['owner_id'] !== (int) $uid ) ) {
					continue; // D-TAA-7: lead/agent only see contacts assigned to them
				}
				$out[] = self::present( $c, $uid );
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
			return array( 'scope' => $scope, 'total' => count( $out ), 'customers' => $out );
		} );
	}

	private static function present( array $c, $uid ) {
		$labels = class_exists( 'BizCity_CRM_Customer_Pipeline' ) ? BizCity_CRM_Customer_Pipeline::LABELS : array();
		return array(
			'contact_ref'     => 'crm:' . (int) $c['contact_id'],
			'name'            => (string) $c['name'],
			'phone_masked'    => BizCity_MCP_Action_Support::mask_phone( $c['phone'] ),
			'crm_stage'       => (string) ( $labels[ $c['stage'] ] ?? $c['stage'] ),
			'tags'            => $c['tags'],
			'orders'          => (int) $c['orders'],
			'last_order_at'   => BizCity_MCP_Action_Support::iso( $c['last_order_ts'] ),
			'last_contact_at' => BizCity_MCP_Action_Support::iso( $c['last_activity_ts'] ),
			'assigned_to_me'  => (int) $c['owner_id'] === (int) $uid,
		);
	}

	/* ── crm.customer.update ─────────────────────────────────────── */

	public static function update( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$id = BizCity_MCP_Action_Support::contact_id( $args['contact_ref'] ?? '' );
			if ( $id <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu contact_ref (ví dụ "crm:731").', 422 );
			}
			$note     = trim( sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) );
			$stage    = sanitize_key( (string) ( $args['stage'] ?? '' ) );
			$birthday = trim( (string) ( $args['birthday'] ?? '' ) );
			$tags_add = array_values( array_unique( array_filter( array_map( static function ( $t ) { return sanitize_text_field( (string) $t ); }, (array) ( $args['tags_add'] ?? array() ) ) ) ) );
			if ( '' === $note && '' === $stage && '' === $birthday && empty( $tags_add ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Chưa có gì để cập nhật: cần note, tags_add, birthday hoặc stage.', 422 );
			}
			if ( '' !== $stage && ! in_array( $stage, self::STAGES, true ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Giai đoạn không hợp lệ. Dùng: ' . implode( ', ', self::STAGES ) . '.', 422 );
			}
			if ( '' !== $birthday && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Ngày sinh phải là ngày có thật, dạng YYYY-MM-DD.', 422 );
			}
			if ( strlen( $note ) > self::NOTE_MAX * 4 || ( function_exists( 'mb_strlen' ) && mb_strlen( $note ) > self::NOTE_MAX ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Ghi chú tối đa 2000 ký tự.', 422 );
			}
			$contact = BizCity_MCP_Action_Support::contact_access( $id, true, $uid );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}
			$changed = array();
			if ( ! empty( $tags_add ) ) {
				$merged = array_values( array_unique( array_merge( $contact['tags'], $tags_add ) ) );
				if ( $merged !== $contact['tags'] ) {
					$r = self::put_tags( $id, $merged );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				$changed[] = 'tags';
			}
			if ( '' !== $birthday ) {
				if ( ! class_exists( 'BizCity_CRM_Contact_Enrichment' ) || ! BizCity_CRM_Contact_Enrichment::set_birthday( $id, $birthday, '', array( 'source' => 'staff', 'force' => true, 'at' => gmdate( 'c' ) ) ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được ngày sinh.', 500 );
				}
				$changed[] = 'birthday';
			}
			if ( '' !== $note || '' !== $stage ) {
				if ( ! class_exists( 'BizCity_CRM_Pipeline_Stage_Service' ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Pipeline CRM chưa sẵn sàng.', 503 );
				}
				$surface = 'person' === BizCity_MCP_Action_Support::customers_scope( $uid ) ? 'c' : 'b2';
				$r = BizCity_CRM_Pipeline_Stage_Service::change( $uid, $id, array_filter( array( 'to' => $stage, 'note' => $note ), 'strlen' ), $surface );
				if ( is_wp_error( $r ) ) {
					return BizCity_MCP_Action_Support::from_business( $r );
				}
				if ( '' !== $note ) {
					$changed[] = 'note';
				}
				if ( '' !== $stage ) {
					$changed[] = 'stage';
				}
			}
			return array( 'contact_ref' => 'crm:' . $id, 'changed' => $changed );
		} );
	}

	/** Tags through the CRM's own contact route (scope re-checked there; it replaces the list, so we send the merge). */
	private static function put_tags( $id, array $tags ) {
		if ( ! class_exists( 'BizCity_CRM_REST_Controller' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'CRM chưa sẵn sàng.', 503 );
		}
		$req = new WP_REST_Request( 'PUT', '/bizcity-crm/v1/crm-contacts/' . (int) $id );
		$req->set_param( 'id', (int) $id );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'tags' => $tags ) ) );
		$res  = BizCity_CRM_REST_Controller::put_crm_contact( $req );
		if ( is_wp_error( $res ) ) {
			return BizCity_MCP_Action_Support::from_business( $res );
		}
		$body = is_object( $res ) && method_exists( $res, 'get_data' ) ? $res->get_data() : $res;
		$data = is_array( $body ) ? ( $body['data'] ?? null ) : null;
		if ( $data instanceof WP_Error ) {
			return BizCity_MCP_Action_Support::from_business( $data );
		}
		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không lưu được nhãn.', 500 );
		}
		return true;
	}

	/* ── staff.notify ────────────────────────────────────────────── */

	public static function notify( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( BizCity_MCP_Action_Support::crm_rank( $uid ) < 1 && ! BizCity_MCP_Action_Support::is_admin( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ nhân sự CRM của cửa hàng mới nhắn được nhân viên.', 403 );
			}
			$message = trim( sanitize_textarea_field( (string) ( $args['message'] ?? '' ) ) );
			if ( '' === $message || ( function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message ) ) > self::NOTIFY_MAX ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Tin nhắn phải có nội dung và tối đa 500 ký tự.', 422 );
			}
			$to = BizCity_MCP_Action_Support::staff( $args['user_id'] ?? 0, $args['name'] ?? '' );
			if ( is_wp_error( $to ) ) {
				return $to;
			}
			$recipient = array( 'user_id' => $to['user_id'], 'name' => $to['name'] );
			$key = 'bzc_mcp_notify_' . md5( get_current_blog_id() . '|' . $uid . '|' . $to['user_id'] . '|' . $message );
			if ( false !== get_transient( $key ) ) {
				return array( 'sent' => true, 'duplicate' => true, 'recipient' => $recipient );
			}
			$target  = class_exists( 'BizCity_Channel_User_Linker' ) && method_exists( 'BizCity_Channel_User_Linker', 'zalo_bot_target_for_user' ) ? (array) BizCity_Channel_User_Linker::zalo_bot_target_for_user( (int) $to['user_id'] ) : array();
			$chat_id = (string) ( $target['chat_id'] ?? '' );
			if ( '' === $chat_id || ! function_exists( 'bizcity_channel_send' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::STAFF_UNREACHABLE, $to['name'] . ' chưa liên kết Zalo Bot nên chưa nhận được tin.', 409 );
			}
			$from = get_userdata( $uid );
			$text = '[' . ( $from ? sanitize_text_field( (string) $from->display_name ) : 'Agent' ) . ' nhắn qua Agent] ' . $message;
			$res  = bizcity_channel_send( $chat_id, $text, 'text', array( 'source' => 'mcp.staff_notify', 'idempotency_key' => $key ) );
			if ( empty( $res['sent'] ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::STAFF_UNREACHABLE, 'Chưa gửi được tin tới ' . $to['name'] . '. Thử lại sau.', 502 );
			}
			set_transient( $key, time(), self::NOTIFY_TTL );
			return array( 'sent' => true, 'duplicate' => false, 'recipient' => $recipient );
		} );
	}

	/* ── staff.assign (confirm = always) ─────────────────────────── */

	public static function assign_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::assign_plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$what = '' !== $plan['contact_name'] ? $plan['contact_name'] : 'hội thoại #' . $plan['conversation_id'];
			return array(
				'summary'  => 'Giao ' . $what . ' cho ' . $plan['assignee']['name'] . ': ' . $plan['title'] . ( $plan['due_date'] ? ' (hạn ' . $plan['due_date'] . ')' : '' ) . '.',
				'assignee' => $plan['assignee'],
				'subject'  => $plan['contact_id'] > 0 ? array( 'contact_ref' => 'crm:' . $plan['contact_id'], 'name' => $plan['contact_name'] ) : array( 'conversation_id' => $plan['conversation_id'] ),
				'title'    => $plan['title'],
				'due_date' => $plan['due_date'],
			);
		} );
	}

	public static function assign( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::assign_plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$payload = array(
				'assignee_user_id' => $plan['assignee']['user_id'],
				'title'            => $plan['title'],
				'instructions'     => $plan['note'],
				'due_date'         => $plan['due_date'],
				'priority'         => 'medium',
			);
			if ( $plan['contact_id'] > 0 ) {
				$payload['contact_ids'] = array( $plan['contact_id'] );
			} else {
				$payload['conversation_id'] = $plan['conversation_id'];
			}
			$res = BizCity_CRM_Task_Handoff::create( $uid, $payload );
			if ( is_wp_error( $res ) ) {
				return BizCity_MCP_Action_Support::from_business( $res );
			}
			return array(
				'task_ids'    => array_values( array_map( 'intval', (array) ( $res['created'] ?? array() ) ) ),
				'assignee'    => $plan['assignee'],
				'contact_ref' => $plan['contact_id'] > 0 ? 'crm:' . $plan['contact_id'] : null,
			);
		} );
	}

	/** Shared by preview and commit: role gate (supervisor/admin), subject in scope, assignee is staff of this site. */
	private static function assign_plan( array $args, $uid ) {
		if ( ! in_array( BizCity_MCP_Action_Support::crm_role( $uid ), array( 'admin', 'supervisor' ), true ) && ! BizCity_MCP_Action_Support::is_admin( $uid ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ quản lý (supervisor) hoặc quản trị viên mới giao khách cho nhân viên.', 403 );
		}
		if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Giao việc CRM chưa sẵn sàng.', 503 );
		}
		$contact_id = BizCity_MCP_Action_Support::contact_id( $args['contact_ref'] ?? '' );
		$conv_id    = max( 0, (int) ( $args['conversation_id'] ?? 0 ) );
		$name       = '';
		if ( $contact_id > 0 ) {
			$c = BizCity_MCP_Action_Support::contact_access( $contact_id, true, $uid );
			if ( is_wp_error( $c ) ) {
				return $c;
			}
			$name = (string) $c['name'];
		} elseif ( $conv_id <= 0 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần contact_ref hoặc conversation_id để giao.', 422 );
		}
		$assignee = BizCity_MCP_Action_Support::staff( $args['assignee_user_id'] ?? 0, $args['assignee_name'] ?? '' );
		if ( is_wp_error( $assignee ) ) {
			return $assignee;
		}
		$due = trim( (string) ( $args['due_date'] ?? '' ) );
		if ( '' !== $due && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Hạn phải dạng YYYY-MM-DD.', 422 );
		}
		$title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
		return array(
			'assignee'        => array( 'user_id' => $assignee['user_id'], 'name' => $assignee['name'] ),
			'contact_id'      => $contact_id,
			'contact_name'    => $name,
			'conversation_id' => $contact_id > 0 ? 0 : $conv_id,
			'title'           => '' !== $title ? $title : 'Chăm sóc khách',
			'note'            => trim( sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) ),
			'due_date'        => '' !== $due ? $due : null,
		);
	}
}

BizCity_CRM_Action_MCP_Service::init();
