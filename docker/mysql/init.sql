-- MySQL initialization script for ERNIE
-- This script sets up the database and users correctly

-- Ensure we're using the right database
USE ernie;

-- Create a dedicated laravel user with proper permissions
-- The password will be the same as the root password for simplicity
CREATE USER IF NOT EXISTS 'laravel'@'%' IDENTIFIED WITH mysql_native_password BY 'GFZdatabase2025!';
GRANT ALL PRIVILEGES ON ernie.* TO 'laravel'@'%';

-- Also ensure root can connect from any host with the correct password
ALTER USER 'root'@'%' IDENTIFIED WITH mysql_native_password BY 'GFZdatabase2025!';
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'GFZdatabase2025!';

-- Apply changes
FLUSH PRIVILEGES;

-- Confirm setup
SELECT 'MySQL initialization completed successfully' AS status;