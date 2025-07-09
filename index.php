<?php
require_once 'config/config.php';

// Redirect to dashboard if logged in, otherwise to login page
if (isLoggedIn()) {
    redirect('/dashboard.php');
} else {
    redirect('/auth/login.php');
}
?> 