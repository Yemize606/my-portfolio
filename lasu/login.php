<?php
// ============================================================
//  login.php — Entry point for all three portals
//  Place at: htdocs/lasu_health_center/login.php
// ============================================================

session_start();

// Already logged in? Redirect to the right dashboard immediately.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    redirectToDashboard($_SESSION['role']);
}

require_once __DIR__ . '/config/db.php';

$error   = '';
$success = '';

// Ensure a CSRF token exists for the form below
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// ── Handle POST (login attempt) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check — was missing before; every other form in this app checks this
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please refresh the page and try again.';

    } else {

    $matricNumber = trim($_POST['matric_number'] ?? '');
    $password     = $_POST['password']     ?? '';
    $role         = $_POST['role']         ?? '';

    $allowedRoles = ['student', 'doctor', 'admin'];
    $ip           = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (empty($matricNumber) || empty($password) || !in_array($role, $allowedRoles, true)) {
        $error = 'Please fill in all fields correctly.';

    } else {
        $pdo = getDB();

        // ── Brute-force lockout check ────────────────────
        $lock = loginIsLockedOut($pdo, $matricNumber, $ip);
        if ($lock !== null) {
            $error = "Too many failed attempts. Please try again in {$lock} minute" . ($lock === 1 ? '' : 's') . '.';
        } else {

        $stmt = $pdo->prepare(
            'SELECT user_id, full_name, password_hash, role, department_id, is_active
               FROM users
              WHERE matric_number = :matric
                AND role = :role
              LIMIT 1'
        );
        $stmt->execute([':matric' => $matricNumber, ':role' => $role]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            recordLoginAttempt($pdo, $matricNumber, $ip, false);
            // Intentionally vague — don't reveal whether the ID or password was wrong
            $error = 'Invalid credentials. Please check your ID and password.';

        } elseif (!(bool)$user['is_active']) {
            recordLoginAttempt($pdo, $matricNumber, $ip, false);
            $error = 'Your account has been deactivated. Please contact the Health Center.';

        } else {
            // ── Login success ──────────────────────────────
            recordLoginAttempt($pdo, $matricNumber, $ip, true);
            session_regenerate_id(true); // Prevent session fixation attacks

            $_SESSION['user_id']       = $user['user_id'];
            $_SESSION['full_name']     = $user['full_name'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['department_id'] = $user['department_id'];

            redirectToDashboard($user['role']);
        }
        }
    }
    }
}

// ── Helper ───────────────────────────────────────────────────
function redirectToDashboard(string $role): void {
    $map = [
        'student' => 'student/dashboard.php',
        'doctor'  => 'doctor/dashboard.php',
        'admin'   => 'admin/dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? 'login.php'));
    exit;
}

// ── Brute-force protection ──────────────────────────────────
// Locks out an identifier (matric_number + IP) after too many failed
// attempts within a rolling window. Uses the login_attempts table
// (see config/login_attempts.sql).
const LOGIN_MAX_ATTEMPTS  = 5;   // failed tries allowed
const LOGIN_WINDOW_MIN    = 15;  // rolling window, in minutes
const LOGIN_LOCKOUT_MIN   = 15;  // lockout duration once tripped

function recordLoginAttempt(PDO $pdo, string $matric, string $ip, bool $success): void {
    $pdo->prepare(
        'INSERT INTO login_attempts (matric_number, ip_address, success, attempted_at)
         VALUES (:matric, :ip, :success, NOW())'
    )->execute([':matric' => $matric, ':ip' => $ip, ':success' => $success ? 1 : 0]);

    // Keep the table small — prune anything older than a day
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

/**
 * Returns minutes remaining if the identifier is currently locked out,
 * or null if login attempts are allowed.
 */
function loginIsLockedOut(PDO $pdo, string $matric, string $ip): ?int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS fails, MAX(attempted_at) AS last_attempt
           FROM login_attempts
          WHERE (matric_number = :matric OR ip_address = :ip)
            AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)'
    );
    $stmt->execute([':matric' => $matric, ':ip' => $ip, ':window' => LOGIN_WINDOW_MIN]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['fails'] < LOGIN_MAX_ATTEMPTS) {
        return null;
    }

    $lastAttempt   = new DateTime($row['last_attempt']);
    $unlocksAt     = (clone $lastAttempt)->modify('+' . LOGIN_LOCKOUT_MIN . ' minutes');
    $now           = new DateTime();

    if ($now >= $unlocksAt) {
        return null; // lockout window has passed
    }

    return (int) ceil(($unlocksAt->getTimestamp() - $now->getTimestamp()) / 60);
}

// Reason messages from session_guard redirects
$reasonMessages = [
    'session'   => 'Your session has expired. Please log in again.',
    'forbidden' => 'You do not have permission to access that page.',
];
$notice = $reasonMessages[$_GET['reason'] ?? ''] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>LASU Health Center — Login</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;1,9..144,400&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --green-deep: #0D3B2E; --green-mid: #1A5C44; --green-light: #2E7D5A;
    --gold: #C8922A; --gold-light: #E8B45A;
    --cream: #FAF8F3; --white: #FFFFFF;
    --text-dark: #0D1F19; --text-mid: #3D5A50; --text-muted: #7A9589;
    --border: #D4E4DC; --error-bg: #FEF2F2; --error-border: #FECACA;
    --error-text: #991B1B; --notice-bg: #FFF8EC; --notice-border: #F0D9A0;
    --notice-text: #7A5C1E;
  }
  html, body { height: 100%; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text-dark); }

  .panel-left {
  display: none;
}

.page {
  display: grid;
  grid-template-columns: 1fr;
  min-height: 100vh;   /* ensures full viewport height */
  width: 100%;         /* ensures full width */
}

  /* Right panel */
   .panel-right {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px 52px;
  background: var(--green-deep); /* changed to green */
  
  /* NEW: make it take full width and center content */
  min-height: 100vh;
  width: 100%;
}
   .form-card {
  width: 100%;
  max-width: 400px;
  animation: fadeUp 0.5s ease both;

  background: var(--white);   /* gives contrast */
  padding: 32px;              /* spacing inside */
  border-radius: 16px;        /* smooth edges */
  box-shadow: 0 10px 30px rgba(0,0,0,0.1); /* nice elevation */
}
  @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
  .form-heading    { font-family: 'Fraunces', serif; font-size: 28px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
  .form-subheading { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; }

  /* Alert banners */
  .alert { border-radius: 9px; padding: 12px 14px; font-size: 13px; line-height: 1.5; margin-bottom: 18px; display: flex; gap: 8px; align-items: flex-start; }
  .alert-error  { background: var(--error-bg);  border: 1px solid var(--error-border);  color: var(--error-text); }
  .alert-notice { background: var(--notice-bg); border: 1px solid var(--notice-border); color: var(--notice-text); }

  /* Role tabs */
  .role-tabs { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; margin-bottom: 24px; background: #EAF0EC; padding: 4px; border-radius: 10px; }
  .role-tab  { padding: 9px 0; font-size: 13px; font-weight: 500; text-align: center; border-radius: 7px; cursor: pointer; border: none; background: transparent; color: var(--text-muted); transition: all 0.2s ease; font-family: 'Plus Jakarta Sans', sans-serif; }
  .role-tab.active { background: var(--white); color: var(--green-deep); box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
  .role-tab:hover:not(.active) { color: var(--text-dark); }

  /* Form fields */
  .field { margin-bottom: 18px; }
  .field label { display: block; font-size: 13px; font-weight: 500; color: var(--text-mid); margin-bottom: 7px; }
  .field input  { width: 100%; height: 46px; padding: 0 14px; border: 1px solid var(--border); border-radius: 9px; font-size: 14px; font-family: 'Plus Jakarta Sans', sans-serif; color: var(--text-dark); background: var(--white); transition: border-color 0.2s, box-shadow 0.2s; outline: none; }
  .field input::placeholder { color: #b0c4ba; }
  .field input:focus { border-color: var(--green-light); box-shadow: 0 0 0 3px rgba(46,125,90,0.12); }
  .password-wrap { position: relative; }
  .password-wrap input { padding-right: 44px; }
  .toggle-pw { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 0; display: flex; align-items: center; }
  .toggle-pw:hover { color: var(--text-dark); }
  .field-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 7px; }
  .forgot-link { font-size: 12px; color: var(--green-light); text-decoration: none; font-weight: 500; }
  .forgot-link:hover { text-decoration: underline; }
  .id-hint { display: inline-block; font-size: 11px; background: #E8F2EC; color: var(--green-mid); padding: 2px 8px; border-radius: 4px; font-weight: 500; margin-left: 6px; }

  /* Submit */
  .btn-submit { width: 100%; height: 48px; background: var(--green-deep); color: var(--white); font-size: 15px; font-weight: 600; font-family: 'Plus Jakarta Sans', sans-serif; border: none; border-radius: 10px; cursor: pointer; transition: background 0.2s, transform 0.1s; margin-top: 6px; display: flex; align-items: center; justify-content: center; gap: 8px; }
  .btn-submit:hover  { background: var(--green-mid); }
  .btn-submit:active { transform: scale(0.98); }
  .btn-submit:disabled { opacity: 0.65; cursor: not-allowed; }

  .divider { display: flex; align-items: center; gap: 12px; margin: 22px 0 18px; color: var(--text-muted); font-size: 12px; }
  .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
  .help-text { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 10px; }
  .help-text a { color: var(--green-light); font-weight: 600; text-decoration: none; }
  .help-text a:hover { text-decoration: underline; }

  @media (max-width: 720px) {
    .page { grid-template-columns: 1fr; }
    .panel-left { display: none; }
    .panel-right { padding: 40px 28px; align-items: flex-start; padding-top: 60px; }
  }
</style>
</head>
<body>
<div class="page">

  <main class="panel-right">
    <div class="form-card">
      <h1 class="form-heading">Welcome back</h1>
      <p class="form-subheading">Sign in to access your health center portal</p>

      <?php if ($notice): ?>
        <div class="alert alert-notice">&#9432; <?= htmlspecialchars($notice) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert">&#9888; <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="role-tabs" role="tablist">
        <button type="button" class="role-tab active" onclick="setRole('student')" role="tab">Student</button>
        <button type="button" class="role-tab"        onclick="setRole('doctor')"  role="tab">Doctor</button>
        <button type="button" class="role-tab"        onclick="setRole('admin')"   role="tab">Admin</button>
      </div>

      <form method="POST" action="login.php" novalidate>
        <!-- CSRF token -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
        <!-- Role sent with form -->
        <input type="hidden" name="role" id="role-input" value="student"/>

        <div class="field">
          <label for="matric_number">
            <span id="id-label">Matric number</span>
            <span class="id-hint" id="id-hint">e.g. 200401001</span>
          </label>
          <input type="text" id="matric_number" name="matric_number"
                 placeholder="Enter your matric number"
                 value="<?= htmlspecialchars($_POST['matric_number'] ?? '') ?>"
                 autocomplete="username" required/>
        </div>

        <div class="field">
          <div class="field-row">
            <label for="password">Password</label>
            <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
          </div>
          <div class="password-wrap">
            <input type="password" id="password" name="password"
                   placeholder="Enter your password"
                   autocomplete="current-password" required/>
            <button type="button" class="toggle-pw" onclick="togglePw()" aria-label="Toggle password visibility">
              <svg id="eye-icon" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn">
          <span id="btn-text">Sign in as Student</span>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
          </svg>
        </button>
      </form>

      <div class="divider">or</div>
      <p class="help-text">Need an account? <a href="signup.php">Sign up here</a></p>
    </div>
  </main>
</div>

<script>
const roleConfig = {
  student: { label: 'Matric number', hint: 'e.g. 200401001', placeholder: 'Enter your matric number', btn: 'Sign in as Student' },
  doctor:  { label: 'Staff ID',      hint: 'e.g. DOC-0042',  placeholder: 'Enter your staff ID',       btn: 'Sign in as Doctor'  },
  admin:   { label: 'Admin ID',      hint: 'e.g. ADM-001',   placeholder: 'Enter your admin ID',       btn: 'Sign in as Admin'   },
};

function setRole(role) {
  const cfg = roleConfig[role];
  document.getElementById('id-label').textContent      = cfg.label;
  document.getElementById('id-hint').textContent       = cfg.hint;
  document.getElementById('matric_number').placeholder = cfg.placeholder;
  document.getElementById('btn-text').textContent      = cfg.btn;
  document.getElementById('role-input').value          = role;
  document.querySelectorAll('.role-tab').forEach((t, i) => {
    t.classList.toggle('active', ['student','doctor','admin'][i] === role);
  });
}

function togglePw() {
  const input = document.getElementById('password');
  const icon  = document.getElementById('eye-icon');
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  icon.innerHTML = show
    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>'
    : '<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>';
}

// Restore role tab if PHP re-displayed the form after an error
const postedRole = '<?= htmlspecialchars($_POST['role'] ?? 'student') ?>';
if (postedRole && roleConfig[postedRole]) setRole(postedRole);

// Disable button on submit to prevent double-posts
document.querySelector('form').addEventListener('submit', () => {
  const btn = document.getElementById('submit-btn');
  btn.disabled = true;
  document.getElementById('btn-text').textContent = 'Signing in…';
});
</script>
</body>
</html>
