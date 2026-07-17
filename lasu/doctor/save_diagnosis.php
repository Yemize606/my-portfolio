<?php
// ============================================================
//  doctor/save_diagnosis.php  — POST handler (no output)
//  Receives the EMR form, validates, saves to diagnoses table,
//  updates appointment status, logs follow-up notification.
// ============================================================
define('REQUIRED_ROLE', 'doctor');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

// CSRF check
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php?error=csrf');
    exit;
}

$pdo           = getDB();
$doctorId      = $currentUser['id'];
$appointmentId = (int)($_POST['appointment_id'] ?? 0);

// ── Validate inputs ──────────────────────────────────────────
$symptomsReported  = trim($_POST['symptoms_reported']  ?? '');
$doctorDiagnosis   = trim($_POST['doctor_diagnosis']   ?? '');
$prescriptionNotes = trim($_POST['prescription_notes'] ?? '');
$followUpRequired  = isset($_POST['follow_up_required']) ? 1 : 0;
$apptStatus        = in_array($_POST['appt_status'] ?? '', ['Completed', 'Missed'], true)
                     ? $_POST['appt_status']
                     : 'Completed';

if (!$appointmentId || !$symptomsReported || !$doctorDiagnosis) {
    // Send back to form with error
    header('Location: consultation.php?id=' . $appointmentId . '&err=missing');
    exit;
}

try {
    $pdo->beginTransaction();

    // Confirm appointment belongs to this doctor and is still Scheduled
    $check = $pdo->prepare(
        'SELECT a.appointment_id, a.student_id, a.status
           FROM appointments a
          WHERE a.appointment_id = :aid
            AND a.doctor_id      = :did'
    );
    $check->execute([':aid' => $appointmentId, ':did' => $doctorId]);
    $appt = $check->fetch();

    if (!$appt) {
        throw new RuntimeException('Appointment not found or does not belong to you.');
    }

    // Check diagnosis doesn't already exist (prevent duplicate saves)
    $dupCheck = $pdo->prepare(
        'SELECT diagnosis_id FROM diagnoses WHERE appointment_id = :aid'
    );
    $dupCheck->execute([':aid' => $appointmentId]);
    if ($dupCheck->fetch()) {
        // Already saved — just redirect back to view
        header('Location: consultation.php?id=' . $appointmentId . '&saved=1');
        exit;
    }

    // ── Insert diagnosis ─────────────────────────────────────
    $insert = $pdo->prepare(
        'INSERT INTO diagnoses
           (appointment_id, doctor_id, symptoms_reported,
            doctor_diagnosis, prescription_notes, follow_up_required)
         VALUES
           (:aid, :did, :symptoms, :diagnosis, :prescription, :followup)'
    );
    $insert->execute([
        ':aid'          => $appointmentId,
        ':did'          => $doctorId,
        ':symptoms'     => $symptomsReported,
        ':diagnosis'    => $doctorDiagnosis,
        ':prescription' => $prescriptionNotes ?: null,
        ':followup'     => $followUpRequired,
    ]);

    // ── Update appointment status ────────────────────────────
    $pdo->prepare(
        'UPDATE appointments SET status = :status WHERE appointment_id = :aid'
    )->execute([':status' => $apptStatus, ':aid' => $appointmentId]);

    // ── Log 3-day follow-up notification if needed ───────────
    if ($followUpRequired && $appt['student_id']) {
        $pdo->prepare(
            'INSERT INTO notifications
               (user_id, appointment_id, type, channel, status)
             VALUES (:uid, :aid, \'follow_up_3d\', \'email\', \'pending\')'
        )->execute([':uid' => $appt['student_id'], ':aid' => $appointmentId]);
    }

    // ── Log completion notification to student ───────────────
    // (confirmation that visit was recorded)
    if ($apptStatus === 'Completed') {
        $pdo->prepare(
            'INSERT INTO notifications
               (user_id, appointment_id, type, channel, status)
             VALUES (:uid, :aid, \'booking_confirmation\', \'email\', \'pending\')'
        )->execute([':uid' => $appt['student_id'], ':aid' => $appointmentId]);
    }

    $pdo->commit();

    // Redirect back to the consultation page with success flag
    header('Location: consultation.php?id=' . $appointmentId . '&saved=1');
    exit;

} catch (RuntimeException $e) {
    $pdo->rollBack();
    error_log('save_diagnosis error: ' . $e->getMessage());
    header('Location: consultation.php?id=' . $appointmentId . '&err=' . urlencode($e->getMessage()));
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('save_diagnosis PDO error: ' . $e->getMessage());
    header('Location: consultation.php?id=' . $appointmentId . '&err=db');
    exit;
}
