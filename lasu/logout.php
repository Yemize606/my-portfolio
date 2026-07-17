<?php
// ============================================================
//  logout.php — Destroys session and redirects to login
//  Link to this from every dashboard's nav bar.
// ============================================================

session_start();
session_unset();
session_destroy();

// Remove the session cookie from the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

header('Location: login.php?reason=logout');
exit;
