<?php
session_start();
include 'db.php';

$message     = "";
$error       = "";
$show_signup = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    /* ── LOGIN ── */
    if (isset($_POST['login'])) {
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = "Please fill in all fields.";
        } else {
            $stmt = $conn->prepare("SELECT id, email, password, first_name FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name']  = $user['first_name'];
                    header("Location: index.php");
                    exit();
                } else {
                    $error = "Invalid email or password.";
                }
            } else {
                $error = "Invalid email or password.";
            }
            $stmt->close();
        }
    }

    /* ── SIGNUP ── */
    elseif (isset($_POST['signup'])) {
        $show_signup      = true;
        $first_name       = htmlspecialchars(trim($_POST['first_name']       ?? ''));
        $last_name        = htmlspecialchars(trim($_POST['last_name']        ?? ''));
        $phone            = htmlspecialchars(trim($_POST['phone']            ?? ''));
        $address          = htmlspecialchars(trim($_POST['address']          ?? ''));
        $city             = htmlspecialchars(trim($_POST['city']             ?? ''));
        $country          = htmlspecialchars(trim($_POST['country']          ?? ''));
        $email            = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $password         = $_POST['password']         ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!$first_name||!$last_name||!$phone||!$address||
            !$city||!$country||!$email||!$password||!$confirm_password) {
            $error = "Please fill in all fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "An account with that email already exists.";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt   = $conn->prepare(
                    "INSERT INTO users (first_name,last_name,phone,address,city,country,email,password,created_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW())"
                );
                $stmt->bind_param("ssssssss",
                    $first_name,$last_name,$phone,$address,$city,$country,$email,$hashed
                );
                if ($stmt->execute()) {
                    $message     = "Account created successfully! Please sign in.";
                    $show_signup = false;
                    $_POST       = [];
                } else {
                    $error = "Error creating account. Please try again.";
                }
            }
            $stmt->close();
        }
    }
}
$conn->close();

function old(string $key): string {
    return htmlspecialchars($_POST[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In / Sign Up – IT Shop.LK</title>
    <link rel="icon" type="image/x-icon" href="assets/logo.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
    /* ══ TOKENS ══════════════════════════════════════ */
    :root {
        --accent:        #0cb100;
        --accent-dark:   #087600;
        --accent-soft:   #f6f7ffb4;
        --accent-border: rgba(13, 255, 0, 0.2);
        --green:  #10b981;
        --red:    #dc2626;
        --amber:  #f59e0b;
        --blue:   #3b82f6;
        --ink:    #0d0d14;
        --ink-2:  #3c3c52;
        --ink-3:  #8585a0;
        --surface:#f5f5f8;
        --white:  #ffffff;
        --r-sm:  8px;
        --r-md:  14px;
        --r-lg:  20px;
        --r-xl:  28px;
        --r-full:999px;
        --tr: .18s ease;
    }

    /* ══ RESET ═══════════════════════════════════════ */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }

    /* ══ BODY & BACKGROUND ═══════════════════════════ */
    body {
        font-family: 'Red Hat Display', sans-serif;
        min-height: 100vh;
        background: var(--ink);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 16px;
        position: relative;
        -webkit-font-smoothing: antialiased;
    }

    .bg-blob {
        position: fixed;
        border-radius: 50%;
        filter: blur(88px);
        pointer-events: none;
        z-index: 0;
    }
    .bb1 {
        width: 560px; height: 560px;
        background: radial-gradient(circle, rgba(70, 229, 73, 0.3) 0%, transparent 70%);
        top: -160px; right: -110px;
    }
    .bb2 {
        width: 400px; height: 400px;
        background: radial-gradient(circle, rgba(61, 185, 16, 0.17) 0%, transparent 70%);
        bottom: -90px; left: -70px;
    }
    .bg-grid {
        position: fixed; inset: 0; z-index: 0; pointer-events: none;
        background-image:
            linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
        background-size: 56px 56px;
    }

    /* ══ CARD ════════════════════════════════════════ */
    .auth-card {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 496px;
        background: var(--white);
        border-radius: var(--r-xl);
        box-shadow: 0 24px 64px rgba(13,13,20,.22);
        overflow: hidden;
    }

    /* ── header ── */
    .auth-head {
        background: #043900;
        padding: 2rem 2.5rem 1.85rem;
        text-align: center;
        position: relative;
    }
    .auth-head::after {
        content: '';
        position: absolute;
        left: 0; right: 0; bottom: 0; height: 3px;
        background: linear-gradient(90deg, var(--accent) 0%, #34d399 100%);
    }

    .auth-logo { margin-bottom: 1.4rem; }
    .auth-logo img {
        height: 42px;
        width: auto;
        display: block;
        margin: 0 auto;
    }
    .auth-logo-fallback {
        display: none;
        font-size: 1.5rem;
        font-weight: 900;
        color: #fff;
        letter-spacing: -.02em;
    }
    .auth-logo-fallback em {
        font-style: normal;
        background: linear-gradient(135deg, #818cf8, #34d399);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    /* tabs */
    .auth-tabs {
        display: inline-flex;
        background: rgba(255,255,255,.07);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: var(--r-full);
        padding: 4px;
        gap: 3px;
    }
    .auth-tab {
        font-family: 'Red Hat Display', sans-serif;
        font-size: .83rem;
        font-weight: 700;
        letter-spacing: .01em;
        padding: 7px 26px;
        border-radius: var(--r-full);
        border: none;
        background: transparent;
        color: rgba(255,255,255,.44);
        cursor: pointer;
        white-space: nowrap;
        transition: background var(--tr), color var(--tr), box-shadow var(--tr);
    }
    .auth-tab.active {
        background: var(--accent);
        color: #fff;
        box-shadow: 0 2px 12px rgba(79,70,229,.5);
    }

    /* ── body ── */
    .auth-body { padding: 2rem 2.5rem 2.5rem; }

    /* ── panels ── */
    .auth-panel { display: none; }
    .auth-panel.active {
        display: block;
        animation: panelIn .22s ease both;
    }
    @keyframes panelIn {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .panel-title {
        font-size: 1.35rem;
        font-weight: 800;
        color: var(--ink);
        letter-spacing: -.025em;
        margin-bottom: .25rem;
    }
    .panel-sub {
        font-size: .83rem;
        font-weight: 500;
        color: var(--ink-3);
        margin-bottom: 1.5rem;
        line-height: 1.5;
    }

    /* ══ ALERTS ══════════════════════════════════════ */
    .auth-alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: .75rem 1rem;
        border-radius: var(--r-md);
        font-size: .84rem;
        font-weight: 600;
        line-height: 1.45;
        margin-bottom: 1.25rem;
        animation: panelIn .2s ease;
    }
    .auth-alert i { margin-top: 2px; flex-shrink: 0; }
    .auth-alert.is-error {
        background: rgba(220,38,38,.07);
        color: #dc2626;
        border: 1px solid rgba(220,38,38,.14);
    }
    .auth-alert.is-success {
        background: rgba(16,185,129,.08);
        color: #059669;
        border: 1px solid rgba(16,185,129,.14);
    }

    /* ══ FORM LAYOUT ══════════════════════════════════ */
    .f-row { display: flex; gap: 12px; }
    .f-row .f-group { flex: 1; min-width: 0; }
    .f-group { margin-bottom: .95rem; }

    .f-label {
        display: block;
        font-size: .72rem;
        font-weight: 800;
        letter-spacing: .09em;
        text-transform: uppercase;
        color: var(--ink-2);
        margin-bottom: .38rem;
    }

    /* input + icon wrapper */
    .f-wrap { position: relative; }
    .f-ico {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        font-size: .82rem;
        width: 1rem;
        text-align: center;
        color: var(--ink-3);
        pointer-events: none;
        transition: color var(--tr);
        line-height: 1;
        z-index: 1;
    }
    .f-wrap:focus-within .f-ico { color: var(--accent); }

    /* inputs */
    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="tel"] {
        width: 100%;
        font-family: 'Red Hat Display', sans-serif;
        font-size: 16px; /* prevent iOS zoom */
        font-weight: 500;
        color: var(--ink);
        background: var(--surface);
        border: 1.5px solid rgba(0,0,0,.09);
        border-radius: var(--r-md);
        padding: .65rem .9rem .65rem .9rem;
        outline: none;
        transition: border-color var(--tr), box-shadow var(--tr), background var(--tr);
        -webkit-appearance: none;
    }
    .f-wrap input[type="text"],
    .f-wrap input[type="email"],
    .f-wrap input[type="password"],
    .f-wrap input[type="tel"] { padding-left: 2.85rem; }

    /* ── eye toggle ── */
    .f-wrap { display: flex; align-items: center; }
    .eye-btn {
        position: absolute;
        right: .85rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
        color: var(--ink-3);
        font-size: .82rem;
        line-height: 1;
        z-index: 1;
        transition: color var(--tr);
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.1rem;
        height: 1.1rem;
    }
    .eye-btn:hover { color: var(--accent); }
    /* when eye button present, add right padding to input */
    .f-wrap input.has-eye { padding-right: 2.5rem; }

    input::placeholder { color: var(--ink-3); }
    input:focus {
        border-color: rgba(79,70,229,.4);
        box-shadow: 0 0 0 3px rgba(79,70,229,.1);
        background: var(--white);
    }
    input.is-valid   { border-color: rgba(16,185,129,.5) !important; }
    input.is-invalid { border-color: rgba(220,38,38,.5)  !important; }

    /* password strength */
    .pw-hint {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: .42rem;
    }
    .pw-bar {
        flex: 1;
        height: 3px;
        background: rgba(0,0,0,.08);
        border-radius: var(--r-full);
        overflow: hidden;
    }
    .pw-fill {
        height: 100%;
        width: 0;
        border-radius: var(--r-full);
        transition: width .3s ease, background .3s ease;
    }
    .pw-label {
        font-size: .72rem;
        font-weight: 700;
        color: var(--ink-3);
        min-width: 52px;
        text-align: right;
        white-space: nowrap;
    }

    /* divider inside form */
    .f-divider {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 1.1rem 0 .9rem;
        font-size: .7rem;
        font-weight: 800;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: var(--ink-3);
    }
    .f-divider::before, .f-divider::after {
        content: '';
        flex: 1;
        height: 1px;
        background: rgba(0,0,0,.08);
    }

    /* ══ SUBMIT ══════════════════════════════════════ */
    .btn-auth {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        margin-top: .6rem;
        padding: .82rem 1rem;
        font-family: 'Red Hat Display', sans-serif;
        font-size: .9rem;
        font-weight: 800;
        letter-spacing: .01em;
        color: #fff;
        background: var(--accent);
        border: none;
        border-radius: var(--r-md);
        cursor: pointer;
        box-shadow: 0 4px 16px rgba(79,70,229,.35);
        transition: background var(--tr), transform var(--tr), box-shadow var(--tr);
        -webkit-appearance: none;
    }
    .btn-auth i { font-size: .82rem; }
    .btn-auth:hover {
        background: var(--accent-dark);
        transform: translateY(-1px);
        box-shadow: 0 8px 24px rgba(79,70,229,.45);
    }
    .btn-auth:active { transform: translateY(0); }

    /* ══ SWITCH LINK ══════════════════════════════════ */
    .auth-switch {
        text-align: center;
        margin-top: 1.25rem;
        font-size: .82rem;
        font-weight: 500;
        color: var(--ink-3);
        line-height: 1.6;
    }
    .auth-switch a {
        color: var(--accent);
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        transition: color var(--tr);
    }
    .auth-switch a:hover { color: var(--accent-dark); text-decoration: underline; }

    /* ══ RESPONSIVE ══════════════════════════════════ */

    /* ≤ 560px – narrow phones / small tablets */
    @media (max-width: 560px) {
        body {
            padding: 16px 12px;
            align-items: flex-start;
        }
        .auth-card {
            border-radius: var(--r-lg);
            max-width: 100%;
        }
        .auth-head { padding: 1.6rem 1.5rem 1.5rem; }
        .auth-body { padding: 1.5rem 1.5rem 2rem; }
        .auth-tab  { padding: 7px 18px; font-size: .8rem; }
        .panel-title { font-size: 1.2rem; }
        .panel-sub   { font-size: .81rem; margin-bottom: 1.2rem; }
    }

    /* ≤ 420px – small phones: stack all f-rows */
    @media (max-width: 420px) {
        body { padding: 12px 10px; }
        .auth-head { padding: 1.4rem 1.25rem 1.3rem; }
        .auth-body { padding: 1.25rem 1.25rem 1.75rem; }
        .auth-logo img { height: 36px; }

        /* make tabs full-width */
        .auth-tabs { display: flex; width: 100%; }
        .auth-tab  { flex: 1; text-align: center; padding: 7px 8px; font-size: .78rem; }

        /* stack every row */
        .f-row { flex-direction: column; gap: 0; }

        .panel-title { font-size: 1.1rem; }
        .panel-sub   { font-size: .79rem; margin-bottom: 1rem; }
        .f-label     { font-size: .7rem; }
        .btn-auth    { font-size: .875rem; }
        .auth-switch { font-size: .79rem; }
    }

    /* ≤ 360px – very small */
    @media (max-width: 360px) {
        .auth-head { padding: 1.2rem 1rem 1.1rem; }
        .auth-body { padding: 1rem 1rem 1.5rem; }
        .f-group   { margin-bottom: .8rem; }
    }
    </style>
</head>
<body>

    <!-- Background -->
    <div class="bg-blob bb1"></div>
    <div class="bg-blob bb2"></div>
    <div class="bg-grid"></div>

    <div class="auth-card">

        <!-- ═══ HEADER ═══ -->
        <div class="auth-head">
            <div class="auth-logo">
                <img src="assets/revised-04.png" alt="IT Shop.LK"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
                <div class="auth-logo-fallback">IT Shop<em>.LK</em></div>
            </div>

            <div class="auth-tabs" role="tablist">
                <button class="auth-tab <?= !$show_signup ? 'active' : '' ?>"
                        id="tabLogin" role="tab"
                        onclick="switchTab('login')">Sign In</button>
                <button class="auth-tab <?= $show_signup ? 'active' : '' ?>"
                        id="tabSignup" role="tab"
                        onclick="switchTab('signup')">Sign Up</button>
            </div>
        </div>

        <!-- ═══ BODY ═══ -->
        <div class="auth-body">

            <!-- ━━━ LOGIN ━━━ -->
            <div class="auth-panel <?= !$show_signup ? 'active' : '' ?>" id="panelLogin">

                <div class="panel-title">Welcome back</div>
                <div class="panel-sub">Sign in to your IT Shop.LK account</div>

                <?php if ($error && !isset($_POST['signup'])): ?>
                <div class="auth-alert is-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <?php if ($message): ?>
                <div class="auth-alert is-success" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>
                    <div class="f-group">
                        <label class="f-label" for="login_email">Email address</label>
                        <div class="f-wrap">
                            <i class="fas fa-envelope f-ico"></i>
                            <input type="email" id="login_email" name="email"
                                   placeholder="you@example.com"
                                   autocomplete="email" required>
                        </div>
                    </div>

                    <div class="f-group">
                        <label class="f-label" for="login_password">Password</label>
                        <div class="f-wrap">
                            <i class="fas fa-lock f-ico"></i>
                            <input type="password" id="login_password" name="password"
                                   placeholder="Enter your password"
                                   autocomplete="current-password" required class="has-eye">
                            <button type="button" class="eye-btn" onclick="togglePw('login_password', this)" aria-label="Show password" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="login" class="btn-auth">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>

                <div class="auth-switch">
                    Don't have an account?
                    <a onclick="switchTab('signup')">Create one free</a>
                </div>
            </div>

            <!-- ━━━ SIGNUP ━━━ -->
            <div class="auth-panel <?= $show_signup ? 'active' : '' ?>" id="panelSignup">

                <div class="panel-title">Create account</div>
                <div class="panel-sub">Join IT Shop.LK – takes less than a minute</div>

                <?php if ($error && isset($_POST['signup'])): ?>
                <div class="auth-alert is-error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>

                    <div class="f-row">
                        <div class="f-group">
                            <label class="f-label" for="first_name">First name</label>
                            <div class="f-wrap">
                                <i class="fas fa-user f-ico"></i>
                                <input type="text" id="first_name" name="first_name"
                                       placeholder="John" value="<?= old('first_name') ?>"
                                       autocomplete="given-name" required>
                            </div>
                        </div>
                        <div class="f-group">
                            <label class="f-label" for="last_name">Last name</label>
                            <div class="f-wrap">
                                <i class="fas fa-user f-ico"></i>
                                <input type="text" id="last_name" name="last_name"
                                       placeholder="Doe" value="<?= old('last_name') ?>"
                                       autocomplete="family-name" required>
                            </div>
                        </div>
                    </div>

                    <div class="f-row">
                        <div class="f-group">
                            <label class="f-label" for="phone">Phone</label>
                            <div class="f-wrap">
                                <i class="fas fa-phone f-ico"></i>
                                <input type="tel" id="phone" name="phone"
                                       placeholder="+94 77 000 0000" value="<?= old('phone') ?>"
                                       autocomplete="tel" required>
                            </div>
                        </div>
                        <div class="f-group">
                            <label class="f-label" for="city">City</label>
                            <div class="f-wrap">
                                <i class="fas fa-building f-ico"></i>
                                <input type="text" id="city" name="city"
                                       placeholder="Colombo" value="<?= old('city') ?>"
                                       autocomplete="address-level2" required>
                            </div>
                        </div>
                    </div>

                    <div class="f-row">
                        <div class="f-group">
                            <label class="f-label" for="address">Address</label>
                            <div class="f-wrap">
                                <i class="fas fa-map-marker-alt f-ico"></i>
                                <input type="text" id="address" name="address"
                                       placeholder="Street address" value="<?= old('address') ?>"
                                       autocomplete="street-address" required>
                            </div>
                        </div>
                        <div class="f-group">
                            <label class="f-label" for="country">Country</label>
                            <div class="f-wrap">
                                <i class="fas fa-flag f-ico"></i>
                                <input type="text" id="country" name="country"
                                       placeholder="Sri Lanka" value="<?= old('country') ?>"
                                       autocomplete="country-name" required>
                            </div>
                        </div>
                    </div>

                    <div class="f-divider">Account details</div>

                    <div class="f-group">
                        <label class="f-label" for="signup_email">Email address</label>
                        <div class="f-wrap">
                            <i class="fas fa-envelope f-ico"></i>
                            <input type="email" id="signup_email" name="email"
                                   placeholder="you@example.com" value="<?= old('email') ?>"
                                   autocomplete="email" required>
                        </div>
                    </div>

                    <div class="f-group">
                        <label class="f-label" for="signup_password">Password</label>
                        <div class="f-wrap">
                            <i class="fas fa-lock f-ico"></i>
                            <input type="password" id="signup_password" name="password"
                                   placeholder="Min. 6 characters"
                                   autocomplete="new-password" minlength="6" required class="has-eye">
                            <button type="button" class="eye-btn" onclick="togglePw('signup_password', this)" aria-label="Show password" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="pw-hint">
                            <div class="pw-bar"><div class="pw-fill" id="pwFill"></div></div>
                            <span class="pw-label" id="pwLabel">—</span>
                        </div>
                    </div>

                    <div class="f-group">
                        <label class="f-label" for="confirm_password">Confirm password</label>
                        <div class="f-wrap">
                            <i class="fas fa-shield-alt f-ico"></i>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="Re-enter password"
                                   autocomplete="new-password" required class="has-eye">
                            <button type="button" class="eye-btn" onclick="togglePw('confirm_password', this)" aria-label="Show password" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="signup" class="btn-auth">
                        <i class="fas fa-user-plus"></i> Create Account
                    </button>
                </form>

                <div class="auth-switch">
                    Already have an account?
                    <a onclick="switchTab('login')">Sign in here</a>
                </div>
            </div>

        </div><!-- /auth-body -->
    </div><!-- /auth-card -->

    <script>

    /* ── Password show/hide ── */
    function togglePw(id, btn) {
        var input = document.getElementById(id);
        var icon  = btn.querySelector('i');
        if (input.type === 'password') {
            input.type   = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
            btn.setAttribute('aria-label', 'Hide password');
        } else {
            input.type   = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
            btn.setAttribute('aria-label', 'Show password');
        }
    }

    /* ── Tab switcher ─────────────────────────── */
    function switchTab(tab) {
        var isLogin = (tab === 'login');
        document.getElementById('panelLogin').classList.toggle('active',  isLogin);
        document.getElementById('panelSignup').classList.toggle('active', !isLogin);
        document.getElementById('tabLogin').classList.toggle('active',    isLogin);
        document.getElementById('tabSignup').classList.toggle('active',   !isLogin);
    }

    /* ── Password strength ────────────────────── */
    (function () {
        var pw    = document.getElementById('signup_password');
        var fill  = document.getElementById('pwFill');
        var label = document.getElementById('pwLabel');
        if (!pw) return;

        var levels = [
            { max: 0,          w: '0%',   bg: 'transparent', txt: '—',      col: 'var(--ink-3)' },
            { max: 5,          w: '25%',  bg: '#dc2626',      txt: 'Weak',   col: '#dc2626' },
            { max: 7,          w: '50%',  bg: '#f59e0b',      txt: 'Fair',   col: '#f59e0b' },
            { max: 11,         w: '75%',  bg: '#3b82f6',      txt: 'Good',   col: '#3b82f6' },
            { max: Infinity,   w: '100%', bg: '#10b981',      txt: 'Strong', col: '#10b981' },
        ];

        pw.addEventListener('input', function () {
            var len = this.value.length;
            var lv  = levels[0];
            for (var i = 0; i < levels.length; i++) { if (len <= levels[i].max) { lv = levels[i]; break; } }
            fill.style.width      = lv.w;
            fill.style.background = lv.bg;
            label.textContent     = lv.txt;
            label.style.color     = lv.col;
        });
    })();

    /* ── Confirm password check ───────────────── */
    (function () {
        var pw  = document.getElementById('signup_password');
        var cpw = document.getElementById('confirm_password');
        if (!pw || !cpw) return;

        function check() {
            if (!cpw.value) {
                cpw.classList.remove('is-valid', 'is-invalid');
                cpw.setCustomValidity('');
                return;
            }
            var match = cpw.value === pw.value;
            cpw.classList.toggle('is-valid',   match);
            cpw.classList.toggle('is-invalid', !match);
            cpw.setCustomValidity(match ? '' : 'Passwords do not match');
        }
        cpw.addEventListener('input', check);
        pw.addEventListener('input',  check);
    })();

    /* ── Email format hint ────────────────────── */
    document.querySelectorAll('input[type="email"]').forEach(function (el) {
        el.addEventListener('blur', function () {
            if (!this.value) return;
            var ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value);
            this.classList.toggle('is-valid',   ok);
            this.classList.toggle('is-invalid', !ok);
        });
        el.addEventListener('input', function () {
            this.classList.remove('is-valid', 'is-invalid');
        });
    });
    </script>
</body>
</html>