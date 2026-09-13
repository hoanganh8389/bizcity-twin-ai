# BizCity Twin Extension Storage & Context Contract v1

> **Contract:** `extension-storage-context@1.0.0`
> **Status:** Public contract proposal for catalog v1.x adoption; schema and
> fixtures are added, runtime adoption remains separate evidence.
> **Directive:** Johnny Chu - Chu Hoàng Anh · 2026-09-13
> **Scope:** Every new or migrated `core/`, `modules/`, plugin, private utility
> or vertical extension that creates/owns data used by operations, Context Bank,
> KG-Hub, MPR/TwinBrain or Brain Chat.
> **Authority:** R-CONTEXT-BANK, R-DATA-STORAGE, R-FILESTORE-BUSINESS,
> R-LOG-HYBRID, R-MPRT, PHASE-1.22A and PHASE-1.33.

---

## 1. Purpose

This contract prevents extensions from treating SQL tables as the default
answer to every data problem. Before an extension creates a table, file, option,
user meta, CPT, repository, event projection or knowledge artifact, it must
choose the smallest canonical storage that preserves correctness and declares how
that data participates in the shared Enterprise Brain spine.

The contract enforces four storage levels:

```text
1. Trace/log data
   -> contracted encrypted/registered JSONL filestore + optional pointer index

2. Important reusable business/context data
   -> encrypted Business JSONL File Store under a registered Context Bank contract
   -> receipt -> tenant pointer ledger -> rollup/retrieval pack

3. Small/low-volume data
   -> existing CPT / option / site option / user meta / existing repository
   -> only when the owner and scope match

4. High-volume / atomic / relational / hot-path data
   -> typed tenant SQL or existing canonical repository
   -> thin payload/index where appropriate
   -> mandatory Context Bank adapter or explicit no-context decision
   -> bounded MPR retrieval bridge when the data is reusable evidence
```

The contract does **not** say every table must be copied into Context Bank. It
requires an explicit decision: `context_bank_role=none` is valid only when the
data cannot be reused as enterprise context, or when the canonical owner already
provides an equivalent governed projection.

---

## 2. Non-negotiable rules

### 2.1 Log/trace records

Operational trace, audit evidence and trace-only usage telemetry must use the
canonical JSONL logger contract. They must not create a new SQL payload table.

Required:

- registered `BizCity_Log_Contract_Registry` entry;
- tenant/scope-aware folder and retention policy;
- redaction policy;
- optional `bizcity_log_index` pointer only, never payload body;
- one shared Log Explorer/read surface;
- `event_uuid`, trace/correlation ID and reason bucket;
- runtime probe for write/read/retention/redaction.

Log rows do not automatically become Context Bank records or KG facts. A bounded
reference may be admitted only when it supports a business/context correlation.

### 2.2 Important context/business records

Durable records that can be reused by Memory, KG-Hub or MPR must use the encrypted
business filestore contract unless they are already owned by an existing
canonical Event Stream or repository.

Required order:

```text
classify data
  -> register file contract
  -> write encrypted JSONL record
  -> receive lock-captured receipt
  -> admit metadata/pointer to bizcity_context_bank
  -> define rollup or explicit no-rollup decision
  -> expose only bounded context-retrieval-pack
  -> allow KG candidate promotion through KG-Hub owner
```

The Context Bank SQL table stores pointer/correlation/provenance metadata only:
`record_id`, contract/version, identity/entity scope, hashes, lifecycle, event
and trace references, verified file location metadata and retrieval status. It
never stores full message/document/memory/rule body, decrypted payload or
embedding.

### 2.3 Small data

Prefer an existing canonical CPT, option, site option, user meta or repository
when all of the following are true:

- low volume and low write frequency;
- no high-cardinality relation/edge/query requirement;
- no atomic lock/counter/queue requirement;
- payload is small and bounded;
- scope matches the chosen primitive;
- the owner already has a stable reader/writer contract;
- retention, privacy and rollback are clear.

Do not create a CPT merely to avoid a table. CPT is not valid for logs, traces,
locks, counters, queues, graph edges, hot memory or high-frequency workflow rows.
Do not use options/usermeta for large JSON payloads, transcripts, work queues or
relational lists.

### 2.4 New SQL tables

A new typed tenant SQL table is allowed only when the decision record proves one
or more of:

- atomic correctness or unique claim is required;
- hot filtered/range/aggregate query is required;
- relational edge/junction semantics are required;
- live workflow state cannot tolerate eventual consistency;
- queue, lock, counter or billing/financial semantics require SQL primitives;
- the data is too large/high-volume for a CPT/option/usermeta and cannot be a
  folded filestore record.

A new SQL table must also declare:

- canonical owner and repository;
- R-DCL changelog + Schema Registry + Site Provisioner path;
- cache contract and invalidation;
- Context Bank role: producer/consumer/none;
- payload fields that remain SQL versus fields that remain filestore;
- rollup ID/window or explicit no-rollup rationale;
- MPR/retrieval-pack bridge if reusable by Brain Chat;
- correction/rebuild/retention/rollback owner;
- Disk/Loader/Runtime probe ID.

"Too much data" alone is not a sufficient reason. The decision must identify
capacity, query shape, consistency and concurrency.

---

## 3. Contract shape

The JSON Schema is:

```text
core/twin-core/contracts/schema/public/v1/extension-storage-context.schema.json
```

A valid declaration contains:

| Section | Required meaning |
|---|---|
| `extension_id` / `package_role` | Who owns the extension and which adoption rules apply |
| `storage_decisions[]` | One row per data object/storage decision |
| `context_bank` | Producer/consumer mode, record contracts, encrypted payload owner, receipt and pointer rules, rollup policy, retrieval contract |
| `mpr_bridge` | How the extension consumes `context-retrieval-pack@1.x`, phase events and citation policy |
| `evidence` | Decision record, probe IDs, Disk/Loader/Runtime state and rollback owner |

### 3.1 `storage_decisions[]`

Each row must answer the R-DATA-STORAGE decision gate:

```text
data_role
criticality
capacity_profile
consistency_requirement
query_shape
retention_policy
rebuildability
relearnability
sensitivity
storage_target
crud_shape
canonical_owner
contract_id_or_equivalent
schema_or_registry_owner
runtime_probe_id
rollback_owner
```

The public schema carries the minimum machine-readable fields. The full decision
record remains in the owning architecture/storage document.

### 3.2 `context_bank`

`receipt_required` is always `true` when `mode` is `producer` or
`producer_consumer`. The receipt must be captured while the filestore append
lock is held and must include:

```text
contract_id
record_id
event_uuid
relative_file
byte_offset
row_hash
content_hash
occurred_at
operation
blog_id
```

No adapter may calculate byte offsets after the write or reconstruct a pointer
from a path guess.

### 3.3 `mpr_bridge`

Any extension that declares reusable context must consume or expose data through
`context-retrieval-pack@1.x` and the canonical MPR/TwinBrain runtime. It must not:

- scan encrypted files in an HTTP/chat turn;
- read the Context Bank ledger as raw business payload;
- create a private vector store/reranker/KG search path;
- call an LLM/provider directly;
- emit a second reasoning timeline;
- bypass KG-Hub for entity/relation/citation promotion.

The bridge must declare phase events such as:

```text
extension.context.admitted
extension.context.rollup_ready
extension.mpr.evidence_used
extension.mpr.degraded
```

These event IDs must use the existing Event Registry/taxonomy process. The
extension may not invent an unregistered event type at runtime.

---

## 4. Rollup contract

Important data must declare a rollup decision. A rollup is required when raw
records are too numerous for MPR hot-path retrieval but a bounded current state,
trend or identity/entity summary is useful.

Each rollup declares:

```text
rollup_id
owner_module
input_contracts
dimensions
window
version
measures
evidence_policy
correction_policy
rebuild_policy
retention_days
kg_candidate_policy
```

The rollup payload remains an encrypted business JSONL record under the registered
Context Bank contract. SQL `bizcity_context_bank` stores only its pointer and
correlation metadata. Rollup lease/checkpoint SQL is operational state, not the
rollup payload.

Examples for a factory extension:

| Raw data | Rollup | MPR use |
|---|---|---|
| Work-item transitions | `ibs_work_coordination` by project/department/workshop/window | "What is blocked and aging?" |
| Procurement demand/receipt/issue | `ibs_material_flow` by project/material/window | "What material blocks production?" |
| Job card/acceptance evidence | `ibs_production_acceptance` by WO/stage/team/window | "What was accepted and what remains?" |
| Revision impacts | `ibs_revision_impact` by project/revision/category | "What downstream work needs action?" |

Rollup output must retain bounded evidence references and output hash. It must
be deterministic, idempotent and rebuildable from canonical source events.

---

## 5. MPR/TwinBrain bridge

The extension-to-Brain path is:

```text
canonical extension data/event
  -> registered Context Bank record or canonical owner reference
  -> encrypted payload receipt + pointer ledger
  -> rollup/retrieval policy
  -> authorized Context Bank Scope Resolver
  -> Context Bank Search (metadata first, bounded pointer follow)
  -> context-retrieval-pack@1.x
  -> canonical Notebook Source Layer / KG-Hub when semantic evidence is needed
  -> MPR Thinking Timeline
  -> answer/action with citation/evidence and degraded status
```

The extension bridge does not own the final answer. It contributes a vertical
source/rollup/policy and consumes a server-authorized pack.

Minimum bridge acceptance:

- current blog/tenant and identity scope resolved server-side;
- context mode allowlist respected (`context_bank`, `vertical`, `notebook`,
  `hybrid`);
- no group/private identity leakage;
- pointer follow budget and decrypted-byte/time budget enforced;
- `degraded`/`incomplete`/`reason_bucket` preserved;
- citations/evidence references retained where required;
- KG candidate promotion is selective and owned by KG-Hub;
- MPR phase event shows the extension evidence was used or rejected.

---

## 6. Extension adoption stages

This contract is consumed by the PHASE-1.22A closed-loop adoption scorecard and
PHASE-1.33 Context Bank roadmap:

| Stage | Required proof for an extension |
|---|---|
| `package` | Manifest/registry role and storage-context contract |
| `storage` | R-DATA-STORAGE decision, owner, target, CRUD and rollback |
| `context_bank` | Contract registry, encrypted receipt, pointer-only ledger admission |
| `rollup` | Registered definition, deterministic reducer, lease/checkpoint and rebuild proof, or explicit no-rollup decision |
| `brain` | Retrieval-pack/MPR bridge, identity/scope/citation/degraded proof |
| `diagnostics` | Probe IDs with Disk/Loader/Runtime result |
| `release` | CI contract fixture + WordPress runtime evidence + artifact/hash metadata |

Registry presence is not compliance. `partial`, `review`, `skip`, `deferred` and
`unproven` are not PASS.

---

## 7. IBS-HI adoption baseline

IBS-HI declares itself a `vertical_extension` and must provide the following
before W1/W2 schema implementation:

```text
storage_context.contract = extension-storage-context@1.0.0
storage_context.decision_record = plugins/ibs-hi/docs/PHASE-0-IBS-HI-DOMAIN-STORAGE.md
storage_context.context_bank.mode = producer_consumer
storage_context.context_bank.payload_owner = encrypted Business JSONL File Store / canonical owner
storage_context.context_bank.ledger = pointer_only
storage_context.context_bank.retrieval = context-retrieval-pack@1.x
storage_context.mpr_bridge.owner = core/twinbrain
storage_context.mpr_bridge.citation_policy = required
```

IBS-HI data policy:

- work-item/PO/WO/acceptance correctness state remains typed tenant SQL where
  atomic/indexed semantics require it;
- logs/traces/history use registered JSONL contracts;
- durable evidence/document/context payload uses encrypted Business JSONL File
  Store or existing canonical owner;
- rollups are produced for work coordination, material flow, production
  acceptance and revision impact where MPR needs bounded summaries;
- options/usermeta/CPT are used only for small config/profile/editorial data;
- every reusable factory stream has a Context Bank adapter or an explicit,
  reviewed `context_bank_role=none` rationale;
- Brain Chat consumes a bounded retrieval pack, never raw IBS files or SQL;
- IBS-HI does not create a private KG, vector index, reranker or MPR timeline.

---

## 8. Public distribution and signature requirement

This contract must be reflected consistently in:

- `PHASE-0-CANON.md` / R-DATA-STORAGE / R-CONTEXT-BANK references;
- `PUBLIC-CONTRACTS-v1.md` and the public contract catalog/schema/fixtures;
- `FRAMEWORK-CONTRACT-INVENTORY-v1.md`;
- `PLUGIN-CONTRACT-REGISTRY-ADOPTION-v1.md` and future registry schema;
- `FRAMEWORK-GUIDE-v1.md` onboarding/storage section;
- `PHASE-1.22A` adoption scorecard;
- `PHASE-1.33` Context Bank implementation authority;
- IBS-HI canon, architecture, storage decision record, roadmap and manifest;
- public extension README/guide where the package is distributed.

Every document that adopts this policy must carry the directive attribution:

```text
Directive: Johnny Chu - Chu Hoàng Anh · 2026-09-13
```

The attribution records the policy owner. It does not turn a static document,
manifest or registry row into runtime evidence.
