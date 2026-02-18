<?php
// admin_login.php
session_start();

// --- Simple credentials (change these) ---
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123');
// -----------------------------------------

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin_dashbaord.php');
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        if ($username === ADMIN_USER && $password === ADMIN_PASS) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            header('Location: admin_dashboard.php');
            exit;
        } else {
            $error_message = "Invalid username or password.";
        }
    } else {
        $error_message = "Please enter both username and password.";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Login - Seoul Trading</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f7f9fc; font-family: Inter, sans-serif; }
    .login-card { max-width:420px; margin:auto; margin-top:10vh; border-radius:12px; box-shadow:0 6px 18px rgba(0,0,0,0.08); }
    .login-card .card-body { padding:2rem; }
    .form-control { border-radius:8px; }
    .btn-login { border-radius:8px; padding:.6rem; font-weight:600; }
  </style>
</head>
<body>
<div class="card login-card">
  <div class="card-body">
    <h3 class="mb-3 text-center">Admin Login</h3>
    <p class="text-muted text-center mb-4">Sign in to manage your store</p>
    <?php if ($error_message): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <form method="post" action="">
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-login">Login</button>
    </form>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>