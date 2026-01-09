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

// Database connection parameters
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "expert_xchange";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle signup form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['name'])) {
    // Prepare statement for checking email
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check_stmt->bind_param("s", $_POST['email']);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<script>
            alert('Email already exists!');
            window.location.href = 'home.html';
        </script>";
        exit();
    }
    $check_stmt->close();

    // Validate password match
    if ($_POST['password'] !== $_POST['confirm_password']) {
        echo "<script>
            alert('Passwords do not match!');
            window.location.href = 'home.html';
        </script>";
        exit();
    }

    // Prepare statement for inserting new user
    $insert_stmt = $conn->prepare("INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)");
    $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $insert_stmt->bind_param("sss", $_POST['name'], $_POST['email'], $hashed_password);
    
    if ($insert_stmt->execute()) {
        // Get the new user's ID
        $user_id = $insert_stmt->insert_id;
        
        // Set session variables
        $_SESSION['user_email'] = $_POST['email'];
        $_SESSION['user_name'] = $_POST['name'];
        $_SESSION['user_id'] = $user_id;
        $_SESSION['last_activity'] = time();
        $_SESSION['created_at'] = time();
        
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Make sure no output has been sent before redirect
        if (!headers_sent()) {
            header("Location: profile-setup.html");
            exit();
        } else {
            echo "<script>window.location.href = 'profile-setup.html';</script>";
        }
    } else {
        echo "<script>
            alert('Error: " . $conn->error . "');
            window.location.href = 'home.html';
        </script>";
    }
    $insert_stmt->close();
}

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email']) && !isset($_POST['name'])) {
    // Clear any existing session
    session_unset();
    session_destroy();
    session_start();
    
    // Prepare statement for getting user data
    $login_stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $login_stmt->bind_param("s", $_POST['email']);
    $login_stmt->execute();
    $result = $login_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($_POST['password'], $user['password'])) {
            // Set session variables
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['last_activity'] = time();
            $_SESSION['created_at'] = time();
            
            // Regenerate session ID
            session_regenerate_id(true);
            
            // Check if profile exists
            $profile_stmt = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
            $profile_stmt->bind_param("i", $user['id']);
            $profile_stmt->execute();
            $profile_result = $profile_stmt->get_result();
            $profile_stmt->close();
            
            // Redirect based on profile existence
            if ($profile_result->num_rows > 0) {
                header("Location: profile.php");
            } else {
                header("Location: profile-setup.html");
            }
            exit();
        } else {
            echo "<script>
                alert('Invalid password!');
                window.location.href = 'home.html';
            </script>";
        }
    } else {
        echo "<script>
            alert('User not found!');
            window.location.href = 'home.html';
        </script>";
    }
    $login_stmt->close();
}

// Handle contact form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['subject']) && $_POST['subject'] == 'contact') {
    // Create contacts table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($create_table);

    // Prepare statement for inserting contact message
    $contact_stmt = $conn->prepare("INSERT INTO contacts (name, email, message) VALUES (?, ?, ?)");
    $contact_stmt->bind_param("sss", $_POST['name'], $_POST['email'], $_POST['message']);
    
    if ($contact_stmt->execute()) {
        echo "<script>
            alert('Message sent successfully!');
            window.location.href = 'home.html#contact';
        </script>";
    } else {
        echo "<script>
            alert('Error: " . $conn->error . "');
            window.location.href = 'home.html#contact';
        </script>";
    }
    $contact_stmt->close();
}

// Handle newsletter subscription
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['email']) && isset($_POST['subscribe'])) {
    $email = $conn->real_escape_string($_POST['email']);

    // Create subscribers table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL UNIQUE,
        subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    $conn->query($create_table);

    // Check if email already subscribed
    $check_email = "SELECT id FROM subscribers WHERE email = '$email'";
    $result = $conn->query($check_email);

    if ($result->num_rows > 0) {
        echo "<script>
            alert('You are already subscribed to our newsletter!');
            window.location.href = 'home.html';
        </script>";
        exit();
    }

    // Insert new subscriber
    $sql = "INSERT INTO subscribers (email) VALUES ('$email')";
    
    if ($conn->query($sql) === TRUE) {
        echo "<script>
            alert('Successfully subscribed to newsletter!');
            window.location.href = 'home.html';
        </script>";
    } else {
        echo "<script>
            alert('Error: " . $conn->error . "');
            window.location.href = 'home.html';
        </script>";
    }
}

$conn->close();
?> 