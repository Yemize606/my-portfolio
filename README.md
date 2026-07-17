# LASU Health Center — Appointment Scheduling & Follow-Up System

A full-stack web application built for the Lagos State University Health Center to manage student appointment booking, doctor scheduling, consultations, and automated patient follow-up — built as my final year Computer Science project.

**Live demo:** _add your Render URL here once deployed_

## What it does

- **Students** book appointments with available doctors, receive automated email/SMS confirmations, and can cancel or track appointment status.
- **Doctors** manage their schedules, record consultations and diagnoses, and propose reschedules.
- **Admins** manage users, departments, and post announcements, and get analytics on appointment trends (no-show rates, department load) via database views.
- **Automated notifications** — instant booking/cancellation emails fire in real time; a scheduled background job sends 24-hour appointment reminders and 3-day post-visit follow-ups.

## Tech stack

- **Backend:** PHP 8, PDO (prepared statements throughout)
- **Database:** MySQL 8
- **Frontend:** HTML5, CSS3, vanilla JS
- **Email:** PHPMailer over Gmail SMTP
- **SMS:** Termii API
- **Deployment:** Docker, Render (web service + scheduled cron job), Aiven MySQL

## Security work

Since this handles real student health and appointment data, security wasn't an afterthought — a few specific things I built in or hardened:

- **SQL injection prevention** — every database query across the app uses PDO prepared statements with `emulate_prepares` disabled, so parameters are never interpolated into query strings.
- **CSRF protection** — every state-changing form (login, signup, booking, cancellation, admin actions) validates a per-session CSRF token using `hash_equals()` to prevent timing attacks.
- **Brute-force protection** — login attempts are rate-limited per account and per IP; 5 failed attempts triggers a 15-minute lockout, tracked in a dedicated `login_attempts` table.
- **Password security** — all passwords are hashed with bcrypt via PHP's `password_hash()`/`password_verify()`, never stored or logged in plaintext.
- **Session security** — session IDs are regenerated on login to prevent session fixation attacks.
- **Access control / IDOR prevention** — every record lookup (appointments, diagnoses, schedules) is scoped to the authenticated user's own ID at the query level, not just hidden in the UI.
- **Race condition handling** — appointment booking uses `SELECT ... FOR UPDATE` row locking to prevent double-booking the same time slot under concurrent requests.
- **Secrets management** — database credentials, email credentials, and API keys are read from environment variables at runtime, never committed to source control.
- **Output escaping** — all user-supplied and database-sourced content is passed through `htmlspecialchars()` before rendering, preventing stored/reflected XSS.

## Architecture notes

- Deployed via Docker on Render (PHP has no native Render runtime, so this uses a custom `php:8.2-apache` image with the `pdo_mysql` extension).
- Two Render services run from the same image: a web service for the app itself, and a scheduled cron job that runs the notification-processing script hourly — so reminder/follow-up emails go out automatically with no manual intervention.
- Database hosted on Aiven's managed MySQL.

## Screenshots
<img width="1366" height="768" alt="Screenshot 2026-04-22 101932" src="https://github.com/user-attachments/assets/2f501052-1105-4682-b96d-19a059391c8a" />
<img width="1920" height="1080" alt="Showing the User Management Page in the Administrator Panel " src="https://github.com/user-attachments/assets/6da018c1-593e-4f40-b14b-6be8a336022e" />
<img width="1920" height="1080" alt="doctor solt management" src="https://github.com/user-attachments/assets/ee690dd8-3276-4cd6-bb10-ce10041d2b1e" />
<img width="1920" height="1080" alt="booking page" src="https://github.com/user-attachments/assets/ffbcaa21-f7c6-440c-b812-2ab1c842faa2" />
<img width="1920" height="1080" alt="the Consultation and Diagnosis" src="https://github.com/user-attachments/assets/20cef267-36d2-49e4-b200-1969ce3085f9" />
<img width="1920" height="1080" alt="registeration page" src="https://github.com/user-attachments/assets/14dc1034-a1ab-4fbd-8f24-4b87e75dd762" />



## What I'd build next
what i would be intreasted in build will be a ai system that tracks and logs threat attack and colaborates with an ai engine on how to build the systems defense against such attacks automatically with just a single permission for the user it it just an idea for now i havent gotten the basics down yet if you have any advice to give or reviews or opions please do im still learning  
