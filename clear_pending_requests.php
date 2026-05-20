<?php
// Add session security measures before starting the session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Session expired',
        'redirect' => 'home.html'
    ]);
    exit();
}

require_once 'config.php';

try {
    // Start transaction
    $conn->begin_transaction();

    $user_id = $_SESSION['user_id'];

    // Delete notifications related to pending requests
    $delete_notifications = $conn->prepare("
        DELETE n FROM notifications n
        INNER JOIN skill_exchange_requests r ON n.related_request_id = r.id
        WHERE (r.requester_id = ? OR r.recipient_id = ?)
        AND r.status = 'pending'
    ");
    $delete_notifications->bind_param("ii", $user_id, $user_id);
    $delete_notifications->execute();

    // Delete pending requests
    $delete_requests = $conn->prepare("
        DELETE FROM skill_exchange_requests 
        WHERE (requester_id = ? OR recipient_id = ?)
        AND status = 'pending'
    ");
    $delete_requests->bind_param("ii", $user_id, $user_id);
    $delete_requests->execute();

    // Get number of affected rows
    $affected_rows = $delete_requests->affected_rows;

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $affected_rows > 0 
            ? "Successfully cleared {$affected_rows} pending request(s)" 
            : "No pending requests found",
        'affected_rows' => $affected_rows
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    error_log("Error clearing pending requests: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Failed to clear pending requests. Please try again.'
    ]);
}

// Close prepared statements
if (isset($delete_notifications)) $delete_notifications->close();
if (isset($delete_requests)) $delete_requests->close();

// Close database connection
$conn->close();
?> 