<?php
session_start();

// Database configuration
include 'db.php';

$message = "";
$error = "";

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['login'])) {
        // Login process
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];
        
        if (empty($email) || empty($password)) {
            $error = "Please fill in all fields.";
        } else {
            $stmt = $conn->prepare("SELECT id, email, password, first_name FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['first_name'];
                    header("Location: index.php"); // Redirect to dashboard
                    exit();
                } else {
                    $error = "Invalid email or password.";
                }
            } else {
                $error = "Invalid email or password.";
            }
            $stmt->close();
        }
    } elseif (isset($_POST['signup'])) {
        // Signup process
        $first_name = htmlspecialchars(trim($_POST['first_name']));
        $last_name = htmlspecialchars(trim($_POST['last_name']));
        $phone = htmlspecialchars(trim($_POST['phone']));
        $address = htmlspecialchars(trim($_POST['address']));
        $city = htmlspecialchars(trim($_POST['city']));
        $country = htmlspecialchars(trim($_POST['country']));
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validation
        if (empty($first_name) || empty($last_name) || empty($phone) || empty($address) || 
            empty($city) || empty($country) || 
            empty($email) || empty($password) || empty($confirm_password)) {
            $error = "Please fill in all fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "Email already exists. Please use a different email.";
            } else {
                // Hash password and insert user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, phone, address, city, country, email, password, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssssssss", $first_name, $last_name, $phone, $address, $city, $country, $email, $hashed_password);
                
                if ($stmt->execute()) {
                    $message = "Account created successfully! Please log in.";
                    // Clear form data after successful registration
                    $_POST = array();
                } else {
                    $error = "Error creating account. Please try again. Error: " . $conn->error;
                }
            }
            $stmt->close();
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
    <title>Login & Signup - IT Shop.LK</title>
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: #0a9101ff;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.container {
    background: white;
    border-radius: 15px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    max-width: 800px;
    width: 100%;
    position: relative;
}

.form-container {
    display: flex;
    height: 100%;
}

.form-panel {
    flex: 1;
    padding: 50px 40px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    position: relative;
}

.form-panel.login-panel {
    background: #f8f9fa;
}

.form-panel.signup-panel {
    background: white;
    display: none;
}

.form-panel.active {
    display: flex;
}

h2 {
    color: #333;
    margin-bottom: 30px;
    text-align: center;
    font-size: 2rem;
    font-weight: 300;
}

.form-group {
    margin-bottom: 20px;
    position: relative;
}

.form-row {
    display: flex;
    gap: 15px;
}

.form-row .form-group {
    flex: 1;
    margin-bottom: 20px;
}

label {
    display: block;
    margin-bottom: 8px;
    color: #555;
    font-weight: 500;
    font-size: 0.9rem;
}

input[type="text"],
input[type="email"],
input[type="password"],
input[type="tel"] {
    width: 100%;
    padding: 12px;
    border: 2px solid #e1e5e9;
    border-radius: 8px;
    font-size: 0.95rem;
    transition: all 0.3s ease;
    background: white;
}

input[type="text"]:focus,
input[type="email"]:focus,
input[type="password"]:focus,
input[type="tel"]:focus {
    outline: none;
    border-color: #0098fdff;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.btn {
    width: 100%;
    padding: 15px;
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

.btn:active {
    transform: translateY(0);
}

.toggle-text {
    text-align: center;
    margin-top: 25px;
    color: #666;
    font-size: 0.9rem;
}

.toggle-link {
    color: #0077ffff;
    text-decoration: none;
    font-weight: 600;
    cursor: pointer;
}

.toggle-link:hover {
    text-decoration: underline;
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

.password-strength {
    font-size: 0.8rem;
    margin-top: 5px;
    color: #666;
}

.logo {
    text-align: center;
    margin-bottom: 30px;
}

.logo img {
    max-width: 150px;
    height: auto;
}

.logo h1 {
    color: #0a9101ff;
    font-size: 2rem;
    font-weight: 600;
}

/* Tablet Responsive */
@media (max-width: 768px) {
    body {
        padding: 15px;
        align-items: flex-start;
    }

    .container {
        margin: 0;
        border-radius: 10px;
        max-height: none;
    }

    .form-panel {
        padding: 30px 25px;
        max-height: none;
        overflow-y: visible;
    }

    .form-row {
        flex-direction: column;
        gap: 0;
    }

    .form-row .form-group {
        margin-bottom: 20px;
    }

    h2 {
        font-size: 1.75rem;
        margin-bottom: 25px;
    }

    .logo img {
        max-width: 120px;
    }

    .logo h1 {
        font-size: 1.75rem;
    }

    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="tel"] {
        padding: 14px 12px;
        font-size: 16px; /* Prevents zoom on iOS */
    }

    .btn {
        padding: 16px;
        font-size: 1rem;
    }
}

/* Mobile Responsive */
@media (max-width: 480px) {
    body {
        padding: 10px;
    }

    .container {
        border-radius: 8px;
    }

    .form-panel {
        padding: 25px 20px;
    }

    h2 {
        font-size: 1.5rem;
        margin-bottom: 20px;
    }

    .logo {
        margin-bottom: 20px;
    }

    .logo img {
        max-width: 100px;
    }

    .logo h1 {
        font-size: 1.5rem;
    }

    .form-group {
        margin-bottom: 18px;
    }

    label {
        font-size: 0.85rem;
        margin-bottom: 6px;
    }

    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="tel"] {
        padding: 12px 10px;
        font-size: 16px; /* Prevents zoom on iOS */
        border-radius: 6px;
    }

    .btn {
        padding: 14px;
        font-size: 0.95rem;
        border-radius: 6px;
        margin-top: 8px;
    }

    .alert {
        padding: 10px 12px;
        font-size: 0.85rem;
        margin-bottom: 15px;
    }

    .toggle-text {
        margin-top: 20px;
        font-size: 0.85rem;
    }

    .password-strength {
        font-size: 0.75rem;
    }
}

/* Small Mobile Devices */
@media (max-width: 360px) {
    body {
        padding: 8px;
    }

    .form-panel {
        padding: 20px 15px;
    }

    h2 {
        font-size: 1.35rem;
    }

    .logo img {
        max-width: 90px;
    }

    .btn {
        padding: 13px;
        font-size: 0.9rem;
    }
}
    </style>
</head>
<body>
    <div class="container">
        <div class="form-container">
            <!-- Login Form -->
            <div class="form-panel login-panel active" id="loginPanel">
                <div class="logo">
                    <img src="assets/revised-04.png" alt="STC Logo">
                </div>
                
                <h2>Welcome Back</h2>
                
                <?php if ($error && !isset($_POST['signup'])): ?>
                    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($message): ?>
                    <div class="alert success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="login_email">Email Address</label>
                        <input type="email" id="login_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="login_password">Password</label>
                        <input type="password" id="login_password" name="password" required>
                    </div>
                    
                    <button type="submit" name="login" class="btn">Sign In</button>
                </form>
                
                <div class="toggle-text">
                    Don't have an account? 
                    <a href="#" class="toggle-link" onclick="toggleForm()">Sign up here</a>
                </div>
            </div>
            
            <!-- Signup Form -->
            <div class="form-panel signup-panel" id="signupPanel">
                <div class="logo">
                    <img src="assets/revised-04.png" alt="STC Logo">
                </div>
                
                <h2>Create Account</h2>
                
                <?php if ($error && isset($_POST['signup'])): ?>
                    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="address">Address</label>
                            <input type="text" id="address" name="address" value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="city">City</label>
                            <input type="text" id="city" name="city" value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="country">Country</label>
                            <input type="text" id="country" name="country" value="<?php echo isset($_POST['country']) ? htmlspecialchars($_POST['country']) : ''; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="signup_email">Email Address</label>
                        <input type="email" id="signup_email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="signup_password">Password</label>
                        <input type="password" id="signup_password" name="password" required minlength="6">
                        <div class="password-strength">Password must be at least 6 characters long</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" name="signup" class="btn">Create Account</button>
                </form>
                
                <div class="toggle-text">
                    Already have an account? 
                    <a href="#" class="toggle-link" onclick="toggleForm()">Sign in here</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleForm() {
            const loginPanel = document.getElementById('loginPanel');
            const signupPanel = document.getElementById('signupPanel');
            
            loginPanel.classList.toggle('active');
            signupPanel.classList.toggle('active');
        }

        // Show signup form if there were signup errors
        <?php if ($error && isset($_POST['signup'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            toggleForm();
        });
        <?php endif; ?>

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('signup_password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Password strength indicator
        document.getElementById('signup_password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = this.parentNode.querySelector('.password-strength');
            
            if (password.length === 0) {
                strengthDiv.textContent = 'Password must be at least 6 characters long';
                strengthDiv.style.color = '#666';
            } else if (password.length < 6) {
                strengthDiv.textContent = 'Password too short';
                strengthDiv.style.color = '#c33';
            } else if (password.length < 8) {
                strengthDiv.textContent = 'Password strength: Fair';
                strengthDiv.style.color = '#f90';
            } else {
                strengthDiv.textContent = 'Password strength: Good';
                strengthDiv.style.color = '#3c3';
            }
        });
    </script>
</body>
</html>