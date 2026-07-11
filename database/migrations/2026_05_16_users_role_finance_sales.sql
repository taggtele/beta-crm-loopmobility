-- Extend users.role ENUM for Party Account RBAC (Finance + Sales).
-- Run once on production after backup. PHP also auto-applies via users_ensure_role_enum() on user admin pages.

ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('Admin', 'Agent', 'Finance', 'Sales') NOT NULL;
