<?php
// ============================================================
//  doctor/cancel_appointment.php — sends email instantly
// ============================================================
define('REQUIRED_ROLE', 'doctor');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../notify/send_now.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php?msg=csrf'); exit;
}

$pdo           = getDB();
$doctorId      = $currentUser['id'];
$appointmentId = (int)($_POST['appointment_id'] ?? 0);
$reason        = trim($_POST['doctor_cancel_reason'] ?? '');

if (!$appointmentId || !$reason) { header('Location: dashboard.php?msg=noreason'); exit; }

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare(
        'SELECT a.appointment_id, a.schedule_id, a.student_id,
                a.reschedule_proposed_schedule_id, a.status
           FROM appointments a WHERE a.appointment_id=:aid AND a.doctor_id=:did'
    );
    $check->execute([':aid' => $appointmentId, ':did' => $doctorId]);
    $appt = $check->fetch();

    if (!$appt || $appt['status'] !== 'Scheduled') {
        $pdo->rollBack();
        header('Location: dashboard.php?msg=notscheduled'); exit;
    }

    $pdo->prepare(
        'UPDATE appointments SET status=\'Cancelled\', doctor_cancel_reason=:r,
                reschedule_status=\'none\', updated_at=NOW()
          WHERE appointment_id=:aid'
    )->execute([':r' => $reason, ':aid' => $appointmentId]);

    $pdo->prepare('UPDATE doctor_schedules SET is_booked=0 WHERE schedule_id=:sid')
        ->execute([':sid' => $appt['schedule_id']]);

    if ($appt['reschedule_proposed_schedule_id']) {
        $pdo->prepare('UPDATE doctor_schedules SET is_booked=0 WHERE schedule_id=:sid')
            ->execute([':sid' => $appt['reschedule_proposed_schedule_id']]);
    }

    $pdo->commit();

    // Send cancellation email to student instantly
    sendNotificationNow($pdo, $appointmentId, 'cancellation', $appt['student_id']);

    header('Location: dashboard.php?msg=cancelled');

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Doctor cancel: ' . $e->getMessage());
    header('Location: dashboard.php?msg=error');
}
exit;
