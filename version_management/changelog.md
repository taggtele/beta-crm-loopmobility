# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-05-23

### Added
- Admin **Release Management** page (`admin/release_management.php`) — edit version, build, date, feature list
- Runtime release config in `version_management/release.json` (sidebar reads from here)
- Sidebar footer shows first feature line + full list in tooltip

### Changed
- Major version bump to 2.0.0

---

## [1.1.0] - 2026-05-23

### Added
- Email logs: flag/important mail, UI refinements
- Ticket view: Reply to Customer / Reply to Vendor (isolated threads)
- Clean Outlook-style reply compose (signature only; threading via headers)

### Fixed
- CC persistence in email outbox log
- Quick-reply recipient validation improvements

---

## [1.0.0] - 2026-04-19

### Added
- Email IMAP import system
- Ticket management (create, update, view, list)
- SMTP outgoing notifications
- Dashboard with stats
- User management (Admin only)
- Notification system (real-time SSE)
- Email logs tracking
- Profile page
- Sidebar with navigation
- Ticket status: Open, In-Progress, Closed
- Priority: Low, Medium, High
- Smart ticket detection (existing ticket ID = reply)
- Auto-generate external ticket ID
- Customer email templates (created/closed)
- Filter show/hide toggle
- Professional 3-dot actions menu
- Version footer in sidebar

### Technical
- PHP 8.x compatible
- MySQL database
- Plain PHP (no framework)
- Real-time notifications via SSE
- IMAP/SMTP email handling

---

## Future Roadmap
- [ ] Email attachments handling
- [ ] Ticket export (PDF/CSV)
- [ ] Reports analytics
- [ ] SLA tracking
- [ ] Multi-language support
- [ ] Mobile responsive improvements