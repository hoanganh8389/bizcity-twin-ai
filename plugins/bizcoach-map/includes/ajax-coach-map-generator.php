<?php
/**
 * AJAX Handler: Coach Map Generator
 *
 * Endpoints:
 *   bccm_get_generators        - Tra ve danh sach generators cho coach type
 *   bccm_run_single_generator  - Chay 1 generator, luu status vao transient
 *   bccm_get_gen_statuses      - Tra ve status da luu cho coachee
 *   bccm_clear_gen_statuses    - Xoa status (bat dau lai tu dau)
 *
 * Status transient key: bccm_gstat_{coachee_id}
 * Shape: { gen_key => { status:'success|error|pending', label, error, updated_at } }
 *
 * @package BizCoach_Map
 */
if (!defined('ABSPATH')) exit;

/**
 * Resolve coachee_id from user_id or coachee_id param.
 * Ưu tiên user_id (nhất quán), fallback coachee_id (backward-compatible).
 */
function _bccm_ajax_resolve_coachee_id() {
  $user_id    = (int)($_POST['user_id'] ?? 0);
  $coachee_id = (int)($_POST['coachee_id'] ?? 0);

  if ($user_id > 0) {
    global $wpdb;
    $t = bccm_tables();
    $coachee_id = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT id FROM {$t['profiles']} WHERE user_id=%d AND platform_type='ADMINCHAT' ORDER BY id DESC LIMIT 1",
      $user_id
    ));
  }
  return $coachee_id;
}

/* =====================================================================
 * AJAX: Lay danh sach generators
 * ===================================================================== */
add_action('wp_ajax_bccm_get_generators', function () {
  while (ob_get_level() > 0) ob_end_clean();
  ob_start();
  @ini_set('display_errors', 0);
  @error_reporting(0);

  check_ajax_referer('bccm_map_gen', 'nonce');

  $coachee_id = _bccm_ajax_resolve_coachee_id();
  $coach_type = sanitize_text_field($_POST['coach_type'] ?? '');

  if (!$coachee_id || !$coach_type) {
    ob_end_clean();
    wp_send_json_error(['message' => 'Thieu user_id/coachee_id hoac coach_type']);
  }

  $generators = bccm_generators_for_type($coach_type);
  if (empty($generators)) {
    ob_end_clean();
    wp_send_json_error(['message' => 'Khong tim thay generators cho: ' . $coach_type]);
  }

  // Kem theo status hien tai neu co
  $statuses   = get_transient('bccm_gstat_' . $coachee_id) ?: [];

  ob_end_clean();
  wp_send_json_success([
    'generators' => $generators,
    'total'      => count($generators),
    'statuses'   => $statuses,
  ]);
});

/* =====================================================================
 * AJAX: Chay 1 generator, luu status
 * ===================================================================== */
add_action('wp_ajax_bccm_run_single_generator', function () {
  while (ob_get_level() > 0) ob_end_clean();
  ob_start();
  @ini_set('display_errors', 0);
  @error_reporting(0);
  @set_time_limit(150);

  check_ajax_referer('bccm_map_gen', 'nonce');

  $coachee_id = _bccm_ajax_resolve_coachee_id();
  $fn         = sanitize_text_field($_POST['fn'] ?? '');
  $gen_key    = sanitize_text_field($_POST['gen_key'] ?? '');
  $gen_label  = sanitize_text_field($_POST['gen_label'] ?? '');

  if (!$coachee_id || !$fn) {
    ob_end_clean();
    wp_send_json_error(['message' => 'Thieu user_id/coachee_id hoac fn']);
  }

  $ok        = false;
  $error_msg = '';

  if (!function_exists($fn)) {
    $error_msg = 'Function khong ton tai: ' . $fn;
  } else {
    try {
      $result = call_user_func($fn, $coachee_id);
      if (is_wp_error($result)) {
        $error_msg = $result->get_error_message();
      } else {
        $ok = true;
      }
    } catch (Exception $e) {
      $error_msg = $e->getMessage();
    } catch (Error $e) {
      $error_msg = 'PHP Error: ' . $e->getMessage();
    }
  }

  // Luu status vao transient (giu 7 ngay)
  $status_key = 'bccm_gstat_' . $coachee_id;
  $statuses   = get_transient($status_key) ?: [];
  $statuses[$gen_key] = [
    'status'     => $ok ? 'success' : 'error',
    'label'      => $gen_label,
    'fn'         => $fn,
    'error'      => $error_msg,
    'updated_at' => current_time('mysql'),
  ];
  set_transient($status_key, $statuses, 7 * DAY_IN_SECONDS);

  ob_end_clean();
  wp_send_json_success([
    'key'     => $gen_key,
    'label'   => $gen_label,
    'fn'      => $fn,
    'success' => $ok,
    'error'   => $error_msg,
  ]);
});

/* =====================================================================
 * AJAX: Lay status da luu cho coachee
 * ===================================================================== */
add_action('wp_ajax_bccm_get_gen_statuses', function () {
  while (ob_get_level() > 0) ob_end_clean();
  @ini_set('display_errors', 0);
  @error_reporting(0);

  check_ajax_referer('bccm_map_gen', 'nonce');

  $coachee_id = _bccm_ajax_resolve_coachee_id();
  if (!$coachee_id) {
    wp_send_json_error(['message' => 'Thieu user_id/coachee_id']);
  }

  $statuses = get_transient('bccm_gstat_' . $coachee_id) ?: [];
  wp_send_json_success(['statuses' => $statuses]);
});

/* =====================================================================
 * AJAX: Xoa status (bat dau lai tu dau)
 * ===================================================================== */
add_action('wp_ajax_bccm_clear_gen_statuses', function () {
  while (ob_get_level() > 0) ob_end_clean();
  @ini_set('display_errors', 0);
  @error_reporting(0);

  check_ajax_referer('bccm_map_gen', 'nonce');

  $coachee_id = _bccm_ajax_resolve_coachee_id();
  if (!$coachee_id) {
    wp_send_json_error(['message' => 'Thieu user_id/coachee_id']);
  }

  delete_transient('bccm_gstat_' . $coachee_id);
  wp_send_json_success(['cleared' => true]);
});
