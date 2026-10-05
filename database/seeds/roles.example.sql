-- TEMPLATE. Copy to roles.<environment>.sql (roles.local.sql, roles.production.sql), replace every
-- <placeholder>, and keep the copy out of git: it holds passwords. Only this template is committed.
-- =============================================================================
-- Seed: Postgres roles and database for the <environment> environment
-- Target:    PostgreSQL 15 or newer. Run ONCE per environment, before the first migration.
--
-- How to run in DBeaver:
--   1. Open a connection to that environment's server as a superuser (postgres), on the default
--      "postgres" database.
--   2. Keep the connection in Auto-commit mode (the default): CREATE DATABASE cannot run inside a
--      transaction.
--   3. Open this file in an SQL editor on that connection and run it as a script (Alt+X).
-- Plain SQL only, so any other client runs it too.
--
-- Names and passwords must match .env.<environment>:
--   owner role        DB_ADMIN_USERNAME / DB_ADMIN_PASSWORD — owns the tables, used only by database/migrate.php.
--   application role  DB_USERNAME / DB_PASSWORD — what the app signs in as. Owns nothing and cannot bypass
--                     row-level security, which is what keeps one client's rows away from another's.
--   database          DB_DATABASE — owned by the owner role; only these two roles may connect.
--
-- Roles belong to the whole Postgres server, not to one database: two environments on one server
-- need different role names, or this seed hands one environment's password to the other.
--
-- Running it again: steps 1 and 3 are safe to repeat. Step 2 fails with "database already exists"
-- once the database is there (also when it was created by hand beforehand) — select and run
-- steps 1 and 3 only.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Step 1 — Roles. Neither is ever a superuser, may bypass row-level security, or may create roles
-- or databases. Existing roles are brought back to these attributes and passwords.
-- -----------------------------------------------------------------------------
DO $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '<owner_role>') THEN
		CREATE ROLE "<owner_role>";
	END IF;
	IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '<application_role>') THEN
		CREATE ROLE "<application_role>";
	END IF;
END
$$;

ALTER ROLE "<owner_role>" LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '<owner_password>';

ALTER ROLE "<application_role>" LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '<application_password>';

-- -----------------------------------------------------------------------------
-- Step 2 — Database, owned by the owner role. As the owner of the database it is also the one
-- role that may create tables in its public schema (Postgres 15+); the application role's rights
-- on each table are granted by the migrations.
-- -----------------------------------------------------------------------------
CREATE DATABASE "<database>" OWNER "<owner_role>" ENCODING 'UTF8';

-- -----------------------------------------------------------------------------
-- Step 3 — The database belongs to the owner role, and only this environment's two roles may
-- connect to it. The ALTER matters when the database already existed (created by hand, or by
-- postgres): without it the owner role cannot create tables and the migrations are refused
-- with "permission denied for schema public".
-- -----------------------------------------------------------------------------
ALTER DATABASE "<database>" OWNER TO "<owner_role>";

REVOKE ALL ON DATABASE "<database>" FROM PUBLIC;

GRANT CONNECT ON DATABASE "<database>" TO "<owner_role>", "<application_role>";
