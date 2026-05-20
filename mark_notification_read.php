<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['notification_id'])) {
        throw new Exception('Notification ID is required');
    }

    // Update notification as read
    $update_query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = (SELECT id FROM users WHERE email = ?)";
    $stmt = $conn->prepare($update_query);
    
    if (!$stmt) {
        throw new Exception("Failed to prepare update query: " . $conn->error);
    }
    
    if (!$stmt->bind_param("is", $data['notification_id'], $_SESSION['user_email'])) {
        throw new Exception("Failed to bind parameters");
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update notification");
    }
    
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Notification marked as read'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?> 