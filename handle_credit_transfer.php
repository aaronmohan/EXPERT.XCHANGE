<?php
require_once 'config.php';
require_once 'session_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request data']);
    exit();
}

// Validate required fields
if (!isset($data['request_id']) || !isset($data['amount'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit();
}

$request_id = intval($data['request_id']);
$amount = intval($data['amount']);
$user_id = $_SESSION['user_id'];

try {
    // Start transaction
    $conn->begin_transaction();

    // Get request details and lock the row
    $request_query = "SELECT * FROM skill_exchange_requests WHERE id = ? FOR UPDATE";
    $stmt = $conn->prepare($request_query);
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();

    if (!$request) {
        throw new Exception("Request not found");
    }

    if ($request['status'] !== 'ACCEPTED') {
        throw new Exception("Request must be accepted before transferring credits");
    }

    if ($request['transfer_status'] === 'COMPLETED') {
        throw new Exception("Credits have already been transferred for this request");
    }

    // Verify user is the requester
    if ($request['requester_id'] !== $user_id) {
        throw new Exception("Only the requester can transfer credits");
    }

    // Check if user has enough credits
    $balance_query = "SELECT COALESCE(SUM(CASE 
        WHEN transaction_type IN ('EARNED', 'TRANSFER_IN') THEN credits 
        WHEN transaction_type IN ('SPENT', 'TRANSFER_OUT') THEN -credits 
        END), 0) as balance 
        FROM user_credits 
        WHERE user_id = ? 
        FOR UPDATE";
    
    $stmt = $conn->prepare($balance_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $balance_result = $stmt->get_result()->fetch_assoc();
    $current_balance = $balance_result['balance'];

    if ($current_balance < $amount) {
        throw new Exception("Insufficient credits. Available: " . $current_balance);
    }

    // Create credit transfer record
    $transfer_query = "INSERT INTO credit_transfers (request_id, from_user_id, to_user_id, amount) 
                      VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($transfer_query);
    $stmt->bind_param("iiii", $request_id, $user_id, $request['recipient_id'], $amount);
    $stmt->execute();
    $transfer_id = $stmt->insert_id;

    // Deduct credits from requester
    $deduct_query = "INSERT INTO user_credits (user_id, credits, transaction_type, description, related_request_id, transfer_to_user_id) 
                     VALUES (?, ?, 'TRANSFER_OUT', ?, ?, ?)";
    $description = "Credit transfer to user ID: " . $request['recipient_id'] . " for skill exchange";
    $stmt = $conn->prepare($deduct_query);
    $stmt->bind_param("iisii", $user_id, $amount, $description, $request_id, $request['recipient_id']);
    $stmt->execute();

    // Add credits to recipient
    $add_query = "INSERT INTO user_credits (user_id, credits, transaction_type, description, related_request_id, transfer_from_user_id) 
                  VALUES (?, ?, 'TRANSFER_IN', ?, ?, ?)";
    $description = "Credit transfer from user ID: " . $user_id . " for skill exchange";
    $stmt = $conn->prepare($add_query);
    $stmt->bind_param("iisii", $request['recipient_id'], $amount, $description, $request_id, $user_id);
    $stmt->execute();

    // Update request status
    $update_query = "UPDATE skill_exchange_requests 
                    SET transfer_status = 'COMPLETED', 
                        transfer_completed_at = CURRENT_TIMESTAMP,
                        status = 'COMPLETED' 
                    WHERE id = ?";
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param("i", $request_id);
    $stmt->execute();

    // Create notifications for both users
    $notification_query = "INSERT INTO notifications (user_id, message, related_request_id) VALUES (?, ?, ?)";
    
    // Notification for recipient
    $recipient_msg = "You have received " . $amount . " credits for the skill exchange.";
    $stmt = $conn->prepare($notification_query);
    $stmt->bind_param("isi", $request['recipient_id'], $recipient_msg, $request_id);
    $stmt->execute();

    // Notification for requester
    $requester_msg = "You have transferred " . $amount . " credits for the skill exchange.";
    $stmt = $conn->prepare($notification_query);
    $stmt->bind_param("isi", $user_id, $requester_msg, $request_id);
    $stmt->execute();

    // Commit transaction
    $conn->commit();

    // Get updated balances
    $balance_query = "SELECT user_id, 
        COALESCE(SUM(CASE 
            WHEN transaction_type IN ('EARNED', 'TRANSFER_IN') THEN credits 
            WHEN transaction_type IN ('SPENT', 'TRANSFER_OUT') THEN -credits 
        END), 0) as balance 
        FROM user_credits 
        WHERE user_id IN (?, ?) 
        GROUP BY user_id";
    
    $stmt = $conn->prepare($balance_query);
    $stmt->bind_param("ii", $user_id, $request['recipient_id']);
    $stmt->execute();
    $balances = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $response = [
        'success' => true,
        'message' => 'Credit transfer completed successfully',
        'transfer_id' => $transfer_id,
        'balances' => $balances
    ];

    echo json_encode($response);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
?> 