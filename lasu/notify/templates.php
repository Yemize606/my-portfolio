<?php
// ============================================================
//  notify/templates.php
//  Returns a [subject, html, plain] array for each notification
//  type. All templates share the same header/footer wrapper.
// ============================================================

/**
 * Build a full HTML email string with the LASU header/footer.
 */
function wrapEmail(string $title, string $bodyHtml): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#F4F4F0;font-family:'Helvetica Neue',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#F4F4F0;padding:32px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;">

      <!-- Header -->
      <tr>
        <td style="background:#0D3B2E;padding:28px 36px;">
          <p style="margin:0;font-size:11px;letter-spacing:.15em;text-transform:uppercase;color:#E8B45A;">Lagos State University</p>
          <p style="margin:6px 0 0;font-size:22px;font-weight:700;color:#ffffff;">Student Health Center</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px;">
          {$bodyHtml}
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#F0EDE6;padding:20px 36px;border-top:1px solid #E0DDD6;">
          <p style="margin:0;font-size:12px;color:#7A9589;line-height:1.7;">
            This is an automated message from the LASU Health Center portal.<br/>
            Do not reply to this email. For enquiries call the health center directly.<br/>
            <strong style="color:#0D3B2E;">LASU Health Center</strong> — Lagos State University, Ojo, Lagos.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Returns [subject, htmlBody, plainText] for a given notification type.
 *
 * @param string $type     One of: booking_confirmation, reminder_24h,
 *                                  cancellation, reschedule, follow_up_3d
 * @param array  $data     Keys: student_name, doctor_name, department,
 *                               date, time, appointment_id, symptoms (optional)
 */
function getEmailTemplate(string $type, array $d): array {

    $ref    = '#' . str_pad($d['appointment_id'] ?? 0, 6, '0', STR_PAD_LEFT);
    $name   = htmlspecialchars($d['student_name']  ?? 'Student');
    $doctor = htmlspecialchars($d['doctor_name']   ?? 'your doctor');
    $dept   = htmlspecialchars($d['department']    ?? '');
    $date   = htmlspecialchars($d['date']          ?? '');
    $time   = htmlspecialchars($d['time']          ?? '');

    // Reusable appointment detail block
    $detailBlock = <<<HTML
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#F0F8F4;border-radius:8px;padding:20px;margin:20px 0;">
  <tr>
    <td style="font-size:13px;color:#7A9589;padding:4px 0;width:120px;">Department</td>
    <td style="font-size:13px;font-weight:600;color:#0D1F19;">{$dept}</td>
  </tr>
  <tr>
    <td style="font-size:13px;color:#7A9589;padding:4px 0;">Doctor</td>
    <td style="font-size:13px;font-weight:600;color:#0D1F19;">Dr. {$doctor}</td>
  </tr>
  <tr>
    <td style="font-size:13px;color:#7A9589;padding:4px 0;">Date</td>
    <td style="font-size:13px;font-weight:600;color:#0D1F19;">{$date}</td>
  </tr>
  <tr>
    <td style="font-size:13px;color:#7A9589;padding:4px 0;">Time</td>
    <td style="font-size:13px;font-weight:600;color:#0D1F19;">{$time}</td>
  </tr>
  <tr>
    <td style="font-size:13px;color:#7A9589;padding:4px 0;">Reference</td>
    <td style="font-size:13px;font-weight:600;color:#0D1F19;">{$ref}</td>
  </tr>
</table>
HTML;

    switch ($type) {

        // ── Booking confirmation ─────────────────────────────
        case 'booking_confirmation':
            $subject = "Appointment confirmed — {$date} at {$time}";
            $body    = <<<HTML
<h2 style="margin:0 0 8px;font-size:22px;color:#0D1F19;">You're booked in!</h2>
<p style="margin:0 0 4px;font-size:15px;color:#3D5A50;">Hi {$name},</p>
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  Your appointment at the LASU Health Center has been confirmed.
  Please arrive <strong>10 minutes early</strong> with your student ID card.
</p>
{$detailBlock}
<p style="font-size:13px;color:#7A9589;line-height:1.7;">
  Need to cancel or reschedule? Log in to the Health Center portal at least
  2 hours before your appointment time.
</p>
HTML;
            $plain = "Hi {$name}, your appointment with Dr. {$doctor} ({$dept}) is confirmed for {$date} at {$time}. Ref: {$ref}.";
            break;

        // ── 24-hour reminder ─────────────────────────────────
        case 'reminder_24h':
            $subject = "Reminder: Your appointment is tomorrow at {$time}";
            $body    = <<<HTML
<h2 style="margin:0 0 8px;font-size:22px;color:#0D1F19;">Your appointment is tomorrow</h2>
<p style="margin:0 0 4px;font-size:15px;color:#3D5A50;">Hi {$name},</p>
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  This is a reminder that you have an appointment at the LASU Health Center
  <strong>tomorrow</strong>. Please don't forget!
</p>
{$detailBlock}
<table width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 20px;">
  <tr>
    <td style="background:#FFF8EC;border:1px solid #F0D9A0;border-radius:8px;padding:14px 16px;">
      <p style="margin:0;font-size:13px;color:#7A5C1E;line-height:1.6;">
        &#9888;&nbsp; If you can no longer make this appointment, please cancel it via the
        portal so the slot can be given to another student.
      </p>
    </td>
  </tr>
</table>
HTML;
            $plain = "Hi {$name}, reminder: your appointment with Dr. {$doctor} is tomorrow ({$date}) at {$time}. Ref: {$ref}.";
            break;

        // ── Cancellation ─────────────────────────────────────
        case 'cancellation':
            $subject = "Appointment cancelled — {$ref}";
            $body    = <<<HTML
<h2 style="margin:0 0 8px;font-size:22px;color:#0D1F19;">Appointment cancelled</h2>
<p style="margin:0 0 4px;font-size:15px;color:#3D5A50;">Hi {$name},</p>
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  Your appointment has been cancelled. Details of the cancelled slot are below.
</p>
{$detailBlock}
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  If you still need to see a doctor, please log in to the portal and book a new appointment.
</p>
HTML;
            $plain = "Hi {$name}, your appointment with Dr. {$doctor} on {$date} at {$time} has been cancelled. Ref: {$ref}.";
            break;

        // ── Reschedule ───────────────────────────────────────
        case 'reschedule':
            $subject = "Appointment rescheduled — new time: {$date} at {$time}";
            $body    = <<<HTML
<h2 style="margin:0 0 8px;font-size:22px;color:#0D1F19;">Your appointment has been rescheduled</h2>
<p style="margin:0 0 4px;font-size:15px;color:#3D5A50;">Hi {$name},</p>
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  Your appointment has been rescheduled. Your new appointment details are below.
</p>
{$detailBlock}
<p style="font-size:13px;color:#7A9589;line-height:1.7;">
  If this new time does not suit you, please log in to the portal to cancel and
  book a different slot.
</p>
HTML;
            $plain = "Hi {$name}, your appointment has been rescheduled to {$date} at {$time} with Dr. {$doctor}. Ref: {$ref}.";
            break;

        // ── 3-day follow-up ──────────────────────────────────
        case 'follow_up_3d':
            $subject = "How are you feeling? — Follow-up from LASU Health Center";
            $body    = <<<HTML
<h2 style="margin:0 0 8px;font-size:22px;color:#0D1F19;">How are you feeling?</h2>
<p style="margin:0 0 4px;font-size:15px;color:#3D5A50;">Hi {$name},</p>
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  It's been 3 days since your consultation with Dr. {$doctor} at the LASU Health Center.
  We hope you are feeling better!
</p>
<p style="font-size:14px;color:#3D5A50;line-height:1.7;">
  If your symptoms have not improved — or if you have new concerns — please
  book a follow-up appointment via the portal.
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;">
  <tr>
    <td align="center">
      <a href="http://localhost/lasu/student/book_appointment.php"
         style="display:inline-block;background:#0D3B2E;color:#fff;text-decoration:none;
                padding:12px 28px;border-radius:8px;font-size:14px;font-weight:600;">
        Book a follow-up appointment
      </a>
    </td>
  </tr>
</table>
<p style="font-size:13px;color:#7A9589;text-align:center;">
  Previous visit: {$date} at {$time} (Ref: {$ref})
</p>
HTML;
            $plain = "Hi {$name}, it's been 3 days since your visit with Dr. {$doctor}. We hope you're feeling better. If not, please book a follow-up at the Health Center portal.";
            break;

        default:
            return ['', '', ''];
    }

    return [$subject, wrapEmail($subject, $body), $plain];
}


/**
 * Returns a short SMS string for a given notification type.
 */
function getSMSTemplate(string $type, array $d): string {
    $name   = $d['student_name']  ?? 'Student';
    $doctor = $d['doctor_name']   ?? 'your doctor';
    $date   = $d['date']          ?? '';
    $time   = $d['time']          ?? '';
    $ref    = '#' . str_pad($d['appointment_id'] ?? 0, 6, '0', STR_PAD_LEFT);

    return match ($type) {
        'booking_confirmation' =>
            "Hi {$name}, your LASU Health Center appointment with Dr. {$doctor} is confirmed for {$date} at {$time}. Ref: {$ref}.",
        'reminder_24h' =>
            "LASU Health Center reminder: You have an appointment TOMORROW ({$date}) at {$time} with Dr. {$doctor}. Please don't miss it. Ref: {$ref}.",
        'cancellation' =>
            "LASU Health Center: Your appointment on {$date} at {$time} (Ref: {$ref}) has been cancelled. Please rebook if needed.",
        'reschedule' =>
            "LASU Health Center: Your appointment has been rescheduled to {$date} at {$time} with Dr. {$doctor}. Ref: {$ref}.",
        'follow_up_3d' =>
            "Hi {$name}, LASU Health Center is checking in. How are you feeling 3 days after your visit with Dr. {$doctor}? Book a follow-up if needed.",
        default => '',
    };
}
