# HudumaDesk — API.md

**Version 1.1 · JSON routes + page routes (implementation plan)**

The same application services power the server-rendered pages and the JSON API. Pages call `/api/v1/*` with `fetch` for dynamic actions. No separate logic.

---

## 1. Conventions

- **Base path:** `/api/v1`
- **Format:** `Content-Type: application/json` (uploads use `multipart/form-data`)
- **Auth (private routes):** PHP session cookie (`Secure`, `HttpOnly`, `SameSite=Lax`)
- **CSRF:** every `POST/PATCH/DELETE` with a cookie session needs header `X-CSRF-Token`. Get it from `GET /api/v1/auth/me` or the page's `<meta name="csrf-token">`.
- **Tenant:** resolved server-side from the authenticated membership. **Never send `tenant_id` in a request.** Any such field is ignored.
- **Timestamps:** ISO 8601 UTC, e.g. `2026-10-26T08:30:00Z`. Clients convert using the workspace timezone.
- **Identifiers:** tickets are addressed by their **reference** in URLs (`HD-000123`), always resolved together with the tenant. Internal database IDs are not used in ticket URLs or ticket payloads. Other resources use numeric IDs. Public routes use opaque tokens only.
- **Methods:** `GET` never changes state.

### Success envelope
```json
{ "data": { }, "meta": { } }
```
Lists:
```json
{
  "data": [ ],
  "meta": { "page": 1, "per_page": 25, "total": 140, "total_pages": 6 }
}
```

### Error envelope
```json
{
  "error": {
    "code": "validation_failed",
    "message": "Please correct the highlighted fields.",
    "fields": { "subject": "Subject is required." }
  }
}
```

### Status codes and error codes

| HTTP | `code` | Meaning |
|---|---|---|
| 200 / 201 / 204 | | OK / Created / No content |
| 400 | `bad_request` | Malformed JSON |
| 401 | `unauthenticated` | No/expired session |
| 403 | `forbidden` | Authenticated but lacks permission |
| 403 | `csrf_failed` | Missing/invalid CSRF token |
| 403 | `tenant_suspended` | Workspace suspended |
| 404 | `not_found` | Missing **or** not in your tenant (never reveal which) |
| 409 | `invalid_transition` / `conflict` | Bad status change or duplicate |
| 409 | `version_conflict` | Ticket changed since you loaded it; reload and retry |
| 413 | `file_too_large` | Upload over limit |
| 415 | `file_type_not_allowed` | Upload type rejected |
| 422 | `validation_failed` | Field errors in `fields` |
| 429 | `rate_limited` | Includes `Retry-After` header |
| 500 | `server_error` | Generic; details only in logs |

### Pagination, sorting, filtering
`?page=1&per_page=25&sort=-created_at` (prefix `-` = descending). `per_page` max 100.

### Optimistic locking
Ticket detail returns a `version`. Every ticket mutation (`PATCH`, `assign`, `status`, `priority`) must send it back. If someone else changed the ticket first:
```json
{ "error": { "code": "version_conflict", "message": "This ticket was updated by someone else. Reload and try again.", "current_version": 5 } }
```
Messages and attachments do not need a version.

### Validation mapping
Every private route has a request class (`StoreTicketRequest`, `AssignTicketRequest`, …). A failed validation becomes the `422 validation_failed` envelope for JSON, or a redirect back with errors and old input for Twig pages. The route file names each route (`tickets.store`) and declares its permission.

---

## 2. Auth

### `POST /api/v1/auth/register` — public
Creates a user, a workspace and an owner membership atomically. (AUTH-01)

```json
// request
{
  "first_name": "Wanjiru",
  "last_name": "Kamau",
  "email": "wanjiru@example.co.ke",
  "password": "a-long-passphrase",
  "workspace_name": "Savannah Lodge",
  "slug": "savannah-lodge",
  "timezone": "Africa/Nairobi"
}
```
```json
// 201
{
  "data": {
    "user": { "id": 12, "first_name": "Wanjiru", "last_name": "Kamau", "email": "wanjiru@example.co.ke" },
    "workspace": { "id": 7, "name": "Savannah Lodge", "slug": "savannah-lodge", "public_form_url": "https://app.example.co.ke/request/savannah-lodge", "timezone": "Africa/Nairobi" },
    "role": "owner"
  }
}
```

### `POST /api/v1/auth/login` — public, rate-limited
```json
// request
{ "email": "wanjiru@example.co.ke", "password": "a-long-passphrase" }
```
```json
// 200 (session cookie set, session ID regenerated)
{
  "data": {
    "user": { "id": 12, "first_name": "Wanjiru", "last_name": "Kamau" },
    "workspaces": [ { "id": 7, "name": "Savannah Lodge", "role": "owner" } ],
    "active_workspace_id": 7
  }
}
```
Errors: `401 invalid_credentials` (same message for unknown email and wrong password).

### `POST /api/v1/auth/logout`
`204 No Content`. Session destroyed.

### `GET /api/v1/auth/me`
```json
{
  "data": {
    "user": { "id": 12, "first_name": "Wanjiru", "last_name": "Kamau", "email": "wanjiru@example.co.ke" },
    "workspace": { "id": 7, "name": "Savannah Lodge", "timezone": "Africa/Nairobi" },
    "role": "owner",
    "permissions": ["tickets.view_all", "tickets.assign", "settings.manage"],
    "csrf_token": "b1c4...e9"
  }
}
```

### `POST /api/v1/auth/switch-workspace` — post-pilot
Not in the MVP. If a user has several memberships, login selects the most recently used active workspace. When added, the server will verify membership before switching.
```json
{ "workspace_id": 9 }
```

### `POST /api/v1/auth/forgot-password` — public, rate-limited
```json
{ "email": "wanjiru@example.co.ke" }
```
Always `200`:
```json
{ "data": { "message": "If that email exists, a reset link has been sent." } }
```

### `POST /api/v1/auth/reset-password` — public
```json
{ "token": "…", "password": "new-long-passphrase" }
```
`200` on success; `422 invalid_or_expired_token` otherwise. Token is single-use and stored hashed. All sessions for that user are invalidated.

### `POST /api/v1/invitations/accept` — public
```json
{ "token": "…", "first_name": "Brian", "last_name": "Otieno", "password": "…" }
```
Existing users can accept while logged in (no password fields needed).

---

## 3. Public requester routes

No account. Rate-limited and protected against abuse (honeypot field + per-IP limits + optional allowed email domains).

**Workspace identifier.** `{identifier}` is the workspace **slug** (default, e.g. `gleehotel`) or the random **private key** when the workspace is in private-link mode. In private-link mode the slug returns `404`. When a verified custom domain is active, the host identifies the workspace and the same routes are served without an identifier (see §5, "Public link and custom domain").

Public routes never confirm whether a slug or key exists beyond returning the form or a neutral `404`/"unavailable" response.

### `GET /api/v1/public/workspaces/{identifier}`
Form configuration. Exposes nothing private.
```json
{
  "data": {
    "name": "Savannah Lodge",
    "categories": [ { "id": 3, "name": "Wi-Fi" }, { "id": 4, "name": "Room maintenance" } ],
    "sites": [ { "id": 1, "name": "Main building" } ],
    "requires_work_email": true,
    "email_domain_hint": "gleehotel.co.ke",
    "attachments_enabled": true
  }
}
```

### `POST /api/v1/public/workspaces/{identifier}/tickets`
```json
// request
{
  "requester_name": "James Mwangi",
  "requester_email": "james@example.com",
  "subject": "Wi-Fi down in room 214",
  "description": "No connection since morning.",
  "category_id": 3,
  "site_id": 1,
  "website": ""
}
```
`website` is the honeypot; must be empty.
```json
// 201
{
  "data": {
    "reference": "HD-000123",
    "tracking_url": "https://app.example.co.ke/track/Zk3p…",
    "message": "We have received your request."
  }
}
```
The raw tracking token is shown/emailed once; only its hash is stored.

Errors specific to this route:

| HTTP | `code` | When |
|---|---|---|
| 404 | `not_found` | Unknown identifier, wrong mode (slug used in private mode), or form disabled |
| 403 | `tenant_suspended` | Workspace suspended (the page shows a neutral "unavailable" message) |
| 422 | `email_domain_not_allowed` | `allowed_email_domains` is set and the requester email is outside it |
| 422 | `validation_failed` | Field errors |
| 429 | `rate_limited` | Too many submissions |

### `GET /api/v1/track/{token}`
Narrow, public-safe view. **Never** includes internal notes, agent emails, or other tickets.
```json
{
  "data": {
    "reference": "HD-000123",
    "subject": "Wi-Fi down in room 214",
    "status": { "code": "in_progress", "label": "In progress" },
    "created_at": "2026-10-26T06:10:00Z",
    "updated_at": "2026-10-26T07:02:00Z",
    "messages": [
      { "author": "Support team", "body": "A technician is on the way.", "created_at": "2026-10-26T07:02:00Z" }
    ],
    "can_reply": true
  }
}
```
Unknown, expired or revoked token → `404 not_found`.

### `POST /api/v1/track/{token}/replies`
```json
{ "body": "Still not working, thanks." }
```
`201` with the created public message. Reopens a `pending` ticket to `in_progress` per workspace policy.

---

## 4. Tickets (staff)

Every route below requires login, tenant resolution and permission checks. Tickets are always loaded by **reference + tenant_id**.

### `GET /api/v1/tickets`
Filters: `status`, `priority`, `assigned_user_id`, `team_id`, `category_id`, `site_id`, `q` (subject/reference/requester), `created_from`, `created_to`, `overdue=1`, `unassigned=1`, `mine=1`.
Agents only receive tickets for their teams (or assigned to them).

```json
{
  "data": [
    {
      "reference": "HD-000123",
      "subject": "Wi-Fi down in room 214",
      "status": { "code": "in_progress", "label": "In progress" },
      "priority": { "code": "high", "label": "High" },
      "requester": { "name": "James Mwangi", "email": "james@example.com" },
      "team": { "id": 2, "name": "IT" },
      "assigned_user": { "id": 15, "name": "Brian Otieno" },
      "category": { "id": 3, "name": "Wi-Fi" },
      "due_at": "2026-10-26T14:10:00Z",
      "is_overdue": false,
      "created_at": "2026-10-26T06:10:00Z",
      "updated_at": "2026-10-26T07:02:00Z"
    }
  ],
  "meta": { "page": 1, "per_page": 25, "total": 1, "total_pages": 1 }
}
```

### `POST /api/v1/tickets` — `tickets.create`
```json
{
  "requester_name": "Front desk",
  "requester_email": "frontdesk@savannah.co.ke",
  "subject": "Printer jammed",
  "description": "Reception printer shows paper jam.",
  "category_id": 5,
  "site_id": 1,
  "priority": "normal",
  "team_id": 2,
  "assigned_user_id": null,
  "due_at": null
}
```
`201` returns the full ticket (same shape as detail). Ticket number is allocated from the tenant counter in the same transaction as the ticket, its first event and the notification outbox row.

### `GET /api/v1/tickets/{ref}`
```json
{
  "data": {
    "reference": "HD-000123",
    "version": 4,
    "subject": "Wi-Fi down in room 214",
    "description": "No connection since morning.",
    "status": { "code": "in_progress", "label": "In progress" },
    "priority": { "code": "high", "label": "High" },
    "requester": { "name": "James Mwangi", "email": "james@example.com" },
    "team": { "id": 2, "name": "IT" },
    "assigned_user": { "id": 15, "name": "Brian Otieno" },
    "category": { "id": 3, "name": "Wi-Fi" },
    "site": { "id": 1, "name": "Main building" },
    "due_at": "2026-10-26T14:10:00Z",
    "resolved_at": null,
    "closed_at": null,
    "created_at": "2026-10-26T06:10:00Z",
    "updated_at": "2026-10-26T07:02:00Z",
    "allowed_transitions": ["pending", "resolved", "assigned"],
    "messages": [
      { "id": 3301, "visibility": "public", "author": { "id": 15, "name": "Brian Otieno" }, "body": "On my way.", "created_at": "2026-10-26T07:02:00Z" },
      { "id": 3302, "visibility": "internal", "author": { "id": 15, "name": "Brian Otieno" }, "body": "Access point on floor 2 rebooting.", "created_at": "2026-10-26T07:10:00Z" }
    ],
    "attachments": [
      { "id": 55, "original_name": "photo.jpg", "mime_type": "image/jpeg", "size_bytes": 184233 }
    ],
    "events": [
      { "id": 8801, "event_type": "status_changed", "actor": { "id": 15, "name": "Brian Otieno" }, "old_value": { "status": "assigned" }, "new_value": { "status": "in_progress" }, "created_at": "2026-10-26T07:00:00Z" }
    ]
  }
}
```

### `PATCH /api/v1/tickets/{ref}` — edit subject/description/category/site
Fields are optional but `version` is required; changes are logged as events.

### `POST /api/v1/tickets/{ref}/assign` — `tickets.assign`
```json
{ "team_id": 2, "assigned_user_id": 15, "version": 4 }
```
Both optional (set one, both, or `null` to clear). Team and user must belong to the same tenant, else `422`. Status moves to `assigned` if it was `open` and an assignee is set.

### `POST /api/v1/tickets/{ref}/status` — `tickets.status`
```json
{ "status": "resolved", "note": "Access point replaced.", "version": 4 }
```
Invalid transitions → `409 invalid_transition` with allowed list:
```json
{ "error": { "code": "invalid_transition", "message": "Cannot move from closed to resolved.", "allowed": ["in_progress"] } }
```
`resolved_at` / `closed_at` are set automatically; reopening clears them.

### `POST /api/v1/tickets/{ref}/priority` — `tickets.priority`
```json
{ "priority": "urgent", "due_at": "2026-10-26T10:00:00Z", "version": 4 }
```
`due_at` is optional; if omitted, it is recalculated from the priority rule.

### `POST /api/v1/tickets/{ref}/messages` — `tickets.reply` / `tickets.note`
```json
{ "visibility": "public", "body": "A technician will visit within the hour." }
```
`visibility`: `public` (queues email to the requester) or `internal`. `201` returns the message.

### Attachments
`POST /api/v1/tickets/{ref}/attachments` (`multipart/form-data`, field `file`, optional `message_id`)
```json
// 201
{ "data": { "id": 56, "original_name": "invoice.pdf", "mime_type": "application/pdf", "size_bytes": 90211 } }
```
Allowlist: `jpg, jpeg, png, pdf, txt` (extendable). Max 5 MB (configurable). Stored outside `public/` under a random key. MIME is checked server-side.

`GET /api/v1/tickets/{ref}/attachments/{attachmentId}` streams the file after authorization (ticket in tenant + permission), with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.

`DELETE /api/v1/tickets/{ref}/attachments/{attachmentId}` — admin or uploader.

### `POST /api/v1/tickets/{ref}/tracking-token/regenerate` — `tickets.status`
Revokes the old public token, returns the new URL once.

---

## 5. Workspace management

### Teams
| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/teams` | List with member counts |
| POST | `/api/v1/teams` | `{ "name": "IT", "site_id": 1 }` — `teams.manage` |
| PATCH | `/api/v1/teams/{id}` | Rename, status |
| POST | `/api/v1/teams/{id}/members` | `{ "user_id": 15 }` — user must be an active member |
| DELETE | `/api/v1/teams/{id}/members/{userId}` | |

```json
{ "data": { "id": 2, "name": "IT", "site_id": 1, "status": "active", "member_count": 3 } }
```

### Members and invitations
| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/members` | Active, disabled and pending |
| PATCH | `/api/v1/members/{userId}` | `{ "role": "admin" }` or `{ "status": "disabled" }` |
| DELETE | `/api/v1/members/{userId}` | Removes membership, not the global user |
| POST | `/api/v1/invitations` | `{ "email": "brian@example.co.ke", "role": "agent", "team_ids": [2] }` |
| POST | `/api/v1/invitations/{id}/resend` | |
| DELETE | `/api/v1/invitations/{id}` | Revoke |

Rules: only owner/admin can invite; admins cannot create owners; the last owner cannot be removed or demoted. Disabling a member invalidates their sessions.

### Categories and sites
Standard CRUD, `categories.manage` / `sites.manage`:
`GET|POST /api/v1/categories`, `PATCH|DELETE /api/v1/categories/{id}`
`GET|POST /api/v1/sites`, `PATCH|DELETE /api/v1/sites/{id}`

```json
{ "data": { "id": 3, "name": "Wi-Fi", "default_priority": "normal", "team_id": 2 } }
```
Categories with a `team_id` auto-route new tickets to that team.

### Settings
`GET /api/v1/settings` · `PATCH /api/v1/settings` — `settings.manage`
```json
{
  "data": {
    "name": "Savannah Lodge",
    "timezone": "Africa/Nairobi",
    "contact_email": "it@savannah.co.ke",
    "contact_phone": "+254700000000",
    "slug": "gleehotel",
    "public_form_enabled": true,
    "public_form_mode": "slug",
    "public_form_url": "https://app.example.co.ke/request/gleehotel",
    "allowed_email_domains": ["gleehotel.co.ke"],
    "allow_requester_replies": true,
    "attachments_enabled": true
  }
}
```
### Public link and custom domain

`public_form_mode` is `slug` (default) or `private`.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/api/v1/slugs/check?slug=gleehotel` | public, rate-limited | `{ "data": { "available": true } }` |
| PATCH | `/api/v1/settings/slug` | `settings.manage` (owner) | `{ "slug": "gleehotel" }`. Old slug redirects to the new one |
| PATCH | `/api/v1/settings/public-form` | `settings.manage` | `{ "mode": "private", "enabled": true, "allowed_email_domains": ["gleehotel.co.ke"] }` |
| POST | `/api/v1/settings/public-form/regenerate` | `settings.manage` | Rotates the private key. Old key stops working immediately |

Slug rules: lowercase letters, digits and hyphens, 3–40 characters, unique across the platform, not on the reserved list (`app, api, admin, platform, login, register, track, request, invite, www, support, help, static, assets`). Violations return `422 validation_failed`. Slugs are changed by the owner only; recent previous slugs redirect (`301`) for at least 12 months.

`allowed_email_domains` is optional. When set, public submissions must use an email on one of the listed domains, and the ticket is confirmed through the emailed tracking link. Staff-created tickets are not restricted.

#### Custom domain (post-pilot, higher plan)

Example: `helpdesk.gleenairobi.com` pointing to the platform. Staff sign-in stays on the main app domain.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/settings/domain` | Current domain, status, DNS instructions |
| POST | `/api/v1/settings/domain` | `{ "domain": "helpdesk.gleenairobi.com" }` starts verification |
| POST | `/api/v1/settings/domain/verify` | Re-check DNS and issue the certificate |
| DELETE | `/api/v1/settings/domain` | Remove the domain |

```json
{
  "data": {
    "domain": "helpdesk.gleenairobi.com",
    "status": "pending_verification",
    "dns": [
      { "type": "CNAME", "name": "helpdesk.gleenairobi.com", "value": "custom.hudumadesk.co.ke" },
      { "type": "TXT", "name": "_hudumadesk.helpdesk.gleenairobi.com", "value": "hd-verify=3f9c1a…" }
    ],
    "ssl_status": "pending"
  }
}
```
`status`: `pending_verification` → `active` (or `failed`). Once active, `GET /request` and `/track/{token}` work on that host, and email links use it. The slug URL keeps working as a fallback.

---

## 6. Dashboard and reports

### `GET /api/v1/dashboard?period=7d`
`overdue` is computed on read (`due_at` in the past and status not terminal).
`period`: `today`, `7d`, `30d`, or `from`/`to`.
```json
{
  "data": {
    "counts": { "open": 12, "assigned": 8, "in_progress": 15, "pending": 3, "resolved": 40, "closed": 120, "overdue": 4, "unassigned": 5 },
    "avg_resolution_hours": 9.2,
    "recent": [ { "reference": "HD-000123", "subject": "Wi-Fi down in room 214", "status": "in_progress", "priority": "high" } ]
  }
}
```

### `GET /api/v1/reports/summary?from=2026-10-01&to=2026-10-31`
```json
{
  "data": {
    "created": 210,
    "resolved": 188,
    "resolved_on_time_pct": 86.4,
    "avg_resolution_hours": 9.2,
    "by_category": [ { "category": "Wi-Fi", "count": 61 } ],
    "by_agent": [ { "user": "Brian Otieno", "resolved": 54, "avg_resolution_hours": 7.8 } ],
    "by_priority": [ { "priority": "high", "count": 35 } ]
  }
}
```
CSV export is post-pilot. The summary above is enough for the first customer.

### `GET /api/v1/audit-logs` — owner/admin
Filters: `action`, `actor_user_id`, `from`, `to`. Returns sanitized entries (no secrets or message bodies).

---

## 7. Platform administration

Requires `is_platform_admin`. Metadata only; no ticket content.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/platform/overview` | Tenant, user and ticket counts |
| GET | `/api/v1/platform/tenants` | List; `?status=&q=` |
| GET | `/api/v1/platform/tenants/{id}` | Metadata, plan, usage |
| POST | `/api/v1/platform/tenants/{id}/suspend` | `{ "reason": "Non-payment" }` |
| POST | `/api/v1/platform/tenants/{id}/reactivate` | |
| PATCH | `/api/v1/platform/tenants/{id}/subscription` | `{ "plan_code": "starter", "status": "active", "current_period_end": "2026-11-30" }` (manual billing) |
| GET | `/api/v1/platform/health` | App, DB, outbox backlog, last backup time |

```json
{
  "data": {
    "id": 7,
    "name": "Savannah Lodge",
    "status": "active",
    "plan": { "code": "starter", "status": "active", "current_period_end": "2026-11-30" },
    "usage": { "agents": 3, "tickets_this_month": 210 },
    "created_at": "2026-10-26T06:00:00Z"
  }
}
```
Suspending invalidates tenant sessions, blocks the public form, and writes an audit entry.

---

## 8. Rate limits (starting values)

| Route | Limit |
|---|---|
| `POST /auth/login` | 5/min per IP + email |
| `POST /auth/forgot-password` | 3/hour per IP + email |
| `POST /invitations` | 20/hour per tenant |
| `POST /public/.../tickets` | 5/10 min per IP, 100/day per tenant |
| `POST /track/{token}/replies` | 10/hour per token |
| Token lookups `GET /track/{token}` | 30/min per IP |
| `GET /slugs/check` | 20/min per IP |

Exceeded → `429 rate_limited` + `Retry-After`.

---

## 9. Page routes (server-rendered HTML)

Pages render with the same services; mutations go through forms (with CSRF field) or `fetch` to the API.

### Public
| Method | Path | Purpose |
|---|---|---|
| GET | `/` | Landing page |
| GET/POST | `/register` | Workspace registration |
| GET/POST | `/login` | Login |
| POST | `/logout` | Logout |
| GET/POST | `/forgot-password` | Request recovery |
| GET/POST | `/reset-password` | Reset |
| GET/POST | `/invite/{token}` | Accept invitation |
| GET/POST | `/request/{slug-or-key}` | Public ticket form (slug by default, private key in private mode) |
| GET/POST | `/request` | Public ticket form on a verified custom domain |
| GET | `/track/{token}` | Tracking page (reply via API) |

### Workspace (`/app`)
| Method | Path | Purpose |
|---|---|---|
| GET | `/app` | Dashboard |
| GET | `/app/tickets` | List + filters |
| GET | `/app/tickets/create` | New ticket |
| GET | `/app/tickets/{ref}` | Ticket detail |
| GET | `/app/teams` | Teams |
| GET | `/app/members` | Members and invitations |
| GET | `/app/categories` | Categories and sites |
| GET | `/app/settings` | Settings |
| GET | `/app/reports` | Reports |
| GET | `/app/audit` | Audit log |

### Platform (`/platform`)
`GET /platform`, `/platform/tenants`, `/platform/tenants/{id}`, `/platform/health`.

---

## 10. Route rules (apply to every private route)

1. Authenticate, then resolve tenant from membership.
2. Load resources by **reference or id + tenant_id**.
3. Authorize the action (policy) against user, tenant and resource.
4. Validate input, run the service, return the envelope.
5. Check CSRF on every state-changing request.
6. Return `404` rather than `403` when the resource is outside the tenant.

---

## 11. Route-to-requirement map

| Routes | Requirement IDs |
|---|---|
| `/auth/register`, `/auth/login`, `/auth/logout` | AUTH-01, AUTH-02 |
| `/auth/forgot-password`, `/auth/reset-password` | AUTH-03 |
| middleware + `/auth/me`, `/auth/switch-workspace` | TEN-01 |
| `/invitations*`, `/members*` | TEN-02, RBAC-01 |
| `/settings*`, `/slugs/check`, `/settings/domain*` | TEN-03 |
| `POST /tickets`, `/public/.../tickets` | TKT-01, TKT-02 |
| `/tickets/{ref}/assign` | TKT-03 |
| `/tickets/{ref}/status` | TKT-04 |
| `/tickets/{ref}/messages`, `/track/{token}/replies` | TKT-05 |
| `/tickets/{ref}/priority` | TKT-06 |
| `GET /tickets` | TKT-07 |
| `/tickets/{ref}/attachments*` | TKT-08 |
| outbox (internal) | MAIL-01 |
| `/dashboard`, `/reports/*` | RPT-01 |
| `/audit-logs` | AUD-01 |
| `/platform/*` | PLAT-01 |

---

## 12. Implementation notes

### Routes are declared in files, with names and permissions
```php
// routes/api.php
$router->group(['prefix' => '/api/v1', 'middleware' => ['auth', 'tenant', 'csrf']], function ($r) {
    $r->get('/tickets',               [TicketApiController::class, 'index'])->name('api.tickets.index')->can('tickets.view_team');
    $r->post('/tickets',              [TicketApiController::class, 'store'])->name('api.tickets.store')->can('tickets.create');
    $r->get('/tickets/{ref}',         [TicketApiController::class, 'show'])->name('api.tickets.show');
    $r->post('/tickets/{ref}/assign', [TicketApiController::class, 'assign'])->name('api.tickets.assign')->can('tickets.assign');
});
```

### Naming convention
Page routes: `tickets.index`, `tickets.show`, `tickets.store`. API routes: `api.tickets.*`. Public: `public.request.show`, `public.request.store`, `public.track.show`. Links in Twig and email use `route('tickets.show', {ref: ticket.reference})`.

### One service, two skins
`POST /app/tickets` (Twig form) and `POST /api/v1/tickets` (JSON) call the same `CreateTicket` service with the same request class. Only the response differs: redirect with a flash message, or the JSON envelope.

### Tenant context
Middleware builds a `TenantContext` from the session membership. Controllers receive it as a parameter. No request field named `tenant_id` is ever read.
