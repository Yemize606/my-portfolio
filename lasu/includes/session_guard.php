<?php
// ============================================================
//  includes/session_guard.php
//
//  Paste ONE line at the very top of every protected page:
//
//      require_once __DIR__ . '/../includes/session_guard.php';
//
//  Optionally restrict to a specific role:
//
//      define('REQUIRED_ROLE', 'student');   // before the require_once
//      define('REQUIRED_ROLE', 'doctor');
//      define('REQUIRED_ROLE', 'admin');
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name']);

if (!$isLoggedIn) {
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/') . '/login.php?reason=session');
    exit;
}

// Role-lock: if the calling page defined REQUIRED_ROLE, enforce it
if (defined('REQUIRED_ROLE') && $_SESSION['role'] !== REQUIRED_ROLE) {
    header('Location: ' . rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/') . '/login.php?reason=forbidden');
    exit;
}

// Expose handy shortcuts to every protected page
$currentUser = [
    'id'        => (int)  $_SESSION['user_id'],
    'name'      => (string) $_SESSION['full_name'],
    'role'      => (string) $_SESSION['role'],
    'dept_id'   => $_SESSION['department_id'] ?? null,
];
