<?php
// Add session security measures
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

header('Content-Type: application/json');

require_once 'session_config.php';
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'User not authenticated'
    ]);
    exit();
}

$current_user_id = $_SESSION['user_id'];

try {
    // Get request data from POST
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['notification_id'])) {
        throw new Exception('Notification ID is required');
    }

    $notification_id = intval($data['notification_id']);

    // Get request ID from notification
    $notification_query = "SELECT n.*, n.related_request_id as request_id, n.message 
                         FROM notifications n 
                         WHERE n.id = ? AND n.user_id = ?";
    $stmt = $conn->prepare($notification_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare notification query');
    }

    $stmt->bind_param("ii", $notification_id, $current_user_id);
    $stmt->execute();
    $notification_result = $stmt->get_result();
    $notification = $notification_result->fetch_assoc();
    
    if (!$notification) {
        throw new Exception('Notification not found');
    }

    $request_id = $notification['request_id'];

    // Get request details with proficiency level from recipient's skills
    $query = "SELECT r.*, s.proficiency_level, u.full_name as requester_name,
              (SELECT GROUP_CONCAT(CONCAT(skill_name, '|', proficiency_level)) 
               FROM skills 
               WHERE user_id = r.requester_id) as requester_skills
              FROM skill_exchange_requests r
              LEFT JOIN users u ON r.requester_id = u.id
              LEFT JOIN skills s ON s.user_id = r.recipient_id AND s.skill_name = r.skill_requested
              WHERE r.id = ? AND (r.requester_id = ? OR r.recipient_id = ?)";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare request query');
    }

    $stmt->bind_param("iii", $request_id, $current_user_id, $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request) {
        throw new Exception('Request not found');
    }

    // Get the correct proficiency level based on whether user is requester or recipient
    $proficiency_level = 'Beginner'; // Default
    if ($request['requester_id'] == $current_user_id) {
        // Current user is the requester, get recipient's proficiency
        $skill_query = "SELECT proficiency_level 
                       FROM skills 
                       WHERE user_id = ? AND skill_name = ?";
        $stmt = $conn->prepare($skill_query);
        if ($stmt) {
            $stmt->bind_param("is", $request['recipient_id'], $request['skill_requested']);
            $stmt->execute();
            $skill_result = $stmt->get_result();
            if ($skill_row = $skill_result->fetch_assoc()) {
                $proficiency_level = $skill_row['proficiency_level'];
            }
        }
    } else {
        // Current user is the recipient, use their proficiency
        $proficiency_level = $request['proficiency_level'] ?? 'Beginner';
    }

    // Calculate credit value based on proficiency
    $credits = 50; // Default for beginner

    switch(strtolower($proficiency_level)) {
        case 'intermediate':
            $credits = 100;
            break;
        case 'advanced':
            $credits = 150;
            break;
        case 'expert':
            $credits = 200;
            break;
        default:
            $credits = 50; // Default to beginner level
    }

    // Mark notification as read
    $update_query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($update_query);
    if ($stmt) {
        $stmt->bind_param("ii", $notification_id, $current_user_id);
        $stmt->execute();
    }

    echo json_encode([
        'success' => true,
        'message' => $notification['message'],
        'request_id' => $request_id,
        'requester_id' => $request['requester_id'],
        'skill_requested' => $request['skill_requested'],
        'proficiency_level' => $proficiency_level,
        'credits' => $credits,
        'requester_skills' => $request['requester_skills']
    ]);

} catch (Exception $e) {
    error_log('Error in get_notification_details.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 