<?php
// ============================================================
//  student/fetch_slots.php  — AJAX JSON endpoint
//  Called by book_appointment.php via fetch()
//
//  ?action=doctors&dept_id=1          → list of doctors
//  ?action=slots&doctor_id=3&date=... → free time slots
// ============================================================
define('REQUIRED_ROLE', 'student');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$pdo    = getDB();

try {

    // ── Return doctors for a given department ─────────────────
    if ($action === 'doctors') {
        $deptId = (int)($_GET['dept_id'] ?? 0);

        if (!$deptId) {
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.full_name, d.name AS department
               FROM users u
               JOIN departments d ON u.department_id = d.department_id
              WHERE u.department_id = :dept
                AND u.role          = \'doctor\'
                AND u.is_active     = 1
              ORDER BY u.full_name'
        );
        $stmt->execute([':dept' => $deptId]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // ── Return free time slots for a doctor on a date ─────────
    if ($action === 'slots') {
        $doctorId = (int)($_GET['doctor_id'] ?? 0);
        $date     = $_GET['date'] ?? '';

        // Basic date validation
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$doctorId || !$parsed || $parsed->format('Y-m-d') !== $date) {
            echo json_encode([]);
            exit;
        }

        // Don't allow past dates
        if ($date < date('Y-m-d', strtotime('+1 day'))) {
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT schedule_id, slot_start, slot_end, is_booked
               FROM doctor_schedules
              WHERE doctor_id      = :did
                AND available_date = :date
              ORDER BY slot_start'
        );
        $stmt->execute([':did' => $doctorId, ':date' => $date]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);

} catch (PDOException $e) {
    error_log('fetch_slots error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
