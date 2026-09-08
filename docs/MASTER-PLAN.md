# KPAW Canteen Portal — Master Build Plan

**Project:** Kanchrapara Workshop Canteen Meal Booking System
**Canteens covered:** Loco Canteen (branded *Annapurna*) and Carriage Canteen (branded *Zaika*)
**Stack:** Native PHP + MySQL, Bootstrap 5 (mobile-first), hosted on Hostinger shared hosting
**Delivery format:** Progressive Web App (installable on Android/iOS/desktop)
**Payment model:** UPI intent link + manual bank CSV reconciliation (no payment gateway)
**Deployment method:** Devin Local edits files directly on the local machine; deployment to Hostinger is by direct copy (File Manager or FTP) into `public_html`. No GitHub, no CI/CD pipeline, no Devin Cloud. Local `git init` is optional (kept only for local undo history, never pushed anywhere).

---

## How to use this document

This is the single source of truth for the build. Each phase below is meant to be completed, tested against its own acceptance criteria, and signed off **before** starting the next one. Don't jump ahead — the phases are ordered so that each one depends only on what's already built, which keeps you from having to rework earlier code when a later phase reveals a gap.

**If you're handing phases to Devin (or any coding agent):** give it one phase at a time, not the whole document at once. Paste that phase's Goal + Deliverables + Acceptance Criteria as the task brief, point it at this file for context, and review its changes against the acceptance checklist before moving to the next phase — this is Devin Local editing files directly in the project folder (see the Deployment Method note above), not a Git-based PR workflow. This keeps each hand-off scoped enough for the agent to actually get right, and keeps you in control of the sequence.

---

## 1. Data Model

Core tables — set this up in full during Phase 1 so nothing downstream needs a schema change mid-build.

| Table | Purpose | Key fields |
|---|---|---|
| `users` | Employees | id, full_name, hrms_id (unique, 6 char), phone, email, password_hash, email_verified_at, remember_token, created_at |
| `guests` | Guests/visitors/contractors | id, full_name, phone (unique, login ID), email, password_hash, email_verified_at, remember_token, created_at |
| `login_attempts` | Rate-limiting backing table | id, identifier (HRMS ID/phone/admin email), ip_address, success, attempted_at |
| `otp_verifications` | Registration OTP tracking | id, user_type (employee/guest), user_id, otp_code, expires_at, verified_at |
| `password_resets` | Forgot-password tokens | id, user_type, user_id, token, expires_at, used_at |
| `canteens` | The two canteens | id, name ("Loco Canteen" / "Carriage Canteen"), brand_name ("Annapurna" / "Zaika"), upi_vpa, is_active |
| `meal_items` | Menu per canteen | id, canteen_id, meal_type (breakfast/lunch/snacks), name, price, is_active |
| `holidays` | Admin-managed blocked dates | id, date, reason, blocks_all_meals (bool) |
| `orders` | The core booking/token table | id, user_type, user_id, canteen_id, meal_type, meal_item_id, amount, utr_number (nullable), status (PENDING_CLEARANCE/APPROVED/SERVED/REJECTED/EXPIRED), order_date, served_at, served_by_admin_id, is_vip_token (bool), vip_reason (nullable), created_at |
| `admins` | Admin + receptionist accounts | id, name, email, password_hash, role (super_admin/receptionist), assigned_canteen_id (nullable, for receptionists), last_login_at |
| `audit_log` | Manual overrides, VIP tokens, force-approvals | id, admin_id, action_type, order_id (nullable), details, created_at |

**Indexes (critical for Hostinger CPU limits, per the original blueprint):**
`orders(utr_number)`, `orders(status)`, `orders(order_date)` — the CSV reconciliation query relies on these.

**Note:** the blueprint separates "Railway Employees" and "Guests" into different registration/login flows. Whether that means two physical tables (`users` + `guests`, as above) or one `people` table with a `role` column is an implementation detail — two tables is cleaner given the different login ID rules (HRMS ID vs phone) and is what's assumed throughout this plan.

---

## 2. Business Rules Quick Reference

Keep this visible during Phase 3 and Phase 6 — these are the rules most likely to cause bugs if misread.

**Meal windows (server clock, must run in Asia/Kolkata):**

| Meal | Booking closes | Admin can upload CSV | Serving window | Unused tokens expire at |
|---|---|---|---|---|
| Breakfast | 8:00 AM | Flexible — no fixed window (per your call in Phase 5) | 8:30–9:30 AM | 9:31 AM |
| Lunch | 10:00 AM | Flexible | 11:30 AM–12:30 PM | 12:31 PM |
| Snacks | 2:30 PM | Flexible | 3:00–4:00 PM | 4:01 PM |

**Day rules:** Sundays fully blocked. Saturdays: breakfast only. Admin-added holiday dates block per the `holidays` table.

**Token status colors:** PENDING CLEARANCE = yellow/orange · APPROVED = bright green · SERVED = greyed out · REJECTED = red · EXPIRED = dark grey.

**Date display format:** all user-facing dates (booking confirmations, "My Bookings" history, admin reports, kitchen headcount sheets) display as **DD-MM-YYYY**, not the database's internal `YYYY-MM-DD`. This is a display-only formatting rule — the database itself keeps standard MySQL `DATE`/`DATETIME` storage throughout; only PHP's output formatting changes wherever a date is shown to a user.

**Non-negotiables from the blueprint:** no cancellations/refunds once booked (flagged as still-open in §6 below); no payment gateway; no cron jobs (everything computed at read-time); double-booking is allowed by design (your call).

---

## 3. Repository & Folder Structure

```
/kpaw-canteen-portal/
├── index.php                # Root landing page — redirects to login or dashboard (Phase 2)
├── app/                     # Employee & guest facing pages
│   ├── dashboard.php
│   ├── book.php
│   ├── my-bookings.php
│   └── ...
├── counter/                 # Receptionist scan/serve screens
│   └── scan.php
├── admin/                   # Admin dashboard modules
│   ├── menu-manager.php
│   ├── holidays.php
│   ├── csv-upload.php
│   ├── vip-tokens.php
│   ├── reports.php
│   └── ...
├── auth/                    # Registration, login, OTP, password reset
├── includes/                # Shared PHP: DB connection, helpers, config
├── assets/
│   ├── css/ js/ icons/
├── sql/
│   └── migrations/          # Numbered .sql files, one per schema change
├── manifest.json            # Employee PWA manifest
├── counter/manifest.json    # Receptionist PWA manifest
├── sw.js
├── offline.html
├── .htaccess                # Blocks direct URL access to .env, sql/, includes/
└── .env.example             # DB creds, SMTP creds — never commit real .env
```

**Deployment note:** this entire folder gets copied directly into Hostinger's `public_html` (no build step, no GitHub). Because of that, `.htaccess` is required, not optional — it's what stops `.env` and the `sql/` folder from being reachable by URL once they're sitting in the public web root. Local `git init` is fine to keep for your own undo history, but nothing here depends on a remote/GitHub existing.

---

## 4. Build Phases

### Phase 0 — Environment & Repo Setup
**Goal:** A working skeleton that connects to the database, on both your local machine and Hostinger, before any real feature is built.

**Deliverables:**
- Local folder structure per §3 created (local `git init` optional, never pushed to a remote — deployment is by direct copy, not Git)
- `.htaccess` in place, blocking direct URL access to `.env`, `sql/`, and `includes/`
- `.gitignore` covering `.env` and vendor/cache dirs (relevant even without a remote, keeps local history clean if git is used)
- Hostinger: PHP version confirmed (match your local dev version), MySQL database created, SSL/HTTPS activated in hPanel
- Local dev environment (XAMPP/Laragon/similar) matching the Hostinger PHP version
- `includes/db.php` — PDO connection using `.env` credentials, prepared-statement-only from day one
- `date_default_timezone_set('Asia/Kolkata')` set globally in a bootstrap include

**Acceptance criteria:**
- [ ] A test PHP page loads on both local and Hostinger (after copying the folder into `public_html`), connects to MySQL, and echoes the current server time in IST correctly
- [ ] HTTPS confirmed active on the Hostinger domain (padlock, no mixed-content warnings)
- [ ] `.env` and any `.sql` file confirmed blocked when requested directly by URL (403, not file contents)

---

### Phase 1 — Database Schema
**Goal:** Every table from §1 exists, indexed, with seed data for the two canteens.

**Deliverables:**
- Numbered SQL migration files (`sql/migrations/001_create_users.sql`, etc.) — not one giant dump, so future changes are trackable
- All tables from §1 created with correct types and foreign keys
- Indexes on `orders.utr_number`, `orders.status`, `orders.order_date`
- Seed data: `canteens` table populated with Loco Canteen/Annapurna and Carriage Canteen/Zaika (with placeholder UPI VPAs until real ones are confirmed — see §6)
- A handful of sample `meal_items` per canteen for testing later phases

**Acceptance criteria:**
- [ ] Migrations run cleanly on a fresh database with no manual fixes
- [ ] Foreign key constraints verified (can't insert an order for a non-existent canteen, etc.)
- [ ] Seed data visible via a simple `SELECT *` check

---

### Phase 2 — Authentication & Registration
**Goal:** Employees and guests can register, verify by OTP, log in, and recover passwords. Nothing about booking yet.

**Deliverables:**
- Employee registration form: Full Name, HRMS ID, Phone, Email, Password
  - HRMS ID validated server-side with `strtoupper()` applied **before** matching against `^[A-Z0-9]{6}$`, so lowercase entries aren't wrongly rejected
- Guest registration form: Full Name, Phone, Email, Password
- 6-digit OTP generation + email delivery for both flows, using PHP's built-in `mail()` function (no SMTP account setup needed — works out of the box on Hostinger shared hosting); registration incomplete until OTP entered
- Passwords hashed with `password_hash()` (bcrypt) — never stored plain or reversibly encrypted
- Minimum password strength enforced at registration (e.g. 8+ characters minimum) — nothing currently stops a 1-character password
- Login: HRMS ID for employees, 10-digit phone for guests
- `session_regenerate_id(true)` called on every successful login — prevents session fixation attacks
- OTP resend is rate-limited (e.g. max 1 resend per 60 seconds, max 5 per hour per account) — without this, OTP resend can be spammed, hammering outbound mail and risking Hostinger flagging the mail function for abuse
- "Remember Me" persistent session (30 days) — confirm whether this applies to guests too, or employees only as the blueprint states (flagged in §6)
- Forgot-password flow: email link with expiring token, sent via PHP's `mail()`
- Admin-side "Manual Override Reset to Default" button (ties into Phase 8's admin panel, but the underlying function belongs here)
- Replace the Phase 0 placeholder `index.php` at the project root with real logic: redirect to `app/dashboard.php` if a valid session exists, otherwise redirect to `auth/login.php`
- **Logout** — clears the session and, if set, the `remember_token`/cookie
- **CSRF protection** — every form (registration, login, password reset) includes and validates a CSRF token
- **Login rate-limiting** — using the `login_attempts` table (run `sql/migrations/002_schema_additions.sql` first): lock out further attempts for an identifier after 5 failures within 15 minutes
- **Initial admin account bootstrap** — there is no admin self-registration by design, so create the first `super_admin` row via a documented manual SQL insert (with a real `password_hash()`-generated hash, not plaintext) or a one-time setup script. Needed before Phase 5, 7, or 8 can be tested at all.

**Acceptance criteria:**
- [ ] Full registration → OTP → verified account flow works for both employee and guest
- [ ] Duplicate HRMS ID / duplicate phone number registration is rejected with a clear error
- [ ] Wrong password, unverified account, and expired OTP all fail login with correct messaging
- [ ] Password reset link expires correctly and can't be reused after use
- [ ] Manually inspect the `users`/`guests` table — passwords are hashed, never plaintext
- [ ] Visiting the root domain while logged out shows login/register, not a blank page or directory listing
- [ ] Logging out actually clears the session (protected pages redirect to login afterward)
- [ ] Submitting a form with a missing/invalid CSRF token is rejected
- [ ] 6 failed logins in a row for the same identifier trigger a lockout; a 7th attempt is blocked even with the correct password
- [ ] At least one working super_admin login exists and can reach the admin panel

---

### Phase 3 — Menu & Booking Core (Rolling Window Logic)
**Goal:** A logged-in user can see the correct meal options for right now, and only right now — this is the trickiest logic in the whole system, so it gets its own isolated phase before payment touches it.

**Deliverables:**
- Canteen dropdown (Loco Canteen/Annapurna, Carriage Canteen/Zaika)
- Meal dropdown, filtered live by the rolling-window rules in §2 (e.g., at 10:01 AM, "Today's Lunch" disappears and only "Tomorrow's Lunch" is offered)
- Live price display pulled from `meal_items`
- Saturday breakfast-only enforcement
- Sunday full block
- Holiday table check (admin-added dates block booking)
- Emergency "Stop All Bookings" master switch check (the switch itself is built in Phase 8, but the booking page must check it here)
- No double-booking prevention — a user can book the same slot twice; this is intentional, don't add a unique constraint

**Acceptance criteria:**
- [ ] Manually test each cutoff boundary (e.g., set server test time to 7:59 vs 8:01 AM) and confirm the correct meal set appears
- [ ] Saturday shows breakfast only; Sunday shows nothing bookable
- [ ] A holiday date added via a direct DB insert correctly blocks booking that day
- [ ] Booking a second meal in the same slot succeeds without error (confirming the intentional non-restriction)

---

### Phase 4 — Payment: UPI Generation & UTR Submission
**Goal:** A booked meal produces a real, correctly-amounted UPI payment request, and the user's UTR submission creates a PENDING order.

**Deliverables:**
- Dynamic `upi://pay` deep link generation with the exact locked price and the correct canteen's UPI VPA (needs real VPAs confirmed — see §6)
- QR code fallback for desktop (same URI encoded)
- UTR/reference number input field + submission handler
- On submission: creates an `orders` row with `status = PENDING_CLEARANCE`

**Acceptance criteria:**
- [ ] UPI link opens GPay/PhonePe correctly on a real Android test device, with the amount pre-filled and locked
- [ ] Desktop view renders a scannable QR code with the same payment details
- [ ] Submitting a UTR creates the order row correctly; submitting twice for the same booking doesn't create duplicate rows (or does, if that's acceptable — confirm expected behavior)

---

### Phase 5 — Admin CSV Reconciliation Engine
**Goal:** Admin can upload a bank statement and have matching payments auto-approve — the financial core of the system.

**Deliverables:**
- CSV upload interface, **separate upload per canteen** (since Loco Canteen and Carriage Canteen have separate bank accounts and their statements are never mixed)
- File validation before parsing: reject anything that isn't a `.csv` file by extension and MIME type, and cap upload size (e.g. 2MB — a bank statement CSV should never realistically exceed this on Hostinger shared hosting)
- **Uploaded CSVs are never stored in a web-accessible location** — save them outside `public_html` if possible, or into a folder blocked by `.htaccess` the same way `sql/` is blocked; these files contain real bank transaction data. Delete or archive them out of the upload folder once processed — don't leave them sitting there indefinitely.
- `fgetcsv`-based parser
- The reconciliation query exactly as specified in the blueprint:
  ```sql
  UPDATE orders
  SET status = 'APPROVED'
  WHERE utr_number = :bank_utr
    AND amount = :bank_amount
    AND status = 'PENDING_CLEARANCE'
    AND order_date >= (CURDATE() - INTERVAL 7 DAY)
  ```
- No fixed upload time window — admin can upload whenever, for either canteen, per your call in Phase 5 discussion
- Manual UTR Override / "Force Approve" for typo cases, logged to `audit_log`
- VIP/Guest Token Generator: instant APPROVED token, ₹0.00, no UTR required — **with a mandatory reason/official-name field**, and every VIP token logged separately to `audit_log` (not just lumped into general financial exports), since this is the single easiest internal fraud vector in the system

**Acceptance criteria:**
- [ ] A test CSV with known UTRs correctly flips only the matching, still-pending orders to APPROVED
- [ ] Orders with a mismatched amount or already-processed status are correctly left untouched
- [ ] The 7-day lookback correctly excludes older pending orders (test with a deliberately old order)
- [ ] Force Approve and VIP token generation both write a complete, attributable `audit_log` entry
- [ ] Upload works for both canteens independently, at any time of day

---

### Phase 6 — Token Wallet & Status Engine ("My Bookings")
**Goal:** Users can see all their bookings with a status that's always correct, computed live — no background jobs.

**Deliverables:**
- "My Bookings" tab listing current and past tokens
- Large, bold token display: Canteen Name, Meal Name, Serving Time — built for a receptionist to read at a glance, not just the user
- Dynamic status computation at render time:
  - PENDING CLEARANCE → yellow/orange
  - APPROVED → bright green
  - SERVED → greyed out
  - REJECTED → red
  - EXPIRED → dark grey, computed by comparing current server time to the meal's serving end-time; **no cron job, no refund, no food given**

**Acceptance criteria:**
- [ ] A token that's still APPROVED at 12:29 PM for Lunch shows green; reload the same page at 12:32 PM (or with test time shifted) and it correctly shows EXPIRED without any scheduled job having run
- [ ] All five statuses render with correct, distinguishable colors
- [ ] Past (SERVED/EXPIRED/REJECTED) tokens remain visible in history, not deleted

---

### Phase 7 — Receptionist / Counter Dashboard
**Goal:** Counter staff can find a token and mark it served, scoped strictly to their own canteen.

**Deliverables:**
- Receptionist login, separate from employee/guest login, tied to `admins.assigned_canteen_id`
- Hard scoping: a Canteen 1 receptionist cannot see or act on Canteen 2 tokens, enforced server-side (not just hidden in the UI)
- Mandatory auto-logout after 5 minutes of inactivity
- Token lookup by ID (typed or scanned from the user's screen)
- "Mark as Served" action — only valid when current status is APPROVED; transitions to SERVED
- Any other status (PENDING, REJECTED, EXPIRED) shows a clear red error screen and blocks food handout

**Acceptance criteria:**
- [ ] Attempting to look up a token from the other canteen returns "not found," not the token's details
- [ ] Session auto-expires after 5 minutes idle, tested with a stopwatch
- [ ] Marking an already-SERVED token as served again is blocked (no double-serving)
- [ ] PENDING/REJECTED/EXPIRED lookups correctly show the red error screen with no serve option

---

### Phase 8 — Admin Dashboard: Remaining Modules
**Goal:** Everything the admin needs to run the system day-to-day, outside of reconciliation (which is Phase 5).

**Deliverables:**
1. **Menu & Pricing Manager** — Add/Edit/Disable `meal_items`; price changes reflect instantly on the live booking page and next UPI link generated
2. **Holiday & Calendar Manager** — CRUD on the `holidays` table; Sunday/Saturday-breakfast-only rules confirmed as automatic (not manually re-entered every week)
3. **Emergency "Stop All Bookings" toggle** — a single switch that Phase 3's booking page checks before allowing any booking
4. **Kitchen Headcount Reports** — live, printable per-canteen plate counts, filterable by meal cutoff
5. **Financial Exports** — monthly CSV: total revenue, per-user breakdown, served vs. uncollected/expired counts

**Acceptance criteria:**
- [ ] A price change in the Menu Manager is reflected on the booking page within the same request cycle (no caching lag)
- [ ] Toggling "Stop All Bookings" immediately blocks new bookings for both canteens
- [ ] Kitchen Headcount report numbers match a manual count of test orders for that meal/canteen
- [ ] Financial export CSV opens correctly in Excel/Sheets with correct column headers and totals

---

### Phase 9 — PWA Layer
**Goal:** Both the employee app and the receptionist app install cleanly as home-screen apps.

**Deliverables** *(largely already drafted — this phase is about wiring and asset creation, not new design)*:
- `manifest.json` (employee) and `counter/manifest.json` (receptionist), each with distinct `start_url`, `scope`, and icons so they install as separate home-screen apps
- `sw.js` — caches static shell assets only (CSS/JS/icons); booking, token, and counter pages stay network-only so prices/statuses are never stale
- Real icon assets (192×192, 512×512, plus maskable variants) generated from the KPAW Canteen Portal branding — placeholder assets need replacing here
- `offline.html` fallback page
- `<link rel="manifest">` + theme-color meta tags added to every relevant page's `<head>`
- Service worker registration script included on `/app/` pages

**Acceptance criteria:**
- [ ] "Add to Home Screen" installs correctly on a real Android device and shows the correct icon/name
- [ ] Same test on iOS Safari (PWA support is more limited there — confirm install works and note any iOS-specific gaps)
- [ ] Lighthouse PWA audit in Chrome DevTools passes with no critical errors
- [ ] Airplane-mode test: static shell loads, dynamic pages correctly show `offline.html` instead of a broken page

---

### Phase 10 — End-to-End QA & Security Review
**Goal:** Full journeys work, and the system is safe to put real money and real employee data through.

**Deliverables / checks:**
- Full employee journey: register → OTP → login → book → pay → admin reconciles → collect at counter → (or) let it expire
- Full guest journey, same path
- Edge cases: exact cutoff-boundary timing, holiday-date blocking, VIP token flow, manual override flow, session timeout, forgot-password flow
- Security pass:
  - Every DB query uses prepared statements (no string-concatenated SQL anywhere, especially in the CSV reconciliation and login queries)
  - All user-supplied output is escaped before rendering (XSS check)
  - Login attempts rate-limited (basic brute-force protection on HRMS ID / phone login)
  - Session cookies flagged `HttpOnly` and `Secure`
  - `.env` file confirmed not web-accessible (test by requesting it directly in a browser)
  - CSRF tokens verified present and validated on every state-changing form
  - HTTPS force-redirect confirmed working (visit the site with plain `http://` and confirm it redirects to `https://`)
  - Uploaded bank CSVs (Phase 5) confirmed not reachable by direct URL
  - `display_errors` turned off in production PHP config; errors logged server-side instead of shown to users (test by triggering a deliberate error and confirming no stack trace/file path appears on screen)
- Basic load sanity check appropriate to Hostinger shared hosting's CPU/RAM limits — simulate a realistic burst (e.g., many employees booking in the 5 minutes before an 8:00 AM cutoff)

**Acceptance criteria:**
- [ ] Every journey above completes without manual DB intervention
- [ ] No SQL injection or XSS found in a basic manual pass (or automated scan if available)
- [ ] `.env` returns 403/404 when requested directly over HTTP
- [ ] System remains responsive under the simulated cutoff-time booking burst

---

### Phase 11 — Deployment & Launch
**Goal:** Go live safely, with a way back out if something's wrong.

**Deliverables:**
- Production database created on Hostinger, migrations run in order
- Confirm email delivery (`mail()`) is actually working in production — send a real test OTP/reset email and check it arrives (spam folder included)
- Final domain/SSL check
- Full production DB backup taken immediately before go-live
- A zipped copy of the current live `public_html` taken immediately before uploading the new version — since there's no Git remote, this zip *is* the rollback mechanism
- Documented rollback plan: restore the DB backup, and re-upload the pre-launch `public_html` zip to undo a bad deploy
- Soft launch to a small group (e.g., one department or shift) before opening to the full workshop

**Acceptance criteria:**
- [ ] A full smoke test (register → book → pay → reconcile → serve) passes on production, not just staging
- [ ] Rollback plan has been tested at least once (restore backup to a scratch DB, confirm it works)
- [ ] Soft-launch group completes at least one full day's meal cycle with no critical issues before wider rollout

---

### Phase 12 — Post-Launch Monitoring & Backlog
**Goal:** Keep it healthy after launch, and track known deferred items.

**Ongoing:**
- PHP error log review routine (who checks it, how often)
- Monthly financial export review against actual bank statements
- Periodic re-check of the security items from Phase 10

**Backlog (deliberately deferred, not forgotten):**
- Self-service cancellation window (still an open decision — see §6)
- Web Push notifications on token status change (APPROVED/EXPIRED) — natural PWA enhancement once the core is stable
- iOS-specific PWA gaps identified in Phase 9, if any

---

## 5. Open Decisions To Confirm

These don't block starting the build, but each one should be settled before the phase that needs it:

| Decision | Needed by | Status |
|---|---|---|
| Real UPI VPA / bank account per canteen | Phase 4 | Not yet provided |
| Self-service cancellation window (or confirm "no cancellations" is final) | Phase 3 | Open |
| Does "Remember Me" (30-day session) apply to guests too, or employees only? | Phase 2 | Open |
| Final KPAW Canteen Portal logo/branding for PWA icons | Phase 9 | Open |
| Who reviews error logs and financial exports post-launch, and how often | Phase 12 | Open |

---

## 6. Summary of What This Plan Assumes

For traceability, everything below reflects decisions already made in our conversation, not assumptions this plan is introducing:

- Payment/reconciliation is UPI intent + admin bank CSV upload (not a prepaid wallet)
- Loco Canteen (Annapurna) and Carriage Canteen (Zaika) have **separate bank accounts** — CSVs are never mixed
- Double-booking is allowed by design — no DB-level restriction
- Admin CSV upload is not restricted to fixed time windows, for either canteen
- Password hashing via `password_hash()`/bcrypt, and HRMS ID input normalized to uppercase before validation — standard practice, included as requirements rather than open questions
