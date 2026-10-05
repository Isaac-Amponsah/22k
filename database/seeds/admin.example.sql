-- TEMPLATE. Copy to admin.<environment>.sql (admin.local.sql, admin.production.sql), replace every
-- <placeholder>, and keep the copy out of git: it holds a password. Only this template is committed.
-- =============================================================================
-- Seed: the admin account for the <environment> environment
-- Target:    PostgreSQL 15 or newer. Run AFTER `php database/migrate.php` (it needs the users table).
--
-- The admin is the accountant who signs in to the app and owns the clients they add. There is no
-- public sign-up, so this seed (or bin/create-user.php) is how the first account comes to exist.
--
-- How to run in DBeaver:
--   1. Open a connection to this environment's DATABASE (DB_DATABASE in .env.<environment>, not
--      the default "postgres" database), as the owner role (DB_ADMIN_USERNAME) or as postgres.
--   2. Open this file in an SQL editor on that connection and run it as a script (Alt+X).
-- Plain SQL only, so any other client runs it too.
--
-- The password is hashed inside the database with bcrypt (pgcrypto); only the hash is stored. The
-- app needs at least 12 characters. Sign-in upgrades the hash to the app's own format by itself.
--
-- Running it again is safe, and is also how a forgotten password is reset: the account with this
-- email gets the name and password written here and is made active again.
-- =============================================================================

-- A trusted extension: the database owner may create it. Used only here, for crypt() and gen_salt().
CREATE EXTENSION IF NOT EXISTS pgcrypto;

INSERT INTO users (email, password_hash, first_name, last_name, is_active)
VALUES (
	'<admin_email>',
	crypt('<admin_password>', gen_salt('bf', 12)),
	'<admin_first_name>',
	'<admin_last_name>',
	TRUE
)
ON CONFLICT ((LOWER(email))) DO UPDATE
	SET password_hash = EXCLUDED.password_hash,
	    first_name    = EXCLUDED.first_name,
	    last_name     = EXCLUDED.last_name,
	    is_active     = TRUE,
	    updated_at    = CURRENT_TIMESTAMP;

-- What is now on file (never the password).
SELECT user_id, email, first_name, last_name, is_active, created_at, updated_at
FROM users
WHERE LOWER(email) = LOWER('<admin_email>');
