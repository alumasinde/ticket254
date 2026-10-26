# HudumaDesk pilot guide

This is the shortest path from a clean machine to a demo a hotel can click
through, plus the pieces that must be in place before real guests use it.

## 1. Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `fileinfo`, `json` and `openssl`
- MariaDB 10.4+ / MySQL 8
- Composer 2
- `mysqldump` and `mysql` clients on the server (same major version as the DB)
- HTTPS for anything reachable by guests. Session cookies are marked secure in
  production, so plain HTTP logins will not stick.
- Two cron entries (or Windows Task Scheduler jobs) for the worker and backups.
  The app has no web-triggered worker.

## 2. Install

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Fill in `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://desk.example.co.ke

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=hudumadesk
DB_USER=hudumadesk
DB_PASS=...

SESSION_SECURE=true
SECURITY_HSTS=true        # only after HTTPS is confirmed working
BACKUP_RETAIN_DAYS=14
MYSQLDUMP_BIN=            # empty = autodetect XAMPP, then PATH
MYSQL_BIN=
```

Then create the schema:

```bash
php hd migrate
php hd migrate:status
```

Point the web root at `public/`; do not serve the project root. `.env`,
`storage/` and `app/` must not be reachable over HTTP.

## 3. Seed the Glee Hotel demo

```bash
php hd demo:seed-glee
```

Example output:

```text
workspace=Glee Hotel slug=glee-hotel-demo owner=owner@glee-hotel-demo.example password=Glee-1a2b3c4d5e6f! (generated)
members=6 sites=3 tickets=14
staff=brian.kiptoo@glee-hotel-demo.example,wanjiku.mwangi@glee-hotel-demo.example,peter.otieno@glee-hotel-demo.example,grace.njeri@glee-hotel-demo.example,hassan.ali@glee-hotel-demo.example
public_request_url=https://desk.example.co.ke/request/glee-hotel-demo
note=Demo data only. Rotate these credentials before a real pilot.
```

Options:

```bash
php hd demo:seed-glee --email=owner@yourhotel.co.ke --password='a-long-passphrase' --slug=glee-hotel-demo
```

The seeder:

- creates the workspace, owner, three sites, five staff and fourteen tickets
- sends every ticket through the real create/assign/reply/transition services,
  so events, counters, first-response times, the outbox and the dashboard are
  consistent
- writes a `demo.seeded` audit entry
- refuses to run if the slug or owner email already exists

The owner and all staff share the password printed above. That is fine for a
demo; it is not fine for a live workspace. The demo addresses use the
`@<slug>.example` domain and cannot receive mail, so outbox mail only lands in
the log (`MAIL_DRIVER=log`).

To show a prospect, sign in as `owner@glee-hotel-demo.example` and open
**Dashboard**, **Tickets**, a ticket timeline, the public request URL and the
audit page.

## 4. Production hardening checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false` in `.env`
- [ ] `APP_URL` is the real HTTPS origin
- [ ] `SESSION_SECURE=true` and `SECURITY_HSTS=true`
- [ ] `.env` readable only by the service account
- [ ] `storage/` and `storage/backups/` writable by PHP, not web-readable
- [ ] Database user limited to this one database (no `GRANT ALL` on `*.*`)
- [ ] TLS certificate valid; HTTP redirects to HTTPS at the proxy
- [ ] `php hd migrate:status` shows every migration applied
- [ ] Worker and backup jobs scheduled (below)
- [ ] Demo credentials changed or the demo workspace removed before real staff
      on-boarding (there is no delete-workspace command; drop the demo tenant in
      SQL or rebuild the database if it was only a demo)

The app sends these headers on every response, including error pages:

`X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`,
`Permissions-Policy`, `Cross-Origin-Opener-Policy` and a strict CSP
(`default-src 'self'`). `Strict-Transport-Security` is added when
`SECURITY_HSTS=true`.

## 5. Scheduled jobs

Linux cron:

```cron
* * * * * cd /srv/hudumadesk && php hd queue:work --once --limit=50 >> storage/logs/worker.log 2>&1
*/10 * * * * cd /srv/hudumadesk && php hd rate-limit:prune --days=1 >> storage/logs/maintenance.log 2>&1
15 2 * * * cd /srv/hudumadesk && php hd db:backup --verify >> storage/logs/backup.log 2>&1
```

Windows Task Scheduler: run `php.exe hd queue:work --once` every minute,
`php.exe hd rate-limit:prune` every ten minutes and `php.exe hd db:backup
--verify` nightly, with the project directory as the working directory.

`queue:work --once` sends one batch and exits, which is the right shape for
cron. Without a worker, ticket mail stays queued; requesters still see replies
on the tracking page.

`rate-limit:prune` deletes expired login and public-form windows after a one-day
grace period. The worker also prunes opportunistically, but the cron entry keeps
the table bounded even when no mail is flowing.

## 6. Backup and restore runbook

Backups land in `storage/backups/` as gzipped SQL, mode `0600`, and are pruned
after `BACKUP_RETAIN_DAYS`.

Nightly verified backup:

```bash
php hd db:backup --verify
```

`--verify` does three things:

1. streams a fresh dump through gzip;
2. restores it into `<DB_NAME>_restore_test`;
3. compares row counts per table with the source and drops the scratch
   database.

It prints a `source=` / `restored=` line per table and exits non-zero with
`Backup verification FAILED` if anything differs. The nightly job should alert
on a non-zero exit.

Restore into a scratch database first:

```bash
php hd db:restore storage/backups/hudumadesk_20261026_021500.sql.gz \
  --database=hudumadesk_test --create
```

`db:restore` only overwrites databases whose name ends in `_test` unless
`--force` is passed. For a real recovery:

1. put the app in maintenance (stop the worker and web traffic);
2. take one more current backup if the database is still reachable;
3. restore:

   ```bash
   php hd db:restore storage/backups/<file>.sql.gz --force
   ```

4. run `php hd migrate:status` to confirm the schema ledger matches the code;
5. restart the worker and check the dashboard plus one recent ticket.

Restoring replaces the target database's tables; anything written after the
dump is lost. Restore to a `_test` database to rehearse before touching
production.

If `mysqldump` or `mysql` is not on `PATH`, set `MYSQLDUMP_BIN` and `MYSQL_BIN`
to their absolute paths. The backup utility passes the password through
`MYSQL_PWD`, so it does not appear in the process list.

## 7. First-week onboarding checklist

Day 1 — access:

- [ ] Owner signs in, changes the demo password, enables HTTPS-only access
- [ ] Owner creates real admin and agent accounts (Members → Invite)
- [ ] Staff accept invitations and sign in on their own devices
- [ ] Confirm staff only see their teams/assigned tickets unless given
      `tickets.view_all`

Day 1 — structure:

- [ ] Settings: workspace name, timezone (`Africa/Nairobi`), contact email
- [ ] Sites: one per property or building
- [ ] Teams: IT, Engineering, Housekeeping, Front Office; assign members
- [ ] Categories: keep the six starter categories or rename them to the hotel's
      language; set a default team and priority for each
- [ ] Ticket statuses and priorities reviewed with the duty manager

Day 2 — public intake:

- [ ] Choose slug or private-link mode for the public form
- [ ] Print the QR code and place it at reception, in rooms and at the bar
- [ ] Submit a test request from a phone and confirm it appears in the dashboard
- [ ] Reply publicly and confirm the requester tracking page updates
- [ ] Add an internal note and confirm the requester never sees it
- [ ] Send the WhatsApp share link to the front-office group

Day 3–5 — operations:

- [ ] Duty manager reviews **Needs attention** each morning
- [ ] Confirm overdue tickets and unassigned tickets are visible
- [ ] Run a full pass: create → assign → in progress → pending → resolved →
      closed, checking the timeline at each step
- [ ] Check the audit page for the expected events
- [ ] Confirm nightly `db:backup --verify` output in `storage/logs/backup.log`
- [ ] Rehearse a restore into `hudumadesk_test`

## 8. What to watch in week one

- Dashboard counts: unassigned and overdue should trend down, not just total
  volume
- First response time: anything over four hours shows in "Needs attention"
- Outbox: `claimed` with `failed` greater than zero means mail delivery or
  credentials need attention
- Audit log: expected actions only; a spike in denied actions suggests role or
  team setup is wrong
- Backup log: every night must end with `verified=...` and exit 0
