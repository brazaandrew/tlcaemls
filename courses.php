<?php
require_once 'config/config.php';
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = getUserRole();

// Process enrollment code submission (only for students)
if ($user_role === 'student' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enrollment_code'])) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        setFlashMessage('error', 'Invalid security token. Please try again.');
        header('Location: courses.php');
        exit();
    }
    
    $enrollment_key = trim(sanitize($_POST['enrollment_code']));
    
    if (empty($enrollment_key)) {
        setFlashMessage('error', 'Please enter an enrollment key.');
    } else {
        // Check if course exists and is published
        $stmt = $conn->prepare("
            SELECT c.*, u.full_name as instructor_name 
            FROM courses c
            JOIN users u ON c.instructor_id = u.id
            WHERE c.enrollment_key = ? AND c.status = 'published'
        ");
        $stmt->bind_param("s", $enrollment_key);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        
        if (!$course) {
            setFlashMessage('error', 'Invalid enrollment code. Please check and try again.');
        } else {
            // Check if already enrolled
            $stmt = $conn->prepare("
                SELECT id, status FROM enrollments 
                WHERE student_id = ? AND course_id = ?
            ");
            $stmt->bind_param("ii", $user_id, $course['id']);
            $stmt->execute();
            $existing_enrollment = $stmt->get_result()->fetch_assoc();
            
            if ($existing_enrollment) {
                setFlashMessage('error', 'You are already enrolled in ' . htmlspecialchars($course['title']));
            } else {
                // Create enrollment with active status
                $stmt = $conn->prepare("
                    INSERT INTO enrollments (student_id, course_id, status, enrollment_date)
                    VALUES (?, ?, 'active', NOW())
                ");
                $stmt->bind_param("ii", $user_id, $course['id']);
                
                if ($stmt->execute()) {
                    setFlashMessage('success', 'Successfully enrolled in ' . htmlspecialchars($course['title']) . '.');
                } else {
                    setFlashMessage('error', 'Failed to enroll in the course. Please try again.');
                }
            }
        }
    }
    
    // Redirect to prevent form resubmission
    header('Location: courses.php');
    exit();
}

// Get filter parameters
$education_level = isset($_GET['education_level']) ? sanitize($_GET['education_level']) : '';
$grade_level = isset($_GET['grade_level']) ? sanitize($_GET['grade_level']) : '';
$subject_area = isset($_GET['subject_area']) ? sanitize($_GET['subject_area']) : '';
$strand = isset($_GET['strand']) ? sanitize($_GET['strand']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Different queries for students and teachers
if ($user_role === 'student') {
    // Check if student has any enrolled courses
    $stmt = $conn->prepare("
        SELECT COUNT(*) as course_count
        FROM enrollments e
        WHERE e.student_id = ? AND e.status IN ('active', 'pending')
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $has_courses = $result['course_count'] > 0;

    // Get enrolled courses for the student
    $query = "
        SELECT c.*, e.status as enrollment_status, e.enrollment_date,
               u.full_name as instructor_name,
               COALESCE((SELECT COUNT(*) FROM enrollments WHERE course_id = c.id AND status = 'active'), 0) as student_count
        FROM courses c
        JOIN enrollments e ON c.id = e.course_id
        JOIN users u ON c.instructor_id = u.id
        WHERE e.student_id = ? AND e.status = 'active'
    ";
} else if ($user_role === 'teacher') {
    // Get courses for the teacher
    $query = "
        SELECT c.*, 
               COALESCE((SELECT COUNT(*) FROM enrollments WHERE course_id = c.id AND status = 'active'), 0) as student_count
        FROM courses c
        WHERE c.instructor_id = ?
    ";
} else {
    // Not authorized
    setFlashMessage('error', 'Unauthorized access. Please login with appropriate role.');
    header('Location: dashboard.php');
    exit();
}

$params = [$user_id];
$types = "i";

if ($education_level) {
    $query .= " AND c.education_level = ?";
    $params[] = $education_level;
    $types .= "s";
}

if ($grade_level) {
    $query .= " AND c.grade_level = ?";
    $params[] = $grade_level;
    $types .= "s";
}

if ($subject_area) {
    $query .= " AND c.subject_area = ?";
    $params[] = $subject_area;
    $types .= "s";
}

if ($strand) {
    $query .= " AND c.strand = ?";
    $params[] = $strand;
    $types .= "s";
}

if ($search) {
    $query .= " AND (c.title LIKE ? OR c.description LIKE ? OR c.course_code LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY " . ($user_role === 'student' ? "e.enrollment_date" : "c.created_at") . " DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = $user_role === 'student' ? "My Courses" : "Manage Courses";
include 'includes/header.php';
?>

<div class="py-10">
    <header>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-3xl font-bold leading-tight text-gray-900">
                <?php echo $page_title; ?>
            </h1>
        </div>
    </header>
    <main>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Flash Messages -->
            <?php if (hasFlashMessage()): ?>
                <?php $message = getFlashMessage(); ?>
                <div class="rounded-md p-4 mb-4 <?php 
                    echo $message['type'] === 'success' ? 'bg-green-50' : 
                        ($message['type'] === 'error' ? 'bg-red-50' : 
                        ($message['type'] === 'info' ? 'bg-blue-50' : 'bg-yellow-50')); ?>">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <?php if ($message['type'] === 'success'): ?>
                                <svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            <?php elseif ($message['type'] === 'error'): ?>
                                <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                            <?php elseif ($message['type'] === 'info'): ?>
                                <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            <?php else: ?>
                                <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium <?php 
                                echo $message['type'] === 'success' ? 'text-green-800' : 
                                    ($message['type'] === 'error' ? 'text-red-800' : 
                                    ($message['type'] === 'info' ? 'text-blue-800' : 'text-yellow-800')); ?>">
                                <?php echo htmlspecialchars($message['message']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($user_role === 'student'): ?>
            <!-- Enrollment Form -->
            <div class="bg-white shadow sm:rounded-lg mt-8">
                <div class="px-4 py-5 sm:p-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                        <?php echo $has_courses ? 'Enroll in Another Course' : 'Welcome to eLMS!'; ?>
                    </h3>
                    <div class="mt-2 max-w-xl text-sm text-gray-500">
                        <p><?php echo $has_courses ? 
                            'Enter an enrollment code to join another course.' : 
                            'You are not enrolled in any courses yet. You can enroll in a course by entering an enrollment code provided by your teacher.'; ?></p>
                    </div>
                    <form action="" method="POST" class="mt-5">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="flex items-center space-x-4">
                            <div class="flex-grow max-w-xs">
                                <label for="enrollment_code" class="sr-only">Enrollment Key</label>
                                <input type="text" name="enrollment_code" id="enrollment_code" required
                                       class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                                       placeholder="Enter enrollment key">
                            </div>
                            <button type="submit"
                                    class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Enroll in Course
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-white shadow sm:rounded-lg mt-8">
                <div class="px-4 py-5 sm:p-6">
                    <form action="" method="GET" class="space-y-4 sm:flex sm:items-center sm:space-y-0 sm:space-x-4">
                        <div class="w-full sm:max-w-xs">
                            <label for="education_level" class="sr-only">Education Level</label>
                            <select id="education_level" name="education_level"
                                    class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="">All Levels</option>
                                <option value="elementary" <?php echo $education_level === 'elementary' ? 'selected' : ''; ?>>Elementary</option>
                                <option value="junior high" <?php echo $education_level === 'junior high' ? 'selected' : ''; ?>>Junior High</option>
                                <option value="senior high" <?php echo $education_level === 'senior high' ? 'selected' : ''; ?>>Senior High</option>
                            </select>
                        </div>

                        <div class="w-full sm:max-w-xs">
                            <label for="grade_level" class="sr-only">Grade Level</label>
                            <select id="grade_level" name="grade_level"
                                    class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="">All Grades</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $grade_level == $i ? 'selected' : ''; ?>>
                                        Grade <?php echo $i; ?>
                            </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="w-full sm:max-w-xs">
                            <label for="subject_area" class="sr-only">Subject Area</label>
                            <select id="subject_area" name="subject_area"
                                    class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="">All Subjects</option>
                                <option value="Mathematics" <?php echo $subject_area === 'Mathematics' ? 'selected' : ''; ?>>Mathematics</option>
                                <option value="Science" <?php echo $subject_area === 'Science' ? 'selected' : ''; ?>>Science</option>
                                <option value="English" <?php echo $subject_area === 'English' ? 'selected' : ''; ?>>English</option>
                                <option value="Filipino" <?php echo $subject_area === 'Filipino' ? 'selected' : ''; ?>>Filipino</option>
                                <option value="Social Studies" <?php echo $subject_area === 'Social Studies' ? 'selected' : ''; ?>>Social Studies</option>
                                <option value="TLE" <?php echo $subject_area === 'TLE' ? 'selected' : ''; ?>>TLE</option>
                                <option value="MAPEH" <?php echo $subject_area === 'MAPEH' ? 'selected' : ''; ?>>MAPEH</option>
                                <option value="Values Education" <?php echo $subject_area === 'Values Education' ? 'selected' : ''; ?>>Values Education</option>
                            </select>
                        </div>

                        <div class="w-full sm:max-w-xs">
                            <label for="strand" class="sr-only">Strand (SHS)</label>
                            <select id="strand" name="strand"
                                    class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                                <option value="">All Strands</option>
                                <option value="STEM" <?php echo $strand === 'STEM' ? 'selected' : ''; ?>>STEM</option>
                                <option value="ABM" <?php echo $strand === 'ABM' ? 'selected' : ''; ?>>ABM</option>
                                <option value="HUMSS" <?php echo $strand === 'HUMSS' ? 'selected' : ''; ?>>HUMSS</option>
                                <option value="GAS" <?php echo $strand === 'GAS' ? 'selected' : ''; ?>>GAS</option>
                                <option value="TVL" <?php echo $strand === 'TVL' ? 'selected' : ''; ?>>TVL</option>
                            </select>
                        </div>

                        <div class="w-full sm:max-w-xs">
                            <label for="search" class="sr-only">Search</label>
                            <input type="text" name="search" id="search"
                                   value="<?php echo htmlspecialchars($search); ?>"
                                   class="shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
                               placeholder="Search courses...">
                        </div>

                        <button type="submit"
                                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Filter
                        </button>

                        <?php if ($education_level || $grade_level || $subject_area || $strand || $search): ?>
                            <a href="?"
                               class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                Clear Filters
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Course Grid -->
            <div class="mt-8 grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($courses as $course): ?>
                <div class="bg-white overflow-hidden shadow rounded-lg">
                    <!-- Course Image -->
                    <div class="relative h-48 bg-gray-200">
                <?php if ($course['thumbnail']): ?>
                        <img src="uploads/course_thumbnails/<?php echo htmlspecialchars($course['thumbnail']); ?>" 
                             alt="<?php echo htmlspecialchars($course['title']); ?>" 
                             class="w-full h-full object-cover">
                <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center bg-gray-100">
                            <svg class="w-12 h-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                    </div>
                <?php endif; ?>

                        <!-- Course Status Badge -->
                        <div class="absolute top-2 right-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                <?php echo $course['enrollment_status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                <?php echo ucfirst($course['enrollment_status']); ?>
                        </span>
                        </div>
                    </div>
                    
                    <div class="p-6">
                        <!-- Course Title and Basic Info -->
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-medium text-gray-900">
                                <?php echo htmlspecialchars($course['title']); ?>
                            </h3>
                            <p class="text-sm text-gray-500">
                                <?php echo $course['student_count']; ?> students
                            </p>
                        </div>
                        
                        <!-- Instructor Info -->
                        <div class="mt-2 flex items-center text-sm text-gray-500">
                            <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                      d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <?php echo htmlspecialchars($course['instructor_name']); ?>
                    </div>

                        <!-- Course Details -->
                        <div class="mt-4">
                            <div class="flex items-center text-sm text-gray-500">
                                <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                                </svg>
                                <?php echo htmlspecialchars($course['subject_area']); ?>
                            </div>
                            <div class="mt-2 flex items-center text-sm text-gray-500">
                                <svg class="flex-shrink-0 mr-1.5 h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                          d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                                Grade <?php echo htmlspecialchars($course['grade_level']); ?>
                            </div>
                    </div>

                        <!-- Course Description -->
                        <p class="mt-3 text-sm text-gray-500 line-clamp-2">
                            <?php echo htmlspecialchars(truncate($course['description'], 150)); ?>
                        </p>
                        
                        <!-- Action Buttons -->
                        <div class="mt-6 flex items-center justify-between">
                            <a href="courses/view.php?id=<?php echo $course['id']; ?>" 
                               class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            View Course
                            </a>
                            <?php if ($course['enrollment_status'] === 'active'): ?>
                            <span class="inline-flex items-center text-sm text-gray-500">
                                <svg class="mr-1.5 h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                Enrolled
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
        </div>

        <?php if (empty($courses)): ?>
            <div class="mt-8 text-center">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                          d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No courses found</h3>
                <p class="mt-1 text-sm text-gray-500">
                    You are not enrolled in any courses yet. Use an enrollment key to join a course.
                </p>
        </div>
        <?php endif; ?>
        </div>
    </main>
    </div>

    <script>
function copyEnrollmentKey(key) {
    navigator.clipboard.writeText(key).then(() => {
        // Show a temporary success message
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg transition-opacity duration-500';
        toast.textContent = 'Enrollment key copied!';
        document.body.appendChild(toast);
        
        // Remove the toast after 2 seconds
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 500);
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy enrollment key:', err);
    });
    }
    </script>

<?php include 'includes/footer.php'; ?> 