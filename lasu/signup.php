<?php
// ============================================================
//  signup.php  — Student self-registration
//  Place at: htdocs/lasu/signup.php
// ============================================================

session_start();

// Already logged in? Redirect
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: student/dashboard.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$error   = '';
$success = '';
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $fullName   = trim($_POST['full_name']     ?? '');
        $matricNo   = trim($_POST['matric_number'] ?? '');
        $email      = trim($_POST['contact_email'] ?? '');
        $phone      = trim($_POST['contact_phone'] ?? '');
        $password   = $_POST['password']           ?? '';
        $confirmPw  = $_POST['confirm_password']   ?? '';

        // Validate
        if (!$fullName || !$matricNo || !$email || !$password) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPw) {
            $error = 'Passwords do not match.';
        } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one uppercase letter and one number.';
        } else {
            try {
                $pdo  = getDB();
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                       (matric_number, full_name, password_hash, role,
                        contact_email, contact_phone)
                     VALUES (:matric, :name, :hash, \'student\', :email, :phone)'
                );
                $stmt->execute([
                    ':matric' => $matricNo,
                    ':name'   => $fullName,
                    ':hash'   => $hash,
                    ':email'  => $email,
                    ':phone'  => $phone ?: null,
                ]);

                $success = true;

            } catch (PDOException $e) {
                $error = str_contains($e->getMessage(), 'Duplicate')
                    ? 'That matric number is already registered. Please log in instead.'
                    : 'A database error occurred. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Student Sign Up — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,400&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --green-deep: #0D3B2E; --green-mid: #1A5C44; --green-light: #2E7D5A;
      --gold: #C8922A; --gold-light: #E8B45A;
      --cream: #FAF8F3; --white: #fff;
      --border: #D4E4DC; --text: #0D1F19; --muted: #7A9589;
      --error-bg: #FEF2F2; --error-border: #FECACA; --error-text: #991B1B;
      --success-bg: #E1F5EE; --success-border: #9FE1CB; --success-text: #085041;
    }
    html, body { min-height: 100%; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text); }
    .page { display: grid; grid-template-columns: 1fr 1fr; min-height: 100vh; }

    /* Left panel */
    .panel-left { background: var(--green-deep); position: relative; display: flex; flex-direction: column; justify-content: space-between; padding: 48px 52px; overflow: hidden; }
    .panel-left::before { content: ''; position: absolute; width: 420px; height: 420px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.06); top: -140px; right: -140px; }
    .panel-left::after  { content: ''; position: absolute; width: 280px; height: 280px; border-radius: 50%; border: 1px solid rgba(255,255,255,0.06); bottom: -80px; left: -80px; }
    .brand { position: relative; z-index: 1; }
    .brand-mark { width: 48px; height: 48px; background: var(--gold); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-bottom: 28px; }
    .brand-mark svg { width: 26px; height: 26px; }
    .brand-name  { font-family: 'Fraunces', serif; font-size: 13px; letter-spacing: .18em; text-transform: uppercase; color: var(--gold-light); margin-bottom: 6px; }
    .brand-title { font-family: 'Fraunces', serif; font-size: 32px; font-weight: 600; color: var(--white); line-height: 1.2; }
    .panel-center { position: relative; z-index: 1; flex: 1; display: flex; align-items: center; }
    .tagline { border-left: 2px solid var(--gold); padding-left: 20px; }
    .tagline-text { font-family: 'Fraunces', serif; font-size: 20px; font-style: italic; color: rgba(255,255,255,.85); line-height: 1.5; margin-bottom: 12px; }
    .tagline-sub  { font-size: 13px; color: rgba(255,255,255,.45); }
    .steps-list { position: relative; z-index: 1; }
    .step-item { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 18px; }
    .step-dot  { width: 24px; height: 24px; border-radius: 50%; background: rgba(200,146,42,.25); border: 1px solid var(--gold); display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 1px; }
    .step-dot span { font-size: 11px; font-weight: 600; color: var(--gold-light); }
    .step-text { font-size: 13px; color: rgba(255,255,255,.7); line-height: 1.5; }
    .step-title { color: rgba(255,255,255,.9); font-weight: 500; }

    /* Right panel */
    .panel-right { display: flex; align-items: flex-start; justify-content: center; padding: 48px 52px; overflow-y: auto; }
    .form-card { width: 100%; max-width: 420px; padding-top: 8px; }
    .form-heading    { font-family: 'Fraunces', serif; font-size: 26px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
    .form-subheading { font-size: 14px; color: var(--muted); margin-bottom: 28px; }

    /* Success state */
    .success-box { text-align: center; padding: 32px 24px; }
    .success-icon { width: 64px; height: 64px; background: var(--success-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
    .success-icon svg { width: 32px; height: 32px; }
    .success-title { font-family: 'Fraunces', serif; font-size: 22px; font-weight: 600; margin-bottom: 8px; }
    .success-msg   { font-size: 14px; color: var(--muted); line-height: 1.7; margin-bottom: 24px; }

    /* Alerts */
    .alert { border-radius: 8px; padding: 12px 14px; font-size: 13px; line-height: 1.5; margin-bottom: 18px; }
    .alert-error { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); }

    /* Fields */
    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 13px; font-weight: 500; color: var(--muted); margin-bottom: 7px; }
    .field label span { color: #E24B4A; margin-left: 2px; }
    .field input {
      width: 100%; height: 46px; padding: 0 14px; border: 1px solid var(--border);
      border-radius: 9px; font-size: 14px; font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--text); background: var(--white); outline: none;
      transition: border-color .2s, box-shadow .2s;
    }
    .field input::placeholder { color: #b0c4ba; }
    .field input:focus { border-color: var(--green-light); box-shadow: 0 0 0 3px rgba(46,125,90,.12); }
    .field input.invalid { border-color: #E24B4A; }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .field-hint { font-size: 11px; color: var(--muted); margin-top: 5px; }

    /* Password strength */
    .pw-strength { margin-top: 6px; }
    .pw-bar { height: 4px; border-radius: 2px; background: var(--border); overflow: hidden; margin-bottom: 4px; }
    .pw-fill { height: 100%; border-radius: 2px; width: 0; transition: width .3s, background .3s; }
    .pw-label { font-size: 11px; color: var(--muted); }

    /* Password wrap */
    .pw-wrap { position: relative; }
    .pw-wrap input { padding-right: 44px; }
    .toggle-pw { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--muted); padding: 0; display: flex; }
    .toggle-pw:hover { color: var(--text); }

    /* Submit */
    .btn-submit {
      width: 100%; height: 48px; background: var(--green-deep); color: var(--white);
      font-size: 15px; font-weight: 600; font-family: 'Plus Jakarta Sans', sans-serif;
      border: none; border-radius: 10px; cursor: pointer; margin-top: 8px;
      transition: background .2s; display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit:hover { background: var(--green-mid); }
    .btn-submit:disabled { opacity: .6; cursor: not-allowed; }
    .btn-login {
      display: block; text-align: center; margin-top: 18px; font-size: 13px;
      color: var(--muted);
    }
    .btn-login a { color: var(--green-light); font-weight: 600; text-decoration: none; }
    .btn-login a:hover { text-decoration: underline; }
    .btn-primary-outline {
      display: inline-block; padding: 12px 28px; border: 2px solid var(--green-deep);
      border-radius: 9px; font-size: 14px; font-weight: 600; color: var(--green-deep);
      text-decoration: none; margin-top: 8px;
    }
    .btn-primary-outline:hover { background: var(--green-deep); color: var(--white); }
    .divider { display: flex; align-items: center; gap: 12px; margin: 20px 0 16px; color: var(--muted); font-size: 12px; }
    .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    @media (max-width: 760px) {
      .page { grid-template-columns: 1fr; }
      .panel-left { display: none; }
      .panel-right { padding: 40px 28px; }
    }
  </style>
</head>
<body>
<div class="page">

  <!-- Left panel -->
  <aside class="panel-left">
    <div class="brand">
      <div class="brand-mark">
        <svg viewBox="0 0 26 26" fill="none"><rect x="10" y="2" width="6" height="22" rx="3" fill="#0D3B2E"/><rect x="2" y="10" width="22" height="6" rx="3" fill="#0D3B2E"/></svg>
      </div>
      <div class="brand-name">Lagos State University</div>
      <div class="brand-title">Student Health<br>Center</div>
    </div>

    <div class="panel-center">
      <div class="tagline">
        <p class="tagline-text">"Your health is the<br>foundation of your success."</p>
        <p class="tagline-sub">LASU Health Services Division</p>
      </div>
    </div>

    <div class="steps-list">
      <div class="step-item">
        <div class="step-dot"><span>1</span></div>
        <div class="step-text"><span class="step-title">Create your account</span><br>Register with your matric number and email.</div>
      </div>
      <div class="step-item">
        <div class="step-dot"><span>2</span></div>
        <div class="step-text"><span class="step-title">Book appointments</span><br>Choose a department, doctor, and time slot.</div>
      </div>
      <div class="step-item">
        <div class="step-dot"><span>3</span></div>
        <div class="step-text"><span class="step-title">Get care</span><br>Attend your appointment and receive treatment.</div>
      </div>
    </div>
  </aside>

  <!-- Right panel -->
  <main class="panel-right">
    <div class="form-card">

      <?php if ($success): ?>
        <!-- Success state -->
        <div class="success-box">
          <div class="success-icon">
            <svg viewBox="0 0 32 32" fill="none">
              <circle cx="16" cy="16" r="14" stroke="#0F6E56" stroke-width="2"/>
              <path d="M10 16.5l4 4 8-9" stroke="#0F6E56" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <h2 class="success-title">Account created!</h2>
          <p class="success-msg">
            Your student account has been created successfully.<br/>
            You can now log in to book appointments.
          </p>
          <a href="login.php" class="btn-primary-outline">Go to login →</a>
        </div>

      <?php else: ?>
        <!-- Sign up form -->
        <h1 class="form-heading">Create your account</h1>
        <p class="form-subheading">Register as a LASU student to access the health center portal.</p>

        <?php if ($error): ?>
          <div class="alert alert-error">&#9888; <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="signup.php" id="signup-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>

          <div class="field">
            <label for="full_name">Full name <span>*</span></label>
            <input type="text" id="full_name" name="full_name"
                   placeholder="e.g. Azeez Omoyemi"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required/>
          </div>

          <div class="field">
            <label for="matric_number">Matric number <span>*</span></label>
            <input type="text" id="matric_number" name="matric_number"
                   placeholder="e.g. 200401001"
                   value="<?= htmlspecialchars($_POST['matric_number'] ?? '') ?>" required/>
          </div>

          <div class="field">
            <label for="contact_email">Email address <span>*</span></label>
            <input type="email" id="contact_email" name="contact_email"
                   placeholder="your@email.com"
                   value="<?= htmlspecialchars($_POST['contact_email'] ?? '') ?>" required/>
          </div>

          <div class="field">
            <label for="contact_phone">Phone number <span style="color:var(--muted);font-weight:400">(optional)</span></label>
            <input type="tel" id="contact_phone" name="contact_phone"
                   placeholder="+2348012345678"
                   value="<?= htmlspecialchars($_POST['contact_phone'] ?? '') ?>"/>
          </div>

          <div class="field">
            <label for="password">Password <span>*</span></label>
            <div class="pw-wrap">
              <input type="password" id="password" name="password"
                     placeholder="Min 8 characters"
                     oninput="checkStrength(this.value)" required/>
              <button type="button" class="toggle-pw" onclick="togglePw('password', this)">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                </svg>
              </button>
            </div>
            <div class="pw-strength">
              <div class="pw-bar"><div class="pw-fill" id="pw-fill"></div></div>
              <span class="pw-label" id="pw-label">Enter a password</span>
            </div>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm password <span>*</span></label>
            <div class="pw-wrap">
              <input type="password" id="confirm_password" name="confirm_password"
                     placeholder="Repeat your password" required/>
              <button type="button" class="toggle-pw" onclick="togglePw('confirm_password', this)">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
                </svg>
              </button>
            </div>
            <p class="field-hint">Must contain uppercase letter and number.</p>
          </div>

          <button type="submit" class="btn-submit" id="submit-btn">
            Create account
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
            </svg>
          </button>
        </form>

        <p class="btn-login">Already have an account? <a href="login.php">Sign in</a></p>

      <?php endif; ?>

    </div>
  </main>
</div>

<script>
function togglePw(id, btn) {
  const input = document.getElementById(id);
  input.type = input.type === 'password' ? 'text' : 'password';
}

function checkStrength(pw) {
  const fill  = document.getElementById('pw-fill');
  const label = document.getElementById('pw-label');
  let score = 0;
  if (pw.length >= 8)          score++;
  if (/[A-Z]/.test(pw))        score++;
  if (/[0-9]/.test(pw))        score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const levels = [
    { w: '0%',   c: '#E24B4A', t: 'Too short'  },
    { w: '25%',  c: '#E24B4A', t: 'Weak'       },
    { w: '50%',  c: '#EF9F27', t: 'Fair'       },
    { w: '75%',  c: '#1D9E75', t: 'Good'       },
    { w: '100%', c: '#085041', t: 'Strong'     },
  ];
  const lvl = pw.length === 0 ? 0 : Math.min(score + 1, 4);
  fill.style.width      = levels[lvl].w;
  fill.style.background = levels[lvl].c;
  label.textContent     = pw.length === 0 ? 'Enter a password' : levels[lvl].t;
}

document.getElementById('signup-form').addEventListener('submit', function() {
  const btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.textContent = 'Creating account…';
});
</script>
</body>
</html>