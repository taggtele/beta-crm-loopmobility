# Email Management Dashboard — Professional Guide

**New:** `admin/email_management.php` (Admin only)

---

## 🎯 Purpose

A single, unified dashboard for admins to:
- View system-wide email health at a glance
- Test connectivity per account (SMTP/IMAP)
- Safely import emails (limited to 10 newest per account)
- Retry failed deliveries
- Understand account configuration

---

## 📊 Dashboard Layout

### Top Bar
- **Page title** + "Refresh" button to reload data

### Stats Row (4 cards)
| Card | Shows | Color |
|------|-------|-------|
| Outbox Pending | Queued emails awaiting send | Amber |
| Sent Total | Lifetime delivered emails | Green |
| Failed | emails with SMTP errors | Red |
| Today's Tickets | Tickets created today | Blue |

### Global Actions Card
- **Import All Accounts (Last 10 each)** — Safe bulk import
- **Retry All Failed (Max 10)** — Resend recent failures
- CLI quick links: Import CLI, Outbox CLI
- Links: View All Logs, Manage Accounts

### Email Accounts Sections (expandable cards)

Each account shows:

**Summary Row (always visible):**
- Envelope icon + email address
- From name (if set)
- Status badge: Active / Inactive
- Meta: Inbound count | Outbound count | Failed last 7d (if any, in red)
- Stats: Last Checked (timestamp), Last UID (number)
- Chevron to expand

**Expanded Details (click to open):**
1. **IMAP Configuration** — host, port, encryption, username, active status
2. **SMTP Configuration** — host, port, encryption, from name
3. **Activity Stats** — total inbound, outbound, recent failed
4. **Import Status** — baseline date, last poll, last UID
5. **Action Buttons:**
   - Test SMTP — quick socket test
   - Test IMAP — quick socket test
   - Import Last 10 — **safe fetch, only 10 newest emails**
   - Edit Account — opens accounts.php
   - View Logs — filters logs by this email

**Bottom Help Section** — explains safe import, ticket linking, auto-emails, UID tracking

---

## 🔒 Safety Features

### 1. Limited Manual Import
- **Import Last 10** button: reads at most 10 newest messages from that mailbox's IMAP server.
- **Import All Accounts**: reads at most 10 newest per account.
- Prevents accidental bulk import of thousands of old emails that would flood tickets.

### 2. Confirmation Dialogs
- "Retry all failed?" — requires confirm
- "Import all accounts?" — explains limit
- "Import last 10 from this account?" — clear scope

### 3. Safe Retry Logic
- Retry limited to last 10 failed emails.
- Per-email retry available in failure table.

### 4. UID-based Incremental Import
- `last_seen_uid` is always stored after each poll.
- Never re-processes same message.
- First run baseline: highest UID captured, all prior messages skipped.

---

## 🛠️ How to Use

### A. Test an Account's Connectivity

1. Expand the account panel (click anywhere on the row)
2. Click **Test SMTP** → green check or red error appears at top
3. Click **Test IMAP** → same

**Success means:**  
- Port reachable from your web server
- Encryption (SSL/TLS) handshake works
- Server responded with 220 greeting (SMTP) or * OK (IMAP)

**Failure means:**  
- Wrong host/port
- Encryption mismatch (use 465+ssl or 587+tls)
- Firewall blocks outbound
- Password not needed for these tests (they only connect, not login)

### B. Manually Import Recent Emails

1. Ensure account is **Active**
2. Click **Import Last 10**
3. Confirm dialog
4. Wait ~5–10 seconds
5. Top-right flash shows summary: "Imported X msgs, Y new, Z replies"
6. Check `emails/logs.php` → Incoming section to see new messages
7. Check `tickets/list.php` → new tickets if any

**What 'new' means:**  
Email had no matching `external_ticket_id` → new ticket created.

**What 'replies' means:**  
Email referenced existing ticket → added as reply (no new ticket).

### C. Retry Failed Emails

1. In **Outbox Queue** card (top), find "Recent Failures" table
2. Individual **Retry** button on each row → attempts immediate resend
3. Or **Retry All Failed** at bottom → retries up to 10 most recent

**After retry:**
- Success: row disappears from failures, Outbox Pending decrements, Sent increments
- Still fails: remains in failed list; check `error_message` for root cause

### D. Diagnose Issues

**Problem:** "Failed" count high, errors show "SMTP greeting failed"
→ Click account's **Test SMTP**, confirm error. Likely cause: port-encryption mismatch.
→ Edit account → ensure `smtp_port=465` uses `smtp_encryption=ssl`; `smtp_port=587` uses `tls`.

**Problem:** No inbound emails for days
→ Check account's **Last Checked** timestamp. If old, cron may not be running.
→ Run `Import Last 10` manually. If it succeeds, cron likely needs enabling.

**Problem:** "Last UID" shows N/A
→ Account never successfully imported. Test IMAP → fix → manually import once → baseline set.

**Problem:** Duplicate tickets from same email
→ Check `email_inbox_log` for duplicate `message_id`. System dedups automatically; duplicates indicate changed Message-ID headers.

---

## 📈 Metrics Explained

| Metric | Definition |
|--------|------------|
| **Inbound** | Count of received emails for this account (all time) |
| **Outbound** | Count of sent emails from this account (all time) |
| **Failed (7d)** | Emails that failed delivery in last 7 days |
| **Last Checked** | When cron last polled this mailbox |
| **Last UID** | Highest IMAP message UID processed; prevents re-import |
| **Baseline Set** | First successful poll timestamp; all messages before skipped |

---

## 🔧 Technical Implementation

### Modified Core Functions

**`modules/email/imap_service.php`:**
- `email_imap_import_messages($pdo, int $limitPerAccount = 0, int $accountId = 0): array`
  - Added `$accountId` parameter (0 = all accounts)
  - Added `$limitPerAccount` safety cap (0 = unlimited, but we always pass 10 from UI)
  - Now tracks `replied` count separately
  - Per-account filtering: `if ($accountId > 0 && (int)$account['id'] !== $accountId) continue;`

**`admin/email_management_v2.php`** (now `email_management.php`):
- Handles all POST actions
- Renders expandable account panels with live stats
- Calls `email_imap_import_messages($pdo, 10, $accountId)` for single-account safe import
- Tooltip CSS integrated

**`includes/sidebar.php`:**
- Added "Email Management" link visible to Admins only

---

## 🧪 Testing Checklist

- [x] Page loads for Admin, forbidden for Agent
- [x] Overall stats display correctly
- [x] Expand/collapse account panels works
- [x] Test SMTP returns immediate success/failure
- [x] Test IMAP returns immediate success/failure
- [x] Import Last 10 fetches only 10 messages max
- [x] Import All respects per-account 10 limit
- [x] Retry All Failed retries max 10
- [x] Individual retry button resends single email
- [x] Tooltips show on hover with dark background
- [x] All actions display flash message at top-right
- [x] Responsive: panels stack on mobile

---

## ⚡ Recommended Cron Setup

Even with manual dashboard, keep cron running:

```bash
# Import inbound: every 5 minutes (reads last 10 automatically due to UID)
*/5 * * * * php /path/to/noc/cron/import_imap_tickets.php

# Send outbound: every 2 minutes
*/2 * * * * php /path/to/noc/cron/process_email_outbox.php
```

The manual dashboard actions are complements, not replacements.

---

## 📚 Related Documents

- `docs/SYSTEM_OVERVIEW.md` — Full architecture
- `docs/EMAIL_MANAGEMENT_DASHBOARD.md` — Earlier design doc
- `cron/import_imap_tickets.php` — Inbound cron source
- `cron/process_email_outbox.php` — Outbound cron source
- `modules/email/imap_service.php` — Import engine
- `modules/email/smtp_service.php` — Send engine

---

## 🚨 Known Limitations & Future Improvements

1. **Import limit is hard-coded to 10** — Could become configurable per-account.
2. **No pagination** in account list — fine for small deployments (< 20 accounts).
3. **Single-account import** processes all active accounts but filters result count; engine still loops all accounts. Could short-circuit.
4. **No real-time push** — dashboard polls on reload. Could add WebSocket for live updates.
5. **Tooltips** are pure CSS — fine for accessibility, but could use JS for mobile.

---

## ✅ Summary

You now have a **professional, responsive, safe** email management interface where:

- ✅ Every account displays full config + health in one pane
- ✅ One-click connectivity tests (SMTP/IMAP)
- ✅ Safe manual import limited to 10 newest emails (prevents ticket flood)
- ✅ Per-account control with expandable panels
- ✅ Clear tooltips explain each field
- ✅ Retry single or batch failed emails
- ✅ All actions confirm before running
- ✅ No breaking changes to existing cron or flows
- ✅ Clean UI with icons, hover effects, mobile-friendly

**Access:** `http://localhost/noc/admin/email_management.php` (Admin only)
