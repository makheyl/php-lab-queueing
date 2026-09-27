# Laboratory Queueing System - Database Setup & Daily Reset

## Overview
This is the data layer for the Laboratory Queueing System: a ticket walks
through interview → optional payment-at-city-hall detour → blood
extraction. Same stack as the rest of this repo — plain PHP, mysqli, no
framework, no Composer. See `CLAUDE.md` at the repo root for full
conventions.

## Database Tables

### 1. `queue`
The main table. One row per ticket per service day.

```sql
CREATE TABLE IF NOT EXISTS `queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_date` date NOT NULL,
  `queue_number` int(11) NOT NULL,
  `status` enum('waiting','interviewing','awaiting_payment','ready_for_extraction','extracting','completed','no_show','cancelled') NOT NULL DEFAULT 'waiting',
  `payment_required` tinyint(1) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `interview_called_at` datetime DEFAULT NULL,
  `interview_completed_at` datetime DEFAULT NULL,
  `interview_station` int(11) DEFAULT NULL,
  `interview_by` varchar(100) DEFAULT NULL,
  `payment_confirmed_at` datetime DEFAULT NULL,
  `payment_confirmed_by` varchar(100) DEFAULT NULL,
  `payment_reference` varchar(50) DEFAULT NULL,
  `extraction_eligible_at` datetime(6) DEFAULT NULL,
  `extraction_called_at` datetime DEFAULT NULL,
  `extraction_completed_at` datetime DEFAULT NULL,
  `extraction_station` int(11) DEFAULT NULL,
  `extraction_by` varchar(100) DEFAULT NULL,
  `recall_count` int(11) NOT NULL DEFAULT 0,
  `no_show_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_service_date_queue_number` (`service_date`, `queue_number`),
  KEY `idx_service_date_status_extraction_eligible` (`service_date`, `status`, `extraction_eligible_at`),
  KEY `idx_service_date_queue_number` (`service_date`, `queue_number`)
);
```

`extraction_eligible_at` is the sort key for the extraction queue — NEVER
`queue_number`. It's `NULL` while a patient is out paying at city hall (not
in the extraction queue yet), and gets stamped at interview completion for
no-charge patients or at payment confirmation for paying patients. It's
`datetime(6)` (microsecond precision) deliberately: two rows stamped with
plain-second `NOW()` in the same second would tie, and any tiebreak that
falls back to `queue_number` would silently reintroduce queue-number
ordering — exactly what this column exists to prevent. Always write it with
`NOW(6)`.

### 2. `lab_activity_log`
Same shape as OPD's `doctor_appointments_log`, generalized to staff/station.

```sql
CREATE TABLE IF NOT EXISTS `lab_activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_name` varchar(100) NOT NULL,
  `station` int(11) NOT NULL,
  `queue_number` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `log_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
);
```

### 3. `daily_statistics`
Per (date, station, staff_name) running counts. Cleared each day by
`daily_reset.php` after being archived into `historical_data`.

```sql
CREATE TABLE IF NOT EXISTS `daily_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `station` int(11) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `no_charge_count` int(11) DEFAULT 0,
  `for_payment_count` int(11) DEFAULT 0,
  `no_show_count` int(11) DEFAULT 0,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_station_staff` (`date`, `station`, `staff_name`)
);
```

### 4. `historical_data`
Archived copy of `daily_statistics`, plus CSV export tracking.

```sql
CREATE TABLE IF NOT EXISTS `historical_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `station` int(11) NOT NULL,
  `staff_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `no_charge_count` int(11) DEFAULT 0,
  `for_payment_count` int(11) DEFAULT 0,
  `no_show_count` int(11) DEFAULT 0,
  `exported_to_csv` tinyint(1) DEFAULT 0,
  `export_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_station_staff` (`date`, `station`, `staff_name`)
);
```

### 5. `settings`
Flat key/value config, read via `get_setting()` in `queue_functions.php`.

```sql
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(50) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`key`)
);
```

| Key | Default | Meaning |
|---|---|---|
| `queue_prefix` | `L` | Display prefix for queue numbers |
| `daily_reset_hour` | `4` | Hour (0-23) the service day rolls over at — see `service_date_now()` |
| `flash_duration_seconds` | `10` | How long a newly-called number flashes on `display.php` |
| `recall_limit` | `3` | Max recalls before a ticket should be marked no-show |
| `announcement` | *(empty)* | Free-text line scrolled in `display.php`'s footer |
| `queue_retention_days` | `30` | `daily_reset.php` purges `queue` rows older than this; `0` disables purging |
| `ticket_printing_enabled` | `1` | `0` = PRINT NEXT NUMBER still issues numbers but prints nothing (printer down) |
| `ticket_header` | `CITY HEALTH OFFICE` | First line of the printed ticket |
| `ticket_subheader` | `LABORATORY` | Second line of the printed ticket |
| `ticket_footer` | *(keep-this-ticket note)* | Text under the number; line breaks are kept |
| `ticket_show_logo` | `1` | Print `CHO.png` at the top of the ticket |
| `ticket_show_waiting` | `1` | Print "N people waiting ahead of you" |
| `ticket_width_mm` | `72` | Ticket print width (48-80 mm); 72 fits the XP-80T's printable area |

The `ticket_*` settings are edited on `printer_setup.php`; the rest are
edited directly in the table.

### 6. `claimable_results`
"Ready for Claiming" entries: patients whose results from a prior visit are
ready for pickup. Standalone data entry, not tied to a queue ticket, and
listed across service days until claimed (see `get_claimable_results()`).

```sql
CREATE TABLE IF NOT EXISTS `claimable_results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_date` date NOT NULL,
  `surname` varchar(100) NOT NULL,
  `first_name_initials` varchar(20) NOT NULL,
  `added_by` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `claimed_at` datetime DEFAULT NULL,
  `claimed_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_claimed_created` (`claimed_at`, `created_at`)
);
```

## Setup Instructions

### 1. Create the database and run the setup SQL

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS labqueue;"
mysql -u root labqueue < database_setup.sql
```

### 2. Verify

```bash
C:\xampp\php\php.exe test_database.php
C:\xampp\php\php.exe test_queue_logic.php
```

Both print `✓`/`✗` lines and exit non-zero on failure.

### 3. Schedule the daily reset

`daily_reset.php` is a CLI script — it is never hit over HTTP. Schedule it
to run shortly after `settings.daily_reset_hour` (default 4 AM), so the
service day has already rolled over by the time it runs.

#### Windows Task Scheduler (this deployment):
1. Open Task Scheduler
2. Create Basic Task
3. Name: `Lab Queueing Daily Reset`
4. Trigger: Daily at `4:10:00 AM`
5. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\PHPLabQueueing\daily_reset.php`
   - Start in: `C:\xampp\htdocs\PHPLabQueueing`

#### Linux Cron (if ever deployed there):
```bash
10 4 * * * /usr/bin/php /path/to/PHPLabQueueing/daily_reset.php
```

`daily_reset.php` archives the previous service day's `daily_statistics`
into `historical_data`, exports that month to `report_month_YYYY_MM.csv`,
clears `daily_statistics`, auto-cancels any ticket still stuck in a
non-terminal status from the day that just closed, and purges old `queue`
rows per `queue_retention_days`. Numbering does not need resetting — it's
scoped per `service_date` by the table's unique key.

## File Structure

```
PHPLabQueueing/
├── config.php              # DB connection (labqueue) + Asia/Manila timezone — connection only, no migrations
├── database_setup.sql      # All table definitions + settings seed — source of truth for the schema
├── queue_functions.php     # Shared queue-transition logic (prepared statements throughout)
├── test_database.php       # CLI: verifies schema + settings seed
├── test_queue_logic.php    # CLI: verifies queue_functions.php behavior end-to-end
├── daily_reset.php         # CLI: scheduled daily rollover housekeeping
├── index.php                # Front Desk (print numbers, interview, confirm payments on return)
├── claiming.php             # Claiming (results ready for pickup: search, add, mark claimed)
├── ticket_print.php         # One 80 mm ticket, printed by the ticket station's browser (read-only)
├── printer_setup.php        # Printer: ticket station, ticket settings, Test Print, checklist
├── extraction.php           # Extraction station
├── admin.php                 # Reports
├── app_header.php            # Shared green header + top menu for the staff screens
├── display.php                # Public kiosk board
├── queue_status.php            # JSON polling endpoint (counts + last_update)
├── clear_notify.php             # Legacy: resets notify.json to '{}' (display.php no longer calls it)
├── notify.json                   # Runtime state file — last 20 voice announcements (announce_call())
├── assets/theme.css                # Shared theme for the staff screens
├── assets/app.js                   # Shared staff-screen behavior: auto-refresh that waits
│                                     while someone is typing, pop-ups, keyboard shortcuts
├── assets/display.css               # Kiosk theme (display.php) — OPD's original rules are
│                                      untouched; lab additions are appended at the bottom
└── report_month_YYYY_MM.csv          # Generated monthly export, not source
```

## Usage

All staff screens share one top menu (Front Desk · Claiming · Extraction ·
Reports · Printer). See `OPERATIONS.md` "Screens at a glance".

### For encoders (Front Desk, `index.php`):
1. Type or pick your name on the "Who's at the front desk?" card (one
   interview window, so there's no window number)
2. On the ticket station PC, click PRINT NEXT NUMBER (or press `T`) for each
   arriving patient. The XP-80T prints the next number for today; see
   `OPERATIONS.md` "Ticket printer (XP-80T)" and `printer_setup.php`
3. CALL NEXT, then FOR PAYMENT or NO CHARGE to route the patient
4. When a patient returns from City Hall, tap their number under Back from
   Payment and confirm (OR number optional)

### For results claiming (`claiming.php`):
1. Search a surname to find a patient's name; tap Claimed when they pick up
2. "+ Add name" adds a patient whose results are ready

### For phlebotomists (`extraction.php`):
1. Type or pick your name (there's only one extraction station)
2. CALL NEXT, then COMPLETE; Call again / No show if the patient doesn't come

### For administrators (Reports, `admin.php`):
1. Pick Today / This Week / This Month (or Other day)
2. Review the summary, average times, busiest hours, and per-staff numbers
3. Show the activity log and search it by number, staff, or what happened
4. Export CSV or print

## Monitoring Queries

### Today's queue, by stage:
```sql
SELECT status, COUNT(*) FROM queue WHERE service_date = CURDATE() GROUP BY status;
```

### Extraction queue in true call order:
```sql
SELECT queue_number, extraction_eligible_at FROM queue
WHERE service_date = CURDATE() AND status = 'ready_for_extraction'
ORDER BY extraction_eligible_at ASC, queue_number ASC;
```

### Staff throughput for a month:
```sql
SELECT staff_name, SUM(patients_served) AS served
FROM historical_data WHERE date BETWEEN '2026-08-01' AND '2026-08-31'
GROUP BY staff_name;
```

## Troubleshooting

1. **Tables missing / wrong shape**: re-run `database_setup.sql` — every
   `CREATE TABLE` uses `IF NOT EXISTS`, so it's safe to re-run, but it will
   NOT fix an already-created table with the wrong column shape. Drop and
   recreate the database if the schema has drifted.
2. **`test_queue_logic.php` aborts before running**: it refuses to run if
   its scratch queue_numbers already exist for today — run it against a
   dev/empty database, or edit the scratch numbers at the top of the file.
3. **Extraction queue looks sorted by queue_number**: check
   `extraction_eligible_at` is actually `datetime(6)` and being written with
   `NOW(6)`, not `NOW()` — see the note on that column above.
4. **`daily_reset.php` doesn't fix "yesterday's" stragglers**: it uses
   `service_date_now()` to figure out which day just closed, so it needs to
   run *after* `daily_reset_hour`, not at midnight.
5. **CSV export is empty**: `historical_data` only has rows for days that
   have already been through `daily_reset.php` — a day that hasn't rolled
   over yet only has data in `daily_statistics`, which `admin.php`'s export
   reads directly (so same-day exports still work).
6. **Statistics not updating**: verify the `$db`/credentials in `config.php`
   and that `labqueue` is the database actually being hit.
