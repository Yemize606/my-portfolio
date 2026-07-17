<?php
// ============================================================
//  student/book_appointment.php  (updated — instant email)
// ============================================================
define('REQUIRED_ROLE', 'student');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../notify/send_now.php'; // ← instant email

$pdo = getDB();
$bookingError   = '';
$bookingSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $bookingError = 'Security token mismatch. Please try again.';
    } else {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $doctorId   = (int)($_POST['doctor_id']   ?? 0);
        $symptoms   = trim($_POST['triage_symptoms'] ?? '');
        $studentId  = $currentUser['id'];

        if (!$scheduleId || !$doctorId) {
            $bookingError = 'Please select a valid time slot.';
        } else {
            try {
                $pdo->beginTransaction();

                $slotCheck = $pdo->prepare(
                    'SELECT schedule_id, is_booked FROM doctor_schedules
                      WHERE schedule_id = :sid AND doctor_id = :did FOR UPDATE'
                );
                $slotCheck->execute([':sid' => $scheduleId, ':did' => $doctorId]);
                $slot = $slotCheck->fetch();

                if (!$slot) throw new RuntimeException('That time slot no longer exists.');
                if ($slot['is_booked']) throw new RuntimeException('Sorry, that slot was just taken. Please choose another.');

                $dupCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM appointments a
                       JOIN doctor_schedules ds ON a.schedule_id = ds.schedule_id
                      WHERE a.student_id = :sid
                        AND ds.available_date = (SELECT available_date FROM doctor_schedules WHERE schedule_id = :schid)
                        AND a.status = \'Scheduled\''
                );
                $dupCheck->execute([':sid' => $studentId, ':schid' => $scheduleId]);
                if ($dupCheck->fetchColumn() > 0) throw new RuntimeException('You already have an appointment on that day.');

                $insert = $pdo->prepare(
                    'INSERT INTO appointments (student_id, doctor_id, schedule_id, status, triage_symptoms)
                     VALUES (:student, :doctor, :schedule, \'Scheduled\', :symptoms)'
                );
                $insert->execute([
                    ':student'  => $studentId,
                    ':doctor'   => $doctorId,
                    ':schedule' => $scheduleId,
                    ':symptoms' => $symptoms ?: null,
                ]);
                $appointmentId = (int)$pdo->lastInsertId();

                $pdo->prepare('UPDATE doctor_schedules SET is_booked = 1 WHERE schedule_id = :sid')
                    ->execute([':sid' => $scheduleId]);

                $pdo->commit();

                // ── Send confirmation email instantly ────────
                sendNotificationNow($pdo, $appointmentId, 'booking_confirmation', $studentId);

                header('Location: booking_confirmed.php?id=' . $appointmentId);
                exit;

            } catch (RuntimeException $e) {
                $pdo->rollBack();
                $bookingError = $e->getMessage();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Booking error: ' . $e->getMessage());
                $bookingError = 'A database error occurred. Please try again.';
            }
        }
    }
}

// Fetch departments
$departments = $pdo->query(
    'SELECT d.department_id, d.name, COUNT(u.user_id) AS doctor_count
       FROM departments d
       JOIN users u ON u.department_id = d.department_id AND u.role = \'doctor\' AND u.is_active = 1
      GROUP BY d.department_id, d.name ORDER BY d.name'
)->fetchAll();

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Book Appointment — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--green:#0D3B2E;--gm:#1A5C44;--gl:#2E7D5A;--cream:#FAF8F3;--white:#fff;--border:#D4E4DC;--text:#0D1F19;--mid:#3D5A50;--muted:#7A9589;--erb:#FEF2F2;--erb2:#FECACA;--ert:#991B1B}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--green);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:4px}
    nav a{color:#fff;text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:7px;opacity:.8;transition:opacity .15s,background .15s}
    nav a:hover,nav a.active{opacity:1;background:rgba(255,255,255,.15)}
    .page{max-width:720px;margin:0 auto;padding:40px 24px}
    h1{font-size:22px;font-weight:600;margin-bottom:6px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:32px}
    .steps{display:flex;align-items:center;margin-bottom:36px}
    .step{display:flex;align-items:center;gap:8px}
    .step-circle{width:28px;height:28px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:var(--muted);background:var(--white);transition:all .25s}
    .step.active .step-circle{border-color:var(--green);background:var(--green);color:#fff}
    .step.done .step-circle{border-color:var(--gl);background:var(--gl);color:#fff}
    .step-label{font-size:12px;color:var(--muted);font-weight:500}
    .step.active .step-label{color:var(--green)}
    .step-line{flex:1;height:1px;background:var(--border);margin:0 8px}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:28px 32px;margin-bottom:20px}
    .card h2{font-size:16px;font-weight:600;margin-bottom:20px}
    .alert{background:var(--erb);border:1px solid var(--erb2);color:var(--ert);border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:20px}
    .field{margin-bottom:20px}
    .field label{display:block;font-size:13px;font-weight:500;color:var(--mid);margin-bottom:7px}
    .field select{width:100%;height:46px;padding:0 14px;border:1px solid var(--border);border-radius:8px;font-size:14px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);background:var(--white);outline:none;transition:border-color .2s}
    .field select:focus{border-color:var(--gl);box-shadow:0 0 0 3px rgba(46,125,90,.12)}
    .field textarea{width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:8px;font-size:14px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);background:var(--white);outline:none;min-height:110px;resize:vertical;line-height:1.6}
    .field textarea:focus{border-color:var(--gl);box-shadow:0 0 0 3px rgba(46,125,90,.12)}
    .doctor-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .doctor-card{border:2px solid var(--border);border-radius:10px;padding:14px 16px;cursor:pointer;transition:border-color .2s,background .2s}
    .doctor-card:hover{border-color:var(--gl);background:#f0f8f4}
    .doctor-card.selected{border-color:var(--green);background:#E1F5EE}
    .doctor-card input[type="radio"]{display:none}
    .doctor-name{font-weight:600;font-size:14px;margin-bottom:2px}
    .doctor-dept{font-size:12px;color:var(--muted)}
    .slot-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
    .slot-btn{padding:10px 0;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:500;text-align:center;cursor:pointer;background:var(--white);color:var(--text);transition:all .2s}
    .slot-btn:hover:not(:disabled){border-color:var(--gl);background:#f0f8f4}
    .slot-btn.selected{border-color:var(--green);background:var(--green);color:#fff}
    .slot-btn:disabled{opacity:.4;cursor:not-allowed;text-decoration:line-through}
    .slot-placeholder{color:var(--muted);font-size:13px;padding:8px 0}
    .summary{background:#F0F8F4;border:1px solid #C2E0D0;border-radius:10px;padding:16px 20px;margin-bottom:20px}
    .summary-row{display:flex;justify-content:space-between;font-size:13px;padding:4px 0}
    .summary-label{color:var(--muted)}
    .summary-value{font-weight:600}
    .btn-row{display:flex;gap:12px;margin-top:8px}
    .btn-primary{flex:1;height:46px;background:var(--green);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:background .2s;display:flex;align-items:center;justify-content:center;gap:8px}
    .btn-primary:hover{background:var(--gm)}
    .btn-primary:disabled{opacity:.5;cursor:not-allowed}
    .btn-back{height:46px;padding:0 20px;background:transparent;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-weight:500;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;color:var(--muted)}
    .btn-back:hover{border-color:var(--text);color:var(--text)}
    .hidden{display:none!important}
    .spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center</span>
  <div class="links">
    <a href="dashboard.php">Dashboard</a>
    <a href="book_appointment.php" class="active">Book appointment</a>
    <a href="change_password.php">Change password</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Book an appointment</h1>
  <p class="sub">Choose a department, doctor, and time that works for you.</p>

  <?php if ($bookingError): ?>
    <div class="alert">&#9888; <?= htmlspecialchars($bookingError) ?></div>
  <?php endif; ?>

  <div class="steps" id="progress">
    <div class="step active" id="prog-1"><div class="step-circle">1</div><span class="step-label">Department & doctor</span></div>
    <div class="step-line"></div>
    <div class="step" id="prog-2"><div class="step-circle">2</div><span class="step-label">Date & time</span></div>
    <div class="step-line"></div>
    <div class="step" id="prog-3"><div class="step-circle">3</div><span class="step-label">Symptoms</span></div>
    <div class="step-line"></div>
    <div class="step" id="prog-4"><div class="step-circle">4</div><span class="step-label">Confirm</span></div>
  </div>

  <!-- Step 1 -->
  <div id="step-1">
    <div class="card">
      <h2>Choose a department</h2>
      <div class="field">
        <label>Department</label>
        <select id="dept-select" onchange="loadDoctors(this.value)">
          <option value="">— Select a department —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['name']) ?> (<?= $d['doctor_count'] ?> doctor<?= $d['doctor_count'] != 1 ? 's' : '' ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="card hidden" id="doctor-card">
      <h2>Choose a doctor</h2>
      <div class="doctor-grid" id="doctor-grid"></div>
    </div>
    <div class="btn-row">
      <a href="dashboard.php" class="btn-back" style="display:flex;align-items:center;justify-content:center;text-decoration:none">Cancel</a>
      <button class="btn-primary" onclick="goToStep(2)" id="btn-step1" disabled>Next: Pick a time</button>
    </div>
  </div>

  <!-- Step 2 -->
  <div id="step-2" class="hidden">
    <div class="card">
      <h2>Choose a date</h2>
      <div class="field">
        <label>Appointment date</label>
        <input type="date" id="appt-date" style="width:100%;height:46px;padding:0 14px;border:1px solid var(--border);border-radius:8px;font-size:14px;font-family:'Plus Jakarta Sans',sans-serif;outline:none"
               min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
               max="<?= date('Y-m-d', strtotime('+30 days')) ?>"
               onchange="loadSlots()"/>
      </div>
      <h2 style="margin-top:8px">Available time slots</h2>
      <p class="slot-placeholder" id="slot-placeholder">Select a date above to see available slots.</p>
      <div class="slot-grid hidden" id="slot-grid"></div>
    </div>
    <div class="btn-row">
      <button class="btn-back" onclick="goToStep(1)">Back</button>
      <button class="btn-primary" onclick="goToStep(3)" id="btn-step2" disabled>Next: Describe symptoms</button>
    </div>
  </div>

  <!-- Step 3 -->
  <div id="step-3" class="hidden">
    <div class="card">
      <h2>Describe your symptoms <span style="font-weight:400;color:var(--muted);font-size:13px">(optional)</span></h2>
      <div class="field">
        <label>What are you experiencing?</label>
        <textarea id="triage" placeholder="e.g. I have had a headache and fever for the past two days..."></textarea>
      </div>
    </div>
    <div class="btn-row">
      <button class="btn-back" onclick="goToStep(2)">Back</button>
      <button class="btn-primary" onclick="goToStep(4)">Next: Review booking</button>
    </div>
  </div>

  <!-- Step 4 -->
  <div id="step-4" class="hidden">
    <div class="card">
      <h2>Review your booking</h2>
      <div class="summary">
        <div class="summary-row"><span class="summary-label">Department</span><span class="summary-value" id="sum-dept">—</span></div>
        <div class="summary-row"><span class="summary-label">Doctor</span><span class="summary-value" id="sum-doctor">—</span></div>
        <div class="summary-row"><span class="summary-label">Date</span><span class="summary-value" id="sum-date">—</span></div>
        <div class="summary-row"><span class="summary-label">Time</span><span class="summary-value" id="sum-time">—</span></div>
        <div class="summary-row"><span class="summary-label">Symptoms</span><span class="summary-value" id="sum-symptoms">None</span></div>
      </div>
      <p style="font-size:13px;color:var(--muted);line-height:1.6">
        A booking confirmation email will be sent to your registered email address immediately after confirming.
      </p>
    </div>
    <form method="POST" action="book_appointment.php" id="confirm-form">
      <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($_SESSION['csrf_token'] ??= bin2hex(random_bytes(32))) ?>"/>
      <input type="hidden" name="schedule_id"     id="f-schedule-id"/>
      <input type="hidden" name="doctor_id"       id="f-doctor-id"/>
      <input type="hidden" name="triage_symptoms" id="f-symptoms"/>
      <div class="btn-row">
        <button type="button" class="btn-back" onclick="goToStep(3)">Back</button>
        <button type="submit" class="btn-primary" id="btn-confirm">Confirm appointment</button>
      </div>
    </form>
  </div>
</div>

<script>
const state = { deptId:null, deptName:'', doctorId:null, doctorName:'', scheduleId:null, date:'', timeLabel:'' };

function goToStep(n) {
  [1,2,3,4].forEach(i => {
    document.getElementById('step-'+i).classList.toggle('hidden', i!==n);
    const p = document.getElementById('prog-'+i);
    p.classList.remove('active','done');
    if(i===n) p.classList.add('active');
    else if(i<n) p.classList.add('done');
  });
  if(n===4) populateSummary();
}

function loadDoctors(deptId) {
  state.deptId = deptId;
  state.deptName = document.getElementById('dept-select').options[document.getElementById('dept-select').selectedIndex].text.replace(/ \(\d+ doctors?\)/,'');
  state.doctorId = null;
  document.getElementById('btn-step1').disabled = true;
  const card = document.getElementById('doctor-card');
  const grid = document.getElementById('doctor-grid');
  if(!deptId){card.classList.add('hidden');return;}
  grid.innerHTML='<p class="slot-placeholder">Loading doctors…</p>';
  card.classList.remove('hidden');
  fetch('fetch_slots.php?action=doctors&dept_id='+encodeURIComponent(deptId))
    .then(r=>r.json()).then(docs=>{
      if(!docs.length){grid.innerHTML='<p class="slot-placeholder">No doctors available.</p>';return;}
      grid.innerHTML=docs.map(d=>`<label class="doctor-card" id="dc-${d.user_id}"><input type="radio" name="doctor" value="${d.user_id}" onchange="selectDoctor(${d.user_id},'${esc(d.full_name)}')"/><div class="doctor-name">Dr. ${esc(d.full_name)}</div><div class="doctor-dept">${esc(d.department)}</div></label>`).join('');
    }).catch(()=>{grid.innerHTML='<p class="slot-placeholder">Failed to load. Please refresh.</p>';});
}

function selectDoctor(id,name){
  state.doctorId=id; state.doctorName=name;
  document.querySelectorAll('.doctor-card').forEach(c=>c.classList.remove('selected'));
  document.getElementById('dc-'+id)?.classList.add('selected');
  document.getElementById('btn-step1').disabled=false;
}

function loadSlots(){
  const date=document.getElementById('appt-date').value;
  state.date=date; state.scheduleId=null;
  document.getElementById('btn-step2').disabled=true;
  const ph=document.getElementById('slot-placeholder'), gr=document.getElementById('slot-grid');
  if(!date)return;
  ph.textContent='Loading slots…'; ph.classList.remove('hidden'); gr.classList.add('hidden'); gr.innerHTML='';
  fetch(`fetch_slots.php?action=slots&doctor_id=${state.doctorId}&date=${encodeURIComponent(date)}`)
    .then(r=>r.json()).then(slots=>{
      if(!slots.length){ph.textContent='No available slots on this date.';return;}
      ph.classList.add('hidden'); gr.classList.remove('hidden');
      gr.innerHTML=slots.map(s=>`<button type="button" class="slot-btn ${s.is_booked?'disabled':''}" ${s.is_booked?'disabled':''} onclick="selectSlot(${s.schedule_id},'${esc(s.slot_start)}',this)">${esc(s.slot_start.slice(0,5))}</button>`).join('');
    }).catch(()=>{ph.textContent='Failed to load slots.';});
}

function selectSlot(sid,time,btn){
  state.scheduleId=sid; state.timeLabel=time;
  document.querySelectorAll('.slot-btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('btn-step2').disabled=false;
}

function populateSummary(){
  const d=new Date(state.date+'T00:00:00');
  const ds=d.toLocaleDateString('en-GB',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  const sym=document.getElementById('triage').value.trim();
  document.getElementById('sum-dept').textContent=state.deptName;
  document.getElementById('sum-doctor').textContent='Dr. '+state.doctorName;
  document.getElementById('sum-date').textContent=ds;
  document.getElementById('sum-time').textContent=state.timeLabel.slice(0,5);
  document.getElementById('sum-symptoms').textContent=sym||'None provided';
  document.getElementById('f-schedule-id').value=state.scheduleId;
  document.getElementById('f-doctor-id').value=state.doctorId;
  document.getElementById('f-symptoms').value=sym;
}

document.getElementById('confirm-form').addEventListener('submit',()=>{
  const btn=document.getElementById('btn-confirm');
  btn.disabled=true;
  btn.innerHTML='<span class="spinner"></span> Confirming…';
});

function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
</body>
</html>
