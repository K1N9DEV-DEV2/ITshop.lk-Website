<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Simple credentials
$admin_accounts = [
    [
        'email'    => 'admin@itshop.lk',
        'password' => 'adminitshop123',
        'role'     => 'admin'
    ],
    [
        'email'    => 'superadmin@itshop.lk',
        'password' => 'superadmin123',
        'role'     => 'superadmin'
    ],
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $matched = false;
    foreach ($admin_accounts as $account) {
        if ($email === $account['email'] && $password === $account['password']) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_email']     = $email;
            $_SESSION['admin_role']      = $account['role'];
            $matched = true;
            header('Location: admin_dashboard.php');
            exit;
        }
    }

    if (!$matched) {
        $error = 'Invalid email or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — IT Shop.LK</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0a0a0f;
            --ink-soft: #3d3d50;
            --ink-muted: #8888a0;
            --surface: #f6f6f8;
            --card: #ffffff;
            --accent: #0cb100;
            --radius-xl: 24px;
            --radius-lg: 16px;
            --radius-md: 12px;
            --shadow-card: 0 2px 12px rgba(10,10,15,0.07), 0 0 0 1px rgba(10,10,15,0.05);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Red Hat Display', sans-serif;
            background: var(--ink);
            color: var(--ink);
            min-height: 100vh;
            display: grid;
            place-items: center;
            -webkit-font-smoothing: antialiased;
            position: relative;
            overflow: hidden;
        }

        /* Background blobs */
        body::before,
        body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(90px);
            pointer-events: none;
        }
        body::before {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(70,229,91,0.25) 0%, transparent 70%);
            top: -100px; right: -80px;
        }
        body::after {
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(79,135,188,0.15) 0%, transparent 70%);
            bottom: -60px; left: -60px;
        }

        /* Grid lines */
        .bg-grid {
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
        }

        .login-wrap {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 420px;
            padding: 1.5rem;
            animation: fadeUp 0.6s ease both;
        }

        /* Logo / brand */
        .brand {
            text-align: center;
            margin-bottom: 2rem;
        }
        .brand-icon {
            width: 56px; height: 56px;
            background: var(--accent);
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #fff;
            margin-bottom: 1rem;
            box-shadow: 0 4px 20px rgba(12,177,0,0.4);
        }
        .brand-name {
            font-size: 1.4rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.02em;
        }
        .brand-sub {
            font-size: 0.85rem;
            color: #9999b8;
            margin-top: 4px;
        }

        /* Card */
        .card {
            background: var(--card);
            border-radius: var(--radius-xl);
            padding: 2.25rem 2rem;
            box-shadow: var(--shadow-card);
        }

        .card-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 0.25rem;
        }
        .card-hint {
            font-size: 0.85rem;
            color: var(--ink-muted);
            margin-bottom: 1.75rem;
        }

        /* Form */
        .field {
            margin-bottom: 1.1rem;
        }
        label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--ink-soft);
            margin-bottom: 6px;
            letter-spacing: 0.02em;
        }

        .input-wrap {
            position: relative;
        }
        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ink-muted);
            font-size: 0.9rem;
            pointer-events: none;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px 12px 40px;
            border: 1.5px solid rgba(10,10,15,0.1);
            border-radius: var(--radius-md);
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.95rem;
            color: var(--ink);
            background: var(--surface);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            outline: none;
        }
        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(12,177,0,0.12);
            background: #fff;
        }

        /* Toggle password visibility */
        .toggle-pw {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--ink-muted);
            font-size: 0.9rem;
            padding: 0;
            transition: color 0.2s;
        }
        .toggle-pw:hover { color: var(--ink); }

        /* Error */
        .alert-error {
            background: #fff1f1;
            border: 1.5px solid #fca5a5;
            color: #b91c1c;
            border-radius: var(--radius-md);
            padding: 10px 14px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Submit */
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-family: 'Red Hat Display', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.25s ease, transform 0.2s ease, box-shadow 0.25s ease;
            box-shadow: 0 4px 20px rgba(12,177,0,0.35);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover {
            background: #098600;
            transform: translateY(-1px);
            box-shadow: 0 8px 28px rgba(12,177,0,0.4);
        }
        .btn-submit:active { transform: translateY(0); }

        /* Back link */
        .back-link {
            text-align: center;
            margin-top: 1.25rem;
            font-size: 0.85rem;
            color: #9999b8;
        }
        .back-link a {
            color: #c7c7e0;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .back-link a:hover { color: #fff; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 480px) {
            .card { padding: 1.75rem 1.25rem; }
        }
    </style>
</head>
<body>
<div class="bg-grid"></div>

<div class="login-wrap">
    <div class="brand">
        <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="brand-name">IT Shop.LK</div>
        <div class="brand-sub">Admin Portal</div>
    </div>

    <div class="card">
        <div class="card-title">Welcome back</div>
        <div class="card-hint">Sign in to access the admin dashboard.</div>

        <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <div class="field">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope"></i>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="email"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        required
                        autocomplete="username"
                    >
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <i class="fas fa-lock"></i>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="••••••••••"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-pw" onclick="togglePassword()" aria-label="Toggle password">
                        <i class="fas fa-eye" id="eye-icon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-right-to-bracket"></i> Sign In
            </button>
        </form>
    </div>

    <div class="back-link">
        <a href="index.php"><i class="fas fa-arrow-left" style="font-size:0.75rem"></i> Back to Store</a>
    </div>
</div>

<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const icon  = document.getElementById('eye-icon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
</script>
</body>
</html>