<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['request_id']) || !isset($data['status'])) {
        throw new Exception('Missing required parameters');
    }

    $request_id = $data['request_id'];
    $status = $data['status'];

    $conn->begin_transaction();

    // Get request details first
    $request_query = "SELECT requester_id FROM skill_exchange_requests WHERE id = ?";
    $stmt = $conn->prepare($request_query);
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();

    if (!$request) {
        throw new Exception("Request not found");
    }

    // Update request status
    $update_query = "UPDATE skill_exchange_requests SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("si", $status, $request_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update request status");
    }

    // Create notification for requester
    $notification_message = "The skill exchange has been completed. Please provide a review.";
    $create_notification = "INSERT INTO notifications (user_id, message, type) VALUES (?, ?, 'exchange_completed')";
    $stmt = $conn->prepare($create_notification);
    $stmt->bind_param("is", $request['requester_id'], $notification_message);
    $stmt->execute();

    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Exchange status updated successfully',
        'requester_id' => $request['requester_id']
    ]);

} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?> 