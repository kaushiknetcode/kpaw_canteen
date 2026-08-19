# KPAW Canteen Portal

Phase 0 skeleton — see `docs/MASTER-PLAN.md` for the full phase-wise build plan. Point Devin at that file for context before starting any phase.

## Folder structure

```
app/          → employee-facing pages (Phase 3+)
counter/      → receptionist scan/serve screens (Phase 7)
admin/        → admin dashboard modules (Phase 5, Phase 8)
auth/         → registration, login, OTP, password reset (Phase 2)
includes/     → shared PHP: config.php, db.php, bootstrap.php
assets/       → css, js, icons
sql/migrations/ → numbered schema files, run in order against Hostinger MySQL
```

## Deploying by direct copy (no Git/GitHub)

Since this gets copied straight into `public_html` rather than deployed via
a pipeline, `.htaccess` (included) blocks direct URL access to `.env`,
`sql/`, and `includes/` — otherwise those would be publicly reachable by
guessing the URL. This doesn't affect PHP's own `require`/`include` calls,
only direct browser requests. Confirm it's working after upload by trying
to visit `yourdomain.com/.env` in a browser — it should show a 403/blocked
page, not the file contents.

## Finishing Phase 0 — do this first

1. Copy `.env.example` to `.env` and fill in your real Hostinger DB credentials (create the DB via hPanel → MySQL Databases first, if you haven't).
2. Run `sql/migrations/001_phase1_schema.sql` in phpMyAdmin against that database (this also covers Phase 1).
3. Upload this whole folder to Hostinger (or clone the Git repo there), then visit `test-connection.php` in your browser.
   - It should print the current server time in IST and "DB connected. Canteens found: 2".
   - If it doesn't, check your `.env` values and confirm the schema was run.
4. Delete `test-connection.php` once it passes — it's a throwaway check, not part of the app.
5. Confirm SSL/HTTPS is active on the domain in hPanel (required later for the PWA service worker in Phase 9).

Once `test-connection.php` passes and HTTPS is confirmed, Phase 0 is done — move on to Phase 2 (Auth).
