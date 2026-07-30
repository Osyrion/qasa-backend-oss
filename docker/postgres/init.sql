-- PostgreSQL initialization
-- Runs once, as $POSTGRES_USER, when the container's data directory is empty.
--
-- Extensions are NOT created here. They are provisioned by the migration
-- database/migrations/0001_01_01_000000_add_postgres_extensions.php, because
-- anything done here reaches only this cluster: the test database below is
-- cloned from template0, and CI runs bare postgres service containers that
-- never see this file at all.

-- Separate test database (phpunit.xml points DB_DATABASE at it).
--
-- No OWNER: the creating role is $POSTGRES_USER, which is the same account
-- the application connects as. This previously named a role docker-compose
-- does not create, which fails the statement — and a failing init script
-- aborts container startup on a fresh volume.
--
-- No LC_COLLATE/LC_CTYPE either: they inherit the cluster locale chosen by
-- initdb. This previously named sk_SK.utf8, which the image does not ship.
-- That does not fail here the way a missing role does — postgres:*-alpine is
-- built on musl, which accepts any locale name and quietly falls back — so
-- the database recorded a collation it was not actually using. Inheriting is
-- both honest and correct.
CREATE DATABASE qasa_test
    WITH
    ENCODING = 'UTF8'
    TEMPLATE = template0;
