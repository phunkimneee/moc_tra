<?php
require_once 'config/db.php';

// Add status_admin to product_reviews
$conn->query("ALTER TABLE product_reviews ADD COLUMN IF NOT EXISTS status_admin ENUM('new', 'read', 'replied') DEFAULT 'new'");

// Add status_user to product_reviews and contacts
$conn->query("ALTER TABLE product_reviews ADD COLUMN IF NOT EXISTS status_user ENUM('unread', 'read') DEFAULT 'read'");
$conn->query("ALTER TABLE contacts ADD COLUMN IF NOT EXISTS status_user ENUM('unread', 'read') DEFAULT 'read'");

// Create notifications table if not exists (general notifications)
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    reference_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

echo "Migration done.";
