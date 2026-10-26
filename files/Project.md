# HudumaDesk — Project.md

**Version 1.1 · 26 October 2026 · Engineering baseline (implementation plan)**
*HudumaDesk is a provisional name. Check name, domain and trademark availability before launch.*

---

## 1. What we are building

A Kenyan-first, multi-tenant ticketing and service-desk SaaS. One hosted application serves many independent businesses. Each business gets its own workspace, staff, teams, categories and tickets.

**First customers:** hotels and SMEs (hotel IT/maintenance, SME and IT-support requests).
**Principle:** one generic ticket engine, one excellent starter template. Other industries come later through templates, not new codebases.

### The problem
Requests are scattered across WhatsApp chats, calls, email and verbal instructions. Businesses cannot tell who owns a request, what is overdue, what is resolved, or which problems keep recurring.

### The promise
A business can register, invite staff, and receive its first ticket in **under 15 minutes**. Customers need no app and no account.

---

## 1a. Worked example: Glee Hotel Limited

**Setup (once, by the IT manager who registers as owner)**
1. Register the workspace "Glee Hotel Limited" (slug `gleehotel`).
2. Create sites (Main Hotel, Annex, Conference Centre) and teams (IT, Engineering, Housekeeping, Front Office).
3. Create categories, each mapped to a team: Wi-Fi/Network → IT, Computer/Printer → IT, POS/Systems → IT, Room maintenance → Engineering.
4. Invite IT staff as agents and add them to the IT team; the IT manager is an admin.
5. Share `/request/gleehotel` as a QR code and in staff WhatsApp groups. Optionally restrict submissions to `gleehotel.co.ke` emails.

**Raising a ticket (employee, no account)**
1. Open the link, fill in name, email, site, category, subject, description, optional photo.
2. Receive reference `HD-000123` and a private tracking link by email.
3. Follow progress and reply through the tracking link.

**Handling (IT)**
`Open` → category routes to the IT team → team and manager notified → agent self-assigns or manager assigns → `In progress` → `Pending` (if waiting on the employee) → `Resolved` → `Closed`. Staff can also create tickets for walk-ins and phone calls.

---

## 2. Decisions (locked for MVP)

| Area | Decision |
|---|---|
| Tenancy | One app, one MySQL database, `tenant_id` on every tenant-owned table |
| Backend | Custom PHP modular monolith with **Laravel-style conventions** (named routes, request classes, container, thin controllers, `hd` CLI). No Laravel dependency |
| Views | **Twig** (auto-escaping) + light JavaScript. Pages work without JS; JSON endpoints are a thin second layer over the same services |
| Channels | Web portal + public request form + email |
| Auth | Native PHP sessions, `password_hash`, CSRF tokens |
| Requesters | No account needed. Access via a private tracking token |
| Notifications | Transactional outbox + CLI worker (cron), email only |
| Billing | Manual invoicing, `plans`/`subscriptions` rows set by platform admin |
| Local dev | Windows / XAMPP |
| Deploy | One VPS (PHP + private MySQL), HTTPS, encrypted off-server backups |
| Public form URL | Readable slug by default: `/request/gleehotel`. Optional private (random) link per workspace |
| Public form protection | Honeypot, rate limits, optional allowed work-email domains |
| Custom domains | Post-pilot (e.g. `helpdesk.gleenairobi.com`). MVP is designed so they plug in without rewrites |

**Not in MVP:** React, Laravel, microservices, per-tenant databases, WhatsApp, SMS, M-Pesa automation, AI, workflow builders, native apps, custom domains per tenant, custom roles, workspace-switching UI, CSV export, full reports page.

---

## 3. Simplifications versus the earlier spec

Kept the spec's intent, trimmed what a solo developer does not need yet.

1. **Roles:** three workspace roles (`owner`, `admin`, `agent`) plus a platform flag on the user (`is_platform_admin`). *Requester* is not a role in the MVP; requesters are token-based and identified by name/email on the ticket. `requester_user_id` stays nullable for a future portal login.
2. **Roles table is global**, not per tenant. Custom roles come later.
3. **Reference data** (statuses, priorities) is global lookup tables, not copied per tenant. Categories are per tenant.
4. **Composite foreign keys** only where cross-tenant mix-ups are most dangerous: ticket → team, site, category. Everything else is enforced by tenant-scoped repository methods and tests.
5. **One audit table** (`audit_logs`) for security events; `ticket_events` for ticket history.
6. **Services first.** Every use case is one service class. Twig pages and JSON endpoints are thin skins over the same services. No duplicated logic.
7. **Ticket URLs use the reference** (`/app/tickets/HD-000123`), always resolved together with the tenant, not the global database ID.
8. **Overdue is computed** (`due_at < now` and not terminal), never stored, so there is no cron job or stale flag.
9. **Optimistic locking** on tickets (`version` column) so two agents cannot silently overwrite each other.
10. **Reports trimmed:** the dashboard shows open, unassigned, overdue and average resolution time. A fuller reports page and CSV export come after the pilot.
11. **Multi-workspace switching** is post-pilot. The membership table already allows it.

---

## 3a. How I would build it (engineering approach)

This document set describes the implementation I would build, not a generic spec.

1. **Custom core, Laravel conventions.** No framework dependency, but the code reads like Laravel: `routes/*.php` with named routes, groups, middleware and `->can('permission')`; `FormRequest` classes for validation; a small auto-wiring container; thin controllers; helpers (`route()`, `config()`, `old()`, `e()`, `can()`, `redirect()`); flash messages; a fluent query builder over PDO; one `php hd` CLI. Details in Design.md §1.
2. **Tenant isolation by construction.** Services and repositories take a `TenantContext` (never a bare tenant ID). All tenant repositories extend `TenantScopedRepository`, which always adds `tenant_id`. A test fails the build if a tenant-table query forgets it.
3. **Twig for every view**, with auto-escaping on and `|raw` banned by a test.
4. **Maintained libraries only where bugs become vulnerabilities:** Twig, Symfony Mailer, phpdotenv, Monolog, PHPUnit. The router, container, validator, query builder and CLI are small and written in-house (about 1,000 lines).
5. **One database engine everywhere.** XAMPP ships MariaDB; production and CI must run the same engine and version (MySQL 8 or MariaDB 10.6+), and integration tests run against it in CI.
6. **Build order** (each milestone ends with tests green):

| Milestone | Outcome |
|---|---|
| M1 | Repo, CI, `hd` CLI, migration runner, container, router, Twig, `TenantContext`, Tenant A/B test fixtures |
| M2 | Register, login, memberships, permissions, session revalidation. **Isolation tests passing** |
| M3 | One full slice: create → list → view a ticket (number, event and outbox in one transaction) |
| M4 | Assignment, lifecycle, messages and notes, events |
| M5 | Public form, tracking page, outbox worker and email |
| M6 | Attachments, dashboard, platform console |
| M7 | Hardening, deploy, backup restore drill, pilot |

> Do not start the public form or dashboard until M2's isolation tests pass.

---

## 4. Users and roles

| Role | Scope | Can do |
|---|---|---|
| Platform admin | Whole SaaS | List/suspend/reactivate tenants, view usage and health. **No routine access to ticket content** |
| Owner | One workspace | Everything in their workspace including settings and membership |
| Admin | One workspace | Staff, teams, categories, sites, all tickets. Not ownership transfer |
| Agent | One workspace | View tickets for their teams, reply, add notes, change status, self-assign |
| Requester | Public | Submit via public form; view/reply via tracking token only |

### Permission map

| Permission | Owner | Admin | Agent |
|---|:-:|:-:|:-:|
| `tickets.view_all` | ✔ | ✔ | |
| `tickets.view_team` | ✔ | ✔ | ✔ |
| `tickets.create` | ✔ | ✔ | ✔ |
| `tickets.assign` | ✔ | ✔ | self only |
| `tickets.status` | ✔ | ✔ | ✔ |
| `tickets.priority` | ✔ | ✔ | |
| `tickets.reply` / `tickets.note` | ✔ | ✔ | ✔ |
| `tickets.attach` | ✔ | ✔ | ✔ |
| `teams.manage`, `categories.manage`, `sites.manage` | ✔ | ✔ | |
| `members.manage` | ✔ | ✔ | |
| `settings.manage` | ✔ | | |
| `reports.view` | ✔ | ✔ | team only |

---

## 5. Ticket model

### Statuses
`open` → `assigned` → `in_progress` → `resolved` → `closed`, plus `pending` (waiting on requester or third party).

| From | Allowed next |
|---|---|
| open | assigned, in_progress, pending, closed |
| assigned | in_progress, pending, open, resolved |
| in_progress | pending, resolved, assigned |
| pending | in_progress, assigned, resolved |
| resolved | closed, in_progress (reopen) |
| closed | in_progress (reopen, policy permitting) |

All transitions live in **one** service: `TicketLifecycle`.

### Priorities
`low`, `normal`, `high`, `urgent`. Each has a default due-time rule (e.g. urgent = 4h, high = 8h, normal = 24h, low = 72h) that sets `due_at` on creation. Staff can override. No SLA calendar in the MVP.

### Assignment
A ticket has a **team** and, separately, an **assigned agent**. A ticket can be routed to a team without picking an agent. Assignee must belong to the same tenant (and ideally the team).

Every ticket mutation carries the `version` the client loaded. If it has changed, the server returns `409 version_conflict` and the UI asks the agent to reload.

### Messages vs events
- **Messages**: conversation. `visibility` = `public` (requester sees) or `internal` (staff only).
- **Events**: history of changes (status, assignment, priority, due date, reopen).

---

## 6. MVP success criteria

A pilot business can:

1. Register a workspace and set its name and timezone.
2. Invite staff and assign roles.
3. Receive tickets via a public form or staff-created ticket.
4. Assign tickets to teams and agents.
5. Communicate with requesters via messages and email.
6. Track status, priority, deadlines and history.
7. Search tickets and see a dashboard with open, unassigned, overdue and average resolution time.
8. Be certain no other business can see its data.

### Pilot metrics
- Time from signup to first ticket.
- % tickets assigned; % resolved before `due_at`.
- Tickets with no update for 48h+.
- Weekly active agents; repeat requesters.
- % notifications delivered.
- Does the pilot agree to pay?

---

## 7. Roadmap (solo, ~10 weeks)

| Week | Focus | Deliverable |
|---|---|---|
| 1 | Validate | Talk to 5–10 businesses, clickable prototype, one pilot lined up |
| 2–3 | **Secure foundation (M1–M2)** | Repo, CI, `hd` CLI, router, container, Twig, migrations, auth, tenants, memberships, permissions, `TenantContext`, **cross-tenant tests passing** |
| 4–5 | Ticketing | Create, number, assign, lifecycle, messages, events, search |
| 6–7 | Customer access | Public form, tracking link, email outbox + worker, attachments, dashboard, mobile layout |
| 8–9 | Hardening + deploy | Security test suite, rate limits, HTTPS, backups + tested restore |
| 10 | Pilot | Onboard one business, observe, fix, agree price |

> First milestone is **not** the dashboard. It is a tenant foundation proven by tests. If time runs short, cut scope, never the security tests.

### Backlog checklist
**Foundation**
- [ ] Repo, Composer, CI (same DB engine as production), `.env`, Twig, error handling, router, container, FormRequest base class, `php hd` CLI
- [ ] Migration runner (ledger with filename + checksum)
- [ ] PDO, transactions, logging
- [ ] Register, login, logout, workspace + owner (atomic)
- [ ] Roles, permissions, tenant resolution, CSRF, session hardening

**Ticketing**
- [ ] Reference data seeds (statuses, priorities, permissions)
- [ ] Create ticket with tenant-scoped numbering + event
- [ ] Teams, assignment, status transitions, priority, due date
- [ ] Public replies, internal notes, event history

**Customer access**
- [ ] Public request form + tracking token page
- [ ] Email outbox, worker, retries, templates
- [ ] Private attachments

**Pilot readiness**
- [ ] Dashboard (four numbers) + simple summary
- [ ] Security test suite (see §9)
- [ ] Deploy, HTTPS, backups, restore drill
- [ ] Onboard pilot

---

## 8. Requirements traceability

| ID | Requirement | Acceptance |
|---|---|---|
| AUTH-01 | Registration | One owner + one workspace created atomically |
| AUTH-02 | Login/logout | Session created; logout invalidates it |
| AUTH-03 | Password recovery | Token hashed, expires, single-use |
| TEN-01 | Workspace context | Every private request resolves an authorized tenant |
| TEN-02 | Invitations | Owners/admins invite into their own workspace only |
| TEN-03 | Settings | Name, timezone, contact details editable |
| RBAC-01 | Permissions | Roles gate actions, not just pages |
| TKT-01 | Create ticket | Staff or public requester can submit |
| TKT-02 | Reference | Unique, tenant-scoped, human-readable (e.g. `HD-000123`) |
| TKT-03 | Assignment | Only to valid same-tenant teams/agents |
| TKT-04 | Transitions | Only valid transitions allowed |
| TKT-05 | Conversation | Public replies and internal notes separated |
| TKT-06 | Priority/deadline | Authorized staff set both |
| TKT-07 | Search | Tenant- and permission-scoped |
| TKT-08 | Attachments | Validated upload, authorized download |
| MAIL-01 | Notifications | Queued, delivered or retried |
| RPT-01 | Dashboard | Counts/lists scoped to tenant and period |
| AUD-01 | Audit trail | Sensitive changes recorded |
| PLAT-01 | Platform admin | Inspect and suspend/reactivate tenants |

---

## 9. Quality gates

### Mandatory cross-tenant tests (release blockers)
Create tenants A and B with separate users. Verify:
- A cannot view or modify B's ticket by numeric ID.
- A cannot assign to B's team or user.
- Internal notes never appear to requesters or via tracking tokens.
- A disabled member's existing session stops working.
- A tracking token grants access to its one ticket only.
- Attachment URLs cannot fetch another tenant's file.
- Search, dashboard, reports and exports never include other tenants' rows.
- Platform admin cannot read ticket content without the explicit permission.

### Before every pilot deployment
- Migrations run on a fresh DB; all tests pass.
- Production error display off; DB not publicly reachable.
- `.env`, `storage/`, logs not reachable over HTTP.
- Backup restored into a clean environment.
- Logs contain no passwords, reset tokens, session IDs or message bodies.
- Tests use fake mail only.

---

## 10. Privacy and operations

- Collect only what a ticket needs. **No** health records, ID documents or card data.
- Publish a privacy notice; define retention and deletion for tickets, attachments, backups.
- Review obligations under the Kenya Data Protection Act, 2019 and ODPC registration for the SaaS, its customers and processors.
- Document incident response and who may access production data.
- Support access to a tenant's data is exceptional, purpose-limited and audited.

---

## 11. Pricing (hypotheses to test)

| Plan | KSh/month | Limits |
|---|---|---|
| Free | 0 | 2 agents, 100 tickets/mo |
| Starter | 1,000 | 3 agents, 500 tickets/mo |
| Team | 2,500 | 10 agents, 2,000 tickets/mo |
| Business | 6,000 | 25 agents, multiple branches |
| Custom | Quoted | Integrations, support |

Invoice manually at first. Automate only when manual work hurts. Money is stored in integer minor units.

---

## 12. After the pilot

M-Pesa Daraja subscriptions · official WhatsApp Business Platform · SMS · SLA calendars and escalations · industry templates · API keys · analytics · custom roles · custom domains per tenant (e.g. `helpdesk.gleenairobi.com`, see Design.md §4a) · workspace-switching UI · CSV export and a fuller reports page.

Design now so these plug in behind interfaces (`NotificationChannel`, `PaymentProvider`), but build none of them yet.

---

## 13. Open decisions

1. Final product name and domain.
2. Free plan: permanent or time-limited pilot?
3. First pilot: hotel or SME?
4. Hosting provider and email provider.
5. Should `allowed_email_domains` be on by default for new workspaces, or off until the owner enables it?
6. Is custom domain a paid-plan feature (recommended) or an add-on?
