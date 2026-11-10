
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// ==========================================
// RESIDENT ACCESS
// ==========================================
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'residents'
) {
    header("Location: ../index.php");
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is unavailable.");
}

$conn->set_charset("utf8mb4");

$user_id = (int) $_SESSION['user_id'];

// ==========================================
// CONSTANTS
// ==========================================
define('MAX_SUBSCRIPTION_DISCOUNT', 150);

// ==========================================
// INITIALIZE VARIABLES
// ==========================================
$message = '';
$message_type = '';

$fullname = 'Resident';

$available_points = 0;
$reserved_points = 0;
$redeem_limit = 0;
$subscription_exists = false;
$redemption_history = [];

$total_earned = 0;
$total_redeemed = 0;

// ==========================================
// CSRF TOKEN
// ==========================================
if (empty($_SESSION['eco_points_csrf'])) {
    $_SESSION['eco_points_csrf'] = bin2hex(random_bytes(32));
}

// ==========================================
// HTML ESCAPE FUNCTION
// ==========================================
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars(
            (string)($value ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

// ==========================================
// DATABASE PROCESSING
// ==========================================
try {

    // ======================================
    // GET RESIDENT INFORMATION
    // ======================================
    $user_stmt = $conn->prepare("
        SELECT fullname
        FROM users
        WHERE id = ?
        AND role = 'residents'
        LIMIT 1
    ");

    if (!$user_stmt) {
        throw new Exception("Unable to retrieve resident information.");
    }

    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();

    $user_result = $user_stmt->get_result();
    $user_data = $user_result->fetch_assoc();

    if (!$user_data) {
        throw new Exception("Resident account not found.");
    }

    $fullname = $user_data['fullname'] ?? 'Resident';

    $user_stmt->close();


    // ======================================
    // GET TOTAL EARNED ECO POINTS
    // ======================================
    $earned_stmt = $conn->prepare("
        SELECT COALESCE(SUM(points), 0) AS total_points
        FROM eco_points
        WHERE user_id = ?
    ");

    if (!$earned_stmt) {
        throw new Exception("Unable to retrieve earned Eco Points.");
    }

    $earned_stmt->bind_param("i", $user_id);
    $earned_stmt->execute();

    $earned_result = $earned_stmt->get_result();
    $earned_data = $earned_result->fetch_assoc();

    $total_earned = (int)($earned_data['total_points'] ?? 0);

    $earned_stmt->close();


    // ======================================
    // GET TOTAL REDEEMED POINTS
    // ======================================
    $redeemed_stmt = $conn->prepare("
        SELECT COALESCE(SUM(points_redeemed), 0) AS total_redeemed
        FROM subscription_point_redemptions
        WHERE user_id = ?
    ");

    if (!$redeemed_stmt) {
        throw new Exception("Unable to retrieve redeemed Eco Points.");
    }

    $redeemed_stmt->bind_param("i", $user_id);
    $redeemed_stmt->execute();

    $redeemed_result = $redeemed_stmt->get_result();
    $redeemed_data = $redeemed_result->fetch_assoc();

    $total_redeemed = (int)($redeemed_data['total_redeemed'] ?? 0);

    $redeemed_stmt->close();


    // ======================================
    // COMPUTE AVAILABLE POINTS
    // ======================================
    $available_points = max(
        0,
        $total_earned - $total_redeemed
    );


    // ======================================
    // GET RESERVED POINTS
    // ======================================
    $reserved_stmt = $conn->prepare("
        SELECT COALESCE(SUM(points_redeemed), 0) AS reserved
        FROM subscription_point_redemptions
        WHERE user_id = ?
        AND subscription_id IS NULL
    ");

    if (!$reserved_stmt) {
        throw new Exception("Unable to retrieve reserved Eco Points.");
    }

    $reserved_stmt->bind_param("i", $user_id);
    $reserved_stmt->execute();

    $reserved_result = $reserved_stmt->get_result();
    $reserved_data = $reserved_result->fetch_assoc();

    $reserved_points = (int)($reserved_data['reserved'] ?? 0);

    $reserved_stmt->close();


    // ======================================
    // COMPUTE REDEMPTION LIMIT
    // ======================================
    $remaining_discount = max(
        0,
        MAX_SUBSCRIPTION_DISCOUNT - $reserved_points
    );

    $redeem_limit = min(
        $available_points,
        $remaining_discount
    );


    // ======================================
    // CHECK EXISTING SUBSCRIPTION
    // ======================================
    $subscription_stmt = $conn->prepare("
        SELECT id
        FROM subscriptions
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$subscription_stmt) {
        throw new Exception("Unable to check subscription record.");
    }

    $subscription_stmt->bind_param("i", $user_id);
    $subscription_stmt->execute();

    $subscription_result = $subscription_stmt->get_result();

    $subscription_exists = (
        $subscription_result->num_rows > 0
    );

    $subscription_stmt->close();


    // ======================================
    // PROCESS ECO POINT REDEMPTION
    // ======================================
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['redeem_points'])
    ) {

        // Validate CSRF token
        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals(
                $_SESSION['eco_points_csrf'],
                $_POST['csrf_token']
            )
        ) {
            throw new Exception(
                "Invalid request. Please refresh the page and try again."
            );
        }

        // Check existing subscription
        if ($subscription_exists) {
            throw new Exception(
                "You already have a subscription record. Please check your Subscription page."
            );
        }

        // Validate submitted points
        $points_to_redeem = filter_var(
            $_POST['points_to_redeem'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $points_to_redeem === false ||
            $points_to_redeem < 1
        ) {
            throw new Exception(
                "Please enter a valid number of Eco Points."
            );
        }

        // Check available points and maximum limit
        if ($points_to_redeem > $redeem_limit) {
            throw new Exception(
                "You can only reserve up to " .
                number_format($redeem_limit) .
                " additional points."
            );
        }

        // 1 Eco Point = ₱1
        $discount_amount = (float)$points_to_redeem;

        // ==================================
        // SAVE REDEMPTION
        // ==================================
        $conn->begin_transaction();

        try {

            $insert_stmt = $conn->prepare("
                INSERT INTO subscription_point_redemptions
                (
                    subscription_id,
                    user_id,
                    points_redeemed,
                    discount_amount,
                    created_at
                )
                VALUES (NULL, ?, ?, ?, NOW())
            ");

            if (!$insert_stmt) {
                throw new Exception(
                    "Unable to prepare Eco Points redemption."
                );
            }

            $insert_stmt->bind_param(
                "iid",
                $user_id,
                $points_to_redeem,
                $discount_amount
            );

            if (!$insert_stmt->execute()) {
                throw new Exception(
                    "Failed to save Eco Points redemption."
                );
            }

            $insert_stmt->close();

            $conn->commit();

            // Redirect after successful redemption
            header("Location: eco_points.php?success=1");
            exit;

        } catch (Exception $e) {

            $conn->rollback();

            throw $e;
        }
    }


    // ======================================
    // SUCCESS NOTIFICATION
    // ======================================
    if (isset($_GET['success'])) {
        $message = "Your Eco Points have been successfully reserved!";
        $message_type = "success";
    }


    // ======================================
    // GET REDEMPTION HISTORY
    // ======================================
    $history_stmt = $conn->prepare("
        SELECT
            id,
            created_at,
            points_redeemed,
            discount_amount,
            subscription_id
        FROM subscription_point_redemptions
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
    ");

    if (!$history_stmt) {
        throw new Exception(
            "Unable to retrieve redemption history."
        );
    }

    $history_stmt->bind_param("i", $user_id);
    $history_stmt->execute();

    $history_result = $history_stmt->get_result();

    while ($row = $history_result->fetch_assoc()) {
        $redemption_history[] = $row;
    }

    $history_stmt->close();

} catch (Exception $e) {

    $message = $e->getMessage();
    $message_type = 'error';

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Eco Points | EcoTrack</title>

<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f7f5;
    font-family: Arial, Helvetica, sans-serif;
    color: #263b30;
}

/* ==========================================
   SIDEBAR
========================================== */


.sidebar {
    width: 250px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    background: #123d2b;
    color: white;
    padding: 25px 15px;
    overflow-y: auto;
    z-index: 1000;
}

.sidebar-logo {
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 35px;
    padding-left: 12px;
}

.sidebar-logo i {
    color: #9be7ad;
    margin-right: 8px;
}

.sidebar-title {
    color: #a9c4b4;
    font-size: 11px;
    margin: 20px 12px 10px;
    text-transform: uppercase;
}

.sidebar-menu {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 13px 15px;
    margin-bottom: 7px;
    border-radius: 8px;
    color: #d8e8df;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: .2s;
}

.sidebar-menu:hover {
    background: rgba(255,255,255,.12);
    color: white;
}

.sidebar-menu.active {
    background: white;
    color: #123d2b;
}

.sidebar-menu i {
    width: 20px;
    text-align: center;
}

.logout-link {
    margin-top: 35px;
    color: #ffd5d5;
}

@media(max-width: 700px) {
    .sidebar {
        width: 70px;
        padding: 20px 8px;
    }

    .sidebar-logo {
        font-size: 0;
        padding-left: 13px;
    }

    .sidebar-logo i {
        font-size: 23px;
    }

    .sidebar-title,
    .sidebar-menu span:not(.menu-icon) {
        display: none;
    }

    .sidebar-menu {
        justify-content: center;
    }
}


.logout-link {
    margin-top: 35px;
    color: #ffd5d5;
}

/* ==========================================
   MAIN CONTENT
========================================== */

.main-content {
    margin-left: 250px;
    min-height: 100vh;
}

/* ==========================================
   TOPBAR
========================================== */

.topbar {
    background: white;
    padding: 18px 30px;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    border-bottom: 1px solid #e5eae6;
    min-height: 75px;
}

.resident-profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.resident-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e5f4e9;
    color: #17643b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.resident-details {
    display: flex;
    flex-direction: column;
}

.resident-name {
    color: #263b30;
    font-size: 14px;
    font-weight: bold;
}

.resident-role {
    color: #829087;
    font-size: 11px;
}

/* ==========================================
   PAGE CONTENT
========================================== */

.content {
    padding: 30px;
    min-width: 0;
}

.page-title {
    margin-bottom: 25px;
}

.page-title h2 {
    color: #183d2b;
    font-size: 26px;
    font-weight: 800;
    margin-bottom: 7px;
}

.page-title h2 i {
    color: #198754;
}

.page-title p {
    color: #77847c;
    font-size: 14px;
    margin-bottom: 0;
}

/* ==========================================
   CARDS
========================================== */

.points-box,
.info-box {
    background: white;
    border: 1px solid #e8eeea;
    border-radius: 13px;
    padding: 25px;
    margin-bottom: 22px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.points-box {
    position: relative;
    overflow: hidden;
}

.points-box::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
    background: #198754;
}

.points-label {
    color: #718078;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
}

.points-box h1 {
    color: #198754;
    font-size: 35px;
    font-weight: 800;
    margin: 10px 0;
}

.points-icon {
    width: 48px;
    height: 48px;
    background: #e5f4e9;
    color: #198754;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    margin-bottom: 15px;
}

.points-box p,
.info-box p {
    color: #66746b;
}

.info-box h3 {
    margin-top: 0;
    margin-bottom: 17px;
    color: #183d2b;
    font-size: 19px;
    font-weight: 800;
}

.info-box h3 i {
    color: #198754;
    margin-right: 8px;
}

.info-box li {
    margin-bottom: 10px;
    font-size: 14px;
    line-height: 1.6;
    color: #53645a;
}

.info-box ul {
    padding-left: 22px;
    margin-bottom: 0;
}

/* ==========================================
   NOTIFICATIONS
========================================== */

.notice {
    padding: 14px 17px;
    border-radius: 9px;
    margin-bottom: 20px;
    line-height: 1.5;
    font-size: 14px;
}

.notice.success {
    background: #e5f5e9;
    border: 1px solid #c6e8d0;
    color: #17643b;
}

.notice.error {
    background: #fce8e8;
    border: 1px solid #f3cccc;
    color: #842029;
}

/* ==========================================
   SUMMARY
========================================== */

.summary {
    background: #f4f8f5;
    border: 1px solid #e4eee7;
    padding: 16px;
    border-radius: 9px;
    margin: 17px 0;
}

.summary p {
    margin: 8px 0;
    font-size: 14px;
    color: #53645a;
}

.summary strong {
    color: #183d2b;
}

/* ==========================================
   FORM
========================================== */

.form-row {
    margin: 18px 0;
}

.form-row label {
    display: block;
    font-weight: 700;
    color: #304b3b;
    font-size: 14px;
    margin-bottom: 8px;
}

.form-row input {
    width: 100%;
    max-width: 320px;
    padding: 12px 13px;
    border: 1px solid #d6e0d9;
    border-radius: 8px;
    font-size: 15px;
    color: #263b30;
    outline: none;
    transition: .2s;
}

.form-row input:focus {
    border-color: #198754;
    box-shadow: 0 0 0 3px rgba(25,135,84,.12);
}

.small {
    color: #829087;
    font-size: 13px;
    line-height: 1.6;
}

/* ==========================================
   BUTTONS
========================================== */

.btn-ecotrack {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 11px 18px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #17643b;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    background: #17643b;
    color: white;
    transition: .2s;
}

.btn-ecotrack:hover {
    background: #104d2d;
    border-color: #104d2d;
    color: white;
}

.btn-outline-ecotrack {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 10px 17px;
    border-radius: 8px;
    text-decoration: none;
    border: 1px solid #17643b;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    background: white;
    color: #17643b;
    transition: .2s;
}

.btn-outline-ecotrack:hover {
    background: #17643b;
    color: white;
}

/* ==========================================
   HISTORY TABLE
========================================== */

.history-table-wrapper {
    width: 100%;
    overflow-x: auto;
    border: 1px solid #e7ede9;
    border-radius: 9px;
}

.history-table {
    width: 100%;
    min-width: 600px;
    border-collapse: collapse;
    margin: 0;
    font-size: 14px;
}

.history-table th,
.history-table td {
    padding: 14px 15px;
    text-align: left;
    border-bottom: 1px solid #e9eeeb;
}

.history-table th {
    background: #f4f8f5;
    color: #365342;
    font-weight: 700;
    white-space: nowrap;
}

.history-table td {
    color: #53645a;
}

.history-table tbody tr:last-child td {
    border-bottom: none;
}

.history-table tbody tr:hover {
    background: #f9fbf9;
}

.status-badge {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.status-reserved {
    background: #fff3cd;
    color: #856404;
}

.status-applied {
    background: #d1e7dd;
    color: #0f5132;
}

/* ==========================================
   FOOTER
========================================== */

.dashboard-footer {
    margin-top: 35px;
    padding: 20px 0;
    border-top: 1px solid #e2e9e5;
    color: #929b96;
    font-size: 12px;
    text-align: center;
    line-height: 1.8;
}

/* ==========================================
   RESPONSIVE DESIGN
========================================== */

@media(max-width: 1000px) {

    .content {
        padding: 20px;
    }

}

@media(max-width: 700px) {

    .sidebar {
        width: 70px;
        padding: 20px 8px;
    }

    .sidebar-logo {
        font-size: 0;
        padding-left: 13px;
    }

    .sidebar-logo i {
        font-size: 23px;
    }

    .sidebar-title,
    .sidebar-menu span:not(.menu-icon) {
        display: none;
    }

    .sidebar-menu {
        justify-content: center;
        padding: 13px 8px;
    }

    .sidebar-menu i {
        font-size: 17px;
    }

    .main-content {
        margin-left: 70px;
    }

    .content {
        padding: 18px 13px;
    }

    .topbar {
        padding: 15px;
        min-height: 65px;
    }

    .resident-name {
        font-size: 12px;
    }

    .resident-role {
        font-size: 10px;
    }

    .page-title h2 {
        font-size: 21px;
    }

    .points-box,
    .info-box {
        padding: 18px;
    }

    .points-box h1 {
        font-size: 28px;
    }

    .history-table {
        font-size: 12px;
    }

}

</style>

</head>

<body>

<!-- ======================================
     RESIDENT SIDEBAR
====================================== -->


<aside class="sidebar">

    <div class="sidebar-logo">
        <i class="fas fa-recycle"></i> EcoTrack
    </div>

    <div class="sidebar-title">Main Menu</div>

    <a href="dashboard.php" class="sidebar-menu">
        <i class="fas fa-chart-pie"></i>
        <span>Dashboard</span>
    </a>

    <a href="eco_points.php" class="sidebar-menu active">
        <i class="fas fa-star"></i>
        <span>Eco Points</span>
    </a>

    <a href="collection_schedule.php" class="sidebar-menu">
        <i class="fas fa-calendar-alt"></i>
        <span>Collection Schedule</span>
    </a>

    <a href="track_truck.php" class="sidebar-menu">
        <i class="fas fa-truck"></i>
        <span>Track Garbage Truck</span>
    </a>

    <a href="subscription.php" class="sidebar-menu">
        <i class="fas fa-credit-card"></i>
        <span>Subscription</span>
    </a>

    <a href="register_household.php" class="sidebar-menu">
        <i class="fas fa-house"></i>
        <span>Register Household</span>
    </a>

    <a href="feedback.php" class="sidebar-menu">
        <i class="fas fa-comments"></i>
        <span>Feedback</span>
    </a>

    <a href="qrcode.php" class="sidebar-menu">
        <i class="fas fa-qrcode"></i>
        <span>Resident QR Code</span>
    </a>

    <div class="sidebar-title">Account</div>

    <a href="../logout.php" class="sidebar-menu logout-link">
        <i class="fas fa-sign-out-alt"></i>
        <span>Logout</span>
    </a>

</aside>



<!-- ======================================
     MAIN CONTENT
====================================== -->

<div class="main-content">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="resident-profile">

            <div class="resident-icon">
                <i class="fas fa-user"></i>
            </div>

            <div class="resident-details">

                <span class="resident-name">
                    <?= h($fullname) ?>
                </span>

                <span class="resident-role">
                    Resident Account
                </span>

            </div>

        </div>

    </div>


    <!-- PAGE CONTENT -->

    <div class="content">

        <!-- PAGE TITLE -->

        <div class="page-title">

            <h2>
                <i class="fas fa-star me-2"></i>
                My Eco Points
            </h2>

            <p>
                Earn points by participating in proper waste management.
            </p>

        </div>


        <!-- NOTIFICATION -->

        <?php if (!empty($message)): ?>

            <div class="notice <?= h($message_type) ?>">

                <i class="fas <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-2"></i>

                <?= h($message) ?>

            </div>

        <?php endif; ?>


        <!-- AVAILABLE POINTS -->

        <div class="points-box">

            <div class="points-icon">
                <i class="fas fa-star"></i>
            </div>

            <p class="points-label">
                My Total Available Points
            </p>

            <h1>
                <?= number_format($available_points) ?> Points
            </h1>

            <p class="small mb-0">
                Your available balance is your earned Eco Points
                minus all points already redeemed or reserved.
            </p>

        </div>


        <!-- REDEEM ECO POINTS -->

        <div class="info-box">

            <h3>
                <i class="fas fa-gift"></i>
                Reserve Eco Points for Your Subscription
            </h3>

            <p>
                <strong>1 Eco Point = ₱1.00 discount.</strong>

                You may reserve up to 150 points for your
                one-month subscription fee of ₱150.
            </p>


            <div class="summary">

                <p>
                    <i class="fas fa-lock me-2"></i>
                    Points already reserved:

                    <strong>
                        <?= number_format($reserved_points) ?>
                    </strong>
                </p>

                <p>
                    <i class="fas fa-coins me-2"></i>
                    Maximum additional points you can reserve:

                    <strong>
                        <?= number_format($redeem_limit) ?>
                    </strong>
                </p>

            </div>


            <?php if ($subscription_exists): ?>

                <div class="notice error">

                    <i class="fas fa-circle-exclamation me-2"></i>

                    You already have a subscription record.
                    Please check your Subscription page.

                </div>

                <a href="subscription.php" class="btn-ecotrack">

                    <i class="fas fa-credit-card"></i>
                    View Subscription

                </a>


            <?php elseif ($redeem_limit <= 0): ?>

                <p class="small">

                    <?php if ($reserved_points >= MAX_SUBSCRIPTION_DISCOUNT): ?>

                        <i class="fas fa-check-circle me-1"></i>

                        You have already reserved the maximum
                        of 150 Eco Points for your next subscription.

                    <?php else: ?>

                        <i class="fas fa-info-circle me-1"></i>

                        You currently have no available points to redeem.

                    <?php endif; ?>

                </p>


            <?php else: ?>


                <!-- REDEMPTION FORM -->

                <form method="POST" action="">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION['eco_points_csrf']) ?>"
                    >

                    <div class="form-row">

                        <label for="points_to_redeem">
                            Eco Points to Redeem
                        </label>

                        <input
                            type="number"
                            id="points_to_redeem"
                            name="points_to_redeem"
                            min="1"
                            max="<?= (int)$redeem_limit ?>"
                            step="1"
                            value="1"
                            required
                        >

                        <p class="small mt-2">

                            You can reserve up to
                            <?= number_format($redeem_limit) ?>
                            additional points.

                        </p>

                    </div>


                    <!-- DISCOUNT PREVIEW -->

                    <div class="summary" aria-live="polite">

                        <p>
                            <i class="fas fa-tag me-2"></i>

                            Eco Points discount:

                            <strong id="discount_preview">
                                ₱1.00
                            </strong>
                        </p>

                        <p>
                            <i class="fas fa-wallet me-2"></i>

                            Remaining subscription amount:

                            <strong id="total_preview">
                                ₱149.00
                            </strong>
                        </p>

                    </div>


                    <button
                        type="submit"
                        name="redeem_points"
                        class="btn-ecotrack"
                    >

                        <i class="fas fa-check"></i>
                        Apply Points

                    </button>

                </form>


            <?php endif; ?>


            <p class="small mt-3 mb-0">

                <i class="fas fa-info-circle me-1"></i>

                Applying points only records your redemption.
                It does not create a subscription.

                Your reserved points will be applied when you
                submit your subscription application.

            </p>

        </div>


        <!-- REDEMPTION HISTORY -->

        <div class="info-box">

            <h3>
                <i class="fas fa-clock-rotate-left"></i>
                My Redemption History
            </h3>


            <?php if (empty($redemption_history)): ?>

                <p class="small mb-0">

                    <i class="fas fa-info-circle me-1"></i>

                    You have no Eco Points redemption records yet.

                </p>


            <?php else: ?>


                <div class="history-table-wrapper">

                    <table class="history-table">

                        <thead>

                            <tr>
                                <th>Date</th>
                                <th>Points</th>
                                <th>Discount</th>
                                <th>Status</th>
                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($redemption_history as $redemption): ?>

                            <tr>

                                <td>
                                    <?= h($redemption['created_at']) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= number_format(
                                            (int)$redemption['points_redeemed']
                                        ) ?>
                                    </strong>
                                </td>

                                <td>
                                    <strong>
                                        ₱<?= number_format(
                                            (float)$redemption['discount_amount'],
                                            2
                                        ) ?>
                                    </strong>
                                </td>

                                <td>

                                    <?php if ($redemption['subscription_id'] === null): ?>

                                        <span class="status-badge status-reserved">
                                            <i class="fas fa-hourglass-half me-1"></i>
                                            Reserved
                                        </span>

                                    <?php else: ?>

                                        <span class="status-badge status-applied">
                                            <i class="fas fa-check-circle me-1"></i>
                                            Applied to Subscription
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


            <?php endif; ?>

        </div>


        <!-- HOW TO EARN POINTS -->

        <div class="info-box">

            <h3>
                <i class="fas fa-leaf"></i>
                How to Earn Points
            </h3>

            <ul>

                <li>
                    <strong>Proper Waste Segregation:</strong>
                    Separate biodegradable, recyclable, and residual waste.
                </li>

                <li>
                    <strong>Recycling Activities:</strong>
                    Bring recyclable materials to the designated collection area.
                </li>

                <li>
                    <strong>Scheduled Waste Collection:</strong>
                    Follow the community waste collection schedule.
                </li>

                <li>
                    <strong>Community Clean-Up:</strong>
                    Participate in approved community clean-up activities.
                </li>

                <li>
                    <strong>Environmental Programs:</strong>
                    Join official environmental programs.
                </li>

            </ul>

        </div>


        <!-- REDEMPTION RULES -->

        <div class="info-box">

            <h3>
                <i class="fas fa-circle-exclamation"></i>
                Important Redemption Rules
            </h3>

            <ul>

                <li>
                    1 Eco Point is equal to ₱1.00 discount.
                </li>

                <li>
                    The monthly subscription fee is ₱150.00.
                </li>

                <li>
                    A maximum of 150 Eco Points can be reserved for one subscription.
                </li>

                <li>
                    Reserved points are immediately deducted from your available balance.
                </li>

                <li>
                    Applying points does not create a subscription.
                </li>

                <li>
                    Reserved points will be attached when you apply through the Subscription page.
                </li>

                <li>
                    Cash Simulation payments still require administrator confirmation.
                </li>

            </ul>

        </div>


        <!-- FOOTER -->

        <div class="dashboard-footer">

            EcoTrack Waste Collection Management System

            <br>

            &copy; <?= date("Y"); ?>

            <br>

            Resident Dashboard

        </div>

    </div>

</div>


<!-- ======================================
     JAVASCRIPT DISCOUNT PREVIEW
====================================== -->

<script>

const pointsInput = document.getElementById('points_to_redeem');

if (pointsInput) {

    const discountPreview =
        document.getElementById('discount_preview');

    const totalPreview =
        document.getElementById('total_preview');


    function updatePreview() {

        let points = parseInt(pointsInput.value, 10);

        if (!Number.isFinite(points) || points < 0) {
            points = 0;
        }

        const maximum = parseInt(pointsInput.max, 10) || 0;

        points = Math.min(points, maximum, 150);

        discountPreview.textContent =
            '₱' + points.toFixed(2);

        totalPreview.textContent =
            '₱' + (150 - points).toFixed(2);

    }


    pointsInput.addEventListener('input', updatePreview);

    updatePreview();

}

</script>


<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
