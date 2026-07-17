<?php
// ============================================================
//  admin/announcements.php  — Post & manage announcements
// ============================================================
define('REQUIRED_ROLE', 'admin');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Create announcement ──────────────────────────────
        if ($action === 'create') {
            $title = trim($_POST['title'] ?? '');
            $body  = trim($_POST['body']  ?? '');

            if (!$title || !$body) {
                $error = 'Title and body are required.';
            } else {
                $pdo->prepare(
                    'INSERT INTO announcements (admin_id, title, body, is_active)
                     VALUES (:aid, :title, :body, 1)'
                )->execute([
                    ':aid'   => $currentUser['id'],
                    ':title' => $title,
                    ':body'  => $body,
                ]);
                $success = 'Announcement posted successfully.';
            }
        }

        // ── Toggle active ────────────────────────────────────
        if ($action === 'toggle') {
            $annId     = (int)($_POST['announcement_id'] ?? 0);
            $newStatus = (int)($_POST['new_status'] ?? 0);
            if ($annId) {
                $pdo->prepare('UPDATE announcements SET is_active = :s WHERE announcement_id = :id')
                    ->execute([':s' => $newStatus, ':id' => $annId]);
                $success = 'Announcement updated.';
            }
        }

        // ── Delete ───────────────────────────────────────────
        if ($action === 'delete') {
            $annId = (int)($_POST['announcement_id'] ?? 0);
            if ($annId) {
                $pdo->prepare('DELETE FROM announcements WHERE announcement_id = :id')
                    ->execute([':id' => $annId]);
                $success = 'Announcement deleted.';
            }
        }
    }
}

// ── Fetch all announcements ──────────────────────────────────
$announcements = $pdo->query(
    'SELECT a.*, u.full_name AS posted_by
       FROM announcements a
       JOIN users u ON a.admin_id = u.user_id
      ORDER BY a.published_at DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Announcements — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --coral: #993C1D; --coral-mid: #D85A30; --coral-light: #FAECE7;
      --cream: #FAF8F3; --white: #fff; --border: #E0D8D0;
      --text: #0D1F19; --muted: #7A7068;
      --green: #085041; --green-bg: #E1F5EE;
      --error-bg: #FEF2F2; --error-border: #FECACA; --error-text: #991B1B;
    }
    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text); }
    nav  { background: var(--coral); color: #fff; padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
    nav .brand { font-weight: 600; font-size: 15px; }
    nav .nav-links { display: flex; gap: 20px; align-items: center; }
    nav a { color: #fff; opacity: .7; text-decoration: none; font-size: 13px; }
    nav a:hover, nav a.active { opacity: 1; }
    .page { max-width: 900px; margin: 0 auto; padding: 32px 24px; }
    h1 { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
    .sub { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 22px 26px; margin-bottom: 20px; }
    .card h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; }
    .alert { border-radius: 8px; padding: 11px 14px; font-size: 13px; margin-bottom: 18px; }
    .alert-success { background: var(--green-bg); border: 1px solid #9FE1CB; color: var(--green); }
    .alert-error   { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); }
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
    .field input, .field textarea {
      width: 100%; padding: 10px 12px; border: 1px solid var(--border);
      border-radius: 7px; font-size: 13px; font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--text); background: var(--white); outline: none;
    }
    .field textarea { min-height: 100px; resize: vertical; line-height: 1.6; }
    .field input:focus, .field textarea:focus { border-color: var(--coral-mid); box-shadow: 0 0 0 3px rgba(216,90,48,.1); }
    .btn-primary { height: 40px; padding: 0 20px; background: var(--coral); color: #fff; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Plus Jakarta Sans', sans-serif; }
    .btn-primary:hover { background: var(--coral-mid); }
    .ann-item { border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; margin-bottom: 12px; background: var(--white); }
    .ann-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; gap: 12px; }
    .ann-title  { font-weight: 600; font-size: 15px; }
    .ann-body   { font-size: 13px; color: var(--muted); line-height: 1.7; white-space: pre-wrap; margin-bottom: 12px; }
    .ann-meta   { font-size: 11px; color: var(--muted); }
    .ann-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-active   { background: var(--green-bg); color: var(--green); }
    .badge-inactive { background: #F1EFE8; color: #444441; }
    .btn-sm { height: 28px; padding: 0 12px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .btn-hide   { background: #F1EFE8; color: #444441; }
    .btn-show   { background: var(--green-bg); color: var(--green); }
    .btn-delete { background: #FCEBEB; color: #791F1F; }
    .empty { color: var(--muted); font-size: 13px; padding: 12px 0; font-style: italic; }
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Admin</span>
  <div class="nav-links">
    <a href="dashboard.php">Overview</a>
    <a href="users.php">Users</a>
    <a href="schedules.php">Schedules</a>
    <a href="announcements.php" class="active">Announcements</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Announcements</h1>
  <p class="sub">Post notices visible to all students on their dashboard.</p>

  <?php if ($success): ?>
    <div class="alert alert-success">&#10003; <?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Create form -->
  <div class="card">
    <h2>Post new announcement</h2>
    <form method="POST" action="announcements.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
      <input type="hidden" name="action"     value="create"/>

      <div class="field">
        <label>Title *</label>
        <input type="text" name="title" placeholder="e.g. Health center closed on public holiday" required/>
      </div>

      <div class="field">
        <label>Body *</label>
        <textarea name="body" placeholder="Write your announcement here…" required></textarea>
      </div>

      <button type="submit" class="btn-primary">Post announcement</button>
    </form>
  </div>

  <!-- Announcement list -->
  <div class="card">
    <h2>All announcements (<?= count($announcements) ?>)</h2>

    <?php if ($announcements): ?>
      <?php foreach ($announcements as $ann): ?>
        <div class="ann-item">
          <div class="ann-header">
            <div>
              <div class="ann-title"><?= htmlspecialchars($ann['title']) ?></div>
              <span class="badge <?= $ann['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                <?= $ann['is_active'] ? 'Visible to students' : 'Hidden' ?>
              </span>
            </div>
            <div class="ann-actions">
              <!-- Toggle visibility -->
              <form method="POST" action="announcements.php" style="display:inline">
                <input type="hidden" name="csrf_token"       value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
                <input type="hidden" name="action"           value="toggle"/>
                <input type="hidden" name="announcement_id"  value="<?= $ann['announcement_id'] ?>"/>
                <input type="hidden" name="new_status"       value="<?= $ann['is_active'] ? 0 : 1 ?>"/>
                <button type="submit" class="btn-sm <?= $ann['is_active'] ? 'btn-hide' : 'btn-show' ?>">
                  <?= $ann['is_active'] ? 'Hide' : 'Show' ?>
                </button>
              </form>
              <!-- Delete -->
              <form method="POST" action="announcements.php" style="display:inline"
                    onsubmit="return confirm('Delete this announcement permanently?')">
                <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
                <input type="hidden" name="action"          value="delete"/>
                <input type="hidden" name="announcement_id" value="<?= $ann['announcement_id'] ?>"/>
                <button type="submit" class="btn-sm btn-delete">Delete</button>
              </form>
            </div>
          </div>
          <div class="ann-body"><?= htmlspecialchars($ann['body']) ?></div>
          <div class="ann-meta">
            Posted by <?= htmlspecialchars($ann['posted_by']) ?>
            on <?= date('d F Y \a\t H:i', strtotime($ann['published_at'])) ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="empty">No announcements yet.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
