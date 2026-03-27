# BizCity Video Kling

Plugin WordPress tạo video AI sử dụng Kling thông qua PiAPI Gateway.

## 🎯 Tính Năng

- ✅ Tạo video từ ảnh (Image to Video) qua Kling AI
- ✅ Hỗ trợ models: kling-v1, kling-v1.5, veo
- ✅ Tích hợp WAIC Workflow System
- ✅ Tải video về WordPress Media Library
- ✅ Upload video lên R2 Cloud Storage
- ✅ Async polling với WP-Cron
- ✅ Focus: Video 9:16 cho mạng xã hội (TikTok, Instagram Reels, YouTube Shorts)

## 📋 Yêu Cầu

- WordPress 5.0+
- PHP 7.4+
- PiAPI Account với API Key ([Đăng ký tại đây](https://piapi.ai))
- WAIC Workflow System (để sử dụng workflow actions)

## 🚀 Cài Đặt

1. Copy thư mục `bizcity-video-kling` vào `/wp-content/mu-plugins/`
2. Truy cập **Tools → Video Kling** trong WordPress Admin
3. Nhập **PiAPI API Key**
4. Cấu hình các thiết lập mặc định

## 💡 Cách Sử Dụng

### 1. Trong WAIC Workflow

Tạo workflow với 3 actions theo thứ tự:

#### Action 1: Kling - Create Job

```
Node: Tạo Video
API Key: {{global.kling_api_key}}
Model: kling-v1
Task Type: image_to_video
Image URL: {{payload.image_url}}
Prompt: {{payload.prompt}}
Duration: 30
Aspect Ratio: 9:16
Job ID: video_{{timestamp}}
TTL: 7200
```

**Output:**
- `job_id`: ID để track job
- `task_id`: ID của task từ PiAPI

#### Action 2: Kling - Poll Status

```
Node: Kiểm Tra Trạng Thái
Job ID: {{node#create_job.job_id}}
Task ID: {{node#create_job.task_id}}
API Key: {{global.kling_api_key}}
Poll Delay: 10
Max Wait: 600
```

**Chức năng:**
- Tự động poll status mỗi 10 giây
- Timeout sau 10 phút
- Kết thúc khi status = succeeded/failed

#### Action 3: Kling - Fetch Video

```
Node: Tải Video
Job ID: {{node#create_job.job_id}}
Mode: media (hoặc r2)
Filename: kling-{{timestamp}}.mp4
Timeout: 300
```

**Output:**
- `attachment_id`: ID của video trong Media Library
- `media_url`: URL của video
- `r2_url`: URL trên R2 (nếu mode=r2)

### 2. Models Khả Dụng

| Model | Mô tả | Max Duration |
|-------|-------|--------------|
| `kling-v1` | Kling AI v1 | 30s |
| `kling-v1.5` | Kling AI v1.5 Pro | 30s |
| `veo` | Google Veo | 30s |

### 3. Task Types

- `text_to_video`: Tạo video từ text prompt
- `image_to_video`: Tạo video từ ảnh (⭐ Focus)

### 4. Aspect Ratios

- `16:9`: Landscape (YouTube)
- `9:16`: Portrait (TikTok, Instagram) ⭐
- `1:1`: Square (Facebook)

## 🔧 API Reference

### Helper Functions

#### `waic_kling_create_task($settings)`

Tạo task tạo video.

**Parameters:**
```php
$settings = [
    'api_key' => 'sk-xxx',
    'endpoint' => 'https://api.piapi.ai/api/v1',
    'model' => 'kling-v1',
    'task_type' => 'image_to_video',
    'image_url' => 'https://example.com/image.jpg',
    'prompt' => 'A dynamic video of...',
    'duration' => 30,
    'aspect_ratio' => '9:16',
];
```

**Returns:**
```php
[
    'ok' => true,
    'task_id' => 'xxx',
    'data' => [...],
]
```

#### `waic_kling_get_task($task_id, $settings)`

Lấy trạng thái task.

**Returns:**
```php
[
    'ok' => true,
    'status' => 'succeeded',
    'video_url' => 'https://...',
    'data' => [...],
]
```

#### `waic_kling_download_video_to_media($url, $filename)`

Tải video về Media Library.

**Returns:**
```php
[
    'ok' => true,
    'attachment_id' => 123,
    'media_url' => 'https://example.com/wp-content/uploads/...',
]
```

#### `waic_kling_upload_video_to_r2($url, $filename)`

Upload video lên R2.

**Returns:**
```php
[
    'ok' => true,
    'r2_url' => 'https://r2.example.com/...',
]
```

### Hooks

#### Actions

```php
// Video đã tải về thành công
do_action('waic_kling_video_downloaded', $job_id, $job_data, $attachment_id);

// Video hoàn thành
do_action('waic_kling_video_completed', $job_id, $job_data);

// Video thất bại
do_action('waic_kling_video_failed', $job_id, $job_data, $error);

// Video timeout
do_action('waic_kling_video_timeout', $job_id, $job_data);
```

#### Filters

```php
// Filter video URL trước khi download
$video_url = apply_filters('waic_kling_video_url', $video_url, $job_data);

// Filter filename trước khi lưu
$filename = apply_filters('waic_kling_video_filename', $filename, $job_data);
```

## 📊 Log System

Plugin tự động log các hoạt động:

```php
waic_kling_log('event_type', [
    'job_id' => 'xxx',
    'status' => 'succeeded',
    // ...
]);
```

Xem log tại: `WP_CONTENT_DIR/bizcity-video-kling.log`

## 🎬 Ví Dụ Use Case

### Tạo Video Social Media Từ Sản Phẩm

```php
// 1. Upload ảnh sản phẩm lên Media Library
$product_image = 'https://example.com/products/shoes.jpg';

// 2. Tạo workflow với prompt
$prompt = "Zoom in on the shoes, rotate 360 degrees, elegant movement";

// 3. Sử dụng 3 workflow actions
// → Kết quả: Video 9:16, 30s cho Instagram Reels
```

### Tạo Video Review Tự Động

```php
// Khi user đánh giá sản phẩm
add_action('woocommerce_new_review', function($review_id) {
    $review = get_comment($review_id);
    $product = wc_get_product($review->comment_post_ID);
    
    // Trigger workflow tạo video từ ảnh sản phẩm
    // với prompt từ nội dung review
});
```

## 🔒 Bảo Mật

- API Key được lưu trong options table (đã sanitize)
- Chỉ admin (manage_options) mới truy cập được settings
- Validate tất cả input từ user
- Nonce check cho AJAX requests

## 📚 Tài Liệu Tham Khảo

- [PiAPI Documentation](https://piapi.ai/docs/overview)
- [Kling AI](https://klingai.com)
- [WAIC Workflow System](https://example.com/waic-docs)

## 📝 Changelog

### Version 1.0.0
- ✅ Initial release
- ✅ Support Kling v1, v1.5, Veo models
- ✅ Image to Video
- ✅ WAIC Workflow integration
- ✅ Media Library + R2 upload
- ✅ Async polling system

## 👨‍💻 Support

Liên hệ: support@bizcity.vn

---

Made with ❤️ by BizCity Team
