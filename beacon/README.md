# BEACON — Student Well-being Monitoring System

Stage 1: database schema + authentication / RBAC. The existing draft pages now sit behind real
logins and role checks; they still show their demo content until each module is rebuilt on the
database (next stages).

## Setup (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\beacon` and start **Apache** and **MySQL**.
2. phpMyAdmin → **Import** → `database/schema.sql`, then `database/seed.sql`.
   (The schema creates the `beacon` database itself.)
3. Copy `config/config.example.php` to `config/config.php`.
4. Generate an encryption key and paste it into `encryption_key`:
   ```
   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```
   Keep this key safe. If it is lost, confidential guidance notes can't be read.
5. Open http://localhost/beacon/login.php

**Already imported the database earlier?** Import each migration once, in order:
- `database/migrations/002_teacher_reports.sql` — report type and Clinic/Guidance routing (Teacher update)
- `database/migrations/003_intervention_updates.sql` — intervention progress check-ins (Guidance update)
- `database/migrations/004_clinic.sql` — visit status, medication notes, stock flags and requests (Clinic update)
- `database/migrations/005_admin.sql` — online/offline tracking (Super Admin update)
- `database/migrations/006_family_notices.sql` — family notices and acknowledgements (Family Portal update)

Needs PHP 8.0+ with `pdo_mysql` and `sodium` (both included in XAMPP 8.x).

## Demo accounts (password `Beacon@2026`)

| Role | E-mail | Notes |
|---|---|---|
| Super Admin | admin@beacon.edu | must set a new password at first login |
| Teacher (adviser, 8-Mabini) | m.reyes@beacon.edu | |
| Teacher (adviser, 8-Rizal) | p.lim@beacon.edu | can't see Mabini students |
| Guidance | g.santos@beacon.edu | |
| Clinic | n.flores@beacon.edu | |
| Parent (Andrea Cruz, approved) | elena.cruz@mail.com | |
| Parent (Joaquin, pending approval) | ramon.mendoza@mail.com | approve in Parent registrations |
| Parent (Nathan, pending approval) | j.reyes.parent@mail.com | not the guardian on file — try rejecting |

To try parent registration, use Student ID `SV-2026-0914`, birthdate `2012-05-14`.

Disable or delete the demo accounts before real use.

## Structure

```
config/config.example.php   settings (copy to config.php — keep it private)
database/schema.sql         tables, view, audit triggers
database/seed.sql           demo data
docs/ERD.md                 ER diagram + who-can-see-what table
inc/bootstrap.php           loaded by every page
inc/db.php                  PDO connection + query helpers
inc/auth.php                sessions, login/logout, throttling, passwords, parent registration
inc/rbac.php                permissions, page map, row-level student scope
inc/privacy.php             mental-health discretion rules, consent toggle, encrypted notes
inc/audit.php               append-only audit log
inc/crypto.php              libsodium encryption for confidential notes
login.php / register.php / change_password.php / logout.php
```

## Using the auth layer in a page

```php
require __DIR__ . '/inc/bootstrap.php';
$user = require_permission('incident.create');   // logged in + right role, else 403
require_student_access($user, $studentId);       // teacher: own sections; parent: approved links
```

Add new permissions to `PERMISSIONS` in `inc/rbac.php`; a typo in a permission name throws,
so mistakes surface immediately instead of silently denying (or allowing) access.

## Security choices

- Passwords: `password_hash` (bcrypt), min. 10 chars with letters and numbers, rehash on login.
- Login: generic error message, equal timing for unknown e-mails, lock after 5 failures per
  e-mail or 20 per IP within 15 minutes.
- Sessions: HttpOnly + SameSite cookies, strict mode, new session ID at login, 30-minute idle
  and 8-hour absolute timeout, account status re-checked on every request.
- CSRF token on every form, including logout.
- Parents need Student ID **and** the child's birthdate, then Super Admin approval, before any
  record is visible.
- Super Admin manages accounts but cannot open guidance or clinic records.
- Mental-health cases stay hidden from parents unless the student consents; each change is
  stored in `consent_events` with how consent was given.
- Confidential notes are encrypted at rest; the audit log stores field names, never values,
  and rejects UPDATE/DELETE.
