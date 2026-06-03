# Diagnostics Schema Changelog — schema v1

> **Governing rule:** [PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md (R-DCL)](../../../../PHASE-0-RULE-DIAGNOSTICS-CHANGELOG.md)
> · Indexed in [PHASE-0-CANON.md](../../../../PHASE-0-CANON.md) (Tier 0 · R-DCL)
>
> Đây là **canonical contract** cho file `core/diagnostics/changelog/*.json`.
> Spec workflow, anti-patterns, checklist commit → đọc **R-DCL**.

Canonical contract for `core/diagnostics/changelog/*.json` files.

Loader: `BizCity_Diagnostics_Changelog_Loader` (PHP, no external dep).
Validator: `core/diagnostics/validate-schema-changelog.php` (CLI).

## Top-level shape

```json
{
  "$schema": "https://bizcity.vn/schema/diagnostics-changelog-v1.json",
  "module_id":       "modules.webchat",
  "owner":           "modules/webchat",
  "installer_id":    "webchat",
  "current_version": "3.11.0",
  "version_option":  "bizcity_webchat_db_version",
  "tables":  { "bizcity_webchat_sources": { ... } },
  "history": [ { "version": "...", "date": "YYYY-MM-DD", "change": "..." } ]
}
```

## `tables.<name>`

```json
{
  "since":   "4.0.0",
  "purpose": "human description",
  "engine":  "InnoDB",          // optional, default InnoDB
  "charset": "utf8mb4",         // optional, default utf8mb4
  "collate": "utf8mb4_unicode_ci",
  "columns": {
    "id": {
      "type":   "BIGINT UNSIGNED AUTO_INCREMENT",
      "since":  "4.0.0",
      "pk":     true,
      "deprecated_since": "5.0.0",   // optional
      "replaced_by":      "new_col"  // optional, used by validator
    }
  },
  "indexes": {
    "idx_session": { "cols": ["session_id"], "since": "4.0.0", "unique": false }
  }
}
```

## Rules enforced by validator (Exit codes: 0=ok, 1=warn, 2=error)

- ERROR: every `tables.*` key MUST exist in `BizCity_Diagnostics_Table_Registry`.
- ERROR: every column / index `since` MUST appear in `history[].version`.
- ERROR: `current_version` MUST NOT be lower than max(history version).
- ERROR: exactly one column has `pk: true`.
- WARN: a registered table has no JSON yet (during migration window).
- WARN: actual column exists in DB but missing from JSON (run-time check).

## Auto-create safety contract

`BizCity_Diagnostics_Auto_Create::run( $table_suffix )` is allowed to:

- `CREATE TABLE IF NOT EXISTS` when table is absent.
- `ALTER TABLE ADD COLUMN` for any declared column missing in actual.
- `ALTER TABLE ADD INDEX` for any declared index missing in actual.

It MUST NEVER:

- `DROP COLUMN`, `DROP INDEX`, `DROP TABLE`.
- `MODIFY COLUMN`, `CHANGE COLUMN`, type-narrowing.
- Touch a column that has `deprecated_since` set.

Any destructive change still goes through hand-written migrations + Site Provisioner.
