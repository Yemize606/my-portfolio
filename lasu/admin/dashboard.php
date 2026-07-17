<?php
// ============================================================
//  admin/dashboard.php  (updated — with full navigation)
// ============================================================
define('REQUIRED_ROLE', 'admin');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

// Quick stats
$stats = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1)   AS total_students,
      (SELECT COUNT(*) FROM users WHERE role='doctor'  AND is_active=1)   AS total_doctors,
      (SELECT COUNT(*) FROM appointments WHERE DATE(booked_at)=CURDATE()) AS booked_today,
      (SELECT COUNT(*) FROM appointments WHERE status='Missed')           AS total_missed
")->fetch();

// Analytics view
$analytics = $pdo->query('SELECT * FROM v_admin_analytics')->fetchAll();

// Recent announcements
$announcements = $pdo->query(
    'SELECT title, is_active, published_at FROM announcements
      ORDER BY published_at DESC LIMIT 5'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --coral: #993C1D; --coral-mid: #D85A30; --coral-light: #FAECE7;
      --cream: #FAF8F3; --white: #fff; --border: #E0D8D0;
      --text: #0D1F19; --muted: #7A7068;
      --green: #085041; --green-bg: #E1F5EE;
    }
    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text); }

    /* Nav */
    nav { background: var(--coral); color: #fff; padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
    nav .brand { font-weight: 600; font-size: 15px; }
    nav .nav-links { display: flex; gap: 4px; align-items: center; }
    nav a {
      color: #fff; text-decoration: none; font-size: 13px; font-weight: 500;
      padding: 6px 14px; border-radius: 7px; opacity: .8;
      transition: opacity .15s, background .15s;
    }
    nav a:hover { opacity: 1; background: rgba(255,255,255,0.12); }
    nav a.active { opacity: 1; background: rgba(255,255,255,0.18); }
    nav a.signout { opacity: .6; margin-left: 8px; }

    .page { max-width: 1000px; margin: 0 auto; padding: 32px 24px; }
    h1   { font-size: 22px; font-weight: 600; margin-bottom: 4px; }
    .sub { color: var(--muted); font-size: 14px; margin-bottom: 28px; }

    /* Metric cards */
    .metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
    .metric  { background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; }
    .metric-val   { font-size: 28px; font-weight: 600; }
    .metric-label { font-size: 12px; color: var(--muted); margin-top: 4px; text-transform: uppercase; letter-spacing: .05em; }

    /* Quick links */
    .quick-links { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
    .quick-link {
      background: var(--white); border: 1px solid var(--border); border-radius: 12px;
      padding: 20px 22px; text-decoration: none; color: var(--text);
      display: flex; align-items: center; gap: 14px; transition: border-color .2s, box-shadow .2s;
    }
    .quick-link:hover { border-color: var(--coral-mid); box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .quick-link-icon {
      width: 42px; height: 42px; border-radius: 10px; display: flex;
      align-items: center; justify-content: center; flex-shrink: 0; font-size: 18px;
    }
    .icon-users  { background: #EEEDFE; }
    .icon-sched  { background: var(--green-bg); }
    .icon-ann    { background: var(--coral-light); }
    .quick-link-title { font-weight: 600; font-size: 14px; margin-bottom: 2px; }
    .quick-link-desc  { font-size: 12px; color: var(--muted); }

    /* Cards */
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .card-header h2 { font-size: 15px; font-weight: 600; }
    .card-header a  { font-size: 13px; color: var(--coral-mid); text-decoration: none; font-weight: 500; }
    .card-header a:hover { text-decoration: underline; }

    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; color: var(--muted); font-weight: 500; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
    td { padding: 10px 0; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    .rate-high { color: #791F1F; font-weight: 600; }
    .rate-low  { color: var(--green); font-weight: 600; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-active   { background: var(--green-bg); color: var(--green); }
    .badge-inactive { background: #F1EFE8; color: #444441; }
    .empty { color: var(--muted); font-size: 13px; font-style: italic; padding: 8px 0; }

    @media (max-width: 700px) {
      .metrics    { grid-template-columns: 1fr 1fr; }
      .quick-links { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<nav>
  <span class="brand">LASU Health Center — Admin</span>
  <div class="nav-links">
    <a href="dashboard.php"     class="active">Overview</a>
    <a href="cancellations.php">Cancellations</a>
    <a href="users.php">Users</a>
    <a href="schedules.php">Schedules</a>
    <a href="announcements.php">Announcements</a>
    <a href="../logout.php" class="signout">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Admin overview</h1>
  <p class="sub"><?= date('l, d F Y') ?> — Welcome back, <?= htmlspecialchars(explode(' ', $currentUser['name'])[0]) ?></p>

  <!-- Metric cards -->
  <div class="metrics">
    <div class="metric">
      <div class="metric-val"><?= (int)$stats['total_students'] ?></div>
      <div class="metric-label">Active students</div>
    </div>
    <div class="metric">
      <div class="metric-val"><?= (int)$stats['total_doctors'] ?></div>
      <div class="metric-label">Active doctors</div>
    </div>
    <div class="metric">
      <div class="metric-val"><?= (int)$stats['booked_today'] ?></div>
      <div class="metric-label">Booked today</div>
    </div>
    <div class="metric">
      <div class="metric-val"><?= (int)$stats['total_missed'] ?></div>
      <div class="metric-label">Total missed</div>
    </div>
  </div>

  <!-- Quick links -->
  <div class="quick-links">
    <a href="users.php" class="quick-link">
      <div class="quick-link-icon icon-users">👥</div>
      <div>
        <div class="quick-link-title">User management</div>
        <div class="quick-link-desc">Create & manage accounts</div>
      </div>
    </a>
    <a href="schedules.php" class="quick-link">
      <div class="quick-link-icon icon-sched">📅</div>
      <div>
        <div class="quick-link-title">Schedule management</div>
        <div class="quick-link-desc">Generate doctor time slots</div>
      </div>
    </a>
    <a href="announcements.php" class="quick-link">
      <div class="quick-link-icon icon-ann">📢</div>
      <div>
        <div class="quick-link-title">Announcements</div>
        <div class="quick-link-desc">Post notices to students</div>
      </div>
    </a>
  </div>

  <!-- Analytics -->
  <div class="card">
    <div class="card-header">
      <h2>No-show rate by department</h2>
    </div>
    <table>
      <thead>
        <tr>
          <th>Department</th><th>Total</th><th>Completed</th>
          <th>Missed</th><th>Cancelled</th><th>No-show rate</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($analytics): foreach ($analytics as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['department']) ?></td>
          <td><?= (int)$row['total_appointments'] ?></td>
          <td><?= (int)$row['completed'] ?></td>
          <td><?= (int)$row['missed'] ?></td>
          <td><?= (int)$row['cancelled'] ?></td>
          <td class="<?= $row['no_show_rate_pct'] >= 20 ? 'rate-high' : 'rate-low' ?>">
            <?= number_format((float)$row['no_show_rate_pct'], 1) ?>%
          </td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="6" class="empty">No appointment data yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Recent announcements -->
  <div class="card">
    <div class="card-header">
      <h2>Recent announcements</h2>
      <a href="announcements.php">Manage all →</a>
    </div>
    <?php if ($announcements): ?>
      <table>
        <thead>
          <tr><th>Title</th><th>Status</th><th>Posted</th></tr>
        </thead>
        <tbody>
        <?php foreach ($announcements as $ann): ?>
          <tr>
            <td><?= htmlspecialchars($ann['title']) ?></td>
            <td>
              <span class="badge <?= $ann['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                <?= $ann['is_active'] ? 'Visible' : 'Hidden' ?>
              </span>
            </td>
            <td><?= date('d M Y', strtotime($ann['published_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="empty">No announcements yet. <a href="announcements.php" style="color:var(--coral-mid)">Post one →</a></p>
    <?php endif; ?>
  </div>
</div>

</body>
</html>