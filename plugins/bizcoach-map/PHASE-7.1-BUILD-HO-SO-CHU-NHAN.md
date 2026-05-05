# PHASE 7.1 — Build Hồ Sơ Chủ Nhân

> Plugin: bizcoach-map  
> Workspace: bizcity-twin-ai  
> Date: 2026-04-15  
> Status: Active planning and UX consolidation  
> Scope: Thu gọn flow BizCoach Map về giai đoạn nền tảng hồ sơ chủ nhân, chuẩn bị để tích hợp sâu hơn vào Twin Core.

---

## 1. Mục tiêu của Phase 7.1

Phase 7.1 không xem BizCoach Map là một workflow 6 bước độc lập nữa. Thay vào đó, plugin được tái định vị thành lớp:

1. Định danh chủ nhân.
2. Chuẩn hóa hồ sơ nền tảng.
3. Tạo context identity để Twin AI và các module khác dùng chung.

Tên phase mới:

**PHASE 7.1 — Build hồ sơ chủ nhân**

Trọng tâm UI ở giai đoạn này chỉ còn:

1. Bước 1: Hồ sơ và chiêm tinh.
2. Bước 2: Coach template và câu hỏi nền.

Các bước từ 3 trở đi vẫn còn trong codebase, nhưng được chuyển sang trạng thái hidden để tránh làm flow chính bị phình quá sớm.

---

## 2. Đánh giá hiện trạng đã có

### 2.1 Những phần đã hình thành tốt

BizCoach Map hiện đã có đủ khung cho một identity module nghiêm túc:

1. Có hồ sơ coachee theo `user_id` và `platform_type`, phù hợp cho multisite và đa kênh.
2. Có lớp dữ liệu chiêm tinh riêng (`bccm_astro`) và đã chuẩn hóa nguyên tắc tra cứu bằng `user_id`.
3. Có step-based admin flow đủ để tạo profile, coach template, character, success plan, life map, reminders.
4. Đã bridge được sang `bizcity-knowledge` để tạo AI character ở step 3.
5. Đã có progress logic (`class-nobi-float.php`) để đo mức hoàn thiện hồ sơ.
6. Đã có frontend onboarding panel và admin dashboard riêng.

### 2.2 Những điểm đang bị phình hoặc lệch trọng tâm

Từ góc nhìn Twin Core, BizCoach Map hiện đang ôm quá nhiều vai trò cùng lúc:

1. Vừa là profile builder.
2. Vừa là character creator.
3. Vừa là life-plan generator.
4. Vừa là reminder scheduler.
5. Vừa là coaching dashboard.

Hệ quả:

1. User flow chính bị dài và nặng quá sớm.
2. Identity layer chưa tách bạch khỏi execution layer.
3. Các bước 3-6 phụ thuộc plugin khác, nên UX dễ vỡ nếu hệ phụ trợ chưa sẵn sàng.
4. Twin Core chưa thật sự dùng BizCoach Map như một canonical identity provider ở mọi nơi.

---

## 3. Quyết định của Phase 7.1

### 3.1 Giảm scope hiển thị

Trong admin UX mặc định:

1. Chỉ hiển thị Step 1 và Step 2 trong workflow guideline.
2. Ẩn submenu từ Step 3 trở đi khỏi menu chính.
3. Ẩn node admin bar `bccm-lifemap` để tránh đẩy user vào flow nâng cao quá sớm.

### 3.2 Giữ code, ẩn navigation

Phase 7.1 không xóa Step 3-6. Các page vẫn được giữ làm hidden pages để:

1. Không làm gãy direct URL cũ.
2. Dev vẫn test được.
3. Có thể re-open từng bước sau khi Twin Core và các plugin liên quan đủ chín.

### 3.3 Reframe vai trò plugin

BizCoach Map trong giai đoạn này được xem là:

**Identity and owner-profile foundation for Twin AI**

Không phải là một coaching suite hoàn chỉnh ở ngay entry point.

---

## 4. Kiến trúc đề xuất sau khi tái định vị

## 4.1 Vai trò của BizCoach Map trong Twin AI

BizCoach Map nên trở thành module cung cấp 4 lớp identity cốt lõi:

1. Hồ sơ chủ nhân: họ tên, ngày sinh, nền tảng, role, trạng thái hoàn thiện.
2. Hồ sơ chiêm tinh: western, vedic, transit-ready identity context.
3. Hồ sơ coaching nền: coach_type, answer_json, motivation, key themes.
4. Hồ sơ đồng hành: readiness để gắn character, plan, reminder về sau.

## 4.2 Ranh giới với Twin Core

Theo PHASE-0-RULES, context phải chảy downstream. Vì vậy BizCoach Map không nên tự ôm mọi trải nghiệm cuối cùng. Nó nên phát dữ liệu identity cho:

1. Twin Context Resolver.
2. Memory layers.
3. Intent Engine.
4. Pipeline blocks downstream.

Nói cách khác:

- BizCoach Map là nơi **khai sinh hồ sơ chủ nhân**.
- Twin Core là nơi **quyết định khi nào và ở mức nào inject hồ sơ đó vào AI runtime**.

---

## 5. Liên kết với các module và plugin khác

## 5.1 Với `bizcity-knowledge`

Hiện đã có bridge ở Step 3 để tạo character. Tương lai nên tách thành 2 lớp:

1. BizCoach Map chỉ sinh ra `character seed profile`.
2. `bizcity-knowledge` là nơi canonical tạo, chỉnh sửa, versioning và lifecycle của character.

Hướng này giúp BizCoach Map không phải gánh vai trò character CMS.

## 5.2 Với `core/memory`

Identity của chủ nhân nên trở thành một nguồn context chuẩn cho memory system:

1. Session memory có thể biết người này là ai.
2. Task memory có thể biết phong cách coaching, archetype, hay life themes.
3. Memory Specs có thể tham chiếu hồ sơ chủ nhân như một durable profile layer.

## 5.3 Với `core/skills`

Coach template và answer_json có thể tiến hóa thành skill inputs hoặc skill presets:

1. Mapping `coach_type` -> suggested skills.
2. Mapping answer clusters -> recommended pipelines.
3. Dùng profile để prefill slot cho planner hoặc tool wrapper.

## 5.4 Với `bizcity-companion-notebook`

Notebook có thể trở thành workspace làm việc của chủ nhân:

1. Lưu kế hoạch sống, reflection, insight cá nhân.
2. Gắn nguồn tài liệu dài hạn theo từng coachee.
3. Sync output từ BizCoach Map sang notebook projects.

## 5.5 Với `bizcity-content-creator`

Khi hồ sơ chủ nhân đủ mạnh, content creator có thể dùng nó làm source cho:

1. Content pillar cá nhân.
2. Storytelling theo identity.
3. Kịch bản phát triển thương hiệu cá nhân.

## 5.6 Với `bizcity-tool-image`

Phase sau có thể dùng hồ sơ chủ nhân để:

1. Sinh portrait prompt cá nhân hóa.
2. Sinh visual identity kit.
3. Tạo card hành trình, avatar, profile visuals.

## 5.7 Với scheduler / reminders

Reminder không nên là UX mặc định ở Phase 7.1. Nhưng về lâu dài nó là execution layer tự nhiên của hồ sơ chủ nhân:

1. Nhắc theo plan.
2. Nhắc theo transit window.
3. Nhắc theo milestone progress.

Khi đó scheduler là downstream executor, không phải entry point.

---

## 6. Roadmap đề xuất

## 6.1 Phase 7.1A — Thu gọn và chuẩn hóa identity

Mục tiêu:

1. Giữ visible flow ở Step 1-2.
2. Chuẩn hóa dữ liệu profile/coaching answers.
3. Bỏ bớt lối vào UI gây nhiễu như submenu step 3-6 và admin bar lifemap.

Deliverables:

1. Hidden pages cho step 3-6.
2. Workflow guideline chỉ còn step 1-2.
3. Tài liệu phase mới.

## 6.2 Phase 7.1B — Identity API hóa

Mục tiêu:

1. Expose owner profile qua service layer/API nội bộ.
2. Twin Context Resolver gọi được profile theo chuẩn.
3. Chuẩn hóa `coachee profile snapshot` để inject downstream.

Deliverables:

1. `bccm_get_owner_profile_snapshot()` hoặc service class tương đương.
2. Contract rõ giữa BizCoach Map và Twin Core.
3. Unit boundaries rõ: data provider vs experience UI.

## 6.3 Phase 7.1C — Character seed, không phải character CMS

Mục tiêu:

1. Step 3 chỉ còn là optional bridge.
2. Character canonical lifecycle thuộc `bizcity-knowledge`.
3. BizCoach Map chỉ gửi seed context.

## 6.4 Phase 7.2 — Build hồ sơ chủ nhân đa chiều

Mục tiêu:

1. Hợp nhất hồ sơ numerology, astrology, coaching, life goals.
2. Tạo owner identity graph cho Twin Core.
3. Tự động sinh context profile theo mode và topic.

## 6.5 Phase 7.3 — Chủ nhân hóa toàn bộ Twin AI

Mục tiêu:

1. Mọi workshop đều có thể đọc hồ sơ chủ nhân.
2. Planner và tools có thể personalize sâu mà không cần user nhập lại.
3. Notebook, content, image, reminders và companion đều dùng chung identity backbone.

---

## 7. Nguyên tắc implementation cho các phase sau

1. BizCoach Map là identity provider trước, feature bundle sau.
2. Không nhồi thêm UI nặng vào entry flow nếu chưa phục vụ trực tiếp Step 1-2.
3. Mọi tích hợp với Twin Core phải đi qua service boundary rõ ràng.
4. Character, reminder, success plan là downstream modules, không phải core onboarding state.
5. Không duplicate canonical data giữa BizCoach Map, Knowledge, Memory và Notebook nếu đã có owner ở nơi khác.

---

## 8. Kết luận

Phase 7.1 đánh dấu việc chuyển BizCoach Map từ một plugin onboarding nhiều bước sang một module nền tảng:

**Build hồ sơ chủ nhân**

Đây là lớp đầu vào cho identity của Twin AI. Khi lớp này sạch, gọn và ổn định, các module khác như Knowledge, Memory, Notebook, Skills, Content Creator, Image Studio và Scheduler mới có thể phối hợp mượt mà với nhau.

Ưu tiên hiện tại không phải mở thêm bước, mà là:

1. Làm hồ sơ chủ nhân đủ tốt.
2. Làm contract với Twin Core đủ rõ.
3. Mở dần các bước 3+ khi downstream modules thật sự sẵn sàng.
