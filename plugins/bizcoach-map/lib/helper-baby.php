<?php if (!defined('ABSPATH')) exit;

if (!function_exists('bccm_generate_baby_growth_map')) {
  function bccm_generate_baby_growth_map($coachee_id){
    global $wpdb; 
    $t   = bccm_tables();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", (int)$coachee_id), ARRAY_A);
    if (!$row) {
      if (function_exists('is_wp_error')) return new WP_Error('not_found','Không tìm thấy coachee');
      return false;
    }

    $gender = $row['baby_gender'] ?? '';
    $dob    = $row['dob'] ?? null;
    $weeks  = isset($row['baby_gestational_weeks']) ? (int)$row['baby_gestational_weeks'] : null;
    $h      = isset($row['baby_height_cm']) ? $row['baby_height_cm'] : null;
    $w      = isset($row['baby_weight_kg']) ? $row['baby_weight_kg'] : null;

    // Tính toán theo WHO
    if (!function_exists('bccm_baby_calc')) {
      if (function_exists('is_wp_error')) return new WP_Error('helper_missing','Thiếu helper_baby.php (bccm_baby_calc)');
      return false;
    }
    $calc = bccm_baby_calc($gender, $dob, $weeks, $h, $w);

    // Gói dữ liệu lưu
    $payload = [
      'age'     => $calc['age'],
      'gender'  => $calc['gender'],
      'inputs'  => [
        'name'   => $row['baby_name'] ?? null,
        'weeks'  => $weeks,
        'height' => is_numeric($h) ? (float)$h : null,
        'weight' => is_numeric($w) ? (float)$w : null,
        'dob'    => $dob,
      ],
      'std'       => $calc['std'],
      'delta_pct' => $calc['delta_pct'],
      'band'      => $calc['band'],
      'charts'    => $calc['charts'],
      'ts'        => current_time('mysql'),
      'disclaimer'=> $calc['disclaimer'],
    ];

    $ok = $wpdb->update($t['profiles'], [
      'baby_json'  => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
      'updated_at' => current_time('mysql'),
    ], ['id' => (int)$coachee_id]);

    if ($ok === false) {
      if (function_exists('is_wp_error')) return new WP_Error('db_error','Không lưu được baby_json');
      return false;
    }
    return true;
  }
}
if (!function_exists('bccm_baby_age_in_months')) {
  function bccm_baby_age_in_months(?string $dob): ?int {
    if (empty($dob)) return null;
    try {
      $dob = new DateTime($dob);
      $now = new DateTime('now', wp_timezone());
      $diff = $dob->diff($now);
      return $diff->y * 12 + $diff->m + ($diff->d >= 15 ? 1 : 0);
    } catch (\Exception $e) { return null; }
  }
}

if (!function_exists('bccm_load_growth_table')) {
  /**
   * Đọc file JSON biểu đồ tăng trưởng. Trả về mảng hoặc null nếu không tồn tại.
   * Kỳ vọng 4 file:
   * - /plugins/bizcoach-map/data/boy_height.json
   * - /plugins/bizcoach-map/data/boy_weight.json
   * - /plugins/bizcoach-map/data/girl_height.json
   * - /plugins/bizcoach-map/data/girl_weight.json
   */
  function bccm_load_growth_table(string $gender, string $type): ?array {
    // $type: 'height' | 'weight'
    $sex = ($gender === 'female') ? 'girl' : 'boy';
    $file = WP_PLUGIN_DIR . "/bizcoach-map/data/{$sex}_{$type}.json";
    if (!file_exists($file)) return null;
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return (json_last_error()===JSON_ERROR_NONE && is_array($j)) ? $j : null;
  }
}

if (!function_exists('bccm_percentile_from_table')) {
  /**
   * Ước tính percentile theo WHO/CDC bằng cách:
   * - Tìm record gần nhất theo tháng (exact -> gần nhất).
   * - Record có thể ở dạng:
   *   + {"month": 12, "P3":70.1, "P15":72.0, "P50":76.0, "P85":80.1, "P97":82.5}
   *   + hoặc {"m":12, "p3":..., "p50":..., ...}
   * - So sánh số đo với các ngưỡng để suy ra dải percentile (P3/P15/P50/P85/P97).
   * Trả về: "P<3", "P3–P15", "P15–P50", "P50–P85", "P85–P97", ">P97" hoặc null nếu không tính được.
   */
  function bccm_percentile_from_table(?array $table, int $months, float $value): ?string {
    if (!$table || $months < 0) return null;

    // Chuẩn hoá keys percentile
    $kMonth = null;
    $pkeys = null;

    // Tìm record gần nhất theo tháng
    $closest = null; $closestDiff = 9999;
    foreach ($table as $row) {
      if (!is_array($row)) continue;

      // Month key detection
      if ($kMonth === null) {
        foreach (['month','m','age_months','age'] as $km) {
          if (isset($row[$km]) && is_numeric($row[$km])) { $kMonth = $km; break; }
        }
      }
      if ($kMonth===null || !isset($row[$kMonth])) continue;

      $rm = (int)$row[$kMonth];
      $df = abs($rm - $months);
      if ($df < $closestDiff) { $closest = $row; $closestDiff = $df; }
    }
    if (!$closest) return null;

    // Tìm các key percentile trong record
    $map = [];
    foreach ($closest as $k => $v) {
      $kl = strtolower((string)$k);
      if (preg_match('/^p(\d{1,2})$/', $kl, $m)) {
        $map[(int)$m[1]] = (float)$v; // p3 -> 3
      } elseif (in_array($kl, ['p3','p15','p50','p85','p97'], true)) {
        $map[(int)filter_var($kl, FILTER_SANITIZE_NUMBER_INT)] = (float)$v;
      }
    }
    // Đảm bảo có một số mốc để so sánh
    $cut = [];
    foreach ([3,15,50,85,97] as $p) { if (isset($map[$p])) $cut[$p] = (float)$map[$p]; }
    if (count($cut) < 3) return null;

    // So sánh value vào dải
    ksort($cut); // theo %
    // làm mốc: p3 < p15 < p50 < p85 < p97
    $p3  = $cut[3]  ?? null;
    $p15 = $cut[15] ?? null;
    $p50 = $cut[50] ?? null;
    $p85 = $cut[85] ?? null;
    $p97 = $cut[97] ?? null;

    if ($p3!==null && $value < $p3)  return 'P<3';
    if ($p15!==null && $value < $p15) return 'P3–P15';
    if ($p50!==null && $value < $p50) return 'P15–P50';
    if ($p85!==null && $value < $p85) return 'P50–P85';
    if ($p97!==null && $value < $p97) return 'P85–P97';
    if ($p97!==null && $value >= $p97) return '>P97';
    return null;
  }
}
