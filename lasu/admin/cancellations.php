<?php
// ============================================================
//  admin/cancellations.php
//  Admin view of all appointment cancellations with reasons
// ============================================================
define('REQUIRED_ROLE', 'admin');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

// Filters
$filterDoctor = (int)($_GET['doctor_id'] ?? 0);
$filterFrom   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$filterTo     = $_GET['to']   ?? date('Y-m-d');

$where  = 'WHERE a.status = \'Cancelled\'';
$params = [];

if ($filterDoctor) {
    $where .= ' AND a.doctor_id = :did';
    $params[':did'] = $filterDoctor;
}
if ($filterFrom) {
    $where .= ' AND DATE(a.updated_at) >= :from';
    $params[':from'] = $filterFrom;
}
if ($filterTo) {
    $where .= ' AND DATE(a.updated_at) <= :to';
    $params[':to'] = $filterTo;
}

$cancellations = $pdo->prepare(
    "SELECT a.appointment_id, a.cancellation_reason, a.updated_at,
            ds.available_date, ds.slot_start,
            s.full_name      AS student_name,
            s.matric_number,
            d.full_name      AS doctor_name,
            dept.name        AS department
       FROM appointments a
       JOIN doctor_schedules ds   ON a.schedule_id    = ds.schedule_id
       JOIN users            s    ON a.student_id     = s.user_id
       JOIN users            d    ON a.doctor_id      = d.user_id
       LEFT JOIN departments dept ON d.department_id  = dept.department_id
     {$where}
     ORDER BY a.updated_at DESC
     LIMIT 100"
);
$cancellations->execute($params);
$records = $cancellations->fetchAll();

// Doctors for filter
$doctors = $pdo->query(
    "SELECT user_id, full_name FROM users WHERE role='doctor' AND is_active=1 ORDER BY full_name"
)->fetchAll();

// Summary stats
$total = count($records);
$withReason = count(array_filter($records, fn($r) => !empty($r['cancellation_reason'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Cancellations — LASU Health Center Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--coral:#993C1D;--cm:#D85A30;--cl:#FAECE7;--cream:#FAF8F3;--white:#fff;--border:#E0D8D0;--text:#0D1F19;--muted:#7A7068;--sb:#E1F5EE;--st:#085041;--erb:#FEF2F2;--erb2:#FECACA;--ert:#991B1B}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--coral);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:4px;align-items:center}
    nav a{color:#fff;text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:7px;opacity:.8;transition:opacity .15s,background .15s}
    nav a:hover,nav a.active{opacity:1;background:rgba(255,255,255,.15)}
    .page{max-width:1100px;margin:0 auto;padding:32px 24px}
    h1{font-size:20px;font-weight:600;margin-bottom:4px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:24px}
    .metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
    .metric{background:var(--white);border:1px solid var(--border);border-radius:10px;padding:16px 20px}
    .metric-val{font-size:26px;font-weight:600}
    .metric-label{font-size:11px;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:.05em}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:20px}
    .filter-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
    .filter-row select,.filter-row input{height:38px;padding:0 12px;border:1px solid var(--border);border-radius:7px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;outline:none}
    .filter-row select:focus,.filter-row input:focus{border-color:var(--cm)}
    .filter-row label{font-size:11px;font-weight:500;color:var(--muted);display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
    .btn-filter{height:38px;padding:0 18px;background:var(--coral);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn-filter:hover{background:var(--cm)}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;color:var(--muted);font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding-bottom:8px;border-bottom:1px solid var(--border)}
    td{padding:10px 0;border-bottom:1px solid var(--border);vertical-align:top}
    tr:last-child td{border-bottom:none}
    .reason-box{font-size:12px;color:var(--ert);background:var(--erb);border-radius:5px;padding:5px 8px;line-height:1.5;font-style:italic;max-width:280px}
    .no-reason{font-size:12px;color:var(--muted);font-style:italic}
    .empty{color:var(--muted);font-size:13px;font-style:italic;padding:12px 0}
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Admin</span>
  <div class="links">
    <a href="dashboard.php">Overview</a>
    <a href="users.php">Users</a>
    <a href="schedules.php">Schedules</a>
    <a href="announcements.php">Announcements</a>
    <a href="cancellations.php" class="active">Cancellations</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Appointment cancellations</h1>
  <p class="sub">View all cancellations and student-provided reasons.</p>

  <!-- Stats -->
  <div class="metrics">
    <div class="metric">
      <div class="metric-val"><?= $total ?></div>
      <div class="metric-label">Total cancellations shown</div>
    </div>
    <div class="metric">
      <div class="metric-val"><?= $withReason ?></div>
      <div class="metric-label">With reason provided</div>
    </div>
    <div class="metric">
      <div class="metric-val"><?= $total > 0 ? round($withReason / $total * 100) : 0 ?>%</div>
      <div class="metric-label">Reason rate</div>
    </div>
  </div>

  <div class="card">

    <!-- Filters -->
    <form method="GET" action="cancellations.php">
      <div class="filter-row">
        <div>
          <label>Doctor</label>
          <select name="doctor_id">
            <option value="">All doctors</option>
            <?php foreach ($doctors as $doc): ?>
              <option value="<?= $doc['user_id'] ?>" <?= $filterDoctor === (int)$doc['user_id'] ? 'selected' : '' ?>>
                Dr. <?= htmlspecialchars($doc['full_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>From</label>
          <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>"/>
        </div>
        <div>
          <label>To</label>
          <input type="date" name="to"   value="<?= htmlspecialchars($filterTo) ?>"/>
        </div>
        <button type="submit" class="btn-filter">Filter</button>
      </div>
    </form>

    <?php if ($records): ?>
      <table>
        <thead>
          <tr>
            <th>Cancelled on</th>
            <th>Appt. date</th>
            <th>Student</th>
            <th>Doctor</th>
            <th>Dept.</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
          <tr>
            <td>
              <div><?= date('d M Y', strtotime($r['updated_at'])) ?></div>
              <div style="font-size:11px;color:var(--muted)"><?= date('H:i', strtotime($r['updated_at'])) ?></div>
            </td>
            <td>
              <div><?= date('d M Y', strtotime($r['available_date'])) ?></div>
              <div style="font-size:11px;color:var(--muted)"><?= substr($r['slot_start'],0,5) ?></div>
            </td>
            <td>
              <div><?= htmlspecialchars($r['student_name']) ?></div>
              <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($r['matric_number']) ?></div>
            </td>
            <td>Dr. <?= htmlspecialchars($r['doctor_name']) ?></td>
            <td><?= htmlspecialchars($r['department'] ?? '—') ?></td>
            <td>
              <?php if ($r['cancellation_reason']): ?>
                <div class="reason-box"><?= htmlspecialchars($r['cancellation_reason']) ?></div>
              <?php else: ?>
                <span class="no-reason">No reason given</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="empty">No cancellations found for the selected filters.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
