# Changelog

All notable changes to BizCity Video Kling will be documented in this file.

## [1.0.0] - 2025-01-25

### Added
- ✅ Initial release
- ✅ Integration với PiAPI Gateway (https://api.piapi.ai)
- ✅ Support Kling AI models:
  - kling-v1 (max 30s)
  - kling-v1.5 (max 30s) 
  - veo (Google Veo, max 30s)
- ✅ Image to Video generation
- ✅ WAIC Workflow System integration với 3 workflow actions:
  - `kl_create_job`: Tạo video generation task
  - `kl_poll_status`: Async polling với WP-Cron
  - `kl_fetch_video`: Download video về WordPress
- ✅ WordPress Media Library integration
- ✅ R2 Cloud Storage support
- ✅ Admin Settings Page tại Tools → Video Kling
- ✅ Test API connection button
- ✅ Comprehensive logging system
- ✅ Focus: 9:16 aspect ratio cho social media (TikTok, Instagram Reels, YouTube Shorts)
- ✅ Configurable settings:
  - API Key management
  - Default model selection
  - Default duration (5-30s)
  - Default aspect ratio (16:9, 9:16, 1:1)
- ✅ Helper functions library:
  - `waic_kling_create_task()`
  - `waic_kling_get_task()`
  - `waic_kling_download_video_to_media()`
  - `waic_kling_upload_video_to_r2()`
  - `waic_kling_log()`
  - `waic_kling_job_key()`
- ✅ WordPress hooks:
  - `waic_kling_video_completed`
  - `waic_kling_video_failed`
  - `waic_kling_video_timeout`
  - `waic_kling_video_downloaded`
  - `waic_kling_poll_event` (cron)
- ✅ Complete documentation:
  - README.md với examples
  - API reference
  - Use cases
  - Security guidelines
- ✅ Admin UI với responsive design

### Security
- API Key encryption trong WordPress options
- Nonce verification cho AJAX requests
- Capability checks (manage_options)
- Input sanitization
- File upload validation

### Developer
- PSR-4 autoloading structure
- Object-oriented design với WaicAction base class
- Extensible via WordPress hooks
- Comprehensive error handling
- Debug logging system

---

## Upcoming Features

### [1.1.0] - Planned
- [ ] Text to Video support
- [ ] Batch video generation
- [ ] Queue management UI
- [ ] Video preview trong admin
- [ ] Custom webhook integration
- [ ] Advanced scheduling options

### [1.2.0] - Planned
- [ ] Template system cho common use cases
- [ ] AI prompt generator
- [ ] Multi-language support
- [ ] Analytics dashboard
- [ ] Video library browser
- [ ] Direct social media posting

### [2.0.0] - Future
- [ ] Support thêm AI models (Runway, Pika, etc.)
- [ ] Advanced editing capabilities
- [ ] Collaborative workflow
- [ ] API rate limiting & optimization
- [ ] CDN integration
- [ ] Performance monitoring

---

## Version Format

Format: `[MAJOR.MINOR.PATCH]`

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

---

## Links

- [PiAPI Documentation](https://piapi.ai/docs/overview)
- [Kling AI](https://klingai.com)
- [Support](mailto:support@bizcity.vn)
