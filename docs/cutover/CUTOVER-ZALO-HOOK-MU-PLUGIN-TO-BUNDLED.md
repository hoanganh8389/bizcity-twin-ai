# Cutover Checklist: Mu Plugin -> Bundled Plugin (Zalo Admin Hook)

Date: 2026-07-26
Owner: Johnny Chu
Phase: R-GW-8 / HOTFIX

## Goal
Remove mu-plugin execution of Zalo Admin Hook while keeping bizcity-twin-ai standalone behavior unchanged.

## Scope
- Source to retire: mu-plugins/bizcity-admin-hook-zalo/bootstrap.php
- Replacement runtime: plugins/bizcity-twin-ai/plugins/bizcity-zalo-bizcity/bizcity-admin-hook-zalo.php
- Key ingress: /bizhook/
- Key dedup guard: waic_twf_process_flow listener + matcher claim guard

## What is included in this patch
1. Bundled bootstrap is synced to mu-safe standalone mode (no legacy helper re-require).
2. Latest anti-double-trigger hotfix is ported to bundled waic listener:
   - skip when matcher already claimed message_id
   - skip notebook capture commands (@ghichu/@notebook)
3. Bundled loader fallback added in bizcity-twin-ai.php:
   - if slug bizcity-admin-hook-zalo is missing, load from
     plugins/bizcity-zalo-bizcity/bizcity-admin-hook-zalo.php

## Pre-cutover checks (must PASS)
- [ ] File exists: plugins/bizcity-twin-ai/plugins/bizcity-zalo-bizcity/bizcity-admin-hook-zalo.php
- [ ] File exists: plugins/bizcity-twin-ai/plugins/bizcity-zalo-bizcity/bootstrap.php
- [ ] File exists: mu-plugins/bizcity-admin-hook-zalo/bootstrap.php
- [ ] No PHP syntax errors in:
  - plugins/bizcity-twin-ai/plugins/bizcity-zalo-bizcity/bootstrap.php
  - plugins/bizcity-twin-ai/bizcity-twin-ai.php

## Cutover steps
### Stage A - Safe prep (mu-plugin still active)
- [ ] Deploy patched code to server.
- [ ] Confirm normal traffic still works with mu-plugin active.
- [ ] Confirm no new PHP fatal logs related to duplicate function declarations.

### Stage B - Switch execution to bundled plugin
- [ ] Disable mu-plugin by renaming:
  - mu-plugins/bizcity-admin-hook-zalo/bootstrap.php -> bootstrap.php.disabled
- [ ] Keep folder for quick rollback.
- [ ] Clear opcode/cache if host uses persistent PHP cache.

### Stage C - Runtime verification after switch
- [ ] Send one normal Zalo Bot message, verify only one scenario runs.
- [ ] Send one notebook capture command (@ghichu or @notebook), verify:
  - notebook capture listener handles it
  - waic legacy pipeline is skipped
- [ ] Verify /bizhook/ inbound still returns HTTP 200 for valid POST.
- [ ] Verify no duplicate reply for same message_id.
- [ ] Verify no new fatal errors in PHP log.

## PASS criteria
All checks in Stage C are green for at least 3 test messages:
- 1 normal text
- 1 notebook capture text
- 1 image/attachment message

## Rollback plan (if any FAIL)
- [ ] Restore mu-plugin bootstrap file name:
  - bootstrap.php.disabled -> bootstrap.php
- [ ] Re-test /bizhook and message flow.
- [ ] Keep bundled patch in place; investigate mismatch before next cutover.

## Evidence to collect
- [ ] Request/response log of /bizhook test
- [ ] Log lines showing matcher-claimed skip in waic listener
- [ ] Log lines showing notebook capture preempt skip
- [ ] One successful end-to-end message trace after switch
