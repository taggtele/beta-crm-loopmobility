# Email System Management Dashboard

**Path:** `/admin/email_management.php`  
**Access:** Admin only (role = 'Admin')

---

## Overview

A single-page dashboard giving complete visibility into the email-ticketing system — SMTP/IMAP health, outbox queue, inbound import stats, and quick actions to fix issues.

---

## Layout

### 1. Outbox Queue Card
Shows delivery status metrics:
- **Pending** — Emails queued but not yet sent
- **Sent** — Successfully delivered
- **Failed** — Delivery failed (error shown)
- **Total** — All-time count

**Recent Failures Table:**
- Shows last 5 failed emails with To, Subject, Error message
- **Retry** button for individual emails (only for non-greeting errors)
- **Retry All Failed** — resends up to 10 most recent failed emails
- **View All Failed** — opens email logs filtered to failed

### 2. Email Accounts Card
For each configured account (SMTP+IMAP):

| Field | Meaning |
|-------|---------|
| Email | Account identity |
| From Name | Display name in outgoing emails |
| Status | Active / Inactive |
| SMTP | `host:port` + encryption + username |
| IMAP | `host:port` + encryption (if configured) |
| Last Checked | When cron last polled this account |
| Last Seen UID | Highest IMAP UID processed (prevents re-import) |

**Quick Actions per account:**
- **Test SMTP** — Opens SSL/TLS socket to SMTP server, verifies 220 greeting
- **Test IMAP** — Opens socket to IMAP server, verifies connectivity
- **Edit** — Links to `emails/accounts.php?id=X`

### 3. Today's Activity Card
Counts for current date:
- Outgoing emails sent
- Incoming emails imported
- Tickets created

### 4. Quick Actions
- **Run IMAP Import Now** — Forces `email_imap_import_messages()` to run immediately (normally cron-driven)
- **Manage Accounts** — Add/edit email accounts
- **All Email Logs** — Full outgoing/incoming view
- **Process Outbox (CLI)** — Manual trigger for SMTP sending
- **Review Failed Emails** — Filtered view

---

## Common Issues & Fixes

### Issue: "SMTP greeting failed"
**Cause:** Port-encryption mismatch (TLS on port 465) or server down  
**Dashboard check:** SMTP test → error details  
**Fix:** Update `smtp_encryption` to `ssl` for port 465, or change port to 587 for TLS

### Issue: "Unable to connect to SMTP server: Permission denied"
**Cause:** Outbound port blocked by firewall/hosting  
**Dashboard check:** SMTP test fails immediately  
**Fix:** Ask hosting to open outbound ports 465 (SSL) or 587 (TLS)

### Issue: IMAP not fetching
**Check:** Last Seen UID — if "N/A", first-time import was skipped  
**Fix:** Manually set `import_cutoff_at` to a past date, then run import

### Issue: High failed count
**Diagnosis:** Open failures table → read error_message  
**Common errors:**
- `Authentication failed` → wrong password in account
- `Certificate verification failed` → disable SSL verify or add CA bundle
- `550 Sender address rejected` → SMTP server doesn't allow this From address

**Quick fix:** Edit account → correct credentials → retry failed

---

## Cron Jobs Integration

The dashboard complements cron:

| Cron Job | What it does | Dashboard equivalent |
|----------|-------------|---------------------|
| `cron/import_imap_tickets.php` | Fetches new inbound emails via IMAP, creates/replies to tickets | **"Run IMAP Import Now"** button |
| `cron/process_email_outbox.php` | Sends queued outgoing emails via SMTP | **"Retry Failed"** (partial), or run CLI |

**Recommended cron entries:**
```bash
# Every 5 minutes — import inbound
*/5 * * * * php /path/to/noc/cron/import_imap_tickets.php

# Every 2 minutes — send outgoing
*/2 * * * * php /path/to/noc/cron/process_email_outbox.php
```

Dashboard lets you **manually trigger** these if needed.

---

## Data Flow Summary

```
┌─────────────────┐
│  Email Account  │  ← Configured in emails/accounts.php
│  (SMTP + IMAP)  │
└────────┬────────┘
         │
    [CRON: import_imap_tickets.php]
         │
         ├──► IMAP Connect → fetch new UIDs
         │              ↓ parse headers/body
         │              ↓ detect external_ticket_id
         │              ↓ lookup existing ticket
         │              ├─► EXISTS → reply (email_inbox_log)
         │              └─► NOT    → create ticket (email_inbox_log + tickets)
         │
    [CRON: process_email_outbox.php]
         │
         └──► SMTP Connect → send queued → mark sent/failed
```

---

## Technical Reference

### Tables Used
- `email_accounts` — account config (SMTP/IMAP credentials)
- `email_outbox_log` — outgoing queue (status: pending/sent/failed)
- `email_inbox_log` — inbound raw messages (ticket_id foreign key)
- `tickets` — main ticket data (`external_ticket_id` matches inbound email references)

### Key Functions
- `email_imap_import_messages($pdo)` — bulk import all accounts
- `email_smtp_process_outbox($pdo, $limit)` — send batch
- `email_smtp_process_outbox_item($pdo, $id)` — retry single
- `email_parser_detect_external_ticket_id()` — extracts "LM-20260429-01" etc. from subject/body

### External Ticket ID Parser Patterns
Located in `services/email_parser_service.php`:
1. `TKT-XXXXX` format (strict)
2. `Ticket #ID`, `Case #ID`, `Ref #ID` variations
3. `Ticket: ID`, `External ID: ID` patterns

Customize these if your business uses a different format.

---

## Pro Tips

1. **Monitor the "Failed" count** — persistent failures mean bad SMTP credentials or blocked ports.
2. **"Last Seen UID = N/A"** means that account's IMAP import has never successfully connected. Run a test IMAP, check password/host/port.
3. **SMTP encryption must match port:**
   - Port 465 → `ssl` (implicit SSL)
   - Port 587 → `tls` (STARTTLS)
   - Port 25 → `none` or `tls`
4. **Retry All** only attempts the 10 most recent failures. For older ones, manually retry or re-queue via SQL:
   ```sql
   UPDATE email_outbox_log SET status='pending', error_message=NULL WHERE id IN (...ids);
   ```
5. **"Force IMAP Import"** is safe to run anytime — it only fetches messages with UID > last_seen_uid.

---
