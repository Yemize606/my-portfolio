<?php
// ============================================================
//  student/dashboard.php  (updated — with cancel feature)
// ============================================================
define('REQUIRED_ROLE', 'student');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// ── Flash messages from cancel redirect ─────────────────────
$flashMessages = [
    'cancelled'    => ['ok',  'Your appointment has been cancelled successfully.'],
    'noreason'     => ['err', 'Please provide a reason for cancellation.'],
    'notfound'     => ['err', 'Appointment not found.'],
    'notscheduled' => ['err', 'This appointment cannot be cancelled — it is no longer scheduled.'],
    'error'        => ['err', 'Something went wrong. Please try again.'],
    'csrf'         => ['err', 'Security error. Please try again.'],
    'invalid'      => ['err', 'Invalid request.'],
];
$msg   = $_GET['msg'] ?? '';
$flash = $flashMessages[$msg] ?? null;

// Highlight the appointment that triggered the "noreason" error
$highlightAppt = (int)($_GET['appt'] ?? 0);

// ── Upcoming appointments ────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.triage_symptoms, a.cancellation_reason,
            ds.available_date, ds.slot_start, ds.slot_end,
            u.full_name  AS doctor_name,
            dept.name    AS department
       FROM appointments a
       JOIN doctor_schedules ds   ON a.schedule_id    = ds.schedule_id
       JOIN users            u    ON a.doctor_id      = u.user_id
       LEFT JOIN departments dept ON u.department_id  = dept.department_id
      WHERE a.student_id = :id
        AND a.status     = \'Scheduled\'
        AND ds.available_date >= CURDATE()
      ORDER BY ds.available_date, ds.slot_start
      LIMIT 5'
);
$stmt->execute([':id' => $currentUser['id']]);
$appointments = $stmt->fetchAll();

// ── Past appointments (last 5) ───────────────────────────────
$past = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.cancellation_reason,
            ds.available_date, ds.slot_start,
            u.full_name AS doctor_name,
            dept.name   AS department
       FROM appointments a
       JOIN doctor_schedules ds   ON a.schedule_id    = ds.schedule_id
       JOIN users            u    ON a.doctor_id      = u.user_id
       LEFT JOIN departments dept ON u.department_id  = dept.department_id
      WHERE a.student_id = :id
        AND (a.status != \'Scheduled\' OR ds.available_date < CURDATE())
      ORDER BY ds.available_date DESC
      LIMIT 5'
);
$past->execute([':id' => $currentUser['id']]);
$pastAppts = $past->fetchAll();

// ── Announcements ────────────────────────────────────────────
$ann = $pdo->query(
    'SELECT title, body, published_at FROM announcements
      WHERE is_active = 1
      ORDER BY published_at DESC LIMIT 5'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Student Dashboard — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--green:#0D3B2E;--gm:#1A5C44;--gl:#2E7D5A;--cream:#FAF8F3;--white:#fff;--border:#D4E4DC;--text:#0D1F19;--muted:#7A9589;--erb:#FEF2F2;--erb2:#FECACA;--ert:#991B1B;--sb:#E1F5EE;--sb2:#9FE1CB;--st:#085041}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--green);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:4px;align-items:center}
    nav a{color:#fff;text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:7px;opacity:.8;transition:opacity .15s,background .15s}
    nav a:hover,nav a.active{opacity:1;background:rgba(255,255,255,.15)}
    .page{max-width:1060px;margin:0 auto;padding:32px 24px}
    h1{font-size:22px;font-weight:600;margin-bottom:4px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:24px}
    .alert{border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:20px;line-height:1.5}
    .aok{background:var(--sb);border:1px solid var(--sb2);color:var(--st)}
    .aerr{background:var(--erb);border:1px solid var(--erb2);color:var(--ert)}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:20px}
    .card h2{font-size:15px;font-weight:600;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;color:var(--muted);font-weight:500;padding-bottom:8px;border-bottom:1px solid var(--border);font-size:11px;text-transform:uppercase;letter-spacing:.04em}
    td{padding:10px 0;border-bottom:1px solid var(--border);vertical-align:middle}
    tr:last-child td{border-bottom:none}
    .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
    .badge-scheduled{background:var(--sb);color:var(--st)}
    .badge-completed{background:#EAF3DE;color:#27500A}
    .badge-missed{background:#FCEBEB;color:#791F1F}
    .badge-cancelled{background:#F1EFE8;color:#444441}
    .btn{display:inline-block;padding:10px 20px;background:var(--green);color:#fff;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;border:none;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn:hover{background:var(--gm)}
    .btn-cancel{background:none;border:1px solid var(--erb2);color:var(--ert);font-size:12px;font-weight:600;padding:4px 10px;border-radius:6px;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:background .15s}
    .btn-cancel:hover{background:var(--erb)}
    .ann-item{padding:12px 0;border-bottom:1px solid var(--border)}
    .ann-item:last-child{border-bottom:none}
    .ann-title{font-weight:600;font-size:13px;margin-bottom:4px}
    .ann-body{font-size:13px;color:var(--muted);line-height:1.5}
    .ann-date{font-size:11px;color:var(--muted);margin-top:4px}
    .empty{color:var(--muted);font-size:13px;font-style:italic;padding:8px 0}
    .cancel-reason{font-size:11px;color:#791F1F;margin-top:3px;font-style:italic}

    /* Modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100;display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .2s}
    .modal-overlay.open{opacity:1;pointer-events:all}
    .modal{background:var(--white);border-radius:14px;padding:28px 32px;width:100%;max-width:460px;box-shadow:0 8px 32px rgba(0,0,0,.15);transform:translateY(10px);transition:transform .2s}
    .modal-overlay.open .modal{transform:translateY(0)}
    .modal h3{font-size:17px;font-weight:600;margin-bottom:6px}
    .modal .modal-sub{font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.5}
    .modal .appt-box{background:var(--cream);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-size:13px;margin-bottom:18px;line-height:1.7}
    .modal .appt-box strong{font-weight:600}
    .modal label{display:block;font-size:12px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
    .modal textarea{width:100%;min-height:90px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);resize:vertical;outline:none;line-height:1.6}
    .modal textarea:focus{border-color:var(--ert);box-shadow:0 0 0 3px rgba(153,27,27,.08)}
    .modal-btns{display:flex;gap:10px;margin-top:16px}
    .btn-confirm-cancel{flex:1;height:42px;background:var(--ert);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn-confirm-cancel:hover{background:#791F1F}
    .btn-back-modal{height:42px;padding:0 18px;background:transparent;border:1.5px solid var(--border);border-radius:8px;font-size:13px;color:var(--muted);cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif}
    .btn-back-modal:hover{border-color:var(--text);color:var(--text)}
    @media(max-width:700px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>

<nav>
  <span class="brand">LASU Health Center</span>
  <div class="links">
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="book_appointment.php">Book appointment</a>
    <a href="change_password.php">Change password</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Good day, <?= htmlspecialchars(explode(' ', $currentUser['name'])[0]) ?></h1>
  <p class="sub">Here is your health center overview.</p>

  <?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'aok' : 'aerr' ?>">
      <?= $flash[0] === 'ok' ? '&#10003;' : '&#9888;' ?> <?= htmlspecialchars($flash[1]) ?>
    </div>
  <?php endif; ?>

  <div class="grid">

    <!-- Upcoming appointments -->
    <div class="card">
      <h2>Upcoming appointments</h2>
      <?php if ($appointments): ?>
        <table>
          <thead>
            <tr><th>Date</th><th>Time</th><th>Doctor</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr class="<?= ($highlightAppt === (int)$a['appointment_id']) ? 'highlighted' : '' ?>">
              <td><?= date('d M Y', strtotime($a['available_date'])) ?></td>
              <td><?= substr($a['slot_start'],0,5) ?></td>
              <td>
                <div>Dr. <?= htmlspecialchars($a['doctor_name']) ?></div>
                <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($a['department'] ?? '') ?></div>
              </td>
              <td><span class="badge badge-<?= strtolower($a['status']) ?>"><?= $a['status'] ?></span></td>
              <td>
                <button class="btn-cancel"
                  onclick="openCancel(
                    <?= $a['appointment_id'] ?>,
                    'Dr. <?= htmlspecialchars(addslashes($a['doctor_name'])) ?>',
                    '<?= date('d M Y', strtotime($a['available_date'])) ?>',
                    '<?= substr($a['slot_start'],0,5) ?>'
                  )">Cancel</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="empty">No upcoming appointments.</p>
      <?php endif; ?>
      <a href="book_appointment.php" class="btn" style="display:inline-block;margin-top:16px">Book an appointment</a>
    </div>

    <!-- Announcements -->
    <div class="card">
      <h2>Health center notices</h2>
      <?php if ($ann): ?>
        <?php foreach ($ann as $item): ?>
          <div class="ann-item">
            <div class="ann-title"><?= htmlspecialchars($item['title']) ?></div>
            <div class="ann-body"><?= nl2br(htmlspecialchars($item['body'])) ?></div>
            <div class="ann-date"><?= date('d M Y', strtotime($item['published_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="empty">No announcements at the moment.</p>
      <?php endif; ?>
    </div>

  </div>

  <!-- Past appointments -->
  <?php if ($pastAppts): ?>
  <div class="card">
    <h2>Recent visit history</h2>
    <table>
      <thead>
        <tr><th>Date</th><th>Time</th><th>Doctor</th><th>Department</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($pastAppts as $a): ?>
        <tr>
          <td><?= date('d M Y', strtotime($a['available_date'])) ?></td>
          <td><?= substr($a['slot_start'],0,5) ?></td>
          <td>Dr. <?= htmlspecialchars($a['doctor_name']) ?></td>
          <td><?= htmlspecialchars($a['department'] ?? '—') ?></td>
          <td>
            <span class="badge badge-<?= strtolower($a['status']) ?>"><?= $a['status'] ?></span>
            <?php if ($a['status'] === 'Cancelled' && $a['cancellation_reason']): ?>
              <div class="cancel-reason">Reason: <?= htmlspecialchars(substr($a['cancellation_reason'], 0, 60)) . (strlen($a['cancellation_reason']) > 60 ? '…' : '') ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Cancel modal -->
<div class="modal-overlay" id="modal-overlay" onclick="closeModal(event)">
  <div class="modal">
    <h3>Cancel appointment</h3>
    <p class="modal-sub">Please tell us why you are cancelling. This helps the health center improve its service.</p>

    <div class="appt-box" id="modal-appt-info"></div>

    <form method="POST" action="cancel_appointment.php" id="cancel-form">
      <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
      <input type="hidden" name="appointment_id"  id="modal-appt-id"/>

      <label for="cancel-reason">Reason for cancellation *</label>
      <textarea id="cancel-reason" name="cancellation_reason"
                placeholder="e.g. I am feeling better and no longer need the appointment…"
                required></textarea>

      <div class="modal-btns">
        <button type="button" class="btn-back-modal" onclick="closeCancel()">Keep appointment</button>
        <button type="submit" class="btn-confirm-cancel">Confirm cancellation</button>
      </div>
    </form>
  </div>
</div>

<script>
function openCancel(id, doctor, date, time) {
  document.getElementById('modal-appt-id').value   = id;
  document.getElementById('modal-appt-info').innerHTML =
    '<strong>' + doctor + '</strong><br>' + date + ' at ' + time;
  document.getElementById('cancel-reason').value   = '';
  document.getElementById('modal-overlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeCancel() {
  document.getElementById('modal-overlay').classList.remove('open');
  document.body.style.overflow = '';
}

function closeModal(e) {
  if (e.target === document.getElementById('modal-overlay')) closeCancel();
}

// Keep modal open if there was a "noreason" error (page reloaded)
<?php if ($msg === 'noreason' && $highlightAppt): ?>
  // Re-open modal for the highlighted appointment
  const row = document.querySelector('button[onclick*="<?= $highlightAppt ?>"]');
  if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
<?php endif; ?>
</script>

</body>
</html>
