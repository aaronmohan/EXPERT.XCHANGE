<?php
require_once 'session_config.php';
require_once 'config.php';

// Basic session check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_email'])) {
    header("Location: home.html");
    exit();
}

$user_id = $_SESSION['user_id'];
$email = $_SESSION['user_email'];

// Get user's basic info
$user_query = "SELECT u.*, p.*, 
               (SELECT COUNT(*) FROM skills WHERE user_id = u.id) as skills_count,
               (SELECT COALESCE(AVG(stars), 5) FROM user_ratings WHERE rated_user_id = u.id) as avg_rating,
               (SELECT COUNT(*) FROM user_ratings WHERE rated_user_id = u.id) as rating_count,
               COALESCE((SELECT SUM(credits) FROM user_credits WHERE user_id = u.id), 500) as total_credits
               FROM users u 
               LEFT JOIN user_profiles p ON u.id = p.user_id 
               WHERE u.id = ? AND u.email = ?";

$stmt = $conn->prepare($user_query);
if (!$stmt) {
    error_log("Failed to prepare user query: " . $conn->error);
    header("Location: home.html");
    exit();
}

$stmt->bind_param("is", $user_id, $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    error_log("User not found: ID = {$user_id}, Email = {$email}");
    session_unset();
    session_destroy();
    header("Location: home.html");
    exit();
}

// Initialize notification count
$notification_count = 0;
$count_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    $count_stmt->bind_param("i", $user_id);
    if ($count_stmt->execute()) {
        $count_result = $count_stmt->get_result();
        if ($count_row = $count_result->fetch_assoc()) {
            $notification_count = $count_row['count'];
        }
    }
    $count_stmt->close();
}

// Format the stats
$credits = $user['total_credits'] ?? 500;
$rating = number_format($user['avg_rating'] ?? 5, 1);
$rating_count = $user['rating_count'] ?? 0;
$skills_count = $user['skills_count'] ?? 0;

// Get user skills
$skills = [];
$skills_query = "SELECT skill_name, proficiency_level 
                FROM skills 
                WHERE user_id = ? 
                ORDER BY skill_name";

$skills_stmt = $conn->prepare($skills_query);
if ($skills_stmt) {
    $skills_stmt->bind_param("i", $user_id);
    if ($skills_stmt->execute()) {
        $skills_result = $skills_stmt->get_result();
        while($skill = $skills_result->fetch_assoc()) {
            $skills[] = $skill;
        }
    }
    $skills_stmt->close();
}

// Initialize search results
$search_results = null;
if(isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $conn->real_escape_string($_GET['search']) . '%';
    $search_query = "SELECT DISTINCT u.id, u.full_name, p.experience_level,
                    (SELECT AVG(stars) FROM user_ratings WHERE rated_user_id = u.id) as rating,
                    (SELECT COUNT(*) FROM user_ratings WHERE rated_user_id = u.id) as rating_count,
                    GROUP_CONCAT(DISTINCT CONCAT(s.skill_name, '|', s.proficiency_level) SEPARATOR ',') as skills,
                    (SELECT status 
                     FROM skill_exchange_requests 
                     WHERE requester_id = ? AND recipient_id = u.id 
                     AND status = 'pending'
                     ORDER BY created_at DESC 
                     LIMIT 1) as request_status
                    FROM users u 
                    LEFT JOIN user_profiles p ON u.id = p.user_id
                    LEFT JOIN skills s ON u.id = s.user_id
                    WHERE s.skill_name LIKE ? AND u.id != ?
                    GROUP BY u.id, u.full_name, p.experience_level";
    
    $search_stmt = $conn->prepare($search_query);
    if ($search_stmt) {
        $current_user_id = $user['id'];
        if ($search_stmt->bind_param("isi", $current_user_id, $search, $current_user_id)) {
            if ($search_stmt->execute()) {
                $search_results = $search_stmt->get_result();
            }
        }
        $search_stmt->close();
    }
}

// Add this variable for debugging
$user_id = $user['id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Expert.Xchange</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fb;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .profile-header {
            background-color: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
        }
        
        .header-actions {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .edit-profile-btn {
            background-color: #f0f4ff;
            color: #2a7de1;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .edit-profile-btn:hover {
            background-color: #2a7de1;
            color: white;
        }
        
        .notification-btn {
            background: none;
            border: none;
            color: #2a7de1;
            font-size: 20px;
            cursor: pointer;
            position: relative;
            padding: 5px;
            transition: color 0.3s;
        }
        
        .notification-btn:hover {
            color: #1c68c5;
        }
        
        .notification-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
            min-width: 18px;
            text-align: center;
        }
        
        .logout-btn {
            background-color: #f0f4ff;
            color: #2a7de1;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background-color: #2a7de1;
            color: white;
        }
        
        .avatar {
            width: 100px;
            height: 100px;
            background-color: #2a7de1;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-info h1 {
            font-size: 32px;
            margin-bottom: 20px;
            color: #333;
        }
        
        .stats {
            display: flex;
            gap: 30px;
        }
        
        .stat-item {
            background-color: #f8f9ff;
            padding: 15px 25px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2a7de1;
            margin-bottom: 5px;
        }
        
        .star-rating {
            color: #ffc107;
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .rating-count {
            color: #666;
            font-size: 12px;
            margin-top: 2px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }
        
        .profile-section {
            background-color: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-title {
            font-size: 20px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .profile-details dl {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 15px;
        }
        
        .profile-details dt {
            color: #666;
            font-weight: 500;
        }
        
        .profile-details dd {
            color: #333;
        }
        
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .skill-tag {
            background-color: #f0f4ff;
            color: #2a7de1;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .skill-tag .proficiency {
            background-color: #2a7de1;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
        }
        
        .skill-tag:hover {
            background-color: #2a7de1;
            color: white;
        }
        
        .skill-tag:hover .proficiency {
            background-color: white;
            color: #2a7de1;
        }
        
        .skill-tag[data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background-color: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            margin-bottom: 5px;
            z-index: 1;
        }
        
        .skill-tag[data-tooltip]:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: #333;
            margin-bottom: -5px;
        }
        
        .search-box {
            width: 100%;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 16px;
        }
        
        .search-box:focus {
            outline: none;
            border-color: #2a7de1;
        }
        
        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .stats {
                flex-direction: column;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .avatar {
                margin: 0 auto;
            }
            
            .header-actions {
                position: static;
                margin-top: 20px;
                width: 100%;
                justify-content: center;
            }
        }
        
        .search-results {
            margin-top: 20px;
        }
        
        .user-card {
            background-color: #f8f9ff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .user-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .user-info h3 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .user-stats {
            display: flex;
            gap: 20px;
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .user-skills {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .request-exchange {
            background-color: #2a7de1;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .request-exchange:hover {
            background-color: #1c68c5;
            transform: translateY(-1px);
        }

        .request-sent {
            background-color: #28a745 !important;
            cursor: default;
        }

        .request-sent:hover {
            background-color: #28a745 !important;
            transform: none;
        }
        
        #notificationModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            margin: 50px auto;
            position: relative;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .modal-header {
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #333;
        }

        .exchange-details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }

        .skill-options {
            margin: 15px 0;
        }

        .skill-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .skill-option:hover {
            background-color: #f0f4ff;
        }

        .skill-option.selected {
            background-color: #2a7de1;
            color: white;
            border-color: #2a7de1;
        }

        .skill-option .proficiency {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            background-color: rgba(255, 255, 255, 0.2);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .action-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .accept-action {
            background-color: #e9ecef;
            color: #28a745;
        }

        .decline-action {
            background-color: #e9ecef;
            color: #dc3545;
        }

        .action-btn:hover.accept-action {
            background-color: #28a745;
            color: white;
        }

        .action-btn:hover.decline-action {
            background-color: #dc3545;
            color: white;
        }

        .action-btn.selected {
            color: white;
        }

        .action-btn.selected.accept-action {
            background-color: #28a745;
        }

        .action-btn.selected.decline-action {
            background-color: #dc3545;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            font-size: 24px;
            color: #666;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: #333;
        }

        /* Add styles for notification dropdown */
        .notification-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 300px;
            max-height: 400px;
            overflow-y: auto;
            z-index: 1000;
        }

        .notification-dropdown.show {
            display: block;
        }

        .notification-item {
            position: relative;
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: opacity 0.3s ease;
        }

        .notification-content {
            flex: 1;
            cursor: pointer;
            padding-right: 10px;
        }

        .notification-item:hover {
            background-color: #f8f9fa;
        }

        .notification-item .message {
            margin-bottom: 5px;
        }

        .notification-item .time {
            font-size: 12px;
            color: #666;
        }

        .delete-notification {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 5px;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.2s ease;
        }

        .notification-item:hover .delete-notification {
            opacity: 1;
        }

        .delete-notification:hover {
            transform: scale(1.1);
        }

        .no-notifications {
            padding: 15px;
            text-align: center;
            color: #666;
            font-style: italic;
        }

        .unread {
            background-color: #f0f4ff;
        }

        .skill-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            padding: 5px;
        }

        .skill-container .request-exchange {
            font-size: 14px;
            padding: 8px 16px;
            min-width: 130px;
            text-align: center;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .action-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #f0f4ff;
            color: #2a7de1;
        }
        .action-btn.selected {
            background-color: #2a7de1;
            color: white;
        }
        .accept-action:hover {
            background-color: #28a745;
            color: white;
        }
        .decline-action:hover {
            background-color: #dc3545;
            color: white;
        }
        .confirm-btn {
            width: 100%;
            padding: 12px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        .confirm-btn:hover {
            background-color: #218838;
        }
        .action-btn.selected.decline-action {
            background-color: #dc3545;
            color: white;
        }
        .action-btn.selected.accept-action {
            background-color: #28a745;
            color: white;
        }
        .action-btn.selected.transfer-action {
            background-color: #ffc107;
            color: #000;
        }

        .exchange-status {
            margin: 15px 0;
            padding: 20px;
            background-color: #f8f9ff;
            border-radius: 8px;
            text-align: center;
        }

        .exchange-progress {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .status-message {
            color: #2a7de1;
            font-weight: 500;
            font-size: 16px;
        }

        .done-btn {
            background-color: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 16px;
            width: 150px;
        }

        .done-btn:hover {
            background-color: #218838;
            transform: translateY(-1px);
        }

        .review-section {
            margin-top: 20px;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            border: 1px solid #eee;
        }

        .star-rating-input {
            font-size: 32px;
            margin: 20px 0;
            text-align: center;
        }

        .star-rating-input i {
            margin: 0 5px;
            cursor: pointer;
            color: #e0e0e0;
            transition: all 0.2s ease;
        }

        .star-rating-input i.selected {
            color: #ffc107;
        }

        .submit-review-btn {
            background-color: #2a7de1;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            font-size: 16px;
        }

        .submit-review-btn:hover {
            background-color: #1c68c5;
            transform: translateY(-1px);
        }

        #confirmButton {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 15px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }

        #confirmButton:hover {
            background-color: #45a049;
        }

        #exchangeStatus {
            margin-top: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 4px;
            text-align: center;
        }

        #exchangeStatus .status-message {
            color: #28a745;
            font-size: 16px;
            margin-bottom: 15px;
        }

        #exchangeStatus button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        #exchangeStatus button:hover {
            background-color: #0056b3;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .action-btn {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .accept-action {
            background-color: #e9ecef;
            color: #28a745;
        }

        .decline-action {
            background-color: #e9ecef;
            color: #dc3545;
        }

        .action-btn.selected {
            color: white;
        }

        .accept-action.selected {
            background-color: #28a745;
        }

        .decline-action.selected {
            background-color: #dc3545;
        }

        /* Add these styles */
        .skill-option {
            display: flex;
            align-items: center;
            padding: 10px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .skill-option:hover {
            background-color: #f0f4ff;
        }

        .skill-option.selected {
            background-color: #2a7de1;
            color: white;
            border-color: #2a7de1;
        }

        .skill-option .proficiency {
            margin-left: auto;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            background-color: rgba(255, 255, 255, 0.2);
        }

        /* Add these styles */
        .clear-requests-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin: 10px 0;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-block;
            width: auto;
        }

        .clear-requests-btn:hover {
            background-color: #c82333;
        }

        .clear-requests-btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }

        .confirm-checkbox {
            display: block;
            margin: 20px 0;
            user-select: none;
        }

        .confirm-btn {
            width: 100%;
            padding: 12px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .confirm-btn:hover {
            background-color: #218838;
        }

        .star-rating {
            font-size: 24px;
            color: #ddd;
            margin: 20px 0;
        }

        .star {
            cursor: pointer;
            transition: color 0.2s;
        }

        .star.selected,
        .star:hover,
        .star:hover ~ .star {
            color: #ffd700;
        }

        #reviewComment {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            resize: vertical;
        }

        .waiting-indicator {
            text-align: center;
            margin: 20px 0;
            color: #666;
        }
        
        .waiting-indicator i {
            font-size: 24px;
            color: #2a7de1;
            margin-bottom: 10px;
        }
        
        .exchange-details {
            background-color: #f8f9ff;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        #completeExchangeBtn {
            margin-top: 20px;
        }
        
        .star-rating-input i {
            color: #ddd;
            cursor: pointer;
            font-size: 24px;
            margin: 0 2px;
            transition: color 0.2s;
        }
        
        .star-rating-input i.selected {
            color: #ffd700;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2a7de1;
            color: white;
            padding: 15px 25px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 1000;
            opacity: 0;
            transform: translateY(-100%);
            transition: all 0.3s ease;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4444;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 12px;
            display: none;
        }

        .unread-badge {
            width: 8px;
            height: 8px;
            background: #2a7de1;
            border-radius: 50%;
            display: inline-block;
            margin-left: 10px;
        }

        /* Add these styles to ensure proper modal display */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background-color: #fff;
            margin: 15% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
            position: relative;
        }

        .modal-close {
            position: absolute;
            right: 10px;
            top: 10px;
            font-size: 24px;
            cursor: pointer;
        }

        .skill-option {
            padding: 10px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .skill-option.selected {
            background-color: #2a7de1;
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .action-btn {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .accept-action {
            background-color: #28a745;
            color: white;
        }

        .decline-action {
            background-color: #dc3545;
            color: white;
        }

        .exchange-details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
        }

        .exchange-details p {
            margin: 8px 0;
        }

        .exchange-details strong {
            color: #2a7de1;
        }

        #exchangeStatusModal .modal-content {
            max-width: 600px;
        }

        #exchangeStatusMessage {
            font-size: 16px;
            color: #28a745;
            margin-bottom: 15px;
        }

        .response-options {
            margin: 20px 0;
        }

        .option-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .skill-exchange-btn {
            background-color: #2a7de1;
            color: white;
        }

        .credit-transfer-btn {
            background-color: #28a745;
            color: white;
        }

        .back-btn {
            background-color: #6c757d;
            color: white;
        }

        .credit-value {
            font-size: 18px;
            color: #28a745;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="profile-header">
            <div class="header-actions">
                <a href="edit-profile.php" class="edit-profile-btn">
                    <i class="fas fa-edit"></i>
                    Edit Profile
                </a>
                <button class="notification-btn" onclick="toggleNotifications()">
                    <i class="fas fa-bell"></i>
                    <?php
                    if($notification_count > 0): ?>
                        <span class="notification-count"><?php echo $notification_count; ?></span>
                    <?php endif; ?>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <?php
                    try {
                        // Get recent notifications
                        $notifications_query = "SELECT id, message, is_read, created_at 
                                             FROM notifications 
                                             WHERE user_id = ? 
                                             ORDER BY created_at DESC LIMIT 10";
                        $notifications_stmt = $conn->prepare($notifications_query);
                        
                        if (!$notifications_stmt) {
                            throw new Exception("Failed to prepare notifications query");
                        }

                        if (!$notifications_stmt->bind_param("i", $user_id)) {
                            throw new Exception("Failed to bind parameter for notifications");
                        }

                        if (!$notifications_stmt->execute()) {
                            throw new Exception("Failed to execute notifications query");
                        }

                        $notifications = $notifications_stmt->get_result();
                        
                        if($notifications && $notifications->num_rows > 0):
                            while($notification = $notifications->fetch_assoc()): 
                                $notification_id = intval($notification['id']);
                                ?>
                                <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>"
                                     data-notification-id="<?php echo $notification_id; ?>">
                                    <div class="notification-content" onclick="handleNotificationClick(<?php echo $notification_id; ?>)">
                                        <div class="message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div class="time"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></div>
                                    </div>
                                    <button class="delete-notification" onclick="deleteNotification(event, <?php echo $notification_id; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            <?php endwhile;
                        else: ?>
                            <div class="no-notifications">No notifications</div>
                        <?php endif;
                        
                        $notifications_stmt->close();
                        
                    } catch (Exception $e) {
                        error_log("Error loading notifications: " . $e->getMessage());
                        echo '<div class="notification-item"><div class="message">Error loading notifications</div></div>';
                    }
                    ?>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
            <div class="avatar">
                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($user['full_name']); ?></h1>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $credits; ?></div>
                        <div class="stat-label">Credits</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $rating; ?></div>
                        <div class="rating-count"><?php echo $rating_count; ?> ratings</div>
                        <div class="stat-label">Rating</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $skills_count; ?></div>
                        <div class="stat-label">Skills</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="content-grid">
            <div class="profile-section">
                <h2 class="section-title">Profile Details</h2>
                <div class="profile-details">
                    <dl>
                        <dt>Name</dt>
                        <dd><?php echo htmlspecialchars($user['full_name']); ?></dd>
                        
                        <dt>Email</dt>
                        <dd><?php echo htmlspecialchars($user['email']); ?></dd>
                        
                        <dt>Phone</dt>
                        <dd><?php echo htmlspecialchars($user['phone_number']); ?></dd>
                        
                        <dt>Qualification</dt>
                        <dd><?php echo htmlspecialchars($user['qualification'] ?? "Not specified"); ?></dd>
                        
                        <dt>Member Since</dt>
                        <dd><?php echo date('F Y', strtotime($user['created_at'])); ?></dd>
                    </dl>
                </div>
                
                <h2 class="section-title" style="margin-top: 30px;">My Skills</h2>
                <div class="skills-list">
                    <?php if (!empty($skills)): ?>
                        <?php foreach($skills as $skill): ?>
                            <span class="skill-tag">
                                <?php echo htmlspecialchars($skill['skill_name']); ?>
                                <span class="proficiency"><?php echo htmlspecialchars($skill['proficiency_level']); ?></span>
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #666; font-style: italic;">No skills added yet.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="profile-section">
                <h2 class="section-title">Find Skills</h2>
                <form method="GET" action="" class="search-form">
                    <input type="text" name="search" class="search-box" placeholder="Search for skills..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                </form>
                
                <div class="search-results">
                    <?php if($search_results && $search_results->num_rows > 0): ?>
                        <?php while($row = $search_results->fetch_assoc()): 
                            $full_name = htmlspecialchars($row['full_name']);
                            $rating = number_format($row['rating'] ?? 0, 1);
                            $rating_count = $row['rating_count'] ?? 0;
                            $experience_level = htmlspecialchars($row['experience_level'] ?? 'Not specified');
                            $skills = $row['skills'];
                            $user_id = $row['id'];
                            $request_status = $row['request_status'];
                        ?>
                            <div class="user-card">
                                <div class="user-card-header">
                                    <div class="user-info">
                                        <h3><?php echo $full_name; ?></h3>
                                        <div class="user-stats">
                                            <span>Rating: <?php echo $rating; ?> (<?php echo $rating_count; ?> reviews)</span>
                                            <span>Level: <?php echo $experience_level; ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="user-skills">
                                    <?php 
                                    if(!empty($skills)) {
                                        $user_skills = explode(',', $skills);
                                        foreach($user_skills as $skill_data): 
                                            list($skill_name, $proficiency) = explode('|', $skill_data);
                                            if($skill_name !== ''): ?>
                                                <div class="skill-container">
                                                    <span class="skill-tag">
                                                        <?php echo htmlspecialchars($skill_name); ?>
                                                        <span class="proficiency"><?php echo htmlspecialchars($proficiency); ?></span>
                                                    </span>
                                                    <button class="request-exchange <?php echo $request_status === 'pending' ? 'request-sent' : ''; ?>" 
                                                            data-user-id="<?php echo $user_id; ?>"
                                                            data-skill-name="<?php echo htmlspecialchars($skill_name); ?>"
                                                            <?php echo $request_status === 'pending' ? 'disabled' : ''; ?>>
                                                        <?php echo $request_status === 'pending' ? 'Request Pending' : 'Request Exchange'; ?>
                                                    </button>
                                                </div>
                                            <?php endif;
                                        endforeach;
                                    } else { ?>
                                        <p style="color: #666; font-style: italic;">No skills added yet.</p>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>No results found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <button id="clearRequestsBtn" class="clear-requests-btn" onclick="clearAllPendingRequests()">
                Clear All Pending Requests
            </button>
        </div>
    </div>

    <!-- Notification Modal -->
    <div id="notificationModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <div class="modal-header">
                <h3>Skill Exchange Request</h3>
            </div>
            <div class="modal-body">
                <p id="modalMessage"></p>
                <div class="exchange-details">
                    <p><strong>Requested Skill:</strong> <span id="requestedSkill"></span></p>
                    <p><strong>Proficiency Level:</strong> <span id="proficiencyLevel"></span></p>
                    <p><strong>Credit Value:</strong> <span id="creditValue"></span></p>
                </div>
                <div class="response-options">
                    <div class="option-buttons">
                        <button class="action-btn skill-exchange-btn" onclick="showSkillSelection()">Skill Exchange</button>
                        <button class="action-btn credit-transfer-btn" onclick="handleCreditTransfer()">Credit Transfer</button>
                        <button class="action-btn decline-action" onclick="handleDecline()">Decline</button>
                    </div>
                </div>
                <div id="skillSelectionSection" style="display: none;">
                    <h4>Select a skill you want to learn in return:</h4>
                    <div id="requesterSkillsList" class="skill-options"></div>
                    <div class="action-buttons">
                        <button class="action-btn accept-action" onclick="handleAccept()">Accept & Select Skill</button>
                        <button class="action-btn back-btn" onclick="showResponseOptions()">Back</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeReviewModal()">&times;</button>
            <div class="modal-header">
                <h3>Review Skill Exchange</h3>
            </div>
            <div class="modal-body">
                <div class="rating-section">
                    <h4>Rate your experience:</h4>
                    <div class="star-rating">
                        <span class="star" data-rating="1">★</span>
                        <span class="star" data-rating="2">★</span>
                        <span class="star" data-rating="3">★</span>
                        <span class="star" data-rating="4">★</span>
                        <span class="star" data-rating="5">★</span>
                    </div>
                    <textarea id="reviewComment" placeholder="Share your experience (optional)" rows="4"></textarea>
                </div>
                <button id="submitReview" class="confirm-btn">Submit Review</button>
            </div>
        </div>
    </div>

    <!-- Add this HTML for the exchange status -->
    <div id="exchangeStatusModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="modal-close" onclick="closeExchangeStatusModal()">&times;</span>
            <div class="modal-header">
                <h3>Skill Exchange Status</h3>
            </div>
            <div class="modal-body">
                <div id="exchangeStatusContent">
                    <p id="exchangeStatusMessage"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        let isProcessingRequest = false;
        let currentNotificationId = null;
        let currentRequestId = null;
        let currentRequesterId = null;
        let selectedSkill = null;
        let currentAction = null;
        let selectedRating = 0;
        let currentExchangeId = null;

        function closeModal() {
            const modal = document.getElementById('notificationModal');
            if (modal) {
                modal.style.display = 'none';
                // Reset state
                selectedSkill = null;
                currentAction = null;
                // Clear skill selection
                document.querySelectorAll('.skill-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
            }
        }

        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('show');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationDropdown');
            const notificationBtn = document.querySelector('.notification-btn');
            
            if (!event.target.closest('.notification-btn') && !event.target.closest('.notification-dropdown')) {
                dropdown.classList.remove('show');
            }
        });

        async function handleNotificationClick(notificationId) {
            if (isProcessingRequest) return;
            isProcessingRequest = true;
            
            try {
                const modal = document.getElementById('notificationModal');
                const modalMessage = document.getElementById('modalMessage');
                const exchangeDetails = document.querySelector('.exchange-details');
                const responseOptions = document.querySelector('.response-options');

                const response = await fetch('get_notification_details.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ notification_id: notificationId })
                });

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.error || 'Failed to load notification details');
                }

                modalMessage.textContent = data.message || '';

                // Check if this is an accepted request notification
                if (data.message.includes('has accepted your request') || data.message.includes('is now in progress')) {
                    // Hide exchange details and response options
                    if (exchangeDetails) exchangeDetails.style.display = 'none';
                    if (responseOptions) responseOptions.style.display = 'none';
                    
                    // Show only a confirm button
                    const confirmButton = document.createElement('button');
                    confirmButton.textContent = 'Confirm';
                    confirmButton.style.cssText = `
                        display: block;
                        width: 100%;
                        padding: 10px;
                        margin-top: 15px;
                        background-color: #2a7de1;
                        color: white;
                        border: none;
                        border-radius: 5px;
                        cursor: pointer;
                        font-size: 16px;
                        transition: background-color 0.3s;
                    `;
                    confirmButton.onmouseover = () => {
                        confirmButton.style.backgroundColor = '#1c68c5';
                    };
                    confirmButton.onmouseout = () => {
                        confirmButton.style.backgroundColor = '#2a7de1';
                    };
                    confirmButton.onclick = () => {
                        modal.style.display = 'none';
                        window.location.reload();
                    };
                    
                    // Clear any existing buttons and add only the confirm button
                    const buttonContainer = document.createElement('div');
                    buttonContainer.className = 'text-center mt-3';
                    buttonContainer.appendChild(confirmButton);
                    
                    // Replace or append the button container
                    const existingButtonContainer = modal.querySelector('.text-center');
                    if (existingButtonContainer) {
                        existingButtonContainer.replaceWith(buttonContainer);
                    } else {
                        modalMessage.parentNode.appendChild(buttonContainer);
                    }
                } else {
                    // For other notifications, show the regular exchange details and options
                    if (exchangeDetails) exchangeDetails.style.display = 'block';
                    if (responseOptions) responseOptions.style.display = 'block';
                    
                    // Regular notification handling code here
                    const skillsList = document.getElementById('requesterSkillsList');
                    const skillSelectionSection = document.getElementById('skillSelectionSection');
                    const requestedSkill = document.getElementById('requestedSkill');
                    const proficiencyLevel = document.getElementById('proficiencyLevel');
                    const creditValue = document.getElementById('creditValue');

                    currentNotificationId = notificationId;
                    currentRequestId = data.request_id;
                    currentRequesterId = data.requester_id;

                    if (requestedSkill) requestedSkill.textContent = data.skill_requested || 'Not specified';
                    if (proficiencyLevel) proficiencyLevel.textContent = data.proficiency_level || 'Not specified';
                    if (creditValue) creditValue.textContent = data.credits + ' Credits';

                    // Show response options by default for regular notifications
                    if (skillSelectionSection) skillSelectionSection.style.display = 'none';
                    
                    // Clear and update skills list for regular notifications
                    if (skillsList) {
                        skillsList.innerHTML = '';
                        if (data.requester_skills) {
                            const skills = data.requester_skills.split(',');
                            skills.forEach(skillData => {
                                if (skillData && skillData.includes('|')) {
                                    const [skillName, proficiency] = skillData.split('|');
                                    const skillOption = document.createElement('div');
                                    skillOption.className = 'skill-option';
                                    skillOption.onclick = () => selectSkill(skillOption, skillName.trim());
                                    skillOption.innerHTML = `
                                        ${skillName.trim()}
                                        <span class="proficiency">${proficiency.trim()}</span>
                                    `;
                                    skillsList.appendChild(skillOption);
                                }
                            });
                        }
                    }
                }

                modal.style.display = 'block';
                await markNotificationAsRead(notificationId);

            } catch (error) {
                console.error('Error:', error);
                alert(error.message);
            } finally {
                isProcessingRequest = false;
            }
        }

        function selectSkill(element, skillName) {
            document.querySelectorAll('.skill-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            element.classList.add('selected');
            selectedSkill = skillName;
        }

        function showSkillSelection() {
            document.querySelector('.response-options').style.display = 'none';
            document.getElementById('skillSelectionSection').style.display = 'block';
        }

        function showResponseOptions() {
            document.querySelector('.response-options').style.display = 'block';
            document.getElementById('skillSelectionSection').style.display = 'none';
            // Reset skill selection
            selectedSkill = null;
            document.querySelectorAll('.skill-option').forEach(opt => {
                opt.classList.remove('selected');
            });
        }

        async function handleCreditTransfer() {
            if (isProcessingRequest) return;
            isProcessingRequest = true;

            try {
                // Get credit value from the modal
                const creditValueText = document.getElementById('creditValue').textContent;
                const credits = parseInt(creditValueText);
                
                if (isNaN(credits)) {
                    throw new Error('Invalid credit value');
                }

                // Confirm with user
                if (!confirm(`Are you sure you want to transfer ${credits} credits for this skill exchange?`)) {
                    isProcessingRequest = false;
                    return;
                }

                const response = await fetch('handle_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'credit_transfer',
                        request_id: currentRequestId,
                        credits: credits
                    })
                });

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.error || 'Failed to process credit transfer');
                }

                // Close the modal
                const modal = document.getElementById('notificationModal');
                modal.style.display = 'none';
                
                // Show success message
                alert(data.message || 'Credit transfer completed successfully!');
                
                // Update the credits display in the UI
                const creditsElement = document.querySelector('.stat-value');
                if (creditsElement && data.new_credits !== undefined) {
                    creditsElement.textContent = data.new_credits;
                }

                // Refresh the page to update all information
                window.location.reload();

            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'An error occurred during credit transfer');
            } finally {
                isProcessingRequest = false;
            }
        }

        async function handleAccept() {
            try {
                const response = await fetch('handle_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'accept',
                        request_id: currentRequestId,
                        selected_skill: selectedSkill
                    })
                });

                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to accept request');
                }

                // Close the notification modal
                closeModal();

                // Show exchange status if the request is in progress
                if (data.status === 'in_progress') {
                    const exchangeStatusModal = document.getElementById('exchangeStatusModal');
                    const message = document.getElementById('exchangeStatusMessage');

                    if (exchangeStatusModal && message) {
                        message.textContent = data.message;
                        exchangeStatusModal.style.display = 'block';
                    }
                } else {
                    alert(data.message || 'Request accepted successfully!');
                    window.location.reload();
                }
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to accept request');
            }
        }

        async function handleDecline() {
            try {
                const response = await fetch('handle_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'decline',
                        request_id: currentRequestId,
                        notification_id: currentNotificationId
                    })
                });

                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to decline request');
                }

                // Close the notification modal
                closeModal();
                
                // Show success message
                alert('Request declined successfully');
                
                // Refresh the page to update notifications
                window.location.reload();
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to decline request');
            }
        }

        function showExchangeStatus(data) {
            const modal = document.getElementById('exchangeStatusModal');
            const message = document.getElementById('exchangeStatusMessage');

            if (modal && message) {
                message.textContent = data.message;
                modal.style.display = 'block';
            }
        }

        function closeExchangeStatusModal() {
            const modal = document.getElementById('exchangeStatusModal');
            if (modal) {
                modal.style.display = 'none';
                window.location.reload(); // Refresh the page to update the UI
            }
        }

        // Add this to the existing window.onclick event handler
        window.onclick = function(event) {
            const notificationModal = document.getElementById('notificationModal');
            const exchangeStatusModal = document.getElementById('exchangeStatusModal');
            
            if (event.target === notificationModal) {
                closeModal();
            }
            if (event.target === exchangeStatusModal) {
                closeExchangeStatusModal();
            }
        }

        // Update the search functionality to handle multiple requests
        let searchTimeout = null;
        document.querySelector('.search-box').addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const searchTerm = e.target.value;
            
            searchTimeout = setTimeout(() => {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('search', searchTerm);
                currentUrl.searchParams.set('_', Date.now());
                
                fetch(currentUrl)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const searchResults = doc.querySelector('.search-results');
                        if (searchResults) {
                            document.querySelector('.search-results').innerHTML = searchResults.innerHTML;
                            initializeRequestButtons(); // Reinitialize buttons after search
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                    });
            }, 300);
        });

        document.addEventListener('DOMContentLoaded', function() {
            initializeRequestButtons();
        });

        function initializeRequestButtons() {
            document.querySelectorAll('.request-exchange').forEach(button => {
                if (!button.classList.contains('request-sent')) {
                    button.onclick = function(e) {
                        e.preventDefault();
                        requestExchange(this);
                    };
                }
            });
        }

        async function requestExchange(button) {
            if (isProcessingRequest) return;
            isProcessingRequest = true;
            
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Sending...';
            
            try {
                const userId = button.getAttribute('data-user-id');
                const skillName = button.getAttribute('data-skill-name');
                
                console.log('Sending request with:', { userId, skillName });
                
                if (!userId || !skillName) {
                    throw new Error('Missing required data');
                }

                const response = await fetch('handle_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'request',
                        recipient_id: userId,
                        skill_requested: skillName
                    })
                });

                const data = await response.json();
                console.log('Response:', data);
                
                if (!data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    throw new Error(data.error || 'Failed to send request');
                }

                // Update button state
                button.textContent = 'Request Pending';
                button.disabled = true;
                button.classList.add('request-sent');

                // Show success message
                alert('Request sent successfully! The recipient will be notified.');
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to send request');
                // Reset button state
                button.disabled = false;
                button.textContent = originalText;
            } finally {
                isProcessingRequest = false;
            }
        }

        async function markExchangeDone() {
            try {
                const response = await fetch('update_exchange_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ request_id: currentRequestId, status: 'completed' })
                });

                const data = await response.json();
                if (data.success) {
                    const exchangeProgress = document.querySelector('.exchange-progress');
                    const reviewSection = document.getElementById('reviewSection');
                    
                    exchangeProgress.style.display = 'none';
                    reviewSection.style.display = 'block';
                    currentRequesterId = data.requester_id;
                } else {
                    throw new Error(data.error || 'Failed to update status');
                }
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to update exchange status');
            }
        }

        // Star rating functionality
        document.querySelectorAll('.star-rating-input i').forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                document.querySelectorAll('.star-rating-input i').forEach(s => {
                    s.classList.toggle('selected', parseInt(s.dataset.rating) <= rating);
                });
            });
        });

        function showReviewModal(exchangeId) {
            currentExchangeId = exchangeId;
            selectedRating = 0;
            document.getElementById('reviewComment').value = '';
            document.querySelectorAll('.star').forEach(s => s.classList.remove('selected'));
            document.getElementById('reviewModal').style.display = 'block';
        }

        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
        }

        document.getElementById('submitReview').addEventListener('click', async function() {
            if (!selectedRating) {
                alert('Please select a rating');
                return;
            }

            try {
                const response = await fetch('handle_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'review',
                        exchange_id: currentExchangeId,
                        rating: selectedRating,
                        comment: document.getElementById('reviewComment').value
                    })
                });

                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to submit review');
                }

                alert('Review submitted successfully!');
                closeReviewModal();
                window.location.reload();
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to submit review');
            }
        });

        // Handle checkbox change
        document.getElementById('confirmCheckbox').addEventListener('change', function() {
            document.getElementById('finalConfirmButton').disabled = !this.checked;
        });

        // Handle final confirmation
        document.getElementById('finalConfirmButton').addEventListener('click', function() {
            if (document.getElementById('confirmCheckbox').checked) {
                handleRequest(true);
            }
        });

        async function deleteNotification(event, notificationId) {
            event.stopPropagation(); // Prevent triggering the notification click handler
            
            try {
                const response = await fetch('delete_notification.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ notification_id: notificationId })
                });

                const data = await response.json();
                
                if (data.success) {
                    // Remove the notification item from DOM
                    const notificationItem = event.target.closest('.notification-item');
                    notificationItem.style.opacity = '0';
                    setTimeout(() => {
                        notificationItem.remove();
                        
                        // Update notification count
                        const notificationCount = document.querySelector('.notification-count');
                        if (data.unread_count > 0) {
                            if (notificationCount) {
                                notificationCount.textContent = data.unread_count;
                            } else {
                                const notificationBtn = document.querySelector('.notification-btn');
                                const newCount = document.createElement('span');
                                newCount.className = 'notification-count';
                                newCount.textContent = data.unread_count;
                                notificationBtn.appendChild(newCount);
                            }
                        } else if (notificationCount) {
                            notificationCount.remove();
                        }
                        
                        // Show "No notifications" message if this was the last one
                        const notificationDropdown = document.getElementById('notificationDropdown');
                        if (!notificationDropdown.querySelector('.notification-item')) {
                            const noNotifications = document.createElement('div');
                            noNotifications.className = 'no-notifications';
                            noNotifications.textContent = 'No notifications';
                            notificationDropdown.appendChild(noNotifications);
                        }
                    }, 300); // Match this with CSS transition duration
                } else {
                    throw new Error(data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete notification: ' + error.message);
            }
        }

        function updateNotificationCount() {
            const countElement = document.querySelector('.notification-count');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent) - 1;
                if (currentCount <= 0) {
                    countElement.remove();
                } else {
                    countElement.textContent = currentCount;
                }
            }
        }

        async function clearAllPendingRequests() {
            if (!confirm('Are you sure you want to clear all pending requests? This action cannot be undone.')) {
                return;
            }

            const button = document.getElementById('clearRequestsBtn');
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Clearing...';

            try {
                const response = await fetch('clear_pending_requests.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });

                const data = await response.json();
                
                if (!data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    throw new Error(data.error || 'Failed to clear requests');
                }

                // Show success message
                alert(data.message);
                
                // Refresh the page to update the UI
                window.location.reload();
            } catch (error) {
                console.error('Error:', error);
                alert(error.message || 'Failed to clear requests');
                // Reset button state
                button.disabled = false;
                button.textContent = originalText;
            }
        }

        async function handleLogout() {
            try {
                const response = await fetch('logout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();
                
                if (data.success) {
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    } else {
                        window.location.reload();
                    }
                } else {
                    throw new Error(data.error || 'Failed to logout');
                }
            } catch (error) {
                console.error('Error:', error);
                alert(error.message);
                // If there's an error, try to redirect anyway
                window.location.href = 'home.html';
            }
        }

        // Add click handler to logout button
        document.getElementById('logoutBtn').addEventListener('click', handleLogout);

        function initNotificationStream() {
            const eventSource = new EventSource('notification_stream.php?last_check=' + Math.floor(Date.now() / 1000));

            eventSource.addEventListener('notification', function(e) {
                const notifications = JSON.parse(e.data);
                notifications.forEach(notification => {
                    // Add notification to the list
                    const notificationHtml = `
                        <div class="notification-item" data-id="${notification.id}" onclick="showNotificationDetails(${notification.id})">
                            <div class="notification-content">
                                <p>${notification.message}</p>
                                <small>${new Date(notification.created_at).toLocaleString()}</small>
                            </div>
                            ${!notification.is_read ? '<span class="unread-badge"></span>' : ''}
                        </div>
                    `;
                    document.querySelector('.notifications-list').insertAdjacentHTML('afterbegin', notificationHtml);
                    
                    // Show toast notification
                    showToast(notification.message);
                });
            });

            eventSource.addEventListener('count', function(e) {
                const data = JSON.parse(e.data);
                updateNotificationCount(data.unread_count);
            });

            eventSource.addEventListener('error', function(e) {
                console.error('SSE Error:', e);
                eventSource.close();
                // Try to reconnect after 5 seconds
                setTimeout(initNotificationStream, 5000);
            });
        }

        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = message;
            document.body.appendChild(toast);

            // Trigger reflow to enable animation
            toast.offsetHeight;
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-100%)';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        function updateNotificationCount(count) {
            const badge = document.querySelector('.notification-badge');
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'block';
            } else {
                badge.style.display = 'none';
            }
        }

        // Initialize notification stream when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            initNotificationStream();
        });

        async function markNotificationAsRead(notificationId) {
            try {
                const response = await fetch('mark_notification_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ notification_id: notificationId })
                });

                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error || 'Failed to mark notification as read');
                }

                // Update UI to reflect the read status
                const notificationItem = document.querySelector(`.notification-item[data-notification-id="${notificationId}"]`);
                if (notificationItem) {
                    notificationItem.classList.remove('unread');
                }

                // Update notification count
                updateNotificationCount();

            } catch (error) {
                console.error('Error marking notification as read:', error);
                // Don't throw the error as this is not critical
            }
        }
    </script>
</body>
</html> 