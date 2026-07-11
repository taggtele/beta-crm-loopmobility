# Version Management

This folder tracks application versions and releases.

## Files

- `app_version.php` — **Git defaults + branding only** (`APP_COMPANY`, etc.). Not modified by Release Management UI (keeps `git pull` clean on production).
- `release.json` — **Runtime source** for version, build, release date, feature notes, and history. Writable on production by Admin.
- `changelog.md` — Detailed release notes (manual / reference)
- `services/release_version_service.php` — Load/save helpers

Sidebar footer reads `release.json` (falls back to `app_version.php` if JSON is missing).

## Admin UI

**Admin → Release Management** / **Agent → Release Notes** (`/admin/release_management.php`)

- **Admin:** publish, edit, and view releases
- **Agent:** read-only — current version, features, and history

Admin tabs:

1. **Publish new release** — new version number (e.g. `2.0.0` → `2.0.1`). Archives the current live release to history, then activates the new one.
2. **Edit current release** — same version only; fix build date, feature text, typos. Cannot change the version number here.

Each release needs: `MAJOR.MINOR.PATCH`, build `YYYYMMDD`, release date, and at least one feature line (one per line).

Saving updates `release.json` only. Edit `app_version.php` in git when you want to change shipped defaults for new installs.

## Version Format

`MAJOR.MINOR.PATCH`

- MAJOR: Breaking changes
- MINOR: New features (backward compatible)
- PATCH: Bug fixes

## Production permissions

`version_management/` must be writable by the web/PHP user so Admin can save `release.json`:

```bash
sudo chown -R nitish:www-data /var/www/crm.loopmobility.com/version_management
sudo chmod 775 /var/www/crm.loopmobility.com/version_management
```

## Current Version

See `release.json` or sidebar footer.
