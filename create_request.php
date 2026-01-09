<?php
// Prevent any output before headers
ob_start();

session_start();
require_once 'config.php';

// Set JSON headers
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    // Get and decode JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Validate JSON data
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }

    // Validate required fields
    if (empty($data['recipient_id']) || empty($data['skill_name'])) {
        throw new Exception('Recipient ID and skill name are required');
    }

    // Start transaction
    $conn->begin_transaction();

    // Get requester details
    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ?");
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("s", $_SESSION['user_email']);
    $stmt->execute();
    $result = $stmt->get_result();
    $requester = $result->fetch_assoc();
    $stmt->close();

    if (!$requester) {
        throw new Exception('Requester not found');
    }

    // Check if skill exists
    $stmt = $conn->prepare("SELECT skill_name, proficiency_level FROM skills WHERE user_id = ? AND LOWER(skill_name) = LOWER(?)");
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("is", $data['recipient_id'], $data['skill_name']);
    $stmt->execute();
    $skill = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$skill) {
        throw new Exception('Selected skill not found for this user');
    }

    // Check for existing request
    $stmt = $conn->prepare("SELECT id FROM skill_exchange_requests WHERE requester_id = ? AND recipient_id = ? AND LOWER(skill_requested) = LOWER(?) AND status = 'pending'");
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("iis", $requester['id'], $data['recipient_id'], $data['skill_name']);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        throw new Exception('You already have a pending request for this skill');
    }

    // Create request
    $stmt = $conn->prepare("INSERT INTO skill_exchange_requests (requester_id, recipient_id, skill_requested, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("iis", $requester['id'], $data['recipient_id'], $data['skill_name']);
    $stmt->execute();
    $request_id = $stmt->insert_id;
    $stmt->close();

    // Create notification
    $message = $requester['full_name'] . " has requested a skill exchange for your skill in " . $data['skill_name'] . " (" . $skill['proficiency_level'] . ")";
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, related_request_id, type, is_read, created_at) VALUES (?, ?, ?, 'EXCHANGE_REQUEST', 0, NOW())");
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("isi", $data['recipient_id'], $message, $request_id);
    $stmt->execute();
    $stmt->close();

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Request sent successfully'
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn) && $conn->connect_errno === 0) {
        $conn->rollback();
    }

    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}

// Clear any buffered output
ob_end_flush();
?> 