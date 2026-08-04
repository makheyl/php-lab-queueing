# Laboratory Queueing System — Operations Guide

Day-to-day procedures for encoders, phlebotomists, and whoever's on duty
when something goes wrong. For schema/setup details see
`README_DATABASE_SETUP.md`. For conventions/architecture see `CLAUDE.md`.

## Daily opening procedure

1. Confirm XAMPP's Apache and MySQL services are running (XAMPP Control
   Panel, or `net start Apache2.4` / `net start mysql` if running as
   Windows services).
2. Open `display.php` on the kiosk screen/TV in the waiting area. Leave it
   open — it's designed to run untouched all day and reset itself
   automatically overnight (see "Display board" below).
3. Encoder opens `index.php` and sets their name and interview station
   number in the header. This only needs doing once per shift — see
   "Session behavior" below.
4. The phlebotomist opens `extraction.php` and sets their name (there's
   only one extraction station, so nothing else to set).
5. Confirm `settings.announcement` (editable via the `settings` table,
   there's no UI for it yet) says whatever the clinic wants scrolling on
   the kiosk footer that day, or leave it blank.

Numbers don't need resetting — `queue.queue_number` is scoped per
`service_date`, so the first number added each service day starts the
count fresh automatically.

## Daily closing procedure

There is no manual close step. `daily_reset.php` is scheduled (Windows Task
Scheduler, `Lab Queueing Daily Reset`, daily at 4:10 AM) to archive the
day's stats, export the month's CSV, and auto-cancel any ticket still stuck
mid-flow when the service day rolls over. If the clinic is closing early or
a shift is ending:

- It's fine to just log off / close the browser tabs — nothing needs
  saving, everything already lives in the database.
- If a ticket is genuinely being abandoned for the day (patient left,
  won't return), cancel it explicitly from `index.php`/`extraction.php`
  rather than leaving it active — `daily_reset.php` will auto-cancel
  anything still non-terminal at rollover anyway, but an explicit cancel
  with a reason is more useful later than "auto-cancelled by daily_reset."

## Session behavior (encoder / extraction screens)

These screens are meant to stay open for an entire shift. If the browser's
PHP session ever expires or gets dropped mid-shift, you will NOT lose the
active ticket — ticket state lives in the database, not in the session.
The page will simply re-show the "set your name and station" prompt inline
at the top; re-enter your name/station and everything (the active ticket,
the queues) picks back up exactly where it was. Nothing needs to be redone.

## If the display board freezes or goes blank

`display.php` is built to run for 12+ hours without a manual reload — it
polls `queue_status.php` every 3 seconds and reloads itself the moment
anything changes (including at the overnight service-date rollover). If it
still looks stuck:

1. **Check the obvious first**: is the screen itself asleep/off, or is the
   browser actually frozen? A hard refresh (Ctrl+F5) is always safe.
2. **Check Apache/MySQL are still running** on the server machine (XAMPP
   Control Panel). If Apache or MySQL crashed, every screen will be stuck,
   not just the display board.
3. **Check the browser console** (F12) for repeated fetch errors — usually
   means the server machine is unreachable over the network, not a
   display.php bug specifically.
4. **If only the announcement voice seems stuck** (numbers update but
   nothing is spoken): check `notify.json` at the web root isn't stuck with
   stale content — `clear_notify.php` resets it to `{}`; hitting it once
   manually is harmless and safe to do.
5. **Last resort**: reload the tab. You will not lose any queue state —
   the board is entirely read-only and re-derives everything from the
   database on load.

## Correcting a mis-marked number

There's no "undo" button in the UI by design (every action is a direct,
guarded database transition) — corrections are made directly in the
database via phpMyAdmin or the `mysql` CLI. Always scope corrections by
both `id` (not just `queue_number`, which can repeat across days) and
double-check `service_date` before running an UPDATE.

**Wrong decision at interview (FOR PAYMENT vs NO CHARGE clicked by mistake)**

```sql
-- Find the ticket first
SELECT id, queue_number, status, payment_required FROM queue
WHERE service_date = CURDATE() AND queue_number = <N>;

-- If it's still awaiting_payment and should have been no-charge:
UPDATE queue SET status = 'ready_for_extraction', payment_required = 0,
    extraction_eligible_at = NOW(6)
WHERE id = <id> AND status = 'awaiting_payment';

-- If it's ready_for_extraction/completed and should have required payment,
-- this needs a judgment call by whoever's on duty — the patient may need
-- to be sent to pay after the fact. There's no clean automatic fix here.
```

**Wrong queue_number typo caught immediately (ticket still `waiting`)**

```sql
UPDATE queue SET queue_number = <correct_number>
WHERE id = <id> AND status = 'waiting';
-- Will fail on the unique (service_date, queue_number) key if the correct
-- number is already taken today — check first.
```

**Accidentally marked no-show / cancelled, patient is actually still here**

- If it was `ready_for_extraction` before the no-show: use `extraction.php`
  — there's no reinstate button in the UI yet, so this is a direct
  correction:
  ```sql
  UPDATE queue SET status = 'ready_for_extraction', extraction_eligible_at = NOW(6)
  WHERE id = <id> AND status = 'no_show';
  ```
  (This intentionally puts them at the back of the extraction queue with a
  fresh timestamp — same rule as the app's own `reinstate()` logic.)
- If it was cancelled by mistake and they were `waiting`/`interviewing`:
  ```sql
  UPDATE queue SET status = 'waiting' WHERE id = <id> AND status = 'cancelled';
  ```

After any manual correction, log it:

```sql
INSERT INTO lab_activity_log (staff_name, station, queue_number, action)
VALUES ('<your name>', 0, <queue_number>, 'manual_correction');
```

## A patient who lost their physical number

This system tracks queue numbers only — no names, no patient IDs — so
there is no built-in "look up by name" recovery path. Practical steps, in
order:

1. Ask the encoder to open **View Full List** on `index.php`'s waiting
   panel — it shows every currently-waiting number with how long each has
   been waiting. Ask the patient roughly when they took a number; cross-
   reference against the wait times shown.
2. If they were already interviewed and are now away paying or waiting for
   extraction, check the **Payment Confirmation** panel or `extraction.php`'s
   queue the same way — approximate wait time can often narrow it down to
   one or two candidates.
3. If they genuinely can't be identified this way, the safest option is to
   **issue a new number** via `index.php`'s Add to Queue. Their old ticket
   (if any) will simply sit in whatever status it was in and eventually be
   auto-cancelled by `daily_reset.php` at rollover — it will not conflict
   with the new number.
4. If this happens often enough to be a real problem, that's a signal the
   system needs a name/contact field added to `queue` — worth raising as a
   feature request rather than working around indefinitely.

## Access

`index.php`, `extraction.php`, and `admin.php` have no login/PIN — same as
the rest of this app (see CLAUDE.md §7), access control is network
isolation (LAN-only deployment), not an in-app gate. `display.php` was
never gated either way.

## `clear_queue.php` — do not visit this URL casually

This deletes **every row in `queue`**, every service date, every status,
immediately — it is not the same as `daily_reset.php`'s controlled
archive/purge. It's meant to be run from Task Scheduler only, but like
every file here it's reachable by URL. Visiting it in a browser now shows a
warning and requires typing `DELETE ALL` before anything happens — it will
NOT delete on a plain page load. Running it from the CLI (Task Scheduler)
skips the prompt by design, so scheduled runs still work unattended. If you
don't recognize why someone would need this, don't type the confirmation
phrase — ask first.
