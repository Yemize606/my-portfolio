<?php
// ============================================================
//  doctor/dashboard.php  (updated — cancel & reschedule)
// ============================================================
define('REQUIRED_ROLE', 'doctor');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// Flash messages
$flashMessages = [
    'cancelled'    => ['ok',  'Appointment cancelled and student notified.'],
    'noreason'     => ['err', 'Please provide a reason.'],
    'notscheduled' => ['err', 'That appointment cannot be cancelled — it is no longer scheduled.'],
    'error'        => ['err', 'Something went wrong. Please try again.'],
    'csrf'         => ['err', 'Security error. Please try again.'],
];
$flash = $flashMessages[$_GET['msg'] ?? ''] ?? null;

// Today's appointments
$stmt = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.triage_symptoms,
            a.cancellation_reason, a.doctor_cancel_reason, a.reschedule_status,
            ds.slot_start, ds.slot_end,
            s.full_name    AS student_name,
            s.matric_number
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id = ds.schedule_id
       JOIN users            s  ON a.student_id  = s.user_id
      WHERE a.doctor_id       = :id
        AND ds.available_date  = CURDATE()
      ORDER BY ds.slot_start'
);
$stmt->execute([':id' => $currentUser['id']]);
$todayAppts = $stmt->fetchAll();

// Upcoming (next 7 days)
$upcoming = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.reschedule_status,
            ds.available_date, ds.slot_start,
            s.full_name AS student_name
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id = ds.schedule_id
       JOIN users            s  ON a.student_id  = s.user_id
      WHERE a.doctor_id       = :id
        AND a.status          = \'Scheduled\'
        AND ds.available_date > CURDATE()
        AND ds.available_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      ORDER BY ds.available_date, ds.slot_start
      LIMIT 10'
);
$upcoming->execute([':id' => $currentUser['id']]);
$upcomingAppts = $upcoming->fetchAll();

// Recent cancellations
$cancellations = $pdo->prepare(
    'SELECT a.appointment_id, a.cancellation_reason, a.doctor_cancel_reason,
            ds.available_date, ds.slot_start,
            s.full_name AS student_name, s.matric_number, a.updated_at
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id = ds.schedule_id
       JOIN users            s  ON a.student_id  = s.user_id
      WHERE a.doctor_id  = :id
        AND a.status     = \'Cancelled\'
        AND a.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      ORDER BY a.updated_at DESC LIMIT 5'
);
$cancellations->execute([':id' => $currentUser['id']]);
$recentCancellations = $cancellations->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Doctor Dashboard — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--purple:#3C3489;--pm:#534AB7;--pl:#EEEDFE;--cream:#FAF8F3;--white:#fff;--border:#D4D2E8;--text:#0D0D1F;--muted:#7A78A0;--sb:#E1F5EE;--st:#085041;--erb:#FEF2F2;--ert:#991B1B;--amb:#FFF8EC;--amt:#7A5C1E}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--purple);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:4px}
    nav a{color:#fff;text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:7px;opacity:.8;transition:opacity .15s,background .15s}
    nav a:hover,nav a.active{opacity:1;background:rgba(255,255,255,.15)}
    .page{max-width:1060px;margin:0 auto;padding:32px 24px}
    h1{font-size:22px;font-weight:600;margin-bottom:4px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:24px}
    .alert{border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:20px;line-height:1.5}
    .aok{background:var(--sb);border:1px solid #9FE1CB;color:var(--st)}
    .aerr{background:var(--erb);border:1px solid #FECACA;color:var(--ert)}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:20px}
    .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .card-header h2{font-size:15px;font-weight:600}
    .count-badge{background:var(--pl);color:var(--purple);font-size:12px;font-weight:600;padding:2px 10px;border-radius:20px}
    .cancel-badge{background:var(--erb);color:var(--ert);font-size:12px;font-weight:600;padding:2px 10px;border-radius:20px}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;color:var(--muted);font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.04em;padding-bottom:8px;border-bottom:1px solid var(--border)}
    td{padding:10px 0;border-bottom:1px solid var(--border);vertical-align:top}
    tr:last-child td{border-bottom:none}
    .triage{font-size:12px;color:var(--muted);max-width:160px;line-height:1.4}
    .reason-box{font-size:11px;color:var(--ert);background:var(--erb);border-radius:5px;padding:3px 7px;max-width:160px;line-height:1.4;font-style:italic}
    .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
    .badge-scheduled{background:var(--sb);color:var(--st)}
    .badge-completed{background:#EAF3DE;color:#27500A}
    .badge-cancelled{background:#F1EFE8;color:#444441}
    .badge-missed{background:var(--erb);color:var(--ert)}
    .badge-proposed{background:var(--amb);color:var(--amt)}
    .actions{display:flex;gap:6px;flex-wrap:wrap}
    .btn-open{display:inline-block;padding:5px 12px;background:var(--purple);color:#fff;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none}
    .btn-open:hover{background:var(--pm)}
    .btn-cancel{background:none;border:1px solid #FECACA;color:var(--ert);font-size:12px;font-weight:600;padding:5px 10px;border-radius:6px;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn-cancel:hover{background:var(--erb)}
    .btn-reschedule{background:none;border:1px solid var(--amb);color:var(--amt);font-size:12px;font-weight:600;padding:5px 10px;border-radius:6px;cursor:pointer;text-decoration:none;display:inline-block}
    .btn-reschedule:hover{background:var(--amb)}
    .empty{color:var(--muted);font-size:13px;font-style:italic;padding:8px 0}

    /* Cancel modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
    .modal-overlay.open{opacity:1;pointer-events:all}
    .modal{background:var(--white);border-radius:14px;padding:28px 32px;width:100%;max-width:440px;box-shadow:0 8px 32px rgba(0,0,0,.15);transform:translateY(10px);transition:transform .2s}
    .modal-overlay.open .modal{transform:translateY(0)}
    .modal h3{font-size:17px;font-weight:600;margin-bottom:6px}
    .modal .msub{font-size:13px;color:var(--muted);margin-bottom:16px;line-height:1.5}
    .modal .abox{background:var(--cream);border:1px solid var(--border);border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px;line-height:1.6}
    .modal label{display:block;font-size:12px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
    .modal textarea{width:100%;min-height:85px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;resize:vertical;outline:none;line-height:1.6}
    .modal textarea:focus{border-color:var(--ert);box-shadow:0 0 0 3px rgba(153,27,27,.08)}
    .mbtns{display:flex;gap:10px;margin-top:16px}
    .btn-conf{flex:1;height:42px;background:var(--ert);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn-conf:hover{background:#791F1F}
    .btn-bk{height:42px;padding:0 18px;background:transparent;border:1.5px solid var(--border);border-radius:8px;font-size:13px;color:var(--muted);cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn-bk:hover{border-color:var(--text);color:var(--text)}
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Doctor Portal</span>
  <div class="links">
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="manage_slots.php">My slots</a>
    <a href="change_password.php">Change password</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Dr. <?= htmlspecialchars($currentUser['name']) ?></h1>
  <p class="sub">Today is <?= date('l, d F Y') ?></p>

  <?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'aok' : 'aerr' ?>">
      <?= $flash[0] === 'ok' ? '&#10003;' : '&#9888;' ?> <?= htmlspecialchars($flash[1]) ?>
    </div>
  <?php endif; ?>

  <!-- Today's schedule -->
  <div class="card">
    <div class="card-header">
      <h2>Today's schedule</h2>
      <span class="count-badge"><?= count($todayAppts) ?> appointment<?= count($todayAppts) !== 1 ? 's' : '' ?></span>
    </div>
    <?php if ($todayAppts): ?>
      <table>
        <thead><tr><th>Time</th><th>Patient</th><th>Matric</th><th>Triage / Note</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($todayAppts as $a): ?>
          <tr>
            <td style="white-space:nowrap"><?= substr($a['slot_start'],0,5) ?> – <?= substr($a['slot_end'],0,5) ?></td>
            <td><?= htmlspecialchars($a['student_name']) ?></td>
            <td><?= htmlspecialchars($a['matric_number']) ?></td>
            <td>
              <?php if ($a['status'] === 'Cancelled'): ?>
                <div class="reason-box"><?= htmlspecialchars(substr($a['doctor_cancel_reason'] ?: $a['cancellation_reason'] ?: 'No reason',0,80)) ?></div>
              <?php elseif ($a['reschedule_status'] === 'proposed'): ?>
                <div style="font-size:11px;color:var(--amt);font-style:italic">Reschedule pending student response</div>
              <?php elseif ($a['triage_symptoms']): ?>
                <div class="triage"><?= htmlspecialchars(substr($a['triage_symptoms'],0,80)) ?></div>
              <?php else: ?>
                <span style="font-size:12px;color:var(--muted);font-style:italic">None</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-<?= strtolower($a['status']) ?>"><?= $a['status'] ?></span>
              <?php if ($a['reschedule_status'] === 'proposed'): ?>
                <br/><span class="badge badge-proposed" style="margin-top:3px">Reschedule sent</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <?php if ($a['status'] === 'Scheduled'): ?>
                  <a href="consultation.php?id=<?= $a['appointment_id'] ?>" class="btn-open">Open</a>
                  <a href="reschedule_appointment.php?id=<?= $a['appointment_id'] ?>" class="btn-reschedule">Reschedule</a>
                  <button class="btn-cancel" onclick="openCancel(<?= $a['appointment_id'] ?>,'<?= htmlspecialchars(addslashes($a['student_name'])) ?>')">Cancel</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="empty">No appointments scheduled for today.</p>
    <?php endif; ?>
  </div>

  <!-- Upcoming -->
  <div class="card">
    <div class="card-header">
      <h2>Upcoming — next 7 days</h2>
      <span class="count-badge"><?= count($upcomingAppts) ?></span>
    </div>
    <?php if ($upcomingAppts): ?>
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>Patient</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($upcomingAppts as $a): ?>
          <tr>
            <td><?= date('D d M', strtotime($a['available_date'])) ?></td>
            <td><?= substr($a['slot_start'],0,5) ?></td>
            <td><?= htmlspecialchars($a['student_name']) ?></td>
            <td>
              <?php if ($a['reschedule_status'] === 'proposed'): ?>
                <span class="badge badge-proposed">Reschedule sent</span>
              <?php else: ?>
                <span class="badge badge-scheduled">Scheduled</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <a href="consultation.php?id=<?= $a['appointment_id'] ?>" class="btn-open">View</a>
                <a href="reschedule_appointment.php?id=<?= $a['appointment_id'] ?>" class="btn-reschedule">Reschedule</a>
                <button class="btn-cancel" onclick="openCancel(<?= $a['appointment_id'] ?>,'<?= htmlspecialchars(addslashes($a['student_name'])) ?>')">Cancel</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="empty">No upcoming appointments in the next 7 days.</p>
    <?php endif; ?>
  </div>

  <!-- Recent cancellations -->
  <?php if ($recentCancellations): ?>
  <div class="card">
    <div class="card-header">
      <h2>Recent cancellations</h2>
      <span class="cancel-badge"><?= count($recentCancellations) ?> in last 7 days</span>
    </div>
    <table>
      <thead><tr><th>Date</th><th>Patient</th><th>Reason</th><th>Cancelled at</th></tr></thead>
      <tbody>
      <?php foreach ($recentCancellations as $c): ?>
        <tr>
          <td><?= date('d M Y', strtotime($c['available_date'])) ?> <?= substr($c['slot_start'],0,5) ?></td>
          <td><?= htmlspecialchars($c['student_name']) ?><br/><span style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($c['matric_number']) ?></span></td>
          <td>
            <?php $reason = $c['doctor_cancel_reason'] ?: $c['cancellation_reason']; ?>
            <?php if ($reason): ?>
              <div class="reason-box"><?= htmlspecialchars(substr($reason,0,100)) ?></div>
            <?php else: ?>
              <span style="font-size:12px;color:var(--muted);font-style:italic">No reason given</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px"><?= date('d M, H:i', strtotime($c['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Cancel modal -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3>Cancel appointment</h3>
    <p class="msub">Please state your reason. The student will be notified by email.</p>
    <div class="abox" id="modal-info"></div>
    <form method="POST" action="cancel_appointment.php">
      <input type="hidden" name="csrf_token"          value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
      <input type="hidden" name="appointment_id"      id="modal-id"/>
      <label for="cancel-reason">Reason *</label>
      <textarea id="cancel-reason" name="doctor_cancel_reason"
                placeholder="e.g. Doctor unavailable due to emergency. Please rebook at your convenience." required></textarea>
      <div class="mbtns">
        <button type="button" class="btn-bk" onclick="closeModal()">Go back</button>
        <button type="submit" class="btn-conf">Confirm cancellation</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCancel(id, name) {
  document.getElementById('modal-id').value   = id;
  document.getElementById('modal-info').textContent = 'Patient: ' + name;
  document.getElementById('cancel-reason').value = '';
  document.getElementById('modal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal() {
  document.getElementById('modal').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
</body>
</html>
