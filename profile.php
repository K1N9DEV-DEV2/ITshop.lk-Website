<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Database configuration
include 'db.php';

$message = "";
$error = "";
$user_data = [];

// Fetch current user data
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT first_name, last_name, phone, address, city, state, zipcode, country, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 1) {
    $user_data = $result->fetch_assoc();
} else {
    $error = "User not found.";
}
$stmt->close();

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $first_name = htmlspecialchars(trim($_POST['first_name']));
    $last_name = htmlspecialchars(trim($_POST['last_name']));
    $phone = htmlspecialchars(trim($_POST['phone']));
    $address = htmlspecialchars(trim($_POST['address']));
    $city = htmlspecialchars(trim($_POST['city']));
    $state = htmlspecialchars(trim($_POST['state']));
    $zipcode = htmlspecialchars(trim($_POST['zipcode']));
    $country = htmlspecialchars(trim($_POST['country']));
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    // Validation
    if (empty($first_name) || empty($last_name) || empty($phone) || empty($address) || 
        empty($city) || empty($state) || empty($zipcode) || empty($country) || empty($email)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if email already exists for another user
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Email already exists. Please use a different email.";
        } else {
            // Update user profile
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, address = ?, city = ?, state = ?, zipcode = ?, country = ?, email = ? WHERE id = ?");
            $stmt->bind_param("sssssssssi", $first_name, $last_name, $phone, $address, $city, $state, $zipcode, $country, $email, $user_id);
            
            if ($stmt->execute()) {
                $message = "Profile updated successfully!";
                $_SESSION['user_name'] = $first_name;
                $_SESSION['user_email'] = $email;
                
                // Refresh user data
                $user_data['first_name'] = $first_name;
                $user_data['last_name'] = $last_name;
                $user_data['phone'] = $phone;
                $user_data['address'] = $address;
                $user_data['city'] = $city;
                $user_data['state'] = $state;
                $user_data['zipcode'] = $zipcode;
                $user_data['country'] = $country;
                $user_data['email'] = $email;
            } else {
                $error = "Error updating profile. Please try again.";
            }
        }
        $stmt->close();
    }
}

// Handle password change
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_new_password = $_POST['confirm_new_password'];
    
    if (empty($current_password) || empty($new_password) || empty($confirm_new_password)) {
        $error = "Please fill in all password fields.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_new_password) {
        $error = "New passwords do not match.";
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if (password_verify($current_password, $user['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_password, $user_id);
                
                if ($stmt->execute()) {
                    $message = "Password changed successfully!";
                } else {
                    $error = "Error changing password. Please try again.";
                }
            } else {
                $error = "Current password is incorrect.";
            }
        }
        $stmt->close();
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
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: #f5f6fa;
    min-height: 100vh;
    padding: 20px;
}

.navbar {
    background: white;
    padding: 15px 30px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    border-radius: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.navbar .logo {
    display: flex;
    align-items: center;
    gap: 15px;
}

.navbar .logo img {
    height: 40px;
}

.navbar .logo h1 {
    color: #0a9101ff;
    font-size: 1.5rem;
}

.navbar .nav-links {
    display: flex;
    gap: 20px;
    align-items: center;
}

.navbar .nav-links a {
    color: #333;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.3s;
}

.navbar .nav-links a:hover {
    color: #0a9101ff;
}

.navbar .user-info {
    color: #666;
    font-weight: 500;
}

.container {
    max-width: 1000px;
    margin: 0 auto;
}

.profile-header {
    background: white;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    text-align: center;
}

.profile-header h2 {
    color: #333;
    font-size: 2rem;
    margin-bottom: 10px;
}

.profile-header p {
    color: #666;
    font-size: 1rem;
}

.tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.tab-btn {
    padding: 12px 25px;
    background: white;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 500;
    color: #666;
    transition: all 0.3s;
}

.tab-btn.active {
    background: #0a9101ff;
    color: white;
    border-color: #0a9101ff;
}

.tab-btn:hover {
    border-color: #0a9101ff;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-card {
    background: white;
    padding: 40px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.form-card h3 {
    color: #333;
    font-size: 1.5rem;
    margin-bottom: 25px;
}

.form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    flex: 1;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #555;
    font-weight: 500;
    font-size: 0.9rem;
}

.form-group input {
    width: 100%;
    padding: 12px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-group input:focus {
    outline: none;
    border-color: #0098fdff;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.btn {
    padding: 15px 30px;
    background: #0a9101ff;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 10px;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(17, 130, 0, 0.4);
}

.btn-secondary {
    background: #6c757d;
    margin-left: 10px;
}

.btn-secondary:hover {
    box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
}

.alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.alert.error {
    background: #fee;
    color: #c33;
    border: 1px solid #fcc;
}

.alert.success {
    background: #efe;
    color: #363;
    border: 1px solid #cfc;
}

/* Responsive */
@media (max-width: 768px) {
    .navbar {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }

    .navbar .nav-links {
        flex-direction: column;
        gap: 10px;
    }

    .form-row {
        flex-direction: column;
        gap: 0;
    }

    .tabs {
        flex-direction: column;
    }

    .tab-btn {
        width: 100%;
    }

    .form-card {
        padding: 25px 20px;
    }

    .btn {
        width: 100%;
        margin-left: 0;
        margin-top: 10px;
    }
}

@media (max-width: 480px) {
    body {
        padding: 10px;
    }

    .navbar {
        padding: 15px;
    }

    .navbar .logo h1 {
        font-size: 1.2rem;
    }

    .profile-header {
        padding: 20px;
    }

    .profile-header h2 {
        font-size: 1.5rem;
    }

    .form-card {
        padding: 20px 15px;
    }

    .form-card h3 {
        font-size: 1.25rem;
    }
}
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <div class="navbar">
        <div class="logo">
            <img src="assets/revised-04.png" alt="STC Logo">
            <h1>IT Shop.LK</h1>
        </div>
        <div class="nav-links">
            <a href="index.php">Home</a>
            <a href="profile.php">Profile</a>
            <span class="user-info">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Profile Header -->
        <div class="profile-header">
            <h2><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></h2>
            <p><?php echo htmlspecialchars($user_data['email']); ?></p>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="alert success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('profile')">Profile Information</button>
            <button class="tab-btn" onclick="switchTab('password')">Change Password</button>
        </div>

        <!-- Profile Information Tab -->
        <div id="profile-tab" class="tab-content active">
            <div class="form-card">
                <h3>Edit Profile Information</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user_data['phone']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="address">Address</label>
                            <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($user_data['address']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($user_data['city']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="state">State</label>
                            <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($user_data['state']); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="zipcode">Zipcode</label>
                            <input type="text" id="zipcode" name="zipcode" value="<?php echo htmlspecialchars($user_data['zipcode']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="country">Country</label>
                            <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($user_data['country']); ?>" required>
                        </div>
                    </div>

                    <button type="submit" name="update_profile" class="btn">Update Profile</button>
                    <a href="index.php"><button type="button" class="btn btn-secondary">Cancel</button></a>
                </form>
            </div>
        </div>

        <!-- Change Password Tab -->
        <div id="password-tab" class="tab-content">
            <div class="form-card">
                <h3>Change Password</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="confirm_new_password">Confirm New Password</label>
                            <input type="password" id="confirm_new_password" name="confirm_new_password" required>
                        </div>
                    </div>

                    <button type="submit" name="change_password" class="btn">Change Password</button>
                    <a href="index.php"><button type="button" class="btn btn-secondary">Cancel</button></a>
                </form>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));

            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => btn.classList.remove('active'));

            // Show selected tab
            if (tabName === 'profile') {
                document.getElementById('profile-tab').classList.add('active');
                buttons[0].classList.add('active');
            } else if (tabName === 'password') {
                document.getElementById('password-tab').classList.add('active');
                buttons[1].classList.add('active');
            }
        }

        // Password confirmation validation
        document.getElementById('confirm_new_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>