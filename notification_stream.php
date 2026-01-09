<?php
session_start();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Not authenticated']) . "\n\n";
    exit();
}

$user_id = $_SESSION['user_id'];
$last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;

while (true) {
    // Check for new notifications
    $query = "SELECT n.*, 
              r.skill_requested,
              u.full_name as sender_name
              FROM notifications n
              LEFT JOIN skill_exchange_requests r ON n.related_request_id = r.id
              LEFT JOIN users u ON r.requester_id = u.id
              WHERE n.user_id = ? AND n.created_at > FROM_UNIXTIME(?)
              ORDER BY n.created_at DESC";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $user_id, $last_check);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = [
            'id' => $row['id'],
            'message' => $row['message'],
            'type' => $row['type'],
            'is_read' => $row['is_read'],
            'created_at' => $row['created_at'],
            'skill_requested' => $row['skill_requested'],
            'sender_name' => $row['sender_name']
        ];
    }
    
    if (!empty($notifications)) {
        echo "event: notification\n";
        echo "data: " . json_encode($notifications) . "\n\n";
        
        // Update last check time
        $last_check = time();
    }
    
    // Get unread count
    $count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param("i", $user_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    
    echo "event: count\n";
    echo "data: " . json_encode(['unread_count' => $count_row['count']]) . "\n\n";
    
    // Clear all buffers
    ob_end_flush();
    flush();
    
    // Sleep for 5 seconds before next check
    sleep(5);
}
?> 