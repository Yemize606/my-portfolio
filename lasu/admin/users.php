<?php
// ============================================================
//  admin/users.php  — User management
//  Create, view, activate/deactivate student & doctor accounts
// ============================================================
define('REQUIRED_ROLE', 'admin');
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$success = '';
$error   = '';

// ── Handle POST actions ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Security token mismatch. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Create new user ──────────────────────────────────
        if ($action === 'create') {
            $fullName    = trim($_POST['full_name']    ?? '');
            $matricNo    = trim($_POST['matric_number'] ?? '');
            $email       = trim($_POST['contact_email'] ?? '');
            $phone       = trim($_POST['contact_phone'] ?? '');
            $role        = $_POST['role'] ?? '';
            $deptId      = (int)($_POST['department_id'] ?? 0);
            $password    = $_POST['password'] ?? '';

            if (!$fullName || !$matricNo || !$password || !in_array($role, ['student','doctor','admin'])) {
                $error = 'Please fill in all required fields.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare(
                        'INSERT INTO users
                           (matric_number, full_name, password_hash, role,
                            contact_email, contact_phone, department_id)
                         VALUES (:matric, :name, :hash, :role, :email, :phone, :dept)'
                    );
                    $stmt->execute([
                        ':matric' => $matricNo,
                        ':name'   => $fullName,
                        ':hash'   => $hash,
                        ':role'   => $role,
                        ':email'  => $email ?: null,
                        ':phone'  => $phone ?: null,
                        ':dept'   => ($role === 'doctor' && $deptId) ? $deptId : null,
                    ]);
                    $success = "Account for {$fullName} created successfully.";
                } catch (PDOException $e) {
                    $error = str_contains($e->getMessage(), 'Duplicate')
                        ? "That matric/staff number already exists."
                        : "Database error. Please try again.";
                }
            }
        }

        // ── Toggle active status ─────────────────────────────
        if ($action === 'toggle') {
            $userId    = (int)($_POST['user_id'] ?? 0);
            $newStatus = (int)($_POST['new_status'] ?? 0);
            if ($userId && $userId !== $currentUser['id']) {
                $pdo->prepare('UPDATE users SET is_active = :s WHERE user_id = :id')
                    ->execute([':s' => $newStatus, ':id' => $userId]);
                $success = 'Account status updated.';
            }
        }

        // ── Reset password ───────────────────────────────────
        if ($action === 'reset_password') {
            $userId      = (int)($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';
            if ($userId && strlen($newPassword) >= 6) {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = :h WHERE user_id = :id')
                    ->execute([':h' => $hash, ':id' => $userId]);
                $success = 'Password reset successfully.';
            } else {
                $error = 'Password must be at least 6 characters.';
            }
        }
    }
}

// ── Fetch users ──────────────────────────────────────────────
$filterRole = $_GET['role'] ?? '';
$search     = trim($_GET['q'] ?? '');

$where  = 'WHERE 1=1';
$params = [];
if ($filterRole) {
    $where .= ' AND u.role = :role';
    $params[':role'] = $filterRole;
}
if ($search) {
    $where .= ' AND (u.full_name LIKE :q OR u.matric_number LIKE :q2)';
    $params[':q']  = "%{$search}%";
    $params[':q2'] = "%{$search}%";
}

$users = $pdo->prepare(
    "SELECT u.user_id, u.matric_number, u.full_name, u.role,
            u.contact_email, u.contact_phone, u.is_active, u.created_at,
            d.name AS department
       FROM users u
       LEFT JOIN departments d ON u.department_id = d.department_id
     {$where}
     ORDER BY u.created_at DESC
     LIMIT 100"
);
$users->execute($params);
$userList = $users->fetchAll();

// Departments for create form
$departments = $pdo->query(
    'SELECT department_id, name FROM departments ORDER BY name'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>User Management — LASU Health Center</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --coral: #993C1D; --coral-mid: #D85A30; --coral-light: #FAECE7;
      --cream: #FAF8F3; --white: #fff; --border: #E0D8D0;
      --text: #0D1F19; --muted: #7A7068;
      --green: #085041; --green-bg: #E1F5EE;
      --error-bg: #FEF2F2; --error-border: #FECACA; --error-text: #991B1B;
    }
    body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--cream); color: var(--text); }
    nav  { background: var(--coral); color: #fff; padding: 0 32px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
    nav .brand { font-weight: 600; font-size: 15px; }
    nav .nav-links { display: flex; gap: 20px; align-items: center; }
    nav a { color: #fff; opacity: .7; text-decoration: none; font-size: 13px; }
    nav a:hover, nav a.active { opacity: 1; }
    .page { max-width: 1100px; margin: 0 auto; padding: 32px 24px; }
    h1 { font-size: 20px; font-weight: 600; margin-bottom: 4px; }
    .sub { color: var(--muted); font-size: 14px; margin-bottom: 24px; }
    .layout { display: grid; grid-template-columns: 340px 1fr; gap: 24px; align-items: start; }
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 20px 24px; margin-bottom: 20px; }
    .card h2 { font-size: 15px; font-weight: 600; margin-bottom: 16px; }
    .alert { border-radius: 8px; padding: 11px 14px; font-size: 13px; margin-bottom: 18px; }
    .alert-success { background: var(--green-bg); border: 1px solid #9FE1CB; color: var(--green); }
    .alert-error { background: var(--error-bg); border: 1px solid var(--error-border); color: var(--error-text); }
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .04em; }
    .field input, .field select {
      width: 100%; height: 40px; padding: 0 12px; border: 1px solid var(--border);
      border-radius: 7px; font-size: 13px; font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--text); background: var(--white); outline: none;
    }
    .field input:focus, .field select:focus { border-color: var(--coral-mid); box-shadow: 0 0 0 3px rgba(216,90,48,0.1); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .btn { height: 38px; padding: 0 18px; border: none; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Plus Jakarta Sans', sans-serif; transition: background .2s; }
    .btn-primary { background: var(--coral); color: #fff; width: 100%; margin-top: 4px; }
    .btn-primary:hover { background: var(--coral-mid); }
    .btn-sm { height: 30px; padding: 0 12px; border: none; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: 'Plus Jakarta Sans', sans-serif; }
    .btn-deactivate { background: #FCEBEB; color: #791F1F; }
    .btn-activate   { background: var(--green-bg); color: var(--green); }
    .btn-reset      { background: #F1EFE8; color: #444441; }
    .search-row { display: flex; gap: 10px; margin-bottom: 16px; }
    .search-row input, .search-row select { height: 38px; padding: 0 12px; border: 1px solid var(--border); border-radius: 7px; font-size: 13px; font-family: 'Plus Jakarta Sans', sans-serif; outline: none; }
    .search-row input { flex: 1; }
    .search-row input:focus, .search-row select:focus { border-color: var(--coral-mid); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { text-align: left; color: var(--muted); font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
    td { padding: 10px 0; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-student  { background: #E1F5EE; color: #085041; }
    .badge-doctor   { background: #EEEDFE; color: #3C3489; }
    .badge-admin    { background: var(--coral-light); color: var(--coral); }
    .badge-active   { background: #E1F5EE; color: #085041; }
    .badge-inactive { background: #F1EFE8; color: #444441; }
    .actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .empty { color: var(--muted); font-size: 13px; padding: 12px 0; font-style: italic; }
    .dept-field { display: none; }
    .dept-field.visible { display: block; }
    @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<nav>
  <span class="brand">LASU Health Center — Admin</span>
  <div class="nav-links">
    <a href="dashboard.php">Overview</a>
    <a href="users.php" class="active">Users</a>
    <a href="schedules.php">Schedules</a>
    <a href="announcements.php">Announcements</a>
    <a href="../logout.php">Sign out</a>
  </div>
</nav>

<div class="page">
  <h1>User management</h1>
  <p class="sub">Create and manage student, doctor, and admin accounts.</p>

  <?php if ($success): ?>
    <div class="alert alert-success">&#10003; <?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error">&#9888; <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="layout">

    <!-- Create user form -->
    <div>
      <div class="card">
        <h2>Create new account</h2>
        <form method="POST" action="users.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
          <input type="hidden" name="action"     value="create"/>

          <div class="field">
            <label>Role *</label>
            <select name="role" id="role-select" onchange="toggleDept(this.value)" required>
              <option value="">— Select role —</option>
              <option value="student">Student</option>
              <option value="doctor">Doctor</option>
              <option value="admin">Admin</option>
            </select>
          </div>

          <div class="field">
            <label>Full name *</label>
            <input type="text" name="full_name" placeholder="e.g. Amara Okafor" required/>
          </div>

          <div class="field">
            <label id="matric-label">Matric number *</label>
            <input type="text" name="matric_number" placeholder="e.g. 200401001" required/>
          </div>

          <div class="field dept-field" id="dept-field">
            <label>Department</label>
            <select name="department_id">
              <option value="">— Select department —</option>
              <?php foreach ($departments as $d): ?>
                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field-row">
            <div class="field">
              <label>Email</label>
              <input type="email" name="contact_email" placeholder="email@domain.com"/>
            </div>
            <div class="field">
              <label>Phone</label>
              <input type="tel" name="contact_phone" placeholder="+2348012345678"/>
            </div>
          </div>

          <div class="field">
            <label>Password *</label>
            <input type="password" name="password" placeholder="Min 6 characters" required/>
          </div>

          <button type="submit" class="btn btn-primary">Create account</button>
        </form>
      </div>
    </div>

    <!-- User list -->
    <div>
      <div class="card">
        <h2>All accounts</h2>

        <form method="GET" action="users.php">
          <div class="search-row">
            <input type="text" name="q" placeholder="Search by name or ID…" value="<?= htmlspecialchars($search) ?>"/>
            <select name="role">
              <option value="">All roles</option>
              <option value="student"  <?= $filterRole === 'student'  ? 'selected' : '' ?>>Students</option>
              <option value="doctor"   <?= $filterRole === 'doctor'   ? 'selected' : '' ?>>Doctors</option>
              <option value="admin"    <?= $filterRole === 'admin'    ? 'selected' : '' ?>>Admins</option>
            </select>
            <button type="submit" class="btn btn-primary" style="width:auto;padding:0 16px">Filter</button>
          </div>
        </form>

        <?php if ($userList): ?>
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>ID</th>
                <th>Role</th>
                <th>Dept</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($userList as $u): ?>
              <tr>
                <td>
                  <div style="font-weight:500"><?= htmlspecialchars($u['full_name']) ?></div>
                  <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($u['contact_email'] ?? '—') ?></div>
                </td>
                <td><?= htmlspecialchars($u['matric_number']) ?></td>
                <td><span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                <td><?= htmlspecialchars($u['department'] ?? '—') ?></td>
                <td>
                  <span class="badge <?= $u['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td>
                  <div class="actions">
                    <!-- Toggle active -->
                    <?php if ($u['user_id'] !== $currentUser['id']): ?>
                      <form method="POST" action="users.php" style="display:inline">
                        <input type="hidden" name="csrf_token"  value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
                        <input type="hidden" name="action"      value="toggle"/>
                        <input type="hidden" name="user_id"     value="<?= $u['user_id'] ?>"/>
                        <input type="hidden" name="new_status"  value="<?= $u['is_active'] ? 0 : 1 ?>"/>
                        <button type="submit" class="btn-sm <?= $u['is_active'] ? 'btn-deactivate' : 'btn-activate' ?>">
                          <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </form>
                    <?php endif; ?>
                    <!-- Reset password -->
                    <button class="btn-sm btn-reset"
                      onclick="showReset(<?= $u['user_id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                      Reset pw
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <p class="empty">No accounts found.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Reset password modal (simple inline) -->
<div id="reset-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:28px 32px;width:360px;box-shadow:0 8px 32px rgba(0,0,0,0.15)">
    <h3 style="font-size:16px;font-weight:600;margin-bottom:4px">Reset password</h3>
    <p id="reset-name" style="font-size:13px;color:var(--muted);margin-bottom:18px"></p>
    <form method="POST" action="users.php">
      <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"/>
      <input type="hidden" name="action"        value="reset_password"/>
      <input type="hidden" name="user_id"       id="reset-uid"/>
      <div class="field">
        <label>New password</label>
        <input type="password" name="new_password" id="new-pw" placeholder="Min 6 characters"/>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="button" onclick="closeReset()"
          style="flex:1;height:38px;border:1.5px solid var(--border);background:transparent;border-radius:7px;cursor:pointer;font-size:13px">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary" style="flex:1;margin:0">Reset</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleDept(role) {
  const df = document.getElementById('dept-field');
  const ml = document.getElementById('matric-label');
  df.classList.toggle('visible', role === 'doctor');
  ml.textContent = role === 'doctor' ? 'Staff ID *' : role === 'admin' ? 'Admin ID *' : 'Matric number *';
}

function showReset(uid, name) {
  document.getElementById('reset-uid').value  = uid;
  document.getElementById('reset-name').textContent = 'Resetting password for: ' + name;
  document.getElementById('new-pw').value = '';
  document.getElementById('reset-modal').style.display = 'flex';
}

function closeReset() {
  document.getElementById('reset-modal').style.display = 'none';
}
</script>
</body>
</html>
