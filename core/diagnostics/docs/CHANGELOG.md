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

## 2026-09-18

### R-DDV / R-MSDB - Resume command lost the tenant and silently switched to mock network

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `R-DDV`, `R-MSDB`, `R-CLI-CONTRACTS`.
- **Trigger:** resuming `diag_20260918024456_2edcf34f` (written on blog `1511`)
  with the printed `Resume command:` returned
  `"Diagnostics checkpoint is missing or catalog hash changed."` on blog `1535`.
  `catalog_hash` and `batch_hash` were identical to the original run, so the
  hashes were not the cause.
- **Root cause:** checkpoints live in the per-blog option
  `bizcity_diag_checkpoints`. The printed resume command omitted `--wp-root` and
  `--host`, so the hostless CLI resolved a different blog and could not find
  the checkpoint. The same command also always appended `--skip-network`, which
  would silently turn a real-network run into mock evidence on resume. The
  error text merged "not found" and "hash changed" into one message, hiding the
  tenant mismatch.
- **Affected paths:** `bin/diagnostics-run.php` (resume command builder);
  `core/diagnostics/includes/class-diagnostics-smoke-runner.php` (resume error).
- **Fix:** the resume command now carries the original `--wp-root`/`--host`
  (shell-escaped), mirrors `--skip-network` only when the original run used it,
  and keeps `--format=json` in machine mode. The resume error now distinguishes
  "checkpoint not found on blog_id=N" (with the `--host` hint) from a catalog or
  batch hash change.
- **Validation:** `php -l` clean on both files. The builder block, executed
  in isolation with `wp-root=/srv/site`, `host=example.test`, no
  `--skip-network`, produced
  `php bin/diagnostics-run.php --wp-root="/srv/site" --host="example.test" --batch="channel" --resume="diag_x" --skip-provision --format=json`.
  Runtime evidence deferred until the next bounded batch stops on the target.
- **Rollback boundary:** revert the two edits; checkpoints and hashing unchanged.
- **Status:** code fixed and linted; target Runtime evidence deferred.

### R-DDV - Three channel-batch probes failed on stale or broken assertions

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `R-DDV`, `R-ZONE`, `R-CH-FILE-LOG`, `PHASE-0.41-CRM-ONE-BRAIN`.
- **Trigger:** post-deploy `--batch=channel` run
  `diag_20260918024456_2edcf34f` (blog `1511`, PHP `7.4.33`) reported
  `8 pass · 3 fail · 31 budget_deferred`. All three failures were probe
  defects, not runtime regressions; each was traced before editing.
- **Affected paths:**
  `core/diagnostics/includes/probes/class-probe-channel-manifest-registration.php`;
  `core/diagnostics/includes/probes/class-probe-channel-log-ownership.php`;
  `core/diagnostics/includes/probes/class-probe-twinweb-zalo-connect-ui.php`.
- **Root causes and fixes:**
  - `core.channel.manifest_registration` hard-coded four channel slugs with an
    exact-set comparison. The shipped manifest and `core.channel.manifest_compat`
    (PASS in the same run) declare five: `mabel_wheel` was added later. The
    expected set now includes `mabel_wheel`; the Disk step reports the expected
    and actual slug sets and the `fix_hint` names the exact manifest content.
  - `core.legacy_table.channel_log_ownership` expected bare `messenger` to be an
    authorized customer channel and bare `zalo` to resolve to zone `unknown`.
    Since PHASE-0.41, `BizCity_CRM_Channel_Contract::describe()` deliberately
    quarantines both aliases (zone `legacy`, CRM disabled) and
    `core.channel.manifest_compat` asserts that fail-closed behaviour. The probe
    now asserts the exact codes (`facebook`, `zalo_oa`, `zalo_personal`,
    `mabel_wheel`, `zalo_bot`) and treats both aliases as quarantined; the
    matrix step names every mismatching code. The probe also returned no
    `fix_hint` (flagged by `evidence_audit.missing_probe_ids`); it now lists the
    failing steps, the owning classes and the narrow rerun command.
  - `twingpt.mychannels.zalo_connect_ui` searched
    `"'c' !== strtolower( $surface )"` in a double-quoted string, so PHP
    interpolated the undefined `$surface` to an empty string and the check could
    never pass on any host. Its separate six-entry report list omitted the real
    culprit, producing `fix_hint: "missing markers: "`. One named 23-check table
    now drives both verdict and report, every literal is single-quoted, and all
    built `dist/assets/*.js` chunks are read instead of only the first file.
- **Validation:** `PHP_BIN=C:\Users\Admin\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.1_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`;
  `php -l` clean on all three files; `php bin/validate-php74.php` PASS for the
  whole tree. A standalone marker evaluation against the local source showed all
  15 backend markers present and only the interpolated literal missing, which
  confirms the diagnosis. The manifest JSON declares exactly the five expected
  slugs, each with `crm_policy=enabled` and the zone the probe asserts.
  **Runtime evidence is deferred**: no local WordPress/DB run was attempted
  (LocalWP fail-closed rule); the fixed probes must be rerun on the target.
- **Deployment requirement:** deploy the three probe files, then run the focused
  command below on the target and attach the JSON:
  `php bin/diagnostics-run.php --filter=core.channel.manifest_registration,core.legacy_table.channel_log_ownership,twingpt.mychannels.zalo_connect_ui --skip-provision --format=json`.
  The 31 `budget_deferred` channel probes still need
  `--resume=diag_20260918024456_2edcf34f`.
- **Rollback boundary:** revert the three probe files only; no runtime owner,
  schema or contract changed.
- **Runtime evidence (target):** focused rerun on host `libedemo.bizcity.vn`,
  blog `1511`, PHP `7.4.33`: `3 pass · 0 fail · 0 skip`, `verdict=pass`,
  `evidence_audit.missing_probe_ids=[]`. `manifest_registration` reported five
  manifests with adapter/policy parity; `channel_log_ownership` passed all five
  steps; `zalo_connect_ui` found all 23 markers. Its `two-key isolation` step
  remains `skip` (needs two deployed Hub API-key fixtures) and is not evidence.
- **Status:** Runtime PASS for the three probes on the target. The 31
  `budget_deferred` channel probes are still unexecuted.

## 2026-09-07

### PHASE-0.41B - Tool Image schema registry probe

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-0.41B`, `R-DDV`, `R-DCL`.
- **Affected paths:** `core/diagnostics/bootstrap.php`;
  `core/diagnostics/includes/probes/class-probe-tool-image-schema-changelog.php`.
- **Intent:** Add a read-only Disk/Loader/Runtime check for the 10-table Tool
  Image changelog and central Schema Registry. An inactive standalone plugin
  is reported as `SKIP`, never as a false runtime PASS or FAIL.
- **Validation:** `PHP_BIN=C:\php\php.exe`, PHP `7.4.4`; both new probes and
  `core/diagnostics/bootstrap.php` linted successfully. Focused run returned
  `1 pass · 0 fail · 1 skip`: `plugins.print_ads.tool_image_degrade` passed
  Disk/Loader/Runtime, while `modules.tool_image.schema_changelog` passed
  Disk/catalog and explicitly skipped runtime registry because Tool Image is
  inactive in the local bootstrap.
- **Deployment requirement:** Deploy the probe and bootstrap together; run
  `modules.tool_image.schema_changelog` on a target where Tool Image is active
  to close runtime registration evidence.
- **Rollback boundary:** Remove only the probe and queue entry; retain the
  schema catalog and runtime registrations.
- **Status:** Print-Ads local Runtime PASS; Tool Image target registry Runtime
  evidence deferred.

### PHASE-0.41B - Print-Ads Tool Image graceful-degrade probe

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-0.41B`, `R-DDV`, `R-ERROR-UX`.
- **Affected paths:** `core/diagnostics/bootstrap.php`;
  `core/diagnostics/includes/probes/class-probe-print-ads-tool-image-degrade.php`.
- **Intent:** Verify the existing Print-Ads dependency guard without invoking a
  provider or creating a generation row. The negative-path fixture runs only
  when Tool Image is inactive and expects
  `bzcrm_print_ads_missing_image_plugin`.
- **Validation:** `PHP_BIN=C:\php\php.exe`, PHP `7.4.4`; focused probe returned
  `1 pass · 0 fail · 0 skip` and confirmed the canonical dependency WP_Error
  before provider or generation-row work. A request with Tool Image already
  active is intentionally deferred rather than deactivated mid-request.
- **Deployment requirement:** Deploy the probe/bootstrap together and run it on
  a target with Print-Ads loaded and Tool Image inactive.
- **Rollback boundary:** Remove only the probe and queue entry; retain the
  existing Print-Ads dependency guard.
- **Status:** Local Disk/Loader/Runtime PASS; production target evidence remains
  deferred.

### PHASE-0.41-CX2 - CRM user-inbox scope probe

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-0.41-CX2`, `R-DDV`, `R-ZONE`, `user-inbox-scope@1.0.0`.
- **Affected paths:** `core/diagnostics/bootstrap.php`;
  `core/diagnostics/includes/probes/class-probe-user-inbox-scope.php`.
- **Intent:** Verify the CRM-owned B2/C scope envelope before downstream
  Context Bank/KG/TwinBrain/MCP consumers. The probe reports the CRM-to-Context
  Bank adapter boundary as `SKIP` until the same principal/account envelope is
  consumed by a canonical handoff.
- **Validation:** `PHP_BIN=C:\php\php.exe`, PHP `7.4.4`; probe/bootstrap lint
  passed. The current focused run returned `1 pass · 0 fail · 0 skip` at the
  probe verdict level. Disk/Loader/B2-C Runtime and Contacts projection passed;
  Context Bank admission and two-user foreign-Personal canary remained
  explicit `SKIP` because the local tenant lacks those fixtures.
- **Deployment requirement:** Deploy the probe/bootstrap together; run with CRM
  loaded and an authenticated user fixture for B2/C Runtime evidence.
- **Rollback boundary:** Remove only the probe and queue entry; retain the CRM
  B2/C forwarding fix and existing Context Bank authorization owners.
- **Validation:** `PHP_BIN=C:\php\php.exe`, PHP `7.4.4`; probe/bootstrap lint
  passed. Focused `core.crm.user_inbox_scope` returned `1 pass · 0 fail · 0
  skip` with Disk/Loader/B2-C Runtime PASS. The CRM-to-Context-Bank handoff
  step remains explicitly `SKIP` because no canonical adapter consumes the same
  scope envelope.
- **Status:** CRM resolver, B2/C envelope, CX1 Contacts projection and CX2
  bridge Disk/Loader evidence PASS locally; Context Bank admission, two-user
  canary and production evidence remain deferred.

### PHASE-DIAG-PERF - bounded MCP/TwinWeb metadata verification

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-DIAG-PERF`, `R-METADATA-CACHE`, `R-PERF.5`.
- **Affected paths:** `core/helper/class-bizcity-table-metadata.php`;
  `core/diagnostics/includes/probes/class-probe-table-metadata.php`;
  `core/mcp/includes/class-mcp-installer.php`;
  `modules/twinweb/includes/class-twinweb-installer.php`.
- **Intent:** Batch cold table checks through the canonical tenant/database-aware
  metadata cache and bound healthy-schema physical verification to a 10-minute
  per-blog/version/database stamp. DDL now invalidates the same canonical cache
  key, including previously cached missing-table results.
- **Validation:** `PHP_BIN=C:\php\php.exe`, PHP `7.4.4`; all four touched PHP
  files linted successfully. Focused `core.helper.table_metadata` returned
  `1 pass · 0 fail · 0 skip`; its Runtime steps proved one cold batch query,
  repeated batch cache hit, false-result cache and DDL generation invalidation.
  The selected run has `coverage.complete=false` because it is intentionally a
  filtered probe, not a full diagnostics batch.
- **Deployment requirement:** Deploy helper, probe and both installers together;
  repeat the same mapped-domain request twice and confirm Query Monitor shows no
  repeated `information_schema.TABLES` query during the 10-minute healthy-schema
  window. Production evidence is pending.
- **Rollback boundary:** Revert only the batch helper, verification stamps and
  installer invalidation calls; retain schema version options, DDL repair paths
  and central Schema Registry ownership.
- **Status:** Local Disk/Loader/Runtime cache evidence PASS; production/runtime
  Query Monitor evidence deferred.

## 2026-09-03

### PHASE-0.41B - Tool Image schema catalog prerequisite

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-0.41B`, `R-DCL`, `R-CR`.
- **Affected paths:** `core/diagnostics/changelog/modules.tool-image.json`;
  `core/diagnostics/includes/class-diagnostics-table-registry.php`;
  `plugins/bizcity-tool-image/includes/install.php`.
- **Intent:** Close the pre-existing Tool Image schema catalog gap before any
  retirement or table cleanup action. The catalog now declares all 10 existing
  `bztimg_*` tables, current columns/indexes and baseline history; Diagnostics
  and central Schema registries cover the same 10 tables.
- **Validation:** `PHP_BIN=C:\php\php.exe`, PHP `7.4.4`; installer and registry
  lint passed, `get_errors` reported no errors, and
  `php core/diagnostics/validate-schema-changelog.php --json` returned
  `38 files`, `0 errors`, `0 warnings`. Runtime registration on a deployed
  site remains deferred until the standalone Tool Image plugin is active in the
  target bootstrap.
- **Deployment requirement:** Deploy the changelog, registry and installer
  together; run the schema registry/Diagnostics runtime check on a target where
  Tool Image is active. This catalog evidence does not authorize DROP.
- **Rollback boundary:** Revert only the Tool Image catalog and file-scope
  registrations; retain the existing Tool Image installer DDL and plugin data.
- **Status:** Static R-DCL/catalog PASS; runtime loader evidence deferred.

### PHASE-CB6.3/CB6.4 - KG citation owner and deferred recheck boundary

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB6.3`, `PHASE-CB6.4`, `R-DDV`, `R-CLI-ASYNC-ISOLATION`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-kg-bridge.php`;
  `core/context-bank/bootstrap.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-kg-bridge.php`.
- **Intent:** Keep KG semantic extraction in KG-Hub, resolve citations through
  one verified tenant pointer and canonical owner view, and preserve verified
  Context Bank candidates as pending when KG infrastructure is unavailable.
- **Validation:** PHP `7.4.4` lint passed. Focused
  `core.context_bank.kg_bridge` returned `1 pass / 0 fail / 0 skip`, with 15/15
  Disk/Loader/Runtime steps on blog `1526`; invalid citation identity and
  direct Diagnostics CLI recheck entry both failed closed. Filtered coverage
  reported `complete=false`.
- **Deployment requirement:** Deploy bridge, Context Bank bootstrap and probe
  together; rerun the focused probe, then run the approved same-tenant KG
  notebook fixture for promoted citation/vector evidence.
- **Rollback boundary:** Disable KG promotion/recheck consumption and keep
  canonical Context Bank/KG owners; do not delete KG rows from the bridge.
- **Status:** Owner-chain/source evidence PASS; promoted citation, stale/rebuild,
  unavailable-KG runtime and full aggregate evidence remain pending.

### PHASE-CB3.4 - bounded reconciler failure-path probe

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB3.4`, `R-DDV`, `R-CRON-META`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-reconciler.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-reconciler.php`;
  `core/diagnostics/bootstrap.php`.
- **Intent:** Keep file-to-ledger reconciliation fail-closed when the source
  cursor regresses or checkpoint persistence cannot be confirmed, and expose a
  focused Disk/Loader/Runtime diagnostic without writing business fixtures.
- **Validation:** PHP `7.4.4` lint passed. Focused
  `core.context_bank.reconciler` returned `1 pass / 0 fail / 0 skip`, with 6/6
  steps on blog `1526`; the filtered run reported `coverage.complete=false`.
  Existing `SDK_MISSING` and unrelated CRM schema warnings remained outside
  this probe's result.
- **Deployment requirement:** Deploy the reconciler, diagnostics probe and
  queue registration together; rerun the focused probe, then the canonical
  release batches after approved failure-injection and retention fixtures.
- **Rollback boundary:** Disable reconciliation workers and retain the prior
  signed checkpoint; do not mark a source partition complete or delete
  canonical filestore/Event Stream/KG data.
- **Status:** Focused failure-path evidence PASS; concurrent append, injected
  file/SQL failure, retention/legal-hold, erasure/rollback and complete
  aggregate evidence remain pending.

### PHASE-CB3.4 - reconciler exception safety

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB3.4`, `R-DDV`, `R-CRON-META`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-reconciler.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-reconciler.php`.
- **Intent:** Convert reader, ledger-admission and checkpoint option
  exceptions into bounded failed batches that retain the prior cursor and emit
  no false checkpoint advancement.
- **Validation:** PHP `7.4.4` lint passed. Focused
  `core.context_bank.reconciler` returned `1 pass / 0 fail / 0 skip`, 6/6
  steps, on blog `1526`; `coverage.complete=false` for the filtered run.
  The run also surfaced pre-existing `BIZCIT` undefined-constant and CRM
  schema warnings outside this slice.
- **Deployment requirement:** Deploy the reconciler and probe together; rerun
  the focused probe, then approved file/SQL failure-injection fixtures.
- **Rollback boundary:** Disable reconciliation workers and retain the prior
  signed checkpoint; never mark a source partition complete after an exception.
- **Status:** Exception-safe source evidence PASS; real failure injection,
  retention/legal-hold, erasure/rollback and complete aggregate evidence remain
  pending.

### PHASE-CB7.3 - metadata REST exception safety

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB7.3`, `R-DDV`, `R-ERROR-UX`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-rest-controller.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-rest.php`;
  `core/diagnostics/bootstrap.php`.
- **Intent:** Keep Context Bank same-origin metadata reads fail-graceful when
  Search, ledger or pointer-follow owners are unavailable or throw, while
  preserving server-side scope and the metadata-only projection.
- **Validation:** PHP `7.4.4` lint passed. Focused
  `core.context_bank.rest` returned `1 pass / 0 fail / 0 skip`, with 7/7 steps
  on blog `1526`. The probe verified unauthenticated denial, admin access,
  valid-owner access, modified-owner denial using the fail-open
  `200 + permission_denied` envelope, missing-record error fields and
  disposable-user cleanup; filtered coverage reported `complete=false`.
- **Deployment requirement:** Deploy controller, probe and queue registration
  together; rerun the focused probe and then the live HTTP permission matrix.
- **Rollback boundary:** Disable the Context Bank REST/UI feature and retain
  read-only diagnostics; do not expose direct ledger/file access.
- **Status:** Local HTTP owner matrix and REST boundary evidence PASS; mapped
  domain HTTP, browser/UI, action-route and full aggregate evidence remain
  pending.

### PHASE-CB7.3 - mapped-domain REST parity probe

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB7.3`, `R-DDV`, `R-ERROR-UX`.
- **Affected paths:** deployed `bizcity-context/v1` REST surface; local
  `core/context-bank/includes/class-context-bank-rest-controller.php` is the
  intended source boundary.
- **Intent:** Compare the exact mapped-domain unauthenticated response with the
  local handler-owned four-field error contract.
- **Validation:** `https://libedemo.bizcity.vn/wp-json/bizcity-context/v1/records?limit=1`
  returned HTTP `401`, JSON body length `161`, with `code/message` markers but
  no `hint/help_code`. This is HTTP response evidence only; no VPS PHP log was
  read and no PHP runtime conclusion is made.
- **Deployment requirement:** Deploy the current REST controller and rerun the
  exact unauthenticated request plus authenticated owner/admin matrix.
- **Rollback boundary:** Keep the Context Bank REST/UI feature disabled or
  read-only until deployed error-envelope parity is verified.
- **Status:** Local REST matrix PASS; mapped deployment parity FAIL/pending.

## 2026-09-02

### PHASE-CB6.3/CB6.4 - bounded KG retry and release error classification

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB6.3`, `PHASE-CB6.4`, `R-DDV`,
  `R-CLI-ASYNC-ISOLATION`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-kg-bridge.php`;
  `core/context-bank/bootstrap.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-kg-bridge.php`.
- **Intent:** Bound KG-unavailable rechecks to three attempts, preserve
  verified Context Bank state as pending, and distinguish source evidence from
  a full diagnostics bootstrap failure.
- **Validation:** PHP `7.4.4` lint passed. With
  `--skip-provision --isolated-mu --skip-network`, focused
  `core.context_bank.kg_bridge` returned `1 pass / 0 fail / 0 skip`, 15/15
  steps on blog `1526`, including invalid citation refusal, KG-Hub ownership
  and Diagnostics CLI retry isolation. A separate full/bootstrap attempt still
  returned `diagnostics_bootstrap_fatal` in the active Object Cache Pro/DB
  routing path before probe execution.
- **Deployment requirement:** Deploy bridge, bootstrap and probe together;
  rerun the focused probe, then run the approved same-tenant notebook fixture
  and the full diagnostics batches only after Object Cache/DB routing is fixed.
- **Rollback boundary:** Disable KG promotion and retry consumption; retain
  pending Context Bank pointers and canonical KG/source owners.
- **Status:** Bounded source/default-path evidence PASS; physical KG canary,
  full aggregate and production release evidence remain pending.

### PHASE-CB6.4 - bounded KG provenance reconciliation

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB6.4`, `R-DDV`, `R-CRON-META`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-ledger.php`;
  `core/context-bank/includes/class-context-bank-kg-bridge.php`;
  `core/diagnostics/includes/probes/class-probe-context-bank-kg-bridge.php`.
- **Intent:** Reconcile derived KG provenance through the canonical ledger and
  KG facade without direct KG SQL deletion or cross-tenant pointer follow.
- **Validation:** PHP `7.4.4` lint and editor diagnostics passed. Focused
  `core.context_bank.kg_bridge` returned `1 pass / 0 fail / 0 skip` with 10/10
  steps, including owner-routed reconciliation source order, notebook-owner
  ordering, replay authorization, Cost Guard ordering and tampered replay
  refusal. The probe is feature-off/default-path evidence; no promoted KG row
  was mutated.
- **Deployment requirement:** Deploy ledger, bridge and probe together. Rerun
  the standalone G4 fixture only with an approved same-tenant notebook to
  exercise stale/rebuild provenance and cleanup.
- **Rollback boundary:** Disable KG promotion/reconcile consumption and retain
  canonical source/KG owners; do not delete KG rows from the bridge.
- **Status:** CB6.4 owner-routed implementation/source evidence PASS; promoted
  KG stale/rebuild, retention/legal-hold and operational aggregate evidence
  remain pending.

### R-DDV - explicit probe batch mismatch is no longer silent

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `R-DDV`, `PHASE-1.31-S2`.
- **Affected paths:** `bin/diagnostics-run.php`.
- **Intent:** Fail closed when an exact probe ID is requested with an
  incompatible explicit batch instead of silently reducing `selected_total`.
- **Validation:** The CLI now emits `diagnostics_filter_batch_mismatch`, the
  requested batch, canonical batch and probe ID before execution; PHP 7.4.4
  lint is required. The Context Bank ledger probe is canonically in the
  `legacy` batch, so it must be rerun with `--batch=legacy` rather than
  `--batch=core`. Local corrected rerun with `PHP_BIN=C:\\php\\php.exe`,
  PHP `7.4.4`, mapped blog `1511` returned `1 pass / 0 fail / 0 skip` for
  `core.context_bank.ledger`, including physical pointer-only schema and
  receipt round-trip evidence.
- **Deployment requirement:** Deploy the runner and changelog entry together;
  rerun exact probe IDs and inspect the JSON envelope before interpreting the
  result as Runtime evidence.
- **Rollback boundary:** Revert only the pre-execution mismatch guard; retain
  the existing batch ownership catalog and probe implementations.
- **Status:** Source guard, local validation and corrected VPS ledger probe
  execution PASS; two-shard comparison and complete release aggregate remain
  pending.

### PHASE-CB-VPS-2026-09-02 - core/channel rerun and ledger deployment evidence

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `R-DDV`, `R-MSDB`, `PHASE-CB-G3`, `PHASE-CB-G4`.
- **Affected paths:** VPS deployed Context Bank probes, ledger loader and
  `build/context-bank-core-rerun.xml` / `build/context-bank-channel-rerun.xml`.
- **Intent:** Record the latest mapped-host probe evidence without treating a
  filtered pass as a complete release aggregate.
- **Validation:** VPS resolved `PHP_BIN=/usr/local/bin/php`, PHP `7.4.33`,
  WordPress `6.9`, host `libedemo.bizcity.vn`, blog `1511`. The core command
  returned `8 pass / 0 fail / 0 skip`, but selected only 8 results although the
  filter requested `core.context_bank.ledger`; ledger was absent from the
  result map and remains unverified. The clean channel command returned
  `2 pass / 0 fail / 0 skip`, executing both
  `core.context_bank.channel_admission` and
  `core.context_bank.channel_crm_continuity`; JUnit was written to
  `build/context-bank-channel-rerun.xml`.
- **Runtime log boundary:** The canonical log
  `/home/vibeyeuc/huongnguyen.vibeyeu.com.vn/wp-content/bps-backup/logs/bps_php_error.log`
  was read through approximately `13:52 UTC`. It contains a
  `context_bank.ledger` Safe Loader `ParseError` (`unexpected end of file`,
  deployed line `367`) and other loader/CRM/Woo/router failures. These are
  retained as deployment/runtime blockers; later selected probes do not erase
  the incident or prove a clean full aggregate.
- **Deployment requirement:** The corrected ledger rerun uses
  `--batch=legacy --skip-provision`; it returned `1 pass / 0 fail / 0 skip`,
  `verdict=pass`, `duration_ms=586`, 11/11 steps and JUnit
  `build/context-bank-ledger-vps.xml`. Then rerun the complete required
  Context Bank core/channel batches and inspect every JSON result plus the
  canonical PHP log.
- **Rollback boundary:** No fixture mutation was introduced by these commands;
  keep Context Bank feature flags off until ledger parity and the full release
  aggregate are verified.
- **Status:** Channel continuity `PASS 2/2`; core component subset `PASS 8/8`
  and corrected ledger probe `PASS 1/1` in the canonical `legacy` batch;
  production aggregate `DEFERRED/BLOCKED`.

### PHASE-CB-VPS-LEDGER-2026-09-02 - current-tenant physical ledger PASS

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB3.1`, `R-DDV`, `R-MSDB`.
- **Affected paths:** VPS `core.context_bank.ledger` probe and
  `build/context-bank-ledger-vps.xml`.
- **Validation:** With `PHP_BIN=/usr/local/bin/php`, PHP `7.4.33`, WordPress
  `6.9`, host `libedemo.bizcity.vn`, blog `1511`, `--batch=legacy`,
  `--skip-provision`, `--skip-network` and exact ledger filter, the probe
  returned `1 pass / 0 fail / 0 skip`, `verdict=pass`, `duration_ms=586`,
  `selected_total=1`, `executed=1`, `coverage.complete=false`. All 11 steps
  passed: route/keymeta, physical pointer-only table, foreign-blog refusal,
  16 EXPLAIN shapes, receipt admission, bounded follow, replay, pointer
  conflict and tombstone update.
- **Boundary:** This is current-tenant physical VPS ledger evidence. It does
  not close G1 two-shard isolation, G2 production cron aggregate or the full
  release aggregate. Historical ledger ParseError remains incident history;
  the corrected deployed probe executed successfully.
- **Status:** VPS current-tenant ledger Runtime PASS; full release readiness
  remains pending.

### R-DDV - Diagnostics loads Intent registrations for tool-registry parity

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Include the `bizcity-diagnostics` admin page in the narrow Intent
  runtime gate so `BizCity_Intent_Tools` is available before the unified
  `BizCity_Tool_Registry` adapters run at `init`.
- **Affected paths:** `bizcity-twin-ai.php`, `core/tools/bootstrap.php`,
  `core/diagnostics/includes/probes/class-probe-webchat-tool-registry-parity.php`.
- **Root cause:** The focused admin probe loaded the unified registry class but
  skipped `core/intent/bootstrap.php`; tool-group registration therefore
  returned early and `get_for_js()` produced an empty catalog.
- **Validation:** Source change applied; PHP lint and authenticated VPS admin
  probe rerun are pending. The screenshot remains a runtime FAIL until the
  focused probe returns `status=pass` with a non-empty `tool_count`.
- **Deployment:** Deploy the updated plugin entrypoint, then rerun only
  `core.webchat.tool_registry_parity` from `tools.php?page=bizcity-diagnostics`.
- **Status:** SOURCE FIX APPLIED - runtime evidence pending.

### PHASE-CB-G4 - same-tenant KG notebook ownership gate

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB-G4`, `PHASE-CB7.1`, `R-MSDB`, `R-DDV`.
- **Affected paths:** `core/context-bank/includes/class-context-bank-kg-bridge.php`;
  `bin/context-bank-kg-fixture.php`; `core/diagnostics/includes/probes/class-probe-context-bank-kg-bridge.php`.
- **Intent:** Require the canonical KG notebook service to authorize the
  notebook owner before Context Bank promotion creates any KG row.
- **Validation:** Focused KG bridge probe passed notebook-authorization order,
  replay provenance order and tampered replay refusal. Read-only ownership
  lookup found zero notebook rows on mapped blogs `1511` and `1526`, so the
  standalone G4 fixture returned `status=partial`,
  `reason=g4_notebook_unavailable`, with zero mutation and cleanup complete.
- **Deployment requirement:** Supply an approved notebook ID owned by the
  explicit operator in the same routed tenant, then rerun the standalone G4
  fixture. Do not create or select a cross-tenant/public fallback implicitly.
- **Rollback boundary:** Remove only the fixture/probe authorization additions;
  retain fail-closed promotion behavior and do not delete canonical notebook or
  KG data.
- **Status:** Authorization implementation PASS; G4 Runtime promotion deferred
  by missing same-tenant notebook.

### PHASE-CB-G3/G4 - standalone lifecycle and KG precondition fixtures

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB-G3`, `PHASE-CB-G4`, `R-DDV`, `R-MSDB`.
- **Affected paths:** `bin/context-bank-commerce-fixture.php`;
  `bin/context-bank-kg-fixture.php`; Context Bank commerce/KG bridge owners.
- **Intent:** Add explicit, outside-Diagnostics runtime artifacts for linked
  Woo relation/lifecycle projection and KG forward/reverse provenance. Both
  fixtures are tenant-explicit, bounded and cleanup-first.
- **Validation:** G3 on mapped blog `1511` with `PHP_BIN=C:\\php\\php.exe`,
  PHP `7.4.4`, host `libedemo.bizcity.vn` and admin `3539` passed seven
  lifecycle projections, linked contact/two-conversation relation, replay,
  pointer follow and cleanup. It returned `partial` only because no canonical
  production inventory producer is registered. G4 returned `partial` with
  `reason=g4_notebook_unavailable` before mutation because the selected tenant
  has no canonical notebook. Both fixtures were PHP 7.4 lint-clean.
- **Deployment requirement:** Run G3 only on an approved disposable Woo tenant.
  Run G4 only with an approved notebook owned in the same routed tenant; never
  guess a notebook ID, fall back to blog 1 or create a test owner implicitly.
- **Rollback boundary:** Fixtures tombstone/remove only their derived pointers
  and disposable KG/Woo records; they do not delete CRM, Woo, Event Stream or
  canonical notebook data.
- **Status:** G3 `PARTIAL` with inventory-owner evidence deferred; G4
  `DEFERRED PRECONDITION` with no KG mutation. Neither gate is closed.

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

### PHASE-1.30-G3-BOOTSTRAP-ISOLATION - direct wp-load completes in main-site context

- **Evidence:** Direct EA-PHP CLI `wp-load.php` test returned
  `wp_load_exit=0` and `WP_LOAD_OK`, using PHP `7.4.33`. The command did not
  set `HTTP_HOST`, so it resolved `blog_id=1`; it is not proof of target blog
  `1511` routing.
- **Diagnostic:** Shutdown reported only non-fatal `E_NOTICE` for missing
  `$_SERVER['REQUEST_METHOD']` in active MU-plugin
  `bizcity-myaccount-phone.php` line `87`. This does not explain the earlier
  WP-CLI Cron `exit 255` by itself.
- **Next action:** Repeat direct bootstrap with
  `HTTP_HOST=libedemo.bizcity.vn` and `REQUEST_METHOD=GET`, then run
  `option get siteurl` through `/opt/cpanel/ea-php74/root/usr/bin/php`
  as `vibeyeuc` before retrying the retention hook.
- **Status:** ISOLATION PASS - target-host Cron failure remains unresolved.

### PHASE-1.30-G3-TARGET-BOOTSTRAP - mapped host loads blog 1511

- **Evidence:** Direct EA-PHP CLI with `HTTP_HOST=libedemo.bizcity.vn` and
  `REQUEST_METHOD=GET` returned `wp_load_target_exit=0`, `WP_LOAD_OK`,
  `blog_id=1511` and the expected host using PHP `7.4.33`.
- **Diagnostic:** The shutdown handler reported only a non-fatal `E_NOTICE` for
  missing `$_SERVER['SERVER_NAME']` in the active Transposh language-switcher
  plugin. This is not a WordPress bootstrap fatal and does not by itself
  explain WP-CLI exit `255`.
- **Next action:** Run `option get siteurl` and `cron event list` as
  `vibeyeuc` through the EA-PHP CLI with both `HTTP_HOST` and `SERVER_NAME`
  defined, then retry the retention hook only after those checks pass.
- **Status:** TARGET BOOTSTRAP PASS - Cron command failure remains unresolved.

### PHASE-1.30-G3-WPCLI-CONTEXT - scoped option command still exits 255

- **Evidence:** The WP-CLI `option get siteurl` command ran as user
  `vibeyeuc` through the EA PHP CLI with `HTTP_HOST`, `SERVER_NAME` and
  `REQUEST_METHOD` set to the mapped host context, but returned
  `option_target_exit=255`. Debug output stopped during the WordPress-load
  phase, before the command result.
- **Classification:** This is not the WP-CLI root-user refusal, CGI SAPI
  failure or a missing `SERVER_NAME` value. Direct target `wp-load.php` without
  the WP-CLI context returned `WP_LOAD_OK blog_id=1511`; the two execution
  contexts are not equivalent.
- **Next action:** Test direct target bootstrap with `WP_CLI` defined, then
  correlate the exact timestamp with the canonical VPS PHP log before running
  the retention hook again.
- **Status:** FAIL - WP-CLI context/bootstrap boundary remains unresolved;
  G3 Cron run/meta evidence is not available.

### PHASE-1.30-G3-WPCLI-HARNESS - manual WP_CLI simulation is not production evidence

- **Evidence:** The manual direct-PHP simulation defined `WP_CLI=true` but did
  not load the WP-CLI `WP_CLI` class. It therefore failed at active MU-plugin
  `bizcity-as-cron-trace.php:112` with `Class 'WP_CLI' not found`, after
  rendering the maintenance response.
- **Classification:** Harness-context failure, not proof that the real WP-CLI
  runner lacks its class. The direct target bootstrap without this artificial
  constant passed with `blog_id=1511`; the correctly scoped real WP-CLI option
  command still exits `255` and requires separate canonical-log correlation.
- **Next action:** Audit the deployed MU-plugin condition for a
  `class_exists('WP_CLI')` guard, then use a real WP-CLI invocation or a
  complete WP-CLI class context for the next reproduction. Do not run the
  retention hook until the command boundary is understood.
- **Status:** DIAGNOSTIC CLARIFICATION - no G3 Cron PASS evidence.

### PHASE-1.30-G3-WPCLI-LOG-CORRELATION - truncated undefined-call failures

- **Evidence:** The canonical VPS PHP log records WP-CLI failures at
  `16:36:31`, `16:48:55` and `16:53:27` Asia/Ho_Chi_Minh as truncated
  `Call to undefine` messages from the WP-CLI Runner eval path at line `427`.
- **Classification:** The excerpt does not contain the missing callable name,
  so it cannot prove a function literally named `undefine()`. The separate
  `16:50:35` `Class 'WP_CLI' not found` entry came from the incomplete manual
  simulation and is tracked separately as harness-only.
- **Next action:** Search only active deployed runtime trees for `undefine(`
  and `WP_CLI::add_command`, excluding backup/archive trees, then correlate
  the exact callable with the canonical PHP log before rerunning retention.
- **Status:** FAIL - real WP-CLI undefined-call boundary remains unresolved;
  G3 Cron run/meta evidence is unavailable.

### PHASE-1.30-G3-WPCLI-SWEEP - no active undefine() call found

- **Evidence:** The deployed active-tree sweep covered
  `wp-content/mu-plugins`, `bizcity-twin-ai/core`, `includes` and `bin` while
  excluding backup/archive/library trees. It found no `undefine()` call and
  reported only normal `WP_CLI::add_command` registration sites.
- **Classification:** Negative static evidence only. It does not override the
  real WP-CLI `255` records; the truncated message may come from an
  eval-generated callable, rewritten logger message or deployed/source drift.
- **Next action:** Compare deployed hashes for the relevant MU/plugin files with
  the release source and obtain the complete fatal message from the canonical
  PHP log. Do not mass-edit all WP-CLI registrations.
- **Status:** INVESTIGATION - G3 Cron run/meta evidence remains unavailable.

### PHASE-1.30-G3-WPCLI-CONFIG-SCOPE - root wp-config.php line 427 is now in scope

- **Evidence:** The real WP-CLI fatal is reported from
  `Runner.php(1334) : eval()'d code on line 427` as a truncated
  `Call to undefine`. The prior active-tree sweep covered `wp-content` runtime
  trees but did not include the root `wp-config.php`.
- **Classification:** The previous negative `undefine()` sweep was incomplete
  for this error path. Root `wp-config.php` line `427` is now the cheapest
  discriminating source check; no plugin/MU-plugin edit is justified yet.
- **Next action:** Inspect redacted lines around root `wp-config.php:427` and
  search that file for `undefine(`, `defined(`, `define(` and `WP_CLI` before
  rerunning any Cron command.
- **Status:** INVESTIGATION - G3 Cron run/meta evidence remains unavailable.

### PHASE-1.30-G3-WPCLI-ROOTCAUSE - stale debug_headers_detailed call in wp-config.php

- **Evidence:** The deployed root `wp-config.php:427` calls
  `debug_headers_detailed()` from `check_headers_early()`, which is attached to
  early WordPress hooks. The deployed/source `wp-content/debug-headers.php`
  defines `debug_headers_sent()` and does not define
  `debug_headers_detailed()`. WP-CLI reports the corresponding failure as
  `Runner.php(1334) : eval()'d code on line 427` with a truncated `Call to
  undefine` message.
- **Classification:** Confirmed external bootstrap blocker. This is not a
  G3 logger/probe failure and not evidence to mass-edit `WP_CLI` registrations.
- **Repair boundary:** In the approved root-config source, remove the stale
  debug call or guard it with `function_exists('debug_headers_detailed')`; do
  not add an ad-hoc function in the plugin and do not edit production config
  without preserving a backup/hash and rollback boundary.
- **Validation required:** Run target `option get siteurl` and
  `cron event list` under the EA PHP CLI after the config repair, then run the
  retention hook and capture blog-1511 `start/meta/end` evidence.
- **Status:** BLOCKED - root-config repair/deploy pending; G3 disposable probe
  remains PASS but Cron run/meta evidence is not available.

### PHASE-1.30-G3-WPCLI-ROOTCAUSE-CONFIRMED - line 427 and report containment

- **Evidence:** VPS output confirms root `wp-config.php` lines `425-431` define
  `check_headers_early()`, call `debug_headers_detailed()` at line `427`, and
  attach that callback to early WordPress hooks. The available
  `wp-content/debug-headers.php` artifact defines `debug_headers_sent()` but
  not `debug_headers_detailed()`.
- **Classification:** Confirmed external WP-CLI bootstrap blocker. Subsequent
  shell `syntax error` and `command not found` lines came from pasting command
  output back into Bash and are not PHP evidence.
- **Security action:** The pasted output included plaintext database, R2 and
  WordPress secret values. Treat them as exposed: revoke/rotate them before
  further diagnostics and redact all future output.
- **Next action:** Apply the approved backup/hash guarded root-config repair,
  run `php -l wp-config.php`, then validate target WP-CLI `option` and Cron
  registration before invoking retention.
- **Status:** ROOT CAUSE CONFIRMED - repair and G3 Cron evidence pending.

### PHASE-1.30-G3-WPCLI-CONFIG-GUARD-RESULT - guard deployed, exit 255 remains

- **Evidence:** VPS line `427` was changed to a
  `function_exists('debug_headers_detailed')` guard with a timestamped backup;
  `wp-config.php` lint returned `0`. The subsequent target-scoped WP-CLI
  `option get siteurl` still returned `option_after_debug_guard_exit=255` with
  no command output.
- **Classification:** The stale config call is repaired, but it was not the
  sole remaining WP-CLI blocker. Do not mark the Cron path fixed from config
  lint alone.
- **Next action:** Read the canonical PHP log immediately around the guard
  validation run, identify the next fatal/callable, then rerun the read-only
  option and Cron-registration checks.
- **Status:** FAIL - WP-CLI bootstrap/command boundary remains blocked; G3 Cron
  run/meta evidence is unavailable.

### PHASE-1.30-G3-WPCLI-POST-GUARD-FATAL - undefined callable remains at config line 427

- **Evidence:** After the VPS changed `wp-config.php:427` to a
  `function_exists('debug_headers_detailed')` guard and lint returned `0`, the
  canonical log recorded another fatal at `18:26:55 Asia/Ho_Chi_Minh` from
  `Runner.php(1334) : eval()'d code line 427` as truncated `Call to undefine`.
- **Classification:** The message is treated as a truncated `Call to undefined
  function ...`. If the guard is the file actually evaluated,
  `debug_headers_detailed()` is being defined elsewhere and its body or a
  second config copy must be identified. This is not yet evidence of a literal
  `undefine()` call.
- **Next action:** Grep active deployed trees for the function definition/call,
  print redacted root-config lines `424-431`, and confirm the evaluated config
  path/hash before another code or config change.
- **Status:** FAIL - WP-CLI bootstrap remains blocked; G3 Cron run/meta evidence
  is unavailable.

### PHASE-1.30-G3-WPCLI-REFLECTION - debug function defined in root config

- **Evidence:** Target direct bootstrap returned `DEBUG_HEADERS_DETAILED=YES`;
  reflection located the definition at root `wp-config.php:406`. The deployed
  active-tree sweep found a guarded call at line `427` and a second unguarded
  `debug_headers_detailed();` at line `437`.
- **Classification:** The missing `wp-content/debug-headers.php` file is not
  sufficient to explain the failure because the function is defined in the
  root config itself. The remaining candidate is the line-437 unguarded call or
  an undefined function used inside the line-406 function body.
- **Next action:** Inspect only redacted root-config lines `400-442`, identify
  every callable used by `debug_headers_detailed()`, then apply one guarded
  config repair with backup/hash and rerun read-only WP-CLI checks.
- **Status:** FAIL - WP-CLI Cron boundary remains blocked; no retention run/meta
  evidence is available.

### PHASE-1.30-G3-WPCLI-CONFIG-BLOCK - obsolete debug instrumentation fully mapped

- **Evidence:** VPS root config lines `400-442` require `wp-settings.php` at
  line `402`, define `debug_headers_detailed()` locally at `405-423`, call it
  through a guard at `427`, and call it directly again at `437` when
  `BizCity_Automation_Market_Admin` is loaded. The function body uses standard
  PHP header/output/backtrace functions; `wp-content/debug-headers.php` is not
  the provider.
- **Classification:** The line-427 guard alone does not isolate the complete
  obsolete debug block. The current WP-CLI `255` remains a config-instrumentation
  boundary failure, while the exact undefined callable is still truncated in
  the cPanel log.
- **Repair boundary:** Remove or disable the obsolete debug function, monitor
  function and their hooks in the private site-config source. Preserve a
  timestamped backup/hash and lint before deployment; do not upload an
  unrelated helper or edit all `WP_CLI::add_command` sites.
- **Status:** BLOCKED - config-block repair and target WP-CLI rerun pending.

### PHASE-1.30-G3-CONFIG-REPAIR-LOCAL - remove obsolete header-debug block

- **Evidence:** Removed the stale header-debug function, early hooks and
  `BizCity_Automation_Market_Admin` monitor from the workspace `wp-config.php`.
  DB/multisite/shard/cache configuration was not changed. `get_errors`, PHP
  lint and an active-file marker sweep passed with no remaining
  `debug_headers_detailed`, `check_headers_early` or
  `monitor_bizcity_plugin` marker.
- **Boundary:** This is local source/config evidence only. The site-specific
  `wp-config.php` is not a plugin artifact and must remain outside the plugin
  release package; VPS upload requires a backup, hash and atomic replacement.
- **Next action:** Upload the approved private config source to the target VPS,
  lint there, then run target `option get siteurl`, `cron event list` and only
  after those pass the retention hook.
- **Status:** LOCAL PASS - VPS deployment and G3 Cron evidence pending.

### PHASE-1.30-G3-CONFIG-DEPLOYED - target config no longer exposes debug function

- **Evidence:** After private config deployment, direct EA-PHP CLI with the
  mapped host returned `wp_load_exit=0`, `blog_id=1511` and
  `DEBUG_HEADERS_DETAILED=NO`. The target config no longer defines or invokes
  the obsolete header-debug function.
- **Boundary:** This proves target config loading and domain-to-blog context,
  not WP-CLI command success or Cron execution. The remaining G3 evidence must
  come from the correctly scoped read-only WP-CLI command and a matching Cron
  `start/meta/end` run for blog `1511`.
- **Next action:** Run `option get siteurl` and `cron event list` under user
  `vibeyeuc` through the EA PHP CLI, then run retention only after both pass.
- **Status:** TARGET CONFIG PASS - G3 Cron run/meta evidence pending.

### PHASE-1.30-G3-CRON-PASS - target blog retention run and metadata

- **Evidence:** After the config repair, target-scoped WP-CLI ran through
  `/opt/cpanel/ea-php74/root/usr/bin/php` as user `vibeyeuc`; `option get
  siteurl` and `cron event list` returned exit `0`, and
  `bizcity_jsonl_retention` ran with `cron_run_exit=0`. Artifacts are under
  `build/phase-1.30-20260902/g3-cron-after-config-deploy-20260902-202220/`.
- **Runtime:** The latest blog-1511 canonical sequence uses
  `run_id=1788355343460699`: `cron_start`, `cron_meta` and `cron_end` agree;
  `cron_end.status=ok`, `duration_ms=40005`. Metadata counters are
  `shared_jsonl_retention_deleted=0`, `shared_jsonl_index_rebuilt=200` and
  `shared_jsonl_index_removed=0`; the retention event reports a complete
  reconcile cursor. This is target-blog Cron metadata Runtime PASS.
- **Boundary:** This does not prove multi-request zero-growth, production DROP
  approval or physical second-shard isolation. The G3 filtered diagnostics
  report does not expose a fixture contract ID/relative file, so evidence
  packaging remains partial even though the retention Cron run passes.
- **Status:** PASS - target blog retention Cron and run metadata confirmed;
  broader G3/release gates remain open.

### PHASE-1.30-FULL-BATCH-20260902 - complete batch, WebChat owner prerequisite pending

- **Evidence:** The post-config full `legacy` batch on
  `libedemo.bizcity.vn` / blog `1511` used
  `/opt/cpanel/ea-php74/root/usr/bin/php`, executed `24/24` probes and reached
  `checkpoint=complete`. It returned `legacy_full_exit=1` with `19 pass · 1
  fail · 3 skip`; JUnit is at
  `build/phase-1.30-20260902/legacy-full-after-config-20260902-202714/legacy-full.xml`.
- **Failure:** `core.legacy_table.crud_stop` failed only for
  `bizcity_webchat_tools` because `core.webchat.tool_registry_parity` had no
  persisted PASS evidence. That owner probe appeared later at step `14/24`,
  while `crud_stop` evaluated the row at step `11/24`; the prerequisite must be
  persisted in a focused run before `crud_stop`.
- **Skips:** `core.memory.filestore_parity` and
  `core.bizcity_llm.usage_ledger_parity` retained their precondition-skip
  classifications; they are not converted to PASS or treated as failures.
- **Next action:** Persist a focused `core.webchat.tool_registry_parity` PASS,
  rerun `core.legacy_table.crud_stop`, then rerun the full `legacy` batch.
- **Status:** FAIL - complete batch coverage, release gate not closed.

### PHASE-1.30-WEBCHAT-OWNER-CLI-SKIP - owner probe requires Diagnostics admin context

- **Evidence:** The focused CLI attempt for `core.webchat.tool_registry_parity`
  returned process exit `0`, but the probe status was `skip` with precondition
  `BizCity_WebChat_Timeline is not loaded.` The following focused
  `core.legacy_table.crud_stop` returned `fail` only for `bizcity_webchat_tools`
  because that persisted owner PASS was absent.
- **Classification:** CLI precondition skip, not WebChat parity failure. The
  `0` process exit is not an owner PASS and cannot satisfy `crud_stop`.
- **Next action:** Run the owner probe through the authenticated Diagnostics
  admin page `tools.php?page=bizcity-diagnostics`, where the WebChat runtime
  loader is available; then run `core.legacy_table.crud_stop` from the same UI
  or a subsequent focused runner after persistence.
- **Status:** BLOCKED - WebChat owner PASS must be persisted before the next
  CRUD-stop/full-batch attempt.

### PHASE-1.30-DDV - recover full module boot before CLI probes

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Invoke the guarded `BizCity_Twin_AI::boot()` after CLI plugin
  recovery and before diagnostics `init`, so module-owned preconditions match
  the backend runtime used by the fixed batch.
- **Affected paths:** `bin/diagnostics-run.php`,
  `modules/webchat/bootstrap.php`,
  `core/diagnostics/includes/probes/class-probe-webchat-tool-registry-parity.php`.
- **Root cause:** The CLI runner recovered the main plugin and fired `init`,
  but did not invoke the main orchestrator; WebChat Timeline was therefore not
  loaded and the tool-registry owner became a precondition skip.
- **Validation:** VPS focused `core.legacy_table.crud_stop` passed with all 24
  target rows and request-local query delta `22→22`; the post-fix CLI owner
  probe and full legacy batch remain pending.
- **Status:** SOURCE FIX APPLIED - targeted CLI owner validation pending.

### PHASE-1.30-DDV - complete WebChat artifact loading after legacy Database preload

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Allow the canonical WebChat bootstrap to load missing Timeline
  artifacts when a compatibility loader has already supplied Database.
- **Affected paths:** `modules/webchat/bootstrap.php`,
  `bin/diagnostics-run.php`,
  `core/diagnostics/includes/probes/class-probe-webchat-tool-registry-parity.php`.
- **Root cause:** The WebChat bootstrap returned as soon as
  `BizCity_WebChat_Database` existed, even when
  `BizCity_WebChat_Timeline` was absent. CLI diagnostics therefore failed the
  owner precondition despite the main orchestrator being invoked.
- **Validation:** VPS CLI owner probe reported
  `BizCity_WebChat_Timeline is not loaded`; local PHP 7.4.4 lint passed after
  changing the guard. Deployment and focused owner rerun are pending.
- **Status:** SOURCE FIX APPLIED - deploy and rerun the owner probe.

### PHASE-1.30-DDV - WebChat CLI owner probe PASS after partial-preload fix

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** Target VPS focused CLI run on `libedemo.bizcity.vn`, blog
  `1511`, PHP `7.4.33` returned `1 pass · 0 fail · 0 skip`; JUnit is
  `build/webchat-tool-registry-cli-after-partial-preload-fix.xml`.
- **Runtime:** `tool_count=126`; sample lookup resolved
  `scheduler_get_today_agenda` with `type=atomic`; Timeline linked-tools call
  returned a safe empty array for the synthetic missing task.
- **Boundary:** This closes the focused WebChat owner probe only. The focused
  result has `selected_total=1` and `coverage.complete=false` by design; the
  full `legacy` batch must still be rerun for batch-level evidence.
- **Status:** PASS - WebChat CLI owner runtime confirmed; full legacy batch pending.

### PHASE-1.30-DDV - full legacy batch reaches budget-deferred checkpoint

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** VPS run `diag_20260902150539_c739441c` on blog `1511` executed
  19 of 24 probes with `18 pass · 0 fail · 5 budget_deferred`; WebChat owner
  and CRUD-stop both passed. Checkpoint status is `deferred`, and
  `coverage.complete=false`.
- **Deferred probes:** `core.bizcity_llm.usage_ledger_parity`,
  `core.knowledge.kg_usage_ledger_parity`,
  `core.helper.jsonl_search_query_index_parity`, `core.helper.log_index`, and
  `core.helper.table_metadata`.
- **Classification:** No executed failure is present, but budget-deferred is
  not an executed PASS and does not close the batch release gate.
- **Next action:** Resume the same run ID with `--batch=legacy --resume=...`
  and inspect the final `coverage.complete`, counts and each result.
- **Status:** SUPERSEDED - resume completed; see the final batch entry below.

### PHASE-1.30-DDV - full legacy batch complete after resume

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** Resume of run `diag_20260902150539_c739441c` on VPS blog
  `1511` reached `checkpoint=complete` with
  `coverage.complete=true`, `deferred=0` and `fail=0`.
- **Counts:** `22 pass · 1 warn · 1 skip`; the single skip is the documented
  Hub-only `core.bizcity_llm.usage_ledger_parity` precondition. The four other
  previously deferred probes returned PASS.
- **Runtime:** WebChat tool-registry parity and `crud_stop` passed; all 24
  CRUD-stop rows passed and the request-local observation remained mutation
  free. Final JUnit: `build/legacy-full-after-resume.xml`.
- **Boundary:** This closes batch execution and coverage, not the full
  PHASE-1.30 release gate. Contract scoreboard remains WARN at `97.92%`, while
  G1 HTTP denial, G4 physical-shard evidence, zero-growth, owner approval,
  zero-row verification and production DROP remain open.
- **Status:** COMPLETE WITH WARN - no executed failure; follow-up lifecycle
  gates remain pending.

### PHASE-1.30-DDV - full legacy batch complete after resume

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** Resume `diag_20260902150539_c739441c` reached
  `checkpoint=complete` with `coverage.complete=true`, `deferred=0` and
  `fail=0` on blog `1511`.
- **Counts:** `22 pass · 1 warn · 1 skip`; the single skip is the documented
  Hub-only `core.bizcity_llm.usage_ledger_parity` precondition. The four other
  deferred probes returned PASS.
- **Runtime:** WebChat tool-registry parity and `crud_stop` passed; all 24
  CRUD-stop rows passed and the request-local observation remained mutation
  free. Final JUnit: `build/legacy-full-after-resume.xml`.
- **Boundary:** This closes batch execution/coverage, not the broader
  PHASE-1.30 release gate. Scoreboard remains WARN at `97.92%`; G1 HTTP denial,
  G4 physical-shard evidence, zero-growth, approval, zero-row verification and
  production DROP remain open.
- **Status:** COMPLETE WITH WARN - follow-up lifecycle gates remain pending.

### PHASE-1.30-DDV - document remaining lifecycle closure execution plan

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Centralize the execution plan for the three scoreboard rows, G1
  HTTP denial, G4 second-shard evidence, multi-request zero-growth,
  approval/`ready_to_drop`, fresh zero-row verification and guarded DROP.
- **Affected paths:** `docs/roadmaps/PHASE-1.30-LEGACY-TABLE-LIFECYCLE.md`.
- **Evidence boundary:** Documentation does not convert `WARN`, `SKIP` or
  `DEFERRED` into PASS and does not authorize production DROP.
- **Validation:** Roadmap validator and Diagnostics document diagnostics are
  required after this documentation change.
- **Status:** DOCUMENTED - execution evidence remains pending by workstream.

### PHASE-1.30-DDV - make scoreboard JSONL scope contract-aware

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Score JSONL owner contracts against their declared storage scope,
  including the global Google usage audit contract.
- **Affected paths:** `core/diagnostics/includes/class-diagnostics-table-registry.php`,
  `core/diagnostics/includes/probes/class-probe-legacy-contract-scoreboard.php`.
- **Root cause:** `plugins.bizgpt_tool_google.usage_audit` is registered with
  `storage_scope=global`, but the scoreboard required `blog` for every JSONL
  owner and reported a false `owner_contract` gap.
- **Validation:** PHP 7.4.4 lint passed for both modified PHP files. Target
  focused owner probes and scoreboard rerun remain pending.
- **Status:** SOURCE FIX APPLIED - runtime scoreboard validation pending.

### PHASE-1.30-DDV - accept dedicated retire-only owner probes

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Let retire-only catalog rows satisfy the owner-contract dimension
  through their declared dedicated probe, while retaining the no-writer and
  explicit-probe requirements.
- **Affected paths:** `core/diagnostics/includes/probes/class-probe-legacy-contract-scoreboard.php`.
- **Root cause:** `bizcity_zalo_bot_memory` declares the dedicated
  `modules.zalobot.memory_unify` removal probe, but the scorer only accepted
  `core.legacy_table.callers`, producing a false owner-contract gap.
- **Validation:** PHP 7.4.4 lint passed. Fresh Zalo memory, LLM usage, Google
  usage and scoreboard runtime probes remain pending on target blog `1511`.
- **Status:** SOURCE FIX APPLIED - runtime scoreboard validation pending.

### PHASE-1.30-DDV - scoreboard rerun closes Google scope gap

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** Target VPS focused run on blog `1511` returned Google usage
  parity PASS and scoreboard `4740/4800` (`98.75%`, `46/48` rows complete).
- **Remaining:** `modules.zalobot.memory_unify` failed its Disk removal step;
  `core.bizcity_llm.usage_filestore_parity` was not selected by the deployed
  four-ID filter run, so client LLM usage runtime freshness remains pending.
- **Action:** Rerun Zalo and LLM owner probes separately, inspect the bounded
  Zalo flags, then rerun `core.legacy_table.contract_scoreboard`.
- **Status:** INCOMPLETE - two named scoreboard rows remain open.

### PHASE-1.30-ZALO-MEMORY-REMOVE - make legacy-removal failure evidence actionable

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Report the individual Zalo legacy-removal Disk checks when the
  probe fails, so deployment drift and a real active marker are distinguishable.
- **Affected paths:** `core/diagnostics/includes/probes/class-probe-zalobot-memory-unify.php`.
- **Behavior:** The probe still requires the legacy class file/loaded class and
  bootstrap/database marker checks to be absent; only the failure detail now
  reports readability and boolean flags without exposing paths or credentials.
- **Validation:** PHP 7.4.4 lint passed; target rerun remains pending.
- **Status:** SOURCE FIX APPLIED - rerun `modules.zalobot.memory_unify`.

### PHASE-1.30-DDV - classify remaining scoreboard deployment drift

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** VPS Zalo rerun reported
  `legacy_class_absent=no` with readable bootstrap/database and no legacy hook
  marker. VPS LLM rerun reported `No probes match filter
  core.bizcity_llm.usage_filestore_parity`; Google parity remained PASS.
- **Local comparison:** Active local Zalo roots contain no legacy class/hook
  markers, and the diagnostics bootstrap queues the LLM filestore probe with
  its class artifact present.
- **Classification:** Deployment/runtime artifact drift, not permission to
  weaken either owner probe or scoreboard requirements.
- **Action:** Verify/deploy the exact LLM probe and diagnostics bootstrap, remove
  the deployed Zalo legacy class artifact or identify its loaded source, then
  rerun each owner separately before the scoreboard.
- **Status:** INCOMPLETE - two named scoreboard rows remain open.

### PHASE-1.30-DDV - confirm Zalo legacy artifact and missing LLM probe on VPS

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** VPS search found the active file
  `plugins/bizcity-zalo-bot/includes/class-memory.php` containing
  `BizCity_Zalo_Bot_Memory` and `bizcity_zalo_bot_memory`; the Zalo probe
  correctly reported `legacy_class_absent=no` and `legacy_hooks_absent=yes`.
  The LLM focused command returned `No probes match filter
  core.bizcity_llm.usage_filestore_parity`.
- **Local comparison:** The active local Zalo source has no legacy memory file
  or markers. The local diagnostics bootstrap queues the LLM probe and its
  class artifact is present.
- **Classification:** VPS deployment drift: one stale Zalo artifact remains,
  and the LLM probe artifact/class is missing or unreadable in the deployed
  diagnostics catalog. Queue markers alone are not runtime evidence.
- **Action:** Verify/hash the deployed LLM probe and bootstrap, quarantine the
  Zalo legacy file only after a successful backup/hash, rerun each owner alone,
  then rerun the scoreboard.
- **Status:** INCOMPLETE - Zalo Disk removal and LLM probe deployment remain
  blocked; no production DROP action is authorized.

### PHASE-1.30-DDV - classify post-quarantine shell evidence

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Evidence:** The VPS follow-up verified the Zalo active file path was absent,
  but did not run the owner probe because `$PHP_BIN` was unset and the shell
  returned `-bash: : command not found`. The same follow-up confirmed the LLM
  probe file is still absent while its bootstrap registration marker exists.
- **Classification:** Zalo is a cleanup candidate with runtime verification and
  backup/hash provenance pending; LLM remains a missing deployed diagnostics
  artifact. Neither state is a PASS.
- **Action:** Resolve PHP in the same shell, run the Zalo probe even with the
  file absent, deploy/verify the LLM probe artifact and run it separately.
- **Status:** INCOMPLETE - fresh owner evidence remains required.

### PHASE-1.30-DDV - record corrected VPS owner reruns

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-1.30`, `R-DDV`, `R-DCL`.
- **Affected paths:** deployed ZaloBot memory owner, deployed Diagnostics
  probe catalog, `build/scoreboard-remediation.xml`,
  `build/zalobot-memory-unify-after-detail.xml` and the legacy batch resume
  artifact.
- **Evidence:** VPS resolved
  `PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php`, PHP `7.4.33`, WordPress
  `6.9`, host `libedemo.bizcity.vn`, blog `1511`. The resumed legacy run
  `diag_20260902150539_c739441c` reached `checkpoint=complete` with
  `coverage.complete=true`, `deferred=0`, `22 pass · 1 warn · 1 skip` and
  `verdict=warn`; the resume had no remaining probes to execute. The fresh
  remediation rerun returned Google usage `PASS`, Zalo memory `FAIL`, and
  scoreboard `WARN 4740/4800, 46/48`. Zalo Disk flags were
  `bootstrap_readable=yes`, `database_readable=yes`,
  `legacy_class_absent=no`, `legacy_hooks_absent=yes`; canonical writer and
  filestore runtime steps passed. The LLM focused filter returned
  `No probes match filter core.bizcity_llm.usage_filestore_parity`.
- **Classification:** The complete batch is not a release PASS. Zalo remains
  blocked by deployed legacy class visibility and missing basic read/search/
  index evidence. LLM remains incomplete because its deployed probe artifact
  is not registered/available. Google owner parity is closed for this slice.
- **Action:** Identify/remove the deployed Zalo legacy class and rerun its
  owner probe; deploy/register the exact LLM filestore probe and rerun it;
  rerun the scoreboard only after both owner results are valid. Preserve the
  Hub-only ledger skip as a skip, not a client JSONL failure.
- **Validation:** Evidence supplied from the VPS transcript; local roadmap
  validator previously returned `LEGACY TABLE LIFECYCLE PASS`. No production
  DROP or approval action was performed.
- **Status:** INCOMPLETE - two scoreboard owner rows remain open.

### PHASE-1.30-DDV - Zalo memory removal owner probe PASS

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-1.30`, `R-DDV`, `R-LOG-HYBRID`.
- **Affected paths:** deployed ZaloBot memory owner and
  `build/zalobot-memory-unify-after-quarantine.xml`.
- **Evidence:** VPS target `libedemo.bizcity.vn`, blog `1511`, PHP `7.4.33`,
  resolved with `PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php`. Focused
  `modules.zalobot.memory_unify` returned `1 pass · 0 fail · 0 skip`,
  `verdict=pass`, `duration_ms=345`, `selected_total=1`, `executed=1` and
  `coverage.complete=false` because it was a focused run. All five steps
  passed: legacy class/bootstrap/cron/migration markers absent, canonical
  writer context loaded, aliases normalized, filestore write succeeded and
  scoped user-memory read-back succeeded.
- **Boundary:** This closes the Zalo owner probe for the current mapped
  tenant only. It does not close the full legacy batch, multi-request
  zero-growth, G1/G4, approval, zero-row or production DROP gates.
- **Remaining blocker:** The focused LLM filter still returned
  `No probes match filter core.bizcity_llm.usage_filestore_parity`; no LLM
  filestore runtime evidence is credited until the exact deployed probe is
  present in the catalog and executes.
- **Action:** Preserve the Zalo JUnit/JSON artifact, deploy/register the LLM
  probe, run it separately, then rerun the contract scoreboard. Do not erase
  the earlier Zalo FAIL; this entry supersedes it with fresh PASS evidence.
- **Status:** Zalo owner PASS; PHASE-1.30 scoreboard remains INCOMPLETE because
  the LLM owner probe is unavailable.

### PHASE-1.30-DDV - latest Zalo PASS supersedes deployed-artifact failure

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-1.30`, `R-DDV`, `R-LOG-HYBRID`.
- **Affected paths:** deployed ZaloBot memory owner and
  `build/zalobot-memory-unify-after-quarantine.xml`.
- **Evidence:** The latest VPS run resolved
  `PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php`, PHP `7.4.33`, WordPress
  `6.9`, host `libedemo.bizcity.vn` and blog `1511`. The focused
  `modules.zalobot.memory_unify` probe returned `1 pass · 0 fail · 0 skip`,
  `verdict=pass`, `duration_ms=345`, `selected_total=1`, `executed=1` and
  `coverage.complete=false` because it was a focused run. Disk, Loader and
  all three canonical runtime checks passed; the legacy class/bootstrap/cron/
  migration markers were absent and the scoped filestore write/read-back
  succeeded.
- **Deployment comparison:** In the same session, the LLM filter still
  returned `No probes match filter core.bizcity_llm.usage_filestore_parity`.
  Local class ID and bootstrap filename/queue are correct, so the remaining
  issue is deployed artifact/catalog availability. No LLM runtime parity score
  is credited.
- **Boundary:** Zalo owner evidence is PASS for the mapped tenant, but this
  does not close the full legacy batch, G1/G4, multi-request zero-growth,
  approval, zero-row or production DROP gates.
- **Action:** Rerun the scoreboard to refresh the Zalo row; deploy/register
  the LLM probe and require one selected/executed PASS before closing the last
  scoreboard row. Retain prior Zalo FAIL evidence as historical, not deleted.
- **Status:** Zalo owner PASS; LLM filestore owner probe unavailable;
  PHASE-1.30 scoreboard remains INCOMPLETE.

### PHASE-1.30-DDV - LLM usage filestore owner probe PASS

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-1.30`, `R-DDV`, `R-LOG-HYBRID`.
- **Affected paths:** deployed LLM filestore probe and
  `build/llm-usage-filestore-parity.xml`.
- **Evidence:** VPS target `libedemo.bizcity.vn`, blog `1511`, PHP `7.4.33`,
  resolved with `PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php`. Focused
  `core.bizcity_llm.usage_filestore_parity` returned `1 pass · 0 fail · 0
  skip`, `verdict=pass`, `duration_ms=346`, `catalog_total=227`,
  `selected_total=1`, `executed=1`, `coverage.complete=false` and
  `deferred=0`. The catalog hash was
  `3a12fbc9b141197dddc9e0e9d1973d7bbf19c1c7d099d5f6379cea39197fb0ee` and
  batch hash was
  `70354b5a828373966d36b28de9ffbf0eeeeae9bc8c86632b23c633e06d6fec48`.
- **Runtime:** All five steps passed: tenant-scoped client usage JSONL
  contract, blog/user-scoped write and aggregate, daily report, legacy SQL
  operation block, and Context Bank exclusion.
- **Boundary:** This closes the LLM owner/runtime probe for the mapped tenant
  only. The focused run is not a full legacy batch and does not close
  zero-growth, G1/G4, approval, zero-row or production DROP gates.
- **Action:** Rerun `core.legacy_table.contract_scoreboard` after the fresh
  Zalo and LLM PASS results. Do not infer `4800/4800` until the scoreboard
  output confirms it.
- **Status:** LLM owner/runtime PASS; scoreboard refresh pending.

### PHASE-1.30-DDV - classify replayed pre-owner scoreboard output

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-1.30`, `R-DDV`.
- **Affected paths:** historical VPS artifacts
  `build/legacy-full-after-resume.xml`, `build/scoreboard-remediation.xml`
  and `build/zalobot-memory-unify-after-detail.xml`.
- **Evidence:** The supplied replay used the earlier catalog hash beginning
  `ea5746a2` and reported the pre-owner state: resumed batch
  `22 pass · 0 fail · 1 skip`, `coverage.complete=true`, `executed=0`; Zalo
  `FAIL` with `legacy_class_absent=no`; LLM `No probes match`; Google `PASS`;
  scoreboard `4740/4800`, `46/48`.
- **Classification:** Historical/superseded deployment state, not a
  regression. Later mapped-host owner probes recorded in this changelog prove
  Zalo PASS with catalog hash beginning `56af4847` and LLM PASS with catalog
  hash beginning `3a12fbc9`. The replay must not overwrite those newer owner
  results or be used to lower current progress.
- **Action:** Retain the replay for audit trace, rerun the scoreboard against
  the latest persisted owner results, and inspect the new `incomplete_rows`.
  Keep G1/G4, zero-growth, approval, zero-row and DROP gates unchanged.
- **Status:** Historical evidence classified; current owner gates remain PASS,
  scoreboard refresh pending.

### PHASE-1.30-DDV - contract scoreboard reaches 100 percent

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-1.30`, `R-DDV`, `R-DATA-STORAGE`.
- **Affected paths:** deployed Diagnostics scoreboard and
  `build/scoreboard-after-zalo-llm-pass.xml`.
- **Evidence:** VPS target `libedemo.bizcity.vn`, blog `1511`, PHP `7.4.33`,
  resolved with `PHP_BIN=/opt/cpanel/ea-php74/root/usr/bin/php`. Focused
  `core.legacy_table.contract_scoreboard` returned `1 pass · 0 fail · 0
  skip`, `verdict=pass`, `duration_ms=16`, `catalog_total=227`,
  `selected_total=1`, `executed=1`, `coverage.complete=false` because it was
  a focused run, `incomplete_rows=[]`, and `4800/4800` points (`100%`) across
  `48/48` unique rows. Catalog hash:
  `3a12fbc9b141197dddc9e0e9d1973d7bbf19c1c7d099d5f6379cea39197fb0ee`.
- **Result:** The previously open Zalo memory and client LLM usage rows now
  have owner contract, fresh runtime and basic evidence. The mode totals are
  `retire_only=2300`, `jsonl=1300`, `filestore=500`, `repository=300`,
  `event_stream=300` and `sql_structural=100`.
- **Boundary:** Contract scoreboard acceptance is PASS. The probe explicitly
  states that this does not close SQL-writer stop, zero-growth, target-shard,
  approval/`ready_to_drop` or zero-row DROP gates. No production DROP or owner
  approval was performed.
- **Action:** Preserve the scoreboard JSON/JUnit artifact and continue G1/G4,
  multi-request zero-growth, owner approval, fresh zero-row verification and
  guarded DROP in their required order.
- **Status:** PASS - contract scoreboard complete; lifecycle release gates
  remain open.

### PHASE-1.30-G3-DEPLOY-DRIFT - VPS source hashes differ from local source

- **Evidence:** VPS deployed and bundled compat files matched each other at
  `51c1408878e9b8b28b2528d5984808ee17c9d43751cdf1003c59af18e0c8d4fa`, while
  the current local bundled compat source hashes to
  `C1D843EE232A2489A3EEE46AB9B1DC930B36CB05621D9414E36F6C343E7115E6`. The
  deployed `class-user-memory.php` hash is
  `81ec4055b9ec2bf315100ddf7bd929ef2a988ae41d0581f04bef70361508d351`, versus
  local `47B5C644CE5C6C81F3C6BF70A2F2F64A2BD8939B9E22DB43B5FAD3638EAB62A3`.
- **Classification:** Deployment parity FAIL. Matching two deployed compat
  copies does not prove parity with the current source. The stale deployed
  user-memory class is a candidate for the real truncated undefined-call
  failure, but the callable must be confirmed from the deployed file/log.
- **Next action:** Grep the deployed `class-user-memory.php` and compat files
  for `undefine()` and inspect their version markers; only then deploy the
  approved source and rerun the PHP CLI bootstrap/retention checks.
- **Status:** BLOCKED - G3 Cron run/meta evidence remains unavailable pending
  source/deployment parity repair.

### PHASE-1.30-G3-DEPLOY-MARKERS - deployed compat markers and undefine sweep

- **Evidence:** The VPS marker sweep reports `@version 1.1.4` and
  `BIZCITY_TWIN_COMPAT_VERSION=1.1.4` in both the deployed MU compat file and
  the bundled compat source. The deployed `class-user-memory.php` sweep found
  no literal `undefine()` call; the only relevant active marker was
  `WP_CLI::add_command` in `bizcity-as-cron-trace.php`.
- **Classification:** Version-marker alignment and negative source evidence
  PASS. This does not prove byte-level parity with local source and does not
  resolve the real WP-CLI `Call to undefine` from the Runner eval path. The
  manual `Class 'WP_CLI' not found` harness fatal remains a separate event.
- **Next action:** Capture the complete missing-callable name from the PHP log
  or a real WP-CLI reproduction, then identify its deployed caller before any
  source overwrite or guard change.
- **Status:** INVESTIGATION - G3 Cron run/meta evidence remains unavailable.

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

### PHASE-1.30-DDV - persist WebChat owners before CRUD-stop

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Intent:** Run the two WebChat owner probes before
  `core.legacy_table.crud_stop` in the fixed `legacy` batch.
- **Affected paths:** `core/diagnostics/includes/class-diagnostics-smoke-runner.php`.
- **Root cause:** `crud_stop` reads the persisted owner-result map, but the
  batch previously evaluated it before `core.webchat.tool_registry_parity`,
  causing a same-process ordering failure even when the owner probe itself was
  valid.
- **Validation:** The attached Diagnostics admin result is PASS with
  `tool_count=126` and sample lookup `scheduler_get_today_agenda`; PHP lint and
  full-batch VPS rerun remain pending.
- **Status:** SOURCE FIX APPLIED - rerun `crud_stop`, then full `legacy` batch.

### PHASE-CB4.3-DDV - Commerce shipment aggregate and provisioning stamp

- **Owner:** Johnny Chu - Chu Hoàng Anh.
- **Rule/phase:** `PHASE-CB4.3`, `R-DDV`, `R-DCL`.
- **Affected paths:** `core/diagnostics/includes/probes/class-probe-context-bank-commerce.php`;
  `core/context-bank/includes/class-context-bank-ledger.php`.
- **Intent:** Ensure the Commerce probe aggregate includes shipment/delivery
  hook and tracking-redaction evidence, and prevent the shared Context Bank
  schema option from being downgraded by the ledger installer.
- **Validation:** PHP `7.4.4` lint and editor diagnostics passed. Focused
  `core.context_bank.commerce` on mapped blog `1526` returned `verdict=pass`,
  `1 pass / 0 fail / 0 skip`, with 14/14 steps and cleanup complete. The
  Provisioner output retained `context_bank` and
  `context_bank_rollup_state` at expected version `1.3.0`.
- **Deployment requirement:** Deploy the Commerce probe and ledger stamp
  together; rerun the focused probe on the approved mapped tenant. This is
  not evidence for two-shard G1 or production aggregate readiness.
- **Rollback boundary:** Revert only the aggregate condition/stamp alignment;
  do not alter Woo, CRM, Context Bank payloads or canonical schema ownership.
- **Status:** Focused Commerce runtime PASS; G3 full gate remains partial due
  to missing canonical inventory producer, correction/PII inspection and
  release-wide evidence.

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
