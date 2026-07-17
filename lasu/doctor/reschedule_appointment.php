<?php
// ============================================================
//  doctor/reschedule_appointment.php — sends email instantly
// ============================================================
define('REQUIRED_ROLE', 'doctor');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../notify/send_now.php';

$pdo           = getDB();
$doctorId      = $currentUser['id'];
$appointmentId = (int)($_GET['id'] ?? $_POST['appointment_id'] ?? 0);
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$success = '';
$error   = '';

if (!$appointmentId) { header('Location: dashboard.php'); exit; }

// Fetch appointment
$apptStmt = $pdo->prepare(
    'SELECT a.appointment_id, a.status, a.student_id, a.schedule_id,
            a.triage_symptoms, a.reschedule_status,
            ds.available_date, ds.slot_start, ds.slot_end,
            s.full_name AS student_name, s.matric_number, s.contact_email AS student_email
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id=ds.schedule_id
       JOIN users            s  ON a.student_id =s.user_id
      WHERE a.appointment_id=:aid AND a.doctor_id=:did'
);
$apptStmt->execute([':aid' => $appointmentId, ':did' => $doctorId]);
$appt = $apptStmt->fetch();

if (!$appt || $appt['status'] !== 'Scheduled') { header('Location: dashboard.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $newScheduleId = (int)($_POST['new_schedule_id'] ?? 0);
        $note          = trim($_POST['reschedule_note'] ?? '');

        if (!$newScheduleId) {
            $error = 'Please select a new time slot.';
        } elseif ($newScheduleId === (int)$appt['schedule_id']) {
            $error = 'Please select a different slot from the current one.';
        } else {
            try {
                $pdo->beginTransaction();

                $slotCheck = $pdo->prepare(
                    'SELECT schedule_id, is_booked FROM doctor_schedules
                      WHERE schedule_id=:sid AND doctor_id=:did FOR UPDATE'
                );
                $slotCheck->execute([':sid' => $newScheduleId, ':did' => $doctorId]);
                $newSlot = $slotCheck->fetch();

                if (!$newSlot) throw new RuntimeException('That slot does not exist.');
                if ($newSlot['is_booked']) throw new RuntimeException('That slot is already booked. Please choose another.');

                // Free any previously proposed slot
                if ($appt['reschedule_status'] === 'proposed') {
                    $prev = $pdo->prepare('SELECT reschedule_proposed_schedule_id FROM appointments WHERE appointment_id=:aid');
                    $prev->execute([':aid' => $appointmentId]);
                    $prevId = $prev->fetchColumn();
                    if ($prevId) {
                        $pdo->prepare('UPDATE doctor_schedules SET is_booked=0 WHERE schedule_id=:sid')
                            ->execute([':sid' => $prevId]);
                    }
                }

                // Reserve the new proposed slot
                $pdo->prepare('UPDATE doctor_schedules SET is_booked=1 WHERE schedule_id=:sid')
                    ->execute([':sid' => $newScheduleId]);

                $pdo->prepare(
                    'UPDATE appointments
                        SET reschedule_proposed_schedule_id=:nsid,
                            reschedule_status=\'proposed\',
                            doctor_cancel_reason=:note,
                            updated_at=NOW()
                      WHERE appointment_id=:aid'
                )->execute([':nsid' => $newScheduleId, ':note' => $note ?: null, ':aid' => $appointmentId]);

                $pdo->commit();

                // Send reschedule email to student instantly
                sendNotificationNow($pdo, $appointmentId, 'reschedule', $appt['student_id']);

                $success = 'Reschedule proposal sent to ' . $appt['student_name'] . ' by email.';
                $apptStmt->execute([':aid' => $appointmentId, ':did' => $doctorId]);
                $appt = $apptStmt->fetch();

            } catch (RuntimeException $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Reschedule: ' . $e->getMessage());
                $error = 'A database error occurred. Please try again.';
            }
        }
    }
}

// Available slots
$slotsStmt = $pdo->prepare(
    'SELECT schedule_id, available_date, slot_start, slot_end
       FROM doctor_schedules
      WHERE doctor_id=:did AND is_booked=0
        AND available_date > CURDATE()
        AND schedule_id != :csid
      ORDER BY available_date, slot_start LIMIT 60'
);
$slotsStmt->execute([':did' => $doctorId, ':csid' => $appt['schedule_id']]);
$availableSlots = $slotsStmt->fetchAll();

$slotsByDate = [];
foreach ($availableSlots as $slot) {
    $slotsByDate[$slot['available_date']][] = $slot;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reschedule Appointment — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--purple:#3C3489;--pm:#534AB7;--pl:#EEEDFE;--cream:#FAF8F3;--white:#fff;--border:#D4D2E8;--text:#0D0D1F;--mid:#3D3A60;--muted:#7A78A0;--sb:#E1F5EE;--sb2:#9FE1CB;--st:#085041;--erb:#FEF2F2;--erb2:#FECACA;--ert:#991B1B;--amb:#FFF8EC;--amb2:#F0D9A0;--amt:#7A5C1E}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--purple);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:4px}
    nav a{color:#fff;text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:7px;opacity:.8}
    nav a:hover{opacity:1;background:rgba(255,255,255,.15)}
    .page{max-width:900px;margin:0 auto;padding:32px 24px}
    h1{font-size:20px;font-weight:600;margin-bottom:4px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:24px}
    .alert{border-radius:9px;padding:12px 16px;font-size:13px;margin-bottom:20px;line-height:1.5}
    .aok{background:var(--sb);border:1px solid var(--sb2);color:var(--st)}
    .aerr{background:var(--erb);border:1px solid var(--erb2);color:var(--ert)}
    .aamb{background:var(--amb);border:1px solid var(--amb2);color:var(--amt)}
    .layout{display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px 24px;margin-bottom:16px}
    .card h2{font-size:14px;font-weight:600;margin-bottom:14px;color:var(--mid)}
    .info-row{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-bottom:1px solid var(--border)}
    .info-row:last-child{border-bottom:none}
    .info-label{color:var(--muted)}
    .info-val{font-weight:500}
    .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
    .badge-proposed{background:var(--amb);color:var(--amt)}
    .badge-scheduled{background:var(--sb);color:var(--st)}
    .field{margin-bottom:16px}
    .field label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
    .field textarea{width:100%;min-height:80px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);resize:vertical;outline:none;line-height:1.6}
    .field textarea:focus{border-color:var(--pm);box-shadow:0 0 0 3px rgba(83,74,183,.1)}
    .date-group{margin-bottom:16px}
    .date-label{font-size:12px;font-weight:600;color:var(--mid);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--border)}
    .slot-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
    .slot-opt input[type="radio"]{display:none}
    .slot-opt label{display:block;text-align:center;padding:9px 6px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;color:var(--text)}
    .slot-opt input:checked+label{border-color:var(--purple);background:var(--pl);color:var(--purple)}
    .slot-opt label:hover{border-color:var(--pm);background:#F5F4FC}
    .btn-row{display:flex;gap:12px;margin-top:8px}
    .btn-primary{flex:1;height:46px;background:var(--purple);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:background .2s}
    .btn-primary:hover{background:var(--pm)}
    .btn-back{height:46px;padding:0 20px;background:transparent;border:1.5px solid var(--border);border-radius:9px;font-size:14px;color:var(--muted);text-decoration:none;display:flex;align-items:center;cursor:pointer}
    .btn-back:hover{border-color:var(--text);color:var(--text)}
    .empty{color:var(--muted);font-size:13px;font-style:italic;padding:8px 0}
    @media(max-width:700px){.layout{grid-template-columns:1fr}}
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Doctor Portal</span>
  <div class="links">
    <a href="dashboard.php">Dashboard</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Reschedule appointment</h1>
  <p class="sub">Propose a new time for this appointment. The student will receive an email immediately.</p>

  <?php if ($success): ?>
    <div class="alert aok">&#10003; <?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert aerr">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($appt['reschedule_status'] === 'proposed' && !$success): ?>
    <div class="alert aamb">&#9432; A reschedule has already been proposed. The student has not responded yet. You can update it below.</div>
  <?php endif; ?>

  <div class="layout">
    <div>
      <div class="card">
        <h2>Current appointment</h2>
        <div class="info-row"><span class="info-label">Patient</span><span class="info-val"><?= htmlspecialchars($appt['student_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Matric</span><span class="info-val"><?= htmlspecialchars($appt['matric_number']) ?></span></div>
        <div class="info-row"><span class="info-label">Date</span><span class="info-val"><?= date('d M Y', strtotime($appt['available_date'])) ?></span></div>
        <div class="info-row"><span class="info-label">Time</span><span class="info-val"><?= substr($appt['slot_start'],0,5) ?> – <?= substr($appt['slot_end'],0,5) ?></span></div>
        <div class="info-row"><span class="info-label">Status</span>
          <span class="info-val">
            <span class="badge badge-<?= $appt['reschedule_status']==='proposed' ? 'proposed' : 'scheduled' ?>">
              <?= $appt['reschedule_status']==='proposed' ? 'Reschedule sent' : 'Scheduled' ?>
            </span>
          </span>
        </div>
        <?php if ($appt['triage_symptoms']): ?>
          <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">Triage notes</div>
            <div style="font-size:13px;color:var(--mid);line-height:1.6"><?= htmlspecialchars($appt['triage_symptoms']) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="card">
        <h2>Choose a new time slot</h2>
        <?php if ($slotsByDate): ?>
          <form method="POST" action="reschedule_appointment.php">
            <input type="hidden" name="csrf_token"     value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
            <input type="hidden" name="appointment_id" value="<?= $appointmentId ?>"/>
            <input type="hidden" name="new_schedule_id" id="sel-slot" value=""/>

            <div class="field">
              <label>Note to student (optional)</label>
              <textarea name="reschedule_note" placeholder="e.g. Doctor unavailable due to emergency. Apologies for the inconvenience."></textarea>
            </div>

            <div style="font-size:12px;font-weight:500;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-bottom:12px">Available slots</div>

            <?php foreach ($slotsByDate as $date => $slots): ?>
              <div class="date-group">
                <div class="date-label"><?= date('l, d F Y', strtotime($date)) ?></div>
                <div class="slot-grid">
                  <?php foreach ($slots as $slot): ?>
                    <div class="slot-opt">
                      <input type="radio" name="slot_radio" id="s-<?= $slot['schedule_id'] ?>"
                             value="<?= $slot['schedule_id'] ?>"
                             onchange="document.getElementById('sel-slot').value=this.value"/>
                      <label for="s-<?= $slot['schedule_id'] ?>"><?= substr($slot['slot_start'],0,5) ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>

            <div class="btn-row">
              <a href="dashboard.php" class="btn-back">Cancel</a>
              <button type="submit" class="btn-primary"
                onclick="return document.getElementById('sel-slot').value||(alert('Please select a new time slot.')&&false)">
                Propose this time & notify student
              </button>
            </div>
          </form>
        <?php else: ?>
          <p class="empty">No free slots available. Go to <a href="manage_slots.php" style="color:var(--pm)">My Slots</a> to create some first.</p>
          <a href="dashboard.php" class="btn-back" style="margin-top:16px">Back to dashboard</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
