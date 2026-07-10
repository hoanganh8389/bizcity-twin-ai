<?php
/**
 * Guru KPI Dashboard — thống kê hiệu suất tất cả Guru/Character
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\Views
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @license    GPL-2.0-or-later
 *
 * [2026-06-24 Johnny Chu] GURU-KPI — New KPI submenu page for all Gurus
 *
 * Data sources:
 *  - bizcity_characters               — guru list
 *  - bizcity_character_conversations  — sessions per guru (platform, message_count)
 *  - bizcity_crm_events               — todos/tasks where metadata.inbound.character_id = X
 *  - bizcity_automation_workflows     — workflows bound to guru via trigger_config.guru_id
 *  - bizcity_automation_runs          — run history per workflow
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Bạn không có quyền xem trang này.', 'bizcity-twin-ai' ) );
}

global $wpdb;

// ─────────────────────────────────────────────
// 1. Fetch all gurus
// ─────────────────────────────────────────────
$tbl_chars = $wpdb->prefix . 'bizcity_characters';
$gurus = $wpdb->get_results(
	"SELECT id, name, avatar, status FROM {$tbl_chars} ORDER BY id ASC",
	ARRAY_A
);
if ( ! is_array( $gurus ) ) {
	$gurus = array();
}

// ─────────────────────────────────────────────
// 2. Conversations table stats (per guru)
// ─────────────────────────────────────────────
$tbl_conv = $wpdb->prefix . 'bizcity_character_conversations';
$conv_by_guru = array(); // guru_id → { total_sessions, total_messages, sessions_7d, sessions_30d }

// [2026-06-24 Johnny Chu] GURU-KPI — check table exists before querying
$tbl_conv_exists = (bool) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
		$tbl_conv
	)
);

if ( $tbl_conv_exists && ! empty( $gurus ) ) {
	$guru_ids = array_map( 'intval', array_column( $gurus, 'id' ) );
	$placeholders = implode( ',', array_fill( 0, count( $guru_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT character_id,
			        COUNT(*) AS total_sessions,
			        COALESCE(SUM(message_count), 0) AS total_messages,
			        SUM(CASE WHEN started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS sessions_7d,
			        SUM(CASE WHEN started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS sessions_30d
			 FROM {$tbl_conv}
			 WHERE character_id IN ({$placeholders})
			 GROUP BY character_id",
			...$guru_ids
		),
		ARRAY_A
	);
	foreach ( (array) $rows as $row ) {
		$conv_by_guru[ (int) $row['character_id'] ] = $row;
	}
}

// ─────────────────────────────────────────────
// 3. CRM Events (todos/tasks per guru via metadata JSON)
// ─────────────────────────────────────────────
$tbl_events = $wpdb->prefix . 'bizcity_crm_events';
$events_by_guru = array(); // guru_id → { total_intents, done, pending, fail }

$tbl_events_exists = (bool) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
		$tbl_events
	)
);

if ( $tbl_events_exists && ! empty( $gurus ) ) {
	// JSON_EXTRACT is MySQL 5.7+. character_id stored as int in JSON.
	// [2026-06-24 Johnny Chu] GURU-KPI — use JSON_EXTRACT for character_id in metadata
	$rows = $wpdb->get_results(
		"SELECT
		    CAST( JSON_UNQUOTE( JSON_EXTRACT(metadata, '$.inbound.character_id') ) AS UNSIGNED ) AS guru_id,
		    COUNT(*) AS total_intents,
		    SUM( CASE WHEN status = 'done' THEN 1 ELSE 0 END ) AS done_count,
		    SUM( CASE WHEN status IN ('queued','pending') THEN 1 ELSE 0 END ) AS pending_count,
		    SUM( CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END ) AS fail_count
		 FROM {$tbl_events}
		 WHERE JSON_EXTRACT(metadata, '$.inbound.character_id') IS NOT NULL
		   AND JSON_UNQUOTE( JSON_EXTRACT(metadata, '$.inbound.character_id') ) != '0'
		 GROUP BY guru_id
		 HAVING guru_id > 0",
		ARRAY_A
	);
	foreach ( (array) $rows as $row ) {
		$events_by_guru[ (int) $row['guru_id'] ] = $row;
	}
}

// ─────────────────────────────────────────────
// 4. Automation Workflows + Runs per guru
// ─────────────────────────────────────────────
$tbl_wf  = $wpdb->prefix . 'bizcity_automation_workflows';
$tbl_run = $wpdb->prefix . 'bizcity_automation_runs';
$auto_by_guru = array(); // guru_id → { workflow_count, run_total, run_ok, run_fail }

$tbl_wf_exists = (bool) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
		$tbl_wf
	)
);
$tbl_run_exists = (bool) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
		$tbl_run
	)
);

if ( $tbl_wf_exists && ! empty( $gurus ) ) {
	// trigger_config is JSON in bizcity_automation_workflows
	// guru_id is stored as trigger_config.guru_id (int)
	$wf_rows = $wpdb->get_results(
		"SELECT id,
		        CAST( JSON_UNQUOTE( JSON_EXTRACT(trigger_config, '$.guru_id') ) AS UNSIGNED ) AS guru_id
		 FROM {$tbl_wf}
		 WHERE JSON_EXTRACT(trigger_config, '$.guru_id') IS NOT NULL
		   AND CAST( JSON_UNQUOTE( JSON_EXTRACT(trigger_config, '$.guru_id') ) AS UNSIGNED ) > 0",
		ARRAY_A
	);

	// Build workflow_id → guru_id map + count workflows per guru
	$wf_guru_map = array(); // workflow_id → guru_id
	foreach ( (array) $wf_rows as $wf ) {
		$gid = (int) $wf['guru_id'];
		$wf_guru_map[ (int) $wf['id'] ] = $gid;
		if ( ! isset( $auto_by_guru[ $gid ] ) ) {
			$auto_by_guru[ $gid ] = array(
				'workflow_count' => 0,
				'run_total'      => 0,
				'run_ok'         => 0,
				'run_fail'       => 0,
			);
		}
		$auto_by_guru[ $gid ]['workflow_count']++;
	}

	if ( $tbl_run_exists && ! empty( $wf_guru_map ) ) {
		$wf_ids = array_keys( $wf_guru_map );
		$ph = implode( ',', array_fill( 0, count( $wf_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$run_rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT workflow_id, status, COUNT(*) AS cnt
				 FROM {$tbl_run}
				 WHERE workflow_id IN ({$ph})
				 GROUP BY workflow_id, status",
				...$wf_ids
			),
			ARRAY_A
		);
		foreach ( (array) $run_rows as $rr ) {
			$gid = (int) ( $wf_guru_map[ (int) $rr['workflow_id'] ] ?? 0 );
			if ( $gid <= 0 ) {
				continue;
			}
			if ( ! isset( $auto_by_guru[ $gid ] ) ) {
				$auto_by_guru[ $gid ] = array( 'workflow_count' => 0, 'run_total' => 0, 'run_ok' => 0, 'run_fail' => 0 );
			}
			$cnt = (int) $rr['cnt'];
			$auto_by_guru[ $gid ]['run_total'] += $cnt;
			if ( (int) $rr['status'] === 2 ) { // STATUS_OK
				$auto_by_guru[ $gid ]['run_ok'] += $cnt;
			} elseif ( (int) $rr['status'] === 3 ) { // STATUS_FAIL
				$auto_by_guru[ $gid ]['run_fail'] += $cnt;
			}
		}
	}
}

// ─────────────────────────────────────────────
// 5. Aggregate totals for header cards
// ─────────────────────────────────────────────
$total_msgs   = 0;
$total_sess   = 0;
$total_intents = 0;
$total_done   = 0;
$total_runs   = 0;
$total_run_ok = 0;
foreach ( $gurus as $g ) {
	$gid = (int) $g['id'];
	$total_msgs    += (int) ( $conv_by_guru[ $gid ]['total_messages'] ?? 0 );
	$total_sess    += (int) ( $conv_by_guru[ $gid ]['total_sessions'] ?? 0 );
	$total_intents += (int) ( $events_by_guru[ $gid ]['total_intents'] ?? 0 );
	$total_done    += (int) ( $events_by_guru[ $gid ]['done_count'] ?? 0 );
	$total_runs    += (int) ( $auto_by_guru[ $gid ]['run_total'] ?? 0 );
	$total_run_ok  += (int) ( $auto_by_guru[ $gid ]['run_ok'] ?? 0 );
}
$overall_completion = $total_intents > 0 ? round( $total_done / $total_intents * 100 ) : 0;
$run_success_rate   = $total_runs > 0 ? round( $total_run_ok / $total_runs * 100 ) : 0;
?>
<div class="wrap">
	<h1 class="wp-heading-inline" style="display:flex;align-items:center;gap:8px;">
		<span>📊</span>
		<span><?php esc_html_e( 'Guru KPI — Thống kê hiệu suất', 'bizcity-twin-ai' ); ?></span>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-characters' ) ); ?>" class="page-title-action">
		← <?php esc_html_e( 'Danh sách Guru', 'bizcity-twin-ai' ); ?>
	</a>
	<hr class="wp-header-end">

	<!-- Header KPI Cards -->
	<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
		<?php
		$cards = array(
			array( 'icon' => '💬', 'label' => 'Tổng tin nhắn',      'value' => number_format( $total_msgs ),   'color' => '#3b82f6' ),
			array( 'icon' => '🗂️', 'label' => 'Tổng phiên chat',    'value' => number_format( $total_sess ),  'color' => '#8b5cf6' ),
			array( 'icon' => '✅', 'label' => 'Intent/Todo phát hiện', 'value' => number_format( $total_intents ), 'color' => '#f59e0b' ),
			array( 'icon' => '🏁', 'label' => 'Hoàn thành',         'value' => $overall_completion . '%',     'color' => '#10b981' ),
			array( 'icon' => '⚙️', 'label' => 'Automation runs',    'value' => number_format( $total_runs ),  'color' => '#6366f1' ),
			array( 'icon' => '🎯', 'label' => 'Tỷ lệ thành công',   'value' => $run_success_rate . '%',       'color' => '#0ea5e9' ),
		);
		foreach ( $cards as $card ) :
		?>
		<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;min-width:150px;flex:1;box-shadow:0 1px 3px rgba(0,0,0,.06);">
			<div style="font-size:28px;"><?php echo $card['icon']; ?></div>
			<div style="font-size:22px;font-weight:700;color:<?php echo esc_attr( $card['color'] ); ?>;margin:4px 0;">
				<?php echo esc_html( $card['value'] ); ?>
			</div>
			<div style="font-size:12px;color:#6b7280;"><?php echo esc_html( $card['label'] ); ?></div>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- Per-Guru Table -->
	<?php if ( empty( $gurus ) ) : ?>
	<div style="text-align:center;padding:60px;color:#9ca3af;">
		<div style="font-size:40px;">🤖</div>
		<p><?php esc_html_e( 'Chưa có Guru nào được tạo.', 'bizcity-twin-ai' ); ?></p>
	</div>
	<?php else : ?>
	<div style="overflow-x:auto;">
		<table class="wp-list-table widefat fixed striped" style="min-width:900px;">
			<thead>
				<tr>
					<th style="width:40px;">#</th>
					<th style="width:44px;">Avatar</th>
					<th><?php esc_html_e( 'Tên Guru', 'bizcity-twin-ai' ); ?></th>
					<th><?php esc_html_e( 'Trạng thái', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( 'Phiên chat', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( 'Tin nhắn', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( '7 ngày', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( '30 ngày', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( 'Intent/Todo', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( 'Hoàn thành', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( 'Automation', 'bizcity-twin-ai' ); ?></th>
					<th style="text-align:center;"><?php esc_html_e( 'Runs OK/Fail', 'bizcity-twin-ai' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Chi tiết', 'bizcity-twin-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $gurus as $idx => $guru ) :
					$gid = (int) $guru['id'];
					$c   = isset( $conv_by_guru[ $gid ] )   ? $conv_by_guru[ $gid ]   : array();
					$e   = isset( $events_by_guru[ $gid ] ) ? $events_by_guru[ $gid ] : array();
					$a   = isset( $auto_by_guru[ $gid ] )   ? $auto_by_guru[ $gid ]   : array();

					$sessions     = (int) ( $c['total_sessions'] ?? 0 );
					$messages     = (int) ( $c['total_messages'] ?? 0 );
					$sess_7d      = (int) ( $c['sessions_7d']    ?? 0 );
					$sess_30d     = (int) ( $c['sessions_30d']   ?? 0 );
					$intents      = (int) ( $e['total_intents']  ?? 0 );
					$done_cnt     = (int) ( $e['done_count']     ?? 0 );
					$pct          = $intents > 0 ? round( $done_cnt / $intents * 100 ) : 0;
					$wf_cnt       = (int) ( $a['workflow_count'] ?? 0 );
					$run_total    = (int) ( $a['run_total']      ?? 0 );
					$run_ok       = (int) ( $a['run_ok']         ?? 0 );
					$run_fail     = (int) ( $a['run_fail']       ?? 0 );

					$status_label = 'active' === $guru['status'] ? '<span style="color:#10b981;font-weight:600;">✓ active</span>' : '<span style="color:#9ca3af;">' . esc_html( $guru['status'] ) . '</span>';

					// Color-coded completion
					if ( $pct >= 70 ) {
						$pct_color = '#10b981';
					} elseif ( $pct >= 40 ) {
						$pct_color = '#f59e0b';
					} else {
						$pct_color = '#ef4444';
					}
				?>
				<tr>
					<td><?php echo esc_html( $idx + 1 ); ?></td>
					<td>
						<?php if ( ! empty( $guru['avatar'] ) ) : ?>
						<img src="<?php echo esc_url( $guru['avatar'] ); ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
						<?php else : ?>
						<span style="font-size:26px;">🤖</span>
						<?php endif; ?>
					</td>
					<td><strong><?php echo esc_html( $guru['name'] ); ?></strong> <span style="color:#9ca3af;font-size:11px;">#<?php echo $gid; ?></span></td>
					<td><?php echo $status_label; ?></td>
					<td style="text-align:center;"><?php echo esc_html( number_format( $sessions ) ); ?></td>
					<td style="text-align:center;font-weight:600;"><?php echo esc_html( number_format( $messages ) ); ?></td>
					<td style="text-align:center;"><?php echo esc_html( number_format( $sess_7d ) ); ?></td>
					<td style="text-align:center;"><?php echo esc_html( number_format( $sess_30d ) ); ?></td>
					<td style="text-align:center;"><?php echo esc_html( number_format( $intents ) ); ?></td>
					<td style="text-align:center;">
						<?php if ( $intents > 0 ) : ?>
						<span style="display:inline-block;background:<?php echo esc_attr( $pct_color ); ?>;color:#fff;border-radius:12px;padding:2px 9px;font-size:12px;font-weight:600;">
							<?php echo esc_html( $done_cnt . '/' . $intents . ' (' . $pct . '%)' ); ?>
						</span>
						<?php else : ?>
						<span style="color:#d1d5db;">—</span>
						<?php endif; ?>
					</td>
					<td style="text-align:center;">
						<?php if ( $wf_cnt > 0 ) : ?>
						<span style="color:#6366f1;font-weight:600;"><?php echo esc_html( $wf_cnt ); ?> workflow</span>
						<?php else : ?>
						<span style="color:#d1d5db;">—</span>
						<?php endif; ?>
					</td>
					<td style="text-align:center;">
						<?php if ( $run_total > 0 ) : ?>
						<span style="color:#10b981;">✓<?php echo esc_html( $run_ok ); ?></span>
						<span style="color:#9ca3af;margin:0 2px;">/</span>
						<span style="color:#ef4444;">✗<?php echo esc_html( $run_fail ); ?></span>
						<?php else : ?>
						<span style="color:#d1d5db;">—</span>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . $gid . '&tab=automations' ) ); ?>" class="button button-small">
							<?php esc_html_e( 'Chi tiết', 'bizcity-twin-ai' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>

	<!-- Data notes -->
	<p style="margin-top:20px;color:#9ca3af;font-size:12px;">
		<?php
		esc_html_e( 'Tin nhắn: từ bảng bizcity_character_conversations. Intent/Todo: từ bizcity_crm_events (metadata.inbound.character_id). Automation: từ bizcity_automation_workflows + runs.', 'bizcity-twin-ai' );
		?>
	</p>
</div>
