<?php
session_start();
include 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$error = "";
$user_id = $_SESSION['user_id'];

// Fetch current user data
$stmt = $conn->prepare("SELECT first_name, last_name, phone, address, city, country, email, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['update_profile'])) {
        $first_name = htmlspecialchars(trim($_POST['first_name']));
        $last_name  = htmlspecialchars(trim($_POST['last_name']));
        $phone      = htmlspecialchars(trim($_POST['phone']));
        $address    = htmlspecialchars(trim($_POST['address']));
        $city       = htmlspecialchars(trim($_POST['city']));
        $country    = htmlspecialchars(trim($_POST['country']));
        $email      = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

        if (empty($first_name) || empty($last_name) || empty($phone) || empty($address) || empty($city) || empty($country) || empty($email)) {
            $error = "Please fill in all fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Check email uniqueness (excluding current user)
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $email, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = "That email is already in use by another account.";
            } else {
                $stmt->close();
                $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone=?, address=?, city=?, country=?, email=? WHERE id=?");
                $stmt->bind_param("sssssssi", $first_name, $last_name, $phone, $address, $city, $country, $email, $user_id);
                if ($stmt->execute()) {
                    $_SESSION['user_name'] = $first_name;
                    $_SESSION['user_email'] = $email;
                    $message = "Profile updated successfully!";
                    // Refresh user data
                    $user = compact('first_name','last_name','phone','address','city','country','email') + ['created_at' => $user['created_at']];
                } else {
                    $error = "Error updating profile. Please try again.";
                }
            }
            $stmt->close();
        }
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password     = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all password fields.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (password_verify($current_password, $row['password'])) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                $stmt->bind_param("si", $hashed, $user_id);
                if ($stmt->execute()) {
                    $message = "Password changed successfully!";
                } else {
                    $error = "Error changing password. Please try again.";
                }
                $stmt->close();
            } else {
                $error = "Current password is incorrect.";
            }
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - IT Shop.LK</title>
    <style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Poppins', sans-serif;
    background: #f0f2f5;
    min-height: 100vh;
    padding: 20px;
}

/* ── Top Nav ── */
.navbar {
    background: #0a9101ff;
    color: white;
    padding: 15px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(10,145,1,.25);
}
.navbar .logo { display: flex; align-items: center; gap: 12px; }
.navbar .logo img { height: 40px; }
.navbar .logo span { font-size: 1.4rem; font-weight: 600; }
.navbar .nav-links { display: flex; gap: 20px; align-items: center; }
.navbar .nav-links a {
    color: rgba(255,255,255,.85);
    text-decoration: none;
    font-size: .9rem;
    padding: 6px 14px;
    border-radius: 6px;
    transition: background .2s;
}
.navbar .nav-links a:hover,
.navbar .nav-links a.active { background: rgba(255,255,255,.2); color: #fff; }
.navbar .nav-links .logout {
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.3);
}

/* ── Page layout ── */
.page-wrapper {
    max-width: 900px;
    margin: 0 auto;
}

.page-title {
    font-size: 1.6rem;
    font-weight: 600;
    color: #333;
    margin-bottom: 6px;
}
.page-subtitle { color: #888; font-size: .9rem; margin-bottom: 25px; }

/* ── Card ── */
.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0,0,0,.07);
    overflow: hidden;
    margin-bottom: 20px;
}
.card-header {
    background: linear-gradient(135deg, #0a9101ff, #05c200);
    padding: 20px 30px;
    color: white;
}
.card-header h3 { font-size: 1.1rem; font-weight: 600; }
.card-header p  { font-size: .85rem; opacity: .85; margin-top: 3px; }
.card-body { padding: 30px; }

/* ── Profile avatar block ── */
.profile-header {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 25px 30px;
    border-bottom: 1px solid #f0f2f5;
}
.avatar {
    width: 70px; height: 70px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0a9101ff, #05c200);
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 1.8rem; font-weight: 700;
    flex-shrink: 0;
}
.profile-meta h4 { font-size: 1.15rem; color: #333; }
.profile-meta p  { color: #888; font-size: .85rem; margin-top: 2px; }
.member-badge {
    margin-top: 6px;
    display: inline-block;
    background: #e8f5e9;
    color: #0a9101ff;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: .75rem;
    font-weight: 600;
}

/* ── Form styles ── */
.form-row { display: flex; gap: 15px; }
.form-row .form-group { flex: 1; }
.form-group { margin-bottom: 18px; }

label {
    display: block;
    margin-bottom: 7px;
    color: #555;
    font-weight: 500;
    font-size: .875rem;
}
input[type="text"],
input[type="email"],
input[type="password"],
input[type="tel"] {
    width: 100%;
    padding: 11px 14px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    font-size: .93rem;
    font-family: inherit;
    transition: border-color .3s, box-shadow .3s;
    background: #fafafa;
}
input:focus {
    outline: none;
    border-color: #0098fdff;
    background: white;
    box-shadow: 0 0 0 3px rgba(0,152,253,.1);
}
input[readonly] { background: #f5f5f5; color: #888; cursor: not-allowed; }

.btn {
    padding: 12px 28px;
    background: #0a9101ff;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: .95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .3s;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(10,145,1,.35);
}
.btn-outline {
    background: transparent;
    border: 2px solid #0a9101ff;
    color: #0a9101ff;
    margin-left: 10px;
}
.btn-outline:hover {
    background: #0a9101ff;
    color: white;
    box-shadow: 0 6px 20px rgba(10,145,1,.25);
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: .9rem;
    font-weight: 500;
}
.alert.error   { background: #fee; color: #c33; border: 1px solid #fcc; }
.alert.success { background: #efe; color: #363; border: 1px solid #cfc; }

/* ── Info tiles ── */
.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 0;
}
.info-tile {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 16px 18px;
    border-left: 4px solid #0a9101ff;
}
.info-tile .label { font-size: .75rem; color: #888; text-transform: uppercase; letter-spacing: .5px; }
.info-tile .value { font-size: .95rem; color: #333; font-weight: 600; margin-top: 4px; word-break: break-word; }

/* ── Tabs ── */
.tabs { display: flex; gap: 5px; margin-bottom: 25px; }
.tab-btn {
    padding: 9px 22px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    background: white;
    color: #666;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    font-family: inherit;
}
.tab-btn.active {
    background: #0a9101ff;
    border-color: #0a9101ff;
    color: white;
}
.tab-btn:hover:not(.active) { border-color: #0a9101ff; color: #0a9101ff; }

.tab-pane { display: none; }
.tab-pane.active { display: block; }

/* ── Responsive ── */
@media (max-width: 700px) {
    .navbar { flex-direction: column; gap: 12px; border-radius: 10px; }
    .navbar .nav-links { flex-wrap: wrap; justify-content: center; }
    .form-row { flex-direction: column; gap: 0; }
    .card-body, .card-header { padding: 20px; }
    .profile-header { flex-direction: column; text-align: center; }
    .tabs { flex-wrap: wrap; }
    .btn-outline { margin-left: 0; margin-top: 10px; }
}
@media (max-width: 480px) {
    body { padding: 10px; }
    .page-title { font-size: 1.3rem; }
    input[type="text"],input[type="email"],input[type="password"],input[type="tel"] { font-size: 16px; }
}
    </style>
</head>
<body>
<!-- Navbar -->
<nav class="navbar">
    <div class="logo">
        <img src="assets/revised-04.png" alt="IT Shop.LK">
        <span>IT Shop.LK</span>
    </div>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="myorders.php">My Orders</a>
        <a href="profile.php" class="active">Profile</a>
        <a href="logout.php" class="logout">Logout</a>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-title">My Profile</div>
    <div class="page-subtitle">Manage your account details and security settings</div>

    <?php if ($message): ?>
        <div class="alert success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Profile header card -->
    <div class="card">
        <div class="profile-header">
            <div class="avatar"><?php echo strtoupper(substr($user['first_name'],0,1) . substr($user['last_name'],0,1)); ?></div>
            <div class="profile-meta">
                <h4><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                <p><?php echo htmlspecialchars($user['email']); ?></p>
                <span class="member-badge">Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-tile">
                    <div class="label">Phone</div>
                    <div class="value"><?php echo htmlspecialchars($user['phone']); ?></div>
                </div>
                <div class="info-tile">
                    <div class="label">City</div>
                    <div class="value"><?php echo htmlspecialchars($user['city']); ?></div>
                </div>
                <div class="info-tile">
                    <div class="label">Country</div>
                    <div class="value"><?php echo htmlspecialchars($user['country']); ?></div>
                </div>
                <div class="info-tile">
                    <div class="label">Address</div>
                    <div class="value"><?php echo htmlspecialchars($user['address']); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('edit')">Edit Profile</button>
        <button class="tab-btn" onclick="switchTab('password')">Change Password</button>
    </div>

    <!-- Edit Profile -->
    <div class="tab-pane active" id="tab-edit">
        <div class="card">
            <div class="card-header">
                <h3>Edit Profile Information</h3>
                <p>Update your personal details below</p>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($user['address']); ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" value="<?php echo htmlspecialchars($user['city']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" value="<?php echo htmlspecialchars($user['country']); ?>" required>
                        </div>
                    </div>
                    <button type="submit" name="update_profile" class="btn">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password -->
    <div class="tab-pane" id="tab-password">
        <div class="card">
            <div class="card-header">
                <h3>Change Password</h3>
                <p>Keep your account secure with a strong password</p>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" id="new_password" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required>
                    </div>
                    <button type="submit" name="change_password" class="btn">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.target.classList.add('active');
}

// Keep password tab open if there was a password-related error/success
<?php if (isset($_POST['change_password'])): ?>
document.addEventListener('DOMContentLoaded', () => switchTab('password'));
<?php endif; ?>

document.getElementById('confirm_password').addEventListener('input', function() {
    const pw = document.getElementById('new_password').value;
    this.setCustomValidity(this.value !== pw ? 'Passwords do not match' : '');
});
</script>
</body>
</html>