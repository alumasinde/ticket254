-- 0018_add_auth_version_to_users.sql
-- Bumped whenever every existing session for a user must stop working:
-- password reset, forced sign-out or account disablement.
-- Sessions store the value seen at login and compare it on every private request.

ALTER TABLE users
  ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash;
