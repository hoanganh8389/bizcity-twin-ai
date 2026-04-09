---
title: Quy trình sản xuất Video AI
category: video
modes: [execution]
status: active
priority: 80
triggers: [tạo video, làm video, create video, video AI, video review, sản xuất video, quay video AI]
tools: [video_create_script, video_create_job, video_poll_status, video_fetch, video_post_production]
slash_commands: [/video]

blocks:
  video_create_script: it_call_content
  video_create_job: it_call_tool
  video_poll_status: it_call_tool
  video_fetch: it_call_tool
  video_post_production: it_call_tool

chain:
  - step: 2
    from: { step: 1, fields: [script_id, content] }
  - step: 3
    from: { step: 2, fields: [job_id] }
  - step: 4
    from: { step: 2, fields: [job_id] }
  - step: 5
    from: { step: 4, fields: [file_path] }
  - step: 5
    from: { step: 1, fields: [content], rename_content: tts_text }

skip_research: true
skip_planner: true
skip_memory: false
skip_reflection: false
---

## Quy trình

1. Viết kịch bản video từ ý tưởng người dùng @video_create_script
2. Gửi kịch bản để tạo job AI video @video_create_job
3. Kiểm tra trạng thái hoàn thành video @video_poll_status
4. Download video đã hoàn thành @video_fetch
5. Hậu kỳ: thêm voiceover + nhạc nền @video_post_production

## ❗ Guardrails
- ❌ KHÔNG skip bước poll — video chưa xong thì không download được
- ❌ KHÔNG thay đổi thứ tự các bước
- ✅ image_url bắt buộc cho bước 2 — user phải cung cấp
