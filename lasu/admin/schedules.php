<?php
// ============================================================
//  admin/schedules.php  — Doctor schedule management
//  Create bulk time slots for a doctor for a whole week
// ============================================================
define('REQUIRED_ROLE', 'admin');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$success = '';
$error   = '';

// ── Fetch all doctors ────────────────────────────────────────
$doctors = $pdo->query(
    "SELECT u.user_id, u.full_name, d.name AS department
       FROM users u
       LEFT JOIN departments d ON u.department_id = d.department_id
      WHERE u.role = 'doctor' AND u.is_active = 1
      ORDER BY d.name, u.full_name"
)->fetchAll();

// ── Handle POST — create bulk slots ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $action   = $_POST['action'] ?? '';

        if ($action === 'create_slots') {
            $doctorId  = (int)($_POST['doctor_id']  ?? 0);
            $startDate = $_POST['start_date'] ?? '';
            $endDate   = $_POST['end_date']   ?? '';
            $startTime = $_POST['start_time'] ?? '';
            $endTime   = $_POST['end_time']   ?? '';
            $duration  = (int)($_POST['slot_duration'] ?? 30);
            $days      = $_POST['days'] ?? [];

            if (!$doctorId || !$startDate || !$endDate || !$startTime || !$endTime || empty($days)) {
                $error = 'Please fill in all fields and select at least one day.';
            } else {
                try {
                    $created = 0;
                    $skipped = 0;

                    $current = new DateTime($startDate);
                    $last    = new DateTime($endDate);
                    $last->modify('+1 day');

                    $stmt = $pdo->prepare(
                        'INSERT IGNORE INTO doctor_schedules
                           (doctor_id, available_date, slot_start, slot_end)
                         VALUES (:did, :date, :start, :end)'
                    );

                    while ($current < $last) {
                        // Check if this weekday is selected (0=Sun,1=Mon,...6=Sat)
                        if (in_array($current->format('w'), $days)) {
                            $date      = $current->format('Y-m-d');
                            $slotStart = new DateTime("{$date} {$startTime}");
                            $slotEnd   = clone $slotStart;
                            $slotEnd->modify("+{$duration} minutes");
                            $dayEnd    = new DateTime("{$date} {$endTime}");

                            while ($slotEnd <= $dayEnd) {
                                $stmt->execute([
                                    ':did'   => $doctorId,
                                    ':date'  => $date,
                                    ':start' => $slotStart->format('H:i:s'),
                                    ':end'   => $slotEnd->format('H:i:s'),
                                ]);
                                $rows = $stmt->rowCount();
                                $rows ? $created++ : $skipped++;
                                $slotStart->modify("+{$duration} minutes");
                                $slotEnd->modify("+{$duration} minutes");
                            }
                        }
                        $current->modify('+1 day');
                    }

                    $success = "{$created} slot(s) created. {$skipped} duplicate(s) skipped.";

                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $error = 'Database error creating slots. Please try again.';
                }
            }
        }

        // ── Delete all slots for a doctor on a date ──────────
        if ($action === 'delete_day') {
            $doctorId = (int)($_POST['doctor_id'] ?? 0);
            $date     = $_POST['date'] ?? '';
            if ($doctorId && $date) {
                $del = $pdo->prepare(
                    "DELETE FROM doctor_schedules
                      WHERE doctor_id = :did AND available_date = :date AND is_booked = 0"
                );
                $del->execute([':did' => $doctorId, ':date' => $date]);
                $success = $del->rowCount() . ' unbooked slot(s) removed for ' . date('d M Y', strtotime($date)) . '.';
            }
        }
    }
}

// ── Fetch upcoming schedules for selected doctor ─────────────
$viewDoctor = (int)($_GET['doctor_id'] ?? ($doctors[0]['user_id'] ?? 0));
$schedules  = [];

if ($viewDoctor) {
    $sch = $pdo->prepare(
        "SELECT available_date,
                SUM(1)          AS total_slots,
                SUM(is_booked)  AS booked_slots
           FROM doctor_schedules
          WHERE doctor_id     = :did
            AND available_date >= CURDATE()
          GROUP BY available_date
          ORDER BY available_date
          LIMIT 30"
    );
    $sch->execute([':did' => $viewDoctor]);
    $schedules = $sch->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Schedules — LASU Health Center</title>
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
    .page { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
    h1 { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
    .sub { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
    .layout { display: grid; grid-template-columns: 360px 1fr; gap: 24px; align-items: start; }
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; }
    .card h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; }
    .alert { border-radius: 8px; padding: 11px 14px; font-size: 13px; margin-bottom: 18px; }
    .alert-success { background: var(--green-bg); border: 1px solid #9FE1CB; color: var(--green); }
    .alert-error   { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); }
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
    .field input, .field select {
      width: 100%; height: 40px; padding: 0 12px; border: 1px solid var(--border);
      border-radius: 7px; font-size: 13px; font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--text); background: var(--white); outline: none;
    }
    .field input:focus, .field select:focus { border-color: var(--coral-mid); box-shadow: 0 0 0 3px rgba(216,90,48,.1); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .day-checks { display: grid; grid-template-columns: repeat(4,1fr); gap: 8px; }
    .day-check  { display: flex; align-items: center; gap: 6px; font-size: 13px; }
    .day-check input { width: 15px; height: 15px; accent-color: var(--coral); }
    .btn { height: 38px; padding: 0 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Plus Jakarta Sans', sans-serif; }
    .btn-primary { background: var(--coral); color: #fff; width: 100%; margin-top: 6px; }
    .btn-primary:hover { background: var(--coral-mid); }
    .btn-sm { height: 28px; padding: 0 10px; border: none; border-radius: 5px; font-size: 11px; font-weight: 600; cursor: pointer; background: #FCEBEB; color: #791F1F; }
    .doctor-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
    .doctor-tab { padding: 7px 14px; border: 1.5px solid var(--border); border-radius: 7px; font-size: 13px; cursor: pointer; text-decoration: none; color: var(--muted); background: var(--white); }
    .doctor-tab.active { border-color: var(--coral); color: var(--coral); background: var(--coral-light); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; color: var(--muted); font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
    td { padding: 10px 0; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    .slot-bar { height: 8px; border-radius: 4px; background: #E0D8D0; overflow: hidden; width: 100px; display: inline-block; }
    .slot-fill { height: 100%; background: var(--coral); border-radius: 4px; }
    .empty { color: var(--muted); font-size: 13px; padding: 12px 0; font-style: italic; }
    @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Admin</span>
  <div class="nav-links">
    <a href="dashboard.php">Overview</a>
    <a href="users.php">Users</a>
    <a href="schedules.php" class="active">Schedules</a>
    <a href="announcements.php">Announcements</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Schedule management</h1>
  <p class="sub">Create and manage doctor time slots in bulk.</p>

  <?php if ($success): ?>
    <div class="alert alert-success">&#10003; <?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="layout">

    <!-- Create slots form -->
    <div class="card">
      <h2>Create time slots</h2>
      <form method="POST" action="schedules.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
        <input type="hidden" name="action"     value="create_slots"/>

        <div class="field">
          <label>Doctor *</label>
          <select name="doctor_id" required>
            <option value="">— Select doctor —</option>
            <?php foreach ($doctors as $doc): ?>
              <option value="<?= $doc['user_id'] ?>">
                Dr. <?= htmlspecialchars($doc['full_name']) ?> — <?= htmlspecialchars($doc['department'] ?? '') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field-row">
          <div class="field">
            <label>From date *</label>
            <input type="date" name="start_date" min="<?= date('Y-m-d') ?>" required/>
          </div>
          <div class="field">
            <label>To date *</label>
            <input type="date" name="end_date"   min="<?= date('Y-m-d') ?>" required/>
          </div>
        </div>

        <div class="field-row">
          <div class="field">
            <label>Start time *</label>
            <input type="time" name="start_time" value="09:00" required/>
          </div>
          <div class="field">
            <label>End time *</label>
            <input type="time" name="end_time"   value="16:00" required/>
          </div>
        </div>

        <div class="field">
          <label>Slot duration (minutes)</label>
          <select name="slot_duration">
            <option value="15">15 minutes</option>
            <option value="20">20 minutes</option>
            <option value="30" selected>30 minutes</option>
            <option value="45">45 minutes</option>
            <option value="60">60 minutes</option>
          </select>
        </div>

        <div class="field">
          <label>Days of the week *</label>
          <div class="day-checks">
            <label class="day-check"><input type="checkbox" name="days[]" value="1" checked/> Mon</label>
            <label class="day-check"><input type="checkbox" name="days[]" value="2" checked/> Tue</label>
            <label class="day-check"><input type="checkbox" name="days[]" value="3" checked/> Wed</label>
            <label class="day-check"><input type="checkbox" name="days[]" value="4" checked/> Thu</label>
            <label class="day-check"><input type="checkbox" name="days[]" value="5" checked/> Fri</label>
            <label class="day-check"><input type="checkbox" name="days[]" value="6"/> Sat</label>
            <label class="day-check"><input type="checkbox" name="days[]" value="0"/> Sun</label>
          </div>
        </div>

        <button type="submit" class="btn btn-primary">Generate slots</button>
      </form>
    </div>

    <!-- Schedule viewer -->
    <div>
      <div class="card">
        <h2>Upcoming schedule</h2>

        <div class="doctor-tabs">
          <?php foreach ($doctors as $doc): ?>
            <a href="schedules.php?doctor_id=<?= $doc['user_id'] ?>"
               class="doctor-tab <?= $doc['user_id'] === $viewDoctor ? 'active' : '' ?>">
              Dr. <?= htmlspecialchars(explode(' ', $doc['full_name'])[0]) ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ($schedules): ?>
          <table>
            <thead>
              <tr><th>Date</th><th>Day</th><th>Total slots</th><th>Booked</th><th>Fill</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $s): ?>
              <?php $pct = $s['total_slots'] > 0 ? round($s['booked_slots'] / $s['total_slots'] * 100) : 0; ?>
              <tr>
                <td><?= date('d M Y', strtotime($s['available_date'])) ?></td>
                <td><?= date('D', strtotime($s['available_date'])) ?></td>
                <td><?= $s['total_slots'] ?></td>
                <td><?= $s['booked_slots'] ?></td>
                <td>
                  <div class="slot-bar">
                    <div class="slot-fill" style="width:<?= $pct ?>%"></div>
                  </div>
                  <span style="font-size:11px;color:var(--muted);margin-left:6px"><?= $pct ?>%</span>
                </td>
                <td>
                  <form method="POST" action="schedules.php"
                        onsubmit="return confirm('Remove all unbooked slots on this day?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
                    <input type="hidden" name="action"    value="delete_day"/>
                    <input type="hidden" name="doctor_id" value="<?= $viewDoctor ?>"/>
                    <input type="hidden" name="date"      value="<?= $s['available_date'] ?>"/>
                    <button type="submit" class="btn-sm">Clear</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="empty">No upcoming slots found for this doctor.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
</body>
</html>
