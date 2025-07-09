<?php
require_once '../config/config.php';

if (isLoggedIn()) {
    // Redirect based on user role
    switch ($_SESSION['role']) {
        case 'admin':
            redirect('/admin/index.php');
            break;
        case 'teacher':
            redirect('/teacher/index.php');
            break;
        case 'student':
            redirect('/dashboard.php');
            break;
        default:
            redirect('/dashboard.php');
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = sanitize($_POST['username']); // This can be either username or email
    $password = $_POST['password'];

    // Debug information
    error_log("Login attempt for: " . $login);

    // Try to find user by email or username
    $sql = "SELECT id, email, username, password, role, full_name FROM users WHERE email = ? OR username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        error_log("User found: " . json_encode($user));
        
        if (verifyHash($password, $user['password'])) {
            // Set all necessary session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
            error_log("Session variables set: " . json_encode($_SESSION));
            
            // Generate CSRF token
            if (!isset($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            
            // Log activity
            logActivity($user['id'], 'login', 'User logged in successfully');
            
            // Debug information
            error_log("Redirecting user with role: " . $user['role']);
            
            // Redirect based on user role
            switch ($user['role']) {
                case 'admin':
                    redirect('/admin/index.php');
                    break;
                case 'teacher':
                    error_log("Redirecting to teacher dashboard");
                    redirect('/teacher/index.php');
                    break;
                case 'student':
                    redirect('/dashboard.php');
                    break;
                default:
                    redirect('/dashboard.php');
            }
        } else {
            error_log("Password verification failed for user: " . $login);
            $error = "Invalid password";
            logActivity(null, 'login_failed', "Failed login attempt for: $login");
        }
    } else {
        error_log("User not found: " . $login);
        $error = "User not found";
        logActivity(null, 'login_failed', "Failed login attempt for: $login");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-4">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-blue-600"><?php echo SITE_NAME; ?></h1>
                <p class="text-gray-600 mt-2">Sign in to your account</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-6">
                <?php echo getCsrfInput(); ?>
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700">Username or Email</label>
                    <div class="mt-1 relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <input type="text" id="username" name="username" required
                               class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                    <div class="mt-1 relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" id="password" name="password" required
                               class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input type="checkbox" id="remember_me" name="remember_me"
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="remember_me" class="ml-2 block text-sm text-gray-700">Remember me</label>
                    </div>
                    <a href="forgot-password.php" class="text-sm text-blue-600 hover:text-blue-500">Forgot password?</a>
                </div>

                <div>
                    <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Sign in
                    </button>
                </div>

                <div class="text-center">
                    <p class="text-sm text-gray-600">
                        Don't have an account?
                        <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500">Register here</a>
                    </p>
                </div>
            </form>
        </div>
    </div>
</body>
</html> 