<?php
define('REQUIRED_ROLE', 'student');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch.';
    } else {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password']     ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        if (!$currentPw || !$newPw || !$confirmPw) {
            $error = 'Please fill in all fields.';
        } elseif (strlen($newPw) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
            $error = 'Password must contain at least one uppercase letter and one number.';
        } elseif ($newPw !== $confirmPw) {
            $error = 'New passwords do not match.';
        } else {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
            $stmt->execute([':id' => $currentUser['id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPw, $user['password_hash'])) {
                $error = 'Your current password is incorrect.';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id')
                    ->execute([':h' => password_hash($newPw, PASSWORD_DEFAULT), ':id' => $currentUser['id']]);
                $success = 'Password changed successfully.';
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
  <title>Change Password — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{--green:#0D3B2E;--gm:#1A5C44;--gl:#2E7D5A;--cream:#FAF8F3;--white:#fff;--border:#D4E4DC;--text:#0D1F19;--mid:#3D5A50;--muted:#7A9589;--erb:#FEF2F2;--erb2:#FECACA;--ert:#991B1B;--sb:#E1F5EE;--sb2:#9FE1CB;--st:#085041}
    body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--text)}
    nav{background:var(--green);color:#fff;padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between}
    nav .brand{font-weight:600;font-size:15px}
    nav .links{display:flex;gap:20px;align-items:center}
    nav a{color:#fff;opacity:.7;text-decoration:none;font-size:13px}
    nav a:hover{opacity:1}
    .page{max-width:520px;margin:48px auto;padding:0 24px}
    h1{font-size:22px;font-weight:600;margin-bottom:4px}
    .sub{color:var(--muted);font-size:14px;margin-bottom:28px}
    .card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:28px 32px}
    .alert{border-radius:8px;padding:12px 16px;font-size:13px;margin-bottom:20px}
    .aerr{background:var(--erb);border:1px solid var(--erb2);color:var(--ert)}
    .aok{background:var(--sb);border:1px solid var(--sb2);color:var(--st)}
    .field{margin-bottom:18px}
    .field label{display:block;font-size:13px;font-weight:500;color:var(--mid);margin-bottom:7px}
    .pwwrap{position:relative}
    .pwwrap input{width:100%;height:46px;padding:0 44px 0 14px;border:1px solid var(--border);border-radius:9px;font-size:14px;font-family:'Plus Jakarta Sans',sans-serif;color:var(--text);background:var(--white);outline:none;transition:border-color .2s,box-shadow .2s}
    .pwwrap input:focus{border-color:var(--gl);box-shadow:0 0 0 3px rgba(46,125,90,.12)}
    .tpw{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);display:flex;align-items:center}
    .tpw:hover{color:var(--text)}
    .pwbar{height:4px;border-radius:2px;background:var(--border);overflow:hidden;margin-top:8px}
    .pwfill{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0}
    .pwlbl{font-size:11px;color:var(--muted);margin-top:3px}
    .divider{border:none;border-top:1px solid var(--border);margin:22px 0}
    .btn-row{display:flex;gap:12px}
    .btn-primary{flex:1;height:48px;background:var(--green);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;transition:background .2s}
    .btn-primary:hover{background:var(--gm)}
    .btn-back{height:48px;padding:0 20px;background:transparent;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-weight:500;cursor:pointer;color:var(--muted);text-decoration:none;display:flex;align-items:center}
    .btn-back:hover{border-color:var(--text);color:var(--text)}
    .rules{list-style:none;margin-top:6px}
    .rules li{font-size:12px;color:var(--muted);padding:2px 0;display:flex;gap:6px;align-items:center}
    .rules li::before{content:'·';font-size:18px;line-height:1;flex-shrink:0}
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center</span>
  <div class="links">
    <a href="dashboard.php">Dashboard</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>Change password</h1>
  <p class="sub">Update your login password. You will stay logged in after changing it.</p>

  <div class="card">
    <?php if ($success): ?>
      <div class="alert aok">&#10003; <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert aerr">&#9888; <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="change_password.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>

      <div class="field">
        <label>Current password</label>
        <div class="pwwrap">
          <input type="password" id="cpw" name="current_password" placeholder="Enter your current password" autocomplete="current-password" required/>
          <button type="button" class="tpw" onclick="tpw('cpw','e0')">
            <svg id="e0" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
            </svg>
          </button>
        </div>
      </div>

      <hr class="divider"/>

      <div class="field">
        <label>New password</label>
        <div class="pwwrap">
          <input type="password" id="npw" name="new_password" placeholder="At least 8 characters"
                 autocomplete="new-password" oninput="checkStr(this.value)" required/>
          <button type="button" class="tpw" onclick="tpw('npw','e1')">
            <svg id="e1" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
            </svg>
          </button>
        </div>
        <div class="pwbar"><div class="pwfill" id="pwf"></div></div>
        <p class="pwlbl" id="pwl">Enter a new password</p>
        <ul class="rules">
          <li>At least 8 characters</li>
          <li>At least one uppercase letter</li>
          <li>At least one number</li>
        </ul>
      </div>

      <div class="field">
        <label>Confirm new password</label>
        <div class="pwwrap">
          <input type="password" id="cpw2" name="confirm_password" placeholder="Repeat your new password"
                 autocomplete="new-password" required/>
          <button type="button" class="tpw" onclick="tpw('cpw2','e2')">
            <svg id="e2" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="btn-row">
        <a href="dashboard.php" class="btn-back">Cancel</a>
        <button type="submit" class="btn-primary">Update password</button>
      </div>
    </form>
  </div>
</div>

<script>
const eyeOff='<path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
const eyeOn='<path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.522 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.478 0-8.268-2.943-9.542-7z"/>';
function tpw(id,eid){const i=document.getElementById(id);const s=i.type==='password';i.type=s?'text':'password';document.getElementById(eid).innerHTML=s?eyeOff:eyeOn;}
function checkStr(pw){const f=document.getElementById('pwf'),l=document.getElementById('pwl');let s=0;if(pw.length>=8)s++;if(/[A-Z]/.test(pw))s++;if(/[0-9]/.test(pw))s++;if(/[^A-Za-z0-9]/.test(pw))s++;const lv=[{w:'0%',bg:'transparent',t:'Enter a new password'},{w:'25%',bg:'#E24B4A',t:'Weak'},{w:'50%',bg:'#EF9F27',t:'Fair'},{w:'75%',bg:'#1D9E75',t:'Good'},{w:'100%',bg:'#0F6E56',t:'Strong'}];const v=pw.length===0?lv[0]:lv[s];f.style.width=v.w;f.style.background=v.bg;l.textContent=v.t;}
</script>
</body>
</html>
