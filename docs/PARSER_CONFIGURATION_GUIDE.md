# Email Parser Configuration Guide

## Overview

The email parser extracts **external ticket references** from incoming emails and applies **guardrails** to prevent unwanted ticket creation from generic/spam messages.

---

## Supported Vendor Ticket ID Formats

The parser matches these patterns (all case-insensitive):

| Vendor / Format | Example | Pattern Type |
|-----------------|---------|--------------|
| **Internal NOC** | `LM-20260429-01` | Prefix + date + sequence |
| **Generic Ticket** | `TKT-ABC123` | `TKT-` prefix |
| **Vendor TT** | `TT-123456` or `TT<452457823>` or `[TT-123456]` | `TT-` prefix with brackets |
| **Vendor TCK** | `TCK-987654` or `(TCK-987654)` | `TCK-` prefix with brackets |
| **Numeric IDs** | `123456789` (6+ digits) | Plain number |
| **Keyword + ID** | `Ticket 123456`, `Case #TKT-98765`, `Ref: TCK-98765` | Keyword patterns |

---

## Extraction Patterns (services/email_parser_service.php)

### Pattern Order & Priority

```php
$patterns = [
    // 1. Explicit prefixes (highest priority)
    '/\b(TKT-[A-Z0-9-]{2,100})\b/i',
    '/\b(TT-[A-Z0-9-]{2,100})\b/i',
    '/\b(TCK-[A-Z0-9-]{2,100})\b/i',
    '/\b(LM-[A-Z0-9-]{2,100})\b/i',

    // 2. Bracketed variants (captures: TT<123>, TT[123], (TT-123))
    '/\b(TT[-\s]?[<\[(]?[A-Z0-9]{5,}[]\)>]?)\b/i',
    '/\b(TCK[-\s]?[<\[(]?[A-Z0-9]{5,}[]\)>]?)\b/i',

    // 3. Keyword patterns: "Ticket #ID", "Case #ID", "Ref: ID"
    '/\b(?:ticket(?:\s+id)?|case|reference|ref|external(?:\s+ticket)?(?:\s+id)?)\s*#\s*([A-Z0-9][A-Z0-9-]{0,100})\b/i',
    '/\b(?:ticket(?:\s+id)?|case|reference|ref|external(?:\s+ticket)?(?:\s+id)?)\s*[:#-]?\s*([A-Z0-9][A-Z0-9-]{2,100})\b/i',

    // 4. "ticket number: 123456789" (requires 6+ digits)
    '/\b(?:ticket\s+number|ticket\s+id|case\s+id|ref\s+id)\s*[:#-]?\s*(\d{6,})\b/i',
    '/\b(?:ticket|case|ref)\s+(\d{6,})\b/i',
];
```

**Priority rule:** First match wins. Non-numeric IDs (alphanumeric) are preferred over pure numeric IDs when multiple candidates exist.

---

## Guardrails — Prevent Unwanted Ticket Creation

### Validation Steps (in order)

1. **External ID Presence**  
   - If a valid ticket reference is found → ✅ ALLOW (ticket created or linked)

2. **Issue Length**  
   - Extracted issue text must be ≥ **10 characters** (configurable via `EMAIL_MIN_ISSUE_LENGTH`)  
   - Short, generic content → ❌ IGNORE

3. **Subject Blacklist**  
   - Subject exactly matches or starts with blacklisted keywords:  
     `hello`, `hi`, `test`, `testing`, `check`, `spam`, `junk`, `xyz`, `sample`, `demo`, 
     `fw:`, `fwd:`, `thank you`, `thanks`, `regards`, `sincerely`, `best regards`, 
     `notification`, `alert`, `warning`, `error`, `issue`
   - If subject is "Hello" → ❌ IGNORE

4. **Body Length**  
   - Full body must be ≥ **20 characters**  
   - Very short "OK" or "Yes" → ❌ IGNORE

5. **Sender Domain Whitelist** (optional)  
   - Set `EMAIL_ALLOWED_SENDER_DOMAINS = ['yourcompany.com', 'vendor1.com'];`  
   - If configured, only these domains can create tickets without an external ID  
   - Empty (default) = all domains allowed

### Final Decision Logic

```php
function email_parser_should_create_ticket($subject, $body, $fromEmail, $externalTicketId, $issue) {
    // RULE 1: External ID found → always allow
    if ($externalTicketId) return true;

    // RULE 2: Issue too short
    if (strlen(trim($issue)) < EMAIL_MIN_ISSUE_LENGTH) return false;

    // RULE 3: Subject blacklisted
    foreach (EMAIL_SUBJECT_BLACKLIST as $bad) {
        if (str_starts_with(strtolower($subject), $bad)) return false;
    }

    // RULE 4: Body extremely short
    if (strlen(trim($body)) < 20) return false;

    // RULE 5: Domain whitelist (if set)
    if (!empty(EMAIL_ALLOWED_SENDER_DOMAINS)) {
        $domain = strtolower(explode('@', $fromEmail)[1] ?? '');
        if (!in_array($domain, array_map('strtolower', EMAIL_ALLOWED_SENDER_DOMAINS), true)) {
            return false;
        }
    }

    return true;
}
```

---

## Configuration Constants

Edit `services/email_parser_service.php`:

| Constant | Default | Description |
|----------|---------|-------------|
| `EMAIL_MIN_ISSUE_LENGTH` | `10` | Minimum issue text length to auto-create ticket (chars) |
| `EMAIL_SUBJECT_BLACKLIST` | Array (see above) | Subjects that NEVER create tickets |
| `EMAIL_ALLOWED_SENDER_DOMAINS` | `[]` | Empty = all allowed; list = whitelist only |

### Example: Vendor-Specific Whitelist

```php
// Only vendors from these domains can create tickets without a ticket ID
const EMAIL_ALLOWED_SENDER_DOMAINS = [
    'yourcompany.com',
    'vendor1.example.com',
    'client-corp.com',
];
```

---

## Ticket ID Normalization

All extracted IDs are normalized to consistent format:

```php
function email_parser_normalize_ticket_reference(string $reference): string {
    $reference = strtoupper(trim($reference));           // uppercase
    $reference = trim($reference, "[]()<> \t\n\r\0\x0B"); // strip brackets
    if (str_starts_with($reference, '#')) {
        $reference = ltrim($reference, '#');              // remove leading #
    }
    return $reference;
}
```

Examples:
- `tt<123456>` → `TT-123456` (if `-` added by pattern)
- `[TCK-98765]` → `TCK-98765`
- `tt-123456` → `TT-123456`
- `Ticket LM-20260429-01` → `LM-20260429-01`

---

## Adding Custom Vendor Patterns

1. **Locate** `email_parser_extract_ticket_references()` in `services/email_parser_service.php`
2. **Add** your regex to the `$patterns` array **above** generic patterns:

```php
$patterns = [
    // Your vendor pattern first (higher priority)
    '/\b(VEND-[A-Z0-9-]{4,})\b/i',  // e.g., VEND-12345

    // ... existing patterns below
    '/\b(TKT-[A-Z0-9-]{2,100})\b/i',
    // ...
];
```

3. **Test** with sample subjects:

```php
$candidates = email_parser_extract_ticket_references($subject, $body);
var_dump($candidates); // view normalized IDs
```

---

## Edge Cases & Behavior

| Scenario | External ID? | Issue Length? | Result |
|----------|-------------|---------------|--------|
| Subject: `TT-123456 - Urgent server down` | Yes (`TT-123456`) | N/A | ✅ Ticket created/linked |
| Subject: `Hello` body: `My server is down` | None | 19 chars | ❌ Ignored (issue < 10) |
| Subject: `Ticket LM-20260429-01` body: `Please fix` | Yes (`LM-20260429-01`) | N/A | ✅ Linked to existing ticket |
| Subject: `Thanks` body: `Great service!` | None | 17 chars | ❌ Ignored (blacklisted + short) |
| From: `unknown@random.com` Subject: `Test TT-123` | Yes (`TT-123`) | N/A | ✅ Created (ID overrides guardrails) |
| Subject: `Error` body: `System crashed at 5pm today` | None | 31 chars | ✅ Created (issue ≥ 10, not blacklisted) |

---

## Testing Your Parser

Run the included test script:

```bash
php scripts/dev/cli_email_parser_patterns.php
```

Expected output:

```
=== TESTING PARSER PATTERNS ===
✓ PASS | Subject: 'TT-123123' → 'TT-123123'
✓ PASS | Subject: 'TCK-2483746174' → 'TCK-2483746174'
✓ PASS | Subject: 'Ticket LM-20260429-01' → 'LM-20260429-01'
...

=== TESTING GUARDRAILS ===
✓ PASS | 'TT-123456' → ShouldCreate=true
✓ PASS | 'Hello' → ShouldCreate=false
...
```

---

## Troubleshooting

### "Emails not creating tickets"
1. Check `email_inbox_log` table: Are `processing_result` = `'ignored'`?  
2. View `ignored_reason` column to see which guardrail failed  
3. If `no external ticket ID and issue is empty` → increase `EMAIL_MIN_ISSUE_LENGTH` or add more patterns  
4. If `email does not meet ticket creation criteria` → subject is blacklisted or body too short

### "Ticket ID not detected"
1. Verify your vendor format matches a pattern (use exact casing in regex)  
2. Add a new pattern to `$patterns[]` array  
3. Normalization strips brackets/angle brackets — test with `scripts/dev/cli_email_parser_patterns.php`

### "Too many spam tickets"
1. Add subject spam keywords to `EMAIL_SUBJECT_BLACKLIST`  
2. Enable `EMAIL_ALLOWED_SENDER_DOMAINS` whitelist  
3. Increase `EMAIL_MIN_ISSUE_LENGTH` from 10 → 15 or 20

---

## Maintenance Checklist

- [ ] Review ignored emails weekly (`SELECT * FROM email_inbox_log WHERE processing_result='ignored' ORDER BY received_at DESC`)  
- [ ] Adjust `EMAIL_MIN_ISSUE_LENGTH` based on average issue text length  
- [ ] Add new vendor prefixes to `$patterns` when onboarding new clients  
- [ ] Update blacklist if new spam subjects appear  
- [ ] Monitor `emails/logs.php` → Incoming section for false positives  

---

## See Also

- **System flow:** `docs/SYSTEM_OVERVIEW.md` §4 — Inbound Flow  
- **Full reference:** `docs/COMPLETE_SYSTEM_DOCUMENTATION.md` §6 — Parser Logic  
- **Admin ops:** `docs/ADMIN_QUICK_REFERENCE.md` — Daily email management  
- **Dashboard:** `admin/email_management.php` — Safe manual import, per-account controls
