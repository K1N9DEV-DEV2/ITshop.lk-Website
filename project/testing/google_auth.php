<?php
// google_auth.php
session_start();
include 'db.php';

// ─── Google OAuth Config ───────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     '989451968033-djkru087qagq8rhrhf43llm89cv0bsli.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI',  'https://itshop.lk/google_auth.php');


$action = $_GET['action'] ?? '';

// ─── STEP 1: Redirect user to Google's OAuth consent screen ───────────────
if ($action === 'init') {
    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
    header("Location: https://accounts.google.com/o/oauth2/v2/auth?$params");
    exit();
}

// ─── STEP 2: Handle Google's callback ─────────────────────────────────────
if ($action === 'callback') {

    // Error returned by Google?
    if (isset($_GET['error'])) {
        header("Location: login.php?error=google_denied");
        exit();
    }

    $code = $_GET['code'] ?? '';
    if (!$code) {
        header("Location: login.php?error=no_code");
        exit();
    }

    // ── Exchange authorization code for access token ──────────────────────
    $tokenResponse = file_get_contents('https://oauth2.googleapis.com/token', false,
        stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'code'          => $code,
                'client_id'     => GOOGLE_CLIENT_ID,
                'client_secret' => GOOGLE_CLIENT_SECRET,
                'redirect_uri'  => GOOGLE_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
            ]),
        ]])
    );

    if (!$tokenResponse) {
        header("Location: login.php?error=token_failed");
        exit();
    }

    $tokenData   = json_decode($tokenResponse, true);
    $accessToken = $tokenData['access_token'] ?? '';

    if (!$accessToken) {
        header("Location: login.php?error=no_access_token");
        exit();
    }

    // ── Fetch user profile from Google ────────────────────────────────────
    $profileResponse = file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false,
        stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer $accessToken\r\n",
        ]])
    );

    if (!$profileResponse) {
        header("Location: login.php?error=profile_failed");
        exit();
    }

    $profile   = json_decode($profileResponse, true);
    $googleId  = $profile['id']             ?? '';
    $email     = $profile['email']          ?? '';
    $firstName = $profile['given_name']     ?? '';
    $lastName  = $profile['family_name']    ?? '';
    $verified  = $profile['verified_email'] ?? false;

    if (!$email || !$googleId) {
        header("Location: login.php?error=incomplete_profile");
        exit();
    }

    if (!$verified) {
        header("Location: login.php?error=email_not_verified");
        exit();
    }

    // ── DATABASE CHECK: Does this Google account already exist? ───────────
    //
    //  CASE 1 – google_id column match  → user previously signed in via Google
    //  CASE 2 – email match only         → user registered manually before;
    //                                      link accounts automatically
    //  CASE 3 – no match                 → brand-new user, auto-register them
    //
    // Make sure your users table has a `google_id` VARCHAR(64) NULL column:
    //   ALTER TABLE users ADD COLUMN google_id VARCHAR(64) NULL AFTER password;
    //   ALTER TABLE users ADD COLUMN avatar_url VARCHAR(512) NULL AFTER google_id;
    // ─────────────────────────────────────────────────────────────────────

    $avatarUrl = $profile['picture'] ?? '';

    // ── CASE 1: Lookup by google_id (returning Google user) ───────────────
    $stmt = $conn->prepare(
        "SELECT id, email, first_name FROM users WHERE google_id = ? LIMIT 1"
    );
    $stmt->bind_param("s", $googleId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // ✅ Existing Google user – just log them in
        $user = $result->fetch_assoc();
        $stmt->close();

        // Optionally refresh their avatar
        $upd = $conn->prepare("UPDATE users SET avatar_url = ? WHERE id = ?");
        $upd->bind_param("si", $avatarUrl, $user['id']);
        $upd->execute();
        $upd->close();

        setUserSession($user['id'], $user['email'], $user['first_name']);
        header("Location: index.php");
        exit();
    }
    $stmt->close();

    // ── CASE 2: Lookup by email (manual account exists) ───────────────────
    $stmt = $conn->prepare(
        "SELECT id, email, first_name FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // ✅ Email already registered manually – link google_id to that account
        $user = $result->fetch_assoc();
        $stmt->close();

        $upd = $conn->prepare(
            "UPDATE users SET google_id = ?, avatar_url = ? WHERE id = ?"
        );
        $upd->bind_param("ssi", $googleId, $avatarUrl, $user['id']);
        $upd->execute();
        $upd->close();

        setUserSession($user['id'], $user['email'], $user['first_name']);
        header("Location: index.php?notice=google_linked");
        exit();
    }
    $stmt->close();

    // ── CASE 3: Brand-new user – auto-register ────────────────────────────
    // Google users don't need a password (set to empty/NULL or a random hash)
    $dummyPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        "INSERT INTO users
            (first_name, last_name, email, password, google_id, avatar_url, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param(
        "ssssss",
        $firstName, $lastName, $email, $dummyPassword, $googleId, $avatarUrl
    );

    if ($stmt->execute()) {
        $newId = $conn->insert_id;
        $stmt->close();

        setUserSession($newId, $email, $firstName);
        header("Location: index.php?notice=welcome");
        exit();
    } else {
        $stmt->close();
        header("Location: login.php?error=register_failed");
        exit();
    }
}

// ─── Unknown action ────────────────────────────────────────────────────────
header("Location: login.php");
exit();

// ─── Helper ───────────────────────────────────────────────────────────────
function setUserSession(int $id, string $email, string $firstName): void {
    session_regenerate_id(true); // Prevent session fixation
    $_SESSION['user_id']    = $id;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name']  = $firstName;
}