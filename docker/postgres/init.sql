-- PostgreSQL initialization
-- This runs once when the container is first created

-- Enable useful extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";   -- trigram search
CREATE EXTENSION IF NOT EXISTS "unaccent";  -- accent-insensitive search

-- Separate test database
CREATE DATABASE qasa_test
    WITH
    OWNER = qasa
    ENCODING = 'UTF8'
    LC_COLLATE = 'sk_SK.utf8'
    LC_CTYPE = 'sk_SK.utf8'
    TEMPLATE = template0;
