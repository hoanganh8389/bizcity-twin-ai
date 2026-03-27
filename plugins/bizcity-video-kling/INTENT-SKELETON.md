# BizCity Intent — Plugin Skeleton Note

## Plugin: `bizcity-video-kling`

**Vai trò:** Tạo video bằng Kling AI — Intent Engine lưu script, user bấm link để generate.

### 1. Intents

| Goal           | Tool           | Slots                                        |
|----------------|----------------|----------------------------------------------|
| `create_video` | `create_video` | `content` (required), `title`, `duration`, `aspect_ratio`, `image_url` |

### 2. Integration Point

- **Class:** `BizCity_Video_Kling_Database`
- **Method:** `::save_script( $data )` → returns `$script_id`
- Intent Engine lưu script trực tiếp vào DB, user nhận link admin để generate video.

### 3. Completion Contract

```php
return [
    'success'  => true,
    'complete' => true,   // Script saved = goal achieved
    'message'  => '🎬 Đã tạo script video. Bấm link để generate.',
    'data'     => [ 'script_id' => $id, 'url' => $edit_url ],
];
```

- Video generation là **async** (Kling API callback) → nhưng từ góc nhìn intent engine, script đã lưu ≡ hoàn thành.

### 4. Testing Checklist

- [ ] `BizCity_Video_Kling_Database` class tồn tại khi plugin active
- [ ] `save_script()` trả về `$script_id` (int > 0) khi thành công
- [ ] URL admin page hoạt động
