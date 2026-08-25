-- Read-only Postgres role for the monitoring agent.
--
-- Run once per production cluster, as the owner (the role in DB_USERNAME):
--
--   docker exec -i flok_db psql -U "$DB_USERNAME" -d "$DB_DATABASE" \
--     -v password="<generated>" -f - < docker/alloy/monitoring-role.sql
--
-- Deliberately not a Laravel migration. A migration runs in every developer's
-- database and in the test database, where this role has nothing to do, and it
-- would need a password from the environment to create — a credential in the
-- schema history. This is a deploy step, so it lives with the deploy config.
--
-- Why a third role rather than reusing one that exists:
--
--   * NOT the owner (DB_USERNAME). Row Level Security is invisible to a table's
--     owner, so the owner's credentials in a long-running sidecar are the one
--     way this repository's tenant isolation can be undone from outside the
--     application. See database/migrations/0001_01_01_000001_create_application_role.php.
--   * NOT the application role (DB_APP_USERNAME). It has SELECT/INSERT/UPDATE/
--     DELETE on every table. The agent needs statistics, not rows, and giving
--     it write access to earn a CPU graph is a bad trade.
--
-- pg_monitor grants exactly the catalog and statistics views the exporter
-- reads (pg_stat_*, pg_locks, pg_database_size) and no table data at all.

\set ON_ERROR_STOP on

-- \gexec rather than a DO block: psql substitutes :variables in the SQL it is
-- about to send, but the body of a dollar-quoted string is opaque to it, so a
-- password passed with -v never arrives inside DO $$ ... $$. This builds the
-- statement as a value first and then executes it.
SELECT format('CREATE ROLE qasa_monitor LOGIN PASSWORD %L', :'password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'qasa_monitor')
\gexec

-- Unconditional, so re-running the file is how the password is rotated.
SELECT format('ALTER ROLE qasa_monitor LOGIN PASSWORD %L', :'password')
\gexec

-- Never a superuser, never able to see past a policy.
ALTER ROLE qasa_monitor NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE INHERIT;

GRANT pg_monitor TO qasa_monitor;
GRANT CONNECT ON DATABASE :"DBNAME" TO qasa_monitor;

-- Needed only so the exporter can resolve relation names in the statistics
-- views it reads; it carries no privilege on the tables themselves.
GRANT USAGE ON SCHEMA public TO qasa_monitor;

-- Belt and braces: whatever ALTER DEFAULT PRIVILEGES may have granted, this
-- role gets no data. Re-run after adding tables if that ever changes.
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM qasa_monitor;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM qasa_monitor;

-- Verify: this must return f (not a superuser) and t (member of pg_monitor).
SELECT rolsuper                                            AS is_superuser,
       pg_has_role('qasa_monitor', 'pg_monitor', 'MEMBER') AS can_read_stats
FROM pg_roles
WHERE rolname = 'qasa_monitor';
