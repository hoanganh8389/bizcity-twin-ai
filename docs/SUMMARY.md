# Table of Contents

## Giới thiệu

* [Tổng quan BizCity Twin AI](README.md)

## 🚀 Bắt đầu

* [Cài đặt nhanh (5 phút)](getting-started/quick-install.md)
* [Cài đặt chi tiết — Người dùng không cần lập trình](getting-started/install-user-guide.md)
* [Kết nối BizCity API Key](getting-started/api-key.md)
* [Kiểm tra hoạt động lần đầu](getting-started/verify.md)

## 🤖 TwinBrain — Trợ lý AI Cá nhân

* [Giới thiệu TwinBrain](twinbrain/overview.md)
* [Các chế độ AI](twinbrain/modes.md)
  * [Research — Nghiên cứu chuyên sâu](twinbrain/modes/research.md)
  * [Creative — Sáng tạo nội dung](twinbrain/modes/creative.md)
  * [Expert — Chuyên gia tư vấn](twinbrain/modes/expert.md)
  * [Astro / Numerology](twinbrain/modes/astro.md)
* [Lịch sử hội thoại (Sessions)](twinbrain/sessions.md)
* [Web Search tích hợp](twinbrain/web-search.md)
* [Cài đặt TwinBrain](twinbrain/settings.md)

## 💬 TwinChat — Giao diện Chat

* [Giới thiệu TwinChat](twinchat/overview.md)
* [Webchat Widget — Nhúng vào website](twinchat/webchat.md)
* [Notebook — Làm việc với tài liệu](twinchat/notebook.md)
* [Tuỳ chỉnh giao diện](twinchat/customization.md)
* [Cài đặt TwinChat](twinchat/settings.md)

## 📚 Knowledge Base — Kho Kiến Thức

* [Giới thiệu Knowledge Base (KG Hub)](knowledge/overview.md)
* [Thêm nguồn kiến thức](knowledge/add-sources.md)
  * [Nhúng Website / URL](knowledge/sources/website.md)
  * [Upload PDF / Word / Excel](knowledge/sources/document.md)
  * [YouTube & Video](knowledge/sources/video.md)
  * [Google Drive](knowledge/sources/gdrive.md)
  * [Văn bản thủ công](knowledge/sources/manual.md)
* [Tìm kiếm & RAG](knowledge/search.md)
* [Graph RAG — Kiến thức liên kết](knowledge/graph-rag.md)
* [Quản lý & cập nhật nguồn](knowledge/manage.md)

## 🔄 Automation — Tự Động Hoá

* [Giới thiệu Automation Workflow](automation/overview.md)
* [Tạo workflow đầu tiên](automation/quickstart.md)
* [Triggers — Kích hoạt tự động](automation/triggers.md)
  * [Nhận tin nhắn từ Zalo / Facebook](automation/triggers/channel-message.md)
  * [Lịch (Cron / Schedule)](automation/triggers/schedule.md)
  * [Sự kiện CRM](automation/triggers/crm-event.md)
* [Conditions — Điều kiện](automation/conditions.md)
* [Actions — Hành động](automation/actions.md)
  * [Gửi tin nhắn](automation/actions/send-message.md)
  * [Đăng bài Facebook](automation/actions/publish-fb.md)
  * [Đăng bài WordPress](automation/actions/publish-wp.md)
  * [Đặt lịch nhắc nhở](automation/actions/schedule.md)
  * [Tạo sự kiện CRM](automation/actions/crm-event.md)
  * [Gọi AI (TwinBrain)](automation/actions/call-ai.md)
* [Ví dụ workflow thực tế](automation/examples.md)

## 📡 Channel Gateway — Kênh Giao Tiếp

* [Tổng quan Channel Gateway](channels/overview.md)
* [Facebook Messenger](channels/facebook.md)
  * [Kết nối Facebook Page](channels/facebook/connect.md)
  * [Xử lý tin nhắn](channels/facebook/messages.md)
* [Zalo OA (Official Account)](channels/zalo-oa.md)
  * [Đăng ký Zalo OA](channels/zalo-oa/register.md)
  * [Kết nối OA với BizCity](channels/zalo-oa/connect.md)
* [Zalo Cá nhân](channels/zalo-personal/overview.md)
  * [⚠️ Cảnh báo rủi ro](channels/zalo-personal/risk-warning.md)
  * [Cài đặt zca-bridge (sidecar)](channels/zalo-personal/setup-bridge.md)
  * [Đăng nhập QR Code](channels/zalo-personal/login-qr.md)
  * [Đăng nhập Cookie (nâng cao)](channels/zalo-personal/login-cookie.md)
  * [Xác minh luồng nhận tin](channels/zalo-personal/verify-inbound.md)
  * [Xử lý sự cố](channels/zalo-personal/troubleshooting.md)
* [Telegram](channels/telegram.md)
* [WebChat — Nhúng chat vào website](channels/webchat.md)
* [CRM Inbox — Hộp thư hợp nhất](channels/crm-inbox.md)

## 📅 Scheduler — Lịch Hẹn & Nhắc Nhở

* [Giới thiệu Scheduler](scheduler/overview.md)
* [Tạo & quản lý sự kiện](scheduler/events.md)
* [Nhắc nhở tự động qua Zalo / Facebook](scheduler/reminders.md)
* [Đồng bộ Google Calendar](scheduler/google-calendar.md)

## 🧠 Skills — Kỹ Năng AI

* [Giới thiệu Skills](skills/overview.md)
* [Danh mục Skills có sẵn](skills/catalog.md)
* [Tạo Skill tùy chỉnh](skills/custom.md)
* [Skill trong Automation](skills/in-automation.md)

## 👤 Persona — Nhân Cách AI

* [Giới thiệu Persona](persona/overview.md)
* [Tạo & tuỳ chỉnh Persona](persona/create.md)
* [Liên kết Persona với Knowledge Base](persona/link-knowledge.md)

## 🛒 Membership & Billing

* [Giới thiệu Membership](membership/overview.md)
* [Quản lý người dùng & gói dịch vụ](membership/manage.md)

## 🔍 Diagnostics — Chẩn Đoán

* [Trang Diagnostics là gì?](diagnostics/overview.md)
* [Các Probe & badge PASS / FAIL](diagnostics/probes.md)
* [Lỗi phổ biến & cách xử lý](diagnostics/common-errors.md)
* [Schema Changelog](diagnostics/schema-changelog.md)

## 🔧 Dành cho Developer

* [Tổng quan kiến trúc](developer/overview.md)
* [Getting Started (Dev)](developer/getting-started.md)
* [Cấu trúc module](developer/module-structure.md)
* [API Reference](developer/api/README.md)
  * [REST API](developer/api/rest-api.md)
  * [Actions (Hooks)](developer/api/actions.md)
  * [Filters](developer/api/filters.md)
  * [Classes & Interfaces](developer/api/classes.md)
* [Tạo sub-plugin](developer/extending/sub-plugin.md)
* [Tạo Agent Tool](developer/extending/agent-tool.md)
* [Tạo Automation Block](developer/extending/automation-block.md)
* [R-GW-8: Client Standalone (quan trọng)](developer/rules/gateway-standalone.md)
* [PHP 7.4 Compatibility](developer/rules/php74-compat.md)
* [Conventions & Coding Rules](developer/rules/overview.md)

## ❓ FAQ & Hỗ Trợ

* [Câu hỏi thường gặp](faq.md)
* [Xử lý sự cố chung](troubleshooting.md)
* [Liên hệ hỗ trợ](support.md)
