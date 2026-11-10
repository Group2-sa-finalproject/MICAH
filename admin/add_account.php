
<?php
session_start();
require_once "../config/database.php";

// Check admin access
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

$success = "";
$error = "";
$fullname = "";
$username = "";
$role = "";

// Generate CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Process registration
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please try again.";

    } else {

        $fullname = trim($_POST["fullname"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";
        $confirm_password = $_POST["confirm_password"] ?? "";
        $role = $_POST["role"] ?? "";

        if (
            $fullname === "" ||
            $username === "" ||
            $password === "" ||
            $confirm_password === "" ||
            $role === ""
        ) {
            $error = "Please fill in all fields.";

        } elseif (!in_array($role, ["admin", "collector", "residents"], true)) {
            $error = "Invalid account type.";

        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";

        } else {

            // Check duplicate username
            $check = $conn->prepare(
                "SELECT id FROM users WHERE users = ? LIMIT 1"
            );

            if (!$check) {
                $error = "Database error: " . $conn->error;

            } else {

                $check->bind_param("s", $username);
                $check->execute();
                $result = $check->get_result();

                if ($result->num_rows > 0) {

                    $error = "Username already exists.";

                } else {

                    // Hash password
                    $hashed_password = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    // Insert account
                    $stmt = $conn->prepare(
                        "INSERT INTO users (fullname, users, password, role)
                         VALUES (?, ?, ?, ?)"
                    );

                    if (!$stmt) {

                        $error = "Database error: " . $conn->error;

                    } else {

                        $stmt->bind_param(
                            "ssss",
                            $fullname,
                            $username,
                            $hashed_password,
                            $role
                        );

                        if ($stmt->execute()) {

                            $success = "Account successfully registered!";

                            $fullname = "";
                            $username = "";
                            $role = "";

                            $_SESSION["csrf_token"] =
                                bin2hex(random_bytes(32));

                        } else {

                            $error = "Registration failed: " . $stmt->error;

                        }

                        $stmt->close();
                    }
                }

                $check->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>EcoTrack | Add Account</title>

<link rel="preconnect" href="https://fonts.googleapis.com">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>

/* =====================================
   GLOBAL
===================================== */

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background: #f4f8f5;
    color: #193c2a;
    min-height: 100vh;
}

/* =====================================
   TOP NAVIGATION
===================================== */

.topbar {
    height: 70px;
    background: #ffffff;
    border-bottom: 1px solid #e5eee8;

    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 0 6%;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.brand-icon {
    width: 38px;
    height: 38px;
    background: #145b35;
    color: white;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;
}

.brand-name {
    font-size: 19px;
    font-weight: 800;
    color: #145b35;
}

.brand-name span {
    color: #20a45b;
}

.admin-label {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;

    background: #f0f7f2;
    padding: 9px 15px;
    border-radius: 20px;
}

/* =====================================
   MAIN CONTAINER
===================================== */

.main-container {
    max-width: 1050px;
    margin: 45px auto;
    padding: 0 25px;
}

/* Back button */

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    margin-bottom: 25px;

    color: #17643b;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;

    transition: 0.2s;
}

.back-link:hover {
    color: #20a45b;
    transform: translateX(-3px);
}

/* =====================================
   PAGE HEADING
===================================== */

.page-heading {
    margin-bottom: 28px;
}

.heading-tag {
    display: inline-block;

    background: #dcfce7;
    color: #166534;

    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.6px;

    padding: 7px 12px;
    border-radius: 20px;

    margin-bottom: 12px;
}

.page-heading h1 {
    font-size: 29px;
    font-weight: 800;
    letter-spacing: -0.8px;
    color: #145b35;

    margin-bottom: 9px;
}

.page-heading p {
    font-size: 13px;
    color: #718096;
    line-height: 1.6;
}

/* =====================================
   FORM CARD
===================================== */

.form-card {
    display: grid;
    grid-template-columns: 32% 68%;

    background: white;
    border: 1px solid #e6eee8;
    border-radius: 18px;

    overflow: hidden;

    box-shadow: 0 8px 30px rgba(20, 91, 53, 0.06);
}

/* Left information panel */

.info-panel {
    background: linear-gradient(145deg, #17643a, #084527);

    padding: 38px 27px;
    color: white;

    position: relative;
    overflow: hidden;
}

.info-panel::after {
    content: "";
    position: absolute;

    width: 240px;
    height: 240px;

    border: 35px solid rgba(255,255,255,0.06);
    border-radius: 50%;

    right: -125px;
    bottom: -100px;
}

.info-icon {
    width: 55px;
    height: 55px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: rgba(255,255,255,0.13);
    border: 1px solid rgba(255,255,255,0.2);

    border-radius: 15px;
    font-size: 25px;

    margin-bottom: 27px;
}

.info-panel h2 {
    font-size: 23px;
    line-height: 1.35;
    font-weight: 800;

    margin-bottom: 14px;
}

.info-panel p {
    font-size: 12px;
    line-height: 1.8;
    color: #d5f1df;
}

.info-list {
    list-style: none;
    margin-top: 32px;
}

.info-list li {
    font-size: 11px;
    color: #e4f6e9;

    margin-bottom: 17px;

    display: flex;
    align-items: center;
    gap: 9px;
}

.info-list span {
    color: #55e28d;
    font-size: 15px;
}

/* =====================================
   FORM CONTENT
===================================== */

.form-content {
    padding: 38px 45px;
}

.form-title {
    margin-bottom: 28px;
}

.form-title h2 {
    font-size: 20px;
    color: #164c30;
    font-weight: 800;
    margin-bottom: 7px;
}

.form-title p {
    font-size: 12px;
    color: #8492a6;
}

/* Alerts */

.alert {
    padding: 13px 15px;
    border-radius: 10px;
    margin-bottom: 22px;

    font-size: 12px;
    line-height: 1.5;
}

.alert-success {
    color: #166534;
    background: #dcfce7;
    border: 1px solid #bbf7d0;
}

.alert-danger {
    color: #991b1b;
    background: #fee2e2;
    border: 1px solid #fecaca;
}

/* Form groups */

.form-group {
    margin-bottom: 21px;
}

.form-group label {
    display: block;

    color: #315541;
    font-size: 12px;
    font-weight: 700;

    margin-bottom: 9px;
}

.form-group input,
.form-group select {
    display: block;

    width: 100%;
    height: 46px;

    padding: 0 14px;

    border: 1px solid #dce7df;
    border-radius: 10px;

    background: #ffffff;

    color: #253e30;

    font-family: 'Inter', sans-serif;
    font-size: 12px;

    outline: none;
    transition: 0.2s;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #168348;
    box-shadow: 0 0 0 3px rgba(22,131,72,0.09);
}

.form-group input::placeholder {
    color: #a0acb9;
}

/* Two column fields */

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 17px;
}

/* Password note */

.password-note {
    color: #8492a6;
    font-size: 10px;
    margin-top: -10px;
    margin-bottom: 20px;
}

/* =====================================
   BUTTONS
===================================== */

.form-actions {
    display: flex;
    align-items: center;
    gap: 12px;

    margin-top: 28px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;

    min-height: 45px;
    padding: 0 23px;

    border-radius: 10px;
    border: none;

    font-family: 'Inter', sans-serif;
    font-size: 12px;
    font-weight: 800;

    cursor: pointer;
    text-decoration: none;

    transition: 0.2s;
}

.btn-submit {
    background: #146338;
    color: white;

    box-shadow: 0 5px 13px rgba(20,99,56,0.17);
}

.btn-submit:hover {
    background: #0c4b2b;
    transform: translateY(-2px);
}

.btn-cancel {
    background: #f1f5f2;
    color: #536b5c;
}

.btn-cancel:hover {
    background: #e4eee7;
}

/* =====================================
   FOOTER
===================================== */

.footer {
    text-align: center;
    margin-top: 30px;

    font-size: 10px;
    color: #9aa9a0;
}

.footer strong {
    color: #17643a;
}

/* =====================================
   RESPONSIVE
===================================== */

@media (max-width: 800px) {

    .form-card {
        grid-template-columns: 1fr;
    }

    .info-panel {
        padding: 28px;
    }

    .info-list {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 20px;
    }

    .info-list li {
        margin-bottom: 5px;
    }

    .form-content {
        padding: 30px;
    }
}

@media (max-width: 500px) {

    .topbar {
        padding: 0 20px;
    }

    .main-container {
        margin: 25px auto;
        padding: 0 15px;
    }

    .page-heading h1 {
        font-size: 24px;
    }

    .form-content {
        padding: 25px 20px;
    }

    .form-row {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .form-actions {
        flex-direction: column;
    }

    .form-actions .btn {
        width: 100%;
    }
}

</style>

</head>

<body>

<!-- TOP NAVIGATION -->

<header class="topbar">

    <div class="brand">

        <span class="brand-icon">
            <svg viewBox="0 0 24 24" width="23" height="23"
                fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round">

                <path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.5 19 2c1 2 2 4.2 2 8 0 5.5-4.8 10-10 10Z"/>
                <path d="M2 21c0-3 1.9-5.5 6-7"/>

            </svg>
        </span>

        <span class="brand-name">
            Eco<span>Track</span>
        </span>

    </div>

    <span class="admin-label">
        ✦ Administrator Panel
    </span>

</header>


<!-- MAIN CONTENT -->

<main class="main-container">

    <!-- BACK TO DASHBOARD -->

    <a href="dashboard.php" class="back-link">
        ← Back to Dashboard
    </a>


    <!-- PAGE HEADING -->

    <div class="page-heading">

        <span class="heading-tag">
            Account Management
        </span>

        <h1>Register New Account</h1>

        <p>
            Create a new EcoTrack account for administrators,
            garbage collectors, or residents.
        </p>

    </div>


    <!-- FORM CARD -->

    <div class="form-card">


        <!-- LEFT INFORMATION PANEL -->

        <aside class="info-panel">

            <div class="info-icon">
                ♙
            </div>

            <h2>
                Grow Your EcoTrack Community
            </h2>

            <p>
                Register new users and provide them access
                to the EcoTrack waste management platform.
            </p>

            <ul class="info-list">

                <li>
                    <span>✓</span>
                    Secure account registration
                </li>

                <li>
                    <span>✓</span>
                    Protected user information
                </li>

                <li>
                    <span>✓</span>
                    Role-based account access
                </li>

            </ul>

        </aside>


        <!-- RIGHT FORM PANEL -->

        <section class="form-content">

            <div class="form-title">

                <h2>Account Information</h2>

                <p>
                    Fill in the required details below.
                </p>

            </div>


            <!-- SUCCESS MESSAGE -->

            <?php if ($success !== "") { ?>

                <div class="alert alert-success">
                    ✓ <?php echo htmlspecialchars($success); ?>
                </div>

            <?php } ?>


            <!-- ERROR MESSAGE -->

            <?php if ($error !== "") { ?>

                <div class="alert alert-danger">
                    ⚠ <?php echo htmlspecialchars($error); ?>
                </div>

            <?php } ?>


            <!-- REGISTRATION FORM -->

            <form method="POST" action="add_account.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                >


                <!-- FULL NAME -->

                <div class="form-group">

                    <label for="fullname">
                        Full Name
                    </label>

                    <input
                        type="text"
                        id="fullname"
                        name="fullname"
                        placeholder="Enter complete name"
                        value="<?php echo htmlspecialchars($fullname); ?>"
                        required
                    >

                </div>


                <!-- USERNAME -->

                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Choose a username"
                        value="<?php echo htmlspecialchars($username); ?>"
                        required
                    >

                </div>


                <!-- ACCOUNT TYPE -->

                <div class="form-group">

                    <label for="role">
                        Account Type
                    </label>

                    <select id="role" name="role" required>

                        <option value="">
                            Select Account Type
                        </option>

                        <option value="admin"
                            <?php echo ($role === "admin") ? "selected" : ""; ?>>
                            Administrator
                        </option>

                        <option value="collector"
                            <?php echo ($role === "collector") ? "selected" : ""; ?>>
                            Garbage Collector
                        </option>

                        <option value="residents"
                            <?php echo ($role === "residents") ? "selected" : ""; ?>>
                            Resident
                        </option>

                    </select>

                </div>


                <!-- PASSWORD ROW -->

                <div class="form-row">

                    <div class="form-group">

                        <label for="password">
                            Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter password"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="confirm_password">
                            Confirm Password
                        </label>

                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Confirm password"
                            required
                        >

                    </div>

                </div>

                <p class="password-note">
                    Make sure both password fields match.
                </p>


                <!-- FORM BUTTONS -->

                <div class="form-actions">

                    <button
                        type="submit"
                        name="register"
                        class="btn btn-submit"
                    >
                        Register Account 
                    </button>

                    <a href="dashboard.php" class="btn btn-cancel">
                        Cancel
                    </a>

                </div>

            </form>

        </section>

    </div>


    <!-- FOOTER -->

    <footer class="footer">

        © 2026 <strong>EcoTrack</strong> · Smart Waste Management

    </footer>

</main>

</body>
</html>
