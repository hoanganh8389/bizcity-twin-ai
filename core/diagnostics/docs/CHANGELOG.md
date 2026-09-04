# Core Diagnostics Changelog

> Canonical change ledger for `core/diagnostics/`.
>
> This file records changes to the Diagnostics framework, probe graph, probe
> loader/registration, Smoke Runner, Diagnostics admin/REST/CLI surfaces, and
> Diagnostics rules. It is separate from schema JSON changelogs under
> `core/diagnostics/changelog/` and from the plugin-wide `CHANGELOG.md`.
>
## Entry Contract

Every change touching `core/diagnostics/` MUST add one entry here before the
change is considered complete. This includes:

- a new, removed, retired, renamed, or moved probe;
- any probe class/declaration, registration, loader, queue, or lazy-load change;
- Smoke Runner, Diagnostics REST/admin/CLI, evidence, or validation changes;
- a new or changed rule/document that governs Diagnostics;
- a schema/table/column/index change, which additionally requires the relevant
  JSON changelog under `core/diagnostics/changelog/` and the R-DCL validator.

Each entry must state the date, owner/author, phase or rule ID, affected paths,
root cause or intent, validation performed, deployment requirement, and current
status. Do not record a change only in the plugin-wide changelog.

## 2026-09-02

### B2C-D11 - exact-key product-page purchase context probe

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `B2C-D11`, `R-DDV`, `R-GW-8`.
- **Affected paths:** `core/diagnostics/includes/probes/class-probe-b2b2c-product-key-context.php`;
  `core/diagnostics/bootstrap.php`; `core/diagnostics/docs/CLASS-INDEX.md`.
- **Intent:** Add a focused read-only probe for the Woo product-page API-key
  selector/create surface without mixing it into the six-route product-page
  probe or creating a real key/order.
- **Validation:** Disk markers, Router Account Experience hook registration,
  Account REST projection fields, active paid Master Plan to Woo product
  mapping, and exact-key checkout markers are checked. PHP 7.4.4 lint and the
  focused local probe are required before deployment.
- **Deployment requirement:** Deploy the probe queue and class together with
  the Router Account Experience and Account REST artifacts; rerun on B1 after
  the product-page cache is purged. Browser create/checkout evidence remains a
  separate authenticated acceptance step.
- **Rollback boundary:** Remove only the probe queue/class and retain the
  product-page implementation; the probe has no persistent cleanup artifacts.
- **Status:** LOCAL READ-ONLY PASS - probe returned `1 pass / 0 fail / 0 skip`
  with mapped product id `922`; B1 deployment and authenticated browser
  interaction remain pending.

### PHASE-CB-G1-PRECONDITION - fail-closed two-shard fixture discovery

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB-G1`, `R-MSDB`, `R-DDV`.
- **Affected path:** `bin/context-bank-two-shard-fixture.php`.
- **Intent:** Verify two explicit blog/domain routes and physical database
  identities before any Context Bank provisioning or pointer mutation.
- **Validation:** `PHP_BIN=C:\\php\\php.exe`, PHP `7.4.4`, mapped host
  `libedemo.bizcity.vn`, blogs `1` and `2`, explicit admin `user=3539`.
  The fixture returned `status=fail`,
  `reason=two_distinct_physical_shards_required`; both routes returned
  `shard_route_mismatch` and the same redacted physical fingerprint. No
  provisioning or pointer mutation occurred, and the switch cleanup guard
  completed without timeout.
- **Deployment requirement:** Rerun only with an approved second blog/domain
  whose router evidence has a distinct verified physical database/keymeta
  identity. Do not use same-database blogs as two-shard evidence.
- **Rollback boundary:** The fixture has no production side effect before the
  distinct-shard precondition; retain fail-closed behavior if discovery fails.
- **Status:** BLOCKED PRECONDITION - G1 isolation remains open; no Runtime PASS
  is claimed.

### PHASE-CB5.1-LATE-CORRECTION - durable late-event reopen and superseded rollup evidence

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB5.1`, `R-DDV`, `R-DCL`, `R-MSDB`.
- **Affected paths:** `core/diagnostics/changelog/core.context-bank.json`;
  `core/context-bank/includes/class-context-bank-rollup-engine.php`;
  `core/context-bank/includes/class-context-bank-rollup-worker.php`;
  `bin/context-bank-rollup-fixture.php`.
- **Root cause:** The worker always advanced from `checkpoint_occurred_at` and
  `checkpoint_record_id`, so an older event arriving after a successful batch
  was silently excluded and could not reopen the affected rollup window.
- **Change:** Added schema version `1.3.0` dirty/supersession metadata,
  `mark_dirty()`, canonical rebuild-from-source behavior that bypasses the
  cursor while dirty, new output identity/hash handling and superseded
  `parent_record_id` provenance. The standalone fixture now inserts a late
  source event, reopens the dimension, verifies a changed output and checks
  superseded provenance before cleaning both rollups and all source pointers.
- **Validation:** `PHP_BIN=C:\\php\\php.exe`, PHP `7.4.4`; schema changelog
  validator scanned 36 JSON files and returned clean; reducer, worker and
  fixture lint passed. Outside Diagnostics CLI on local blog `1511`, the
  standalone fixture returned `status=pass` with 12/12 steps: source admission,
  initial checkpoint, checkpoint-current resume, interruption before checkpoint,
  Cron Meta persistence, idempotent retry, durable dirty reopen, canonical
  rebuild with new output hash, superseded pointer provenance, correction
  replay and complete tombstone cleanup.
- **Deployment requirement:** Deploy schema/worker/reducer/fixture together
  and rerun the fixture on an approved mapped target. Do not call this a
  production cron or two-shard result; `SDK_MISSING` and `--skip-network` do
  not constitute provider evidence.
- **Rollback boundary:** Disable rollup workers and retain canonical source
  files/ledger pointers; revert only dirty metadata handling and the fixture if
  compatibility rollback is required. Do not remove the existing diagnostics
  isolation guard.
- **Status:** LOCAL LATE-EVENT/CORRECTION PASS - durable reopen, superseded
  provenance, interruption recovery, correction replay idempotency and
  synthetic R-CRON-META proven; two-shard isolation and production cron
  aggregate remain open.

### PHASE-CB4.3-DDV - explicit Commerce relation and no-conversation guard

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB4.3`, `R-DDV`, `R-DATA-STORAGE`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-commerce-adapter.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-commerce.php`.
- **Root cause:** The Woo projection carried `customer_user_id` but did not
  preserve the exact CRM contact/conversation relation already attached to a
  Woo order, and its disposable probe had no assertion for an unlinked order.
- **Change:** Read only `_bizcity_crm_contact_id` and
  `_bizcity_crm_conversation_id` from the current Woo order, persist bounded
  relation dimensions to the encrypted record and tenant pointer, and return
  `unlinked` when no relation exists. No conversation creation or latest-ID
  lookup is introduced.
- **Validation:** `PHP_BIN=C:\\php\\php.exe`, PHP `7.4.4`; adapter and probe
  lint passed. The owning `core` batch ran filtered
  `core.context_bank.commerce` on blog `1526` and returned
  `1 pass · 0 fail · 0 skip`, `duration_ms=6148`, `selected_total=1`,
  `executed=1`; all 11 steps passed, including exact relation guard,
  unlinked-order no-conversation, encrypted projection, replay, verified
  pointer follow, tombstone and derived-pointer cleanup.
- **Deployment requirement:** Deploy the adapter and probe together, then rerun
  the same focused Commerce probe on the mapped target shard. Add a disposable
  order carrying exact CRM relation metadata before claiming linked relation
  runtime evidence.
- **Rollback boundary:** Revert only relation extraction, bounded relation
  fields and the probe assertions; retain Woo canonical ownership, capture-off
  default and pointer cleanup.
- **Status:** LOCAL COMMERCE RELATION SAFETY PASS - unlinked conversation gate
  closed locally; linked relation fixture, warehouse/SKU, late correction and
  full payment/refund/shipment/delivery coverage remain open.

### PHASE-1.30-G2-FIX - read/write append mode closes VPS duplicate source rows

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30-G2`, `R-LOG-HYBRID`, `R-DDV`.
- **Affected path:** `core/helper/class-bizcity-jsonl-file-logger.php`.
- **Root cause:** The mapped VPS G2 run had both concurrent receipts and one
  unique pointer, but failed exactly-one-source-row because `append_jsonl_line()`
  opened the file with `ab`. That mode is write-only on the VPS PHP runtime,
  so the locked `event_uuid` scan could not read existing rows.
- **Change:** Use `a+b`, retaining append semantics and the existing exclusive
  file lock while allowing the idempotency scan to read before writing.
- **Validation:** PHP `7.4.4` lint and `get_errors` passed. Local focused G2
  rerun passed `1 pass · 0 fail · 0 skip` with two child writers, exactly one
  JSONL row, exactly one verified pointer, identical retry and hash conflict
  refusal. The VPS failure remains historical until the fix is deployed.
- **Deployment requirement:** Deploy the logger change and rerun
  `core.helper.log_idempotency_concurrency` on blog `1511` with the resolved
  `/usr/local/bin/php`; inspect source-row count and pointer verification, not
  only the exit code.
- **Rollback boundary:** Revert only the append mode if compatibility testing
  requires it; do not remove event deduplication or rely on SQL pointer
  uniqueness as a substitute for canonical JSONL exactly-once behavior.
- **Status:** Historical fix entry; superseded by the VPS rerun evidence entry
  below.

### PHASE-1.30-G2-VPS-RERUN - concurrent JSONL idempotency gate PASS

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30-G2`, `R-DDV`, `R-CLI-ASYNC-ISOLATION`.
- **Affected path:** `core/helper/class-bizcity-jsonl-file-logger.php` and
  `core/diagnostics/includes/probes/class-probe-log-idempotency-concurrency.php`.
- **Evidence:** After deploying the `a+b` append-mode fix, VPS
  `libedemo.bizcity.vn` / blog `1511` ran with `PHP_BIN=/usr/local/bin/php`,
  PHP `7.4.33`, WordPress `6.9`, `--skip-provision` and `--skip-network`.
  The focused probe returned `1 pass · 0 fail · 0 skip`, `verdict=pass`,
  `duration_ms=2968`, catalog hash
  `8208011be7ddc35c57546d5a3a5c8869dcbed6d2a5f22dde907478a2acb8f68a`, batch
  hash `ff744e6cca587cc346716c739a6a4b123b0bf0fa7c339f758032201a733e09db`,
  and JUnit `build/g2-vps-rerun.xml`.
- **Result:** All six runtime/loader steps passed: concurrent workers shared
  one event identity, identical retry reused the locked row, exactly one
  canonical JSONL row remained, exactly one verified pointer remained, and a
  changed same-event hash was refused. This is Runtime PASS for G2, not a
  full PHASE-1.30 batch completion; `coverage.complete=false` is retained for
  the filtered run.
- **Status:** PASS - G2 runtime gate closed. G1 HTTP denial, G3 retention/Cron
  metadata and G4 distinct physical-shard evidence remain open.

### PHASE-1.30-G3-VPS-RUN - disposable reconcile and retention runtime PASS

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30-G3`, `R-DDV`, `R-CRON-META`.
- **Affected paths:** `core/helper/class-bizcity-jsonl-file-logger.php`,
  `core/helper/class-bizcity-log-index.php` and
  `core/diagnostics/includes/probes/class-probe-log-reconcile-retention.php`.
- **Evidence:** VPS `libedemo.bizcity.vn` / blog `1511` used
  `PHP_BIN=/usr/local/bin/php`, PHP `7.4.33`, WordPress `6.9` and the focused
  `core.helper.log_reconcile_retention` probe. It returned `1 pass · 0 fail ·
  0 skip`, `verdict=pass`, `duration_ms=829`; all eight disposable G3 steps
  passed, including missing-pointer rebuild, bounded resume, stale-hash removal,
  retention deletion veto preservation and exact retry cleanup.
- **Boundary:** The filtered run has `coverage.complete=false`. The malformed
  WP-CLI command did not produce valid schedule or `bizcity_cron_runs` metadata
  evidence, so canonical retention Cron execution remains a separate pending
  gate. This result does not close G3 or prove production retention behavior.
- **Status:** TARGET-SHARD DISPOSABLE RUNTIME PASS - Cron run/meta evidence
  pending.

### PHASE-1.30-G3-CRON-COLLECTION - Cron evidence still pending

- **Evidence:** The aggregate target-shard run stored artifacts under
  `build/phase-1.30-20260902/g3-final-20260902-153842/`. The G3 probe itself
  passed on blog `1511` with `PHP_BIN=/usr/local/bin/php`, PHP `7.4.33`,
  WordPress `6.9`, `1 pass · 0 fail · 0 skip`, probe duration `3428ms` and
  runner total `3445ms`.
- **Boundary:** `RUN_RETENTION_CRON=0`, so no retention hook was executed.
  The Cron listing returned exit `1` because the aggregate wrapper invoked
  WP-CLI as `root` and received the WP-CLI root-user refusal. The JSONL scan
  found retention records for other blog IDs, but no valid blog-1511 Cron run
  was established. Those records are not reused as target-shard evidence.
- **Next action:** Run the canonical `bizcity_jsonl_retention` hook using
  `/usr/local/bin/php /usr/local/bin/wp` under user `vibeyeuc`, then capture
  the matching blog-1511 `start`, `meta` and `end` records and retain the
  command stderr/exit artifact.
- **Status:** DEFERRED - G3 disposable runtime PASS; target blog Cron
  schedule/run/meta evidence remains required.

### PHASE-1.30-G3-CRON-FAILURE - target retention command exit 255

- **Evidence:** The target Cron attempt created
  `build/phase-1.30-20260902/g3-cron-final-20260902-161343/` and returned
  `cron_run_exit=255` on `libedemo.bizcity.vn` / target blog context. No
  successful blog-1511 `start/meta/end` artifact was supplied.
- **Classification:** Hard failure, not `SKIP` and not Runtime PASS. The SSH
  session closed because the shell block ended with `exit "$RC"`; that does
  not identify the underlying PHP/WordPress failure. The supplied output did
  not include the contents of `run.stderr`, `run.stdout`, or the canonical VPS
  PHP error log.
- **Required diagnosis:** Read `run.stderr`, `run.stdout`, `run.exit`, the
  target-blog match file and
  `/home/vibeyeuc/huongnguyen.vibeyeu.com.vn/wp-content/bps-backup/logs/bps_php_error.log`
  before rerunning the retention hook. Redact credentials, tokens, SQL and
  PII from any report.
- **Status:** FAIL - G3 Cron evidence blocked; disposable G3 probe remains
  PASS, but canonical retention run/meta is not proven.

### PHASE-1.33-W4-DDV - mapped-host core and CRM Context Bank evidence

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.33`, `R-DDV`, `R-CLI-ASYNC-ISOLATION`, `R-MSDB`.
- **Affected paths:** `core/diagnostics/includes/probes/`
  (`context-bank-rollup`, `context-bank-rollup-worker`, `context-bank-commerce`,
  `context-bank-references`, `context-bank-kg-bridge`, `context-bank-w4-chain`,
  `context-bank-channel-admission`, `context-bank-channel-crm-continuity`).
- **Evidence:** Valid VPS runs on `libedemo.bizcity.vn` / blog `1511` used
  `PHP_BIN=/usr/local/bin/php`, PHP `7.4.33`, WordPress `6.9`. The `core`
  filtered run returned `6 pass · 0 fail · 0 skip`, `verdict=pass`,
  `duration_ms=632`, with catalog hash
  `8208011be7ddc35c57546d5a3a5c8869dcbed6d2a5f22dde907478a2acb8f68a` and
  batch hash `004265f86ee710341e956c6edd9a207eabb78bd838c794ef66d1d37961c03156`.
  The `channel` filtered run returned `2 pass · 0 fail · 0 skip`,
  `verdict=pass`, `duration_ms=924`, and batch hash
  `218c56e47b10a6aa36942a65da8369484065cc2655bad0bd652124305dd94c17`.
- **Boundary:** Core probes passed reducer/worker isolation, Woo disposable
  projection/replay/tombstone, Skill/KG capture-off and owner-wiring checks.
  Channel probes passed archive receipt, pointer admission/follow/tombstone and
  normalized CRM continuity without provider transport. `w4_chain` retains its
  two-tenant Runtime `deferred` sub-step; these are mapped-host disposable or
  structural PASS results, not production-canary or second-shard evidence.
- **Note:** The earlier pasted command was malformed/interrupted and is
  excluded from evidence; only the subsequent clean invocations are recorded.
- **Status:** MAPPED-HOST FOCUSED PASS - two-tenant W4 chain, provider E2E,
  second-shard isolation and production canary remain open.

### PHASE-CB5.1-DDV - deterministic rollup and worker isolation evidence

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-CB5.1`, `R-DDV`, `R-CLI-ASYNC-ISOLATION`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-rollup-engine.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-rollup.php`.
- **Root cause:** UUID replay deduplication occurred before canonical ordering,
  making `output_hash` depend on input arrival order. Delivery-only records were
  also still included in conversation evidence references.
- **Change:** Sort normalized metadata by `(occurred_at, record_id)` before UUID
  deduplication and exclude delivery-only conversation events before state and
  evidence construction. Extend the probe to verify the durable worker is loaded
  and both lease acquisition and worker entry return `diagnostics_cli_isolated`.
- **Validation:** PHP `7.4.4` lint passed; focused `core` batch probe passed 1/1
  on blog `1511`, `catalog_total=226`, `duration_ms=107`, with 7/7 steps PASS.
- **Deployment requirement:** The focused command now passes on the mapped
  tenant `libedemo.bizcity.vn` / blog `1511` with PHP `7.4.33`,
  `duration_ms=18`, and `1 pass · 0 fail · 0 skip`. This evidence does not
  prove physical lease/checkpoint persistence, late-event reopening or
  two-shard isolation.
- **Rollback boundary:** Revert only reducer ordering/evidence filtering and
  probe assertions; do not disable worker CLI isolation or create another rollup
  state owner.
- **Status:** IMPLEMENTED - local and mapped-host reducer/worker-isolation Runtime PASS; physical worker evidence pending.

## 2026-09-03

### PHASE-CB5.1-INTERRUPTION-CRON-META - worker recovery and correction replay evidence

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB5.1`, `R-CRON-META`, `R-DDV`, `R-CLI-ASYNC-ISOLATION`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-rollup-worker.php`;
  `bin/context-bank-rollup-fixture.php`.
- **Root cause:** A checkpoint failure after encrypted output and ledger
  admission left a durable rollup pointer ahead of the checkpoint; retrying by
  appending a new receipt could create a pointer conflict. The worker also had
  no bounded Cron Meta evidence for its lifecycle outcomes.
- **Change:** Added checkpoint fault injection, durable output reuse after an
  interrupted checkpoint, bounded worker `note_event()` outcomes and fixture
  inspection through `BizCity_Cron_Manager::with_synthetic_run()`. The fixture
  separately repeats a correction and verifies the same output hash/pointer is
  reused.
- **Validation:** Resolved `PHP_BIN=C:\\php\\php.exe`, PHP `7.4.4`; worker and
  fixture `php -l` passed. Outside Diagnostics CLI on mapped host
  `libedemo.bizcity.vn` / blog `1511`, with explicit admin `user=3539` and
  targeted provisioning, the standalone fixture returned `status=pass` with
  12/12 steps: interruption before checkpoint, Cron Meta persistence,
  idempotent retry, checkpoint persistence/current resume, late reopen,
  canonical rebuild, superseded provenance, correction replay and complete
  tombstone cleanup.
- **Deployment requirement:** Deploy worker, fixture and schema `1.3.0`
  together before repeating the approved target-shard run. The synthetic Cron
  Meta run proves metadata API behavior, not a production cron schedule or
  complete diagnostics batch. Two-shard isolation remains G1 evidence.
- **Rollback boundary:** Stop rollup workers and keep canonical source files;
  revert only checkpoint recovery/fault-injection and worker metadata changes
  if needed. Do not remove diagnostics CLI isolation or pointer-only storage.
- **Status:** LOCAL G2 SUBGATES PASS - interruption recovery, correction
  replay idempotency and synthetic R-CRON-META proven; two-shard and production
  cron aggregate evidence remain open.

### PHASE-CB5.1-PHYSICAL-DDV - standalone worker lease and checkpoint evidence

- **Owner:** Johnny Chu.
- **Rule/phase:** `PHASE-CB5.1`, `R-DDV`, `R-CLI-ASYNC-ISOLATION`, `R-SAFE-LOADER`.
- **Affected paths:** `bin/context-bank-rollup-fixture.php`;
  `core/helper/bootstrap.php`.
- **Root cause:** The standalone fixture initially could not provision the
  rollup state table because its request did not load the canonical diagnostics
  dependencies. After that boundary was isolated, the worker output was
  rejected because `core.context_bank.rollup` was missing from the shared file
  contract registry.
- **Change:** The fixture now loads the canonical diagnostics dependencies only
  for an explicit `--provision=1` request and invokes only the registered
  `context_bank_rollup_state` installer. The shared helper registers the worker
  rollup contract before encrypted JSONL writes.
- **Validation:** `PHP_BIN=C:\\php\\php.exe`, PHP `7.4.4`; both changed PHP
  files passed `php -l`. Outside Diagnostics CLI, local blog `1511` with
  explicit admin `user=3539` passed all 6 fixture steps: two source pointers,
  one bounded encrypted rollup, persisted checkpoint, second-call
  `rollup_checkpoint_current`, and tombstone/cleanup. The fixture restored its
  feature flag in `finally`; no provider transport was used.
- **Deployment requirement:** Repeat the standalone fixture in an approved
  target-shard maintenance context after deploying the matching helper,
  Context Bank and fixture artifacts. This local result does not prove
  two-shard isolation, late-event reopening or production cron execution.
- **Rollback boundary:** Revert only the fixture provisioning path and the
  `core.context_bank.rollup` registry entry; keep the worker isolation guard,
  pointer-only ledger and schema ownership unchanged.
- **Status:** LOCAL PHYSICAL WORKER PASS - lease/checkpoint/resume/cleanup
  proven; late-event, two-shard and production worker gates remain open.

## 2026-09-02

### PHASE-CB4.5-DDV - canonical Rule/Skill reference adapter loading

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB4.5`, `R-DDV`, `R-SAFE-LOADER`.
- **Affected paths:** `core/context-bank/bootstrap.php`.
- **Root cause:** The Context Bank bootstrap loaded adjacent producer/reference
  slices but did not load the canonical Rule/Skill reference adapter, leaving
  Skill lifecycle hook attachment dependent on another surface loader.
- **Change:** Load and boot `class-context-bank-rule-reference-adapter.php`
  through the existing Safe Loader from the Context Bank owner boundary. The
  adapter remains pointer-only and does not create a second Skill registry.
- **Validation:** `PHP_BIN=C:\\php\\php.exe`, PHP `7.4.4`; `php -l` passed.
  The owning `core` diagnostics batch ran the filtered
  `core.context_bank.references` probe on blog `1526` and returned
  `1 pass · 0 fail · 0 skip`, `duration_ms=205`, `selected_total=1`,
  `executed=1`; all 4 Disk/Loader/Runtime steps passed. The filtered run has
  `coverage.complete=false` by design and is not a full-batch claim.
- **Deployment requirement:** Deploy the matching Context Bank bootstrap and
  Rule/Skill adapter, then rerun the same focused probe on the mapped target
  shard before claiming mapped-shard lifecycle admission.
- **Rollback boundary:** Revert only the bootstrap load/boot block; preserve
  the adapter contract, hash-only body representation and capture-off default.
- **Status:** LOCAL LOADER AND POINTER-ONLY BOUNDARY PASS - MPR owner
  navigation, mapped-shard admission and live Skill lifecycle write evidence
  remain open.

### PHASE-1.30-G1/G2 - JSONL security scope and concurrent idempotency probes

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30-G1`, `PHASE-1.30-G2`, `R-LOG-HYBRID`, `R-DDV`.
- **Affected paths:** `core/helper/class-bizcity-jsonl-file-logger.php`;
  `core/channel-gateway/includes/class-channel-rest-api.php`;
  `core/diagnostics/includes/probes/class-probe-log-security-scope.php`;
  `core/diagnostics/includes/probes/class-probe-log-idempotency-concurrency.php`;
  `core/diagnostics/bootstrap.php`; `bin/log-idempotency-worker.php`.
- **Intent:** Keep JSONL event append idempotent inside the existing file lock,
  reject changed same-event pointer hashes, enforce exact account scope on
  channel-log deletion/legacy responses, and provide focused G1/G2 evidence
  without creating a second logger or index owner.
- **Validation:** PHP `7.4.4` syntax lint and `get_errors` passed for changed
  PHP files. Focused G2 diagnostics passed on local blog `1526`: two child PHP
  writers, one canonical JSONL row, one verified pointer, identical retry and
  same-event hash conflict refusal. Focused G1 passed its Disk/Loader/runtime
  scope checks but returned `SKIP` for HTTP direct-file denial because no
  explicit `BIZCITY_DIAGNOSTICS_HTTP_PROBE_URL` was supplied.
- **Deployment requirement:** Deploy both probes and rerun on mapped VPS blog
  `1511`; run G1 with the exact deployed JSONL URL and retain HTTP status,
  headers, server type and redacted body summary. Local evidence does not close
  the production web-server or two-shard gates.
- **Rollback boundary:** Remove only the synthetic run-specific G2 file,
  pointer and worker artifact; never disable upload deny rules or fall back to
  an unscoped legacy log response.
- **Status:** IMPLEMENTED LOCALLY - G2 Runtime PASS; G1 scope PASS with HTTP
  denial deferred; mapped VPS rerun pending.

### PHASE-1.30-G3/G4 - reconcile retention and multisite rollback probes

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30-G3`, `PHASE-1.30-G4`, `R-LOG-HYBRID`, `R-MSDB`,
  `R-DDV`.
- **Affected paths:** `core/helper/class-bizcity-jsonl-file-logger.php`;
  `core/helper/class-bizcity-log-index.php`;
  `core/diagnostics/includes/probes/class-probe-log-reconcile-retention.php`;
  `core/diagnostics/includes/probes/class-probe-log-multisite-rollback.php`;
  `core/diagnostics/bootstrap.php`; `bin/diagnostics-run.php`.
- **Intent:** Prove bounded pointer rebuild/stale-hash cleanup and retention
  deletion failure safety, then add a reversible index-disable boundary that
  preserves JSONL as canonical source while pointer indexes are rebuilt.
- **Validation:** PHP `7.4.4` lint and `get_errors` passed for the G3/G4 helper,
  probe and bootstrap changes. Focused G3 Runtime probe passed on local blog
  `1526`: missing pointer rebuild, multi-call cursor resume, stale-hash removal
  and rebuild, retention veto preservation, and exact successful cleanup. G4
  probe is implemented and its distinct two-blog/shard result remains pending
  until an approved multisite runtime is executed.
- **Deployment requirement:** Deploy the probes and rerun G3/G4 on approved
  VPS blogs. G4 must retain both `blog_id`, `$wpdb->prefix`, database/keymeta
  identity and explicit `switch_to_blog()`/restore evidence; same-database
  prefix isolation is not a distinct-shard PASS.
- **Rollback boundary:** Disposable files, pointers and test filters only;
  `is_enabled()` defaults to true and must not provide a blog 1/current-DB
  fallback.
- **Status:** IMPLEMENTED LOCALLY - G3 Runtime PASS; G4 two-blog prefix,
  cross-blog, cache and rollback checks PASS, with distinct-shard Runtime
  evidence pending.

**Follow-up evidence 2026-09-02:** G4 focused multisite probe passed two real
blog prefix/cross-blog/cache/rollback checks on local blog `1526`; both blogs
resolved to the same database, so distinct-shard identity is `SKIP`. The
filtered diagnostics runner was corrected so canonical probe `status=skip` is
counted as `skip`, emitted as JUnit `<skipped>`, and exits `0` when no fail is
present. G1/G4 topology-deferred runs now report `0 fail` with explicit skips.

### PHASE-1.30-DEPLOY - isolate diagnostics from early compat preloads

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30`, `R-PERF-LOADER`, `R-SAFE-LOADER`, `R-DDV`.
- **Affected paths:** `mu-plugin/bizcity-twin-compat.php`;
  `wp-content/mu-plugins/bizcity-twin-compat.php` after automatic sync;
  `core/helper/bootstrap.php`.
- **Root cause:** A normal focused diagnostics run timed out during
  WordPress bootstrap in WooCommerce `AbstractDynamicBlock`, while the same
  run with `--isolated-mu` completed. This isolated the failure to early MU
  compatibility preloads, not to the G1-G4 probes. The compat loader was
  preloading heavy Knowledge/Intent/Twin Core/Market/WebChat modules before
  the regular plugin lifecycle.
- **Change:** Bump the canonical compat source to `1.1.3` and skip those heavy
  early preloads only when `BIZCITY_DIAGNOSTICS_CLI` is true. Production
  REST/webhook preload conditions remain unchanged. The helper bootstrap also
  fails closed if its Safe Loader artifact is unavailable.
- **Validation:** Canonical source and automatically synchronized deployed MU
  copy both expose compat version `1.1.3` and diagnostics preload guards.
  PHP `7.4.4` lint passed. Normal non-isolated local G1 focused run now
  completes with `0 fail · 1 skip`; the HTTP step is the expected G1 skip.
- **Deployment requirement:** Verify the deployed compat source version and
  guards, clear OPcache if the version remains stale, then rerun the exact
  focused VPS command. A remaining bootstrap fatal must include the complete
  class name, file and line; empty `results` is a bootstrap/deployment failure,
  not probe evidence.
- **Rollback boundary:** Remove only the diagnostics-specific early preload
  gate after confirming a synchronized compatible loader; never restore a
  second compat loader or bypass Safe Loader checks.
- **Status:** IMPLEMENTED LOCALLY - deployed MU copy synchronized locally;
  target VPS rerun pending.

### PHASE-1.30-DEPLOY - prevent incomplete legacy MU bundles from aborting probes

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30`, `R-SAFE-LOADER`, `R-DDV`.
- **Affected path:** `wp-content/mu-plugins/bizgpt-agent.php`.
- **Root cause:** After the early compat preload timeout was removed, normal
  diagnostics bootstrap reached the legacy BizGPT MU entrypoint, which called
  missing `bizgpt-agent/*` files with raw `require_once` and terminated before
  probe execution. `--isolated-mu` had hidden this deployment artifact.
- **Change:** Skip this incomplete legacy chatbot entrypoint only when
  `BIZCITY_DIAGNOSTICS_CLI` is active. Production frontend/webhook behavior is
  unchanged; the missing legacy bundle remains deployment debt rather than a
  synthetic diagnostics PASS.
- **Validation:** PHP `7.4.4` lint and `get_errors` passed. Normal local MU
  focused diagnostics now completes with `0 pass · 0 fail · 2 skip`; no
  `diagnostics_bootstrap_fatal` or Woo timeout occurred. The two skips are the
  expected HTTP denial and same-database distinct-shard prerequisites.
- **Deployment requirement:** Deploy this MU guard together with compat
  `1.1.3`, clear OPcache if needed, verify markers, then rerun the canonical
  mapped-host G1-G4 command. A `results=[]` response remains a bootstrap
  blocker and must include the complete class/file/line from stderr.
- **Rollback boundary:** Revert only the diagnostics-context guard after the
  legacy bundle is restored and validated; do not add placeholder chatbot files
  or bypass Safe Loader/partial-deployment handling.
- **Status:** IMPLEMENTED LOCALLY - target VPS deployment and rerun pending.

### PHASE-1.30-DEPLOY - make stale helper bootstrap drift machine-readable

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30`, `R-SAFE-LOADER`, `R-DDV`.
- **Affected path:** `bin/diagnostics-run.php`.
- **Root cause:** A partial/stale deployed helper could fatal before
  `wp-load.php`, leaving no probe results and an opaque truncated class error.
- **Change:** Add a pre-WordPress artifact/marker preflight for the helper
  file-contract registry. Machine mode now returns
  `diagnostics_deployment_preflight` with exit `2` when the deployed bundle is
  incomplete; it does not add an unguarded fallback loader.
- **Validation:** PHP `7.4.4` lint and `get_errors` passed. Normal local MU
  focused G1-G4 rerun reached the probes with `2 pass · 0 fail · 2 skip`.
- **Deployment requirement:** Deploy the runner, helper bootstrap, registry
  artifact, compat loader and legacy MU guard together; verify markers before
  running the mapped-host probe command.
- **Rollback boundary:** Revert only the preflight reporting after artifact
  synchronization is proven; retain fail-closed Safe Loader behavior.
- **Status:** IMPLEMENTED LOCALLY - target VPS artifact parity pending.

### PHASE-CB4.3-DDV - extend WooCommerce Context Bank projection evidence

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-CB4.3`, `R-DDV`, `R-DATA-STORAGE`.
- **Affected path:** `core/diagnostics/includes/probes/class-probe-context-bank-commerce.php`.
- **Intent:** Exercise one disposable Woo order through the canonical Woo API,
  project a bounded encrypted order transition, verify replay/follow, admit a
  tombstone and remove only the derived Context Bank pointer before deleting
  the test order.
- **Validation:** PHP `7.4.4` syntax lint passed; focused `core` batch probe
  passed 1/1 on blog `1511`, `duration_ms=7586`. Capture-off, no-PII,
  projection, replay, verified follow, tombstone and cleanup steps passed.
  The mapped-host rerun on blog `1511` returned `precheck-fail` with
  `executed=0`, `allowed_skipped=1`, `verdict=skip`, `duration_ms=13` because
  `BizCity_Context_Bank_Commerce_Adapter` was not loaded; no Woo order fixture
  executed on the VPS.
- **Deployment requirement:** Deploy the probe with the existing commerce
  adapter and rerun `core.context_bank.commerce` on the mapped VPS tenant.
  Local PASS does not close warehouse, full lifecycle or production-canary
  gates.
- **Rollback boundary:** Remove only the disposable probe fixture; keep Woo as
  order/payment truth and do not add a Context Bank commerce shadow table.
- **Status:** IMPLEMENTED LOCALLY - focused Runtime PASS; mapped-host Loader precondition SKIP requires deployment parity rerun.

### PHASE-CB4.3-DDV - load Commerce adapter before headless probe precondition

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-CB4.3`, `R-DDV`, `R-SAFE-LOADER`.
- **Affected path:** `core/diagnostics/includes/probes/class-probe-context-bank-commerce.php`.
- **Root cause:** The mapped-host probe reached its precondition before the
  deferred Commerce adapter path had loaded, producing `executed=0` and a
  loader skip even though the adapter artifact and bootstrap marker existed.
- **Change:** The probe precondition now loads the canonical Context Bank
  bootstrap through `BizCity_Safe_Loader` with readable-file guards before
  checking the adapter class. It does not bypass the package boundary or
  enable capture outside the fixture.
- **Validation:** PHP `7.4.4` lint passed; local focused `core` probe passed
  1/1 with `catalog_total=223`, `duration_ms=7118`, including the disposable
  Woo projection/replay/follow/tombstone/cleanup chain. Two mapped-host reruns
  on blog `1511` still returned `precheck-fail`, `executed=0`,
  `allowed_skipped=1`, `verdict=skip`, `duration_ms=13`; no VPS Woo fixture
  executed.
- **Deployment requirement:** Deploy the updated probe and rerun the exact
  mapped-host `core.context_bank.commerce` command. The earlier VPS skip stays
  historical and cannot be promoted to Runtime PASS.
- **Rollback boundary:** Revert only the probe-side loader correction; retain
  the canonical Commerce adapter and package loader contracts.
- **Status:** IMPLEMENTED LOCALLY - focused Runtime PASS; mapped-host Loader precondition remains SKIP and deployment parity rerun is pending.

The latest mapped-host rerun repeated the same precondition result:
`executed=0`, `allowed_skipped=1`, `verdict=skip`, `duration_ms=13`,
`catalog_total=214`, `catalog_hash=fe2452aa72bdc3420d1d1ee9bed5f1fd9ea03e9a133ffce518676aa5a081e797`.
This fingerprint predates the current local probe correction, so the result is
still deployment-parity evidence rather than a Commerce runtime failure.

### PHASE-CB4.3-DDV - recover partially mounted Commerce package in probe

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-CB4.3`, `R-DDV`, `R-SAFE-LOADER`.
- **Affected path:** `core/diagnostics/includes/probes/class-probe-context-bank-commerce.php`.
- **Root cause:** The deployed Commerce precondition still reported the adapter
  missing when the Context Bank package had been partially mounted and the
  Safe Loader class was not already available at probe time.
- **Change:** The precondition now guarded-loads the canonical Safe Loader when
  needed, retries the package bootstrap, and then loads only the requested
  Commerce adapter artifact through Safe Loader before calling its idempotent
  `boot()` method.
- **Validation:** PHP `7.4.4` lint passed; local focused `core` probe passed
  1/1 with `catalog_total=223`, `duration_ms=6161`, including the disposable
  Woo projection/replay/follow/tombstone/cleanup chain.
- **Deployment requirement:** Deploy this probe version and verify its marker
  on the VPS before rerunning. The previous VPS `precheck-fail` remains a real
  historical skip and is not retroactively upgraded.
- **Rollback boundary:** Revert only the probe-side recovery path; retain the
  canonical Commerce adapter and Context Bank package ownership.
- **Status:** IMPLEMENTED LOCALLY - focused Runtime PASS; mapped-host rerun pending.

### PHASE-CB4.3-DDV - mapped-host WooCommerce projection PASS

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-CB4.3`, `R-DDV`, `R-DATA-STORAGE`.
- **Affected paths:** `core/context-bank/bootstrap.php`;
  `core/context-bank/includes/class-context-bank-commerce-adapter.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-commerce.php`.
- **Evidence:** The mapped host `libedemo.bizcity.vn` resolved to blog `1511`
  with `PHP_BIN=/usr/local/bin/php`, PHP `7.4.33` and WordPress `6.9`. The
  `core` batch executed `core.context_bank.commerce` 1/1 with `9/9` steps,
  `1 pass · 0 fail · 0 skip`, `duration_ms=581`, and the bootstrap marker was
  present.
- **Runtime boundary:** Disposable Woo order creation, encrypted bounded
  projection, same-transition replay, verified pointer follow, receipt-bearing
  tombstone and derived-pointer cleanup passed. No provider transport or
  customer PII was used.
- **Remaining gates:** Warehouse ownership and complete payment/refund/
  shipment/delivery lifecycle coverage remain pending; capture rollout stays
  gated.
- **Status:** PASS - mapped-host disposable Runtime evidence; production canary pending.

### PHASE-0.41-CRM-ONE-BRAIN - add normalized CRM continuity probe

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-0.41-CRM-ONE-BRAIN`, `R-DDV`, `R-CH-FILE-LOG`.
- **Affected paths:** `core/diagnostics/includes/probes/class-probe-context-bank-channel-crm-continuity.php`;
  `core/diagnostics/bootstrap.php`;
  `plugins/bizcity-twin-crm/includes/class-ai-autoreply-listener.php`.
- **Intent:** Exercise the canonical Facebook CRM normalizer and ingestor,
  CRM event/archive owner, Context Bank pointer admission, verified follow,
  tombstone and disposable cleanup without provider transport or plaintext
  storage in the ledger.
- **Validation:** PHP `7.4.4` syntax lint passed for the probe and bootstrap;
  focused local `channel` Runtime probe passed 1/1 on blog `1511` with
  `duration_ms=2325`. The mapped-host rerun also passed 1/1 on blog `1511`
  with PHP `7.4.33`, `duration_ms=2504` and all 12 Runtime steps. The first
  local run exposed CRM autoreply/LLM/outbound side effects;
  `class-ai-autoreply-listener.php` now blocks that callback in
  `BIZCITY_DIAGNOSTICS_CLI`, and both reruns completed without those effects.
- **Deployment requirement:** The probe and guard are deployed and passed on the
  mapped tenant. Keep capture rollout gated while observation, rollup and
  production-canary gates remain open.
- **Rollback boundary:** Remove only the probe registration and fixture; do not
  bypass the CRM repository, archive owner or Context Bank admission gate.
- **Status:** IMPLEMENTED - local and mapped-host focused Runtime PASS; provider delivery and production canary pending.

### PHASE-0.41-CRM-ONE-BRAIN - extend Context Bank channel admission evidence

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-0.41-CRM-ONE-BRAIN`, `R-DDV`, `R-MSDB`.
- **Affected paths:** `core/diagnostics/includes/probes/class-probe-context-bank-channel-admission.php`;
  `core/context-bank/includes/class-context-bank-ledger.php`.
- **Intent:** Add a disposable archive receipt -> pointer admission -> verified
  follow -> tombstone -> derived-pointer cleanup runtime fixture, while keeping
  CRM business rows, plaintext content and provider transport out of the probe.
- **Root cause:** The ledger stores the canonical `source_contract_id` field,
  while the archive receipt reader requires the equivalent `contract_id` field;
  an earlier deployed run therefore rejected an otherwise valid persisted pointer.
- **Validation:** PHP `7.4.4` syntax lint passed for both changed PHP files;
  local focused probe passed. After the ledger mapping was deployed, the VPS
  `channel` probe on blog `1511` passed all 11 steps, including verified follow,
  tombstone and continuity cleanup (`1 pass · 0 fail · 0 skip`,
  `duration_ms=1004`).
- **Deployment requirement:** Deploy the probe and ledger boundary together,
  verify the `source_contract_id` -> `contract_id` mapping marker, then rerun
  the canonical focused VPS command. Real CRM message/archive continuity remains
  a separate gate.
- **Rollback boundary:** Revert only the probe fixture and internal receipt-field
  mapping; retain the canonical archive/ledger contracts and do not enable
  channel capture as a rollback workaround.
- **Status:** PASS - local and mapped-host disposable continuity evidence;
  real CRM continuity remains pending.

### PHASE-1.30-PROVISION - fix Intent installer callback context

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30`, `R-CR`.
- **Affected path:** `core/diagnostics/includes/installer-registry.php`.
- **Root cause:** Site Provisioner registered the instance method
  `BizCity_Intent_Database::maybe_create_tables()` as a static callback,
  causing `Using $this when not in object context` during provisioning.
- **Change:** Register the callback from
  `BizCity_Intent_Database::instance()` so the method receives its database
  object context.
- **Validation:** PHP 7.4 syntax lint passed locally; target-shard
  provisioning rerun is required to confirm the error is gone.
- **Deployment requirement:** Deploy the installer registry and rerun the
  canonical provisioning command before the PHASE-1.30 legacy batch.
- **Rollback boundary:** Revert only the callback target; do not bypass the
  Site Provisioner or create a second Intent installer path.
- **Status:** IMPLEMENTED LOCALLY - target-shard rerun pending.

### PHASE-1.30-DDV - align lifecycle and metadata probes with routed runtime

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-1.30`, `R-MSDB`, `R-METADATA-CACHE`.
- **Affected paths:**
  - `core/diagnostics/includes/probes/class-probe-legacy-table-state-machine.php`
  - `core/diagnostics/includes/probes/class-probe-table-metadata.php`
- **Root cause:** The state-machine probe selected a JSONL table already in the
  fully retired SQL cohort and incorrectly required a `draining` state. The
  metadata probe selected global `$wpdb->users`, which is not guaranteed to be
  present on the current tenant shard.
- **Change:** The state probe now treats an empty active writer-stop cohort as
  a valid dead-SQL state and verifies a quarantine-only tenant fixture. The
  metadata probe uses the routed tenant `$wpdb->options` table for the existing
  table cache-hit assertion.
- **Validation:** PHP 7.4 lint passed for both probe files. Target-shard
  rerun remains required after deployment; no production DROP is performed.
- **Deployment requirement:** Deploy both probe files and rerun the focused
  state-machine and table-metadata probes on the target shard.
- **Rollback boundary:** Revert only these probe assertion changes; do not
  restore SQL read fallback or alter legacy policy state.
- **Status:** IMPLEMENTED LOCALLY - target-shard rerun pending.

### R-PERF-DIAG - contain physical schema inventory scans

- **Owner:** Johnny Chu
- **Rule/phase:** `R-PERF-DIAG`, `R-METADATA-CACHE`.
- **Affected paths:**
  - `core/diagnostics/includes/class-diagnostics-table-inspector.php`
  - `core/diagnostics/includes/class-diagnostics-dashboard-widget.php`
  - `core/diagnostics/includes/class-diagnostics-notices.php`
  - `core/diagnostics/includes/class-diagnostics-auto-create.php`
  - `core/diagnostics/includes/class-diagnostics-admin-page.php`
- **Root cause:** The standard WordPress dashboard widget and the critical
  regression notice called `BizCity_Diagnostics_Table_Inspector::inspect_all()`
  on ordinary admin requests. The inspector then ran a broad
  `information_schema.TABLES` snapshot on every new request; Query Monitor
  observed about 8.759 seconds for this path on a routed shard.
- **Change:** Removed live inventory reads from the dashboard and notice hot
  paths. Added a five-minute tenant/database/prefix-scoped object-cache for
  explicit inventory requests and an explicit `flush_cache()` call after
  additive schema changes or admin repair actions. Soft-guard notices remain
  the low-cost warning path.
- **Validation:** PHP 7.4 lint and VS Code diagnostics pass for all five PHP
  files. VPS Query Monitor two-request comparison and p95 measurement remain
  required; no production performance PASS is claimed yet.
- **Deployment requirement:** Deploy the five changed Diagnostics files, clear
  the persistent object cache if present, then compare `wp-admin/index.php`
  before/after and verify that only the explicit Diagnostics page runs schema
  inventory.
- **Rollback boundary:** Revert only the hot-path caller/cache changes; do not
  remove the canonical table metadata helper or alter schema ownership.
- **Status:** IMPLEMENTED LOCALLY - VPS performance evidence pending.

## 2026-08-27

### PHASE-DIAG-CI-MOCK — provision Automation schemas in headless CI

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-DIAG-CI-MOCK`, R-DDV, R-CR.
- **Affected paths:** `core/automation/bootstrap.php` and
  `core/diagnostics/docs/PHASE-DIAGNOSTICS-CI-MOCK-MODE.md`.
- **Root cause:** Core Automation only self-healed from its admin screen, so
  the headless Diagnostics runner could reach `core.automation` with missing
  workflow/run/log tables.
- **Change:** Registered `BizCity_Automation_Installer::ensure()` with the
  canonical Site Provisioner, including its schema version option.
- **Validation:** VS Code diagnostics and static installer-contract checks are
  clean. PHP CLI is unavailable locally; CI matrix rerun remains required.
- **Deployment requirement:** Push and rerun all `diagnostics-mock` matrix jobs.
- **Status:** IMPLEMENTED LOCALLY — CI evidence pending.

### PHASE-DIAG-CI-MOCK — provision Scheduler and Cron schemas in headless CI

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-DIAG-CI-MOCK`, R-DDV, R-CR.
- **Affected paths:**
  - `core/scheduler/bootstrap.php`
  - `core/cron/bootstrap.php`
  - `core/diagnostics/docs/PHASE-DIAGNOSTICS-CI-MOCK-MODE.md`
- **Root cause:** Scheduler and Cron exposed tables to Diagnostics but did not
  register their existing schema installers with `BizCity_Site_Provisioner`.
  The headless runner intentionally avoids `admin_init`, so clean CI started
  without `bizcity_crm_events` and `bizcity_cron_registry`.
- **Change:** Registered `BizCity_Scheduler_Manager::ensure_schema()` and
  `BizCity_Cron_Manager::maybe_install()` with the canonical installer filter,
  including their version options for the provisioner report.
- **Validation:** VS Code diagnostics clean for both bootstrap files; static
  contract checks confirm the callbacks and version constants exist. PHP CLI is
  unavailable in this environment, so the four CI matrix jobs remain required.
- **Deployment requirement:** Push and rerun all `diagnostics-mock` matrix jobs;
  inspect the raw JUnit artifacts for the remaining probe failures.
- **Status:** IMPLEMENTED LOCALLY — CI evidence pending.

## 2026-08-21

### PHASE-DIAG-CI-MOCK — Diagnostics CLI runner (mock mode) CI stabilization

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-DIAG-CI-MOCK` (see
  [PHASE-DIAGNOSTICS-CI-MOCK-MODE.md](PHASE-DIAGNOSTICS-CI-MOCK-MODE.md) for the
  full root-cause map and roadmap status), R-DDV, R-DCL, R-CR.
- **Affected paths:**
  - `bin/diagnostics-run.php`
  - `core/diagnostics/includes/probes/class-probe-final-compose.php`
  - `core/diagnostics/includes/probes/class-probe-kg-graph-rag-ask.php`
  - `plugins/bizcity-zalo-bot/bootstrap.php`
  - `.gitignore`
- **Change:** Closed the remaining gaps in the ongoing CI mock-mode stabilization
  work: added the `BIZCITY_DIAGNOSTICS_MOCK` skip guard to `twin.final.compose`
  and `kg.graph.rag.ask` (both make a real LLM/embedding call and were missing
  the guard every sibling live-gateway probe already had); registered
  `BizCity_Zalo_Bot_Plugin::maybe_create_tables()` with the
  `bizcity_register_installers` filter so Site Provisioner — not only this
  bundled sub-plugin's dead `register_activation_hook()`/never-fired
  `admin_init` — provisions `bizcity_zalo_bots`/`bizcity_zalo_bot_logs` in
  headless CI, multisite new-blog, and admin self-heal contexts; added the
  missing `.gitignore` patterns for `bizcoach-pro`/`bizcity-twin-crm` (comments
  said "do NOT publish" but no ignore pattern existed).
- **Root cause:** see `PHASE-DIAGNOSTICS-CI-MOCK-MODE.md` §2 — headless CLI
  never ran schema installers that only relied on `admin_init`/activation
  hooks, and several live-gateway probes lacked the mock-mode skip guard.
- **Validation:** `get_errors` clean on all four touched PHP/gitignore files;
  no PHP syntax errors. CI rerun of `diagnostics-mock` (7.4/8.1 × 6.4/latest)
  still required to confirm the fail count collapses.
- **Deployment requirement:** push + CI rerun; no production deploy needed for
  this change (CI-only surface) beyond the normal repo sync.
- **Status:** IMPLEMENTED LOCALLY — CI rerun evidence pending. Remaining
  backlog tracked in `PHASE-DIAGNOSTICS-CI-MOCK-MODE.md` §4 (W7/W8).

### R-DDV-PRECONDITION-CONTRACT — honor string skip reasons

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-DIAG-CI-MOCK`, `R-DDV-PRECONDITION-CONTRACT`.
- **Affected paths:**
  - `core/diagnostics/includes/class-diagnostics-smoke-runner.php`
  - `core/diagnostics/includes/interface-diagnostics-probe.php`
  - `core/diagnostics/docs/PHASE-DIAGNOSTICS-CI-MOCK-MODE.md`
- **Root cause:** Active probes return both `WP_Error` and human-readable strings
  from `precondition()` for intentional skips. The runner only recognized
  `WP_Error`, so mock-mode strings such as `Mock mode: bỏ qua ...` were ignored
  and the live `run()` body still executed.
- **Change:** `run_probe()` now proceeds only when `precondition()` explicitly
  returns `true`; `WP_Error`, scalar string reasons, and other non-true values
  become `precheck-fail`. The interface documentation now matches the active
  implementation with `true|WP_Error|string`.
- **Validation:** VS Code diagnostics clean for both PHP files. The next CI
  matrix must confirm the mock SKIP count increases and live-gateway FAILs do
  not execute.
- **Deployment requirement:** Push the runner, interface, phase document and
  diagnostics ledger together, then rerun all four `diagnostics-mock` jobs.
- **Status:** IMPLEMENTED LOCALLY — remote CI evidence pending.

### R-DDV-SITE-PROVISIONER — Membership installer registration

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-DIAG-CI-MOCK`, `R-DDV`, `R-CR`.
- **Affected paths:** `core/membership/bootstrap.php` and
  `core/diagnostics/docs/PHASE-DIAGNOSTICS-CI-MOCK-MODE.md`.
- **Root cause:** The canonical `BizCity_Membership_Manager::maybe_upgrade()`
  already creates `bizcity_member_subscriptions` plus the existing usage and
  payments tables, but Membership registered only its Diagnostics table rows;
  headless Site Provisioner could not invoke the migration callback.
- **Change:** Registered the existing manager callback with
  `bizcity_register_installers`, using the existing version option and schema
  version. No new table or schema definition was added.
- **Validation:** VS Code diagnostics clean for `core/membership/bootstrap.php`;
  CI rerun required to prove the four critical missing-table errors collapse.
- **Deployment requirement:** Push the Membership bootstrap together with the
  CLI runner/orchestration fix, then rerun all `diagnostics-mock` matrix jobs.
- **Status:** IMPLEMENTED LOCALLY — runtime evidence pending.


## 2026-08-17

### R-DDV-CLASS-INDEX — Diagnostics symbol collision inventory

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-0-RULE-PROBE-LOADER-INTEGRITY`, `R-DDV-CLASS-INDEX`
- **Affected paths:**
  - `core/diagnostics/docs/CLASS-INDEX.md`
  - `core/diagnostics/docs/PHASE-0-RULE-PROBE-LOADER-INTEGRITY.md`
  - `core/diagnostics/docs/CHANGELOG.md`
- **Change:** scanned 198 active Diagnostics PHP files (excluding `_archived/`)
  and indexed 193 exact class/interface/trait declarations with relative file
  and line references.
- **Validation:** `DUPLICATE_NAMES=0`; `ANONYMOUS_CLASS_SITES=0`; index contains
  193 table rows; VS Code diagnostics clean for related files.
- **Policy:** regenerate the index for every declaration add/remove/rename/move;
  duplicate exact names or anonymous class sites block deployment unless an
  explicit exception is recorded here.
- **Status:** PASS snapshot 2026-08-17.

### R-DDV-PROBE-LOADER — Prevent probe redeclare fatal

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-0-RULE-PROBE-LOADER-INTEGRITY`, `R-DDV-PROBE-LOAD`
- **Affected paths:**
  - `core/diagnostics/bootstrap.php`
  - `core/diagnostics/includes/probes/class-probe-automation-runtime.php`
  - `core/diagnostics/includes/probes/class-probe-automation-runtime-impl.php`
  - `core/diagnostics/docs/PHASE-0-RULE-PROBE-LOADER-INTEGRITY.md`
  - `core/diagnostics/docs/CHANGELOG.md`
- **Incident:** production repeatedly logged `Cannot redeclare BizCity_Probe_A`,
  `Cannot redeclare BizCity_Diagnos`, and `Cannot redeclare class@anonymous` for
  the automation runtime probe. Renaming classes and file-scope guards did not
  solve the issue because PHP parses declarations before executing `return` or
  `define`, and the implementation was reachable through duplicate/stale load
  paths.
- **Change:** retired the unstable read-only automation runtime probe from the
  canonical queue and made both legacy probe paths class-free. No named class or
  anonymous class remains in the production-referenced files.
- **Validation:** VS Code diagnostics clean; exact declaration scan reports
  `NamedClass=0`, `Anonymous=0` for both retired paths; loader queue no longer
  registers `class-probe-automation-runtime.php`.
- **Deployment:** deploy all affected paths atomically; clear OPcache/PHP-FPM;
  verify the production file contents and monitor the next Diagnostics request.
- **Status:** fixed locally; production verification required.

## 2026-08-14

### Twin vertical picker UI parity

- **Owner:** Johnny Chu
- **Rule/phase:** `PHASE-TWB-WOO-BIZOPS`
- **Affected area:** TwinChat/Twin GPT UI and TwinWeb mode catalog.
- **Status:** recorded for cross-reference; details remain in the plugin-wide
  changelog and the relevant TwinBrain vertical documents.
