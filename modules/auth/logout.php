<?php
/**
 * Logout Page
 * 
 * Handles user logout
 */

// Include authentication system
require_once __DIR__ . '/../../includes/auth.php';

// Log out user
logoutUser();

// Redirect to login page
header("Location: login");
exit;
