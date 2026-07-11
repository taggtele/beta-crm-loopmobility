# Documentation Index

**NOC Email Ticketing System** — Complete operational and technical documentation.

---

## 📚 Core Documentation

| Document | Audience | Purpose |
|----------|----------|---------|
| [COMPLETE_SYSTEM_DOCUMENTATION.md](COMPLETE_SYSTEM_DOCUMENTATION.md) | Developers, Admins, Maintainers | **Full technical reference** — architecture, schema, flows, API, troubleshooting, security |
| [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) | New team members, stakeholders | High-level introduction with diagrams and data flow |
| [ADMIN_QUICK_REFERENCE.md](ADMIN_QUICK_REFERENCE.md) | Admins, support staff | One-page daily operations checklist and common fixes |

---

## 🎯 Feature-Specific Guides

| Document | Covers |
|----------|--------|
| [EMAIL_MANAGEMENT_DASHBOARD.md](EMAIL_MANAGEMENT_DASHBOARD.md) | v1 dashboard design and usage |
| [EMAIL_MANAGEMENT_V2.md](EMAIL_MANAGEMENT_V2.md) | Current dashboard (per-account panels, safe imports) |
| [PARSER_CONFIGURATION_GUIDE.md](PARSER_CONFIGURATION_GUIDE.md) | Vendor ID formats, guardrails, adding custom patterns |
| [PARSER_EXAMPLES.md](PARSER_EXAMPLES.md) | Real-world examples, edge cases, debugging |
| [FULL_IMPLEMENTATION_SUMMARY.md](FULL_IMPLEMENTATION_SUMMARY.md) | All changes, files modified, deployment checklist |

---

## 🔧 Implementation Notes

| Document | Creator | Purpose |
|----------|---------|---------|
| [TICKET_SERIAL_IMPLEMENTATION.md](../version_management/TICKET_SERIAL_IMPLEMENTATION.md) | Previous | LM-YYYYMMDD-NN format implementation |

---

## 📖 How to Use This Documentation

### For New Developers
1. Start with `SYSTEM_OVERVIEW.md` for big picture
2. Read `COMPLETE_SYSTEM_DOCUMENTATION.md` → Sections 1–6 (architecture, flow, schema)
3. Review code in order:
   - `cron/import_imap_tickets.php`
   - `modules/email/imap_service.php`
   - `modules/email/email_processor.php`
   - `modules/tickets/ticket_service.php`

### For Admins
1. Read `ADMIN_QUICK_REFERENCE.md` (print or save bookmark)
2. Practice on staging: `admin/email_management.php`
3. Refer to **Troubleshooting** section in `COMPLETE_SYSTEM_DOCUMENTATION.md` for error messages

### For Maintenance
1. Check `CHANGELOG` in `COMPLETE_SYSTEM_DOCUMENTATION.md` for version history
2. Refer to **Hook Points** section for safe extension
3. Review **Security** notes before modifying auth/password handling

---

## 🗂️ Document Structure

```
docs/
├── COMPLETE_SYSTEM_DOCUMENTATION.md   ← Master reference (read this first)
├── SYSTEM_OVERVIEW.md                 ← Architecture & data flow
├── ADMIN_QUICK_REFERENCE.md           ← One-page operations guide
├── EMAIL_MANAGEMENT_DASHBOARD.md      ← Dashboard v1 design
├── EMAIL_MANAGEMENT_V2.md             ← Dashboard v2 enhancements
└── version_management/
    └── TICKET_SERIAL_IMPLEMENTATION.md  ← Ticket ID format history
```

---

## 🔍 Quick Lookup

| Question | See Section |
|----------|-------------|
| How does inbound email become a ticket? | §4 Inbound Flow |
| Where is `external_ticket_id` parsed? | §6 Parser Logic |
| Which cron jobs exist? | §8 Cron Jobs |
| How to add a new email account? | §9 Configuration |
| Why are emails failing? | §10 Troubleshooting |
| How to manually import safely? | §7 Dashboard → Import Last 10 |
| Where are auto-email templates? | §5 Outbound Flow → Trigger Matrix |
| How to extend with custom logic? | §13 API Reference → Hook Points |
| What ports/encryption to use? | §9 Port/Encryption Mapping |
| How to purge old logs? | §12 Maintenance → Monthly Tasks |

---

## 📝 Contributing to Documentation

When modifying system behavior:

1. **Update relevant doc** in `docs/` (this is source of truth)
2. Add changelog entry to `COMPLETE_SYSTEM_DOCUMENTATION.md` → §14
3. If adding API: document function signature, params, return, side effects
4. If changing flow: update diagrams and step-by-step
5. Commit docs alongside code changes

**Style:** Markdown, clear headings, tables for structured data, code blocks with syntax highlighting.

---

## 🚀 Getting Started (New Admin)

1. **Access dashboard:** `http://localhost/noc/admin/email_management.php` (Admin only)
2. **Check accounts:** Expand each → Test SMTP/IMAP → should show green
3. **Try safe import:** Click "Import Last 10" → verify `emails/logs.php` shows inbound
4. **Read `ADMIN_QUICK_REFERENCE.md`** — save to desktop for daily use
5. **Bookmark:** `docs/COMPLETE_SYSTEM_DOCUMENTATION.md` for deep dives

---

**Maintained by:** Engineering Team  
**Last reviewed:** 2026-04-29  
**Next review:** 2026-05-29
