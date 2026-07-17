<?php
// ============================================================
//  doctor/manage_slots.php
//  Doctors create and manage their own available time slots
// ============================================================
define('REQUIRED_ROLE', 'doctor');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo      = getDB();
$doctorId = $currentUser['id'];
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Create bulk slots ────────────────────────────────
        if ($action === 'create_slots') {
            $startDate = $_POST['start_date'] ?? '';
            $endDate   = $_POST['end_date']   ?? '';
            $startTime = $_POST['start_time'] ?? '';
            $endTime   = $_POST['end_time']   ?? '';
            $duration  = (int)($_POST['slot_duration'] ?? 30);
            $days      = $_POST['days'] ?? [];

            if (!$startDate || !$endDate || !$startTime || !$endTime || empty($days)) {
                $error = 'Please fill in all fields and select at least one day.';
            } elseif ($endDate < $startDate) {
                $error = 'End date cannot be before start date.';
            } elseif ($endTime <= $startTime) {
                $error = 'End time must be after start time.';
            } elseif ($startDate < date('Y-m-d')) {
                $error = 'Start date cannot be in the past.';
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
                                $stmt->rowCount() ? $created++ : $skipped++;
                                $slotStart->modify("+{$duration} minutes");
                                $slotEnd->modify("+{$duration} minutes");
                            }
                        }
                        $current->modify('+1 day');
                    }

                    $success = "{$created} slot(s) created successfully." .
                               ($skipped ? " {$skipped} duplicate(s) skipped." : '');

                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $error = 'Database error. Please try again.';
                }
            }
        }

        // ── Delete a single slot ─────────────────────────────
        if ($action === 'delete_slot') {
            $slotId = (int)($_POST['schedule_id'] ?? 0);
            if ($slotId) {
                $del = $pdo->prepare(
                    'DELETE FROM doctor_schedules
                      WHERE schedule_id = :sid
                        AND doctor_id   = :did
                        AND is_booked   = 0'
                );
                $del->execute([':sid' => $slotId, ':did' => $doctorId]);
                $success = $del->rowCount()
                    ? 'Slot removed successfully.'
                    : 'Slot could not be removed (it may already be booked).';
            }
        }

        // ── Delete all unbooked slots for a day ──────────────
        if ($action === 'delete_day') {
            $date = $_POST['date'] ?? '';
            if ($date) {
                $del = $pdo->prepare(
                    'DELETE FROM doctor_schedules
                      WHERE doctor_id     = :did
                        AND available_date = :date
                        AND is_booked      = 0'
                );
                $del->execute([':did' => $doctorId, ':date' => $date]);
                $success = $del->rowCount()
                    ? $del->rowCount() . ' unbooked slot(s) removed for ' . date('d M Y', strtotime($date)) . '.'
                    : 'No unbooked slots to remove on that date.';
            }
        }
    }
}

// ── Fetch this doctor's upcoming schedule ────────────────────
$viewDate  = $_GET['date'] ?? date('Y-m-d');
$schedules = $pdo->prepare(
    'SELECT available_date,
            COUNT(*)          AS total_slots,
            SUM(is_booked)    AS booked_slots,
            SUM(is_booked=0)  AS free_slots
       FROM doctor_schedules
      WHERE doctor_id     = :did
        AND available_date >= CURDATE()
      GROUP BY available_date
      ORDER BY available_date
      LIMIT 30'
);
$schedules->execute([':did' => $doctorId]);
$scheduleDays = $schedules->fetchAll();

// ── Slots for selected day ───────────────────────────────────
$daySlots = $pdo->prepare(
    'SELECT schedule_id, slot_start, slot_end, is_booked
       FROM doctor_schedules
      WHERE doctor_id     = :did
        AND available_date = :date
      ORDER BY slot_start'
);
$daySlots->execute([':did' => $doctorId, ':date' => $viewDate]);
$slots = $daySlots->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Slots — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--purple:#3C3489;--pm:#534AB7;--pl:#EEEDFE;--cream:#FAF8F3;--white:#fff;--border:#D4D2E8;--text:#0D0D1F;--mid:#3D3A60;--muted:#7A78A0;--erb:#FEF2F2;--erb2:#FECACA;--ert:#991B1B;--sb:#E1F5EE;--sb2:#9FE1CB;--st:#085041}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--purple);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:4px;align-items:center}
    nav a{color:#fff;text-decoration:none;font-size:13px;font-weight:500;padding:6px 12px;border-radius:7px;opacity:.8;transition:opacity .15s,background .15s}
    nav a:hover,nav a.active{opacity:1;background:rgba(255,255,255,.15)}
    .page{max-width:1100px;margin:0 auto;padding:32px 24px}
    h1{font-size:20px;font-weight:600;margin-bottom:4px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:24px}
    .layout{display:grid;grid-template-columns:360px 1fr;gap:24px;align-items:start}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:22px 26px;margin-bottom:20px}
    .card h2{font-size:15px;font-weight:600;margin-bottom:16px}
    .alert{border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:18px;line-height:1.5}
    .aerr{background:var(--erb);border:1px solid var(--erb2);color:var(--ert)}
    .aok{background:var(--sb);border:1px solid var(--sb2);color:var(--st)}
    .field{margin-bottom:14px}
    .field label{display:block;font-size:12px;font-weight:500;color:var(--muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em}
    .field input,.field select{width:100%;height:40px;padding:0 12px;border:1px solid var(--border);border-radius:7px;font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);background:var(--white);outline:none}
    .field input:focus,.field select:focus{border-color:var(--pm);box-shadow:0 0 0 3px rgba(83,74,183,.1)}
    .field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .day-checks{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:4px}
    .day-check{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer}
    .day-check input{width:15px;height:15px;accent-color:var(--purple);cursor:pointer}
    .btn-primary{width:100%;height:40px;background:var(--purple);color:#fff;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;margin-top:6px;transition:background .2s}
    .btn-primary:hover{background:var(--pm)}
    .day-list{display:flex;flex-direction:column;gap:8px}
    .day-row{background:var(--white);border:1px solid var(--border);border-radius:9px;padding:12px 16px;cursor:pointer;transition:border-color .2s;display:flex;justify-content:space-between;align-items:center}
    .day-row:hover,.day-row.active{border-color:var(--purple);background:var(--pl)}
    .day-label{font-weight:600;font-size:13px}
    .day-sub{font-size:11px;color:var(--muted);margin-top:2px}
    .day-badges{display:flex;gap:6px;align-items:center;flex-shrink:0}
    .badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600}
    .badge-free{background:var(--sb);color:var(--st)}
    .badge-booked{background:var(--pl);color:var(--purple)}
    .slot-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:6px}
    .slot-item{border:1px solid var(--border);border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between}
    .slot-item.booked{background:#F8F7FF;border-color:var(--purple);opacity:.7}
    .slot-time{font-size:13px;font-weight:600}
    .slot-status{font-size:11px;margin-top:2px}
    .slot-status.free{color:var(--st)}
    .slot-status.booked{color:var(--purple)}
    .btn-del{background:none;border:none;cursor:pointer;color:var(--ert);font-size:12px;font-weight:600;padding:3px 8px;border-radius:5px;transition:background .15s}
    .btn-del:hover{background:var(--erb)}
    .btn-del-day{height:28px;padding:0 12px;background:#FCEBEB;color:#791F1F;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer}
    .btn-del-day:hover{background:#F7C1C1}
    .day-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
    .empty{color:var(--muted);font-size:13px;font-style:italic;padding:8px 0}
    .no-day{color:var(--muted);font-size:13px;font-style:italic;padding:20px 0;text-align:center}
    .fill-bar{height:5px;border-radius:3px;background:var(--border);overflow:hidden;width:80px;display:inline-block;vertical-align:middle;margin-left:6px}
    .fill-fill{height:100%;background:var(--purple);border-radius:3px}
    @media(max-width:900px){.layout{grid-template-columns:1fr}}
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Doctor Portal</span>
  <div class="links">
    <a href="dashboard.php">Dashboard</a>
    <a href="manage_slots.php" class="active">My slots</a>
    <a href="change_password.php">Change password</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Manage my availability</h1>
  <p class="sub">Create time slots for students to book appointments with you.</p>

  <?php if ($success): ?>
    <div class="alert aok">&#10003; <?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert aerr">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="layout">

    <!-- ── Left: Create slots form ── -->
    <div>
      <div class="card">
        <h2>Add new slots</h2>
        <form method="POST" action="manage_slots.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
          <input type="hidden" name="action"     value="create_slots"/>

          <div class="field-row">
            <div class="field">
              <label>From date *</label>
              <input type="date" name="start_date" min="<?= date('Y-m-d') ?>"
                     value="<?= date('Y-m-d') ?>" required/>
            </div>
            <div class="field">
              <label>To date *</label>
              <input type="date" name="end_date" min="<?= date('Y-m-d') ?>"
                     value="<?= date('Y-m-d', strtotime('+6 days')) ?>" required/>
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
            <label>Slot duration</label>
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

          <button type="submit" class="btn-primary">Generate slots</button>
        </form>
      </div>

      <!-- Quick stats -->
      <div class="card">
        <h2>My upcoming availability</h2>
        <?php
          $totalFree   = array_sum(array_column($scheduleDays, 'free_slots'));
          $totalBooked = array_sum(array_column($scheduleDays, 'booked_slots'));
          $totalDays   = count($scheduleDays);
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px">
          <div style="text-align:center;background:var(--pl);border-radius:8px;padding:12px 8px">
            <div style="font-size:22px;font-weight:600;color:var(--purple)"><?= $totalDays ?></div>
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:2px">Days</div>
          </div>
          <div style="text-align:center;background:var(--sb);border-radius:8px;padding:12px 8px">
            <div style="font-size:22px;font-weight:600;color:var(--st)"><?= $totalFree ?></div>
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:2px">Free</div>
          </div>
          <div style="text-align:center;background:#F1EFE8;border-radius:8px;padding:12px 8px">
            <div style="font-size:22px;font-weight:600;color:#444"><?= $totalBooked ?></div>
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:2px">Booked</div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Right: Schedule viewer ── -->
    <div>
      <div class="card">
        <h2>Upcoming schedule</h2>

        <?php if ($scheduleDays): ?>
          <div class="day-list">
            <?php foreach ($scheduleDays as $day): ?>
              <?php
                $d    = $day['available_date'];
                $pct  = $day['total_slots'] > 0
                        ? round($day['booked_slots'] / $day['total_slots'] * 100)
                        : 0;
                $active = ($d === $viewDate) ? 'active' : '';
              ?>
              <a href="manage_slots.php?date=<?= $d ?>" class="day-row <?= $active ?>"
                 style="text-decoration:none;color:inherit">
                <div>
                  <div class="day-label"><?= date('D, d M Y', strtotime($d)) ?></div>
                  <div class="day-sub">
                    <?= $day['total_slots'] ?> slots total
                    <span class="fill-bar"><span class="fill-fill" style="width:<?= $pct ?>%"></span></span>
                    <?= $pct ?>% booked
                  </div>
                </div>
                <div class="day-badges">
                  <?php if ($day['free_slots'] > 0): ?>
                    <span class="badge badge-free"><?= $day['free_slots'] ?> free</span>
                  <?php endif; ?>
                  <?php if ($day['booked_slots'] > 0): ?>
                    <span class="badge badge-booked"><?= $day['booked_slots'] ?> booked</span>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="empty">No upcoming slots yet. Use the form to create some.</p>
        <?php endif; ?>
      </div>

      <!-- Slot detail for selected day -->
      <?php if ($slots): ?>
        <div class="card">
          <div class="day-header">
            <h2><?= date('l, d F Y', strtotime($viewDate)) ?></h2>
            <form method="POST" action="manage_slots.php"
                  onsubmit="return confirm('Remove all unbooked slots on this day?')">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
              <input type="hidden" name="action"     value="delete_day"/>
              <input type="hidden" name="date"       value="<?= htmlspecialchars($viewDate) ?>"/>
              <button type="submit" class="btn-del-day">Clear unbooked</button>
            </form>
          </div>

          <div class="slot-grid">
            <?php foreach ($slots as $s): ?>
              <div class="slot-item <?= $s['is_booked'] ? 'booked' : '' ?>">
                <div>
                  <div class="slot-time">
                    <?= substr($s['slot_start'],0,5) ?> – <?= substr($s['slot_end'],0,5) ?>
                  </div>
                  <div class="slot-status <?= $s['is_booked'] ? 'booked' : 'free' ?>">
                    <?= $s['is_booked'] ? 'Booked' : 'Available' ?>
                  </div>
                </div>
                <?php if (!$s['is_booked']): ?>
                  <form method="POST" action="manage_slots.php"
                        onsubmit="return confirm('Remove this slot?')">
                    <input type="hidden" name="csrf_token"   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
                    <input type="hidden" name="action"       value="delete_slot"/>
                    <input type="hidden" name="schedule_id"  value="<?= $s['schedule_id'] ?>"/>
                    <button type="submit" class="btn-del">✕</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php elseif ($scheduleDays): ?>
        <div class="card">
          <p class="no-day">Click a day on the left to see its slots.</p>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body>
</html>