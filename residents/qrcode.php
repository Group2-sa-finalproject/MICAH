
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

// Get the logged-in resident's ID
$user_id = (int) $_SESSION["user_id"];

// Check database connection
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");

// Get resident information
$sql = mysqli_prepare(
    $conn,
    "SELECT id, fullname
     FROM users
     WHERE id = ? AND role = 'residents'
     LIMIT 1"
);

if (!$sql) {
    die("Unable to retrieve resident information.");
}

mysqli_stmt_bind_param($sql, "i", $user_id);
mysqli_stmt_execute($sql);

$result = mysqli_stmt_get_result($sql);
$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($sql);

// If the resident account does not exist, return to login
if (!$user) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// Create QR code data using the resident's unique ID
$qr_data = "RESIDENT - " . $user["id"];

// Generate the QR code image
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data="
        . urlencode($qr_data);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Resident QR Code | EcoTrack</title>

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
           QR CODE MAIN CARD
        ========================================= */

        .qr-main-card {
            width: 100%;
            background: #fff;
            border: 1px solid #edf1ee;
            border-radius: 14px;
            padding: 32px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
            margin-bottom: 22px;
        }

        .qr-layout {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) minmax(320px, 1fr);
            gap: 35px;
            align-items: center;
        }

        /* =========================================
           RESIDENT INFORMATION
        ========================================= */

        .qr-information {
            padding: 15px;
        }

        .qr-information h4 {
            color: #183d2b;
            font-size: 21px;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .qr-information > p {
            color: #78877e;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 25px;
        }

        .resident-details {
            background: #f5faf6;
            border: 1px solid #e3eee6;
            border-radius: 10px;
            padding: 20px;
        }

        .detail-label {
            display: block;
            color: #819087;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }

        .detail-value {
            display: block;
            color: #183d2b;
            font-size: 16px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .resident-detail-divider {
            height: 1px;
            background: #e0eae3;
            margin: 16px 0;
        }

        /* =========================================
           QR CODE DISPLAY
        ========================================= */

        .qr-display-area {
            background: #f8fbf9;
            border: 1px solid #e5eee8;
            border-radius: 13px;
            padding: 28px;
            text-align: center;
        }

        .qr-display-area h5 {
            color: #183d2b;
            font-size: 17px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .qr-display-area p {
            color: #819087;
            font-size: 12px;
            margin-bottom: 18px;
        }

        .qr-image-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            padding: 14px;
            border: 1px solid #dfe9e2;
            border-radius: 12px;
            box-shadow: 0 3px 10px rgba(0,0,0,.04);
        }

        .qr-image {
            display: block;
            width: 300px;
            max-width: 100%;
            height: auto;
        }

        .resident-code {
            display: inline-block;
            background: #e7f3ea;
            color: #17643b;
            padding: 10px 18px;
            border-radius: 7px;
            font-size: 14px;
            font-weight: 800;
            margin-top: 20px;
            overflow-wrap: anywhere;
        }

        /* =========================================
           QR INSTRUCTIONS
        ========================================= */

        .qr-instructions {
            width: 100%;
            background: #fff;
            border: 1px solid #edf1ee;
            border-radius: 12px;
            padding: 25px 28px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
            margin-bottom: 22px;
        }

        .qr-instructions h5 {
            color: #183d2b;
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 18px;
        }

        .instruction-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .instruction-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .instruction-icon {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            background: #e7f3ea;
            color: #17643b;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
        }

        .instruction-text strong {
            display: block;
            color: #40594a;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .instruction-text span {
            display: block;
            color: #819087;
            font-size: 11px;
            line-height: 1.5;
        }

        /* =========================================
           BUTTONS
        ========================================= */

        .qr-actions {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

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
            gap: 8px;
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
            gap: 8px;
        }

        .btn-outline-ecotrack:hover {
            background: #17643b;
            color: #fff;
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

        @media (max-width: 1100px) {

            .qr-layout {
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            .content {
                padding: 25px 22px;
            }

            .instruction-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

        }

        @media (max-width: 800px) {

            .qr-layout {
                grid-template-columns: 1fr;
            }

            .qr-information {
                padding: 5px;
            }

            .qr-display-area {
                padding: 25px 15px;
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

            .qr-main-card {
                padding: 18px;
            }

            .qr-information h4 {
                font-size: 18px;
            }

            .qr-image {
                width: 250px;
            }

            .qr-instructions {
                padding: 20px 17px;
            }

            .qr-actions {
                flex-direction: column;
            }

            .qr-actions button,
            .qr-actions a {
                width: 100%;
            }

        }

        /* =========================================
           PRINT DESIGN
        ========================================= */

        @media print {

            @page {
                size: A4;
                margin: 15mm;
            }

            body {
                background: #fff !important;
                color: #000 !important;
            }

            .sidebar,
            .topbar,
            .page-heading,
            .qr-instructions,
            .qr-actions,
            .page-footer {
                display: none !important;
            }

            .main-content {
                margin: 0 !important;
                width: 100% !important;
                min-height: auto !important;
            }

            .content {
                padding: 0 !important;
                margin: 0 !important;
            }

            .qr-main-card {
                border: none !important;
                box-shadow: none !important;
                padding: 10px !important;
                margin: 0 !important;
            }

            .qr-layout {
                display: flex !important;
                flex-direction: column !important;
                gap: 20px !important;
            }

            .qr-information {
                text-align: center;
            }

            .qr-display-area {
                background: #fff !important;
                border: none !important;
            }

            .qr-image-wrapper {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
            }

            .qr-image {
                width: 300px !important;
            }

            .resident-details {
                background: #fff !important;
            }

            .resident-code {
                color: #000 !important;
                background: #eee !important;
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
                <a href="feedback.php">
                    <i class="fas fa-comment-dots"></i>
                    <span>Feedback</span>
                </a>
            </li>

            <li>
                <a href="qrcode.php" class="active">
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
                        <?php echo htmlspecialchars($user["fullname"]); ?>
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
                    <i class="fas fa-qrcode me-2"></i>
                    My Resident QR Code
                </h2>

                <p>
                    View, print, and present your unique resident QR code
                    for identification in the EcoTrack system.
                </p>

            </div>


            <!-- QR CODE MAIN CARD -->

            <section class="qr-main-card">

                <div class="qr-layout">

                    <!-- RESIDENT INFORMATION -->

                    <div class="qr-information">

                        <h4>
                            <i class="fas fa-id-card me-2"></i>
                            Resident Identification
                        </h4>

                        <p>
                            Your QR code is connected to your registered
                            EcoTrack resident account. Present this code
                            whenever your resident identity needs to be verified.
                        </p>

                        <div class="resident-details">

                            <div>
                                <span class="detail-label">
                                    Resident Name
                                </span>

                                <span class="detail-value">
                                    <?php echo htmlspecialchars($user["fullname"]); ?>
                                </span>
                            </div>

                            <div class="resident-detail-divider"></div>

                            <div>
                                <span class="detail-label">
                                    Resident ID
                                </span>

                                <span class="detail-value">
                                    <?php echo htmlspecialchars((string) $user["id"]); ?>
                                </span>
                            </div>

                            <div class="resident-detail-divider"></div>

                            <div>
                                <span class="detail-label">
                                    Account Type
                                </span>

                                <span class="detail-value">
                                    Registered Resident
                                </span>
                            </div>

                            <div class="resident-detail-divider"></div>

                            <div>
                                <span class="detail-label">
                                    QR Code Reference
                                </span>

                                <span class="detail-value">
                                    <?php echo htmlspecialchars($qr_data); ?>
                                </span>
                            </div>

                        </div>

                    </div>


                    <!-- QR CODE DISPLAY -->

                    <div class="qr-display-area">

                        <h5>
                            <i class="fas fa-qrcode me-2"></i>
                            Your Resident QR Code
                        </h5>

                        <p>
                            Keep this code visible when presenting your account.
                        </p>

                        <div class="qr-image-wrapper">

                            <img
                                src="<?php echo htmlspecialchars($qr_url); ?>"
                                alt="Resident QR Code"
                                class="qr-image"
                            >

                        </div>

                        <div>
                            <span class="resident-code">
                                <?php echo htmlspecialchars($qr_data); ?>
                            </span>
                        </div>

                    </div>

                </div>


                <!-- ACTION BUTTONS -->

                <div class="qr-actions">

                    <button
                        type="button"
                        class="btn-ecotrack"
                        onclick="window.print()">

                        <i class="fas fa-print"></i>
                        Print QR Code

                    </button>

                

                </div>

            </section>


            <!-- QR CODE INSTRUCTIONS -->

            <section class="qr-instructions">

                <h5>
                    <i class="fas fa-circle-info me-2"></i>
                    How to Use Your QR Code
                </h5>

                <div class="instruction-grid">

                    <div class="instruction-item">

                        <div class="instruction-icon">
                            <i class="fas fa-print"></i>
                        </div>

                        <div class="instruction-text">
                            <strong>1. Print Your QR Code</strong>
                            <span>
                                Print a copy of your QR code or keep a digital
                                copy on your device.
                            </span>
                        </div>

                    </div>


                    <div class="instruction-item">

                        <div class="instruction-icon">
                            <i class="fas fa-mobile-screen-button"></i>
                        </div>

                        <div class="instruction-text">
                            <strong>2. Present Your Code</strong>
                            <span>
                                Show your QR code when resident identification
                                is required.
                            </span>
                        </div>

                    </div>


                    <div class="instruction-item">

                        <div class="instruction-icon">
                            <i class="fas fa-user-check"></i>
                        </div>

                        <div class="instruction-text">
                            <strong>3. Verify Your Identity</strong>
                            <span>
                                Your resident ID can be used by authorized
                                personnel to identify your account.
                            </span>
                        </div>

                    </div>

                </div>

            </section>


            <!-- FOOTER -->

            <footer class="page-footer">

                Waste Collection Management System by Group 2

                &copy; <?php echo date("Y"); ?>

                <br>

                Resident - My QR Code

            </footer>

        </main>

    </div>


    <!-- BOOTSTRAP JAVASCRIPT -->

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
