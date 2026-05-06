<?php
require_once __DIR__ . '/../../config/db.php';

// Cache sidebar badge counts in session (60s TTL) — avoids a DB hit on every page
if (!isset($_SESSION['_badge_ts']) || time() - $_SESSION['_badge_ts'] > 60) {
    $_SESSION['_badge_ts']      = time();
    $_SESSION['_pending_count'] = (int)$conn->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetch_row()[0];
    
    $__r = $conn->query("SHOW TABLES LIKE 'contacts'");
    $contacts_count = ($__r && $__r->num_rows > 0)
        ? (int)$conn->query("SELECT COUNT(*) FROM contacts WHERE status='new'")->fetch_row()[0]
        : 0;

    $__rev_r = $conn->query("SHOW TABLES LIKE 'product_reviews'");
    $reviews_count = ($__rev_r && $__rev_r->num_rows > 0)
        ? (int)$conn->query("SELECT COUNT(*) FROM product_reviews WHERE status_admin='new'")->fetch_row()[0]
        : 0;

    $_SESSION['_new_contacts_count'] = $contacts_count;
    $_SESSION['_new_reviews_count'] = $reviews_count;
    $_SESSION['_new_contacts']  = $contacts_count + $reviews_count;
}
$pendingCount  = (int)$_SESSION['_pending_count'];
$__newContacts = (int)$_SESSION['_new_contacts'];
$__newMessages = (int)$_SESSION['_new_contacts_count'];
$__newReviews = (int)$_SESSION['_new_reviews_count'];
