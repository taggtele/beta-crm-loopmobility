# Party Account Module — User Guide

**Loop Mobility CRM**  
Version 1.0 | June 2026

---

## Table of Contents

1. [Module Overview](#1-module-overview)
2. [User Roles & Access](#2-user-roles--access)
3. [Database & Data Model](#3-database--data-model)
4. [Getting Started — Setup](#4-getting-started--setup)
5. [Party Accounts Workspace](#5-party-accounts-workspace)
6. [Managing Party Accounts](#6-managing-party-accounts)
7. [Ledger & Transactions](#7-ledger--transactions)
8. [Party Transactions Explorer](#8-party-transactions-explorer)
9. [Import & Export](#9-import--export)
10. [Multi-Currency Support](#10-multi-currency-support)
11. [Monthly Closing](#11-monthly-closing)
12. [System Files Reference](#12-system-files-reference)

---

## 1. Module Overview

The Party Account module is the treasury and finance management slice of Loop Mobility CRM. It replaces fragmented spreadsheets with a structured, auditable ledger for every business relationship — vendors, customers, agents, and partners. Every party record stores contact details, banking information, credit limits, and a complete transaction history.

Built for accuracy and compliance: every create, update, archive, restore, and ledger transaction is logged with actor name, timestamp, IP address, and user agent.

---

## 2. User Roles & Access

Access is controlled via the `users.role` column in the database. The module supports four roles:

| Role | Description |
|------|-------------|
| **Admin** | Full access to the entire CRM including all Party Account features (create, edit, delete, ledger, reports). |
| **Finance** | Full access **only** to the Party Account module. The sidebar is scoped to this module; other CRM routes redirect here. |
| **Sales** | View-only access. Can browse party lists, view details, and apply filters. Cannot edit, import, or export. |
| **Agent** | No access. Receives HTTP 403 Forbidden on any attempt to use this module. |

**To assign the Finance role to a user:**
1. Go to your MySQL client (phpMyAdmin or CLI).
2. Run: `UPDATE users SET role = 'Finance' WHERE id = <user_id>;`
3. Log in — the Party Account item will appear in the sidebar.

---

## 3. Database & Data Model

The module creates and manages the following tables:

| Table | Purpose |
|-------|---------|
| `loop_entities` | Master list of company branches / legal entities used to classify party relationships. |
| `party_accounts` | Core party profile: contact, banking, credit, currency, status, and opening balance. |
| `party_currency_ledgers` | Per-currency opening balances for multi-currency parties. |
| `party_ledger_transactions` | Individual ledger entries — invoice values, payments, invoice period, running balance. |
| `party_ledger_monthly_closing` | Month-close records tracking which periods are locked (closed/reopened). |
| `party_account_activity_logs` | Append-only audit trail for all actions on party accounts. |
| `party_account_emails` | Secondary email addresses linked to each party (for duplicate detection and notifications). |
| `party_account_import_logs` | Records of bulk import operations with success/failure counts. |

**Setup order (run after database backup):**
1. `migrations/001_party_account_schema.sql` — core tables
2. `migrations/002_party_account_seed.sql` — optional sample loop entities
3. `migrations/003_rbac_roles_note.sql` — notes on RBAC provisioning (PHP roles, not DB rows)
4. `migrations/004_party_account_am_bm.sql` — AM/BM name columns
5. `migrations/005` through `011` — incremental schema updates (opening balance, emails, ledger, multi-currency)

> **Note:** The module also auto-provisions missing columns and tables at runtime via `PartyAccountSchemaService::ensure_schema()`. Migrations above are the authoritative baseline; the auto-provisioning adds safety for incremental deployments.

---

## 4. Getting Started — Setup

1. **Apply migrations** — Run all SQL migration files in order through your MySQL client.
2. **Verify tables** — Confirm `loop_entities`, `party_accounts`, and `party_account_activity_logs` exist.
3. **Assign user role** — Set at least one user's role to Finance (or Admin) in the `users` table.
4. **Log in** — Open the application — Party Account appears in the sidebar for Finance/Admin roles.
5. **Verify assets** — Confirm `public/assets/css/pages/party-account.css` and `public/assets/js/pages/party-account.js` are deployed.
6. **Create loop entities** — In the Party Account workspace, use the '+ Add branch' button to create at least one Loop Entity (company branch) for classification.

**Production checklist:**
- [ ] Apply all migrations to the production database.
- [ ] Create or update a Finance user (`users.role = 'Finance'`).
- [ ] Log in, open sidebar → **Party Account**, confirm grid loads.
- [ ] Verify `/var/www/.../storage/logs/php-error.log` has no new errors.
- [ ] Confirm mail cron is unaffected (module uses dedicated tables only).

---

## 5. Party Accounts Workspace

**URL:** `/modules/party_account/index.php`

The main workspace displays all party accounts in a sortable, filterable data table. The view adapts based on your role:

| UI Element | Available To | Description |
|-----------|-------------|-------------|
| + Add party account | Admin, Finance | Opens the profile modal to create a new party. |
| Import party accounts | Admin, Finance | Opens the bulk import modal (CSV/XLSX). |
| Export CSV | Admin, Finance | Downloads all visible/filtered accounts as a CSV file. |
| Party Ledger | Admin, Finance | Navigates to the Party Ledger workspace. |
| Advanced filters | All roles | Filter by status, loop entity, country, currency, creation date range. |
| Search bar | All roles | Full-text search across name, email, phone, bank, account holder, country, currency. |
| Scope selector | All roles | Show Live accounts / Archived only / All records. |
| Row selection + Archive/Restore | Admin, Finance | Select multiple rows and bulk archive or restore. |
| Sort columns | All roles | Click column headers: Party, Contact, Location, Finance, Updated. |

**KPIs displayed above the table:**

| KPI | Description |
|-----|-------------|
| Matching parties | Total number of records matching current filters. |
| Active accounts | Number of live (`deleted_at IS NULL`) accounts. |
| Company net amount | Sum of all party balances (opening + ledger movement) across filtered records. |

---

## 6. Managing Party Accounts

Click '+ Add party account' or 'Edit' on an existing row to open the profile modal.

### 6.1 Contact Details
- **Party Name** (required): Legal/trade name of the business relationship.
- **Primary Email**: Main contact email. Used for duplicate detection.
- **Country** (required): Select from the global country catalog. Controls phone code prefix.
- **Primary Phone**: National number only (no country code prefix). Phone code auto-fills from country selection.
- **Additional Emails**: Optional secondary email addresses.
- **Registered Address**: Free-text address field.

### 6.2 Assignment
- **Loop Entity**: Select the company branch owning this party relationship. Use '+ Add branch' to create a new entity inline.
- **AM** (Assistant Manager): Name of the assigned assistant manager.
- **BM** (Business Manager): Name of the assigned business manager.
- **Payment Terms**: e.g. Net 30, Net 45, Due on receipt.
- **Account Status**: Active, Draft, Suspended, or Archived.

### 6.3 Banking Details
- **Bank Name**: Name of the financial institution.
- **Account Holder Name**: Name on the bank account.
- **Account Number**: Account or IBAN reference (stored securely, masked in detail view).
- **IFSC / SWIFT / Routing Code**: Bank routing identifier.
- **IBAN**: International Bank Account Number.

### 6.4 Credit & Currency
- **Credit Limit**: Maximum outstanding amount allowed for this party.
- **Currency**: Primary currency for single-currency parties.
- **Opening Balance**: Initial balance at the time of account creation.
- **Opening Balance Type**: Receivable (we will receive money) or Payable (we need to pay this party).
- **Enable Multi-Currency**: Toggle if this party needs separate balances in multiple currencies.

### 6.5 Internal Notes
Free-text notes for internal use only. Not visible to the party.

### 6.6 Archive / Restore
- **Archive**: Soft-delete using the bulk toolbar or individual actions. Sets `deleted_at` timestamp and status = `archived`.
- **Restore**: Reverses archive. Sets `deleted_at = NULL` and status = `active`.

---

## 7. Ledger & Transactions

**URL:** `/modules/party_account/ledger.php`

The Party Ledger provides a party-wise view of financial positions. It shows opening balance, current balance, transaction count, and last activity date.

**Ledger Filters:**

| Filter | Options |
|--------|---------|
| Party | Filter by specific party or view all. |
| Currency | Filter by currency (e.g. INR, USD, EUR). |
| From / To | Date range filter. |
| Balance Type | Receivable (positive), Payable (negative), or Zero. |

**Accessing a Party's Ledger:**
1. Click the 'Open Ledger' link in any Party Ledger row.
2. A drawer slides open showing the party's transaction history for the selected currency.

**Inside the Ledger Drawer:**

| Element | Description |
|---------|-------------|
| Party header | Shows party name, email, phone, status, and currency summary. |
| Transaction form | Add or edit ledger entries: invoice period, dates, invoice numbers, values, payments, notes. |
| Month tabs | Horizontal tab strip for each month (YYYY-MM). Click to navigate. |
| Transaction table | Rows of all transactions for the selected period with running balance and status. |
| Export buttons | Excel (CSV) and PDF export of the current ledger view. |
| Month summary | Opening and closing balance for each month shown below the tabs. |

**Ledger Fields Explained:**

| Field | Meaning |
|-------|---------|
| Invoice Period | `YYYY-MM` format. Groups transactions by billing month (e.g. 2025-06). |
| Transaction Date | Actual date the financial event occurred. |
| Customer Invoice No | Invoice number issued to the customer/the party we are billing. |
| Customer Invoice Value | Amount invoiced to the customer (credit — positive). |
| Vendor Invoice No | Invoice number from the vendor/the party we need to pay. |
| Vendor Invoice Value | Amount invoiced by the vendor (debit — positive liability). |
| Payment In | Money received FROM this party (reduces our receivable). |
| Payment Out | Money paid TO this party (increases our payable). |
| Net Balance | `Customer Invoice - Vendor Invoice - Payment In + Payment Out` (per row cumulative). |

**Running Balance Formula:**

```
Running Balance = Opening Balance + SUM(Customer Invoice - Vendor Invoice - Payment In + Payment Out)
```

A positive balance means Receivable; negative means Payable.

---

## 8. Party Transactions Explorer

**URL:** `/modules/party_account/party_transactions.php`

The Party Transactions page is a flat, searchable list of all ledger transactions across all parties. Use this for comprehensive reporting, reconciliation, and export.

**Key features:**

| Feature | Description |
|---------|-------------|
| KPI Cards | Total Transactions, Customer Invoice Value, Vendor Invoice Value, Payment In, Payment Out, Net Balance. |
| Filters | Party (multi-select), Currency, Date range, Open/Closed status, Search text. |
| Sortable columns | Transaction Date, Party Name, Currency, Invoice Period, Invoice Numbers, Values, Payments, Status, Created By. |
| Status badge | Green = Open (period not closed). Red = Closed (period locked by month-close). |
| Pagination | 10, 20, 50, or 100 rows per page with full navigation. |
| Export XLSX | Downloads a grouped multi-sheet Excel file — one sheet per party. |

---

## 9. Import & Export

### 9.1 CSV Import

Navigate to the Party Account workspace and click 'Import party accounts'. Accepted file types: `.csv` or `.xlsx`. Maximum 500 rows per file.

**Required columns:**
- `party_name` — The party's legal/trade name.
- `country` — Must match a country from the global catalog (e.g. 'India').

**Optional columns include:**
`party_email`, `party_phone`, `address`, `bank_name`, `account_holder_name`, `account_number`, `ifsc_swift_code`, `iban_number`, `credit_limit`, `currency`, `payment_terms`, `loop_entity_name`, `assistant_manager_name`, `business_manager_name`, `notes`, `status`, `opening_balance`, `opening_balance_type` (receivable|payable), `is_multi_currency` (TRUE/FALSE or 1/0)

**Import workflow:**
1. Download the sample template from the import modal.
2. Fill in party data and upload the file.
3. Click 'Preview' to scan for errors and duplicates.
4. Check 'Skip duplicate rows' to skip duplicates automatically.
5. Click 'Import rows' to commit.

Import errors are logged to `party_account_import_logs` with full detail.

### 9.2 CSV Export

Click 'Export CSV' in the workspace toolbar. Downloads all currently visible records (respecting filters and scope) as a UTF-8 CSV with BOM. Fields included: ID, Party Name, Email, Phone, Country, Loop Entity, AM, BM, Credit Limit, Currency, Payment Terms, Bank details, Status, Archived flag, Created At, Updated At, Notes.

### 9.3 Ledger Export

From the Party Ledger drawer:
- **Excel**: Downloads the selected ledger as a CSV file with party info header.
- **PDF**: Opens the ledger as a print-ready HTML page. Use your browser's Print/Save PDF feature.

### 9.4 Party Transactions Export (XLSX)

From the Party Transactions page, click 'Export XLSX'. The file contains one sheet per party with summary (total transactions, credit/debit, balances) and full transaction detail.

---

## 10. Multi-Currency Support

Enable multi-currency when a single party maintains accounts in multiple currencies. When enabled, the party's primary currency field on `party_accounts` is cleared, and per-currency balances are stored in `party_currency_ledgers`.

| Scenario | Behavior |
|----------|----------|
| **Single currency (default)** | Balance stored directly on `party_accounts.currency`. |
| **Multi-currency enabled** | `party_accounts.currency = NULL`. Each currency ledger in `party_currency_ledgers` has its own opening balance and type (receivable/payable). |
| **Transactions** | Every ledger transaction is tagged with a currency. Cannot move a transaction between currencies after creation. |
| **Ledger filtering** | When viewing a multi-currency party's ledger, a currency selector appears. |
| **Deprecated fields** | Old AM/BM user-link columns (`assistant_manager_id`, `business_manager_email`) were removed. |

---

## 11. Monthly Closing

Month closing locks a specific invoice period (YYYY-MM) for a party + currency combination. Once closed, new transactions cannot be added to that period, and existing transactions cannot be edited or deleted.

**Closing a month (from ledger view):**
1. Navigate to a party's ledger drawer.
2. Click the 'Close Month' action for the target period.
3. The system calculates the period's opening balance and closing balance.
4. A record is inserted into `party_ledger_monthly_closing` with status = `closed`.

**Reopening a month (Admin only):**
1. Only users with Admin role can reopen a closed month.
2. Click 'Reopen Month' in the ledger action bar.
3. The record is updated to status = `reopened` with `reopened_by` and `reopened_at` timestamps.

Closed periods are shown with a red 'Closed' badge in all transaction views.

---

## 12. System Files Reference

| File / Path | Role |
|-------------|------|
| `index.php` | Main workspace entry point. Bootstraps config, renders workspace view, injects JS config. |
| `ledger.php` | Party Ledger workspace entry. Loads `ledger_workspace` view. |
| `party_transactions.php` | Transaction explorer page with filters, KPI strip, sortable table, and XLSX export. |
| `config/bootstrap.php` | Bundles all module includes: constants, helpers, services, repositories. |
| `config/constants.php` | Global constants and helper functions (currencies, symbols, statuses, table check). |
| `middleware/require_party_account_access.php` | RBAC gate functions: `gate_view`, `gate_manage`, `gate_ledger`, `gate_ledger_admin`. |
| `models/PartyAccountRepository.php` | Data access layer for `party_accounts` with filters, pagination, currency ledgers, bulk ops. |
| `models/LoopEntityRepository.php` | Data access layer for `loop_entities` master data. |
| `services/PartyAccountService.php` | Business rules: create, update, soft-delete, restore, bulk archive/restore, email sync. |
| `services/PartyAccountImportService.php` | Bulk import: file parsing, preview, duplicate detection, commit, import logging. |
| `services/PartyLedgerService.php` | Ledger calculations: opening balance, running balance, monthly summary, close/reopen, CRUD for transactions. |
| `services/PartyAccountActivityLogService.php` | Audit logging: inserts into `party_account_activity_logs` with actor, IP, user agent. |
| `services/LoopEntityService.php` | Business rules for loop entity CRUD. |
| `services/PartyAccountSchemaService.php` | Auto-provisions tables and columns on first use. Migration helper. |
| `views/workspace.php` | Main workspace HTML: toolbar, filters, KPIs, data table, add/edit modal, import modal. |
| `views/ledger_workspace.php` | Ledger page HTML: party list, drawer with transaction form and ledger table. |
| `ajax/datatable.php` | POST endpoint: returns paginated + filtered party account data for the workspace grid. |
| `ajax/account.php` | POST endpoint: detail, create, update, delete (archive), restore actions. |
| `ajax/bulk.php` | POST endpoint: bulk archive and bulk restore for selected party account IDs. |
| `ajax/export.php` | POST endpoint: streams CSV export of party accounts. |
| `ajax/import.php` | POST endpoint: template download, preview, and commit for CSV/XLSX imports. |
| `ajax/ledger.php` | POST endpoint: ledger list, detail (with transaction rows and months), save/delete transaction, close/reopen month. |
| `ajax/ledger_export.php` | GET endpoint: exports ledger as CSV or PDF (printable HTML). |
| `ajax/loop_entities.php` | GET/POST endpoint: list active entities, CRUD for loop entities (with manage filter). |
| `helpers/http.php` | HTTP utilities: `is_xhr`, `read_json_body`, `json_exit`. |
| `helpers/validation.php` | Payload validation and normalization functions. |
| `helpers/emails.php` | Email collection, sync, deduplication for `party_account_emails` table. |
| `helpers/import.php` | File parsing (CSV/XLSX reader), row-to-payload conversion for imports. |
| `helpers/phone_countries.php` | Global country catalog with dial codes, phone length rules, and flag URLs. |
| `helpers/transaction_export.php` | XLSX export builder for party transactions (multi-sheet grouping). |

---

## Technical Notes

### API-Ready Architecture
All JSON handlers live under `ajax/` and can be routed behind versioning (e.g. `/api/v1/party_accounts`) without rewriting business logic in `services/`.

### Security
All mutating AJAX endpoints require CSRF token validation. Read endpoints use standard cookie-based session auth. Row-level email uniqueness is enforced in `PartyAccountService` to prevent duplicate parties.

### Audit Trail
Every state-changing operation writes an entry to `party_account_activity_logs`. Fields: `party_account_id`, `actor_user_id`, `actor_name`, `action`, `summary`, `metadata` (JSON), `ip_address`, `user_agent`, `created_at`.

### Logs
Import operations are logged in `party_account_import_logs` with per-row error detail. PHP errors unrelated to this module continue to log in the normal `storage/logs/php-error.log`.
