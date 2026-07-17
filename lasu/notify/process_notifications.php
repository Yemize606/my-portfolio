<?php
// ============================================================
//  notify/process_notifications.php
//
//  This is the CRON SCRIPT. Run it via Windows Task Scheduler
//  every hour. It reads pending rows from the notifications
//  table, sends the email/SMS, and updates the status.
//
//  Command to run (Task Scheduler action):
//    C:\xampp\php\php.exe C:\xampp\htdocs\lasu\notify\process_notifications.php
//
//  ALSO handles: automatic 24-hour reminder creation.
//  It scans for appointments happening tomorrow that have not
//  yet had a reminder queued, and inserts them automatically.
// ============================================================

// CLI-only guard — prevent accidental browser access
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/templates.php';

$pdo   = getDB();
$log   = [];
$sent  = 0;
$failed = 0;

echo '[' . date('Y-m-d H:i:s') . '] Notification processor started.' . PHP_EOL;

// ── STEP 1: Auto-queue 24-hour reminders ────────────────────
// Find all Scheduled appointments for tomorrow that don't yet
// have a reminder_24h notification queued or sent.
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$autoReminders = $pdo->prepare(
    'SELECT a.appointment_id, a.student_id
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id = ds.schedule_id
      WHERE ds.available_date = :tomorrow
        AND a.status          = \'Scheduled\'
        AND a.appointment_id NOT IN (
              SELECT appointment_id FROM notifications
               WHERE type IN (\'reminder_24h\')
                 AND appointment_id IS NOT NULL
            )'
);
$autoReminders->execute([':tomorrow' => $tomorrow]);
$toRemind = $autoReminders->fetchAll();

foreach ($toRemind as $r) {
    // Queue email reminder
    $pdo->prepare(
        'INSERT INTO notifications (user_id, appointment_id, type, channel, status)
         VALUES (:uid, :aid, \'reminder_24h\', \'email\', \'pending\')'
    )->execute([':uid' => $r['student_id'], ':aid' => $r['appointment_id']]);

    // Queue SMS reminder if student has a phone number
    $phone = $pdo->prepare('SELECT contact_phone FROM users WHERE user_id = :uid');
    $phone->execute([':uid' => $r['student_id']]);
    $phoneNum = $phone->fetchColumn();
    if ($phoneNum) {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, appointment_id, type, channel, status)
             VALUES (:uid, :aid, \'reminder_24h\', \'sms\', \'pending\')'
        )->execute([':uid' => $r['student_id'], ':aid' => $r['appointment_id']]);
    }

    echo "  Queued 24h reminder for appointment #{$r['appointment_id']}" . PHP_EOL;
}

// ── STEP 2: Auto-queue 3-day follow-ups ─────────────────────
// Find Completed appointments from exactly 3 days ago that have
// follow_up_required = 1 and no follow_up_3d notification yet.
$threeDaysAgo = date('Y-m-d', strtotime('-3 days'));

$autoFollowUps = $pdo->prepare(
    'SELECT a.appointment_id, a.student_id
       FROM appointments a
       JOIN doctor_schedules ds ON a.schedule_id  = ds.schedule_id
       JOIN diagnoses        d  ON d.appointment_id = a.appointment_id
      WHERE ds.available_date  = :three_ago
        AND a.status           = \'Completed\'
        AND d.follow_up_required = 1
        AND a.appointment_id NOT IN (
              SELECT appointment_id FROM notifications
               WHERE type = \'follow_up_3d\'
                 AND appointment_id IS NOT NULL
            )'
);
$autoFollowUps->execute([':three_ago' => $threeDaysAgo]);
$toFollowUp = $autoFollowUps->fetchAll();

foreach ($toFollowUp as $f) {
    $pdo->prepare(
        'INSERT INTO notifications (user_id, appointment_id, type, channel, status)
         VALUES (:uid, :aid, \'follow_up_3d\', \'email\', \'pending\')'
    )->execute([':uid' => $f['student_id'], ':aid' => $f['appointment_id']]);
    echo "  Queued follow-up for appointment #{$f['appointment_id']}" . PHP_EOL;
}

// ── STEP 3: Process all pending notifications ────────────────
// Fetch in batches of 50 to avoid memory issues
$pending = $pdo->query(
    'SELECT n.notification_id, n.user_id, n.appointment_id,
            n.type, n.channel,
            u.full_name, u.contact_email, u.contact_phone
       FROM notifications n
       JOIN users u ON n.user_id = u.user_id
      WHERE n.status = \'pending\'
      ORDER BY n.notification_id ASC
      LIMIT 50'
)->fetchAll();

echo '  Found ' . count($pending) . ' pending notification(s).' . PHP_EOL;

foreach ($pending as $notif) {

    $notifId     = $notif['notification_id'];
    $type        = $notif['type'];
    $channel     = $notif['channel'];
    $appointId   = $notif['appointment_id'];

    // Build the data array for the template
    $data = [
        'student_name'   => $notif['full_name'],
        'appointment_id' => $appointId ?? 0,
        'doctor_name'    => '',
        'department'     => '',
        'date'           => '',
        'time'           => '',
    ];

    // Pull appointment details if we have an appointment_id
    if ($appointId) {
        $apptStmt = $pdo->prepare(
            'SELECT ds.available_date, ds.slot_start,
                    u.full_name AS doctor_name, dept.name AS department
               FROM appointments a
               JOIN doctor_schedules ds   ON a.schedule_id    = ds.schedule_id
               JOIN users            u    ON a.doctor_id      = u.user_id
               LEFT JOIN departments dept ON u.department_id  = dept.department_id
              WHERE a.appointment_id = :aid'
        );
        $apptStmt->execute([':aid' => $appointId]);
        $apptRow = $apptStmt->fetch();

        if ($apptRow) {
            $data['doctor_name'] = $apptRow['doctor_name'];
            $data['department']  = $apptRow['department'] ?? '';
            $data['date']        = date('l, d F Y', strtotime($apptRow['available_date']));
            $data['time']        = substr($apptRow['slot_start'], 0, 5);
        }
    }

    // ── Send via the correct channel ────────────────────────
    $success = false;

    if ($channel === 'email') {

        if (empty($notif['contact_email'])) {
            echo "  SKIP #{$notifId} — no email address for user {$notif['user_id']}" . PHP_EOL;
            markNotification($pdo, $notifId, 'failed');
            $failed++;
            continue;
        }

        [$subject, $html, $plain] = getEmailTemplate($type, $data);

        if (!$subject) {
            echo "  SKIP #{$notifId} — unknown type '{$type}'" . PHP_EOL;
            markNotification($pdo, $notifId, 'failed');
            $failed++;
            continue;
        }

        $success = sendEmail(
            $notif['contact_email'],
            $notif['full_name'],
            $subject,
            $html,
            $plain
        );

        echo '  ' . ($success ? 'SENT' : 'FAIL') . " email #{$notifId} [{$type}] → {$notif['contact_email']}" . PHP_EOL;

    } elseif ($channel === 'sms') {

        if (empty($notif['contact_phone'])) {
            echo "  SKIP #{$notifId} — no phone number for user {$notif['user_id']}" . PHP_EOL;
            markNotification($pdo, $notifId, 'failed');
            $failed++;
            continue;
        }

        $message = getSMSTemplate($type, $data);

        if (!$message) {
            markNotification($pdo, $notifId, 'failed');
            $failed++;
            continue;
        }

        $success = sendSMS($notif['contact_phone'], $message);

        echo '  ' . ($success ? 'SENT' : 'FAIL') . " SMS #{$notifId} [{$type}] → {$notif['contact_phone']}" . PHP_EOL;
    }

    markNotification($pdo, $notifId, $success ? 'sent' : 'failed');
    $success ? $sent++ : $failed++;
}

// ── Done ─────────────────────────────────────────────────────
echo PHP_EOL . '[' . date('Y-m-d H:i:s') . "] Done. Sent: {$sent} | Failed: {$failed}" . PHP_EOL;

// ── Helper ───────────────────────────────────────────────────
function markNotification(PDO $pdo, int $id, string $status): void {
    $pdo->prepare('UPDATE notifications SET status = :s, sent_at = NOW() WHERE notification_id = :id')
        ->execute([':s' => $status, ':id' => $id]);
}
