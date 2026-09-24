<?php
/**
 * PHASE-0.71 F71-03 — content-fidelity check for templates/pipelines/_bundled_pending_split/production.json.
 *
 * Why this file exists: BizCity_CRM_Pipeline_Registry::validate() (exercised by
 * CrmPipelineRegistryTest.php) only checks SHAPE — required keys, regex-valid codes, gates
 * pointing at real steps. It happily returned `true` for a year on a production.json that had
 * 7/26 task-step rows with the wrong role or wrong label, and a role R08 that was mislabelled
 * "Kỹ thuật" when the real org chart has no such code (R08 is "Tài chính Kế toán") — see
 * PHASE-0.71 §1.3/§2.3 F71-1. Shape tests cannot catch wrong BUSINESS CONTENT. This file locks
 * the 26 verified "có phiếu việc" rows (PHASE-0.71 §1.3, transcribed verbatim from photographed
 * pages of the source "Kiến trúc hệ thống IBS ERP" document) so a future edit that silently
 * changes a role or a label goes red here instead of passing quietly forever.
 *
 * Only the 26 task-mode rows are locked — the 10 status-only rows (P4.1..P4.4, P5.1A..P5.1F) are
 * a reasoned reconstruction, not verbatim source (PHASE-0.71 §1.4), so this file does not pin
 * their exact wording — only that they exist, are status_only, and land in the right stage.
 *
 * Run: php tests/unit/CrmProductionPipelineContentTest.php
 * No WordPress required — pure JSON + array assertions against the file on disk.
 */

$pass = 0;
$fail = 0;
function check_content( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL: {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

$path = dirname( __DIR__, 2 ) . '/templates/pipelines/_bundled_pending_split/production.json';
$definition = json_decode( (string) file_get_contents( $path ), true );
check_content( 'production.json parses as JSON', is_array( $definition ) );
if ( ! is_array( $definition ) ) {
	printf( "\n%d passed, %d failed\n", $pass, $fail );
	exit( 1 );
}

/* ---- Flatten every sub_step across all stages, keyed by step code -------------- */

$steps = array();
foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) {
	foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub ) {
		if ( isset( $sub['key'] ) ) {
			$steps[ $sub['key'] ] = $sub + array( 'stage' => $stage['key'] ?? '' );
		}
	}
}

/* ---- PHASE-0.71 §1.1 — the 13-role table, verbatim from the org chart photo ----- */

$expected_roles = array(
	'R01'  => 'Ban Giám đốc',
	'R02'  => 'Quản lý dự án (PM)',
	'R03'  => 'Kinh tế Kế hoạch (KTKH)',
	'R04'  => 'Thiết kế',
	'R05'  => 'Kho',
	'R06b' => 'Sản xuất (tổ trưởng xưởng)',
	'R07'  => 'Thương mại',
	'R08'  => 'Tài chính Kế toán',
	'R09'  => 'QAQC',
	'R13'  => 'Trang thiết bị',
);
foreach ( $expected_roles as $code => $label ) {
	$role = $definition['roles'][ $code ] ?? null;
	check_content( "role {$code} is declared", null !== $role );
	if ( null !== $role ) {
		check_content( "role {$code} label is \"{$label}\"", $label === ( $role['label'] ?? '' ), (string) ( $role['label'] ?? '(missing)' ) );
	}
}
check_content(
	'role R08 is never mislabelled "Kỹ thuật" (PHASE-0.71 F71-1 — that code does not exist in the 13-role table)',
	'Kỹ thuật' !== ( $definition['roles']['R08']['label'] ?? '' )
);

/* ---- PHASE-0.71 §1.3 — the 26 verbatim task-mode rows (Mã | GĐ | Vai trò | Nội dung) -- */

$expected_task_steps = array(
	// key      stage   role    label (verbatim)
	'P1.1'  => array( 'GD1', 'R02', 'Tạo dự án' ),
	'P1.1B' => array( 'GD1', 'R01', 'BGĐ phê duyệt triển khai' ),
	'P1.2A' => array( 'GD1', 'R02', 'PM lập kế hoạch kickoff, WBS, cột mốc' ),
	'P1.2'  => array( 'GD1', 'R03', 'Xây dựng dự toán thi công' ),
	'P1.3'  => array( 'GD1', 'R01', 'Phê duyệt kế hoạch và dự toán thi công' ),
	'P2.1'  => array( 'GD2', 'R04', 'Thiết kế bản vẽ và đề xuất vật tư chính' ),
	'P2.2'  => array( 'GD2', 'R02', 'PM đề xuất vật tư hàn và sơn' ),
	'P2.3'  => array( 'GD2', 'R05', 'Kho đề xuất vật tư tiêu hao' ),
	'P2.1A' => array( 'GD2', 'R08', 'Lập kế hoạch dòng tiền' ),
	'P2.4'  => array( 'GD2', 'R03', 'KTKH điều chỉnh dự toán' ),
	'P2.5'  => array( 'GD2', 'R01', 'BGĐ phê duyệt kế hoạch SX và dự toán chính thức' ),
	'P3.1'  => array( 'GD3', 'R02', 'PM điều chỉnh kế hoạch, đẩy tiến độ cấp hàng' ),
	'P3.3'  => array( 'GD3', 'R02', 'PM lập lệnh SX và đề nghị cấp vật tư — phần thuê ngoài' ),
	'P3.4'  => array( 'GD3', 'R02', 'PM lập lệnh SX và đề nghị cấp vật tư — phần làm tại IBS' ),
	'P3.5'  => array( 'GD3', 'R07', 'Thương mại tìm nhà cung cấp' ),
	'P3.6'  => array( 'GD3', 'R01', 'BGĐ phê duyệt báo giá nhà cung cấp' ),
	'P4.5'  => array( 'GD4', 'R05', 'Kho đề nghị cấp vật tư cho PM và quản lý SX' ),
	'P5.2'  => array( 'GD5', 'R06b', 'Xưởng báo cáo khối lượng hoàn thành theo tuần' ),
	'P5.3'  => array( 'GD5', 'R09', 'QAQC nghiệm thu chất lượng và khối lượng' ),
	'P5.4'  => array( 'GD5', 'R02', 'PM nghiệm thu chất lượng và khối lượng' ),
	'P5.5'  => array( 'GD5', 'R03', 'Tổng hợp và tính lương khoán' ),
	'P6.1'  => array( 'GD6', 'R09', 'QC tổng hợp hồ sơ chất lượng' ),
	'P6.2'  => array( 'GD6', 'R08', 'Quyết toán chi phí trực tiếp' ),
	'P6.3'  => array( 'GD6', 'R03', 'Quyết toán tổng hợp (lãi lỗ)' ),
	'P6.4'  => array( 'GD6', 'R02', 'Tổ chức rút kinh nghiệm' ),
	'P6.5'  => array( 'GD6', 'R01', 'BGĐ phê duyệt đóng dự án' ),
);

check_content( 'exactly 26 verified task steps are checked', 26 === count( $expected_task_steps ) );

foreach ( $expected_task_steps as $code => list( $stage, $role, $label ) ) {
	$step = $steps[ $code ] ?? null;
	check_content( "step {$code} exists", null !== $step );
	if ( null === $step ) {
		continue;
	}
	check_content( "step {$code} is mode=task", 'task' === ( $step['mode'] ?? '' ), (string) ( $step['mode'] ?? '(missing)' ) );
	check_content( "step {$code} belongs to stage {$stage}", $stage === $step['stage'], (string) $step['stage'] );
	check_content( "step {$code} role is {$role}", $role === ( $step['role'] ?? '' ), (string) ( $step['role'] ?? '(missing)' ) );
	check_content( "step {$code} label matches source verbatim", $label === ( $step['label'] ?? '' ), (string) ( $step['label'] ?? '(missing)' ) );
}

/* ---- PHASE-0.71 §1.4 — the 10 reconstructed status_only rows: existence + shape only -- */

$expected_status_steps = array(
	'P4.1' => 'GD4', 'P4.2' => 'GD4', 'P4.3' => 'GD4', 'P4.4' => 'GD4',
	'P5.1A' => 'GD5', 'P5.1B' => 'GD5', 'P5.1C' => 'GD5', 'P5.1D' => 'GD5', 'P5.1E' => 'GD5', 'P5.1F' => 'GD5',
);
check_content( 'exactly 10 reconstructed status steps are checked', 10 === count( $expected_status_steps ) );
foreach ( $expected_status_steps as $code => $stage ) {
	$step = $steps[ $code ] ?? null;
	check_content( "status step {$code} exists", null !== $step );
	if ( null === $step ) {
		continue;
	}
	check_content( "status step {$code} is mode=status_only", 'status_only' === ( $step['mode'] ?? '' ), (string) ( $step['mode'] ?? '(missing)' ) );
	check_content( "status step {$code} belongs to stage {$stage}", $stage === $step['stage'], (string) $step['stage'] );
}

/* ---- Totals — 26 + 10 = 36, matching the source document's own count ----------- */

check_content( 'production.json declares exactly 36 sub-steps in total', 36 === count( $steps ), (string) count( $steps ) );
$task_count   = count( array_filter( $steps, static fn( $s ) => 'task' === ( $s['mode'] ?? '' ) ) );
$status_count = count( array_filter( $steps, static fn( $s ) => 'status_only' === ( $s['mode'] ?? '' ) ) );
check_content( 'exactly 26 are task-mode ("có phiếu việc")', 26 === $task_count, (string) $task_count );
check_content( 'exactly 10 are status_only ("trạng thái")', 10 === $status_count, (string) $status_count );

/* ---- PHASE-0.71 §1.3 gate evidence — P2.4/P3.6/P6.5 verbatim from the source's own example -- */

$gates = array();
foreach ( (array) ( $definition['gates'] ?? array() ) as $gate ) {
	$gates[ $gate['stage'] ?? '' ] = $gate['requires']['all_of'] ?? array();
}
check_content(
	'gate P2.4 requires all_of [P2.1,P2.2,P2.3,P2.1A] (verbatim source example, PHASE-0.71 §1.3)',
	( $gates['P2.4'] ?? array() ) === array( 'P2.1', 'P2.2', 'P2.3', 'P2.1A' ),
	implode( ',', $gates['P2.4'] ?? array() )
);
check_content( 'gate P6.5 requires all_of [P6.1,P6.2,P6.3,P6.4]', ( $gates['P6.5'] ?? array() ) === array( 'P6.1', 'P6.2', 'P6.3', 'P6.4' ) );

/* ---- Exception handler roles — must resolve to a real role, never the fabricated R08=Kỹ thuật -- */

$exceptions = array();
foreach ( (array) ( $definition['exceptions'] ?? array() ) as $exception ) {
	$exceptions[ $exception['key'] ?? '' ] = $exception['handler_role'] ?? '';
}
check_content( 'machine_down handler_role is R13 (Trang thiết bị)', 'R13' === ( $exceptions['machine_down'] ?? '' ), (string) ( $exceptions['machine_down'] ?? '(missing)' ) );
check_content( 'material_short handler_role is R05 (Kho)', 'R05' === ( $exceptions['material_short'] ?? '' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
