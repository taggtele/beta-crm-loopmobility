LM-YYYYMMDD-XX Ticket Serial Format Implementation

=== DATABASE CONSIDERATIONS ===

Column to use: `external_ticket_id` (already exists in tickets table)
- This column is currently used for external references
- We'll repurpose it to store the formatted ticket serial: "LM-YYYYMMDD-XX"
- It's VARCHAR(255) typically, enough for 13 chars

===new_width IMPLEMENTATION STEPS ===

Step 1: Create helper function to generate ticket serial
Step 2: Update ticket creation (create.php + IMAP) to set external_ticket_id
Step 3: Update display locations (list.php, view.php, update.php) to show formatted ID
Step 4: Backfill existing tickets with generated serials
Step 5: Ensure daily reset and sequential numbering

=== DAILY SEQUENCE LOGIC ===

Format: LM-{YYYY}{MM}{DD}-{seq:2d}
Where seq = count of tickets created on that date + 1

On creation:
- Date part: date('Ymd')
- Sequence: SELECT COUNT(*) FROM tickets WHERE DATE(created_at) = CURDATE() + 1
- Generate: 'LM-' . $date . '-' . str_pad($seq, 2, '0', STR_PAD_LEFT)

=== SQL BACKFILL FOR EXISTING TICKETS ===

Backfill order: ORDER BY created_at, ticket_id
For each ticket, compute its daily sequence:
  - Group by DATE(created_at)
  - Within each day, assign row_number starting from 1
Update external_ticket_id with LM-YYYYMMDD-XX

=== FILES TO MODIFY (MINIMAL) ===

- /tickets/create.php  ← add serial generation before INSERT
- /tickets/list.php    ← display external_ticket_id (already shows ticket_id)
- /tickets/view.php    ← display formatted serial
- /tickets/update.php  ← display formatted serial
- /modules/tickets/ticket_service.php  ← if exists, add helper
- Backfill script: /cron/backfill_ticket_serials.php (one-time run)

=== TEST CHECKLIST ===

- New ticket shows LM-YYYYMMDD-01 format
- Second ticket same day shows LM-YYYYMMDD-02
- Next day resets to 01
- Existing tickets display formatted after backfill
- All links/references using ticket_id still work (ID remains same)
