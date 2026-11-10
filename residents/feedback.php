
<?php
session_start();

include "../config/database.php";

// Check if the user is logged in as a resident
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "residents"
) {
    header("Location: ../index.php");
    exit;
}

// Get resident ID from session
$user_id = (int) $_SESSION["user_id"];

// Set database charset
if ($conn instanceof mysqli) {
    $conn->set_charset("utf8mb4");
} else {
    die("Database connection failed.");
}

// Generate CSRF token
if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$feedback_type = "";
$message = "";
$error = "";
$resident_name = "Resident";

// Get resident information
$name_stmt = $conn->prepare("
    SELECT fullname
    FROM users
    WHERE id = ?
      AND role = 'residents'
    LIMIT 1
");

if ($name_stmt) {
    $name_stmt->bind_param("i", $user_id);
    $name_stmt->execute();

    $name_result = $name_stmt->get_result();

    if ($resident = $name_result->fetch_assoc()) {
        $resident_name = $resident["fullname"];
    }

    $name_stmt->close();
}

// Check if feedback was successfully submitted
$success = isset($_GET["sent"]) && $_GET["sent"] == "1";

// Process feedback submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $feedback_type = trim($_POST["feedback_type"] ?? "");
    $message = trim($_POST["message"] ?? "");
    $csrf_token = $_POST["csrf_token"] ?? "";

    // Validate CSRF token
    if (!hash_equals($_SESSION["csrf_token"], $csrf_token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    }

    // Validate feedback type
    elseif (!in_array($feedback_type, [
        "Complaint",
        "Suggestion",
        "Satisfaction"
    ], true)) {
        $error = "Please select a valid feedback type.";
    }

    // Validate message
    elseif ($message === "") {
        $error = "Please enter your feedback message.";
    }

    else {

        // Insert feedback into database
        $sql = "INSERT INTO feedback (user_id, feedback_type, message)
                VALUES (?, ?, ?)";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "iss",
                $user_id,
                $feedback_type,
                $message
            );

            if (mysqli_stmt_execute($stmt)) {

                // Generate a new CSRF token
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                // Redirect after successful submission
                header("Location: feedback.php?sent=1");
                exit;

            } else {
                $error = "Unable to submit your feedback. Please try again.";
            }

            mysqli_stmt_close($stmt);

        } else {
            $error = "Database query failed. Please check your feedback table.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Send Feedback | EcoTrack</title>

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">

    <!-- Dashboard CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <!-- Font Awesome -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>

        /* =========================================
           GENERAL
        ========================================= */

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f7f5;
            font-family: Arial, Helvetica, sans-serif;
            color: #263b30;
        }

        a {
            text-decoration: none;
        }

        /* =========================================
           SIDEBAR
        ========================================= */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            height: 100vh;
            background: #123d2b;
            color: #fff;
            padding: 25px 15px;
            overflow-y: auto;
            z-index: 1000;
        }

        .sidebar-logo {
            text-align: center;
            margin-bottom: 35px;
        }

        .sidebar-logo h3 {
            font-size: 23px;
            font-weight: 800;
            color: #fff;
            margin: 0;
        }

        .sidebar-logo i {
            color: #9be7ad;
            margin-right: 7px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin-bottom: 8px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 15px;
            color: #d8e8df;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: .2s ease;
        }

        .sidebar-menu a i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-menu a:hover {
            background: rgba(255,255,255,.12);
            color: #fff;
        }

        .sidebar-menu a.active {
            background: #fff;
            color: #123d2b;
        }

        .sidebar-menu .logout-link {
            margin-top: 25px;
            color: #ffcccc;
        }

        .sidebar-menu .logout-link:hover {
            background: rgba(255,255,255,.12);
            color: #fff;
        }

        /* =========================================
           MAIN CONTENT
        ========================================= */

        .main-content {
            margin-left: 250px;
            min-height: 100vh;
            width: calc(100% - 250px);
        }

        .topbar {
            background: #fff;
            padding: 18px 35px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            border-bottom: 1px solid #e5eae6;
            min-height: 75px;
        }

        .resident-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-icon {
            width: 40px;
            height: 40px;
            background: #e3f3e8;
            color: #17643b;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 17px;
        }

        .profile-name {
            font-size: 14px;
            font-weight: 700;
            color: #183d2b;
        }

        .profile-role {
            display: block;
            font-size: 11px;
            color: #819087;
            font-weight: 500;
        }

        .content {
            padding: 32px 35px;
            width: 100%;
            min-width: 0;
        }

        /* =========================================
           PAGE HEADING
        ========================================= */

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h2 {
            font-size: 26px;
            color: #183d2b;
            font-weight: 800;
            margin-bottom: 7px;
        }

        .page-heading p {
            color: #78877e;
            font-size: 14px;
            margin: 0;
        }

        /* =========================================
           FEEDBACK CARD
        ========================================= */

        .content-card {
            background: #fff;
            padding: 32px;
            border-radius: 13px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
            border: 1px solid #edf1ee;
            width: 100%;
            margin-bottom: 22px;
        }

        .section-heading {
            color: #183d2b;
            font-size: 19px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .section-description {
            color: #78877e;
            font-size: 13px;
            margin-bottom: 28px;
        }

        /* =========================================
           FORM LAYOUT
        ========================================= */

        .feedback-form-grid {
            display: grid;
            grid-template-columns: minmax(230px, 0.8fr) minmax(350px, 1.5fr);
            gap: 25px;
            align-items: start;
        }

        .feedback-form-column {
            min-width: 0;
        }

        .feedback-form-actions {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            padding-top: 5px;
            border-top: 1px solid #edf1ee;
            margin-top: 5px;
        }

        /* =========================================
           FORM CONTROLS
        ========================================= */

        .form-label {
            font-weight: 700;
            color: #40594a;
            font-size: 13px;
            margin-bottom: 9px;
        }

        .form-control,
        .form-select {
            width: 100%;
            border: 1px solid #dce5df;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            color: #34483b;
            background-color: #fff;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #17643b;
            box-shadow: 0 0 0 .15rem rgba(23,100,59,.12);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 190px;
            line-height: 1.6;
        }

        .form-hint {
            display: block;
            color: #89968e;
            font-size: 11px;
            margin-top: 8px;
        }

        /* =========================================
           FEEDBACK TYPE INFO
        ========================================= */

        .feedback-info {
            margin-top: 22px;
            padding: 17px;
            background: #f5faf6;
            border: 1px solid #e3eee6;
            border-radius: 9px;
        }

        .feedback-info h6 {
            color: #183d2b;
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 13px;
        }

        .feedback-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
        }

        .feedback-info-item:last-child {
            margin-bottom: 0;
        }

        .feedback-info-item i {
            color: #17643b;
            font-size: 14px;
            margin-top: 3px;
            width: 17px;
            text-align: center;
        }

        .feedback-info-item strong {
            display: block;
            color: #40594a;
            font-size: 12px;
            margin-bottom: 3px;
        }

        .feedback-info-item span {
            display: block;
            color: #819087;
            font-size: 11px;
            line-height: 1.5;
        }

        /* =========================================
           BUTTONS
        ========================================= */

        .btn-ecotrack {
            background: #17643b;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 13px;
            font-weight: 700;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .btn-ecotrack:hover {
            background: #104b2c;
            color: #fff;
        }

        .btn-outline-ecotrack {
            border: 1px solid #17643b;
            color: #17643b;
            background: #fff;
            border-radius: 8px;
            padding: 11px 19px;
            font-size: 13px;
            font-weight: 700;
            transition: .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .btn-outline-ecotrack:hover {
            background: #17643b;
            color: #fff;
        }

        /* =========================================
           ALERTS
        ========================================= */

        .alert {
            border-radius: 10px;
            font-size: 14px;
        }

        /* =========================================
           FOOTER
        ========================================= */

        .page-footer {
            margin-top: 30px;
            padding: 20px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
            width: 100%;
        }

        /* =========================================
           RESPONSIVE
        ========================================= */

        @media (max-width: 1000px) {

            .content {
                padding: 25px 22px;
            }

            .feedback-form-grid {
                grid-template-columns: 1fr 1.3fr;
                gap: 18px;
            }

        }

        @media (max-width: 700px) {

            .sidebar {
                width: 70px;
                padding: 20px 8px;
            }

            .sidebar-logo h3 {
                font-size: 0;
            }

            .sidebar-logo i {
                font-size: 25px;
                margin: 0;
            }

            .sidebar-menu a {
                justify-content: center;
                padding: 13px 8px;
            }

            .sidebar-menu a span {
                display: none;
            }

            .sidebar-menu a i {
                font-size: 18px;
                width: auto;
            }

            .main-content {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .topbar {
                padding: 15px;
            }

            .content {
                padding: 20px 13px;
            }

            .page-heading h2 {
                font-size: 21px;
            }

            .content-card {
                padding: 20px 17px;
            }

            .feedback-form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .feedback-form-actions {
                grid-column: 1;
                flex-direction: column;
            }

            .feedback-form-actions a,
            .feedback-form-actions button {
                width: 100%;
            }

            textarea.form-control {
                min-height: 150px;
            }

        }

    </style>

</head>

<body>

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="sidebar-logo">
            <h3>
                <i class="fas fa-recycle"></i>
                EcoTrack
            </h3>
        </div>

        <ul class="sidebar-menu">

            <li>
                <a href="dashboard.php">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li>
                <a href="eco_points.php">
                    <i class="fas fa-coins"></i>
                    <span>Eco Points</span>
                </a>
            </li>

            <li>
                <a href="collection_schedule.php">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Collection Schedule</span>
                </a>
            </li>

            <li>
                <a href="track_truck.php">
                    <i class="fas fa-truck"></i>
                    <span>Track Garbage Truck</span>
                </a>
            </li>

            <li>
                <a href="subscription.php">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Subscription</span>
                </a>
            </li>

            <li>
                <a href="register_household.php">
                    <i class="fas fa-house-user"></i>
                    <span>Register Household</span>
                </a>
            </li>

            <li>
                <a href="feedback.php" class="active">
                    <i class="fas fa-comment-dots"></i>
                    <span>Feedback</span>
                </a>
            </li>

            <li>
                <a href="qrcode.php">
                    <i class="fas fa-qrcode"></i>
                    <span>Resident QR Code</span>
                </a>
            </li>

            <li>
                <a href="../logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </li>

        </ul>

    </aside>


    <!-- MAIN CONTENT -->

    <div class="main-content">

        <!-- TOPBAR -->

        <div class="topbar">

            <div class="resident-profile">

                <div class="profile-icon">
                    <i class="fas fa-user"></i>
                </div>

                <div>

                    <div class="profile-name">
                        <?php echo htmlspecialchars($resident_name); ?>
                    </div>

                    <span class="profile-role">
                        Resident
                    </span>

                </div>

            </div>

        </div>


        <!-- PAGE CONTENT -->

        <main class="content">

            <!-- PAGE HEADING -->

            <div class="page-heading">

                <h2>
                    <i class="fas fa-comment-dots me-2"></i>
                    Send Feedback
                </h2>

                <p>
                    Share your complaints, suggestions, or satisfaction
                    about our waste collection services.
                </p>

            </div>


            <!-- SUCCESS MESSAGE -->

            <?php if ($success): ?>

                <div class="alert alert-success alert-dismissible fade show">

                    <i class="fas fa-check-circle me-2"></i>

                    Your feedback has been submitted successfully!

                    <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"></button>

                </div>

            <?php endif; ?>


            <!-- ERROR MESSAGE -->

            <?php if ($error !== ""): ?>

                <div class="alert alert-danger alert-dismissible fade show">

                    <i class="fas fa-exclamation-circle me-2"></i>

                    <?php echo htmlspecialchars($error); ?>

                    <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"></button>

                </div>

            <?php endif; ?>


            <!-- FEEDBACK FORM CARD -->

            <section class="content-card">

                <h4 class="section-heading">
                    <i class="fas fa-pen-to-square me-2"></i>
                    Feedback Form
                </h4>

                <p class="section-description">
                    We value your feedback. Please provide your comments
                    to help us improve our waste collection services.
                </p>


                <form method="POST" action="feedback.php">

                    <!-- CSRF TOKEN -->

                    <input type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">


                    <div class="feedback-form-grid">

                        <!-- LEFT COLUMN -->

                        <div class="feedback-form-column">

                            <div class="mb-3">

                                <label for="feedback_type" class="form-label">
                                    Feedback Type
                                </label>

                                <select
                                    name="feedback_type"
                                    id="feedback_type"
                                    class="form-select"
                                    required>

                                    <option value="">
                                        Select Feedback Type
                                    </option>

                                    <option value="Complaint"
                                        <?php echo $feedback_type === "Complaint" ? "selected" : ""; ?>>
                                        Complaint
                                    </option>

                                    <option value="Suggestion"
                                        <?php echo $feedback_type === "Suggestion" ? "selected" : ""; ?>>
                                        Suggestion
                                    </option>

                                    <option value="Satisfaction"
                                        <?php echo $feedback_type === "Satisfaction" ? "selected" : ""; ?>>
                                        Satisfaction
                                    </option>

                                </select>

                            </div>


                            <!-- FEEDBACK TYPE GUIDE -->

                            <div class="feedback-info">

                                <h6>
                                    <i class="fas fa-circle-info me-1"></i>
                                    Feedback Guide
                                </h6>

                                <div class="feedback-info-item">

                                    <i class="fas fa-triangle-exclamation"></i>

                                    <div>
                                        <strong>Complaint</strong>
                                        <span>
                                            Report problems or concerns about waste collection.
                                        </span>
                                    </div>

                                </div>

                                <div class="feedback-info-item">

                                    <i class="fas fa-lightbulb"></i>

                                    <div>
                                        <strong>Suggestion</strong>
                                        <span>
                                            Share ideas that can improve our services.
                                        </span>
                                    </div>

                                </div>

                                <div class="feedback-info-item">

                                    <i class="fas fa-face-smile"></i>

                                    <div>
                                        <strong>Satisfaction</strong>
                                        <span>
                                            Tell us about your experience with our services.
                                        </span>
                                    </div>

                                </div>

                            </div>

                        </div>


                        <!-- RIGHT COLUMN -->

                        <div class="feedback-form-column">

                            <div class="mb-3">

                                <label for="message" class="form-label">
                                    Your Message
                                </label>

                                <textarea
                                    name="message"
                                    id="message"
                                    class="form-control"
                                    rows="7"
                                    placeholder="Write your feedback here..."
                                    required><?php echo htmlspecialchars($message); ?></textarea>

                                <span class="form-hint">
                                    <i class="fas fa-lock me-1"></i>
                                    Your feedback will be submitted to the EcoTrack management team.
                                </span>

                            </div>

                        </div>


                        <!-- FORM BUTTONS -->

                        <div class="feedback-form-actions">

                            <button type="submit" class="btn-ecotrack">

                                <i class="fas fa-paper-plane"></i>
                                Submit Feedback

                            </button>

                            <a href="feedback_history.php"
                                class="btn-outline-ecotrack">

                                <i class="fas fa-clock-rotate-left"></i>
                                Feedback History

                            </a>

                        </div>

                    </div>

                </form>

            </section>


            <!-- FOOTER -->

            <footer class="page-footer">

                Waste Collection Management System by Group 2

                &copy; <?php echo date("Y"); ?>

                <br>

                Resident - Send Feedback

            </footer>

        </main>

    </div>


    <!-- BOOTSTRAP JAVASCRIPT -->

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
