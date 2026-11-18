-- Admin report: patch for an EXISTING database that already has the earlier schema imported.
-- (A fresh database should import docs/pixelforge_schema.sql instead, which already includes this.)
-- Run once in phpMyAdmin: select the database > SQL tab > paste > Go.

-- 1. The operator flag. Existing users stay non-admin.
ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Record the migration as run, so a later `php artisan migrate` does not try to add the column again.
INSERT INTO `migrations` (`migration`, `batch`)
VALUES ('2026_11_18_223436_add_is_admin_to_users_table', 2);

-- 3. Make your own account an admin. CHANGE THE EMAIL to the account you log in with.
UPDATE `users` SET `is_admin` = 1 WHERE `email` = 'admin@example.com';

-- Check: exactly the accounts you expect should be admins.
SELECT `id`, `name`, `email`, `is_admin` FROM `users` WHERE `is_admin` = 1;
