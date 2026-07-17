<?php
// ============================================================
//  doctor/consultation.php
//  Opens a specific appointment, shows patient + triage info,
//  lets the doctor log diagnosis, prescription, follow-up.
// ============================================================
define('REQUIRED_ROLE', 'doctor');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo           = getDB();
$appointmentId = (int)($_GET['id'] ?? 0);

if (!$appointmentId) {
    header('Location: dashboard.php');
    exit;
}

// Fetch appointment — must belong to this doctor
$stmt = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.triage_symptoms, a.booked_at,
            ds.available_date, ds.slot_start, ds.slot_end,
            s.full_name      AS student_name,
            s.matric_number,
            s.contact_email  AS student_email,
            s.contact_phone  AS student_phone,
            dept.name        AS department
       FROM appointments a
       JOIN doctor_schedules ds   ON a.schedule_id    = ds.schedule_id
       JOIN users            s    ON a.student_id     = s.user_id
       LEFT JOIN departments dept ON dept.department_id = (
           SELECT department_id FROM users WHERE user_id = :doc_id2
       )
      WHERE a.appointment_id = :aid
        AND a.doctor_id      = :doc_id'
);
$stmt->execute([
    ':aid'     => $appointmentId,
    ':doc_id'  => $currentUser['id'],
    ':doc_id2' => $currentUser['id'],
]);
$appt = $stmt->fetch();

if (!$appt) {
    header('Location: dashboard.php');
    exit;
}

// Check if diagnosis already exists
$diagStmt = $pdo->prepare(
    'SELECT * FROM diagnoses WHERE appointment_id = :aid'
);
$diagStmt->execute([':aid' => $appointmentId]);
$existingDiag = $diagStmt->fetch();

// Fetch patient's full visit history (excluding current)
$historyStmt = $pdo->prepare(
    'SELECT ds.available_date, ds.slot_start, u.full_name AS doctor_name,
            d.doctor_diagnosis, d.prescription_notes, d.follow_up_required
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id    = ds.schedule_id
       JOIN users            u  ON a.doctor_id      = u.user_id
       LEFT JOIN diagnoses   d  ON d.appointment_id = a.appointment_id
      WHERE a.student_id = (
              SELECT student_id FROM appointments WHERE appointment_id = :aid
            )
        AND a.appointment_id != :aid2
        AND a.status = \'Completed\'
      ORDER BY ds.available_date DESC
      LIMIT 5'
);
$historyStmt->execute([':aid' => $appointmentId, ':aid2' => $appointmentId]);
$history = $historyStmt->fetchAll();

// CSRF
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$saved   = isset($_GET['saved']);
$saveErr = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Consultation — <?= htmlspecialchars($appt['student_name']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&family=Fraunces:opsz,wght@9..144,600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --purple: #3C3489; --purple-mid: #534AB7; --purple-light: #EEEDFE;
      --cream: #FAF8F3; --white: #fff;
      --border: #D4D2E8; --text: #0D0D1F; --muted: #7A78A0;
      --green-bg: #E1F5EE; --green-text: #085041;
      --amber-bg: #FAEEDA; --amber-text: #633806;
      --error-bg: #FEF2F2; --error-border: #FECACA; --error-text: #991B1B;
    }
    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text); }

    /* Nav */
    nav { background: var(--purple); color: #fff; padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
    nav .brand { font-weight: 600; font-size: 15px; }
    nav .nav-links { display: flex; gap: 20px; align-items: center; }
    nav a { color: #fff; opacity: .7; text-decoration: none; font-size: 13px; }
    nav a:hover { opacity: 1; }

    /* Layout */
    .page { max-width: 1100px; margin: 0 auto; padding: 32px 24px; display: grid; grid-template-columns: 300px 1fr; gap: 24px; align-items: start; }

    /* Sidebar */
    .sidebar { display: flex; flex-direction: column; gap: 16px; }

    /* Cards */
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 20px 22px; }
    .card-title { font-size: 11px; font-weight: 600; letter-spacing: .07em; text-transform: uppercase; color: var(--muted); margin-bottom: 14px; }

    /* Patient info */
    .patient-avatar { width: 52px; height: 52px; border-radius: 50%; background: var(--purple-light); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 600; color: var(--purple-mid); margin-bottom: 12px; }
    .patient-name { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
    .patient-matric { font-size: 12px; color: var(--muted); margin-bottom: 14px; }
    .info-row { display: flex; justify-content: space-between; font-size: 12px; padding: 5px 0; border-bottom: 1px solid var(--border); }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: var(--muted); }
    .info-val { font-weight: 500; text-align: right; max-width: 160px; }

    /* Appointment meta */
    .appt-date { font-size: 20px; font-weight: 600; margin-bottom: 2px; }
    .appt-time { font-size: 13px; color: var(--muted); margin-bottom: 14px; }

    /* Status badge */
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-scheduled { background: #E1F5EE; color: #085041; }
    .badge-completed  { background: #EAF3DE; color: #27500A; }
    .badge-missed     { background: #FCEBEB; color: #791F1F; }

    /* Triage panel */
    .triage-box { background: var(--amber-bg); border: 1px solid #F0D9A0; border-radius: 8px; padding: 14px 16px; font-size: 13px; color: var(--amber-text); line-height: 1.7; white-space: pre-wrap; }
    .triage-empty { font-size: 13px; color: var(--muted); font-style: italic; }

    /* History */
    .history-item { padding: 10px 0; border-bottom: 1px solid var(--border); }
    .history-item:last-child { border-bottom: none; }
    .history-date { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
    .history-diag { font-size: 12px; color: var(--muted); line-height: 1.5; }
    .history-empty { font-size: 13px; color: var(--muted); font-style: italic; }

    /* Main EMR form */
    .emr-header { margin-bottom: 22px; }
    .emr-header h1 { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
    .emr-header p { font-size: 13px; color: var(--muted); }

    /* Already completed banner */
    .completed-banner { background: var(--green-bg); border: 1px solid #9FE1CB; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
    .completed-banner strong { color: var(--green-text); font-size: 14px; display: block; margin-bottom: 6px; }
    .completed-banner p { font-size: 13px; color: #0F6E56; line-height: 1.6; }

    /* Alert */
    .alert { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); border-radius: 8px; padding: 12px 16px; font-size: 13px; margin-bottom: 18px; }

    /* Form fields */
    .field { margin-bottom: 20px; }
    .field label { display: block; font-size: 13px; font-weight: 500; color: var(--muted); margin-bottom: 7px; }
    .field label span.req { color: #E24B4A; margin-left: 2px; }
    .field textarea, .field input[type="text"] {
      width: 100%; padding: 11px 14px; border: 1px solid var(--border); border-radius: 8px;
      font-size: 14px; font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text);
      background: var(--white); outline: none; transition: border-color .2s, box-shadow .2s; line-height: 1.6;
    }
    .field textarea { min-height: 110px; resize: vertical; }
    .field textarea:focus, .field input[type="text"]:focus {
      border-color: var(--purple-mid); box-shadow: 0 0 0 3px rgba(83,74,183,0.1);
    }
    .field textarea:read-only, .field input[type="text"]:read-only {
      background: #F5F4FC; cursor: default;
    }

    /* Read-only diagnosis display */
    .diag-display { background: #F5F4FC; border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; font-size: 14px; line-height: 1.7; white-space: pre-wrap; color: var(--text); min-height: 80px; }

    /* Follow-up toggle */
    .follow-up-row { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: var(--purple-light); border-radius: 8px; }
    .follow-up-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--purple); cursor: pointer; }
    .follow-up-row label { font-size: 14px; font-weight: 500; color: var(--purple); cursor: pointer; }

    /* Status update */
    .status-row { display: flex; gap: 10px; margin-bottom: 20px; }
    .status-opt { flex: 1; }
    .status-opt input[type="radio"] { display: none; }
    .status-opt label {
      display: block; text-align: center; padding: 10px; border: 2px solid var(--border);
      border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer;
      transition: all .2s; color: var(--muted);
    }
    .status-opt input:checked + label { border-color: var(--purple); color: var(--purple); background: var(--purple-light); }
    .status-opt label:hover { border-color: var(--purple-mid); }

    /* Buttons */
    .btn-row { display: flex; gap: 12px; }
    .btn-primary {
      flex: 1; height: 48px; background: var(--purple); color: #fff; border: none;
      border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer;
      font-family: 'Plus Jakarta Sans', sans-serif; transition: background .2s; display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-primary:hover { background: var(--purple-mid); }
    .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
    .btn-back {
      height: 48px; padding: 0 20px; background: transparent; border: 1.5px solid var(--border);
      border-radius: 9px; font-size: 14px; font-weight: 500; cursor: pointer;
      font-family: 'Plus Jakarta Sans', sans-serif; color: var(--muted); text-decoration: none;
      display: flex; align-items: center;
    }
    .btn-back:hover { border-color: var(--text); color: var(--text); }

    .divider { border: none; border-top: 1px solid var(--border); margin: 22px 0; }
    .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 800px) { .page { grid-template-columns: 1fr; } }
  </style>
</head>
<body>

<nav>
  <span class="brand">LASU Health Center — Doctor Portal</span>
  <div class="nav-links">
    <a href="dashboard.php">Dashboard</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">

  <!-- ══ SIDEBAR ══════════════════════════════════════════ -->
  <aside class="sidebar">

    <!-- Patient info -->
    <div class="card">
      <div class="card-title">Patient</div>
      <div class="patient-avatar">
        <?= strtoupper(substr($appt['student_name'], 0, 1) . (strpos($appt['student_name'], ' ') !== false ? substr($appt['student_name'], strpos($appt['student_name'], ' ') + 1, 1) : '')) ?>
      </div>
      <div class="patient-name"><?= htmlspecialchars($appt['student_name']) ?></div>
      <div class="patient-matric"><?= htmlspecialchars($appt['matric_number']) ?></div>

      <div class="info-row"><span class="info-label">Email</span><span class="info-val"><?= htmlspecialchars($appt['student_email'] ?? '—') ?></span></div>
      <div class="info-row"><span class="info-label">Phone</span><span class="info-val"><?= htmlspecialchars($appt['student_phone'] ?? '—') ?></span></div>
      <div class="info-row"><span class="info-label">Department</span><span class="info-val"><?= htmlspecialchars($appt['department'] ?? '—') ?></span></div>
    </div>

    <!-- Appointment meta -->
    <div class="card">
      <div class="card-title">Appointment</div>
      <div class="appt-date"><?= date('d F Y', strtotime($appt['available_date'])) ?></div>
      <div class="appt-time"><?= substr($appt['slot_start'], 0, 5) ?> – <?= substr($appt['slot_end'], 0, 5) ?></div>
      <div class="info-row"><span class="info-label">Status</span>
        <span class="info-val"><span class="badge badge-<?= strtolower($appt['status']) ?>"><?= htmlspecialchars($appt['status']) ?></span></span>
      </div>
      <div class="info-row"><span class="info-label">Booked</span>
        <span class="info-val"><?= date('d M Y, H:i', strtotime($appt['booked_at'])) ?></span>
      </div>
      <div class="info-row"><span class="info-label">Ref</span>
        <span class="info-val">#<?= str_pad($appt['appointment_id'], 6, '0', STR_PAD_LEFT) ?></span>
      </div>
    </div>

    <!-- Triage symptoms -->
    <div class="card">
      <div class="card-title">Triage notes from patient</div>
      <?php if ($appt['triage_symptoms']): ?>
        <div class="triage-box"><?= htmlspecialchars($appt['triage_symptoms']) ?></div>
      <?php else: ?>
        <p class="triage-empty">No symptoms were provided during booking.</p>
      <?php endif; ?>
    </div>

    <!-- Visit history -->
    <div class="card">
      <div class="card-title">Previous visits</div>
      <?php if ($history): ?>
        <?php foreach ($history as $h): ?>
          <div class="history-item">
            <div class="history-date"><?= date('d M Y', strtotime($h['available_date'])) ?> — Dr. <?= htmlspecialchars($h['doctor_name']) ?></div>
            <?php if ($h['doctor_diagnosis']): ?>
              <div class="history-diag"><?= htmlspecialchars(substr($h['doctor_diagnosis'], 0, 100)) . (strlen($h['doctor_diagnosis']) > 100 ? '…' : '') ?></div>
            <?php else: ?>
              <div class="history-diag"><em>No diagnosis recorded</em></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="history-empty">No previous visits on record.</p>
      <?php endif; ?>
    </div>

  </aside>

  <!-- ══ MAIN EMR FORM ════════════════════════════════════ -->
  <main>
    <div class="emr-header">
      <h1>Consultation record</h1>
      <p>Complete the fields below after the consultation. All fields marked <span style="color:#E24B4A">*</span> are required.</p>
    </div>

    <?php if ($saved): ?>
      <div class="completed-banner">
        <strong>&#10003; Diagnosis saved successfully</strong>
        <p>The medical record has been updated. The appointment status has been marked as Completed.</p>
      </div>
    <?php endif; ?>

    <?php if ($saveErr): ?>
      <div class="alert">&#9888; <?= htmlspecialchars($saveErr) ?></div>
    <?php endif; ?>

    <?php if ($existingDiag && !$saved): ?>
      <div class="completed-banner">
        <strong>&#9432; Diagnosis already recorded</strong>
        <p>This consultation was completed on <?= date('d F Y \a\t H:i', strtotime($existingDiag['diagnosed_at'])) ?>. You can review the record below.</p>
      </div>
    <?php endif; ?>

    <form method="POST" action="save_diagnosis.php" id="emr-form">
      <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
      <input type="hidden" name="appointment_id"  value="<?= $appointmentId ?>"/>

      <!-- Symptoms reported during visit -->
      <div class="field">
        <label for="symptoms_reported">Symptoms reported during consultation <span class="req">*</span></label>
        <?php if ($existingDiag): ?>
          <div class="diag-display"><?= htmlspecialchars($existingDiag['symptoms_reported']) ?></div>
        <?php else: ?>
          <textarea id="symptoms_reported" name="symptoms_reported"
                    placeholder="Document what the patient described during the visit…"
                    required><?= htmlspecialchars($_POST['symptoms_reported'] ?? $appt['triage_symptoms'] ?? '') ?></textarea>
        <?php endif; ?>
      </div>

      <!-- Doctor's diagnosis -->
      <div class="field">
        <label for="doctor_diagnosis">Clinical diagnosis <span class="req">*</span></label>
        <?php if ($existingDiag): ?>
          <div class="diag-display"><?= htmlspecialchars($existingDiag['doctor_diagnosis']) ?></div>
        <?php else: ?>
          <textarea id="doctor_diagnosis" name="doctor_diagnosis"
                    placeholder="Enter your clinical findings and diagnosis…"
                    required><?= htmlspecialchars($_POST['doctor_diagnosis'] ?? '') ?></textarea>
        <?php endif; ?>
      </div>

      <!-- Prescription -->
      <div class="field">
        <label for="prescription_notes">Prescription &amp; treatment notes</label>
        <?php if ($existingDiag): ?>
          <div class="diag-display"><?= $existingDiag['prescription_notes'] ? htmlspecialchars($existingDiag['prescription_notes']) : '<em style="color:var(--muted)">None prescribed</em>' ?></div>
        <?php else: ?>
          <textarea id="prescription_notes" name="prescription_notes"
                    placeholder="Drugs, dosage, duration, and any special instructions…"><?= htmlspecialchars($_POST['prescription_notes'] ?? '') ?></textarea>
        <?php endif; ?>
      </div>

      <hr class="divider"/>

      <!-- Follow-up required -->
      <div class="field">
        <?php if ($existingDiag): ?>
          <div class="follow-up-row">
            <input type="checkbox" id="follow_up" <?= $existingDiag['follow_up_required'] ? 'checked' : '' ?> disabled/>
            <label for="follow_up">Follow-up visit required</label>
          </div>
        <?php else: ?>
          <div class="follow-up-row">
            <input type="checkbox" id="follow_up" name="follow_up_required" value="1"
                   <?= isset($_POST['follow_up_required']) ? 'checked' : '' ?>/>
            <label for="follow_up">Follow-up visit required</label>
          </div>
        <?php endif; ?>
      </div>

      <!-- Appointment status -->
      <?php if (!$existingDiag): ?>
      <div class="field">
        <label>Mark appointment as <span class="req">*</span></label>
        <div class="status-row">
          <div class="status-opt">
            <input type="radio" id="s-completed" name="appt_status" value="Completed" <?= !isset($_POST['appt_status']) || $_POST['appt_status'] === 'Completed' ? 'checked' : '' ?>>
            <label for="s-completed">Completed</label>
          </div>
          <div class="status-opt">
            <input type="radio" id="s-missed" name="appt_status" value="Missed" <?= (($_POST['appt_status'] ?? '') === 'Missed') ? 'checked' : '' ?>>
            <label for="s-missed">Missed</label>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="btn-row">
        <a href="dashboard.php" class="btn-back">Back to schedule</a>
        <?php if (!$existingDiag): ?>
          <button type="submit" class="btn-primary" id="btn-save">
            Save consultation record
          </button>
        <?php endif; ?>
      </div>

    </form>
  </main>

</div>

<script>
document.getElementById('emr-form')?.addEventListener('submit', function(e) {
  const btn = document.getElementById('btn-save');
  if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Saving…';
});
</script>

</body>
</html>
