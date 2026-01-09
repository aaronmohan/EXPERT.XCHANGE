<?php
// Add session security measures before starting the session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);

// Start session and set session cookie parameters
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true
]);

session_start();

// Prevent any output before JSON response
ob_start();

// Set JSON content type header
header('Content-Type: application/json');

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_email']) || empty($_SESSION['user_email']) || !isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Please log in to continue'
    ]);
    exit();
}

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['action'])) {
        throw new Exception('Invalid request data: action is required');
    }

    $action = $data['action'];
    $current_user_id = $_SESSION['user_id'];
    
    // Get selected skill from request data
    $selected_skill = isset($data['selected_skill']) ? $data['selected_skill'] : null;
    $request_id = isset($data['request_id']) ? intval($data['request_id']) : null;

    if ($action === 'request') {
        // Validate required fields for new request
        if (!isset($data['recipient_id']) || !isset($data['skill_requested'])) {
            throw new Exception('Invalid request data: recipient_id and skill_requested are required');
        }

        $recipient_id = intval($data['recipient_id']);
        $skill_requested = $data['skill_requested'];

        // Start transaction
        $conn->begin_transaction();

        try {
            // Verify user exists in database and matches session
            $verify_user = $conn->prepare("SELECT id FROM users WHERE id = ? AND email = ? LIMIT 1");
            if (!$verify_user) {
                throw new Exception('Failed to prepare user verification query');
            }
            $verify_user->bind_param("is", $current_user_id, $_SESSION['user_email']);
            $verify_user->execute();
            if ($verify_user->get_result()->num_rows === 0) {
                throw new Exception('Unauthorized access');
            }

            // Verify the recipient exists and is not the same as requester
            $verify_recipient = $conn->prepare("SELECT id FROM users WHERE id = ?");
            if (!$verify_recipient) {
                throw new Exception('Failed to prepare recipient verification query');
            }
            $verify_recipient->bind_param("i", $recipient_id);
            $verify_recipient->execute();
            if ($verify_recipient->get_result()->num_rows === 0) {
                throw new Exception('Invalid recipient');
            }

            if ($recipient_id === $current_user_id) {
                throw new Exception('Cannot request exchange with yourself');
            }

            // Check if there's any existing request between these users for this skill
            $check_duplicate = $conn->prepare("SELECT id FROM skill_exchange_requests 
                              WHERE ((requester_id = ? AND recipient_id = ?) 
                              OR (requester_id = ? AND recipient_id = ?))
                              AND skill_requested = ? AND status = 'pending'");
            
            if (!$check_duplicate) {
                throw new Exception('Failed to prepare duplicate check query');
            }

            $check_duplicate->bind_param("iiiis", $current_user_id, $recipient_id, $recipient_id, $current_user_id, $skill_requested);
            $check_duplicate->execute();
            if ($check_duplicate->get_result()->num_rows > 0) {
                throw new Exception('A pending request already exists for this skill exchange');
            }

            // Create new request
            $create_request = $conn->prepare("INSERT INTO skill_exchange_requests 
                             (requester_id, recipient_id, skill_requested, status, created_at) 
                             VALUES (?, ?, ?, 'pending', NOW())");
            
            if (!$create_request) {
                throw new Exception('Failed to prepare create request query');
            }

            $create_request->bind_param("iis", $current_user_id, $recipient_id, $skill_requested);
            if (!$create_request->execute()) {
                throw new Exception('Failed to create request');
            }

            $request_id = $conn->insert_id;

            // Get requester's name for the notification
            $name_query = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $name_query->bind_param("i", $current_user_id);
            $name_query->execute();
            $requester_name = $name_query->get_result()->fetch_assoc()['full_name'];
            
            // Create notification for recipient
            $message = "{$requester_name} has requested to learn {$skill_requested} from you";
            $create_notification = $conn->prepare("INSERT INTO notifications (user_id, message, related_request_id, created_at, is_read) 
                                VALUES (?, ?, ?, NOW(), 0)");
            
            if (!$create_notification) {
                throw new Exception('Failed to prepare notification query');
            }

            $create_notification->bind_param("isi", $recipient_id, $message, $request_id);
            if (!$create_notification->execute()) {
                throw new Exception('Failed to create notification');
            }

            $conn->commit();
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Request sent successfully'
            ]);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }

    // Handle existing request actions (accept/decline)
    if (!isset($data['request_id'])) {
        throw new Exception('Invalid request data: request_id is required');
    }

    $request_id = intval($data['request_id']);
    $skill_requested = isset($data['skill_requested']) ? $data['skill_requested'] : null;

    if ($action === 'accept') {
        if (!$request_id) {
            throw new Exception('Request ID is required');
        }
        
        if (!$selected_skill) {
            throw new Exception('Please select a skill');
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // Verify the request exists and belongs to the recipient
            $verify_request = $conn->prepare("SELECT r.requester_id, r.skill_requested, u.full_name 
                                           FROM skill_exchange_requests r 
                                           JOIN users u ON r.requester_id = u.id 
                                           WHERE r.id = ? AND r.recipient_id = ? AND r.status = 'pending'");
            $verify_request->bind_param("ii", $request_id, $current_user_id);
            $verify_request->execute();
            $request_result = $verify_request->get_result();
            
            if ($request_result->num_rows === 0) {
                throw new Exception('Invalid request');
            }

            $request_data = $request_result->fetch_assoc();
            $requester_id = $request_data['requester_id'];
            $skill_requested = $request_data['skill_requested'];
            $requester_name = $request_data['full_name'];

            // Update request status and selected skill
            $update_request = $conn->prepare("UPDATE skill_exchange_requests 
                                           SET status = 'in_progress', 
                                               selected_skill = ?,
                                               updated_at = NOW() 
                                           WHERE id = ?");
            $update_request->bind_param("si", $selected_skill, $request_id);
            if (!$update_request->execute()) {
                throw new Exception('Failed to update request status');
            }

            // Get recipient's name for notifications
            $recipient_name_query = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $recipient_name_query->bind_param("i", $current_user_id);
            $recipient_name_query->execute();
            $recipient_name = $recipient_name_query->get_result()->fetch_assoc()['full_name'];

            // Create notification for requester
            $requester_message = "{$recipient_name} has accepted your request to learn {$skill_requested} and wants to learn {$selected_skill} in return. The skill exchange is now in progress!";
            $create_notification = $conn->prepare("INSERT INTO notifications (user_id, message, related_request_id, created_at, is_read) 
                                                VALUES (?, ?, ?, NOW(), 0)");
            
            if (!$create_notification) {
                throw new Exception('Failed to prepare notification query');
            }

            $create_notification->bind_param("isi", $requester_id, $requester_message, $request_id);
            if (!$create_notification->execute()) {
                throw new Exception('Failed to create notification');
            }

            // Create notification for recipient
            $recipient_message = "You have accepted {$requester_name}'s request to learn {$skill_requested} and will teach {$selected_skill} in return. The skill exchange is now in progress!";
            $create_notification->bind_param("isi", $current_user_id, $recipient_message, $request_id);
            if (!$create_notification->execute()) {
                throw new Exception('Failed to create notification');
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => "Request accepted! You will learn {$selected_skill} from {$requester_name}.",
                'status' => 'in_progress'
            ]);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } else if ($action === 'decline') {
        if (!isset($data['request_id'])) {
            throw new Exception('Request ID is required');
        }

        $request_id = $data['request_id'];
        
        // Get request details first
        $get_request = "SELECT r.*, u.full_name as requester_name 
                      FROM skill_exchange_requests r 
                      JOIN users u ON r.requester_id = u.id 
                      WHERE r.id = ? AND r.recipient_id = ?";
        $stmt = $conn->prepare($get_request);
        $stmt->bind_param("ii", $request_id, $current_user_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$request) {
            throw new Exception('Request not found or unauthorized');
        }

        // Get decliner's full name
        $get_decliner = "SELECT full_name FROM users WHERE id = ?";
        $stmt = $conn->prepare($get_decliner);
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $decliner = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Update request status to declined
        $update_query = "UPDATE skill_exchange_requests SET status = 'declined' WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $stmt->close();

        // Send notification to requester
        $notification_message = $decliner['full_name'] . " has declined your skill exchange request for " . $request['skill_requested'];
        $insert_notification = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
        $stmt = $conn->prepare($insert_notification);
        $stmt->bind_param("is", $request['requester_id'], $notification_message);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Request declined successfully']);
        exit();
    } else if ($action === 'confirm_exchange') {
        if (!$request_id) {
            throw new Exception('Request ID is required');
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // Verify the request exists and belongs to the requester
            $verify_request = $conn->prepare("SELECT recipient_id, skill_requested, selected_skill FROM skill_exchange_requests 
                                            WHERE id = ? AND requester_id = ? AND status = 'awaiting_confirmation'");
            $verify_request->bind_param("ii", $request_id, $current_user_id);
            $verify_request->execute();
            $request_result = $verify_request->get_result();
            
            if ($request_result->num_rows === 0) {
                throw new Exception('Invalid request or wrong status');
            }

            $request_data = $request_result->fetch_assoc();
            $recipient_id = $request_data['recipient_id'];
            $skill_requested = $request_data['skill_requested'];
            $selected_skill = $request_data['selected_skill'];

            // Update request status to 'in_progress'
            $update_request = $conn->prepare("UPDATE skill_exchange_requests SET status = 'in_progress' WHERE id = ?");
            $update_request->bind_param("i", $request_id);
            if (!$update_request->execute()) {
                throw new Exception('Failed to update request status');
            }

            // Create notifications for both users
            $exchange_message = "Skill exchange started! You will learn {$skill_requested} and teach {$selected_skill}.";
            $create_notifications = $conn->prepare("INSERT INTO notifications (user_id, message, related_request_id, type) VALUES (?, ?, ?, 'exchange_started')");
            
            // Notify requester
            $create_notifications->bind_param("isi", $current_user_id, $exchange_message, $request_id);
            if (!$create_notifications->execute()) {
                throw new Exception('Failed to create requester notification');
            }

            // Notify recipient
            $recipient_message = "Skill exchange started! You will teach {$skill_requested} and learn {$selected_skill}.";
            $create_notifications->bind_param("isi", $recipient_id, $recipient_message, $request_id);
            if (!$create_notifications->execute()) {
                throw new Exception('Failed to create recipient notification');
            }

            // Initialize rating entries for both users
            $init_rating_sql = "INSERT INTO user_ratings (exchange_request_id, rater_user_id, rated_user_id, status) VALUES (?, ?, ?, 'pending')";
            $init_rating_stmt = $conn->prepare($init_rating_sql);

            // Create rating entries for both users
            $init_rating_stmt->bind_param("iii", $request_id, $current_user_id, $recipient_id);
            if (!$init_rating_stmt->execute()) {
                throw new Exception('Failed to create rating entry for requester');
            }

            $init_rating_stmt->bind_param("iii", $request_id, $recipient_id, $current_user_id);
            if (!$init_rating_stmt->execute()) {
                throw new Exception('Failed to create rating entry for recipient');
            }

            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Skill exchange started successfully',
                'status' => 'in_progress'
            ]);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit();
        }
    } else if ($action === 'review') {
        if (!isset($data['exchange_id']) || !isset($data['rating']) || !isset($data['comment'])) {
            throw new Exception('Invalid review data: exchange_id, rating, and comment are required');
        }

        $exchange_id = intval($data['exchange_id']);
        $rating = intval($data['rating']);
        $comment = $data['comment'];

        if ($rating < 1 || $rating > 5) {
            throw new Exception('Rating must be between 1 and 5');
        }

        // Verify the exchange exists and user is part of it
        $verify_exchange = "SELECT requester_id, recipient_id 
                          FROM skill_exchange_requests 
                          WHERE id = ? AND (requester_id = ? OR recipient_id = ?)
                          AND status = 'accepted'";
        
        $verify_stmt = $conn->prepare($verify_exchange);
        $verify_stmt->bind_param("iii", $exchange_id, $current_user_id, $current_user_id);
        $verify_stmt->execute();
        $exchange = $verify_stmt->get_result()->fetch_assoc();

        if (!$exchange) {
            throw new Exception('Exchange not found or not authorized to review');
        }

        // Determine which user to rate
        $rated_user_id = ($exchange['requester_id'] == $current_user_id) 
            ? $exchange['recipient_id'] 
            : $exchange['requester_id'];

        // Update the rating
        $update_rating = "UPDATE user_ratings 
                         SET stars = ?, comment = ?, updated_at = NOW()
                         WHERE rated_user_id = ? AND rater_user_id = ?";
        
        $rating_stmt = $conn->prepare($update_rating);
        $rating_stmt->bind_param("isii", $rating, $comment, $rated_user_id, $current_user_id);
        
        if (!$rating_stmt->execute()) {
            throw new Exception('Failed to update rating');
        }

        // Update user's average rating
        $update_avg = "UPDATE users u 
                      SET rating = (
                          SELECT AVG(stars) 
                          FROM user_ratings 
                          WHERE rated_user_id = u.id AND stars > 0
                      )
                      WHERE id = ?";
        
        $avg_stmt = $conn->prepare($update_avg);
        $avg_stmt->bind_param("i", $rated_user_id);
        
        if (!$avg_stmt->execute()) {
            throw new Exception('Failed to update average rating');
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Review submitted successfully'
        ]);
        exit();
    } else if ($action === 'credit_transfer') {
        if (!isset($data['request_id']) || !isset($data['credits'])) {
            throw new Exception('Missing required data for credit transfer');
        }

        $request_id = $data['request_id'];
        $credits = $data['credits'];

        // Start transaction
        $conn->begin_transaction();

        try {
            // Get request details with FOR UPDATE to lock the row
            $request_query = "SELECT requester_id, recipient_id, status FROM skill_exchange_requests WHERE id = ? FOR UPDATE";
            $stmt = $conn->prepare($request_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare request query');
            }
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$request) {
                throw new Exception('Request not found');
            }

            if ($request['status'] !== 'pending') {
                throw new Exception('Request is no longer pending');
            }

            if ($request['recipient_id'] !== $current_user_id) {
                throw new Exception('Unauthorized to accept this request');
            }

            // Check if requester has enough credits
            $requester_credits_query = "SELECT COALESCE(SUM(credits), 500) as total_credits FROM user_credits WHERE user_id = ? FOR UPDATE";
            $stmt = $conn->prepare($requester_credits_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare credits query');
            }
            $stmt->bind_param("i", $request['requester_id']);
            $stmt->execute();
            $requester_credits = $stmt->get_result()->fetch_assoc()['total_credits'];
            $stmt->close();

            if ($requester_credits < $credits) {
                throw new Exception('Requester does not have enough credits');
            }

            // Deduct credits from requester
            $deduct_query = "INSERT INTO user_credits (user_id, credits, transaction_type, description) VALUES (?, ?, 'debit', 'Skill exchange credit transfer')";
            $stmt = $conn->prepare($deduct_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare deduct query');
            }
            $debit_amount = -$credits;
            $stmt->bind_param("ii", $request['requester_id'], $debit_amount);
            $stmt->execute();
            $stmt->close();

            // Add credits to recipient
            $add_query = "INSERT INTO user_credits (user_id, credits, transaction_type, description) VALUES (?, ?, 'credit', 'Skill exchange credit transfer')";
            $stmt = $conn->prepare($add_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare credit query');
            }
            $stmt->bind_param("ii", $current_user_id, $credits);
            $stmt->execute();
            $stmt->close();

            // Update request status
            $update_query = "UPDATE skill_exchange_requests SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'pending'";
            $stmt = $conn->prepare($update_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare update query');
            }
            $stmt->bind_param("i", $request_id);
            if (!$stmt->execute() || $stmt->affected_rows === 0) {
                throw new Exception('Failed to update request status');
            }
            $stmt->close();

            // Add notification for requester
            $notification_query = "INSERT INTO notifications (user_id, message, related_request_id, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($notification_query);
            if (!$stmt) {
                throw new Exception('Failed to prepare notification query');
            }
            $message = "Your skill exchange request has been accepted. {$credits} credits have been transferred.";
            $stmt->bind_param("isi", $request['requester_id'], $message, $request_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            echo json_encode([
                'success' => true, 
                'message' => 'Credit transfer completed successfully',
                'new_credits' => $requester_credits - $credits
            ]);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn && $conn->connect_errno === 0) {
        $conn->rollback();
    }
    
    error_log('Request handling error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 