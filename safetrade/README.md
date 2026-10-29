# SafeTrade Backend

Plain LAMP stack: procedural PHP (mysqli), MySQL/MariaDB, HTML. Built for XAMPP.

## Setup

1. Start Apache + MySQL in XAMPP, drop this folder into `htdocs/safetrade`.
2. Import the schema: open phpMyAdmin → SQL tab → run `sql/schema.sql`
   (or `mysql -u root -p < sql/schema.sql`). This creates the `safetrade`
   database, all tables, seed categories, and one admin account.
3. Default admin login: **admin@safetrade.local / Admin!12345** — change this
   password immediately from Admin → Settings after first login.
4. If your MySQL isn't the XAMPP default (root / no password / localhost),
   copy `.env.example` to `.env` and adjust, or edit `config/database.php` directly.
5. Visit `http://localhost/safetrade/Index.html` for the public site, or
   `http://localhost/safetrade/auth/register.php` to create a user account.

## Structure

```
config/         DB connection, app bootstrap, hardened session setup
includes/       auth.php, functions.php, validation.php, image_helper.php
auth/           register / login / logout / forgot / reset password
admin/          full admin panel (users, listings, groups, reports, verifications, audit log, settings)
sql/schema.sql  full database schema + seed data
uploads/        future home for ID/selfie documents (execution blocked via .htaccess)
Index.html      public marketing homepage (CTAs wired to auth pages)
dashboard.php   logged-in user home (existing prototype UI, now session-aware)
```

## Auth & security

- Passwords hashed with bcrypt (`password_hash`, cost 12).
- CSRF token required on every state-changing POST.
- Session fixation prevented (`session_regenerate_id` on login), httponly/SameSite cookies.
- Server-side session registry (`user_sessions`) so sessions can be revoked (e.g. on password reset).
- Login rate limiting: 5 failed attempts per email+IP locks out for 15 minutes (`login_attempts`).
- All SQL uses prepared statements — no string-built queries.
- Role-based access (`user` / `manager` / `admin`) enforced via `require_role()`.
- Every sensitive action (register, login, logout, password reset, admin moderation) is written to
  the immutable `audit_logs` table.
- `config/`, `includes/`, `sql/` are blocked from direct web access via `.htaccess`.

## What's intentionally NOT built yet

ID verification and face recognition are **not implemented** in this pass, per spec. What IS in
place so they can be added without restructuring anything:

- `identity_verifications` and `verification_documents` tables, with fields for status, similarity
  score, match method, and reviewer.
- `users.verification_status` kept in sync for fast reads/gating elsewhere in the app.
- `includes/image_helper.php` → `compress_and_store_image()`: resizes to a max dimension and
  re-encodes to JPEG at a tuned quality, ready to run on every ID/selfie upload before it's stored
  or handed to a face-match model (the existing C++ similarity tool, or a future vendor API /
  InsightFace pipeline). Records original vs. compressed size so the reduction is auditable.
- Admin → Verifications page already renders the (currently empty) review queue.
- `uploads/verification_docs/` exists with execution disabled, ready to receive files.

## Admin panel pages

Dashboard (stats) · Users (search/filter/suspend/ban/role) · Verifications (scaffold) ·
Listings (moderate/remove) · Groups (view/delete) · Reports (open/review/resolve/dismiss) ·
Audit Log (paginated) · Settings (change own password)

## Next steps

- Wire `dashboard.php`'s client-side mock data (groups/items/chat) to real API endpoints backed
  by the `listings`, `trade_groups`, `conversations`, and `messages` tables.
- Build the ID verification upload flow using the scaffolding above.
- Add email delivery (currently password-reset links are shown on-screen in dev mode instead of emailed).
