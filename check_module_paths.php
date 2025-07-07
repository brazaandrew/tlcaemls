<?php
require_once 'config/config.php';
require_once 'config/database.php';

// Get all module files
$query = "
    SELECT 
        ci.id as content_id,
        ci.title,
        ci.file_path as content_path,
        ci.content_type,
        m.id as module_id,
        m.title as module_title,
        c.id as course_id,
        c.title as course_title
    FROM content_items ci
    JOIN modules m ON ci.module_id = m.id
    JOIN courses c ON m.course_id = c.id
    WHERE ci.content_type = 'pdf'
    AND ci.file_path IS NOT NULL
";

$results = $conn->query($query);

echo "=== Module File Paths ===\n\n";

while ($row = $results->fetch_assoc()) {
    echo "Content ID: " . $row['content_id'] . "\n";
    echo "Title: " . $row['title'] . "\n";
    echo "File Path: " . $row['content_path'] . "\n";
    echo "Module: " . $row['module_title'] . " (ID: " . $row['module_id'] . ")\n";
    echo "Course: " . $row['course_title'] . " (ID: " . $row['course_id'] . ")\n";
    
    // Check if file exists
    $full_path = __DIR__ . '/' . $row['content_path'];
    echo "Full Path: " . $full_path . "\n";
    echo "File exists: " . (file_exists($full_path) ? "Yes" : "No") . "\n";
    echo "\n-------------------\n\n";
}

// Get all course material files
$query = "
    SELECT 
        cm.id,
        cm.title,
        cm.file_path,
        cm.type,
        c.id as course_id,
        c.title as course_title
    FROM course_materials cm
    JOIN courses c ON cm.course_id = c.id
    WHERE cm.type = 'pdf'
    AND cm.file_path IS NOT NULL
";

$results = $conn->query($query);

echo "\n=== Course Material File Paths ===\n\n";

while ($row = $results->fetch_assoc()) {
    echo "Material ID: " . $row['id'] . "\n";
    echo "Title: " . $row['title'] . "\n";
    echo "File Path: " . $row['file_path'] . "\n";
    echo "Course: " . $row['course_title'] . " (ID: " . $row['course_id'] . ")\n";
    
    // Check if file exists
    $full_path = __DIR__ . '/' . $row['file_path'];
    echo "Full Path: " . $full_path . "\n";
    echo "File exists: " . (file_exists($full_path) ? "Yes" : "No") . "\n";
    echo "\n-------------------\n\n";
} 