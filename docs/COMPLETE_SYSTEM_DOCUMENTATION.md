# Email Ticketing System — Complete Technical Documentation

**Version:** 2.0  
**Last Updated:** 2026-04-29  
**Status:** Production Ready  
**Author:** Kilo Engineering  
**Application:** NOC Support Desk

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Architecture](#2-architecture)
3. [Database Schema](#3-database-schema)
4. [Email Flow — Inbound](#4-email-flow---inbound)
5. [Email Flow — Outbound](#5-email-flow---outbound)
6. [External Ticket ID Parsing](#6-external-ticket-id-parsing)
7. [Email Management Dashboard](#7-email-management-dashboard)
8. [Cron Jobs](#8-cron-jobs)
9. [Configuration](#9-configuration)
10. [Troubleshooting](#10-troubleshooting)
11. [Security](#11-security)
12. [Maintenance](#12-maintenance)
13. [API Reference](#13-api-reference)
14. [Changelog](#14-changelog)

---

## 1. System Overview

The Email Ticketing System automatically converts customer emails into support tickets and sends status updates via email. It supports:

- **Multiple IMAP inboxes** — poll multiple mailboxes for incoming customer requests
- **Multiple SMTP accounts** — send outgoing emails from different identities
- **Automatic ticket creation** — incoming emails become tickets without manual work
- **Automatic ticket linking** — replies to existing tickets are detected via `external_ticket_id`
- **Full lifecycle emails** — customer notified on create, in-progress, closed
- **Complete audit trail** — every email logged, searchable, linked to tickets

### Key Concepts

| Term | Meaning |
|------|---------|
| **External Ticket ID** | Customer-facing ticket number, e.g. `LM-20260429-01`. Stored in `tickets.external_ticket_id`. Used to match replies. |
| **Internal Ticket ID** | Auto-increment `ticket_id` primary key. Not shown to customers. |
| **Email Inbox Log** | Archive of received emails (`email_inbox_log`). Does not send from here. |
| **Email Outbox Log** | Queue of emails to send (`email_outbox_log`). Cron processes these. |
| **IMAP UID** | Unique message ID per mailbox. Stored as `last_seen_uid` to avoid re-import. |
| **Processing Result** | `created` | `replied` | `ignored` | `pending` |

---

## 2. Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                        EMAIL SYSTEM                               │
├──────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌─────────────────┐         ┌──────────────────────┐          │
│  │   IMAP Mailbox  │ ──────► │  import_imap_tickets  │          │
│  │ (customer → us) │ cron    │      (cron job)       │          │
│  └─────────────────┘         └───────────┬───────────┘          │
│                 │                         │                      │
│                 │      Parse + Extract   │                      │
│                 │    (subject, body,     │                      │
│                 │     external_ticket_id)│                      │
│                 │                         ▼                      │
│                 │              ┌─────────────────────┐           │
│                 │              │email_processor_      │           │
│                 │              │process_message()     │           │
│                 │              └───────────┬───────────┘           │
│                 │                          │                       │
│                 │        ┌─────────────────┼─────────────────┐     │
│                 │        │                 │                 │     │
│                 │        ▼                 ▼                 ▼     │
│                 │   ┌─────────┐      ┌─────────┐      ┌─────────┐│
│                 │   │ TICKETS │      │INBOX    │      │OUTBOX   ││
│                 │   │ table   │      │LOG      │      │LOG      ││
│                 │   │         │      │(store)  │      │(queue)  ││
│                 │   └─────────┘      └─────────┘      └─────────┘│
│                 │                          │                      │
│                 │                          │                      │
│  ┌──────────────▼───────────────────┐    │                      │
│  │   AUTO-EMAIL TRIGGERS            │    │                      │
│  │   (on ticket change)             │    │                      │
│  │   - ticket created   → outbox    │    │   ┌───────────────┐ │
│  │   - status in-progress → outbox  │    │   │ process_     │ │
│  │   - status closed    → outbox    │    │   │ outbox       │ │
│  └───────────────────────────────────┘    │   │ (cron job)   │ │
│                                           │   └───────┬───────┘ │
│                                           │           │         │
│                                           │           ▼         │
│                                           │   ┌─────────────────┐│
│                                           │   │  SMTP SEND      ││
│                                           │   │  (socket, AUTH) ││
│                                           │   └────────┬────────┘│
│                                           │            │         │
│                                           │            ▼         │
│                                           │   ┌─────────────────┐│
│                                           │   │ External Email  ││
│                                           │   │ (Gmail, etc.)   ││
│                                           │   └─────────────────┘│
│                                                                   │
└───────────────────────────────────────────────────────────────────┘
```

### Component Summary

| Component | File | Purpose |
|-----------|------|---------|
| **IMAP Fetcher** | `modules/email/imap_service.php` | Connects to IMAP, fetches raw messages |
| **SMTP Sender** | `modules/email/smtp_service.php` | Connects to SMTP, sends queued emails |
| **Message Processor** | `modules/email/email_processor.php` | Decides: reply vs new ticket |
| **Ticket Service** | `modules/tickets/ticket_service.php` | Auto-email triggers, notifications |
| **Parser** | `services/email_parser_service.php` | Extracts external ticket ID from subject/body |
| **Inbox DB** | `email_inbox_log` table | Stores received emails |
| **Outbox DB** | `email_outbox_log` table | Queues outgoing emails |
| **Tickets DB** | `tickets` table | Core ticket data |

---

## 3. Database Schema

### Table: `email_accounts`

Stores all mail account configurations.

```sql
CREATE TABLE email_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(150) NOT NULL,
    from_name VARCHAR(100) NULL,
    username VARCHAR(150) NULL,
    password TEXT NOT NULL,
    
    -- IMAP (incoming)
    imap_host VARCHAR(150) NULL,
    imap_port INT NULL,
    encryption ENUM('ssl','tls','none') DEFAULT 'ssl',
    
    -- SMTP (outgoing)
    smtp_host VARCHAR(150) NULL,
    smtp_port INT NULL,
    smtp_encryption ENUM('ssl','tls','none') DEFAULT 'ssl',
    
    -- State tracking
    is_active TINYINT(1) DEFAULT 1,
    last_seen_uid BIGINT NULL,           -- Highest IMAP UID processed
    import_cutoff_at DATETIME NULL,      -- Baseline timestamp (first run)
    last_checked_at DATETIME NULL,       -- Last poll timestamp
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Important notes:**
- `encryption` field used for IMAP; `smtp_encryption` used for SMTP
- Port 465 → `ssl` (implicit SSL); Port 587 → `tls` (STARTTLS)
- `last_seen_uid` ensures incremental import (no duplicates)
- `import_cutoff_at` skips all emails before that date (first-run optimization)

---

### Table: `email_inbox_log`

Incoming email archive. **Read-only** after insert.

```sql
CREATE TABLE email_inbox_log (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    message_id VARCHAR(255) UNIQUE,           -- RFC822 Message-ID
    from_email VARCHAR(150) NULL,
    subject TEXT NULL,
    body LONGTEXT NULL,
    raw_message LONGTEXT NULL,
    received_at DATETIME NULL,
    processed TINYINT(1) DEFAULT 0,
    processed_at DATETIME NULL,
    processing_result ENUM('pending','created','replied','ignored') DEFAULT 'pending',
    ignored_reason TEXT NULL,
    ticket_id BIGINT NULL,                    -- FK to tickets.ticket_id
    external_ticket_id VARCHAR(255) NULL,     -- Detected reference
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ticket_id (ticket_id),
    INDEX idx_received (received_at),
    INDEX idx_external (external_ticket_id)
);
```

**Processing flow:**
1. Raw email inserted with `processing_result = 'pending'`, `ticket_id = NULL`
2. Processor extracts `external_ticket_id`, finds/creates ticket
3. Updated: `ticket_id` set, `processing_result = 'created'|'replied'`

---

### Table: `email_outbox_log`

Outgoing email queue.

```sql
CREATE TABLE email_outbox_log (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    email_account_id INT NOT NULL,
    from_email VARCHAR(150) NOT NULL,
    to_email VARCHAR(150) NOT NULL,
    cc_email TEXT NULL,
    subject TEXT NULL,
    body LONGTEXT NULL,
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ticket_id BIGINT NULL,                    -- Link to ticket (optional)
    
    INDEX idx_status (status),
    INDEX idx_ticket (ticket_id),
    INDEX idx_created (created_at)
);
```

**Status lifecycle:**
- `pending` → Queued, awaiting cron
- `sent` → Delivered successfully, `sent_at` set
- `failed` → SMTP error, `error_message` populated, ready for retry

---

### Table: `tickets`

Core ticket data.

```sql
CREATE TABLE tickets (
    ticket_id INT PRIMARY KEY AUTO_INCREMENT,
    external_ticket_id VARCHAR(255) NULL,      -- Customer-facing ID
    issue VARCHAR(255) NOT NULL,
    description LONGTEXT NULL,
    customer VARCHAR(150) NOT NULL,
    customer_email VARCHAR(150) NULL,
    country VARCHAR(100) NULL,
    status ENUM('Open','In-Progress','Closed') DEFAULT 'Open',
    priority ENUM('Low','Medium','High') DEFAULT 'Medium',
    assign_to VARCHAR(50) NULL,
    created_by VARCHAR(50) NOT NULL,
    source VARCHAR(50) NULL,
    mail_message_id VARCHAR(255) NULL,
    mail_thread_id VARCHAR(255) NULL,
    reference TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    
    INDEX idx_external (external_ticket_id),
    INDEX idx_created (created_at),
    INDEX idx_status (status)
);
```

**`external_ticket_id` format:**
- Manually created: `LM-YYYYMMDD-NN` (e.g. `LM-20260429-01`)
- Imported from email: Whatever pattern matched (e.g. `TKT-98765`, `INV-2026-001`)

---

## 4. Email Flow — Inbound

### Step-by-Step Process

```
Cron: cron/import_imap_tickets.php
   ↓
email_imap_import_messages($pdo, $limitPerAccount = 0, $accountId = 0)
   ↓
For each active IMAP account (or single if $accountId specified):
   ├─ Connect via IMAP (ssl:// or tls + STARTTLS)
   ├─ SELECT INBOX
   ├─ Get last_seen_uid from DB
   ├─ UID SEARCH UID > last_seen_uid [LIMIT $limitPerAccount]
   ├─ For each UID:
   │   ├─ UID FETCH RFC822 (raw email)
   │   ├─ Parse: email_imap_parse_message()
   │   │   └─ Returns: message_id, from_email, from_name, subject, body, received_at, thread_id
   │   └─ Process: email_processor_process_message($pdo, $account, $parsedMessage)
   │       ├─ Check duplicate: SELECT id FROM email_inbox_log WHERE message_id = ?
   │       ├─ Extract external_ticket_id: email_parser_detect_external_ticket_id($subject, $body)
   │       ├─ Lookup existing ticket: SELECT ticket_id FROM tickets WHERE external_ticket_id = LOWER(:ext)
   │       │
   │       ├─ If FOUND (Reply path):
   │       │   ├─ UPDATE email_inbox_log SET ticket_id = ?, processing_result = 'replied'
   │       │   ├─ INSERT ticket_log ('email_reply')
   │       │   ├─ If ticket Closed → reopen (status='Open', closed_at=NULL)
   │       │   └─ Notify admins
   │       │
   │       └─ If NOT FOUND (New ticket path):
   │           ├─ Extract issue: email_processor_extract_issue($message)
   │           │   └─ Uses subject if meaningful; else first non-greeting body line
   │           ├─ INSERT INTO tickets (
   │           │   issue, description=body, external_ticket_id=detected,
   │           │   customer=from_name, customer_email=from_email,
   │           │   status='Open', priority='Medium', source='email',
   │           │   created_by=resolve_user_id()
   │           │ )
   │           ├─ INSERT INTO email_inbox_log (ticket_id=new, processing_result='created')
   │           ├─ INSERT ticket_log ('created')
   │           ├─ Notify admins
   │           └─ Queue customer email: ticket_service_queue_created_email()
   │
   ├─ Update last_seen_uid = max UID seen for this account
   └─ Update last_checked_at = NOW()
```

### Parser Logic (`services/email_parser_service.php`)

**Extracts external_ticket_id** from subject + first 4000 chars of body.

**Patterns (in order):**

```php
1. '/\b(TKT-[A-Z0-9-]{2,100})\b/i'
   → Matches: TKT-ABC123, TKT-20260429-01

2. '/\b(?:ticket|case|reference|ref|external\s*ticket\s*id?)\s*#\s*([A-Z0-9][A-Z0-9-]{0,100})\b/i'
   → Matches: Ticket #LM-20260429-01, Case #TKT-98765

3. '/\b(?:ticket|case|reference|ref|external\s*ticket\s*id?)\s*[:#-]?\s*([A-Z0-9][A-Z0-9-]{2,100})\b/i'
   → Matches: Ticket: LM-20260429-01, Ref: ABC-12345
```

**Normalization:**
- Uppercase
- Trim whitespace
- Strip leading `#` if present

**Detection strategy:** Returns first **non-numeric** candidate (prefers alphanumeric like `LM-...` over pure digits). If only numeric candidates found, returns first one.

---

## 5. Email Flow — Outbound

### Trigger Matrix

| Trigger | Function | Email To | Subject Pattern | Body Includes Description? |
|---------|----------|----------|-----------------|---------------------------|
| Ticket created (manual or inbound) | `ticket_service_queue_created_email()` | Customer (if email set) | `Ticket LM-YYYYMMDD-NN - Support Request Received` | ✅ Yes |
| Status → In-Progress | `ticket_service_queue_in_progress_email()` | Customer | `Ticket LM-... - Status Update: In Progress` | ✅ Yes |
| Status → Closed | `ticket_service_queue_closed_email()` | Customer | `Ticket LM-... - Support Request Resolved` | ✅ Yes |
| Manual compose via UI | `email_smtp_queue()` → `email_smtp_send_message()` | Any | User-provided | As typed |
| Assignment change | `ticket_service_queue_assigned_email()` | Assignee (agent) | `Ticket LM-... assigned to you` | ❌ No (short notice) |

### Outbox Processing (`cron/process_email_outbox.php`)

```php
// Fetch up to 25 pending emails
$stmt = $pdo->query("
    SELECT * FROM email_outbox_log 
    WHERE status = 'pending' 
    ORDER BY created_at ASC 
    LIMIT 25
");

foreach ($emails as $email) {
    $account = email_smtp_active_account($pdo, $email['email_account_id']);
    
    try {
        // SMTP connect
        $stream = stream_socket_client(
            $encryption === 'ssl' ? 'ssl://' . $host : $host . ':' . $port,
            $errno, $errstr, 10
        );
        
        // Handshake
        email_smtp_read_response($stream);  // expect 220
        email_smtp_command($stream, 'EHLO localhost', [250]);
        
        // TLS upgrade if needed
        if ($encryption === 'tls') {
            email_smtp_command($stream, 'STARTTLS', [220]);
            stream_socket_enable_crypto($stream, true);
            email_smtp_command($stream, 'EHLO localhost', [250]);
        }
        
        // Auth
        email_smtp_command($stream, 'AUTH LOGIN', [334]);
        email_smtp_command($stream, base64_encode($username), [334]);
        email_smtp_command($stream, base64_encode($password), [235]);
        
        // Send
        email_smtp_command($stream, "MAIL FROM:<{$from}>", [250]);
        email_smtp_command($stream, "RCPT TO:<{$to}>", [250]);
        email_smtp_command($stream, 'DATA', [354]);
        email_smtp_command($stream, $headers . "\r\n\r\n" . $body . "\r\n.", [250]);
        
        // Success
        email_smtp_mark_sent($pdo, $email['id']);
        
    } catch (RuntimeException $e) {
        email_smtp_mark_failed($pdo, $email['id'], $e->getMessage());
    }
}
```

**Retry logic:** Failed emails stay in `status='failed'`. Manual retry via dashboard or re-run cron (cron attempts only pending, not failed — must manually retry or re-queue).

---

## 6. External Ticket ID Parsing

### Patterns File
`services/email_parser_service.php`

### Matching Strategy

| Pattern | Examples | Notes |
|---------|----------|-------|
| `TKT-XXXXX` | `TKT-ABC123`, `TKT-20260429-01` | Strict prefix `TKT-` |
| `Ticket #ID` | `Ticket #LM-20260429-01`, `Case #75` | After `#`, alphanumeric, min 2 chars |
| `Ticket: ID` | `Ticket: LM-20260429-01` | Colon or hyphen separator |


### Normalization

```php
function email_parser_normalize_ticket_reference(string $reference): string {
    $reference = strtoupper(trim($reference));
    $reference = trim($reference, "[]()<> \t\n\r\0\x0B");  // Strip surrounding brackets
    if (str_starts_with($reference, '#')) {
        $reference = ltrim($reference, '#');
    }
    return $reference;  // e.g. "LM-20260429-01"
}
```

### Lookup Logic

```php
// In email_processor_process_message()
$externalTicketId = email_parser_detect_external_ticket_id($subject, $body);

$existingTicketStmt = $pdo->prepare("
    SELECT ticket_id, status FROM tickets 
    WHERE LOWER(external_ticket_id) = LOWER(:external_ticket_id) 
    LIMIT 1
");
$existingTicketStmt->execute([':external_ticket_id' => $externalTicketId]);
```

**Case-insensitive match** → `LM-20260429-01` matches `lm-20260429-01`.

---

## 7. Email Management Dashboard

### Location
`admin/email_management.php` (Admin only)

### Purpose
Single-pane monitoring and control for all email accounts.

---

### Features

#### A. Global Stats (Top)
| Stat | Query | Meaning |
|------|-------|---------|
| Outox Pending | `COUNT(*) WHERE status='pending'` | Queued, not yet sent |
| Sent Total | `COUNT(*) WHERE status='sent'` | Lifetime delivered |
| Failed | `COUNT(*) WHERE status='failed'` | Needs attention |
| Today's Tickets | `COUNT(*) FROM tickets WHERE DATE(created_at)=today` | New tickets today |

---

#### B. Global Actions

| Action | What it does | Safety |
|--------|--------------|--------|
| **Import All Accounts (Last 10 each)** | Calls `email_imap_import_messages($pdo, 10)` → each active account fetches max 10 newest emails | ✅ Cap prevents flood |
| **Retry All Failed (Max 10)** | Selects 10 most recent failed outbox rows → `email_smtp_process_outbox_item()` each | ✅ Limited batch |
| **Import CLI** | Opens `cron/import_imap_tickets.php` in new tab (manual cron run) | ⚠️ Unlimited if cron not configured |
| **Outbox CLI** | Opens `cron/process_email_outbox.php` in new tab | ⚠️ Processes all pending |

---

#### C. Per-Account Panels (Expandable)

**Summary row:**
- Icon (envelope) + Email address
- From name (if set)
- Active/Inactive badge
- Inbound count (total received)
- Outbound count (total sent)
- Failed (last 7 days) — highlighted in red if > 0
- Last Checked (timestamp)
- Last UID (highest IMAP message ID processed)
- Chevron (click to expand)

**Expanded details:**

1. **IMAP Configuration**
   - Server: `imap_host:imap_port`
   - Encryption: `ssl` | `tls` | `none`
   - Username
   - Active status

2. **SMTP Configuration**
   - Server: `smtp_host:smtp_port`
   - Encryption
   - From Name
   - From Email

3. **Activity Stats**
   - Total Inbound
   - Total Outbound
   - Failed (7 days)

4. **Import Status**
   - Baseline Set: `import_cutoff_at` (first successful poll)
   - Last Poll: `last_checked_at`
   - Processed UID: `last_seen_uid` (prevents re-import)

5. **Action Buttons**
   | Button | Action | Safety |
   |--------|--------|--------|
   | Test SMTP | Socket connect to `smtp_host:smtp_port` | Read-only, no login |
   | Test IMAP | Socket connect to `imap_host:imap_port` | Read-only |
   | Import Last 10 | Call `email_imap_import_messages($pdo, 10, $accountId)` | ✅ Only 10 messages |
   | Edit Account | Link to `emails/accounts.php?id=X` | — |
   | View Logs | Link to `emails/logs.php?from_email=X` | — |

---

### Tooltips

All `?` icons have CSS tooltips:
```css
.tooltip-icon:hover::after { /* dark bubble with text */ }
```
No JS required. Accessible via `title` attribute.

---

## 8. Cron Jobs

### Job 1: Import Inbound (IMAP)

**File:** `cron/import_imap_tickets.php`

```bash
*/5 * * * * php /path/to/noc/cron/import_imap_tickets.php
```

**What it does:**
- Loops all `email_accounts` where `imap_host IS NOT NULL AND is_active=1`
- For each account:
  - Connects via IMAP
  - Fetches UIDs > `last_seen_uid`
  - Processes each message
  - Updates `last_seen_uid` to highest seen
  - Updates `last_checked_at = NOW()`

**First run behavior:**
- If `last_seen_uid = 0` (never polled):
  - Fetches **all** UIDs in mailbox
  - Stores highest UID as `last_seen_uid` and `import_cutoff_at = NOW()`
  - **Skips processing all existing messages** — does not create tickets from old mail
  - Only new messages arriving **after** baseline will be processed

**Safety:** UID-based incremental import prevents duplicates. Even if cron runs twice, same UIDs are skipped.

---

### Job 2: Send Outbound (SMTP)

**File:** `cron/process_email_outbox.php`

```bash
*/2 * * * * php /path/to/noc/cron/process_email_outbox.php
```

**What it does:**
- Fetches up to 25 rows where `status = 'pending'` ordered by `created_at ASC`
- For each:
  - Loads associated `email_accounts` row
  - Opens SMTP connection
  - Sends via `AUTH LOGIN` (plain credentials)
  - On 250 response after `DATA`: marks `status='sent'`, `sent_at=NOW()`
  - On any exception: marks `status='failed'`, stores error in `error_message`

**Note:** Failed emails are **not** automatically retried by cron. Must retry manually via dashboard or re-queue with:
```sql
UPDATE email_outbox_log SET status='pending', error_message=NULL WHERE id = ?;
```

---

## 9. Configuration

### Adding an Email Account (UI)

**Path:** `emails/accounts.php` (Admin only)

**Fields:**

| Field | Meaning | Required? |
|-------|---------|-----------|
| Email | Sender address (also login) | Yes |
| From Name | Display name in recipient's inbox | No (defaults to email) |
| Username | IMAP/SMTP username (often same as Email) | Recommended |
| Password | IMAP + SMTP password (same unless servers differ) | Yes |
| IMAP Host | e.g. `imap.gmail.com`, `mail.loopmobility.com` | Yes for inbound |
| IMAP Port | 993 (SSL), 143 (TLS/plain) | Yes if IMAP set |
| IMAP Encryption | `ssl` | `tls` | `none` | Yes if IMAP set |
| SMTP Host | e.g. `smtp.gmail.com` | Yes for outbound |
| SMTP Port | 465 (SSL), 587 (TLS) | Yes if SMTP set |
| SMTP Encryption | **Must match port**: 465→`ssl`, 587→`tls` | Yes |
| Active | Check to enable | Yes |

**Save:** Inserts/updates `email_accounts` row.

---

### Port/Encryption Mapping

| Port | Encryption | Transport | Example |
|------|-----------|------------|---------|
| 993 | `ssl` | `ssl://host:993` (implicit) | Gmail IMAP |
| 143 | `tls` | `host:143` + STARTTLS | Self-hosted |
| 465 | `ssl` | `ssl://host:465` (implicit) | Gmail SMTP |
| 587 | `tls` | `host:587` + STARTTLS | Most relays |
| 25 | `none` or `tls` | `host:25` + optional STARTTLS | Legacy |

**Critical:** `smtp_encryption` **must** match `smtp_port`. Mismatch → "SMTP greeting failed".

---

### Testing After Setup

1. Go to **Email Management** dashboard (`admin/email_management.php`)
2. Expand new account
3. Click **Test SMTP** → expect green "✓ SMTP OK: host:port (ssl/tls)"
4. Click **Test IMAP** → expect green "✓ IMAP OK: host:port"
5. Click **Import Last 10** → should show "Imported 0 msgs" if no new mail
6. Send test email to that account's address → then re-import → should create ticket

---

## 10. Troubleshooting

### Issue: "SMTP greeting failed"

**Symptom:** Outbox entry shows error: `SMTP greeting failed.`

**Cause:** Port-encryption mismatch. Port 465 expects SSL handshake immediately; using `tls` (plain) means no greeting sent.

**Diagnosis:**
1. Dashboard → account → Test SMTP → same error
2. Check `email_accounts.smtp_encryption` and `smtp_port`

**Fix:**
```sql
-- Update to use ssl on port 465
UPDATE email_accounts 
SET smtp_encryption = 'ssl' 
WHERE smtp_port = 465;
```

Or change port to 587 and use `tls`.

---

### Issue: "Unable to connect to SMTP server: Permission denied"

**Cause:** Outbound port blocked by hosting firewall.

**Diagnosis:**
- Test SMTP from dashboard → fails instantly with "Permission denied" or empty
- `error_get_last()` returns empty message

**Fix:**
- Ask hosting provider to allow outbound connections on port 465 or 587
- Or use external SMTP relay (e.g. SendGrid, Mailgun)

---

### Issue: Inbound emails not creating tickets

**Diagnosis:**

1. Check `email_inbox_log` for this account's emails:
```sql
SELECT * FROM email_inbox_log 
WHERE from_email = 'account@domain.com' 
ORDER BY id DESC LIMIT 5;
```

2. Look at `processing_result`:
   - `ignored` → check `ignored_reason`
   - `pending` → processor hasn't run (cron down?)
   - `created`/`replied` → working fine

3. Common `ignored_reason`:  
   `"Ignored incoming email because external ticket ID and issue both are missing."`

   **Cause:** Subject like "Hello" + body starts with greeting → no issue extracted, no external ID found.

   **Fix:**
   - Ensure emails contain a recognizable ticket reference in subject/body, OR
   - Adjust parser patterns to extract issue from generic subjects, OR
   - Ensure `customer_email` is present so at least confirmation email can be sent

---

### Issue: Duplicate tickets from same email

**Should not happen.** System dedups by `message_id` (UNIQUE constraint).

If duplicates appear:
- Check if email client generates new `Message-ID` each time (some do for testing)
- Or database UNIQUE index missing:
```sql
ALTER TABLE email_inbox_log ADD UNIQUE KEY uq_email_inbox_message_id (message_id);
```

---

### Issue: "Last Seen UID = N/A" and no inbound imports

**Cause:** Account never successfully connected via IMAP. Baseline never set.

**Fix:**
1. Dashboard → account → **Test IMAP** → see error
2. Correct host/port/encryption in `emails/accounts.php`
3. Click **Import Last 10** once → should succeed → `last_seen_uid` populated

---

### Issue: Replies don't link to existing ticket

**Symptom:** Customer replies with "Re: Ticket LM-20260429-01" but new ticket created.

**Diagnosis:**
1. Confirm ticket's `external_ticket_id` matches what customer is referencing.
   ```sql
   SELECT external_ticket_id FROM tickets WHERE ticket_id = 123;
   ```
2. Check what parser extracted from email:
   ```sql
   SELECT external_ticket_id FROM email_inbox_log WHERE id = ?;
   ```
3. If `external_ticket_id` in inbox_log is NULL or different format → parser didn't match.

**Fix:**
- Ensure subject contains clear reference: `Ticket LM-20260429-01` (exact match)
- Or adjust `email_parser_service.php` patterns to capture your ID format
- Test patterns using one-off script or unit tests

---

### Issue: Customer never receives confirmation/status emails

**Diagnosis:**

1. Check `email_outbox_log` for that ticket:
```sql
SELECT * FROM email_outbox_log 
WHERE ticket_id = 123 
ORDER BY id DESC;
```

2. Look at `status`:
   - `pending` → cron hasn't run yet or outbox stuck
   - `sent` → delivered (check spam)
   - `failed` → see `error_message`

3. If `failed`: 
   - Click **Retry** from dashboard outbox section
   - Or fix SMTP credentials → re-queue:
   ```sql
   UPDATE email_outbox_log SET status='pending', error_message=NULL WHERE id = ?;
   ```

4. Check `tickets.customer_email` is populated. Auto-emails only send if this field is non-empty.

---

### Issue: Port 465 SSL still fails after setting encryption=ssl

**Possible causes:**
1. **Self-signed certificate** — PHP stream may reject. Fix: add `stream_context_set_option($stream, ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);` in `smtp_service.php` (not recommended for production)
2. **Firewall blocks** — outbound 465 blocked by provider
3. **Wrong password** — "Authentication failed" error, not greeting fail

**Diagnose with openssl:**
```bash
openssl s_client -connect mail.loopmobility.com:465 -quiet
```
Should see SMTP greeting after handshake.

---

## 11. Security

### Password Storage

**Current:** Plaintext in `email_accounts.password`.  
**Risk:** Database compromise exposes mail credentials.

**Recommendation (future):**
- Encrypt using `openssl_encrypt()` with app key
- Store IV + ciphertext
- Decrypt on use in `smtp_service.php` / `imap_service.php`

---

### CSRF Protection

All POST forms include `csrf_field()`:
```php
<?php echo csrf_field(); ?>
<!-- outputs: <input type="hidden" name="csrf_token" value="..."> -->
```

Verified in handler via `verify_csrf()`.

---

### Access Control

- **Email Management page:** `role === 'Admin'` only
- **Account edit page:** Admin only
- **Compose email:** Any logged-in user (ticket agents)
- **View logs:** Any authenticated user (respects ticket scope)

---

### Input Validation

- Email fields: `filter_var($email, FILTER_VALIDATE_EMAIL)`
- Ticket IDs: `(int)` cast
- SQL: Prepared statements everywhere (PDO)

---

### Rate Limiting

**None currently implemented.** Consider:
- Throttling manual import per admin (e.g., 5 times/hour)
- Limiting outbox retry attempts (max 3 per email)
- Daily quota per account to avoid spam flags

---

## 12. Maintenance

### Daily Checks

1. **Dashboard** → `admin/email_management.php`:
   - Failed count should not trend upward
   - All accounts show recent "Last Checked"
   - No accounts stuck in baseline (N/A)

2. **Cron logs** (if enabled):
   ```bash
   tail -f /var/log/cron.log
   # Look for: import_imap_tickets.php, process_email_outbox.php errors
   ```

3. **Ticket volume** — sudden drop may mean IMAP down

---

### Weekly Tasks

1. Retry any failed emails (dashboard → Retry All)
2. Review "Ignored" inbound messages in `email_inbox_log` — may need parser tweaks
3. Check disk usage: `email_outbox_log` can grow large
   ```sql
   SELECT COUNT(*) FROM email_outbox_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
   -- Consider archiving/purging old sent emails if needed
   ```

---

### Monthly Tasks

1. **Archive old logs:**
   ```sql
   -- Keep last 6 months in main tables, archive older to separate DB/CSV
   DELETE FROM email_outbox_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY);
   DELETE FROM email_inbox_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY);
   ```

2. **Review account credentials:**
   - Some providers (Gmail) require app passwords refreshed periodically
   - Update in `emails/accounts.php` if needed

3. **Check parser patterns:** 
   - If customers use new ID formats, update `services/email_parser_service.php`

---

### Database Optimization

```sql
-- Index optimization (already in schema)
ALTER TABLE email_inbox_log ADD INDEX idx_message_id (message_id);
ALTER TABLE email_outbox_log ADD INDEX idx_status_created (status, created_at);

-- Partition by month (optional for high volume)
ALTER TABLE email_outbox_log PARTITION BY RANGE (TO_DAYS(created_at)) (...);
```

---

## 13. API Reference

### Public Functions (for extension)

#### `email_imap_import_messages(PDO $pdo, int $limitPerAccount = 0, int $accountId = 0): array`

Initiates IMAP import.

**Parameters:**
- `$pdo` — Database connection
- `$limitPerAccount` — Max emails to fetch per account (0 = unlimited, but use 10 for safety)
- `$accountId` — Specific account ID to import (0 = all active accounts)

**Returns:**
```php
[
    'accounts' => int,        // Accounts processed
    'messages' => int,        // Total messages fetched
    'created' => int,         // New tickets created
    'replied' => int,         // Replies linked to existing tickets
    'ignored' => int,         // Messages ignored (no ID + no issue)
    'duplicates' => int,      // Already seen (same message_id)
    'baseline_initialized' => int,  // Accounts newly baselined
    'failed' => int           // Errors during processing
]
```

---

#### `email_smtp_process_outbox_item(PDO $pdo, int $id): bool`

Attempts to send one queued email.

**Parameters:**
- `$id` — `email_outbox_log.id`

**Returns:**
- `true` — Sent successfully, row marked `sent`
- `false` — Failed, row marked `failed` with error

**Side effects:** Updates `email_outbox_log.status`, `sent_at`, `error_message`.

---

#### `email_parser_detect_external_ticket_id(string $subject, string $body): ?string`

Detects external ticket reference from email content.

**Returns:** String like `LM-20260429-01` or `NULL` if none found.

---

#### `format_ticket_serial(PDO $pdo, array $ticket): string`

Formats ticket for display: `LM-YYYYMMDD-NN`.

Uses `$ticket['daily_sequence']` if available (precomputed in query). Falls back to counting same-day tickets (expensive).

---

#### `ticket_service_queue_created_email(PDO $pdo, array $ticket): ?int`

Queues "Ticket Created" customer notification.

**Returns:** `email_outbox_log.id` on success, `null` if no customer email.

---

#### `ticket_service_queue_in_progress_email(PDO $pdo, array $ticket): ?int`

Queues "In Progress" notification.

---

#### `ticket_service_queue_closed_email(PDO $pdo, array $ticket): ?int`

Queues "Ticket Closed" notification.

---

### Hook Points

To add custom behavior on ticket events:

```php
// In modules/tickets/ticket_service.php

function ticket_service_handle_ticket_created(PDO $pdo, array $ticket): void
{
    // ... existing: log, notify, queue created email ...
    
    // Your custom code here:
    // my_custom_function($pdo, $ticket);
}

function ticket_service_handle_ticket_updated(PDO $pdo, array $before, array $after): void
{
    // ... existing: status change handling ...
    
    // Hook: after status change
    if ($before['status'] !== $after['status']) {
        // my_on_status_change($pdo, $after);
    }
}
```

---

## 14. Changelog

### v2.0 (2026-04-29)

**Major Features:**
- Added Email Management Dashboard (`admin/email_management.php`)
  - Per-account expandable panels
  - Test SMTP/IMAP buttons
  - Safe manual import (limit 10)
  - Retry failed emails
  - Tooltips + responsive UI
  
**Improvements:**
- Enhanced `email_imap_import_messages()` with `$accountId` filter and `$limitPerAccount` cap
- `replied` count now tracked separately in import summary
- Compose modal now includes ticket selector with autocomplete
- Ticket cards show clickable ticket IDs linking to detail page
- Description field now editable in ticket update
- Auto-emails (created/in-progress/closed) now include ticket description

**Bug Fixes:**
- Fixed `smtp_encryption` mismatch for port 465 accounts (was `tls`, now `ssl`)
- Fixed undefined `$ticketId` in `ticket_service_queue_closed_email()`
- Fixed `format_date_time()` undefined → use `format_date()`

**Documentation:**
- Created `SYSTEM_OVERVIEW.md`
- Created `EMAIL_MANAGEMENT_DASHBOARD.md`
- Created `EMAIL_MANAGEMENT_V2.md`
- Updated inline code comments

---

### v1.0 (Initial)

- Basic email → ticket creation via IMAP
- Outbound SMTP with queue
- Email logs view
- Manual account management
- Auto-emails on create/close only

---

## Appendix

### A. Regex Pattern Test Cases

Use these to validate parser:

```php
$tests = [
    ['Ticket LM-20260429-01 - Issue', 'LM-20260429-01'],
    ['Re: Ticket LM-20260429-01', 'LM-20260429-01'],
    ['Case #TKT-98765: Problem', 'TKT-98765'],
    ['Ref: ABC-12345 - Hello', 'ABC-12345'],
    ['No ID here', null],
];
```

### B. SMTP Response Codes

| Code | Meaning | Action |
|------|---------|--------|
| 220 | Service ready | Proceed with EHLO |
| 250 | OK | Continue |
| 334 | Auth challenge | Send username/password (base64) |
| 235 | Auth successful | Proceed to MAIL FROM |
| 354 | Start mail input | Send headers + body |
| 421 | Service not available | Retry later (mark failed) |
| 450 | Mailbox unavailable | Retry later |
| 550 | Mailbox unreachable | Permanent failure (mark failed) |

### C. IMAP Commands Used

| Command | Purpose |
|---------|---------|
| `a001 LOGIN "user" "pass"` | Authenticate |
| `a002 SELECT INBOX` | Choose mailbox |
| `a003 UID SEARCH ALL` | Get all message UIDs |
| `a004 UID FETCH 123 (RFC822)` | Fetch full raw email |
| `a005 LOGOUT` | Disconnect |

---

**End of Documentation**

For questions or contributions, refer to inline code comments or contact the engineering team.
