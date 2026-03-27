<?php if (!defined('ABSPATH')) exit;



/** ===== 2) Helper: sanitize & normalize JSON ===== */
if (!function_exists('bccm_sanitize_json_text')) {
  function bccm_sanitize_json_text($txt){
    if (!is_string($txt)) $txt = '';
    $txt = wp_unslash($txt);
    // Loại code fences vô tình dán
    $txt = preg_replace('/^```[a-zA-Z0-9_-]*\s*|\s*```$/u', '', trim($txt));
    // Bỏ BOM/zero-width
    $txt = preg_replace('/^\xEF\xBB\xBF/u','',$txt);
    $txt = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u','',$txt);
    return $txt;
  }
}

/** ===== 3) Helper: decode JSON "khoan dung" ===== */
if (!function_exists('bccm_try_decode_json_relaxed')) {
  function bccm_try_decode_json_relaxed($raw){
    $raw = (string)$raw;
    $try = json_decode($raw, true);
    if (is_array($try)) return $try;
    // bắt object ngoài cùng
    if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m)) {
      $try = json_decode($m[0], true);
      if (is_array($try)) return $try;
    }
    return null;
  }
}

/** ===== 4) Trang admin: Editor ===== */

/**
 * Admin: Coachee • AI JSON (Dynamic Form per JSON shape + coach-type registry)
 * - Tự render form theo cấu trúc JSON (object/array/scalar)
 * - Tự lấy danh sách cột theo bccm_coach_type_base_registry()
 * - Nếu coach type = baby => thêm baby_json, health_json
 *
 * Lưu ý:
 * - Với mảng indexed (list) => hiển thị dạng repeater, có nút + Thêm / Xóa
 * - Với object => fieldset nhóm theo key
 * - Scalar string ngắn -> input; dài hoặc có xuống dòng -> textarea; number/bool -> input number/checkbox
 * - Có "Chế độ RAW JSON" để dán/sửa nhanh (toggle mỗi tab)
 */
if (!function_exists('bccm_admin_ai_json_editor')) {
  /**
   * Admin page: Trình sửa các JSON AI theo từng cột (ai_summary, numeric_json, ...).
   * - Tự động suy cột theo coach type từ bccm_coach_type_base_registry()
   * - Tabs hiển thị dạng "{column} – {label}"
   * - Form auto-render theo JSON hiện có (object/array/scalar) + chế độ Raw JSON
   */
  function bccm_admin_ai_json_editor() {
    if (!current_user_can('manage_options')) {
      wp_die(__('Bạn không có quyền truy cập trang này.'));
    }

    global $wpdb;
    $t = function_exists('bccm_tables') ? bccm_tables() : ['profiles' => $wpdb->prefix.'bccm_coachees'];
    $coachee_id = isset($_GET['coachee_id']) ? (int)$_GET['coachee_id'] : 0;
    if (!$coachee_id) {
      echo '<div class="notice notice-error"><p>Thiếu tham số <code>coachee_id</code>.</p></div>';
      return;
    }

    // --- Load profile
    $row = $wpdb->get_row(
      $wpdb->prepare("SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id),
      ARRAY_A
    );
    if (!$row) {
      echo '<div class="notice notice-error"><p>Không tìm thấy coachee.</p></div>';
      return;
    }

    // --- Lấy coach registry & cấu hình coach hiện tại
    $registry = function_exists('bccm_coach_type_base_registry') ? bccm_coach_type_base_registry() : [];
    $coach_conf = null;
    $coach_type_val = $row['coach_type'] ?? ''; // vd: biz_coach, baby_coach, mental_coach
    foreach ($registry as $key => $conf) {
      if (!empty($conf['coach_type']) && $conf['coach_type'] === $coach_type_val) {
        $coach_conf = $conf;
        break;
      }
    }
    // fallback: lấy cấu hình đầu tiên nếu không match
    if (!$coach_conf && !empty($registry)) {
      $coach_conf = reset($registry);
    }

    // --- Xây danh sách cột theo generators (đúng thứ tự) + bổ sung mặc định
    $cols = [];           // [['col'=>'vision_json','label'=>'Tạo Mission • Vision Map'], ...]
    $colSeen = [];

    if (!empty($coach_conf['generators']) && is_array($coach_conf['generators'])) {
      foreach ($coach_conf['generators'] as $g) {
        $col = $g['column'] ?? '';
        $lab = $g['label']  ?? '';
        if ($col && !isset($colSeen[$col]) && array_key_exists($col, $row)) {
          $cols[] = ['col' => $col, 'label' => ($lab ?: $col)];
          $colSeen[$col] = true;
        }
      }
    }

    // Các cột chung nếu có mà chưa thêm
    foreach (['ai_summary','numeric_json','answer_json'] as $c) {
      if (array_key_exists($c, $row) && !isset($colSeen[$c])) {
        $defaultLabel = [
          'ai_summary'   => 'Nhận xét tổng quan',
          'numeric_json' => 'Bản đồ Thần số học',
          'answer_json'  => 'Bảng hỏi (Answers)'
        ][$c] ?? $c;
        $cols[] = ['col' => $c, 'label' => $defaultLabel];
        $colSeen[$c] = true;
      }
    }

    // BabyCoach: thêm baby_json/health_json/iqmap_json nếu có cột
    if (($coach_conf['coach_type'] ?? '') === 'baby_coach') {
      $extras = [
        'baby_json'   => 'Bản đồ tăng trưởng (WHO/CDC)',
        'health_json' => 'Sức khoẻ (Health JSON)',
        'iqmap_json'  => 'Baby IQ Map'
      ];
      foreach ($extras as $c => $lab) {
        if (array_key_exists($c, $row) && !isset($colSeen[$c])) {
          $cols[] = ['col' => $c, 'label' => $lab];
          $colSeen[$c] = true;
        }
      }
    }

    // BizCoach: ép đúng thứ tự bạn yêu cầu (phòng khi generators thiếu/không cùng thứ tự)
    if (($coach_conf['coach_type'] ?? '') === 'biz_coach') {
      $bizOrder = [
        'numeric_json'  => 'Bản đồ Thần số học',
        'ai_summary'    => 'Nhận xét tổng quan',
        'iqmap_json'    => 'Bản đồ IQ (Leadership Map)',
        'vision_json'   => 'Mission • Vision Map',
        'swot_json'     => 'SWOT Analysis',
        'customer_json' => 'Customer Insights',
        'value_json'    => 'Bản đồ chuỗi giá trị',
        'winning_json'  => 'Winning Model (What • Why • How • Who)',
        'bizcoach_json' => '90-Day Map (legacy)',
      ];
      $reordered = [];
      $seenTmp = [];
      foreach ($bizOrder as $c => $lab) {
        // nếu cột đã trong $cols thì lấy label theo $cols, ngược lại thêm mới
        $found = null;
        foreach ($cols as $item) {
          if ($item['col'] === $c) { $found = $item; break; }
        }
        if ($found) {
          $reordered[] = $found;
        } elseif (array_key_exists($c, $row)) {
          $reordered[] = ['col' => $c, 'label' => $lab];
        }
        if (array_key_exists($c, $row)) $seenTmp[$c] = true;
      }
      // giữ các cột còn lại (nếu có) ở cuối
      foreach ($cols as $item) {
        if (!isset($seenTmp[$item['col']])) $reordered[] = $item;
      }
      if (!empty($reordered)) $cols = $reordered;
    }

    if (empty($cols)) {
      echo '<div class="notice notice-warning"><p>Không có cột JSON nào để chỉnh sửa cho coachee này.</p></div>';
      return;
    }

    $colLabelMap = [];
    foreach ($cols as $it) $colLabelMap[$it['col']] = $it['label'];

    $active = (isset($_GET['tab']) && isset($colLabelMap[$_GET['tab']]))
      ? sanitize_key($_GET['tab']) : $cols[0]['col'];

    // --- Helpers: decode JSON an toàn
    $decode_json = function($text) {
      if (is_array($text)) return $text;
      if (!is_string($text) || $text==='') return [];
      $j = json_decode($text, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($j)) return $j;
      // Thử bắt khối JSON đầu tiên nếu có text lẫn
      if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $m)) {
        $j = json_decode($m[0], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($j)) return $j;
      }
      return [];
    };

    // --- Save handler
    $saved = false; $save_err = '';
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['_bccm_ai_json_editor_nonce'])) {
      if (!wp_verify_nonce($_POST['_bccm_ai_json_editor_nonce'], 'bccm_ai_json_editor_save')) {
        $save_err = 'Xác thực không hợp lệ (nonce).';
      } else {
        // Ưu tiên Raw JSON nếu có
        $payload = null;
        if (isset($_POST['json_raw'])) {
          $raw = wp_unslash((string)$_POST['json_raw']);
          $payload = json_decode($raw, true);
          if (json_last_error() !== JSON_ERROR_NONE) {
            $save_err = 'Raw JSON không hợp lệ: '.json_last_error_msg();
          }
        } else {
          // Lấy từ form động (mảng 'data')
          $payload = isset($_POST['data']) ? $_POST['data'] : null;
          // Chuyển '' → null cho đẹp JSON (tuỳ chọn)
          $payload = bccm_admin__normalize_empty_strings($payload);
        }

        if ($save_err==='') {
          $ok = $wpdb->update(
            $t['profiles'],
            [ $active => wp_json_encode($payload, JSON_UNESCAPED_UNICODE) , 'updated_at'=> current_time('mysql') ],
            ['id' => $coachee_id],
            ['%s','%s'],
            ['%d']
          );
          if ($ok === false) {
            $save_err = 'Không lưu được vào DB.';
          } else {
            $saved = true;
            // Reload row sau khi lưu
            $row[$active] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
          }
        }
      }
    }

    // --- Lấy JSON hiện tại của tab active
    $currentJson = $decode_json($row[$active] ?? '');

    // --------- UI ---------
    echo '<div class="wrap bccm-wrap">';
    echo '<h1>Chỉnh sửa Bản đồ khai mở Mindset – Coachee #'.esc_html($coachee_id).'</h1>';
    echo '<p>'.esc_html($row['full_name'] ?? '').'</p>';
    // Nav links quay lại list
    $link_list  = admin_url('admin.php?page=bccm_coachees_list');
    $link_view  = admin_url('admin.php?page=bccm_coachees&edit='.$coachee_id);
    echo '<p><a class="button" href="'.esc_url($link_list).'">← Danh sách Coachee</a> ';
    echo '<a class="button button-primary" href="'.esc_url($link_view).'">Xem Coachee</a></p>';

    // Notices
    if ($saved) {
      echo '<div class="notice notice-success is-dismissible"><p>Đã lưu <strong>'.esc_html($active).'</strong> thành công.</p></div>';
    } elseif ($save_err!=='') {
      echo '<div class="notice notice-error"><p>'.esc_html($save_err).'</p></div>';
    }

    // Tabs
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($cols as $it) {
      $c   = $it['col'];
      $lab = $it['label'];
      $cls = ($c === $active) ? ' nav-tab nav-tab-active' : ' nav-tab';
      $url = add_query_arg(['page'=>'bccm_ai_json_editor','coachee_id'=>$coachee_id,'tab'=>$c], admin_url('admin.php'));
      echo '<a class="'.esc_attr($cls).'" href="'.esc_url($url).'">'.esc_html($lab.' - '.$c).'</a>';
    }
    echo '</h2>';

    // Body
    echo '<div class="bccm-json-editor">';
    echo '<form method="post" action="">';
    wp_nonce_field('bccm_ai_json_editor_save', '_bccm_ai_json_editor_nonce');

    // Toggle (Form / Raw)
    $mode = isset($_GET['mode']) && $_GET['mode']==='raw' ? 'raw' : 'form';
    $toggleUrl = add_query_arg(['page'=>'bccm_ai_json_editor','coachee_id'=>$coachee_id,'tab'=>$active,'mode'=> ($mode==='raw' ? 'form':'raw')], admin_url('admin.php'));
    echo '<p style="margin-top:8px;">Chế độ: ';
    if ($mode==='raw') {
      echo '<strong>Raw JSON</strong> · <a href="'.esc_url($toggleUrl).'">Chuyển sang Form</a>';
    } else {
      echo '<strong>Form</strong> · <a href="'.esc_url($toggleUrl).'">Chuyển sang Raw JSON</a>';
    }
    echo '</p>';

    // Render
    if ($mode === 'raw') {
      echo '<textarea name="json_raw" rows="24" style="width:100%;font-family:monospace;">'
         . esc_textarea( json_encode($currentJson, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) )
         . '</textarea>';
      echo '<p><button class="button button-primary" type="submit">Lưu JSON</button></p>';
    } else {
      // Form động
      echo '<div class="bccm-json-form">';
      bccm_admin__render_json_form('data', $currentJson);
      echo '</div>';
      echo '<p><button class="button button-primary" type="submit">Lưu</button></p>';
    }

    echo '</form>';
    echo '</div>'; // .bccm-json-editor

    // CSS nhỏ
    ?>
    <style>
      .bccm-json-form .field-group{ margin:10px 0 16px; padding:12px; border:1px solid #e2e2e2; background:#fff; }
      .bccm-json-form .field-title{ font-weight:600; margin:0 0 8px; }
      .bccm-json-form .kv{ display:flex; gap:8px; align-items:center; margin:6px 0; }
      .bccm-json-form input[type="text"],
      .bccm-json-form input[type="number"],
      .bccm-json-form textarea{ width:100%; }
      .bccm-json-form .list-item{ border:1px dashed #ddd; padding:8px; margin:8px 0; background:#fafafa; }
      .bccm-json-form .muted{ color:#777; font-size:12px; }
    </style>
    <script>
      // Thêm dòng cho list (mảng) đơn giản
      document.addEventListener('click', function(e){
        if (e.target && e.target.matches('[data-add-item]')) {
          e.preventDefault();
          var wrap = e.target.closest('[data-list-wrap]');
          if (!wrap) return;
          var proto = wrap.querySelector('[data-proto]');
          if (!proto) return;
          var idx = wrap.querySelectorAll('.list-item').length;
          var html = proto.innerHTML.replace(/__INDEX__/g, idx);
          var holder = document.createElement('div');
          holder.className = 'list-item';
          holder.innerHTML = html;
          wrap.querySelector('[data-list-body]').appendChild(holder);
        }
        if (e.target && e.target.matches('[data-remove-item]')) {
          e.preventDefault();
          var li = e.target.closest('.list-item');
          if (li) li.remove();
        }
      });
    </script>
    <?php
    echo '</div>'; // .wrap
  }

  /* =========================
   * Helpers: render & normalize
   * ========================= */

  /**
   * Chuẩn hoá các chuỗi rỗng '' thành null trong cây dữ liệu (tùy chọn cải thiện JSON).
   */
  function bccm_admin__normalize_empty_strings($v) {
    if (is_array($v)) {
      $o = [];
      foreach ($v as $k=>$val) $o[$k] = bccm_admin__normalize_empty_strings($val);
      return $o;
    }
    if ($v === '') return null;
    return $v;
  }

  /**
   * Render form đệ quy từ JSON.
   * - $name: prefix (vd "data")
   * - $value: mixed
   */
  function bccm_admin__render_json_form($name, $value, $title = '') {
    // object (assoc array)
    if (is_array($value) && array_values($value) !== $value) {
      echo '<div class="field-group">';
      if ($title !== '') echo '<div class="field-title">'.esc_html($title).'</div>';
      foreach ($value as $k => $v) {
        $child = $name.'['.esc_attr($k).']';
        $label = is_string($k) ? $k : ('#'.$k);
        // Hiển thị key
        echo '<div>';
        echo '<label><strong>'.esc_html($label).'</strong></label>';
        bccm_admin__render_json_form($child, $v);
        echo '</div>';
      }
      echo '</div>';
      return;
    }

    // list (indexed array)
    if (is_array($value)) {
      echo '<div class="field-group" data-list-wrap>';
      if ($title !== '') echo '<div class="field-title">'.esc_html($title).'</div>';
      echo '<div class="muted">Danh sách ('.count($value).' mục)</div>';
      echo '<div data-list-body>';
      $idx = 0;
      foreach ($value as $v) {
        echo '<div class="list-item">';
        echo '<div class="kv">';
        // Hỗ trợ scalar/object/array trong item
        bccm_admin__render_json_form($name.'['.$idx.']', $v);
        echo '<button class="button-link-delete" data-remove-item>&times; Xoá</button>';
        echo '</div></div>';
        $idx++;
      }
      echo '</div>';
      // proto ẩn cho item mới
      echo '<template data-proto><div class="kv">'
         . bccm_admin__render_json_form__proto($name.'[__INDEX__]')
         . '<button class="button-link-delete" data-remove-item>&times; Xoá</button>'
         . '</div></template>';
      echo '<p><a href="#" class="button" data-add-item>+ Thêm mục</a></p>';
      echo '</div>';
      return;
    }

    // scalar
    $is_bool = is_bool($value);
    $is_num  = is_numeric($value);
    $str     = !is_null($value) ? (string)$value : '';

    if ($is_bool) {
      $checked = $value ? 'checked' : '';
      echo '<div class="kv"><label class="muted">Boolean</label> ';
      echo '<input type="hidden" name="'.esc_attr($name).'" value="0">';
      echo '<label><input type="checkbox" name="'.esc_attr($name).'" value="1" '.$checked.'> true/false</label>';
      echo '</div>';
      return;
    }

    // chọn textarea nếu có xuống dòng hoặc dài
    $is_long = (is_string($value) && (strpos($value, "\n") !== false || mb_strlen($value) > 120));

    if ($is_num) {
      echo '<div class="kv"><input type="number" step="any" name="'.esc_attr($name).'" value="'.esc_attr($str).'"></div>';
    } else {
      if ($is_long) {
        echo '<div class="kv"><textarea rows="4" name="'.esc_attr($name).'">'.esc_textarea($str).'</textarea></div>';
      } else {
        echo '<div class="kv"><input type="text" name="'.esc_attr($name).'" value="'.esc_attr($str).'"></div>';
      }
    }
  }

  /**
   * Phiên bản "proto" cho item mới (list), để chèn bằng JS.
   */
  function bccm_admin__render_json_form__proto($name) {
    ob_start();
    echo '<input type="text" name="'.esc_attr($name).'" value="">';
    return ob_get_clean();
  }
}