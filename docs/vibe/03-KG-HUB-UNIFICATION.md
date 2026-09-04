# 03 — KG Hub Unification: Mọi dòng chảy tri thức đều hội tụ về 1 não

> **Status:** ✅ CANON HIỆN HÀNH — tổng hợp lại
> [PHASE-0-RULE-KG-HUB-CONTRACT.md](../rules/PHASE-0-RULE-KG-HUB-CONTRACT.md) và
> [PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md)
> áp dụng riêng cho ranh giới plugin.

> ⚠️ **GAP THẬT (audit code 2026-08-29 — xem
> [13-GAP-CRITIQUE-READINESS-REVIEW.md](13-GAP-CRITIQUE-READINESS-REVIEW.md) §3):**
> KG Hub hôm nay **KHÔNG phải trung tâm tự động**. Ingestion là reactive
> theo thao tác thủ công của user (`attach_source()` — user tự chọn nguồn
> gắn vào notebook). Không có bằng chứng nào cho việc CRM/Automation tự
> động đẩy dữ liệu vào `bizcity_kg_*`. Song song đó, `bizcity_webchat_*`/
> `bizcity_crm_*` vẫn là kho SQL đang hoạt động độc lập với KG Hub (xem
> [PHASE-1.29 §9.1](../roadmaps/PHASE-1.29-PLUGIN-TIER-EXTENSIONS-LOG-CANON.md)).
> Đây là định hướng đúng, chưa phải sự thật kỹ thuật — xem Wave 9 trong
> [12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md](12-ROADMAP-CLOSED-LOOP-VIBE-CODING.md).

---

## 1. Phát biểu gốc

> *"Mỗi 1 dòng channel sẽ nguồn, file, đều sẽ được lưu về và đẩy vào KG Hub.
> Tất cả dòng chảy, rule unify cuối cùng đều phải về KG Hub."*

KG Hub (`core/knowledge/kg-hub`) là **lớp tri thức duy nhất** của toàn nền
tảng — không phải một tính năng của một plugin, mà là hạ tầng dùng chung mà
mọi plugin đóng góp vào và đọc ra. "KG Hub giữ phần não, plugin chỉ bổ sung
chuyên môn" (xem [00-VIBE-CANON.md](00-VIBE-CANON.md) §2).

## 2. Pipeline hội tụ

```mermaid
flowchart LR
    A["Channel (Zalo/FB/Google/POS...)"] -->|raw payload| B["Channel Gateway<br/>normalize envelope"]
    B -->|identity + zone| C["CRM / Automation<br/>(nếu là dữ liệu khách hàng)"]
    B -->|file/tài liệu| D["Plugin KG Source Adapter<br/>(ingestion facade)"]
    C -->|evidence cần lưu tri thức| D
    D -->|chunks/passages/entities| KG["KG Hub<br/>ingest → embed → graph"]
    KG -->|graph_vector_rerank_pack<br/>(R-TWEB-20)| TB[TwinBrain Runtime]
    TB -->|citation [nb:X/pY]| OUT[Final Composer]
```

Mọi "nguồn" — dù là file người dùng tải lên, dữ liệu trả về từ 1 kênh
(Zalo/FB/Google), hay kết quả tra cứu nội bộ của 1 plugin (đơn hàng Woo, hồ
sơ CRM) — nếu cần dùng để trả lời câu hỏi tương lai, phải đi qua **KG Source
Adapter facade** để nạp vào KG Hub, không được giữ làm "kho tri thức riêng"
của plugin.

## 3. KG Source Adapter — cửa ngõ duy nhất

```php
interface BizCity_KG_Source_Adapter_Interface {
    // Xem định nghĩa đầy đủ tại core/twin-core/contracts/content-contracts.php
    public function source_id(): string;
    public function fetch_candidates( array $args ): array;   // trả về passage thô
    public function provenance(): array;                       // nguồn gốc, để KG Hub gắn citation
}
```

Plugin implement interface này, đăng ký qua `register_source()` (xem
[07-PLUGIN-SDK-PUBLIC-INTERFACES.md](07-PLUGIN-SDK-PUBLIC-INTERFACES.md)).
KG Hub sẽ tự lo: chunk hoá, embedding, entity extraction, graph edge, citation
mapping. Plugin KHÔNG tự làm các bước này.

## 4. Cấm truy cập trực tiếp bảng KG

Theo [PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md)
và [PHASE-0-RULE-KG-HUB-CONTRACT.md](../rules/PHASE-0-RULE-KG-HUB-CONTRACT.md):

- ❌ Plugin KHÔNG được `$wpdb->query()` trực tiếp vào `bizcity_kg_*`.
- ❌ Plugin KHÔNG được tự cài đặt vector search/embedding riêng.
- ✅ Mọi đọc/ghi đi qua Facade/Service đã công bố (KG Hub API, không phải
  class nội bộ `includes/`).

## 5. "Rule unify cuối cùng" nghĩa là gì

Có nhiều lớp trung gian (Channel Gateway, CRM, Automation, filestore JSONL của
từng plugin — xem [02](02-FILESTORE-LOG-INDEX-STANDARD.md)), nhưng **lớp nào
cũng chỉ là trạm trung chuyển**, không phải điểm dừng cuối của tri thức. Nếu
một dữ liệu có giá trị tri thức lâu dài (không chỉ là log audit tạm thời), nó
phải có đường đi rõ ràng tới KG Hub — nếu không, đó là một "silo" cần được
review theo đúng câu hỏi tự vấn của R-ENTERPRISE-BRAIN-DIRECTION: *"thay đổi
này củng cố trục ngang, trục dọc và lớp KG như thế nào?"*

## 6. Evidence pack chuẩn khi TwinBrain đọc lại KG Hub

Khi TwinBrain Runtime cần trả lời, nó không tự chọn "1 chunk hay nhất" — nó
đi qua contract W0.20 (R-TWEB-20, xem
[TWINBRAIN-EXT-VERTICAL-CHAT-DEFAULT-ROADMAP.md](../../core/twinbrain/docs/TWINBRAIN-EXT-VERTICAL-CHAT-DEFAULT-ROADMAP.md)):

```text
graph_entities[] → retrieval_candidates[] (top30) → rerank → final_context_chunks[] (top5-8)
```

Plugin đóng góp vào `graph_entities`/`retrieval_candidates` qua KG Source
Adapter — plugin KHÔNG tự rerank hay tự chọn top chunk để "ép" Final Composer
dùng nguồn của mình.

## 7. Anti-pattern CẤM

- ❌ Plugin tạo bảng `wp_myplugin_embeddings`/`wp_myplugin_vectors` riêng.
- ❌ Plugin cache toàn bộ kết quả tra cứu vào transient rồi tự "prompt inject"
  thẳng vào system prompt của Final Composer, bỏ qua Source Layer/Reflection.
- ❌ Plugin coi dữ liệu filestore JSONL của chính nó (mục 02) là "kho tri
  thức" thay thế KG Hub — filestore JSONL chỉ là **bản ghi vận hành/nghiệp
  vụ**, không phải retrieval index.

## 8. Tham chiếu

- [PHASE-0-RULE-KG-HUB-CONTRACT.md](../rules/PHASE-0-RULE-KG-HUB-CONTRACT.md)
- [PHASE-0-RULE-BRAIN-UNIFICATION.md](../rules/PHASE-0-RULE-BRAIN-UNIFICATION.md)
- [PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md](../rules/PHASE-0-RULE-ENTERPRISE-BRAIN-DIRECTION.md)
- `core/twin-core/contracts/content-contracts.php` (`BizCity_KG_Source_Adapter_Interface`)
