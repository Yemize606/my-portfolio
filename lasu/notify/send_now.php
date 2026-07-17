<?php
// ============================================================
//  notify/send_now.php
//  Sends a notification email IMMEDIATELY when called.
//  Include in any PHP handler that needs instant email.
// ============================================================

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/templates.php';

/**
 * Send an email notification immediately and log it.
 *
 * @param PDO    $pdo           Database connection
 * @param int    $appointmentId Appointment this is about
 * @param string $type          booking_confirmation | cancellation | reschedule | reminder_24h | follow_up_3d
 * @param int    $recipientId   user_id of the person receiving the email
 * @return bool  true if sent successfully
 */
function sendNotificationNow(PDO $pdo, int $appointmentId, string $type, int $recipientId): bool {
    try {
        // Fetch recipient
        $user = $pdo->prepare('SELECT full_name, contact_email FROM users WHERE user_id = :id');
        $user->execute([':id' => $recipientId]);
        $recipient = $user->fetch();

        if (!$recipient || empty($recipient['contact_email'])) {
            error_log("sendNotificationNow: No email for user {$recipientId}");
            _logNotif($pdo, $recipientId, $appointmentId, $type, 'failed');
            return false;
        }

        // Fetch appointment details
        $a = $pdo->prepare(
            'SELECT ds.available_date, ds.slot_start,
                    s.full_name   AS student_name,
                    d.full_name   AS doctor_name,
                    dept.name     AS department,
                    np.available_date AS new_date,
                    np.slot_start     AS new_start
               FROM appointments a
               JOIN doctor_schedules ds   ON a.schedule_id = ds.schedule_id
               JOIN users            s    ON a.student_id  = s.user_id
               JOIN users            d    ON a.doctor_id   = d.user_id
               LEFT JOIN departments dept ON d.department_id = dept.department_id
               LEFT JOIN doctor_schedules np
                      ON a.reschedule_proposed_schedule_id = np.schedule_id
              WHERE a.appointment_id = :aid'
        );
        $a->execute([':aid' => $appointmentId]);
        $appt = $a->fetch();

        if (!$appt) {
            error_log("sendNotificationNow: Appointment {$appointmentId} not found");
            _logNotif($pdo, $recipientId, $appointmentId, $type, 'failed');
            return false;
        }

        // Use proposed slot dates for reschedule notifications
        $useNewDate = ($type === 'reschedule' && !empty($appt['new_date']));

        $data = [
            'student_name'   => $appt['student_name'],
            'doctor_name'    => $appt['doctor_name'],
            'department'     => $appt['department'] ?? '',
            'appointment_id' => $appointmentId,
            'date'           => date('l, d F Y', strtotime($useNewDate ? $appt['new_date'] : $appt['available_date'])),
            'time'           => substr($useNewDate ? $appt['new_start'] : $appt['slot_start'], 0, 5),
        ];

        [$subject, $html, $plain] = getEmailTemplate($type, $data);

        if (!$subject) {
            error_log("sendNotificationNow: Unknown type '{$type}'");
            return false;
        }

        $sent = sendEmail($recipient['contact_email'], $recipient['full_name'], $subject, $html, $plain);
        _logNotif($pdo, $recipientId, $appointmentId, $type, $sent ? 'sent' : 'failed');
        return $sent;

    } catch (Throwable $e) {
        error_log("sendNotificationNow: " . $e->getMessage());
        return false;
    }
}

function _logNotif(PDO $pdo, int $uid, ?int $aid, string $type, string $status): void {
    try {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, appointment_id, type, channel, status, sent_at)
             VALUES (:uid, :aid, :type, \'email\', :status, NOW())'
        )->execute([':uid' => $uid, ':aid' => $aid, ':type' => $type, ':status' => $status]);
    } catch (PDOException $e) {
        error_log("_logNotif: " . $e->getMessage());
    }
}
