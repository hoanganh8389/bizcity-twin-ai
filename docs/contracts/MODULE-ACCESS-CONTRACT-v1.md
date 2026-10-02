# Module Access Contract v1

> Contract ID: `module-access`
> Version: `1.0.0`
> Status: Implemented in source, unit PASS — runtime pending (PHASE-0.84)
> Owner: Twin AI Core / TwinShell (`modules/twinshell`)
> Design: [PHASE-0.84](../../modules/twinshell/docs/PHASE-0.84-SHELL-GPT-MODULE-ACCESS/00-REQUIREMENTS-AND-CONTRACT.md) · Mockup: [PHASE-0.84 mockup](../../modules/twinshell/docs/mockups/PHASE-0.84-shell-gpt-module-access-mockup.html)

## 1. Purpose

`/twin/` is the shared shell for the site owner and staff. This contract fixes one answer to
"who may use module X on this site": the meta capability `bizcity_use_<id>`. The shell shows an
ActivityBar icon only when the user has it; the module's own page and REST check it again.
It does not replace a module's internal permissions (for example CRM `Staff_Policy` inside the Inbox).

## 2. Registration

An entry of the `bizcity_twin_register_plugins` filter opts in with `access`:

```php
'access' => array(
    'mode'          => 'grantable' | 'delegated' | 'admin_only', // default grantable
    'owner'         => 'modules/twinweb',                        // display + diagnostics
    'manage'        => array( 'plugin' => 'crm', 'r' => '/staff' ), // optional "manage it there" link
    'default_roles' => array( 'editor' ),                        // optional; first seed only
),
```

`BizCity_Twin_Shell_Registry::all()` then forces `capability = 'bizcity_use_<id>'` and keeps the declared
capability as `access.legacy_capability`. Entries without `access` keep their own capability and are
not governed by this contract.

## 3. Resolution

`BizCity_Twin_Module_Access::map_meta_cap()` (`modules/twinshell/includes/class-twin-module-access.php`):

| Case | Result |
|---|---|
| Unknown id, no `access`, user 0 | `do_not_allow` |
| Site admin (`BizCity_Network_Admin_Capability::can_manage( $user_id )`) | allowed |
| `admin_only` | denied for everyone else |
| `delegated` | filter `bizcity_module_access_delegate( false, $module_id, $user_id )`; no owner answer ⇒ denied |
| `grantable` | primitive capability `bizcity_access_<id>` |

WordPress precedence applies to `grantable`: a user-level capability — including an explicit `false` —
overrides the user's roles. That is the per-user override (allow / deny / inherit = no user cap).

Delegated owners today: `crm` → `BizCity_CRM_Authority::can( 'crm.inbox.open', [], BizCity_CRM_Actor::for_user( $id ) )`;
`gpt` → `BizCity_TwinWeb_REST::module_access_allows_user( $id )` (the Twin GPT access policy).

Plan gates (`plan`) are evaluated after this contract, as before. Access is not a plan.

## 4. Invariants

| # | Invariant |
|---|---|
| MA-1 | `current_user_can( 'bizcity_use_<id>' )` on the current blog is the only answer; no parallel list. |
| MA-2 | Storage = WordPress role/user capabilities of the current blog (`bizcity_access_<id>`) or the delegated owner's own store. No new table, no generic option. |
| MA-3 | A delegated module without an owner answer is denied (fail closed). |
| MA-4 | Site admins (and network super admins) always pass; the UI never offers to change them. |
| MA-5 | Visibility is not authorization: governed pages call `BizCity_Twin_Module_Access::require_page()`, REST calls `::can()` / `::rest_permission()`. |
| MA-6 | A `user_id` in a request is a selector: the server checks site membership and the caller's admin right; administrators cannot be overridden. |
| MA-7 | Every write emits `shell.access.changed` `{plugin_id, target:{role|user_id}, before, after}` — no email, no customer data. |
| MA-8 | First activation seeds `bizcity_access_<id>` to exactly the roles that held the entry's previous capability (or `default_roles`), once per (blog, module) — option `bizcity_twin_module_access_seeded`. Nobody gains or loses access on upgrade. |
| MA-9 | Errors use R-ERROR-UX: `capability_denied`, `module_access_read_failed`, `module_access_write_failed` (`reason: not_grantable`), `user_not_in_site`, `invalid_param`, `auth_required`. |

## 5. REST (site admins only, namespace `bizcity-twinchat/v1`)

| Method | Route | Body / query | Response |
|---|---|---|---|
| GET | `/shell/module-access` | — | `{roles:[{slug,name,user_count}], modules:[{id,label,desc,icon,emoji,mode,owner,section,primary,available,plan_badge,manage:{url}\|null,cells:{role:{state:on\|off\|na,editable,reason}}}]}` |
| GET | `/shell/module-access/users` | `search, role, overrides_only, page` | `{page,per_page:50,total,users:[{id,display_name,email,roles,is_admin,is_self,overrides_count,modules:[{id,allowed,override:inherit\|allow\|deny\|null}]}]}` |
| POST | `/shell/module-access/preview` | `{changes:[{module_id,role,allowed}]}` | `{items:[{…,affected_users}],approximate}` — no write |
| POST | `/shell/module-access/roles` | `{changes:[…]}` | `{success,applied,errors}` |
| POST | `/shell/module-access/users/{id}` | `{overrides:{module_id:inherit\|allow\|deny}}` | `{success,errors,user}` |

`reason` values: `admin_always`, `admin_only`, `unavailable`, `managed_by_owner`, `delegated_other`.
Writable cells: `grantable` modules (role caps) and the core WordPress roles of `gpt` (Twin GPT policy
`member.allowed_roles`; turning a role on below `minimum_role` lowers the minimum). Other delegated rows are read-only.

## 6. Setting Panel

Registration `core.twinshell.module_access`, destination `settings`, scope `site`, capability `manage_options`,
renderer `route` `/settings/module-access`, icon `cil-lock-locked`.

## 7. Evidence

- Unit: `php tests/unit/TwinModuleAccessTest.php` (resolver, seed, overrides, ActivityBar order).
- Browser: `modules/twinshell/docs/tools/module-access-selfcheck.js`.
- Runtime: PHASE-0.84 evidence table E-01…E-10.

## Changelog

- **1.0.0 (2026-09-30):** Initial contract from PHASE-0.84 (owner approval 2026-09-30).
