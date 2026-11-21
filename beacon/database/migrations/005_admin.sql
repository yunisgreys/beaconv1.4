-- Run ONCE if your database was created before the Super Admin update (2026-11-18).
USE beacon;
ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at;
