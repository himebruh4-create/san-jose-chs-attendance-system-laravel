# San Jose CHS Attendance System (Laravel)

Barcode attendance and Daily Time Record (DTR) system for San Jose Community High School personnel.
A Laravel 13 rewrite of the native-PHP system (`san-jose-chs-attendance-system`), with the same screens,
the same attendance rules and all of its data.

## Requirements

- PHP 8.3 or newer with `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `openssl` (XAMPP with PHP 8.3+ works; XAMPP 8.2 does not)
- MySQL 8 or MariaDB 10.4+
- Composer (only to install; no Node.js or build step is needed)

Everything the pages load (icons, Chart.js, JsBarcode) is in `public/vendor`, so the system works with no internet.

## Install

```bash
composer install
copy .env.example .env          # then edit DB_* and APP_URL
php artisan key:generate
php artisan migrate
```

Then either:

- **Bring over the old system's data** (the old database must be on the same MySQL server, default name `san_jose_chs`):

  ```bash
  php artisan legacy:import            # add --fresh to replace data already imported
  ```

  Every legacy row is copied with its ID, and the command fails (changing nothing) unless every row is accounted
  for. Attendance rows of personnel that no longer exist go to `archived_attendance` with all their columns.
  Existing passwords keep working.

- **Or start empty**: `php artisan db:seed`, then open `http://localhost/setup` **on the server PC itself** to create
  the first Super Admin (or run `php artisan account:create-superadmin you@example.com`).

`php artisan legacy:parity` compares the ported attendance rules with the original PHP code on the real data
(15,779 checks on the 2026-10-05 data, all identical).

## Setting up the kiosk

Only registered browsers can record scans.

1. On the kiosk PC, log in as Super Admin.
2. **Settings → Kiosk Devices → Register this browser as a kiosk.**
3. Log out and open `/kiosk`. Revoke a kiosk from the same card.

**Barcode scanner:** a USB scanner in keyboard mode, set to read **Code 39** (the symbology printed on the ID
badges) and to send **Enter** after each code. The scan box stays focused and is cleared after every scan.

**Webcam:** the kiosk takes a photo with each scan (accepted or refused). Browsers only allow the camera on
`http://localhost` or `https://`, so either run the kiosk on the server PC itself or serve the system over HTTPS.
Without a camera, scans are still recorded and marked "no photo". Photos are kept for 90 days, are only visible
to the Super Admin (in the Attendance Adjustments form), and are stored outside the web root. Post a notice at
the kiosk: these photos are personal data under the Data Privacy Act (RA 10173).

## Scheduled tasks

Create a Windows Task Scheduler task (or a cron entry) that runs every minute:

```
php C:\path\to\sjchs-attendance-laravel\artisan schedule:run
```

It runs `backup:run` daily at 18:30 (keeps the newest 30 verified backups in `storage/app/private/backups`)
and `attendance:prune-scan-photos` daily at 02:00. Copy backups off the server regularly.

## Tests

```bash
# needs an empty MySQL database named sjchs_attendance_test
php artisan test
```

## What changed from the native-PHP system

Security
- Every page and data endpoint checks the role; Admin's monthly report, the DTR data endpoints, live logs and
  weekly summaries no longer answer without a login.
- Removed: the public barcode lookup (`get-teacher-barcode.php`), the SQL-injectable `teacher-crud.php`, the
  unauthenticated auto-absent script, and other unused files.
- Scans only from registered kiosk devices; repeated unknown barcodes pause the kiosk; the kiosk's "view my DTR"
  is enforced by the server, not the browser.
- CSRF protection on every form and POST; session ID regenerated at login; login locks for 5 minutes after 5
  failures; the security-question step allows 5 tries per 15 minutes; changing a password signs out other sessions.
- First Super Admin is created on a localhost-only setup page instead of an environment variable.
- Output escaped everywhere (several pages inserted names into HTML unescaped); security headers on every response.
- Personnel photos and kiosk photos are re-encoded and stored privately, served only to authorized users.
- Audit log of logins and every change (Settings → Audit Log); adjustments record who approved them.

Data
- `attendance` has a primary key, one unique key on (teacher_id, date), a foreign key, TIME columns, and no legacy
  columns; permanently deleting a person moves their attendance to `archived_attendance` instead of orphaning it.
- Names keep the casing typed (the old lower-case/re-capitalize step turned "II" into "Ii").

Fixes
- Kiosk: a failed scan no longer leaves its barcode in the box (the next person's scan re-sent it); network
  errors are shown instead of silently ignored.
- Attendance Report: no strict-mode GROUP BY crash; pagination keeps the month and search.
- Inline buttons no longer break on names with apostrophes; print templates load assets locally.
- Barcodes render as Code 39 on every page.
