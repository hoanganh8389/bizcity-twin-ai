# BizCity Intent — Plugin Skeleton Note

## Plugin: `bizcity-tool-heygen`

**Vai trò:** Tạo video avatar lipsync bằng HeyGen AI — quản lý nhân vật AI + nhập script → video chuyên nghiệp.

### 1. Intents

| Goal                 | Tool                   | Slots                                           |
|----------------------|------------------------|-------------------------------------------------|
| `create_lipsync_video` | `create_lipsync_video` | `script` (required), `character_id`, `mode`    |
| `list_characters`    | `list_characters`      | (none)                                         |
| `check_video_status` | `check_video_status`   | `job_id` (optional)                            |

### 2. Integration Point

- **Class:** `BizCity_Tool_HeyGen`
- **Primary Method:** `::create_lipsync_video( $slots, $context )` → calls HeyGen API → schedules cron poll
- **Secondary:** `::list_characters()`, `::check_video_status()`
- Intent Engine invoke tool callback → create job → schedule cron → cron poll → webchat push.

### 3. Completion Contract

```php
// create_lipsync_video
return [
    'success'  => true,
    'complete' => true,
    'message'  => '🎬 Đang tạo video lipsync... Khi hoàn thành sẽ gửi kết quả về đây!',
    'data'     => [
        'job_id'       => $job_id,
        'task_id'      => $task_id,
        'character'    => $character_name,
        'mode'         => 'text',
        'profile_url'  => site_url('/tool-heygen/'),
    ],
];

// list_characters
return [
    'success'  => true,
    'complete' => true,
    'message'  => '🎭 Danh sách nhân vật AI:...',
    'data'     => [ 'characters' => [...] ],
];

// check_video_status
return [
    'success'  => true,
    'complete' => true,
    'message'  => '📊 Trạng thái: processing (45%)...',
    'data'     => [ 'job' => [...] ],
];
```

### 4. Async Pipeline (Pillar 3)

1. Tool callback → HeyGen API `POST /v2/video/generate` → `task_id`
2. Schedule WP-Cron: `bthg_poll_video` (20s interval)
3. Cron poll: `GET /v1/video_status.get?video_id={task_id}`
4. When completed: download to Media Library → webchat push
5. Method 1: `BizCity_WebChat_Database::log_message()` (direct)
6. Method 2: Transient fallback

### 5. Testing Checklist

- [ ] `BizCity_Tool_HeyGen` class tồn tại khi plugin active
- [ ] `create_lipsync_video()` trả về `success=true` với valid character + script
- [ ] `list_characters()` trả về danh sách nhân vật active
- [ ] `check_video_status()` trả về trạng thái job gần nhất
- [ ] HeyGen API key được validate trước khi gọi API
- [ ] Cron poll hoạt động đúng interval (20s)
- [ ] Webchat push gửi kết quả khi job hoàn thành
- [ ] Profile URL `/tool-heygen/` hiển thị đúng 4 tab
- [ ] Voice clone workflow hoạt động (upload → clone → voice_id saved)
- [ ] DB tables tạo đúng khi activate plugin
