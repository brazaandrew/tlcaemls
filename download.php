<?php
require_once 'config/config.php';
require_once 'config/database.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}

// Get file path from request
$file_path = isset($_GET['file']) ? $_GET['file'] : '';

// Basic path sanitization and validation
$file_path = str_replace('..', '', $file_path);
$file_path = str_replace(['\\', '//'], '/', $file_path);
$file_path = trim($file_path, '/');

// Log the requested file path for debugging
error_log("Requested file path: " . $file_path);

// Check if this is a module file
$is_module_file = strpos($file_path, 'module_') === 0;

// Determine the correct base path
$base_path = '';
if ($is_module_file) {
    $base_path = DEPED_MODULES_PATH;
} else if (strpos($file_path, 'course_materials/') === 0) {
    $base_path = UPLOAD_PATH;
} else if (strpos($file_path, 'assignments/') === 0) {
    $base_path = UPLOAD_PATH;
} else {
    $base_path = __DIR__;
}

// Construct the full path
$full_path = $base_path . '/' . ($is_module_file ? basename($file_path) : $file_path);
error_log("Full path: " . $full_path);

// Verify file exists
if (!file_exists($full_path)) {
    error_log("File not found: " . $full_path);
    http_response_code(404);
    exit('File not found');
}

// Get file extension
$extension = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));

// Set content type based on file extension
$content_types = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm'
];

$content_type = isset($content_types[$extension]) ? $content_types[$extension] : 'application/octet-stream';

// Get original filename
$original_name = basename($full_path);

// Clear any previous output
if (ob_get_level()) ob_end_clean();

// For PDFs, we can optionally display in browser instead of forcing download
$is_pdf = $extension === 'pdf';
$force_download = isset($_GET['download']) && $_GET['download'] === 'true';

// Set appropriate headers
header('Content-Type: ' . $content_type);
if ($force_download || !$is_pdf) {
    header('Content-Disposition: attachment; filename="' . $original_name . '"');
} else {
    header('Content-Disposition: inline; filename="' . $original_name . '"');
}
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output file content
readfile($full_path);
exit(); 