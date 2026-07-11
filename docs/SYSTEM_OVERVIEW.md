# NOC Email Ticketing System — Complete Technical Overview

**Version:** 1.0  
**Last Updated:** 2026-04-29  

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                      EMAIL-TICKETING SYSTEM                          │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐         ┌──────────────────┐                  │
│  │  IMAP Mailboxes │◄───────►│  Inbound Import  │                  │
│  │ (Multiple)      │ cron    │ (email_imap_     │                  │
│  └─────────────────┘         │  import_messages)│                  │
│         │                     └─────────┬────────┘                  │
│         │                               │                          │
│         │                       Parse & Match                     │
│         │                               │                          │
│         │                  ┌────────────▼────────────┐            │
│         │                  │ email_processor_        │            │
│         │                  │ process_message()       │            │
│         │                  └────────────┬────────────┘            │
│         │                               │                          │
│         │               ┌───────────────┴───────────────┐          │
│         │               │                               │          │
│         ▼               ▼                               ▼          │
│  ┌────────────────┐ ┌────────────────┐         ┌───────────────┐ │
│  │ email_inbox_   │ │   TICKETS      │         │ email_outbox_ │ │
│  │   log          │ │   table        │         │   log         │ │
│  │(raw msgs only) │ │(ticket data)   │         │(queue)        │ │
│  └────────────────┘ └────────────────┘         └───────────────┘ │
│                                                        │          │
│                                                        ▼          │
│                                          ┌─────────────────────┐  │
│                                          │  SMTP Sender        │  │
│                                          │  (email_smtp_       │  │
│                                          │   process_outbox)   │  │
│                                          └─────────────────────┘  │
│                                                        │          │
│                                                        ▼          │
│                                          ┌─────────────────────┐  │
│                                          │  External Mail      │  │
│                                          │  (Gmail, etc.)      │  │
│                                          └─────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Core Tables

#### `email_accounts`
Stores SMTP+IMAP configuration per mailbox.

| Column | Purpose |
|--------|---------|
| `id` | Primary key |
| `email` | Account email address |
| `from_name` | Display name in outgoing emails |
| `username` | SMTP/IMAP username (often same as email) |
| `password` | Encrypted password |
| `imap_host`, `imap_port`, `encryption` | Incoming settings |
| `smtp_host`, `smtp_port`, `smtp_encryption` | Outgoing settings |
| `is_active` | Enable/disable account |
| `last_seen_uid` | Highest IMAP UID processed (incremental import) |
| `import_cutoff_at` | First-run baseline timestamp |
| `last_checked_at` | Last poll timestamp |

#### `email_inbox_log`
Inbound email archive. **Never** sends from here — just storage.

| Column | Purpose |
|--------|---------|
| `id` | PK |
| `message_id` | Global unique ID (deduplication) |
| `from_email` | Sender |
| `subject` | Email subject |
| `body` | Plain-text content |
| `raw_message` | Full RFC822 source (optional) |
| `received_at` | When email was received (from headers) |
| `processing_result` | `pending` | `created` (new ticket) | `replied` (linked) | `ignored` |
| `ticket_id` | FK to tickets (if matched/created) |
| `external_ticket_id` | Detected reference (e.g. `LM-20260429-01`) |

#### `email_outbox_log`
Outgoing email queue.

| Column | Purpose |
|--------|---------|
| `id` | PK |
| `email_account_id` | Which account sends this |
| `from_email` | Resolved From address |
| `to_email` | Recipient |
| `cc_email` | CSV of CC addresses |
| `subject` | Email subject |
| `body` | Email body |
| `status` | `pending` | `sent` | `failed` |
| `error_message` | SMTP error if failed |
| `sent_at` | Delivery timestamp |
| `created_at` | Queue timestamp |
| `ticket_id` | FK to linked ticket |

#### `tickets`
Main ticket data.

| Column | Purpose |
|--------|---------|
| `ticket_id` | Auto-increment internal ID |
| `external_ticket_id` | Customer-facing ID (e.g. `LM-20260429-01`) |
| `issue` | Short subject |
| `description` | Full details |
| `customer`, `customer_email`, `country` | Contact info |
| `status` | `Open` | `In-Progress` | `Closed` |
| `priority` | `Low` | `Medium` | `High` |
| `assign_to` | User ID of assignee |
| `created_by` | User ID of creator |
| `mail_message_id`, `mail_thread_id` | For email threading |
| `source` | `email`, `manual`, etc. |

---

## Data Flow

### 1. Inbound: Email → Ticket

```
IMAP Poll (cron)
    ↓
Fetch new UIDs (last_seen_uid+1 .. max)
    ↓
RFC822 → parse_message()
    ├─ headers: from, subject, message-id
    └─ body: extract plain text
    ↓
Detect external_ticket_id (parser)
    ↓
Lookup: SELECT ticket_id FROM tickets WHERE external_ticket_id = :detected
    ↓
    ├─ FOUND      → REPLY path
    │   ├─ Update email_inbox_log (ticket_id, processing_result='replied')
    │   ├─ Add ticket_log entry
    │   ├─ Re-open if was Closed
    │   └─ Send notification to admins
    │
    └─ NOT FOUND   → NEW TICKET path
        ├─ Extract issue (subject or first non-greeting body line)
        ├─ INSERT INTO tickets (issue=extracted, description=body, external_ticket_id=detected, ...)
        ├─ INSERT INTO email_inbox_log (ticket_id=new)
        ├─ Log: "Ticket LM-... created"
        ├─ Notify admins
        └─ Queue customer email (confirmation) IF customer_email provided
```

**Key function:** `email_processor_process_message()` (`modules/email/email_processor.php`)

### 2. Outbound: Ticket → Email

Automatic triggers:

| Trigger | Function | Email To | Includes Description? |
|---------|----------|----------|----------------------|
| Ticket created | `ticket_service_queue_created_email()` | Customer | ✅ Yes |
| Status → In-Progress | `ticket_service_queue_in_progress_email()` | Customer | ✅ Yes |
| Status → Closed | `ticket_service_queue_closed_email()` | Customer | ✅ Yes |
| Manual compose via UI | `email_smtp_queue()` → `email_smtp_send_message()` | Any | As typed |

**Automatic sending:** Cron `process_email_outbox.php` → processes pending → SMTP → marks sent.

### 3. Manual Compose with Ticket Linking

User clicks "Send Mail" → selects From account → enters To, CC, Subject, Body, **chooses Related Ticket** (optional) → submits.

- If `ticket_id` provided → email stored in `email_outbox_log.ticket_id`
- Ticket detail page shows this outgoing email in its thread

---

## Email Management Dashboard (`admin/email_management.php`)

### Purpose
Single-pane monitoring for system admin: health checks, queue inspection, manual triggers, per-account controls.

### Features

1. **Outbox Stats Bar** (4 cards, tooltip-enabled)
   - Pending, Sent, Failed, Today's Tickets counts
   - Hover any card for tooltip explanation

2. **Global Actions**
   - Import All Accounts (Last 10 each) — safe bulk import across all active accounts
   - Retry All Failed (Max 10) — batch resend limited to 10 most recent
   - Quick CLI links: Import CLI, Outbox CLI (open in new tab)

3. **Per-Account Expandable Panels**
   Each account row expands to reveal:
   - **Summary:** Email + From name, Active badge, Inbound/Outbound counts, recent failed (7d), Last Checked timestamp, Last UID
   - **Details grid:**
     - IMAP Configuration + tooltip explanation
     - SMTP Configuration + tooltip explanation
     - Activity Stats (inbound/outbound totals + 7-day failures)
     - Import Status (baseline date, last poll timestamp, highest UID processed + tooltip)
   - **Action buttons:**
     - ✅ Test SMTP — socket connectivity test
     - ✅ Test IMAP — socket connectivity test
     - ✅ Import Last 10 — reads only 10 newest emails from THIS account (SAFE)
     - Edit Account → opens `emails/accounts.php`
     - View Logs → `emails/logs.php` filtered by this email

4. **Search / Filter / Pagination**
   - Search by email or from name (partial match)
   - Filter by status: All / Active / Inactive
   - Per-page selector: 10 / 25 / 50 / 100
   - Pagination controls: First / Prev / Page numbers / Next / Last
   - Showing X–Y of Z accounts label

5. **Help Section**
   - Six info cards explaining: Safe Import, Ticket Linking, Auto-Emails, UID Tracking, Guardrails, Parser Configuration

### Access
- Visible only to `role = 'Admin'`
- Sidebar: "Email Management" ⚡ link

### Safety Guarantees
| Action | Limit | Purpose |
|--------|-------|---------|
| Manual import (single account) | 10 emails | Prevent flood of old messages |
| Bulk import (all accounts) | 10 each | Same safe cap across all |
| Retry failed | 10 emails | Avoid overwhelming SMTP |
| UID-based import | Incremental since last_seen_uid | No duplicates ever |

---

## Parser Logic & Guardrails

### Ticket ID Extraction

**Supported vendor formats** (`services/email_parser_service.php`):
```
LM-YYYYMMDD-NN    (internal NOC format)
TT-123456         (vendor prefix)
TT<123456>        (angled brackets)
[TT-123456]       (square brackets)
(TCK-987654)      (parentheses)
Ticket #TKT-98765 (keyword + hash)
Ref: ABC-12345    (keyword colon)
ticket 123456789  (numeric, 6+ digits)
```

**Prioritization:** First match wins. Prefers alphanumeric IDs over pure numeric when multiple candidates found.

### Guardrail Checks (Before Ticket Creation)

Email must satisfy **ALL** applicable checks:

1. **External ID present** → ✅ Bypass all others (any matched ID allows creation)
2. **Issue length** ≥ `EMAIL_MIN_ISSUE_LENGTH` (default 10 characters)
3. **Subject blacklist** — not in `EMAIL_SUBJECT_BLACKLIST` (`hello`, `hi`, `test`, `fw:`, `thanks`, etc.)
4. **Body length** ≥ 20 characters
5. **Sender domain** in `EMAIL_ALLOWED_SENDER_DOMAINS` (if configured; empty = all allowed)

**Failure → `email_inbox_log.processing_result = 'ignored'` + `ignored_reason` logged + admin notification.**

### Configuration Constants

Edit `services/email_parser_service.php`:

| Constant | Default | Meaning |
|----------|---------|---------|
| `EMAIL_MIN_ISSUE_LENGTH` | `10` | Minimum issue text length |
| `EMAIL_SUBJECT_BLACKLIST` | Array (see file lines 47–52) | Subjects that never create tickets |
| `EMAIL_ALLOWED_SENDER_DOMAINS` | `[]` | Domain whitelist (empty = no restriction) |

**To customize vendor formats:** Modify `$patterns` array in `email_parser_extract_ticket_references()` (lines 67–85).

---

## Cron Jobs

### Inbound: IMAP Import
**Script:** `cron/import_imap_tickets.php`  
**Frequency:** Every 5 minutes

```
Loop all active email_accounts
  → Connect IMAP (SSL/TLS)
  → Fetch UIDs > last_seen_uid
  → Fetch RFC822 for each UID
  → Parse → detect external_ticket_id
  → Create ticket OR link as reply
  → Update last_seen_uid after each account
```

**Safety:** First run baseline — stores highest UID then skips all existing messages (no historical import).

### Outbound: SMTP Send
**Script:** `cron/process_email_outbox.php`  
**Frequency:** Every 2 minutes

```
SELECT * FROM email_outbox_log WHERE status='pending' LIMIT 25
FOR EACH:
  → Resolve from_email via account_id
  → SMTP connect, AUTH, send
  → On success: status='sent', sent_at=NOW()
  → On failure: status='failed', error_message=SMTP error
```

---

## Configuration & Setup

### Adding an Email Account (UI)

1. Navigate: `emails/accounts.php` (Admin only)
2. Create new account:
   - **Email:** `support@yourcompany.com`
   - **From Name:** `Support Team` (optional)
   - **IMAP:** host, port (993 SSL or 143 TLS), encryption (`ssl` / `tls`), password
   - **SMTP:** host, port (465 SSL or 587 TLS), encryption (`ssl` / `tls`), from name, password
   - **Active:** ✓
3. Save

### Port/Encryption Mapping

| Port | Encryption | Behavior |
|------|-----------|----------|
| 993  | `ssl`     | `ssl://host:993` (implicit TLS) |
| 143  | `tls`     | STARTTLS after connect |
| 465  | `ssl`     | `ssl://host:465` (implicit TLS) |
| 587  | `tls`     | STARTTLS after connect |

**Common pitfall:** Using port 465 with `tls` → "SMTP greeting failed". Must use `ssl`.

### Post-Setup Verification

1. **Dashboard →** Expand new account
2. **Test SMTP →** Green ✓ OK
3. **Test IMAP →** Green ✓ OK
4. **Import Last 10 →** Check logs for inbound activity
5. **Send test email →** Create manual ticket → check outbox for confirmation email

---

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|-------------|-----|
| Outbox pending growing | Cron not running | Verify `cron/process_email_outbox.php` scheduled |
| "SMTP greeting failed" | Port-encryption mismatch | Port 465 → change to `ssl`; Port 587 → ensure `tls` |
| "Authentication failed" | Wrong password | Edit account → re-enter |
| "Certificate verification failed" | Self-signed/unknown CA | Switch ports or add CA bundle to php.ini |
| Inbound not creating tickets | `external_ticket_id` not detected | Check parser patterns; view `email_inbox_log.external_ticket_id` |
| Duplicate tickets | Same Message-ID sent twice (rare) | `email_inbox_log.message_id` UNIQUE prevents dupes |
| IMAP never polls | `last_seen_uid` = 0, `last_checked_at` = NULL | Test IMAP; fix credentials; ensure cron running |
| Reply doesn't link | External ID format mismatch between email & ticket | Ensure ticket's `external_ticket_id` exactly matches parser output |
| Customer never gets auto-email | `tickets.customer_email` NULL | Populate field; resave ticket |
| "Permission denied" socket | Outbound ports blocked by hosting | Request firewall open for 465/587 |

---

## Security Notes

- **Admin auth:** All management pages require `role = 'Admin'`
- **CSRF:** All POST actions require valid token
- **Passwords:** Stored plaintext in `email_accounts.password` — TODO: encrypt at rest
- **App passwords:** Recommended for Gmail/Office365 (not main account password)
- **Rate limiting:** Manual import capped at 10 per account → prevents DoS via bulk fetch

---

## Extension Points

### Custom Auto-Email Templates
Edit `modules/tickets/ticket_service.php`:
- `ticket_service_queue_created_email()`
- `ticket_service_queue_in_progress_email()`
- `ticket_service_queue_closed_email()`

Each receives `$ticket` array — modify body, subject, add CC as needed.

### New Ticket Status Emails
Add trigger in `ticket_service_handle_ticket_updated()` detecting status change → call new queue function.

### Custom Parser Logic
1. Add regex to `$patterns` in `email_parser_extract_ticket_references()`
2. Set `EMAIL_MIN_ISSUE_LENGTH`, `EMAIL_SUBJECT_BLACKLIST`, `EMAIL_ALLOWED_SENDER_DOMAINS`
3. Test with `scripts/dev/cli_email_parser_patterns.php`

---

**Last Updated:** 2026-04-29  
**Next Review:** 2026-05-29 (monthly)

## Cron Jobs

### 1. Import Inbound (IMAP)
**File:** `cron/import_imap_tickets.php`

```bash
*/5 * * * * php /path/to/noc/cron/import_imap_tickets.php
```

**Behavior:**
- Loops all active IMAP accounts
- For each: connect, fetch UIDs > last_seen_uid
- Parse → `email_processor_process_message()`
- Updates `last_seen_uid` after each batch
- **First run:** Baseline init — stores highest UID, skips all existing (prevents historical import)

### 2. Send Outbound (SMTP)
**File:** `cron/process_email_outbox.php`

```bash
*/2 * * * * php /path/to/noc/cron/process_email_outbox.php
```

**Behavior:**
- Fetches up to 25 `pending` rows from `email_outbox_log`
- For each: resend (SMTP socket, AUTH, DATA)
- On success: `status='sent'`, `sent_at=NOW()`
- On failure: `status='failed'`, `error_message=...`
- Logs outcome to `ticket_log` if linked to ticket

---

## Configuration & Setup

### Adding an Email Account (UI)

1. Admin → Email Accounts → Create
2. Fill:
   - Email: `sms.noc@loopmobility.com`
   - From Name: `Support` (optional)
   - IMAP: host, port (993 for SSL, 143 for plain/TLS), encryption (`ssl`|`tls`), password
   - SMTP: host, port (465 SSL, 587 TLS), encryption, password
   - Active: ✓
3. Save

**Encryption/Port mapping:**
| Port | Encryption | Transport |
|------|-----------|-----------|
| 993  | `ssl`     | `ssl://host:993` (implicit) |
| 143  | `tls`     | `host:143` + STARTTLS |
| 465  | `ssl`     | `ssl://host:465` (implicit) |
| 587  | `tls`     | `host:587` + STARTTLS |

**Important:** `smtp_encryption` must match port. If using 465 → set `ssl`. If using 587 → set `tls`.

### Testing After Setup

1. Go to **Email Management** dashboard
2. Click **Test SMTP** for the account → should say "SMTP OK"
3. Click **Test IMAP** → should say "IMAP OK"
4. Click **Force IMAP Import** → check logs for "created" or "replied"
5. Check **Outbox Queue** — pending should go to 0 within cron cycle

---

## Debugging Guide

| Symptom | Check | Fix |
|---------|-------|-----|
| Emails not sending | Outbox → Pending count > 0 | Run cron or Process Outbox manually |
| "SMTP greeting failed" in failures | Account SMTP host/port/encryption | Port 465 must use `ssl` encryption |
| "Authentication failed" | Password field in account | Re-enter password (plain text stored, no hashing) |
| "Certificate verification failed" | Host uses self-signed or unknown CA | Switch to `tls` on 587, or configure `openssl.cafile` in php.ini |
| Inbound emails not creating tickets | `email_inbox_log` entries show `ignored` | Check `ignored_reason` — likely "no ticket ID or issue" |
| Duplicate tickets from same email | `message_id` uniqueness? | `email_inbox_log` has UNIQUE constraint on `message_id` — dedup works |
| IMAP never fetches | `last_seen_uid` = 0, never checked | Test IMAP → if OK, ensure cron runs; or set `import_cutoff_at` manually |
| Reply doesn't link to existing ticket | External ID not matching | Check email parser patterns; ensure ticket's `external_ticket_id` matches extracted value |
| Customer never receives confirmation email | `customer_email` empty on ticket? | Check ticket record; ensure field is populated |
| "Permission denied" socket errors | Outbound ports blocked by hosting | Request port 465/587 open; or use alternative SMTP relay |

---

## Security Notes

- **Passwords:** Stored as plaintext in `email_accounts.password` — consider encrypting at rest.
- **CSRF:** All POST actions protected by `csrf_token()`.
- **Admin-only:** Email management page restricted to `role = 'Admin'`.
- **IMAP credentials:** Stored per account; use app-specific passwords where possible (Gmail).
- **Outbox retry:** Limited to 10 most recent failed per manual trigger to avoid flooding.

---

## Extension Points

### Customizing Auto-Email Templates
Edit functions in `modules/tickets/ticket_service.php`:
- `ticket_service_queue_created_email()`
- `ticket_service_queue_in_progress_email()`
- `ticket_service_queue_closed_email()`

### Adding New Status Emails
1. Add function similar to in-progress
2. Hook in `ticket_service_handle_ticket_updated()` detecting status change
3. Add corresponding log entry

### Changing Parser Patterns
Modify `$patterns` array in `services/email_parser_service.php:22-26` to match your external ID format (e.g., `INV-YYYY-NNNN`).

---

## Summary

- **All email activity** (in+out) is logged, searchable, and linked to tickets.
- **Automatic ticket creation** from inbound works without manual intervention.
- **Full lifecycle emails:** Created → In-Progress → Closed all notify customer.
- **Dashboard** gives admin complete visibility and control.
- **No breaking changes** — all prior features preserved.

For questions or enhancements, refer to inline code comments or contact the development team.
