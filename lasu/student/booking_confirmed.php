<?php
// ============================================================
//  student/booking_confirmed.php
//  Shown after a successful booking submission
// ============================================================
define('REQUIRED_ROLE', 'student');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo           = getDB();
$appointmentId = (int)($_GET['id'] ?? 0);

if (!$appointmentId) {
    header('Location: dashboard.php');
    exit;
}

// Fetch the appointment details — make sure it belongs to this student
$stmt = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.triage_symptoms,
            ds.available_date, ds.slot_start, ds.slot_end,
            u.full_name   AS doctor_name,
            dept.name     AS department
       FROM appointments a
       JOIN doctor_schedules ds   ON a.schedule_id    = ds.schedule_id
       JOIN users            u    ON a.doctor_id      = u.user_id
       LEFT JOIN departments dept ON u.department_id  = dept.department_id
      WHERE a.appointment_id = :aid
        AND a.student_id     = :sid'
);
$stmt->execute([':aid' => $appointmentId, ':sid' => $currentUser['id']]);
$appt = $stmt->fetch();

if (!$appt) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Appointment Confirmed — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&family=Fraunces:ital,opsz,wght@0,9..144,600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --green: #0D3B2E; --green-light: #2E7D5A; --cream: #FAF8F3; --border: #D4E4DC; --text: #0D1F19; --muted: #7A9589; }
    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }
    nav  { background: var(--green); color: #fff; padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
    nav .brand { font-weight: 600; font-size: 15px; }
    nav a { color: #fff; opacity: .7; text-decoration: none; font-size: 13px; }
    nav a:hover { opacity: 1; }
    .page { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 24px; }
    .box { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 40px; max-width: 520px; width: 100%; text-align: center; }
    .icon { width: 64px; height: 64px; background: #E1F5EE; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px; }
    .icon svg { width: 32px; height: 32px; }
    h1   { font-family: 'Fraunces', serif; font-size: 26px; font-weight: 600; margin-bottom: 8px; }
    .lead { color: var(--muted); font-size: 14px; margin-bottom: 28px; line-height: 1.6; }
    .summary { background: #F0F8F4; border: 1px solid #C2E0D0; border-radius: 10px; padding: 16px 20px; margin-bottom: 28px; text-align: left; }
    .summary-row { display: flex; justify-content: space-between; font-size: 13px; padding: 5px 0; border-bottom: 1px solid #D4EAE0; }
    .summary-row:last-child { border-bottom: none; }
    .summary-label { color: var(--muted); }
    .summary-value { font-weight: 600; }
    .ref { font-size: 11px; color: var(--muted); margin-bottom: 28px; }
    .ref strong { color: var(--text); }
    .btn-row { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    .btn-primary { padding: 12px 24px; background: var(--green); color: #fff; border: none; border-radius: 9px; font-size: 14px; font-weight: 600; text-decoration: none; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; }
    .btn-primary:hover { background: var(--green-light); }
    .btn-ghost { padding: 12px 24px; border: 1.5px solid var(--border); border-radius: 9px; font-size: 14px; color: var(--muted); text-decoration: none; }
    .btn-ghost:hover { border-color: var(--text); color: var(--text); }
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center</span>
  <a href="dashboard.php">Dashboard</a>
</nav>

<div class="page">
  <div class="box">
    <div class="icon">
      <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="16" cy="16" r="14" stroke="#0F6E56" stroke-width="2"/>
        <path d="M10 16.5l4 4 8-9" stroke="#0F6E56" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>

    <h1>Appointment confirmed!</h1>
    <p class="lead">
      A confirmation has been queued to your registered email address.
      Please arrive 10 minutes before your slot.
    </p>

    <div class="summary">
      <div class="summary-row">
        <span class="summary-label">Department</span>
        <span class="summary-value"><?= htmlspecialchars($appt['department'] ?? '—') ?></span>
      </div>
      <div class="summary-row">
        <span class="summary-label">Doctor</span>
        <span class="summary-value">Dr. <?= htmlspecialchars($appt['doctor_name']) ?></span>
      </div>
      <div class="summary-row">
        <span class="summary-label">Date</span>
        <span class="summary-value">
          <?= date('l, d F Y', strtotime($appt['available_date'])) ?>
        </span>
      </div>
      <div class="summary-row">
        <span class="summary-label">Time</span>
        <span class="summary-value">
          <?= substr($appt['slot_start'], 0, 5) ?> – <?= substr($appt['slot_end'], 0, 5) ?>
        </span>
      </div>
    </div>

    <p class="ref">Reference: <strong>#<?= str_pad($appt['appointment_id'], 6, '0', STR_PAD_LEFT) ?></strong></p>

    <div class="btn-row">
      <a href="dashboard.php" class="btn-primary">Go to dashboard</a>
      <a href="book_appointment.php" class="btn-ghost">Book another</a>
    </div>
  </div>
</div>
</body>
</html>
