# 🍽️ BizCity Calo – Nhật ký Bữa ăn AI

> AI Agent theo dõi nhật ký bữa ăn, phân tích ảnh thức ăn, thống kê calo & dinh dưỡng.
> Hỗ trợ chat, chụp ảnh, biểu đồ — Mobile First.

## 📋 Tổng quan

| Thông tin | Giá trị |
|---|---|
| **Slug** | `bizcity-agent-calo` |
| **Prefix** | `BZCALO` (constants), `bzcalo_` (functions/tables) |
| **Version** | 1.3.0 |
| **Role** | Agent (Intent Engine) |
| **Template Page** | `/calo/` |
| **Shortcode** | `[bizcity_calo]` |
| **Credit** | 50 / phiên |

## 🎯 3 Nguyên tắc cốt lõi

1. **AI First** — Chụp ảnh bữa ăn → AI tự nhận diện món & tính calo (Google Gemini Vision). Hoặc mô tả bằng text → AI estimate dinh dưỡng (OpenRouter).
2. **User Profile First** — Mọi dữ liệu gắn `user_id`. Admin theo dõi danh sách users. BMR tự tính theo Mifflin-St Jeor + mức vận động + mục tiêu.
3. **Mobile First** — Bottom tab navigation, camera capture nổi bật, touch-friendly, max-width 480px.

## 🗂️ Cấu trúc Plugin

```
bizcity-agent-calo/
├── bizcity-agent-calo.php          # Bootstrap — defines, requires, rewrite, hooks, assets
├── index.php                       # Guard
│
├── includes/
│   ├── install.php                 # 4 DB tables + seed 20 thực phẩm Việt + helper functions
│   ├── class-intent-provider.php   # Intent Engine — 5 goals, 5 tools, AI Vision, CaloCoach
│   ├── ajax.php                    # 12 AJAX endpoints
│   ├── admin-menu.php              # Menu "🍽️ Calo Tracker" với 6 trang
│   ├── admin-dashboard.php         # Dashboard, ghi bữa, lịch sử, users, thực phẩm, settings
│   ├── shortcode.php               # [bizcity_calo] — SPA 5 tabs mobile-first (Dashboard, Ghi bữa, Cân nặng, Lịch sử, Hồ sơ)
│   ├── integration-chat.php        # Intent detection, secure tokens, push to Zalo/Webchat
│   ├── knowledge-binding.php       # RAG knowledge character binding
│   ├── topics.php                  # 9 topic categories (ghi bữa, dinh dưỡng, giảm cân…)
│   └── index.php
│
├── assets/
│   ├── public.css                  # Mobile CSS — ring chart, macro bars, mascot, BMI, weight chart, bottom nav
│   ├── public.js                   # Frontend jQuery — tabs, photo upload, AI, charts, weight/BMI, week strip
│   ├── admin.css                   # Admin stat cards, forms, tables
│   ├── admin.js                    # Admin meal logging, photo upload, AI analysis
│   └── index.php
│
└── views/
    ├── page-calo-full.php          # Full page template cho /calo/
    ├── calo-landing.php            # Template filter cho WP pages
    └── index.php
```

## 🗄️ Database Schema (4 tables)

### `bzcalo_profiles`
Hồ sơ dinh dưỡng user — BMR, mục tiêu calo, dị ứng.

| Column | Type | Note |
|---|---|---|
| `user_id` | BIGINT UNIQUE | FK → wp_users |
| `full_name` | VARCHAR(255) | |
| `gender` | ENUM(male,female,other) | |
| `dob` | DATE | Ngày sinh |
| `height_cm` | DECIMAL(5,1) | |
| `weight_kg` | DECIMAL(5,1) | |
| `target_weight` | DECIMAL(5,1) | Cân nặng mục tiêu |
| `activity_level` | VARCHAR(30) | sedentary / light / moderate / active / very_active |
| `goal` | VARCHAR(30) | lose / maintain / gain |
| `daily_calo_target` | INT | Tự tính = BMR × activity ± goal |
| `allergies` | TEXT | |
| `medical_notes` | TEXT | |

### `bzcalo_meals`
Mỗi bữa ăn ghi nhận.

| Column | Type | Note |
|---|---|---|
| `user_id` | BIGINT | FK |
| `meal_type` | ENUM(breakfast,lunch,dinner,snack) | |
| `meal_date` | DATE | |
| `meal_time` | TIME | |
| `description` | TEXT | Mô tả bữa ăn |
| `photo_url` | VARCHAR(500) | Ảnh chụp |
| `ai_analysis` | LONGTEXT | Raw JSON từ AI |
| `items_json` | LONGTEXT | `[{name, qty, calories, protein, carbs, fat}]` |
| `total_calories` | DECIMAL(8,1) | |
| `total_protein/carbs/fat/fiber` | DECIMAL(6,1) | |
| `source` | VARCHAR(30) | manual / ai_text / ai_photo / chat |
| `platform` | VARCHAR(30) | WEBCHAT / ZALO / ADMIN |
| `session_id` | VARCHAR(100) | Liên kết chat session |

### `bzcalo_foods`
Cơ sở dữ liệu thực phẩm (seed 20 món VN).

| Column | Type | Note |
|---|---|---|
| `slug` | VARCHAR(120) UNIQUE | pho-bo, com-tam… |
| `name_vi` / `name_en` | VARCHAR(255) | |
| `category` | VARCHAR(60) | mon_chinh / tinh_bot / protein / rau_cu / trai_cay / do_uong |
| `serving_size` | VARCHAR(60) | "1 tô (500g)" |
| `calories` | DECIMAL(8,1) | |
| `protein_g/carbs_g/fat_g/fiber_g` | DECIMAL(6,1) | |

### `bzcalo_daily_stats`
Thống kê tổng hợp hàng ngày (auto-recalc mỗi lần có meal mới).

| Column | Type | Note |
|---|---|---|
| `user_id` + `stat_date` | UNIQUE KEY | |
| `meals_count` | TINYINT | |
| `total_calories/protein/carbs/fat/fiber` | DECIMAL | Tổng ngày |
| `water_ml` | INT | Nước uống (ml) |
| `weight_kg` | DECIMAL(5,1) | Ghi nhận cân nặng ngày |

## 🤖 Intent Engine — 5 Goals

| Goal | Keywords | Mô tả |
|---|---|---|
| `calo_log_meal` | ăn, bữa, ghi nhận, log, vừa ăn, tôi ăn, ăn sáng/trưa/tối | Ghi bữa ăn bằng text |
| `calo_photo_meal` | chụp, ảnh, photo, hình, gửi ảnh, nhận diện | Phân tích ảnh bữa ăn (Gemini Vision) |
| `calo_daily_stats` | hôm nay, thống kê, today, cân nặng, BMI/BMR/TDEE, chiều cao, thể trạng, hồ sơ sức khỏe | Xem tổng kết ngày / hồ sơ cơ thể |
| `calo_report` | báo cáo, report, tuần, tháng, hành trình, lịch sử ăn, tiến trình | Báo cáo nutrition nhiều ngày |
| `calo_suggest` | gợi ý, suggest, nên ăn, giảm cân, tăng cân, ăn kiêng, dinh dưỡng, protein, macro, béo, gầy, sức khỏe | Đề xuất bữa ăn / tư vấn dinh dưỡng |

## 🔌 AJAX Endpoints (12 actions)

| Action | Auth | Mô tả |
|---|---|---|
| `bzcalo_save_profile` | ✅ | Lưu hồ sơ + tự tính BMR & target |
| `bzcalo_get_profile` | ✅ | Lấy profile user |
| `bzcalo_log_meal` | ✅ | Ghi bữa ăn (manual/AI) |
| `bzcalo_upload_photo` | ✅ | Upload ảnh bữa ăn → WP Media |
| `bzcalo_ai_analyze_photo` | ✅ | AI Vision phân tích ảnh → nutrition |
| `bzcalo_ai_analyze_text` | ✅ | AI text → nutrition estimate |
| `bzcalo_get_today` | ✅ | Stats + meals hôm nay |
| `bzcalo_get_chart` | ✅ | N-day nutrition chart data |
| `bzcalo_delete_meal` | ✅ | Xóa bữa ăn (ownership check) |
| `bzcalo_admin_users` | 🔒 | Admin: danh sách users + stats |
| `bzcalo_search_foods` | ✅ | Typeahead tìm thực phẩm |
| `bzcalo_push_result` | Public | Push AI result → chat platform |

## 📐 BMR Formula (Mifflin-St Jeor)

```
Nam:   BMR = 10 × weight(kg) + 6.25 × height(cm) − 5 × age − 161 + 166
Nữ:    BMR = 10 × weight(kg) + 6.25 × height(cm) − 5 × age − 161

TDEE = BMR × Activity Multiplier
 ├─ sedentary:    × 1.2
 ├─ light:        × 1.375
 ├─ moderate:     × 1.55
 ├─ active:       × 1.725
 └─ very_active:  × 1.9

Goal Adjustment:
 ├─ lose:     TDEE − 300 kcal
 ├─ maintain: TDEE
 └─ gain:     TDEE + 300 kcal
```

## 🍜 Seed Foods (20 món)

| # | Món | Category | Khẩu phần | Calo |
|---|---|---|---|---|
| 1 | Cơm trắng | tinh_bot | 1 chén (200g) | 260 |
| 2 | Phở bò | mon_chinh | 1 tô (500g) | 450 |
| 3 | Bánh mì thịt | mon_chinh | 1 ổ | 350 |
| 4 | Bún chả | mon_chinh | 1 phần | 500 |
| 5 | Cơm tấm sườn | mon_chinh | 1 dĩa | 600 |
| 6 | Gà nướng | protein | 1 đùi (150g) | 250 |
| 7 | Cá hồi nướng | protein | 150g | 280 |
| 8 | Trứng luộc | protein | 1 quả | 78 |
| 9 | Rau cải luộc | rau_cu | 1 đĩa (150g) | 35 |
| 10 | Salad trộn | rau_cu | 1 đĩa (200g) | 120 |
| 11 | Sữa tươi | do_uong | 200ml | 120 |
| 12 | Trà đá | do_uong | 1 ly (300ml) | 30 |
| 13 | Cà phê sữa đá | do_uong | 1 ly (250ml) | 120 |
| 14 | Chuối | trai_cay | 1 quả | 89 |
| 15 | Táo | trai_cay | 1 quả | 95 |
| 16 | Mì gói | tinh_bot | 1 gói (75g) | 350 |
| 17 | Xôi | tinh_bot | 1 gói (200g) | 340 |
| 18 | Đậu hũ | protein | 150g | 120 |
| 19 | Gỏi cuốn | mon_chinh | 2 cuốn | 200 |
| 20 | Hủ tiếu | mon_chinh | 1 tô (450g) | 400 |

## 🔗 Tích hợp

- **Intent Engine** (`bizcity-intent`) — Provider class, 5 goals, 5 tools
- **Chat** — `bizcity_unified_message_intent` (Zalo/Bot), `bizcity_chat_pre_ai_response` (Webchat)
- **Knowledge** (`bizcity-knowledge`) — Character binding, RAG context cho AI
- **AI APIs** — `bizcity_openrouter_chat()` via `BizCity_OpenRouter` (text + Vision), model: `google/gemini-2.0-flash-001`

## 📱 Frontend UI (Shortcode)

4 tab dạng SPA, bottom navigation:

| Tab | Chức năng |
|---|---|
| 📊 **Hôm nay** | Ring chart calo, macro P/C/F, danh sách bữa ăn hôm nay |
| 📸 **Ghi bữa** | Camera capture, mô tả text, meal type pills, AI analyze, lưu |
| 📜 **Lịch sử** | Bar chart 7 ngày, danh sách daily summary 30 ngày |
| 👤 **Hồ sơ** | Form thông tin cá nhân, chiều cao/cân nặng, mục tiêu, vận động |

## ⚙️ Admin Pages (6 trang)

| Trang | Quyền | Mô tả |
|---|---|---|
| Dashboard | `read` | Stats cards, bữa ăn hôm nay, xu hướng 7 ngày, profile |
| Ghi bữa ăn | `read` | Form ghi bữa + chụp ảnh + AI phân tích |
| Lịch sử | `read` | Bảng lịch sử bữa ăn + phân trang |
| Users | `manage_options` | Danh sách users + profile + stats |
| Thực phẩm | `manage_options` | CSDL thực phẩm |
| Cài đặt | `manage_options` | Chỉnh sửa hồ sơ dinh dưỡng |

## 🚀 Roadmap

- [x] ~~Intent Routing: mở rộng regex patterns, thêm `bizcity_mode_execution_patterns`~~ (v1.1.0)
- [x] ~~Profile Context: implement `get_profile_context()` cho AI biết user data~~ (v1.1.0)
- [x] ~~Mode Classifier: hook execution patterns cho nutrition keywords~~ (v1.1.0)
- [x] ~~Photo slot types fix + chat integration~~ (v1.2.0)
- [x] ~~Weight log history + BMI chart~~ (v1.3.0)
- [x] ~~Macro progress bars + mascot UI + week strip~~ (v1.3.0)
- [x] ~~Chat nhan ảnh bữa ăn → auto phân tích Vision AI~~ (v1.3.0)
- [ ] Food CRUD admin (thêm/sửa/xóa thực phẩm)
- [ ] Water tracking (nước uống)
- [ ] Weekly/Monthly PDF report
- [ ] Barcode scan (UPC → nutrition DB)
- [ ] Meal plan generator (AI suggest thực đơn tuần)
- [ ] Exercise tracking integration
- [ ] Notification reminders (nhắc ghi bữa ăn)

---

## 📝 Changelog

### v1.3.0 — 2026-03-01 (Weight + Macro Bars + Chat Photo)

**Tính năng mới:**

| # | Feature | Files |
|---|---|---|
| 1 | **Fix JS infinite loop** — `<input type="file">` moved OUTSIDE photo zone div → no click recursion | `shortcode.php`, `public.js` |
| 2 | **Macro Progress Bars** — 3 animated bars (Tinh bột/Đạm/Chất béo) với target tự tính theo goal | `shortcode.php`, `public.js`, `public.css` |
| 3 | **Mascot + greeting** — Bouncing chef mascot 🧑‍🍳 với dynamic message theo % calo hôm nay | `shortcode.php`, `public.js`, `public.css` |
| 4 | **Week strip calendar** — 7-day calendar strip trên dashboard, highlight ngày có dữ liệu | `public.js`, `public.css`, `ajax.php` (get_today returns week_stats) |
| 5 | **Tab Cân nặng (⚖️)** — BMI card + BMI color scale + log weight form + SVG line chart + history | `shortcode.php`, `public.js`, `public.css` |
| 6 | **Weight AJAX** — `bzcalo_log_weight`, `bzcalo_get_weight_history`, `bzcalo_delete_weight` endpoints | `ajax.php` |
| 7 | **Chat photo auto-detect** — ANY image → Vision AI phân tích, nếu KHÔNG phải food → passthrough | `integration-chat.php` |
| 8 | **Broader intent detection** — Regex mở rộng: "anh ăn", "người ăn", "ăn này", "mình ăn"... | `integration-chat.php`, `bizcity-agent-calo.php` |
| 9 | **Non-food image safety** — AI trả `is_food: false` → plugin skip, gateway xử lý bình thường | `integration-chat.php` |

### v1.2.0 — 2026-02-28 (Photo Slot + Chat Integration)

**Fixes:** Photo slot types `text` → `image`, optional `photo_url` in `calo_log_meal`, chat integration with Gemini Vision.

### v1.1.0 — 2026-03-01 (Intent Routing Fix)

**Vấn đề:** Câu hỏi như "cân nặng tôi bao nhiêu", "giảm cân", "dinh dưỡng" KHÔNG được route tới plugin calo. Debug cho thấy `provider_profile_build` trả về `calo: — (0 chars)`.

**Root Causes & Fixes:**

| # | Nguyên nhân | Fix | File |
|---|---|---|---|
| 1 | `get_profile_context()` không được override → trả về empty array (0 chars) | Implement đầy đủ: trả về profile user (tên, tuổi, chiều cao, cân nặng, mục tiêu, calo/ngày, vận động, dị ứng, stats hôm nay) | `class-intent-provider.php` |
| 2 | Goal patterns quá hẹp — chỉ match cụm từ cố định ("ghi bữa ăn", "chụp ảnh bữa") | Mở rộng regex: thêm ~40 keywords (cân nặng, BMI, giảm cân, dinh dưỡng, protein, béo, gầy, sức khỏe…) | `class-intent-provider.php` |
| 3 | Mode Classifier phân loại "cân nặng tôi bao nhiêu" là `knowledge` (không phải `execution`) → message không đến Intent Router | Hook `bizcity_mode_execution_patterns` filter với 8 regex patterns covering nutrition/health keywords | `bizcity-agent-calo.php` |
| 4 | CSS/JS cache cũ | Bump version 1.0.0 → 1.1.0 | `bizcity-agent-calo.php` |

**Bài học rút ra → xem phần "🧭 Hướng dẫn Intent Integration" bên dưới.**

---

## 🧭 Hướng dẫn Intent Integration (Lesson Learned)

> **Dành cho các plugin mới** — 3 tầng phải xử lý đúng để Intent Engine route message tới plugin.

### Tầng 1: Mode Classifier (Tier 1 — Phân loại Mode)

Mode Classifier phân loại message vào 6 mode: `emotion`, `reflection`, `knowledge`, `planning`, `coding`, `execution`.
**CHỈ mode `execution` mới đi tiếp vào Intent Router (Tier 2).**

⚠️ **Sai lầm phổ biến:** Chỉ viết goal patterns mà quên hook mode classifier → message bị phân loại `knowledge` → không đến router.

**Fix:** Plugin PHẢI hook `bizcity_mode_execution_patterns` trong bootstrap:

```php
add_filter( 'bizcity_mode_execution_patterns', function( $patterns ) {
    // Thêm regex patterns cho keywords chuyên môn của plugin
    $patterns[] = '/keyword1|keyword2|keyword3/ui';
    $patterns[] = '/keyword4|keyword5/ui';
    return $patterns;
} );
```

**Nguyên tắc:** Mỗi keyword chuyên môn của plugin (cân nặng, BMI, calo, tarot, chiêm tinh…) PHẢI có trong execution patterns.

### Tầng 2: Intent Router (Tier 2 — Pattern Matching + LLM Fallback)

Router dùng `get_goal_patterns()` để match message → goal.

⚠️ **Sai lầm phổ biến:** Chỉ viết regex cho action cụ thể ("ghi bữa ăn", "chụp ảnh") mà bỏ qua câu hỏi tổng quát ("cân nặng tôi bao nhiêu", "giảm cân thế nào").

**Fix:** Patterns phải cover 3 loại:
1. **Action trực tiếp:** "ghi bữa ăn", "bốc bài tarot" = user biết plugin gì
2. **Câu hỏi liên quan:** "cân nặng tôi bao nhiêu", "hôm nay ăn gì" = hỏi về domain
3. **Keywords broad:** "dinh dưỡng", "protein", "giảm cân" = thuộc lĩnh vực plugin

**Regex tip:** Dùng alternation lớn, gộp các biến thể dấu tiếng Việt:
```php
'/c[âa]n\s*n[ặa]ng|bao\s*nhi[êe]u\s*k[gý]|BMI|BMR|TDEE|chi[ềe]u\s*cao/ui'
```

**Test messages:** Viết ít nhất 10 câu test thực tế, bao gồm câu "bất ngờ" mà user thật sẽ hỏi.

### Tầng 3: Profile Context (`get_profile_context()`)

Engine gọi `get_profile_context($user_id)` cho MỌI provider → inject vào system prompt.
**Nếu không override → trả empty → AI không biết gì về user trong domain này.**

⚠️ **Sai lầm phổ biến:** Không override method này. Base class trả `['complete'=>false, 'context'=>'', 'fallback'=>'']` → 0 chars → AI không có dữ kiện để trả lời.

**Fix:** PHẢI override và trả về profile data đầy đủ:

```php
public function get_profile_context( $user_id ) {
    if ( ! $user_id ) {
        return [
            'complete' => false,
            'context'  => '',
            'fallback' => 'Hướng dẫn đăng nhập...',
        ];
    }

    $profile = get_user_profile( $user_id );
    $complete = has_essential_data( $profile );

    if ( ! $complete ) {
        return [
            'complete' => false,
            'context'  => partial_context( $profile ),  // Vẫn trả partial nếu có
            'fallback' => 'Hướng dẫn điền profile...',
        ];
    }

    return [
        'complete' => true,
        'context'  => full_context( $profile ),  // Tất cả data user
        'fallback' => '',
    ];
}
```

**Context nên chứa:**
- Thông tin cá nhân (tên, tuổi, giới tính)
- Dữ liệu chuyên môn (cân nặng, mục tiêu, dị ứng, etc.)
- Trạng thái hiện tại (stats hôm nay, lần dùng cuối, etc.)
- Link trang hồ sơ (nếu cần hướng user cập nhật)

### Checklist 3-Tầng (Bắt buộc cho mọi provider)

```
□ Tầng 1 — Mode Execution Patterns
  □ Hook bizcity_mode_execution_patterns trong bootstrap
  □ Cover TẤT CẢ keywords chuyên môn (domain-specific)
  □ Test: hỏi câu tổng quát → mode = execution (check debug log)

□ Tầng 2 — Goal Patterns (get_goal_patterns)
  □ Cover action trực tiếp + câu hỏi liên quan + keywords broad
  □ Hỗ trợ biến thể dấu tiếng Việt (regex [ăa], [ếe], [ìi]…)
  □ Viết ≥10 test messages (5 positive + 5 negative)
  □ LLM Router sẽ fallback nếu regex miss → nhưng KHÔNG nên dựa vào đây

□ Tầng 3 — Profile Context (get_profile_context)
  □ Override method, KHÔNG dùng default base class
  □ Trả đầy đủ: complete, context, fallback
  □ Context chứa profile + stats hiện tại
  □ Fallback hướng user điền profile nếu thiếu
  □ Debug: provider_profile_build phải > 0 chars
```
