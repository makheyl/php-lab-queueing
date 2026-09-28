# Laboratory Queueing System — Operations Guide

Day-to-day procedures for encoders, phlebotomists, and whoever's on duty
when something goes wrong. For schema/setup details see
`README_DATABASE_SETUP.md`. For conventions/architecture see `CLAUDE.md`.

## Screens at a glance

Every staff screen has the same green top menu: **Front Desk · Claiming ·
Extraction · Reports · Printer**. Each screen does one job and shows the
next thing to press as the biggest button. The letter on a button (`N`,
`P`, `C`, `R`, `S`, `T`) is its keyboard shortcut.

| Screen | File | Who | What it's for |
|---|---|---|---|
| Front Desk | `index.php` | Encoder | Print numbers (ticket PC only), call and interview patients, confirm payments when patients come back from City Hall |
| Claiming | `claiming.php` | Encoder / lab staff | Names of patients whose results are ready to pick up |
| Extraction | `extraction.php` | Phlebotomist | Call, complete, call again, or mark No Show |
| Reports | `admin.php` | Supervisor | Today / This Week / This Month numbers, activity log, CSV export |
| Printer | `printer_setup.php` | Whoever sets up the ticket PC | Ticket printer settings, Test Print, setup checklist |
| Display board | `display.php` | Waiting area TV | Read-only; calls numbers out loud |

**Front Desk in short:** press **CALL NEXT**, then **FOR PAYMENT** (patient
pays at City Hall first) or **NO CHARGE** (straight to extraction). When a
patient comes back with their receipt, tap their number under **Back from
Payment** and confirm (the OR number is optional). **Call again**, **No
show**, and **Add note** are the smaller buttons under the patient's number.

Screens refresh themselves when the queue changes, but never while you are
typing or have a pop-up open — they wait until you're done.

## Daily opening procedure

1. Confirm XAMPP's Apache and MySQL services are running (XAMPP Control
   Panel, or `net start Apache2.4` / `net start mysql` if running as
   Windows services).
2. On the waiting-area TV PC, open the display board from the "Lab Queue –
   Display Board" shortcut (see the Printer screen → "Display Board PC").
   It runs full screen and can speak without anyone tapping it. Leave it
   open — it's designed to run untouched all day and reset itself
   automatically overnight (see "Display board" below). If it shows "Tap
   anywhere to turn on voice announcements", tap it once.
3. On the ticket station PC, switch on the XP-80T and check the paper (see
   "Ticket printer (XP-80T)" below). Open the Front Desk from the
   "Lab Queue – Front Desk" desktop shortcut, not a normal browser window.
4. The encoder types or picks their name on the "Who's at the front desk?"
   card. There is only one interview window, so there's no window number
   to set. This only needs doing once per shift — see "Session behavior"
   below.
5. The phlebotomist opens Extraction and picks their name the same way
   (there's only one extraction station, so nothing else to set).
6. Confirm `settings.announcement` (editable via the `settings` table,
   there's no UI for it yet) says whatever the clinic wants scrolling on
   the kiosk footer that day, or leave it blank.

Numbers don't need resetting — PRINT NEXT NUMBER issues today's highest
number + 1, and `queue.queue_number` is scoped per `service_date`, so the
first ticket each service day is number 1 automatically.

## Daily closing procedure

There is no manual close step. `daily_reset.php` is scheduled (Windows Task
Scheduler, `Lab Queueing Daily Reset`, daily at 4:10 AM) to archive the
day's stats, export the month's CSV, and auto-cancel any ticket still stuck
mid-flow when the service day rolls over. If the clinic is closing early or
a shift is ending:

- It's fine to just log off / close the browser tabs — nothing needs
  saving, everything already lives in the database.
- If a patient being served has left and won't return, mark them **No
  show** on the Front Desk or Extraction screen rather than leaving the
  ticket active — `daily_reset.php` will auto-cancel anything still
  non-terminal at rollover anyway, but an explicit No Show is more useful
  later than "auto-cancelled by daily_reset."

## Ticket printer (XP-80T)

Queue numbers are no longer typed. The encoder clicks **PRINT NEXT NUMBER**
(or presses `T`) at the top of the Front Desk. The server issues the next number for the
service day (today's highest + 1, starting again at 1 after the 4 AM
rollover), and the XP-80T prints it. The number on the ticket is always the
number the display board shows and the voice calls.

**How it's wired.** The XP-80T is plugged by USB into the *ticket station
PC* (the encoder PC it sits next to), not into the XAMPP server. That PC's
browser prints the ticket: Chrome is started with `--kiosk-printing`, which
prints straight to the default printer without a dialog. Nothing
printer-related is installed on the server. Only the browser marked as the
ticket station on `printer_setup.php` shows PRINT NEXT NUMBER. Other encoder
PCs can't issue numbers, so nobody can print a ticket on the wrong printer
by accident.

### One-time setup

Follow the checklist on the **Printer** screen (`printer_setup.php`, in the
top menu). It shows the exact Chrome shortcut for this server. In short:

1. Install the Xprinter driver. Set the paper size to the 80 mm roll (e.g.
   "80(72.1) x 3276 mm") and the cutter to cut after each document.
2. Make XP-80T the Windows default printer, with "Let Windows manage my
   default printer" turned OFF.
3. Create the "Lab Queue – Front Desk" Chrome shortcut with
   `--kiosk-printing --user-data-dir=...`.
4. Open the shortcut, mark the PC as the ticket station, and run Test Print.

### Loading paper

Use an 80 mm thermal roll (79.5 ± 0.5 mm). Open the cover and drop the roll
in with the coated side facing the print head. To find the coated side,
scratch the paper with a fingernail: the coated side turns dark. Pull a few
centimeters out and close the cover until it clicks.

### When a ticket doesn't print

The number is issued as soon as PRINT NEXT NUMBER is clicked, whether or not
paper comes out. **Don't click it again.** Fix the printer, then use
**Reprint #N** in the ticket bar (or tap the number in the Waiting list).

1. Check the paper, the cover (fully closed?), the power, and the USB cable.
2. Open the Windows print queue: Settings → Printers & scanners → XP-80T →
   Open print queue. If jobs are stuck, cancel them *before* fixing the
   printer. Otherwise every stuck ticket prints at once when the printer
   comes back. Then reprint only what's needed.
3. If a print dialog appeared, the Front Desk wasn't opened from the shortcut.
   Close every window of that Chrome profile and reopen it from the shortcut.
4. If the ticket came out of a different printer, Windows changed the
   default printer. Set XP-80T as the default again and check that "Let
   Windows manage my default printer" is OFF.
5. If the right edge is cut off, lower **Print width** on the Printer
   screen (e.g. to 70).

### If the printer is down for a while

On the Printer screen, untick **Ticket printing is ON** and click Save. The button
changes to ISSUE NEXT NUMBER (printing off). Numbers are still issued in
order and shown on screen; write each one on a slip for the patient. Turn
printing back on when the printer is fixed.

### Reprinting

**Reprint #N** in the ticket bar reprints the most recent ticket. To reprint
any other waiting ticket (for example, a patient lost theirs), tap its number
in the **Waiting** list and choose **Reprint ticket**. Reprinted tickets say
REPRINT, and each reprint shows as "Ticket reprinted" in the Reports activity
log. Every issued number shows there as "Number issued".

## Session behavior (Front Desk / Claiming / Extraction)

These screens are meant to stay open for an entire shift. If the browser's
PHP session ever expires or gets dropped mid-shift, you will NOT lose the
active ticket — ticket state lives in the database, not in the session.
The page will simply show the "Who's working?" name card again; pick your
name and everything (the active ticket, the queues) picks back up exactly
where it was. Nothing needs to be redone. To hand over the desk mid-shift,
click **change** next to the name in the top-right corner.

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
4. **If numbers update but nothing is spoken**:
   - Check the PC's speakers and volume (and that the browser tab isn't muted).
   - If the board shows "Tap anywhere to turn on voice announcements", tap
     it. Browsers keep sound off until someone taps the page; opening the
     board from the "Lab Queue – Display Board" shortcut
     (`--autoplay-policy=no-user-gesture-required`) avoids this entirely.
   - Every call is announced in order, with a short pause between calls, so
     several numbers called at once take a few seconds to get through.
   - `notify.json` keeps the last 20 calls (see `announce_call()` in
     `queue_functions.php`); boards only read it, so there is nothing to
     clear. Several display boards can run at once and all of them speak.
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

**A number issued by mistake (ticket still `waiting`, nobody holds it)**

Numbers are issued by PRINT NEXT NUMBER, so typos can't happen anymore. If
a ticket was issued that no patient will use (for example, an extra click),
cancel it so it's never called:

```sql
UPDATE queue SET status = 'cancelled'
WHERE id = <id> AND status = 'waiting';
```

Don't renumber tickets: the printed number must keep matching the queue.

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

1. Look at the **Waiting** list on the Front Desk — it shows every
   currently-waiting number with how many minutes each has waited (tap
   "+N more" if the list is long). Ask the patient roughly when they took a
   number; cross-reference against the wait times shown.
2. If they were already interviewed and are now away paying or waiting for
   extraction, check the **Back from Payment** numbers (minutes away) or the
   Extraction screen's **Next in Line** the same way — approximate timing
   can often narrow it down to one or two candidates. **Can't find the
   number?** under Back from Payment tells you where any number currently is.
3. Once their number is identified and they're still waiting, the ticket
   station can print it again: tap the number in the Waiting list →
   **Reprint ticket**.
4. If they genuinely can't be identified this way, the safest option is to
   **issue a new number** with PRINT NEXT NUMBER. Their old ticket (if any)
   will simply sit in whatever status it was in and eventually be
   auto-cancelled by `daily_reset.php` at rollover — it will not conflict
   with the new number.
5. If this happens often enough to be a real problem, that's a signal the
   system needs a name/contact field added to `queue` — worth raising as a
   feature request rather than working around indefinitely.

## Access

`index.php`, `claiming.php`, `extraction.php`, `admin.php`, and
`printer_setup.php` have no login/PIN — same as the rest of this app (see CLAUDE.md §7), access control
is network isolation (LAN-only deployment), not an in-app gate.
`display.php` was never gated either way. The ticket station mark is a
browser cookie, not a security control: it only decides which PC shows
PRINT NEXT NUMBER.

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
