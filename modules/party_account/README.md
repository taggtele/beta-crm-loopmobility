# Party Account Module

Enterprise treasury-style slice for Loop Mobility CRM.

**RBAC** (`includes/rbac.php`):

| Role | Party Account |
|------|----------------|
| Admin | Full access (entire CRM + manage) |
| Finance | Full access **only this module** (sidebar scoped; other URLs redirect here) |
| Sales | View-only (list, detail, filters) |
| Agent | No access (403) |

## Routes (web entry)

- Main UI: `/modules/party_account/index.php` (via `public/index.php` whitelist on `modules/`)
- JSON AJAX: `/modules/party_account/ajax/{datatable,account,bulk,loop_entities,export}.php`

Session + CSRF: mutating AJAX sends `csrf_token` in JSON; read endpoints use logged-in cookie session.

## Database

Run in order after backup:

1. `migrations/001_party_account_schema.sql`
2. `migrations/002_party_account_seed.sql` (optional sample loop entities)
3. `migrations/004_party_account_am_bm.sql` (adds `assistant_manager_name`, `business_manager_name` on `party_accounts`)

RBAC provisioning is **PHP user roles** (`users.role`), not rows in DB. See `migrations/003_rbac_roles_note.sql`.

Tables: `loop_entities`, `party_accounts` (includes optional AM/BM name fields — standalone, not linked to Party AM mapping), `party_account_activity_logs` (audit / activity).

## Frontend assets

Browsable URLs must live under `public/`; this module consumes:

- `public/assets/css/pages/party-account.css`
- `public/assets/js/pages/party-account.js`

## Production verify

After deploy:

1. `mysql` apply migrations above.
2. Create or update a Finance user (`users.role = 'Finance'`).
3. Log in, open sidebar **Party Account**, confirm grid loads.
4. `/var/www/.../storage/logs/php-error.log` + mail cron unaffected (this module is UI + dedicated tables only).

## API-ready note

Heavy JSON handlers under `ajax/` can later be routed behind versioning (e.g. `/api/v1/party_accounts`) without rewriting services.
