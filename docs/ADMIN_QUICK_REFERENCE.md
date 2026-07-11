# Email System — Admin Quick Reference

**Dashboard:** `admin/email_management.php` — Per-account panels, safe import (10), search/filter, pagination  
**Logs:** `emails/logs.php` — Full inbound/outbound history with reply/compose  
**Accounts:** `emails/accounts.php` — Add/edit SMTP/IMAP credentials  

---

## Dashboard Quick Tour

### Stats Bar (Top 4 Cards — hover for tooltips)
| Card | Tooltip Meaning |
|------|-----------------|
| **Outbox Pending** | Emails queued, not yet sent |
| **Sent Total** | Lifetime successful deliveries |
| **Failed** | Emails that bounced (click number → see details in logs) |
| **Today's Tickets** | All-source tickets created today |

### Global Actions
| Button | Action | Safety Limit |
|--------|--------|-------------|
| **Import All Accounts (Last 10 each)** | Polls ALL active mailboxes | 10 emails per account only |
| **Retry All Failed (Max 10)** | Resends 10 most recent failed | Caps 10 to prevent flood |
| **Import CLI / Outbox CLI** | Links to cron scripts (open in new tab) | — |

### Per-Account Panel (Expandable)
Click any account row → reveals:
- **Summary line:** Email + From name, Active badge, Inbound/Outbound counts, recent failed (7d)
- **Details grid:**
  - IMAP Config (host, port, encryption, username, status) — ? tooltip explains usage
  - SMTP Config (host, port, encryption, from name)
  - Activity Stats (totals + 7-day failures)
  - Import Status (baseline date, last poll, last UID processed)
- **Action buttons:**
  - ✅ Test SMTP — socket check only
  - ✅ Test IMAP — socket check only
  - ✅ Import Last 10 — reads only 10 newest emails (SAFE)
  - Edit Account → opens accounts.php
  - View Logs → filtered view of emails from this account

---

## Daily Checklist

- [ ] **Dashboard** → Outbox Pending not spiking unexpectedly
- [ ] Failed ≤ 5; persistent failures need investigation
- [ ] All accounts show "Last Checked" within last hour (cron running?)
- [ ] No red "failed (7d)" warnings next to accounts
- [ ] Run **Test SMTP** on any account with errors (should show green ✓)
- [ ] Check **Today's Tickets** vs expected volume

---

## Common Actions

### 1. Test Account Connectivity
**Dashboard → Expand account → Test SMTP / Test IMAP**

| Result | Diagnosis |
|--------|-----------|
| ✓ SMTP OK: `mail.host.com:465 (ssl)` | Outbound OK |
| ✗ SMTP failed: "Connection refused" | Wrong host/port or firewall blocks |
| ✗ SMTP failed: "Authentication failed" | Wrong password → edit account |
| ✓ IMAP OK | Inbound OK |
| ✗ IMAP failed | Host/port wrong or blocked |

👉 **Tip:** Both tests are socket-only (no email sent/received)

---

### 2. Manually Import Recent Emails (Safe)
**Dashboard → Expand account → Import Last 10**

- Reads **only the 10 newest emails** from that mailbox (by UID)
- Creates new tickets OR links replies to existing tickets
- Safe to run anytime — will never re-process old messages

**Bulk import:** Global Actions → "Import All Accounts (Last 10 each)"  
(10 per account capped → safe even with many accounts)

---

### 3. Retry Failed Outbound Emails
- **Per-email:** `emails/logs.php` → failed tab → "Retry" button
- **Bulk:** Dashboard → "Retry All Failed (Max 10)"

**Note:** Only retry after fixing root cause:
- Authentication error → update credentials first
- 550/5xx → fix recipient or content, then retry
- Network timeout → safe to retry directly

---

### 4. Diagnose High Failure Rate

1. **Logs page** (`emails/logs.php`) → filter `status=failed`
2. Sort by `ID DESC` → latest errors first
3. Group by `error_message`:

| Error Pattern | Fix |
|---------------|-----|
| "SMTP greeting failed" | Update `smtp_encryption` to `ssl` (port 465) |
| "Authentication failed" | Wrong password → edit account → re-enter |
| "Certificate verification failed" | Switch to port 587 (TLS) or add CA bundle |
| "Permission denied" | Hosting firewall blocks outbound → request port open |
| "Connection timed out" | Server unreachable → check host/port/VPC |

4. After fix → retry affected rows

---

### 5. Verify Auto-Email Templates
Tickets → open recent ticket → **Outgoing** section should show:

| Event | Expected Email |
|-------|---------------|
| Ticket created (manual or email) | "Support Request Received" + description |
| Status → In-Progress | "Status Update: In Progress" + description |
| Status → Closed | "Support Request Resolved" + description |

**If missing:**
- Check `tickets.customer_email` is populated (required)
- Query `email_outbox_log` for that `ticket_id` → any `failed` rows?
- Retry or run outbox cron manually

---

### 6. Check IMAP Import Health
**Per account on Dashboard:**

| Metric | Healthy |
|--------|---------|
| Last Checked | ≤ 1 hour ago (if cron running 5min interval) |
| Last UID | > 0 (baseline initialized) |
| Inbound count | Gradually increasing over days |
| Recent failed (7d) | 0 or single digits |

If "Never" → cron not running OR IMAP credentials wrong → Test IMAP → fix → Import once to baseline.

---

## Emergency Procedures

### Stop All Outbound Mail
```sql
-- Disable every SMTP account (is_active → 0)
UPDATE email_accounts SET is_active = 0 WHERE id > 0;

-- OR: clear pending queue (will not send until re-queued)
UPDATE email_outbox_log SET status='failed', error_message='Paused' WHERE status='pending';
```

### Clear Stuck Pending (> 1 hour)
```sql
UPDATE email_outbox_log 
SET status='failed', error_message='Stuck > 1h' 
WHERE status='pending' 
  AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```
Then retry after root-cause fix.

### Replay All Failed (after credentials fixed)
```sql
UPDATE email_outbox_log 
SET status='pending', error_message=NULL 
WHERE status='failed';
```
Then run "Retry All Failed" or wait for outbox cron.

### Force Import From CLI (single account)
```bash
# All accounts (import_imap_tickets.php already uses limit=10 safe)
php cron/import_imap_tickets.php

# One specific account (by DB ID):
php -r "
require 'config/db.php';
email_imap_import_messages(\$pdo, 10, 3);  // account_id=3 only, limit 10
"
```

### Peek at Raw Email (debug)
```sql
SELECT subject, body, raw_message 
FROM email_inbox_log 
WHERE id = ?;
-- Copy raw_message into .eml file; open with Thunderbird/Outlook
```

---

## Parser Guardrails — Understanding "Ignored" Emails

The system **automatically skips** emails that don't meet criteria:

| Reason | Trigger | Fix |
|--------|---------|-----|
| "no external ticket ID and issue is empty" | Subject is blank/generic AND extracted issue is empty | Review subject; adjust parser patterns if valid vendor format missing |
| "email does not meet ticket creation criteria" | Subject blacklisted OR body < 20 chars OR domain not whitelisted | Add sender domain to whitelist or ensure email has detailed body |
| "no external ticket ID and issue is empty" (second check) | Double-validation before INSERT | Same as above |

**View ignored count:**  
Dashboard → per-account "Imported Last 10" summary shows `ignored` count in result message.

---

## Parser — Vendor Ticket ID Formats

The parser auto-detects **any** of these in subject or body:

```
TT-123456          → standard vendor prefix
TT<452457823>      → angle brackets
[TT-123456]        → square brackets
(TCK-987654)       → parentheses
Ticket LM-20260429-01 → internal NOC format
Case #TKT-98765:    → keyword + #
Ref: ABC-12345      → "Ref:" prefix
ticket number: 123456789 → 9-digit numeric
```

All matched IDs are normalized to uppercase (no brackets) and stored in `email_inbox_log.external_ticket_id`.

**Customization:** Edit `services/email_parser_service.php` → `$patterns[]` array (lines 67–85) to add new formats.

---

## Search / Filter / Pagination (Dashboard)

**Filters available on `admin/email_management.php`:**
- **Search:** Email or From Name (partial match)
- **Status:** All / Active / Inactive
- **Per Page:** 10 / 25 / 50 / 100
- **Pagination:** Full page navigation (First / Prev / 1 2 3 / Next / Last)

Use filters to quickly find accounts with issues (e.g., search "failed" to see those with recent failures).

---

## Monthly Maintenance

- [ ] Purge `email_inbox_log` & `email_outbox_log` entries > 90 days ( GDPR / storage)
  ```sql
  DELETE FROM email_inbox_log WHERE received_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
  DELETE FROM email_outbox_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
  ```
- [ ] Archive closed tickets older than 6 months (optional)
- [ ] Review `ignored` emails in `email_inbox_log` — adjust parser if valid IDs being skipped
- [ ] Verify cron jobs: `import_imap_tickets.php` (5min) and `process_email_outbox.php` (2min)

---

## Important File Reference

| Purpose | File | Entry Point |
|---------|------|-------------|
| IMAP fetch | `modules/email/imap_service.php` | `email_imap_import_messages()` |
| SMTP send | `modules/email/smtp_service.php` | `email_smtp_process_outbox_item()` |
| Parser logic | `services/email_parser_service.php` | `email_parser_detect_external_ticket_id()` |
| Ticket auto-emails | `modules/tickets/ticket_service.php` | `ticket_service_handle_ticket_created()` |
| Dashboard UI | `admin/email_management.php` | All per-account actions |
| Inbound cron | `cron/import_imap_tickets.php` | CLI: `php cron/import_imap_tickets.php` |
| Outbound cron | `cron/process_email_outbox.php` | CLI: `php cron/process_email_outbox.php` |

---

## SQL Snippets

### A. Pending queue size by account
```sql
SELECT from_email, COUNT(*) as pending_count
FROM email_outbox_log
WHERE status='pending'
GROUP BY from_email
ORDER BY pending_count DESC;
```

### B. Last import run per account
```sql
SELECT email, last_checked_at, last_seen_uid
FROM email_accounts
ORDER BY last_checked_at DESC;
```

### C. Recently ignored emails (check parser guardrails)
```sql
SELECT id, from_email, subject, ignored_reason, received_at
FROM email_inbox_log
WHERE processing_result='ignored'
ORDER BY received_at DESC LIMIT 20;
```

### D. Auto-emails sent today
```sql
SELECT COUNT(*) as sent_today
FROM email_outbox_log
WHERE status='sent'
  AND DATE(created_at) = CURDATE();
```

---

## Security & Reliability

- **CSRF:** All POST actions require token
- **Rate limiting:** Manual import capped at 10 per account
- **UID tracking:** IMAP uses UID (never decreases) → no duplicates
- **Transaction safety:** `email_processor_process_message()` uses DB transaction
- **Notifications:** Admin users get notified of ignored emails and failures

---

**Maintained by:** Engineering Team  
**Last reviewed:** 2026-04-29  
**Next review:** 2026-05-29 (monthly)

