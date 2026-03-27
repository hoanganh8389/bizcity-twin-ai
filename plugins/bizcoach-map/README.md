# BizCoach Map — Plugin README & Roadmap

## Mô tả

BizCoach Map là plugin WordPress multisite cho phép người dùng xây dựng hồ sơ coaching cá nhân theo 4 bước:

1. **Bước 1** — Hồ sơ cá nhân & Chiêm tinh (Western + Vedic)
2. **Bước 2** — Chọn Coach Template & trả lời 20 câu hỏi
3. **Bước 3** — Tạo Trợ lý AI cá nhân (liên kết với bizcity-knowledge)
4. **Bước 4** — Generate Success Plan & Bản đồ cuộc sống

---

## Kiến trúc DB chính

### Bảng `wp_{id}_bccm_profiles`

| Cột             | Kiểu         | Ghi chú                                  |
|-----------------|--------------|------------------------------------------|
| `id`            | INT PK       | coachee_id                               |
| `user_id`       | INT          | WP user_id — **khoá chính tra cứu**      |
| `platform_type` | VARCHAR(32)  | `ADMINCHAT`, `WEBCHAT`, `TELEGRAM`, v.v. |
| `coach_type`    | VARCHAR(32)  | `mental_coach`, `biz_coach`, v.v.        |
| `full_name`     | VARCHAR(255) |                                          |
| `dob`           | DATE         |                                          |
| `answer_json`   | LONGTEXT     | mảng 20 câu trả lời (JSON)               |
| `mental_json`   | LONGTEXT     | Life Map (generated)                     |
| `bizcoach_json` | LONGTEXT     | Milestone Map (generated)                |

### Bảng `wp_{id}_bccm_astro`

| Cột          | Kiểu        | Ghi chú                                             |
|--------------|-------------|-----------------------------------------------------|
| `id`         | INT PK      |                                                     |
| `user_id`    | INT         | **Khoá chính tra cứu** — chia sẻ across platforms   |
| `coachee_id` | INT         | FK tới bccm_profiles (dùng kèm, không phải primary) |
| `chart_type` | VARCHAR(32) | `western` hoặc `vedic`                              |
| `birth_place`| VARCHAR(255)|                                                     |
| `birth_time` | VARCHAR(10) | HH:MM                                               |
| `latitude`   | FLOAT       |                                                     |
| `longitude`  | FLOAT       |                                                     |
| `timezone`   | FLOAT       | e.g. 7.0 (UTC+7)                                    |
| `summary`    | LONGTEXT    | JSON từ API chiêm tinh                              |
| `traits`     | LONGTEXT    | JSON traits chiêm tinh                              |
| `llm_report` | LONGTEXT    | JSON sections AI report (>= 10 sections = full)     |

---

## Quy tắc tra cứu astro data (QUAN TRỌNG)

> **Luôn dùng `user_id` làm khoá chính tra cứu bảng `bccm_astro`.**  
> `coachee_id` chỉ được ghi khi INSERT để giữ tham chiếu, không dùng làm khoá WHERE.

```php
// ✅ ĐÚNG — tra cứu theo user_id
$astro_row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1",
    $user_id
), ARRAY_A);
// Fallback vedic nếu chưa có western
if (!$astro_row) {
    $astro_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='vedic' ORDER BY id DESC LIMIT 1",
        $user_id
    ), ARRAY_A);
}

// ❌ SAI — KHÔNG dùng coachee_id WHERE
$astro_row = $wpdb->get_row("SELECT * FROM $t WHERE coachee_id=%d", $coachee_id);
```

**Lý do:** Cùng một user có thể có hồ sơ trên nhiều platform (`ADMINCHAT`, `WEBCHAT`, `TELEGRAM`) với các `coachee_id` khác nhau. `user_id` là định danh duy nhất xuyên suốt các platform.

### Khi gọi `bccm_astro_save_chart()` / `bccm_vedic_save_chart()`

Luôn truyền `$user_id` làm tham số thứ 4:

```php
bccm_astro_save_chart($coachee_id, $chart_result, $birth_input, $user_id);
bccm_vedic_save_chart($coachee_id, $vedic_result, $birth_input, $user_id);
```

---

## Workflow 4 bước

```
[Bước 1 - admin-self-profile.php / frontend-profile.php]
  User nhập: full_name, dob, birth_place, birth_time, lat/lng/tz
  → Lưu bccm_profiles (user_id, coachee_id)
  → Lưu bccm_astro (user_id PRIMARY, coachee_id secondary)
  → Gọi API Western + Vedic Astrology → lưu summary, traits

[Bước 2 - admin-step2-coach-template.php]
  User chọn coach_type (mental / biz / baby / astro / tarot / tiktok)
  → Trả lời 20 câu hỏi → lưu answer_json
  → Có thể chạy Generators từ bước này

[Bước 3 - admin-step3-character.php]
  → Tạo / cập nhật Character trong bizcity-knowledge plugin
  → System prompt tự động tạo từ dữ liệu chiêm tinh + coach_type
  → Lưu character_id vào user_meta('bccm_linked_character_id')

[Bước 4 - admin-step4-success-plan.php]
  → Generate Life Map (mental_json), Milestone Map (bizcoach_json)
  → Gán Character làm trợ lý đồng hành
  → Preview bản đồ cuộc đời
```

---

## File structure

```
bizcoach-map/
├── bizcoach.php                        # Plugin bootstrap
├── assets/
│   ├── css/
│   └── js/
├── data/                               # Static BMI/height/weight charts
├── includes/
│   ├── install.php                     # CREATE TABLE, migrations (add columns)
│   ├── functions.php                   # Core helpers: bccm_tables(), bccm_get_or_create_user_coachee()...
│   ├── admin-dashboard.php             # BizCoach Dashboard (tổng quan)
│   ├── admin-self-profile.php          # Bước 1 (admin): hồ sơ + chiêm tinh
│   ├── admin-step2-coach-template.php  # Bước 2 (admin): coach type + questions
│   ├── admin-step3-character.php       # Bước 3 (admin): tạo AI Character
│   ├── admin-step4-success-plan.php    # Bước 4 (admin): success plan
│   ├── frontend-profile.php            # Bước 1 (frontend/My Account)
│   ├── class-nobi-float.php            # Progress tracking widget
│   └── ...
├── lib/
│   ├── astro-api-free.php              # Western Astrology API
│   ├── astro-transit.php               # Transit report
│   ├── astro-report-llm.php            # LLM natal report generation
│   ├── astro-transit-report.php        # Transit LLM report
│   └── ...
└── templates/
```

---

## Migration checklist (khi có site cũ chưa có cột)

| Cột              | Bảng         | Migration                                              |
|------------------|--------------|--------------------------------------------------------|
| `chart_type`     | bccm_astro   | `install.php::add_column_if_missing()` + UPDATE existing rows to 'western' |
| `llm_report`     | bccm_astro   | `install.php::add_column_if_missing()`                 |
| `user_id`        | bccm_astro   | `install.php::add_column_if_missing()`                 |

**Cách trigger migration:** Vào **BizCoach Map → Dashboard** hoặc kích hoạt lại plugin → hook `admin_init` chạy `bccm_maybe_run_migrations()`.

### Query an toàn khi cột chưa tồn tại

```php
// Kiểm tra cột tồn tại trước khi dùng trong WHERE
$astro_cols = $wpdb->get_col("SHOW COLUMNS FROM $astro_tbl", 0);
$has_chart_type_col = is_array($astro_cols) && in_array('chart_type', $astro_cols, true);

if ($has_chart_type_col) {
    $astro_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $astro_tbl WHERE user_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
} else {
    $astro_row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $astro_tbl WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user_id
    ), ARRAY_A);
}
```

*(Pattern này đang được dùng trong `class-nobi-float.php`.)*

---

## Các lỗi phổ biến & cách fix

| Lỗi                                                    | Nguyên nhân                                     | Fix                                          |
|--------------------------------------------------------|-------------------------------------------------|----------------------------------------------|
| `Unknown column 'chart_type' in WHERE`                 | Site chưa chạy migration                        | Kiểm tra cột trước khi query (xem trên)      |
| `Bizname not found` khi loop `switch_to_blog()`        | Blog không có `bizname` trong `wp_blogs`        | Filter bằng `bizcity_get_blogs_with_bizname()` trước khi switch |
| Dữ liệu chiêm tinh không hiện trên WEBCHAT khi đã nhập ở ADMINCHAT | Query bằng `coachee_id` thay vì `user_id` | Luôn query `bccm_astro` bằng `user_id` |
| LLM report không hiện                                  | Cột `llm_report` chưa có hoặc `sections < 10`  | Chạy migration, regenerate report            |

---

## Changelog

### 2026-02-28
- **FIX** `class-nobi-float.php`: Thêm kiểm tra cột `chart_type` trước khi dùng trong WHERE (tránh lỗi `Unknown column`)
- **FIX** `admin-step2-coach-template.php`: Query `bccm_astro` dùng `user_id` thay `coachee_id`
- **FIX** `admin-step3-character.php`: Query `bccm_astro` dùng `user_id` thay `coachee_id`, thêm fallback vedic
- **FIX** `admin-step4-success-plan.php`: Query `bccm_astro` dùng `user_id` thay `coachee_id`, thêm fallback vedic
- **FIX** `admin-self-profile.php`: Thêm `$user_id` vào tất cả lệnh gọi `bccm_astro_save_chart()` và `bccm_vedic_save_chart()`
- **FIX** `frontend-profile.php`: Xoá hoàn toàn fallback query theo `coachee_id` (đã migrate sang `user_id`)
- **FIX** `network-menu-config.php` (bizcity-web): Thêm `bizcity_get_blogs_with_bizname()` để skip sites không có bizname trước khi `switch_to_blog()` — tránh `wp_die('Bizname not found')`
