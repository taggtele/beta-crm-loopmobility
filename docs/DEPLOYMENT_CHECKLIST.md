# Quick Start — Email System v2 Deployment

## Pre-Deployment Checklist

- [ ] Backup current database: `mysqldump -u root -p noc > backup_$(date +%F).sql`
- [ ] Backup current files: `cp -r /path/to/noc /path/to/noc-backup-$(date +%F)`
- [ ] Verify PHP version: ≥ 7.4 recommended
- [ ] Check that cron jobs are already running (5min inbound, 2min outbound)
- [ ] Log in as Admin to verify admin privileges

---

## Deploy (3 minutes)

```bash
# 1. Copy new files
cp admin/email_management.php /var/www/noc/admin/
cp modules/email/imap_service.php /var/www/noc/modules/email/
cp modules/email/email_processor.php /var/www/noc/modules/email/
cp services/email_parser_service.php /var/www/noc/services/

# 2. Ensure docs are in place
cp -r docs/* /var/www/noc/docs/

# 3. Validate syntax locally
php -l admin/email_management.php
php -l modules/email/imap_service.php
php -l modules/email/email_processor.php
php -l services/email_parser_service.php

# All should say: No syntax errors detected
```

---

## Post-Deploy Verification (10 minutes)

### Step 1 — Dashboard loads
Open: `http://localhost/noc/admin/email_management.php`
- Should see 4 stats cards (Pending, Sent, Failed, Today's)
- Account panels listed
- No PHP errors

### Step 2 — Test connectivity
For each account:
- **Test SMTP** → green ✓ SMTP OK
- **Test IMAP** → green ✓ IMAP OK

If red ✗:
- SMTP error "greeting" → change `smtp_encryption` to `ssl` for port 465
- IMAP error → verify host/port, firewall

### Step 3 — Safe import test
Click **Import Last 10** on ONE account.
Wait for flash: "✓ Imported (Account #X): N msgs, Y new, Z replies."

Then:
```
# Verify inbound log
SELECT COUNT(*) FROM email_inbox_log WHERE received_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE);

# Should show N (or fewer if duplicates/ignored)
```

### Step 4 — Check filter/search
- Type partial email in search box → press Apply
- Change Status dropdown → Reapply
- Change Per Page to 25 → verify count label updates
- Click page 2 → different accounts shown
- Click Clear (×) → resets to default

### Step 5 — Verify auto-email template
1. Create ticket manually via UI
2. Check `email_outbox_log` for that ticket_id
3. Should see row with status `pending` (soon `sent` by cron)
4. Body contains ticket description ✅

### Step 6 — Test parser guardrail
Send test email from external address:
- Subject: `Hello`
- Body: `This is a test`
→ Should be **ignored**. Check `email_inbox_log.processing_result = 'ignored'` and `ignored_reason` explains which guardrail failed.

---

## Monitor First Hour

| Metric | Expected |
|--------|----------|
| Outbox Pending | Decreases steadily (cron processing) |
| Failed | No new failures (unless real issue) |
| Last Checked (each account) | Updates to ~now within 5 minutes |
| No PHP errors in `error_log` | Clean |

If Pending grows without bound → outbox cron not running:
```bash
# Test manually
php /var/www/noc/cron/process_email_outbox.php
# Should process up to 25 emails
```

---

## Rollback (if needed)

```bash
# Stop at last known good
cp admin/email_management.php.bak admin/email_management.php
cp modules/email/imap_service.php.bak modules/email/imap_service.php
cp modules/email/email_processor.php.bak modules/email/email_processor.php
cp services/email_parser_service.php.bak services/email_parser_service.php

# Clear cache if opcache enabled
# Restart PHP-FPM or Apache
```

---

## Known Limitations (Design Decisions)

1. **Manual import capped at 10** — can't override from UI (only code). Prevents accidents.
2. **Bulk retry capped at 10** — prevents flood if dozens stuck; retry in batches.
3. **UID never decreases** — intentional; to reset baseline, manually `UPDATE email_accounts SET last_seen_uid = 0 WHERE id = X`.
4. **Parser ignores emails without ID or issue** — intentional anti-spam; adjust guardrails if needed.
5. **Passwords stored plaintext** — known limitation; future enhancement: encryption at rest.

---

## Support Documentation

| Need | Read |
|------|------|
| Daily admin operations | `docs/ADMIN_QUICK_REFERENCE.md` |
| Vendor ID formats & parser config | `docs/PARSER_CONFIGURATION_GUIDE.md` |
| Real-world examples & edge cases | `docs/PARSER_EXAMPLES.md` |
| System architecture | `docs/SYSTEM_OVERVIEW.md` |
| Full technical reference | `docs/COMPLETE_SYSTEM_DOCUMENTATION.md` |
| What changed in this release | `docs/FULL_IMPLEMENTATION_SUMMARY.md` |
| All docs index | `docs/INDEX.md` |

---

## Success Indicators

After 24 hours, you should see:

- ✅ Outbox Pending oscillating 0–50 (normal flow)
- ✅ Failed count < 5
- ✅ All accounts Last Checked within past hour
- ✅ Tickets created from valid emails with `external_ticket_id` populated
- ✅ ~0–5 `ignored` emails per day (normal spam filtering)
- ✅ Auto-emails appearing in outbox → sent → arriving in inboxes

---

**Deployment time:** ~15 minutes total  
**Risk level:** Low (no breaking changes, all existing features preserved)  
**Rollback:** Instant (restore 4 files)  

**Questions?** See `docs/ADMIN_QUICK_REFERENCE.md` → Emergency Procedures.
