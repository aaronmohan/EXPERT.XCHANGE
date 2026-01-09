<?php
require_once 'session_config.php';
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

try {
    if (!isset($_GET['notification_id'])) {
        throw new Exception('Notification ID is required');
    }

    $notification_id = intval($_GET['notification_id']);
    $current_user_id = $_SESSION['user_id'];

    // Get request details including proficiency level and phone numbers
    $query = "SELECT r.*, n.message, s.proficiency_level, 
              u.full_name as requester_name, u.phone_number as requester_phone,
              u2.phone_number as recipient_phone
              FROM notifications n
              JOIN skill_exchange_requests r ON n.related_request_id = r.id
              JOIN users u ON r.requester_id = u.id
              JOIN users u2 ON r.recipient_id = u2.id
              LEFT JOIN skills s ON s.user_id = r.recipient_id 
                   AND s.skill_name = r.skill_requested
              WHERE n.id = ? AND (r.recipient_id = ? OR r.requester_id = ?)";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $notification_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Request not found or unauthorized');
    }

    $request = $result->fetch_assoc();
    
    // Set default proficiency level if not found
    if (!$request['proficiency_level']) {
        $request['proficiency_level'] = 'beginner';
    }

    echo json_encode([
        'success' => true,
        'request' => $request
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 