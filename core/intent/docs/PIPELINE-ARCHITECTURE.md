# Pipeline Orchestration Architecture

> **Phiên bản:** 1.0 — Draft  
> **Mục tiêu:** Thiết kế hệ thống pipeline multi-step cho phép chuỗi plugin chạy tuần tự, truyền JSON output → input giữa các bước, theo dõi tiến độ qua checklist.

---

## 1. Tổng quan vấn đề

### Hiện tại (Single-step)

```
User Message → Router → Planner → 1 Tool → Complete
```

- `process()` chạy **1 lần duy nhất** mỗi message
- Planner chỉ gọi **1 tool** rồi trả kết quả
- Không có cơ chế chạy chuỗi tool liên tiếp
- Field `open_loops` tồn tại trong DB nhưng **chưa được sử dụng**

### Mong muốn (Multi-step Pipeline)

```
User: "Viết bài về sản phẩm mới, tạo ảnh minh họa, đăng lên web và Facebook"

→ Planner phát hiện cần multi-step
→ Tạo Pipeline Plan (checklist)
→ Step 1: write_article  → {title, content, url}     ✅
→ Step 2: generate_image → {image_url}                ✅
→ Step 3: post_wordpress → {post_id, post_url}        ✅
→ Step 4: post_facebook  → {fb_post_id}               ⏳ đang chạy...
→ Hoàn tất pipeline
```

---

## 2. Nguyên tắc thiết kế

| # | Nguyên tắc | Giải thích |
|---|---|---|
| 1 | **Backward-compatible** | Tool hiện tại không cần sửa — output `{success, message, data}` đã đủ |
| 2 | **Convention over code** | Chuẩn hóa naming trong `data` thay vì bắt buộc interface mới |
| 3 | **Tái sử dụng open_loops** | Dùng field DB sẵn có, không thêm table mới cho pipeline state |
| 4 | **Progressive — chạy được ngay** | Bước 1 dùng pipeline template cố định, bước 2 mới để LLM generate |
| 5 | **Bridge không replace** | Bổ sung layer mới, không thay đổi flow hiện tại của single-step |

---

## 3. Standard Tool Output Contract

### 3.1 Hiện tại (không thay đổi)

Mọi tool đều trả về:

```php
[
    'success'        => bool,
    'complete'       => bool,
    'message'        => string,   // Human-readable
    'data'           => array,    // Structured output
    'missing_fields' => array,    // Optional
]
```

### 3.2 Quy ước chuẩn hóa `data` (Convention)

Để pipeline có thể tự động mapping output → input giữa các step, **khuyến nghị** (không bắt buộc) các tool trả `data` theo convention:

```php
'data' => [
    // === Identification ===
    'id'        => mixed,    // ID chính (post_id, script_id, order_id...)
    'type'      => string,   // 'article' | 'image' | 'video' | 'product' | 'post' | 'link' | 'data'

    // === Content ===
    'title'     => string,   // Tiêu đề
    'content'   => string,   // Nội dung chính (HTML/text)
    'excerpt'   => string,   // Tóm tắt

    // === Media ===
    'url'       => string,   // URL chính (permalink, download link)
    'image_url' => string,   // URL ảnh
    'video_url' => string,   // URL video

    // === Platform ===
    'edit_url'  => string,   // URL chỉnh sửa (admin)
    'platform'  => string,   // 'wordpress' | 'facebook' | 'zalo'

    // === Extended ===
    'meta'      => array,    // Dữ liệu mở rộng tùy plugin
]
```

### 3.3 Mapping hiện tại → convention

| Tool hiện tại | Output data hiện tại | Mapping |
|---|---|---|
| `write_article` | `post_id`, `title`, `url`, `edit_url` | `id=post_id`, `type='article'` |
| `create_product` | `result` (mixed) | `id=result.post_id`, `type='product'` |
| `post_facebook` | `post_id` hoặc `fb_post_id` | `id=post_id`, `type='post'`, `platform='facebook'` |
| `create_video` | `script_id`, `url` | `id=script_id`, `type='video'` |
| `send_link_tarot` | `tarot_link`, `focus` | `type='link'`, `url=tarot_link` |

> **Lưu ý:** Pipeline resolver sẽ hỗ trợ CẢ naming cũ lẫn convention mới qua alias mapping (xem mục 6.3).

---

## 4. Pipeline Plan Schema

### 4.1 Cấu trúc Pipeline

```json
{
    "pipeline_id": "pipe_a1b2c3d4",
    "template":    "publish_article_social",
    "goal":        "Viết bài và đăng đa nền tảng",
    "created_at":  "2025-07-15T10:00:00",

    "steps": [
        {
            "step":      1,
            "tool":      "write_article",
            "label":     "Viết bài blog",
            "input_map": {
                "topic":   "$slots.topic",
                "content": "$slots.content"
            },
            "status":    "completed",
            "output":    { "success": true, "data": { "id": 456, "title": "...", "content": "...", "url": "..." } },
            "started_at":   "2025-07-15T10:00:01",
            "completed_at": "2025-07-15T10:00:15"
        },
        {
            "step":      2,
            "tool":      "generate_image",
            "label":     "Tạo ảnh minh họa",
            "input_map": {
                "prompt": "$step[1].data.title"
            },
            "depends_on": [1],
            "status":    "running",
            "output":    null
        },
        {
            "step":      3,
            "tool":      "post_wordpress",
            "label":     "Đăng bài lên web",
            "input_map": {
                "title":     "$step[1].data.title",
                "content":   "$step[1].data.content",
                "image_url": "$step[2].data.image_url"
            },
            "depends_on": [1, 2],
            "status":    "pending",
            "output":    null
        },
        {
            "step":      4,
            "tool":      "post_facebook",
            "label":     "Đăng bài Facebook",
            "input_map": {
                "content":   "$step[1].data.content",
                "image_url": "$step[2].data.image_url"
            },
            "depends_on": [1, 2],
            "status":    "pending",
            "output":    null
        }
    ],

    "current_step": 2,
    "status":       "running",
    "error":        null
}
```

### 4.2 Step Status Lifecycle

```
pending → running → completed
                  → failed → (retry | skip | abort)
                  → waiting  (async tool, đợi callback)
```

### 4.3 Pipeline Status

```
created → running → completed
                  → failed     (step thất bại + không thể tiếp tục)
                  → paused     (đợi user input hoặc async callback)
                  → aborted    (user hủy bỏ)
```

### 4.4 Input Map Reference Syntax

| Pattern | Ý nghĩa | Ví dụ |
|---|---|---|
| `$slots.field` | Từ conversation slots | `$slots.topic` → "sản phẩm mới" |
| `$step[N].data.field` | Từ output step N | `$step[1].data.title` → "Sản phẩm ABC" |
| `$step[N].message` | Message text của step N | `$step[1].message` → "✅ Bài đã đăng..." |
| `$step[N].data.meta.field` | Từ meta output step N | `$step[2].data.meta.width` → 1024 |
| `$user.field` | Từ WP user profile | `$user.display_name` → "Hương" |
| `$context.field` | Từ rolling_summary/context | `$context.summary` |
| `"literal"` | Giá trị cố định | `"9:16"` → "9:16" |

---

## 5. Kiến trúc hệ thống

### 5.1 Tổng quan flow

```
                    ┌──────────────────────────────────┐
                    │        Intent Engine              │
                    │       process() - 6 steps         │
                    └──────────┬───────────────────────┘
                               │
                    Step 5: Planner
                               │
                    ┌──────────▼──────────┐
                    │   action = ?         │
                    ├─────────────────────┤
                    │ ask_user            │──→ Hỏi user slot
                    │ call_tool           │──→ Chạy 1 tool (hiện tại)
                    │ compose_answer      │──→ AI trả lời
                    │ passthrough         │──→ Chuyển AI brain
                    │ complete            │──→ Kết thúc
                    │ ──────────────────  │
                    │ ★ run_pipeline ★    │──→ NEW: Chạy multi-step
                    └─────────────────────┘
                               │
                    ┌──────────▼──────────────────┐
                    │   BizCity_Intent_Pipeline    │
                    │   (class mới)                │
                    ├─────────────────────────────┤
                    │ create_pipeline($template)   │
                    │ resume_pipeline($conv)       │
                    │ execute_step($step)          │
                    │ resolve_inputs($input_map)   │
                    │ checkpoint($conv, $pipeline) │
                    │ get_progress_message()       │
                    └──────────┬──────────────────┘
                               │
                    ┌──────────▼──────┐
                    │  Tools::execute │  (tool hiện tại, không thay đổi)
                    └─────────────────┘
```

### 5.2 Sequence Diagram — Pipeline Execution

```
User                Engine              Planner           Pipeline            Tools
 │                    │                    │                  │                  │
 │ "viết bài và đăng" │                    │                  │                  │
 │───────────────────>│                    │                  │                  │
 │                    │──── plan() ───────>│                  │                  │
 │                    │<── run_pipeline ───│                  │                  │
 │                    │                    │                  │                  │
 │                    │── create_pipeline ─────────────────>│                  │
 │                    │<── pipeline_plan (4 steps) ────────│                  │
 │                    │                    │                  │                  │
 │                    │── execute_step(1) ────────────────>│                  │
 │                    │                    │                  │── execute ─────>│
 │                    │                    │                  │<── {data} ──────│
 │                    │<── step 1 done ───────────────────│                  │
 │                    │                    │                  │                  │
 │  "✅ 1/4 Viết bài" │                    │                  │                  │
 │<───────────────────│                    │                  │                  │
 │                    │                    │                  │                  │
 │                    │── execute_step(2) ────────────────>│                  │
 │                    │                    │                  │── execute ─────>│
 │                    │                    │                  │<── {data} ──────│
 │                    │<── step 2 done ───────────────────│                  │
 │                    │                    │                  │                  │
 │  "✅ 2/4 Tạo ảnh"  │                    │                  │                  │
 │<───────────────────│                    │                  │                  │
 │                    │                    │                  │                  │
 │                    │   ... step 3, 4 ...                │                  │
 │                    │                    │                  │                  │
 │ "🎉 Pipeline done" │                    │                  │                  │
 │<───────────────────│                    │                  │                  │
```

### 5.3 Class Structure

```
bizcity-intent/
  includes/
    class-intent-engine.php          (SỬA: thêm handle action='run_pipeline')
    class-intent-planner.php         (SỬA: thêm detect multi-step → run_pipeline)
    class-intent-tools.php           (KHÔNG ĐỔI)
    class-intent-conversation.php    (SỬA: thêm methods cho open_loops)

    ★ class-intent-pipeline.php      (MỚI: Pipeline Executor)
    ★ class-pipeline-template.php    (MỚI: Pipeline Template Registry)
    ★ class-pipeline-resolver.php    (MỚI: Input Reference Resolver)
```

---

## 6. Chi tiết thiết kế các class

### 6.1 `BizCity_Intent_Pipeline` — Pipeline Executor

```php
class BizCity_Intent_Pipeline {

    private $tools;        // BizCity_Intent_Tools
    private $templates;    // BizCity_Pipeline_Template
    private $resolver;     // BizCity_Pipeline_Resolver
    private $conversation; // BizCity_Intent_Conversation

    /**
     * Tạo pipeline mới từ template hoặc LLM plan.
     *
     * @param string $template_id  Template name (vd: 'publish_article_social')
     * @param array  $slots        Conversation slots hiện tại
     * @param array  $conv         Conversation data
     * @return array Pipeline plan (JSON structure từ mục 4.1)
     */
    public function create_pipeline( $template_id, $slots, $conv ) {
        $template = $this->templates->get( $template_id );
        if ( ! $template ) {
            return [ 'error' => 'Template not found: ' . $template_id ];
        }

        $pipeline = [
            'pipeline_id'  => 'pipe_' . wp_generate_uuid4(),
            'template'     => $template_id,
            'goal'         => $conv['goal'] ?? '',
            'created_at'   => current_time( 'mysql' ),
            'steps'        => $this->build_steps( $template['steps'], $slots ),
            'current_step' => 0,
            'status'       => 'created',
            'error'        => null,
        ];

        // Lưu vào open_loops
        $this->checkpoint( $conv['conversation_id'], $pipeline );

        return $pipeline;
    }

    /**
     * Resume pipeline từ open_loops (khi user gửi message tiếp).
     *
     * @param array $conv Conversation data (chứa open_loops JSON)
     * @return array|null Pipeline data hoặc null nếu không có pipeline active
     */
    public function resume_pipeline( $conv ) {
        $loops = json_decode( $conv['open_loops'] ?: '[]', true );
        if ( empty( $loops ) || empty( $loops['pipeline'] ) ) {
            return null;
        }
        return $loops['pipeline'];
    }

    /**
     * Chạy pipeline — thực thi từng step tuần tự.
     *
     * @param array  $pipeline Pipeline plan
     * @param array  $conv     Conversation data
     * @param array  $context  Extra context (user message, etc.)
     * @return array Kết quả sau khi chạy xong hoặc pause
     *   [
     *     'pipeline'     => array,    // Updated pipeline state
     *     'completed'    => bool,     // Pipeline hoàn tất?
     *     'paused'       => bool,     // Đợi async/user input?
     *     'messages'     => array,    // Progress messages gửi cho user
     *     'final_result' => array,    // Kết quả cuối (nếu completed)
     *   ]
     */
    public function run( $pipeline, $conv, $context = [] ) {
        $pipeline['status'] = 'running';
        $messages = [];

        foreach ( $pipeline['steps'] as $i => &$step ) {
            if ( $step['status'] === 'completed' ) {
                continue; // Bỏ qua step đã xong
            }

            // Check dependencies
            if ( ! $this->dependencies_met( $step, $pipeline['steps'] ) ) {
                continue; // Chưa đủ dependency
            }

            // Resolve input references
            $resolved_slots = $this->resolver->resolve(
                $step['input_map'],
                $pipeline['steps'],
                $conv['slots'] ?? [],
                $context
            );

            // Execute tool
            $step['status']     = 'running';
            $step['started_at'] = current_time( 'mysql' );
            $pipeline['current_step'] = $step['step'];

            $result = $this->tools->execute( $step['tool'], $resolved_slots );

            if ( ! empty( $result['missing_fields'] ) ) {
                // Tool cần thêm input → pause pipeline
                $step['status'] = 'waiting';
                $pipeline['status'] = 'paused';
                $this->checkpoint( $conv['conversation_id'], $pipeline );

                return [
                    'pipeline'  => $pipeline,
                    'completed' => false,
                    'paused'    => true,
                    'messages'  => array_merge( $messages, [
                        $this->progress_message( $pipeline ),
                    ]),
                    'waiting_for' => $result['missing_fields'],
                ];
            }

            if ( $result['success'] ) {
                $step['status']       = 'completed';
                $step['completed_at'] = current_time( 'mysql' );
                $step['output']       = [
                    'success' => true,
                    'message' => $result['message'],
                    'data'    => $result['data'] ?? [],
                ];

                $messages[] = $this->step_message( $step, $pipeline );
            } else {
                // Step failed
                $step['status'] = 'failed';
                $step['output'] = [
                    'success' => false,
                    'message' => $result['message'],
                    'data'    => [],
                ];

                // Có thể tiếp tục nếu step không critical?
                if ( ! $this->is_critical_dependency( $step, $pipeline['steps'] ) ) {
                    $messages[] = "⚠️ Step {$step['step']} ({$step['label']}) thất bại, bỏ qua...";
                    continue;
                }

                // Critical failure → abort pipeline
                $pipeline['status'] = 'failed';
                $pipeline['error']  = "Step {$step['step']} failed: " . $result['message'];
                $this->checkpoint( $conv['conversation_id'], $pipeline );

                return [
                    'pipeline'  => $pipeline,
                    'completed' => false,
                    'paused'    => false,
                    'messages'  => array_merge( $messages, [
                        "❌ Pipeline thất bại tại step {$step['step']}: {$step['label']}",
                    ]),
                ];
            }

            // Checkpoint sau mỗi step
            $this->checkpoint( $conv['conversation_id'], $pipeline );
        }

        // Tất cả steps completed
        $pipeline['status'] = 'completed';
        $this->checkpoint( $conv['conversation_id'], $pipeline );

        $last_step = end( $pipeline['steps'] );

        return [
            'pipeline'     => $pipeline,
            'completed'    => true,
            'paused'       => false,
            'messages'     => array_merge( $messages, [
                $this->completion_message( $pipeline ),
            ]),
            'final_result' => $last_step['output'] ?? [],
        ];
    }

    /**
     * Lưu pipeline state vào conversation.open_loops.
     */
    private function checkpoint( $conversation_id, $pipeline ) {
        $loops = [ 'pipeline' => $pipeline ];
        $this->conversation->update_open_loops(
            $conversation_id,
            wp_json_encode( $loops )
        );
    }

    /**
     * Kiểm tra dependencies đã hoàn tất chưa.
     */
    private function dependencies_met( $step, $all_steps ) {
        if ( empty( $step['depends_on'] ) ) {
            return true;
        }
        foreach ( $step['depends_on'] as $dep_step_num ) {
            foreach ( $all_steps as $s ) {
                if ( $s['step'] === $dep_step_num && $s['status'] !== 'completed' ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check nếu step thất bại có block step khác không.
     */
    private function is_critical_dependency( $failed_step, $all_steps ) {
        foreach ( $all_steps as $s ) {
            if ( isset( $s['depends_on'] ) && in_array( $failed_step['step'], $s['depends_on'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tạo progress message cho user.
     */
    private function progress_message( $pipeline ) {
        $total     = count( $pipeline['steps'] );
        $completed = 0;
        $lines     = [ "📋 **Pipeline: {$pipeline['goal']}**\n" ];

        foreach ( $pipeline['steps'] as $step ) {
            $icon = '⬜';
            if ( $step['status'] === 'completed' ) { $icon = '✅'; $completed++; }
            elseif ( $step['status'] === 'running' )  { $icon = '⏳'; }
            elseif ( $step['status'] === 'failed' )   { $icon = '❌'; }
            elseif ( $step['status'] === 'waiting' )  { $icon = '⏸️'; }

            $lines[] = "{$icon} Step {$step['step']}: {$step['label']}";
        }

        $lines[] = "\n📊 Tiến độ: {$completed}/{$total}";
        return implode( "\n", $lines );
    }

    /**
     * Message khi 1 step hoàn tất.
     */
    private function step_message( $step, $pipeline ) {
        $total     = count( $pipeline['steps'] );
        return "✅ [{$step['step']}/{$total}] {$step['label']}";
    }

    /**
     * Message khi pipeline hoàn tất.
     */
    private function completion_message( $pipeline ) {
        $total = count( $pipeline['steps'] );
        return "🎉 **Pipeline hoàn tất!** {$total}/{$total} bước đã thực hiện xong.\n\n" .
               $this->progress_message( $pipeline );
    }
}
```

### 6.2 `BizCity_Pipeline_Template` — Template Registry

```php
class BizCity_Pipeline_Template {

    private $templates = [];

    public function __construct() {
        $this->register_defaults();

        // Cho phép plugin khác đăng ký pipeline template
        do_action( 'bizcity_pipeline_register_templates', $this );
    }

    /**
     * Đăng ký template.
     *
     * @param string $id    Template identifier
     * @param array  $config Template config
     */
    public function register( $id, $config ) {
        $this->templates[ $id ] = wp_parse_args( $config, [
            'label'       => $id,
            'description' => '',
            'triggers'    => [],     // Keywords/intents that trigger this pipeline
            'steps'       => [],
        ]);
    }

    public function get( $id ) {
        return $this->templates[ $id ] ?? null;
    }

    public function list_all() {
        return $this->templates;
    }

    /**
     * Tìm template phù hợp dựa trên goal + slots.
     *
     * @param string $goal  Current goal
     * @param array  $slots Current slots
     * @return string|null Template ID hoặc null
     */
    public function match( $goal, $slots = [] ) {
        foreach ( $this->templates as $id => $tpl ) {
            if ( in_array( $goal, $tpl['triggers'], true ) ) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Templates mặc định.
     */
    private function register_defaults() {

        // ── Pipeline 1: Viết bài + đăng web ─────────────────
        $this->register( 'publish_article', [
            'label'       => 'Viết & đăng bài',
            'description' => 'Viết bài blog bằng AI, tạo ảnh minh họa, đăng lên WordPress',
            'triggers'    => [ 'write_and_publish', 'create_blog_post' ],
            'steps'       => [
                [
                    'tool'      => 'write_article',
                    'label'     => 'Viết bài blog',
                    'input_map' => [
                        'topic'   => '$slots.topic',
                        'content' => '$slots.content',
                    ],
                ],
            ],
        ]);

        // ── Pipeline 2: Viết bài + ảnh + đăng multi-platform ──
        $this->register( 'publish_article_social', [
            'label'       => 'Viết & đăng đa nền tảng',
            'description' => 'Viết bài, tạo ảnh, đăng lên web + Facebook',
            'triggers'    => [ 'write_and_publish_social', 'publish_everywhere' ],
            'steps'       => [
                [
                    'tool'      => 'write_article',
                    'label'     => 'Viết bài blog',
                    'input_map' => [
                        'topic'   => '$slots.topic',
                        'content' => '$slots.content',
                    ],
                ],
                [
                    'tool'      => 'post_facebook',
                    'label'     => 'Đăng bài Facebook',
                    'input_map' => [
                        'content'   => '$step[1].data.content',
                        'image_url' => '$step[1].data.image_url',
                    ],
                    'depends_on' => [1],
                ],
            ],
        ]);

        // ── Pipeline 3: Tạo video từ bài viết ──────────────
        $this->register( 'article_to_video', [
            'label'       => 'Bài viết → Video',
            'description' => 'Viết script từ chủ đề, tạo video bằng AI',
            'triggers'    => [ 'create_article_video', 'write_and_video' ],
            'steps'       => [
                [
                    'tool'      => 'write_article',
                    'label'     => 'Viết kịch bản',
                    'input_map' => [
                        'topic'   => '$slots.topic',
                        'content' => '$slots.content',
                    ],
                ],
                [
                    'tool'      => 'create_video',
                    'label'     => 'Tạo video AI',
                    'input_map' => [
                        'content'      => '$step[1].data.content',
                        'title'        => '$step[1].data.title',
                        'aspect_ratio' => '$slots.aspect_ratio',
                    ],
                    'depends_on' => [1],
                ],
            ],
        ]);

        // ── Pipeline 4: Content suite (bài + ảnh + video + đăng) ──
        $this->register( 'content_suite', [
            'label'       => 'Content Suite',
            'description' => 'Viết bài, tạo ảnh, tạo video, đăng web + Facebook',
            'triggers'    => [ 'full_content_pipeline', 'content_suite' ],
            'steps'       => [
                [
                    'tool'      => 'write_article',
                    'label'     => 'Viết nội dung',
                    'input_map' => [
                        'topic'   => '$slots.topic',
                        'content' => '$slots.content',
                    ],
                ],
                [
                    'tool'      => 'create_video',
                    'label'     => 'Tạo video AI',
                    'input_map' => [
                        'content' => '$step[1].data.content',
                        'title'   => '$step[1].data.title',
                    ],
                    'depends_on' => [1],
                ],
                [
                    'tool'      => 'post_facebook',
                    'label'     => 'Đăng Facebook',
                    'input_map' => [
                        'content'   => '$step[1].data.content',
                        'image_url' => '$step[1].data.image_url',
                    ],
                    'depends_on' => [1],
                ],
            ],
        ]);
    }
}
```

### 6.3 `BizCity_Pipeline_Resolver` — Input Reference Resolver

```php
class BizCity_Pipeline_Resolver {

    /**
     * Alias mapping cho các tool output không chuẩn convention.
     * Giúp backward-compatible với output hiện tại.
     */
    private $aliases = [
        'post_id'    => 'id',
        'script_id'  => 'id',
        'fb_post_id' => 'id',
        'result'     => 'id',
        'tarot_link' => 'url',
    ];

    /**
     * Resolve tất cả references trong input_map thành giá trị thực.
     *
     * @param array $input_map  Input map của step (vd: ['topic' => '$slots.topic'])
     * @param array $steps      Tất cả steps trong pipeline (có output)
     * @param array $slots      Conversation slots
     * @param array $context    Extra context (user info, etc.)
     * @return array Resolved slots — sẵn sàng truyền vào Tools::execute()
     */
    public function resolve( $input_map, $steps, $slots = [], $context = [] ) {
        $resolved = [];

        foreach ( $input_map as $field => $reference ) {
            $resolved[ $field ] = $this->resolve_reference( $reference, $steps, $slots, $context );
        }

        return $resolved;
    }

    /**
     * Resolve 1 reference string thành giá trị.
     *
     * Patterns:
     *   $slots.field           → $slots['field']
     *   $step[N].data.field    → $steps[N-1]['output']['data']['field']
     *   $step[N].message       → $steps[N-1]['output']['message']
     *   $user.field            → wp_get_current_user()->field
     *   $context.field         → $context['field']
     *   "literal"              → literal (remove quotes)
     *   anything else          → passthrough as-is
     */
    private function resolve_reference( $ref, $steps, $slots, $context ) {
        if ( ! is_string( $ref ) ) {
            return $ref; // Already a value
        }

        // Literal string (quoted)
        if ( preg_match( '/^"(.+)"$/', $ref, $m ) ) {
            return $m[1];
        }

        // $slots.field
        if ( preg_match( '/^\$slots\.(.+)$/', $ref, $m ) ) {
            return $slots[ $m[1] ] ?? '';
        }

        // $step[N].data.field  OR  $step[N].message
        if ( preg_match( '/^\$step\[(\d+)\]\.(.+)$/', $ref, $m ) ) {
            $step_num = (int) $m[1];
            $path     = $m[2];

            // Find step by number
            $step_output = null;
            foreach ( $steps as $s ) {
                if ( (int) $s['step'] === $step_num && ! empty( $s['output'] ) ) {
                    $step_output = $s['output'];
                    break;
                }
            }

            if ( ! $step_output ) {
                return ''; // Step not completed yet
            }

            return $this->resolve_dot_path( $step_output, $path );
        }

        // $user.field
        if ( preg_match( '/^\$user\.(.+)$/', $ref, $m ) ) {
            $user = wp_get_current_user();
            return $user->{$m[1]} ?? '';
        }

        // $context.field
        if ( preg_match( '/^\$context\.(.+)$/', $ref, $m ) ) {
            return $context[ $m[1] ] ?? '';
        }

        // No $ prefix → literal value
        return $ref;
    }

    /**
     * Resolve dot-path on array (vd: "data.title" → $arr['data']['title'])
     * Hỗ trợ alias fallback.
     */
    private function resolve_dot_path( $data, $path ) {
        $parts   = explode( '.', $path );
        $current = $data;

        foreach ( $parts as $part ) {
            if ( is_array( $current ) && isset( $current[ $part ] ) ) {
                $current = $current[ $part ];
            } elseif ( is_array( $current ) && isset( $this->aliases[ $part ] ) ) {
                // Try alias
                $alias = $this->aliases[ $part ];
                $current = $current[ $alias ] ?? '';
            } else {
                return '';
            }
        }

        return $current;
    }
}
```

---

## 7. Tích hợp vào Intent Engine

### 7.1 Thay đổi trong `class-intent-planner.php`

Thêm logic phát hiện multi-step:

```php
// Trong method plan(), SAU khi xác định goal và slots:

// Kiểm tra nếu goal match pipeline template
$pipeline_tpl = $this->pipeline_templates->match( $goal, $slots );
if ( $pipeline_tpl ) {
    return [
        'action'       => 'run_pipeline',
        'pipeline_tpl' => $pipeline_tpl,
        'tool_slots'   => $merged_slots,
        'ai_compose'   => false,
    ];
}

// ... existing logic (ask_user, call_tool, compose_answer, etc.)
```

### 7.2 Thay đổi trong `class-intent-engine.php`

Thêm xử lý action `run_pipeline` trong `process()`:

```php
// Trong Step 6 (execute planned action):

case 'run_pipeline':
    $pipeline_executor = new BizCity_Intent_Pipeline(
        $this->tools,
        new BizCity_Pipeline_Template(),
        new BizCity_Pipeline_Resolver(),
        $this->conversation_mgr
    );

    // Check if resuming existing pipeline
    $existing = $pipeline_executor->resume_pipeline( $conversation );

    if ( $existing ) {
        $pipeline = $existing;
    } else {
        $pipeline = $pipeline_executor->create_pipeline(
            $plan['pipeline_tpl'],
            $merged_slots,
            $conversation
        );
    }

    // Run pipeline
    $result = $pipeline_executor->run( $pipeline, $conversation, [
        'user_message' => $input['message'],
    ]);

    // Handle result
    if ( $result['completed'] ) {
        $reply  = implode( "\n\n", $result['messages'] );
        $status = 'COMPLETED';
    } elseif ( $result['paused'] ) {
        $reply  = implode( "\n\n", $result['messages'] );
        $status = 'WAITING_USER';
    } else {
        // Failed
        $reply  = implode( "\n\n", $result['messages'] );
        $status = 'ACTIVE';
    }

    break;
```

### 7.3 Thay đổi trong `class-intent-conversation.php`

Thêm methods quản lý `open_loops`:

```php
/**
 * Update open_loops field cho conversation.
 */
public function update_open_loops( $conversation_id, $loops_json ) {
    return $this->db->update_conversation( $conversation_id, [
        'open_loops' => $loops_json,
    ]);
}

/**
 * Lấy pipeline đang chạy (nếu có).
 */
public function get_active_pipeline( $conversation_id ) {
    $conv = $this->get( $conversation_id );
    if ( ! $conv ) return null;

    $loops = json_decode( $conv['open_loops'] ?: '[]', true );
    return $loops['pipeline'] ?? null;
}

/**
 * Xóa pipeline (khi hoàn tất hoặc hủy).
 */
public function clear_pipeline( $conversation_id ) {
    return $this->update_open_loops( $conversation_id, '[]' );
}
```

---

## 8. Plugin mới đăng ký Pipeline Template

### 8.1 Từ bên ngoài (plugin khác)

```php
// Trong plugin bizcity-tarot hoặc bất kỳ plugin nào:
add_action( 'bizcity_pipeline_register_templates', function( $registry ) {

    $registry->register( 'tarot_full_reading', [
        'label'       => 'Bốc bài + Luận giải + Ghi nhật ký',
        'description' => 'Bốc bài Tarot, AI luận giải, lưu vào nhật ký cá nhân',
        'triggers'    => [ 'tarot_full', 'boc_bai_luan_giai' ],
        'steps'       => [
            [
                'tool'      => 'send_link_tarot',
                'label'     => 'Gửi link bốc bài Tarot online',
                'input_map' => [
                    'question_focus' => '$slots.question_focus',
                ],
            ],
            // Step 2, 3... có thể thêm sau
        ],
    ]);
});
```

### 8.2 LLM-Generated Pipeline (Phase 2)

```php
// Khi không match template nào, Planner gọi LLM để generate pipeline:

$available_tools = $this->tools->list_all(); // Lấy tất cả tool có sẵn
$prompt = $this->build_pipeline_prompt( $user_request, $available_tools );

// LLM trả về JSON pipeline plan
$llm_plan = $this->call_llm_for_pipeline( $prompt );

// Validate plan → chỉ chấp nhận tool names có trong registry
$validated = $this->validate_llm_pipeline( $llm_plan, $available_tools );
```

LLM system prompt ví dụ:

```
Bạn là Pipeline Planner. Dựa vào danh sách tools và yêu cầu của user,
hãy tạo pipeline plan dạng JSON.

Available tools:
- write_article: Viết bài blog (input: topic, content)
- create_video: Tạo video AI (input: content, title, duration, aspect_ratio)
- post_facebook: Đăng Facebook (input: content, image_url)
- create_product: Tạo sản phẩm (input: title, price, description, image_url)
- send_link_tarot: Gửi link bốc bài Tarot online (input: question_focus)

User request: "{user_message}"

Trả về JSON pipeline:
{
    "steps": [
        { "tool": "...", "label": "...", "input_map": {...}, "depends_on": [...] }
    ]
}

Quy tắc:
- Chỉ dùng tool có trong danh sách
- input_map dùng $slots.field cho input từ user, $step[N].data.field cho output step trước
- depends_on là mảng step numbers mà step này phụ thuộc
```

---

## 9. Async Tool Support

Một số tool có thời gian xử lý lâu (tạo video, generate ảnh AI). Pipeline cần hỗ trợ async.

### 9.1 Tool trả `complete: false`

```php
// Trong builtin_create_video:
return [
    'success'  => true,
    'complete' => false,   // ← Chưa xong, đợi async
    'message'  => '🎬 Đang tạo video...',
    'data'     => [
        'id'     => $script_id,
        'status' => 'processing',
        'poll_endpoint' => 'bizcity_kling_check_status',
        'poll_params'   => [ 'script_id' => $script_id ],
    ],
];
```

### 9.2 Pipeline xử lý async step

```php
// Trong Pipeline::run(), khi tool trả complete=false:
if ( $result['success'] && ! $result['complete'] ) {
    $step['status'] = 'waiting';
    $step['output'] = [
        'success' => true,
        'data'    => $result['data'],
        'poll'    => [
            'endpoint' => $result['data']['poll_endpoint'] ?? '',
            'params'   => $result['data']['poll_params'] ?? [],
            'interval' => 10, // seconds
        ],
    ];

    $pipeline['status'] = 'paused';
    $this->checkpoint( $conv_id, $pipeline );

    return [
        'pipeline'  => $pipeline,
        'completed' => false,
        'paused'    => true,
        'messages'  => [ "⏳ Step {$step['step']} đang xử lý... Sẽ tiếp tục khi hoàn tất." ],
    ];
}
```

### 9.3 Resume khi async hoàn tất

Khi user gửi message hoặc callback:

```php
// Trong Engine::process(), đầu tiên check pipeline đang paused:
$active_pipeline = $this->pipeline->resume_pipeline( $conversation );

if ( $active_pipeline && $active_pipeline['status'] === 'paused' ) {
    // Check if waiting step is now complete
    $waiting_step = $this->find_waiting_step( $active_pipeline );
    if ( $waiting_step && $this->poll_step_status( $waiting_step ) ) {
        // Step completed → resume pipeline
        return $this->pipeline->run( $active_pipeline, $conversation );
    }
}
```

---

## 10. UI/UX — Checklist Progress

### 10.1 Chat Message Format

Pipeline gửi progress qua chat messages:

```
📋 **Pipeline: Viết bài và đăng đa nền tảng**

✅ Step 1: Viết bài blog
⏳ Step 2: Tạo ảnh minh họa
⬜ Step 3: Đăng bài lên web
⬜ Step 4: Đăng bài Facebook

📊 Tiến độ: 1/4
```

Khi hoàn tất:

```
🎉 **Pipeline hoàn tất!** 4/4 bước đã thực hiện xong.

✅ Step 1: Viết bài blog
✅ Step 2: Tạo ảnh minh họa
✅ Step 3: Đăng bài lên web
✅ Step 4: Đăng bài Facebook
```

### 10.2 Webchat Frontend Integration

Trong webchat, có thể render checklist interactively:

```javascript
// Khi nhận message có pipeline progress
if (message.meta && message.meta.pipeline) {
    renderPipelineChecklist(message.meta.pipeline);
}

function renderPipelineChecklist(pipeline) {
    const html = pipeline.steps.map(step => {
        const icon = {
            completed: '✅', running: '⏳',
            pending: '⬜', failed: '❌', waiting: '⏸️'
        }[step.status] || '⬜';

        return `<div class="pipeline-step pipeline-step--${step.status}">
            ${icon} ${step.label}
        </div>`;
    }).join('');

    return `<div class="pipeline-checklist">
        <h4>📋 ${pipeline.goal}</h4>
        ${html}
        <div class="pipeline-progress">
            ${pipeline.steps.filter(s => s.status === 'completed').length}/${pipeline.steps.length}
        </div>
    </div>`;
}
```

---

## 11. Database Schema Changes

### 11.1 Không cần table mới

Pipeline state lưu trong `open_loops` (JSON column, đã có sẵn).

### 11.2 Optional: Pipeline History Table (Phase 2)

Nếu cần lưu lịch sử pipeline để analytics:

```sql
CREATE TABLE {prefix}bizcity_intent_pipelines (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    pipeline_id     VARCHAR(64) UNIQUE,
    conversation_id VARCHAR(64),
    user_id         BIGINT UNSIGNED,
    template        VARCHAR(100),
    goal            VARCHAR(255),
    steps_json      LONGTEXT,        -- Full pipeline JSON
    status          VARCHAR(20),
    started_at      DATETIME,
    completed_at    DATETIME NULL,
    error           TEXT NULL,
    INDEX idx_user   (user_id),
    INDEX idx_status (status),
    INDEX idx_conv   (conversation_id)
);
```

---

## 12. Lộ trình triển khai

### Phase 1 — Foundation (1-2 tuần)

| # | Task | Files |
|---|---|---|
| 1 | Tạo `class-pipeline-resolver.php` | Mới |
| 2 | Tạo `class-pipeline-template.php` với 3-4 template mặc định | Mới |
| 3 | Tạo `class-intent-pipeline.php` — core executor | Mới |
| 4 | Thêm `update_open_loops()` vào conversation manager | Sửa |
| 5 | Thêm action `run_pipeline` vào Planner | Sửa |
| 6 | Thêm handle `run_pipeline` vào Engine process() | Sửa |
| 7 | Test với template `publish_article_social` | Test |

### Phase 2 — Enhancement (2-3 tuần)

| # | Task |
|---|---|
| 8 | Thêm async tool support (poll/resume) |
| 9 | LLM-generated pipeline (khi không match template) |
| 10 | Webchat frontend pipeline checklist UI |
| 11 | Pipeline history table + admin page |
| 12 | Cho phép user retry/skip failed step qua chat |

### Phase 3 — Ecosystem (ongoing)

| # | Task |
|---|---|
| 13 | Plugins đăng ký custom templates via hook |
| 14 | Bridge bizcity-automation workflow → pipeline template |
| 15 | Pipeline analytics dashboard |
| 16 | Parallel step execution (steps không phụ thuộc nhau chạy đồng thời) |

---

## 13. So sánh với bizcity-automation Workflow

| Tiêu chí | Automation Workflow | Intent Pipeline |
|---|---|---|
| **Trigger** | Manual / Schedule / Webhook | AI phát hiện từ chat message |
| **Thiết kế** | Visual builder (drag-drop nodes) | Template JSON hoặc LLM-generated |
| **State** | Transient (`waic_exec_*`) | Conversation `open_loops` |
| **Execution** | BFS graph traversal | Sequential step-by-step |
| **Variable** | `{{node#ID.var}}` interpolation | `$step[N].data.field` resolver |
| **User interaction** | N/A (headless) | Chat-based progress + input |
| **Tool layer** | `WaicAction::getResults()` | `Tools::execute()` (same underlying functions) |

> **Bridge opportunity:** Phase 3 có thể tạo adapter chuyển Automation Workflow blocks thành Intent Tools, cho phép pipeline gọi bất kỳ automation action nào.

---

## 14. Ví dụ End-to-End

### User request

```
"Viết bài về sản phẩm thé giới khăn cho bé, tạo ảnh minh họa rồi đăng lên Facebook"
```

### Engine nhận message

```php
process([
    'message'    => 'Viết bài về sản phẩm thé giới khăn cho bé, tạo ảnh minh họa rồi đăng lên Facebook',
    'session_id' => 'wcs_abc123',
    'user_id'    => 5,
    'channel'    => 'webchat',
]);
```

### Step 2: Mode → `execution`

### Step 3: Router → goal = `write_and_publish_social`

### Step 5: Planner

```php
// Pipeline template match:
$template = 'publish_article_social';

// Return:
[
    'action'       => 'run_pipeline',
    'pipeline_tpl' => 'publish_article_social',
    'tool_slots'   => [
        'topic'   => 'sản phẩm thé giới khăn cho bé',
        'content' => 'viết bài về sản phẩm thé giới khăn cho bé',
    ],
]
```

### Step 6: Pipeline Execution

**Step 1 — write_article:**
```php
// input: { topic: 'sản phẩm thé giới khăn cho bé', content: '...' }
// output.data: { id: 789, title: 'Thế Giới Khăn Cho Bé...', content: '<p>...</p>', url: 'https://...', image_url: 'https://...' }
```

**Step 2 — post_facebook:**
```php
// input: { content: '$step[1].data.content' → '<p>...</p>', image_url: '$step[1].data.image_url' → 'https://...' }
// output.data: { id: 'fb_123456', platform: 'facebook' }
```

### User nhận message:

```
📋 **Pipeline: Viết & đăng đa nền tảng**

✅ [1/2] Viết bài blog
📝 Thế Giới Khăn Cho Bé — Sản Phẩm Chăm Sóc Tuyệt Vời
🔗 https://example.com/the-gioi-khan-cho-be

✅ [2/2] Đăng bài Facebook
📘 Post ID: fb_123456

🎉 **Pipeline hoàn tất!** 2/2 bước đã thực hiện xong.
```

---

## 15. Mở rộng — Plugin tự đăng ký Tool chuẩn

Nếu plugin mới muốn tham gia pipeline ecosystem, chỉ cần:

### 15.1 Đăng ký tool (đã có sẵn)

```php
add_action( 'bizcity_intent_tools_ready', function( $registry ) {
    $registry->register( 'my_custom_tool', [
        'description'  => 'Mô tả tool',
        'input_fields' => [
            'param1' => [ 'required' => true,  'type' => 'text' ],
            'param2' => [ 'required' => false, 'type' => 'number' ],
        ],
    ], 'my_tool_callback' );
});
```

### 15.2 Return data theo convention (khuyến nghị)

```php
function my_tool_callback( $slots ) {
    // ... logic ...

    return [
        'success'  => true,
        'complete' => true,
        'message'  => 'Mô tả kết quả cho user',
        'data'     => [
            'type'      => 'article',      // Convention type
            'id'        => $created_id,
            'title'     => $title,
            'content'   => $content,
            'url'       => $permalink,
            'image_url' => $image,
            'meta'      => [ 'custom' => 'data' ],
        ],
    ];
}
```

### 15.3 Đăng ký pipeline template (tùy chọn)

```php
add_action( 'bizcity_pipeline_register_templates', function( $registry ) {
    $registry->register( 'my_custom_flow', [
        'label'    => 'Custom Flow',
        'triggers' => [ 'my_goal_name' ],
        'steps'    => [ /* ... */ ],
    ]);
});
```

---

## Tổng kết

| Component | Vai trò | Effort |
|---|---|---|
| **Pipeline Resolver** | Parse `$step[N].data.field` → giá trị thực | Nhỏ (1 class, ~100 lines) |
| **Pipeline Template** | Registry template + match goal → template | Nhỏ (1 class, ~150 lines) |
| **Pipeline Executor** | Chạy steps tuần tự, checkpoint, progress | Trung bình (1 class, ~300 lines) |
| **Planner update** | Thêm detect multi-step → `run_pipeline` | Nhỏ (~20 lines thêm) |
| **Engine update** | Handle `run_pipeline` action | Nhỏ (~40 lines thêm) |
| **Conversation update** | Methods cho `open_loops` | Nhỏ (~30 lines thêm) |
| **Total Phase 1** | | **~640 lines PHP mới** |

Hệ thống thiết kế theo hướng **minimal invasive** — chỉ thêm 3 class mới + sửa nhỏ 3 class hiện tại. Mọi tool hiện tại tiếp tục hoạt động bình thường, pipeline chỉ kích hoạt khi Planner phát hiện goal khớp với template.
