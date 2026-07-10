<?php
/**
 * Action: Schedule a generic event into bizcity_crm_events.
 *
 * Usage scenarios (BE-7.D pilot — keyword "lên lịch"):
 *   - Generic meeting/task/reminder reminders that don't go to a channel.
 *   - reminder_zalo (resolve OA bot id + zalo user id from ctx, send via cron).
 *
 * Output: { event_id, event_type, start_at }.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.D (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Schedule_Event extends BizCity_Automation_Block_Base {

	// [2026-06-15 Johnny Chu] R-UNIFY — thêm reminder_personal cho admin reminder qua bất kỳ kênh nào.
	const EVENT_TYPES = array( 'task', 'meeting', 'reminder', 'reminder_zalo', 'reminder_personal' );

	public function id(): string   { return 'action.schedule_event'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Lên lịch (CRM event)',
			'short'    => 'schedule_event',
			'category' => 'output',
			'color'    => '#9333ea',
			'icon'     => 'calendar-clock',
			'defaults' => array(
				'label'         => 'schedule_event',
				'event_type'    => 'task',
				'title'         => '{{trigger.text}}',
				'description'   => '',
				'start_at'      => '+1 hour',
				'reminder_min'  => 15,
				// reminder_personal specifics (optional).
				// [2026-06-15 Johnny Chu] R-UNIFY — reminder_personal inbound auto-forwarded.
				'reminder_text' => '',
				// reminder_zalo specifics (optional, only used if event_type=reminder_zalo).
				'zalo_bot_id'   => '',
				'zalo_user_id'  => '{{trigger.sender_id}}',
				'zalo_text'     => '{{trigger.text}}',
			),
			'fields' => array(
				array( 'name' => 'label',        'label' => 'Tên hiển thị',                            'type' => 'text' ),
				array( 'name' => 'event_type',   'label' => 'Loại event',                              'type' => 'select', 'options' => self::EVENT_TYPES ),
				array( 'name' => 'title',        'label' => 'Tiêu đề',                                  'type' => 'text' ),
				array( 'name' => 'description',  'label' => 'Mô tả',                                    'type' => 'textarea' ),
				array( 'name' => 'start_at',     'label' => 'Bắt đầu (ISO hoặc strtotime: +1 hour)',    'type' => 'text' ),
				array( 'name' => 'reminder_min', 'label' => 'Reminder trước (phút)',                    'type' => 'number' ),
				array( 'name' => 'reminder_text','label' => '[reminder_personal] Nội dung nhắc nhở',    'type' => 'textarea' ),
				array( 'name' => 'zalo_bot_id',  'label' => '[reminder_zalo] Bot ID',                   'type' => 'text' ),
				array( 'name' => 'zalo_user_id', 'label' => '[reminder_zalo] Zalo user ID',             'type' => 'text' ),
				array( 'name' => 'zalo_text',    'label' => '[reminder_zalo] Nội dung gửi',             'type' => 'textarea' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-15 Johnny Chu] PHASE-0 — resolve event_type qua {{}} (hỗ trợ {{extract.output.kind}}).
		$event_type = (string) $this->resolve( $data['event_type'] ?? 'task', $ctx );
		if ( ! in_array( $event_type, self::EVENT_TYPES, true ) ) {
			$event_type = 'task';
		}
		$title       = trim( (string) $this->resolve( $data['title'] ?? '', $ctx ) );
		$description = (string) $this->resolve( $data['description'] ?? '', $ctx );
		$start_raw   = (string) $this->resolve( $data['start_at'] ?? '', $ctx );

		if ( $title === '' ) {
			return new WP_Error( 'no_title', 'schedule_event: thiếu title.' );
		}

		// [2026-06-15 Johnny Chu] PHASE-0 — handle time-only string "HH:MM" hoặc "HH:MM:SS"
		// từ LLM output. strtotime("10:00") = false trong PHP → prepend today's date.
		if ( $start_raw !== '' && preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', trim( $start_raw ) ) ) {
			$start_raw = gmdate( 'Y-m-d', current_time( 'timestamp' ) ) . ' ' . $start_raw;
		}

		// strtotime tolerates ISO + relative ("+1 hour", "tomorrow 9am", "today 10:00:00").
		$ts       = $start_raw !== '' ? strtotime( $start_raw, current_time( 'timestamp' ) ) : ( current_time( 'timestamp' ) + HOUR_IN_SECONDS );
		$start_at = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );

		$metadata = array();
		// [2026-06-15 Johnny Chu] R-UNIFY — reminder_personal: reminder_text từ field, inbound forwarded qua build_event_metadata.
		if ( $event_type === 'reminder_personal' ) {
			$reminder_text = (string) $this->resolve( $data['reminder_text'] ?? '', $ctx );
			if ( $reminder_text !== '' ) {
				$metadata['reminder_text'] = $reminder_text;
			}
			// notify.enabled = true để completion notifier + handler đều biết cần fire.
			$metadata['notify'] = array( 'enabled' => true );
		}
		if ( $event_type === 'reminder_zalo' ) {
			$bot_id  = trim( (string) $this->resolve( $data['zalo_bot_id'] ?? '', $ctx ) );
			$user_id = trim( (string) $this->resolve( $data['zalo_user_id'] ?? '', $ctx ) );
			$text    = (string) $this->resolve( $data['zalo_text'] ?? '', $ctx );
			if ( $bot_id === '' || $user_id === '' || $text === '' ) {
				return new WP_Error( 'reminder_zalo_missing', 'reminder_zalo cần zalo_bot_id + zalo_user_id + zalo_text.' );
			}
			$metadata = array(
				'zalo_bot_id'          => $bot_id,
				'zalo_user_id'         => $user_id,
				'zalo_text'            => $text,
				'zalo_reminder_status' => 'pending',
			);
		}

		// [2026-06-15 Johnny Chu] PHASE-0 — forward wp_user_id từ trigger để event
		// gán đúng user (automation chạy trong cron → get_current_user_id()=0).
		$wp_user_id = (int) (
			$ctx['trigger']['wp_user_id']
			?? $ctx['wp_user_id']
			?? get_current_user_id()
		);

		$payload = array(
			'event_type'   => $event_type,
			'title'        => $title,
			'description'  => $description,
			'start_at'     => $start_at,
			'reminder_min' => max( 0, (int) ( $data['reminder_min'] ?? 15 ) ),
			'status'       => 'active',
			'source'       => 'workflow',
			'user_id'      => $wp_user_id,
			'related_id'   => $ctx['_run_id'] ?? '',
			'workflow_id'  => $ctx['_workflow_id'] ?? 0,
			// [2026-06-03 Johnny Chu] R-SCH-REPLY — forward inbound{} qua helper.
			'metadata'     => $this->build_event_metadata( $ctx, $metadata ),
		);

		$event_id = BizCity_Automation_CRM_Bridge::create_event( $payload );

		return array(
			'event_id'   => $event_id,
			'event_type' => $event_type,
			'start_at'   => $start_at,
		);
	}
}
