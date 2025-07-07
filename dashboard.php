<?php
require_once 'config/config.php';
require_once 'includes/session.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('/auth/login.php');
}

// Ensure CSRF token is generated
if (!isset($_SESSION['csrf_token'])) {
    generateCSRFToken();
}

// Get user's courses based on role
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

if ($role === 'student') {
    // Get enrolled courses for student
    $sql = "
        SELECT c.*, u.full_name as instructor_name, e.progress, e.status as enrollment_status
        FROM courses c
        JOIN enrollments e ON c.id = e.course_id
        JOIN users u ON c.instructor_id = u.id
        WHERE e.student_id = ? AND c.status = 'published'
        ORDER BY e.enrollment_date DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
} elseif ($role === 'instructor') {
    // Get courses taught by instructor
    $sql = "
        SELECT c.*, 
               (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as student_count
        FROM courses c
        WHERE c.instructor_id = ?
        ORDER BY c.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
} else {
    // Admin sees all courses
    $sql = "
        SELECT c.*, u.full_name as instructor_name,
               (SELECT COUNT(*) FROM enrollments WHERE course_id = c.id) as student_count
        FROM courses c
        LEFT JOIN users u ON c.instructor_id = u.id
        ORDER BY c.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
}

$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get announcements
$sql = "
    SELECT a.*, c.title as course_title, u.full_name as author_name
    FROM announcements a
    JOIN courses c ON a.course_id = c.id
    JOIN users u ON a.user_id = u.id
    WHERE a.course_id IN (
        CASE 
            WHEN ? = 'student' THEN (SELECT course_id FROM enrollments WHERE student_id = ?)
            WHEN ? = 'instructor' THEN (SELECT id FROM courses WHERE instructor_id = ?)
            ELSE (SELECT id FROM courses)
        END
    )
    ORDER BY a.created_at DESC
    LIMIT 5
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sisi", $role, $user_id, $role, $user_id);
$stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unread messages count
$sql = "SELECT COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0 AND deleted_by_receiver = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$unread_messages = $stmt->get_result()->fetch_assoc()['count'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex">
                    <div class="flex-shrink-0 flex items-center">
                        <a href="dashboard.php" class="text-2xl font-bold text-blue-600"><?php echo SITE_NAME; ?></a>
                    </div>
                    <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                        <a href="dashboard.php" class="border-blue-500 text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Dashboard
                        </a>
                        <a href="courses.php" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                            Courses
                        </a>
                        <?php if ($role === 'admin'): ?>
                            <a href="admin/users.php" class="border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium">
                                Users
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hidden sm:ml-6 sm:flex sm:items-center">
                    <!-- Messages -->
                    <a href="messages.php" class="relative p-2 text-gray-600 hover:text-gray-900">
                        <i class="fas fa-envelope"></i>
                        <?php if ($unread_messages > 0): ?>
                            <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
                                <?php echo $unread_messages; ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- Profile dropdown -->
                    <div class="ml-3 relative group">
                        <div class="flex items-center">
                            <button type="button" class="flex text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500" id="user-menu-button">
                                <span class="sr-only">Open user menu</span>
                                <?php if (isset($_SESSION['profile_image']) && !empty($_SESSION['profile_image'])): ?>
                                    <img class="h-8 w-8 rounded-full" src="<?php echo $_SESSION['profile_image']; ?>" alt="">
                                <?php else: ?>
                                    <div class="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center text-white">
                                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </button>
                            <div class="ml-2">
                                <div class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <div class="text-xs text-gray-500"><?php echo ucfirst($_SESSION['role']); ?></div>
                            </div>
                        </div>

                        <!-- Dropdown menu -->
                        <div class="hidden group-hover:block absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-white ring-1 ring-black ring-opacity-5" role="menu">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Your Profile</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">Settings</a>
                            <div class="border-t border-gray-100"></div>
                            <form action="auth/logout.php" method="POST" class="block">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <button type="submit" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">
                                    Sign out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main content -->
    <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <!-- Welcome message -->
        <div class="px-4 py-5 sm:px-6">
            <h1 class="text-2xl font-bold text-gray-900">
                Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
            </h1>
            <p class="mt-1 text-sm text-gray-600">
                Here's what's happening in your courses
            </p>
        </div>

        <!-- Quick actions -->
        <div class="mt-6 px-4 sm:px-6">
            <div class="flex flex-wrap gap-4">
                <?php if ($role === 'instructor' || $role === 'admin'): ?>
                    <a href="teacher/courses.php" onclick="openModal('createCourseModal'); return false;" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-plus mr-2"></i> Create Course
                    </a>
                <?php endif; ?>
                <a href="courses.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-search mr-2"></i> Browse Courses
                </a>
            </div>
        </div>

        <!-- Course grid -->
        <div class="mt-8">
            <div class="px-4 sm:px-6">
                <h2 class="text-lg leading-6 font-medium text-gray-900">Your Courses</h2>
            </div>
            <div class="mt-4 grid gap-5 max-w-lg mx-auto grid-cols-1 lg:grid-cols-3 lg:max-w-none px-4 sm:px-6">
                <?php foreach ($courses as $course): ?>
                    <div class="flex flex-col rounded-lg shadow-lg overflow-hidden">
                        <div class="flex-shrink-0">
                            <img class="h-48 w-full object-cover" src="<?php echo $course['thumbnail'] ?? 'assets/images/default-course.jpg'; ?>" alt="Course thumbnail">
                        </div>
                        <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                            <div class="flex-1">
                                <a href="courses/view.php?id=<?php echo $course['id']; ?>" class="block">
                                    <h3 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($course['title']); ?></h3>
                                </a>
                                <p class="mt-3 text-base text-gray-500">
                                    <?php echo htmlspecialchars(substr($course['description'], 0, 150)) . '...'; ?>
                                </p>
                            </div>
                            <div class="mt-6">
                                <?php if ($role === 'student'): ?>
                                    <div class="flex items-center">
                                        <div class="flex-1">
                                            <div class="relative pt-1">
                                                <div class="overflow-hidden h-2 text-xs flex rounded bg-blue-200">
                                                    <div style="width:<?php echo $course['progress']; ?>%" class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-blue-500"></div>
                                                </div>
                                            </div>
                                            <p class="text-sm text-gray-600 mt-1">Progress: <?php echo $course['progress']; ?>%</p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm text-gray-600">
                                        <?php echo $course['student_count']; ?> students enrolled
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($courses)): ?>
                    <div class="col-span-full text-center py-12">
                        <i class="fas fa-book-open text-gray-400 text-5xl mb-4"></i>
                        <p class="text-gray-500">No courses found. <?php echo $role === 'student' ? 'Start by enrolling in a course!' : 'Create your first course!'; ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Announcements -->
        <div class="mt-8">
            <div class="px-4 sm:px-6">
                <h2 class="text-lg leading-6 font-medium text-gray-900">Recent Announcements</h2>
            </div>
            <div class="mt-4 px-4 sm:px-6">
                <?php if (!empty($announcements)): ?>
                    <div class="space-y-4">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="bg-white shadow rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                    <span class="text-sm text-gray-500">
                                        <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                                    </span>
                                </div>
                                <p class="mt-2 text-gray-600"><?php echo htmlspecialchars($announcement['content']); ?></p>
                                <div class="mt-2 text-sm text-gray-500">
                                    Posted by <?php echo htmlspecialchars($announcement['author_name']); ?> in <?php echo htmlspecialchars($announcement['course_title']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-bullhorn text-gray-400 text-5xl mb-4"></i>
                        <p class="text-gray-500">No announcements yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Add any dashboard-specific JavaScript here
    </script>
</body>
</html> 