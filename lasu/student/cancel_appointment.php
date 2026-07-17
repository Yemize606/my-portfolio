<?php
// ============================================================
//  student/cancel_appointment.php — sends email instantly
// ============================================================
define('REQUIRED_ROLE', 'student');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../notify/send_now.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php?msg=csrf'); exit;
}

$pdo           = getDB();
$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$reason        = trim($_POST['cancellation_reason'] ?? '');
$studentId     = $currentUser['id'];

if (!$appointmentId) { header('Location: dashboard.php?msg=invalid'); exit; }
if (!$reason)        { header('Location: dashboard.php?msg=noreason&appt=' . $appointmentId); exit; }

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT a.appointment_id, a.schedule_id, a.doctor_id, a.status
           FROM appointments a WHERE a.appointment_id=:aid AND a.student_id=:sid'
    );
    $check->execute([':aid' => $appointmentId, ':sid' => $studentId]);
    $appt = $check->fetch();

    if (!$appt) { $pdo->rollBack(); header('Location: dashboard.php?msg=notfound'); exit; }
    if ($appt['status'] !== 'Scheduled') { $pdo->rollBack(); header('Location: dashboard.php?msg=notscheduled'); exit; }

    $pdo->prepare(
        'UPDATE appointments SET status=\'Cancelled\', cancellation_reason=:r, updated_at=NOW()
          WHERE appointment_id=:aid'
    )->execute([':r' => $reason, ':aid' => $appointmentId]);

    $pdo->prepare('UPDATE doctor_schedules SET is_booked=0 WHERE schedule_id=:sid')
        ->execute([':sid' => $appt['schedule_id']]);

    $pdo->commit();

    // Send cancellation email to doctor instantly
    sendNotificationNow($pdo, $appointmentId, 'cancellation', $appt['doctor_id']);

    header('Location: dashboard.php?msg=cancelled');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Student cancel: ' . $e->getMessage());
    header('Location: dashboard.php?msg=error');
}
exit;
