<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enforce POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlashMessage('error', 'Invalid logout request method.');
    redirect('/dashboard.php');
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    setFlashMessage('error', 'Invalid security token. Please try logging out again.');
    redirect('/dashboard.php');
    exit();
}

if (isLoggedIn()) {
    // Store user info before destroying session
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'] ?? 'user';
    
    // Log the activity with role-specific information
    logActivity(
        $user_id, 
        'logout', 
        sprintf('User logged out (Role: %s)', $user_role)
    );
    
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }
    
    // Destroy session
    session_destroy();
    
    // Clear any other application-specific cookies
    setcookie(
        'remember_me',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );
}

// Set security headers
header('Clear-Site-Data: "cache", "cookies", "storage"');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Redirect to login page with success message
setFlashMessage('success', 'You have been successfully logged out.');
redirect('/auth/login.php');
?> 