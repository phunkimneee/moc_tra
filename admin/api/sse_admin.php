<?php
session_name('MOCTRA_SESSION');
session_set_cookie_params(['path' => '/', 'httponly' => true]);
session_start();

require_once '../../config/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    exit;
}

// Release session lock
session_write_close();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

@ini_set('zlib.output_compression', 0);
if (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

set_time_limit(0);
ignore_user_abort(true);

$lastCount = -1;
$lastContactId = -1;
$lastReviewId = -1;
$tick = 0;

$stContacts = $conn->prepare("SELECT COUNT(*) FROM contacts WHERE status='new'");
$stReviews = $conn->prepare("SELECT COUNT(*) FROM product_reviews WHERE status_admin='new'");

$stLatestContact = $conn->prepare("SELECT id, name, subject, created_at FROM contacts ORDER BY id DESC LIMIT 1");
$stLatestReview = $conn->prepare("SELECT r.id, r.rating, r.comment, u.username as name FROM product_reviews r JOIN users u ON r.user_id = u.id ORDER BY r.id DESC LIMIT 1");

while (true) {
    if (connection_aborted()) break;
    if (!$conn->ping()) break;

    $stContacts->execute();
    $contacts_count = (int)$stContacts->get_result()->fetch_row()[0];

    $stReviews->execute();
    $reviews_count = (int)$stReviews->get_result()->fetch_row()[0];

    $total_badge = $contacts_count + $reviews_count;

    $stLatestContact->execute();
    $resContact = $stLatestContact->get_result();
    $currContactId = $resContact->num_rows > 0 ? (int)$resContact->fetch_row()[0] : 0;

    $stLatestReview->execute();
    $resReview = $stLatestReview->get_result();
    $currReviewId = $resReview->num_rows > 0 ? (int)$resReview->fetch_row()[0] : 0;

    $data = [];
    $send = false;

    if ($tick === 0 || $total_badge !== $lastCount) {
        $data['badge_count'] = $total_badge;
        $data['contacts_count'] = $contacts_count;
        $data['reviews_count'] = $reviews_count;
        $lastCount = $total_badge;
        $send = true;
    }

    if ($tick > 0 && $currContactId > $lastContactId) {
        $data['new_contact'] = true;
        $send = true;
    }
    
    if ($tick > 0 && $currReviewId > $lastReviewId) {
        $data['new_review'] = true;
        $send = true;
    }

    $lastContactId = $currContactId;
    $lastReviewId = $currReviewId;

    if ($send) {
        echo 'data: ' . json_encode($data) . "\n\n";
    }

    $tick++;
    sleep(3);
}
