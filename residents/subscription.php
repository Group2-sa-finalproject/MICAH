<?php
session_start();
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| RESIDENT LOGIN VALIDATION
|--------------------------------------------------------------------------
*/

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

mysqli_set_charset($conn, "utf8mb4");

$user_id = (int) $_SESSION['user_id'];
$monthly_fee = 150.00;

/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function peso($amount)
{
    return '₱' . number_format((float) $amount, 2);
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/*
|--------------------------------------------------------------------------
| GET RESIDENT INFORMATION
|--------------------------------------------------------------------------
*/

$resident_name = 'Resident';

$stmt = $conn->prepare("
    SELECT fullname
    FROM users
    WHERE id = ?
      AND role = 'residents'
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $resident_name = $row['fullname'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| ALERT MESSAGES
|--------------------------------------------------------------------------
*/

$success_message = '';
$error_message = '';

/*
|--------------------------------------------------------------------------
| CREATE SUBSCRIPTION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['create_subscription'])) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrf_token, $_POST['csrf_token'])
    ) {
        $error_message = "Invalid request. Please refresh the page and try again.";
    } else {

        $conn->begin_transaction();

        try {

            /*
            |--------------------------------------------------------------
            | CHECK EXISTING SUBSCRIPTION
            |--------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT id, subscription_status, payment_status
                FROM subscriptions
                WHERE user_id = ?
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception("Unable to check subscription.");
            }

            $stmt->bind_param("i", $user_id);
            $stmt->execute();

            $existing_subscription = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if ($existing_subscription) {

                throw new Exception(
                    "You already have a subscription record."
                );
            }

            /*
            |--------------------------------------------------------------
            | GET RESERVED ECO POINTS
            |--------------------------------------------------------------
            */

            $reserved_points = 0;

            $stmt = $conn->prepare("
                SELECT COALESCE(SUM(points_redeemed), 0) AS total_points
                FROM subscription_point_redemptions
                WHERE user_id = ?
                  AND subscription_id IS NULL
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new Exception("Unable to retrieve reserved Eco Points.");
            }

            $stmt->bind_param("i", $user_id);
            $stmt->execute();

            $reserved_result = $stmt->get_result()->fetch_assoc();

            if ($reserved_result) {
                $reserved_points = (int) $reserved_result['total_points'];
            }

            $stmt->close();

            /*
            |--------------------------------------------------------------
            | CALCULATE DISCOUNT
            |--------------------------------------------------------------
            */

            $points_redeemed = min($reserved_points, (int) $monthly_fee);

            $discount_amount = min(
                (float) $points_redeemed,
                $monthly_fee
            );

            $total_amount = max(
                0,
                $monthly_fee - $discount_amount
            );

            /*
            |--------------------------------------------------------------
            | INSERT SUBSCRIPTION
            |--------------------------------------------------------------
            |
            | NOTE:
            | This query uses the original subscription fields.
            | The additional billing fields must exist in your database.
            |
            */

            $stmt = $conn->prepare("
                INSERT INTO subscriptions (
                    user_id,
                    service_fee,
                    subscription_status,
                    payment_status,
                    months,
                    original_amount,
                    points_redeemed,
                    discount_amount,
                    total_amount,
                    amount_paid,
                    balance_due
                )
                VALUES (?, ?, 'Pending Approval', 'Unpaid', 1, ?, ?, ?, ?, 0, ?)
            ");

            if (!$stmt) {
                throw new Exception(
                    "Unable to create subscription. Please check your subscriptions table structure."
                );
            }

            $stmt->bind_param(
                "ididddd",
                $user_id,
                $monthly_fee,
                $monthly_fee,
                $points_redeemed,
                $discount_amount,
                $total_amount,
                $total_amount
            );

            if (!$stmt->execute()) {
                throw new Exception("Unable to save subscription.");
            }

            $subscription_id = $conn->insert_id;

            $stmt->close();

            /*
            |--------------------------------------------------------------
            | ATTACH RESERVED POINT REDEMPTIONS
            |--------------------------------------------------------------
            */

            if ($reserved_points > 0) {

                $stmt = $conn->prepare("
                    UPDATE subscription_point_redemptions
                    SET subscription_id = ?
                    WHERE user_id = ?
                      AND subscription_id IS NULL
                ");

                if (!$stmt) {
                    throw new Exception(
                        "Unable to attach Eco Points redemption."
                    );
                }

                $stmt->bind_param(
                    "ii",
                    $subscription_id,
                    $user_id
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to update Eco Points redemption."
                    );
                }

                $stmt->close();
            }

            $conn->commit();

            $success_message = "Your subscription request has been submitted successfully.";

        } catch (Exception $e) {

            $conn->rollback();

            $error_message = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUBMIT CASH PAYMENT NOTICE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_cash_notice'])) {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrf_token, $_POST['csrf_token'])
    ) {
        $error_message = "Invalid request. Please refresh the page and try again.";

    } else {

        $subscription_id = (int) ($_POST['subscription_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT id, payment_status, total_amount, amount_paid, balance_due
            FROM subscriptions
            WHERE id = ?
              AND user_id = ?
            LIMIT 1
        ");

        if (!$stmt) {

            $error_message = "Unable to verify your subscription.";

        } else {

            $stmt->bind_param(
                "ii",
                $subscription_id,
                $user_id
            );

            $stmt->execute();

            $subscription_record = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            if (!$subscription_record) {

                $error_message = "Subscription record not found.";

            } elseif (
                strtolower($subscription_record['payment_status']) === 'paid'
            ) {

                $error_message = "Your subscription has already been paid.";

            } else {

                $stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET payment_status = 'Pending Confirmation'
                    WHERE id = ?
                      AND user_id = ?
                ");

                if (!$stmt) {

                    $error_message = "Unable to submit your payment notice.";

                } else {

                    $stmt->bind_param(
                        "ii",
                        $subscription_id,
                        $user_id
                    );

                    if ($stmt->execute()) {

                        $success_message = "Your cash payment notice has been submitted. Please wait for administrator confirmation.";

                    } else {

                        $error_message = "Unable to submit your payment notice.";
                    }

                    $stmt->close();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| GET EARNED ECO POINTS
|--------------------------------------------------------------------------
*/

$earned_points = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(points), 0) AS total_points
    FROM eco_points
    WHERE user_id = ?
");

if ($stmt) {

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $points_result = $stmt->get_result()->fetch_assoc();

    if ($points_result) {
        $earned_points = (int) $points_result['total_points'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| GET REDEEMED ECO POINTS
|--------------------------------------------------------------------------
*/

$redeemed_points = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(points_redeemed), 0) AS total_redeemed
    FROM subscription_point_redemptions
    WHERE user_id = ?
");

if ($stmt) {

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $redeemed_result = $stmt->get_result()->fetch_assoc();

    if ($redeemed_result) {
        $redeemed_points = (int) $redeemed_result['total_redeemed'];
    }

    $stmt->close();
}

$available_points = max(
    0,
    $earned_points - $redeemed_points
);

/*
|--------------------------------------------------------------------------
| GET RESERVED ECO POINTS
|--------------------------------------------------------------------------
*/

$reserved_points = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(points_redeemed), 0) AS total_reserved
    FROM subscription_point_redemptions
    WHERE user_id = ?
      AND subscription_id IS NULL
");

if ($stmt) {

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $reserved_result = $stmt->get_result()->fetch_assoc();

    if ($reserved_result) {
        $reserved_points = (int) $reserved_result['total_reserved'];
    }

    $stmt->close();
}

$eco_discount = min(
    (float) $reserved_points,
    $monthly_fee
);

/*
|--------------------------------------------------------------------------
| GET SUBSCRIPTION RECORD
|--------------------------------------------------------------------------
*/

$subscription = null;

$stmt = $conn->prepare("
    SELECT
        id,
        user_id,
        service_fee,
        subscription_status,
        payment_status,
        created_at,
        months,
        original_amount,
        points_redeemed,
        discount_amount,
        total_amount,
        amount_paid,
        balance_due
    FROM subscriptions
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if ($stmt) {

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $subscription = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| GET LATEST RESIDENT PAYMENT
|--------------------------------------------------------------------------
*/

$latest_payment = null;

if ($subscription) {

    $stmt = $conn->prepare("
        SELECT amount, status, paid_at
        FROM resident_payments
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $latest_payment = $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| SUBSCRIPTION STATUS
|--------------------------------------------------------------------------
*/

$can_print_soa = false;
$is_pending_payment = false;
$is_paid = false;
$can_submit_cash_notice = false;

if ($subscription) {

    $payment_status = strtolower(
        $subscription['payment_status'] ?? ''
    );

    $subscription_status = strtolower(
        $subscription['subscription_status'] ?? ''
    );

    $is_paid = ($payment_status === 'paid');

    $is_pending_payment = (
        $payment_status === 'pending confirmation'
    );

    $can_submit_cash_notice = (
        !$is_paid &&
        !$is_pending_payment
    );

    $can_print_soa = true;
}

/*
|--------------------------------------------------------------------------
| BILLING COMPUTATION
|--------------------------------------------------------------------------
*/

$service_fee = (float) ($subscription['service_fee'] ?? $monthly_fee);

$months = (int) ($subscription['months'] ?? 1);

if ($months < 1) {
    $months = 1;
}

$original_amount = (float) (
    $subscription['original_amount'] ??
    ($service_fee * $months)
);

$subscription_points = (int) (
    $subscription['points_redeemed'] ??
    $reserved_points
);

$discount_amount = (float) (
    $subscription['discount_amount'] ??
    min($subscription_points, $original_amount)
);

$total_amount = (float) (
    $subscription['total_amount'] ??
    max(0, $original_amount - $discount_amount)
);

$amount_paid = (float) (
    $subscription['amount_paid'] ?? 0
);

$balance_due = (float) (
    $subscription['balance_due'] ??
    max(0, $total_amount - $amount_paid)
);

$subscription_status = $subscription['subscription_status'] ?? 'Not Registered';
$payment_status = $subscription['payment_status'] ?? 'Unpaid';

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Subscription | EcoTrack</title>

<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/style.css">

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

/* =========================================================
   GENERAL
========================================================= */

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

/* =========================================================
   SIDEBAR
========================================================= */

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

/* =========================================================
   MAIN CONTENT
========================================================= */

.main-content {
    margin-left: 250px;
    min-height: 100vh;
}

.topbar {
    background: #fff;
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
    gap: 12px;
}

.resident-profile .profile-icon {
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

.resident-profile .profile-name {
    font-size: 14px;
    font-weight: 700;
    color: #183d2b;
}

.resident-profile .profile-role {
    display: block;
    font-size: 11px;
    color: #819087;
    font-weight: 500;
}

.content {
    padding: 30px;
    min-width: 0;
}

/* =========================================================
   PAGE HEADER
========================================================= */

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

/* =========================================================
   ALERTS
========================================================= */

.alert {
    border-radius: 10px;
    font-size: 14px;
}

/* =========================================================
   CARDS
========================================================= */

.subscription-card {
    background: #fff;
    padding: 25px;
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
    border: 1px solid #edf1ee;
    margin-bottom: 22px;
}

.subscription-card h4 {
    font-size: 18px;
    font-weight: 800;
    color: #183d2b;
    margin-bottom: 18px;
}

.subscription-card h5 {
    font-size: 15px;
    font-weight: 700;
    color: #183d2b;
}

.card-description {
    color: #78877e;
    font-size: 13px;
    margin-top: -10px;
    margin-bottom: 20px;
}

/* =========================================================
   SUMMARY CARDS
========================================================= */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 22px;
}

.summary-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
    border: 1px solid #edf1ee;
}

.summary-card .summary-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e7f5eb;
    color: #17643b;
    margin-bottom: 13px;
    font-size: 18px;
}

.summary-card .summary-label {
    color: #7b8981;
    font-size: 12px;
    margin-bottom: 5px;
}

.summary-card .summary-value {
    color: #183d2b;
    font-size: 22px;
    font-weight: 800;
}

/* =========================================================
   DETAILS
========================================================= */

.detail-row {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 13px 0;
    border-bottom: 1px solid #edf1ee;
    font-size: 14px;
}

.detail-row:last-child {
    border-bottom: none;
}

.detail-label {
    color: #78877e;
}

.detail-value {
    color: #183d2b;
    font-weight: 700;
    text-align: right;
}

/* =========================================================
   STATUS BADGES
========================================================= */

.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-paid {
    background: #d8f3df;
    color: #17643b;
}

.status-unpaid {
    background: #f8d7da;
    color: #842029;
}

.status-default {
    background: #e9ecef;
    color: #495057;
}

/* =========================================================
   BUTTONS
========================================================= */

.btn-ecotrack {
    background: #17643b;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 11px 18px;
    font-size: 13px;
    font-weight: 700;
    transition: .2s;
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
    padding: 10px 17px;
    font-size: 13px;
    font-weight: 700;
}

.btn-outline-ecotrack:hover {
    background: #17643b;
    color: #fff;
}

.btn:disabled {
    opacity: .6;
    cursor: not-allowed;
}

/* =========================================================
   FORMS
========================================================= */

.form-control {
    border-radius: 8px;
    padding: 11px 13px;
    font-size: 13px;
    border: 1px solid #dce5de;
}

.form-control:focus {
    border-color: #17643b;
    box-shadow: 0 0 0 .15rem rgba(23,100,59,.12);
}

.form-label {
    font-size: 13px;
    color: #40594a;
    font-weight: 700;
}

/* =========================================================
   INFORMATION BOX
========================================================= */

.info-box {
    background: #edf7ef;
    border-left: 4px solid #21834d;
    border-radius: 8px;
    padding: 15px;
    font-size: 13px;
    color: #355b43;
    margin-bottom: 18px;
}

.warning-box {
    background: #fff8e6;
    border-left: 4px solid #e0a800;
    border-radius: 8px;
    padding: 15px;
    font-size: 13px;
    color: #765b18;
    margin-bottom: 18px;
}

/* =========================================================
   EMPTY STATE
========================================================= */

.empty-state {
    text-align: center;
    padding: 35px 15px;
}

.empty-state i {
    font-size: 42px;
    color: #a8c5b0;
    margin-bottom: 15px;
}

.empty-state h5 {
    color: #183d2b;
    font-weight: 800;
}

.empty-state p {
    color: #849188;
    font-size: 13px;
}

/* =========================================================
   SOA PRINT AREA
========================================================= */

.soa-print {
    background: #fff;
    border: 1px solid #dce5de;
    padding: 30px;
    border-radius: 10px;
}

.soa-header {
    text-align: center;
    border-bottom: 2px solid #183d2b;
    padding-bottom: 18px;
    margin-bottom: 20px;
}

.soa-header h3 {
    color: #183d2b;
    font-size: 22px;
    font-weight: 800;
}

.soa-header p {
    font-size: 12px;
    color: #6f7e74;
    margin: 4px 0;
}

.soa-total {
    background: #edf7ef;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
}

.soa-total strong {
    color: #17643b;
    font-size: 19px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {

    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
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
    }

    .topbar {
        padding: 15px;
    }

    .content {
        padding: 18px 13px;
    }

    .page-heading h2 {
        font-size: 21px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .subscription-card {
        padding: 18px;
    }

}

@media print {

    body {
        background: #fff;
    }

    .sidebar,
    .topbar,
    .page-heading,
    .no-print,
    .alert,
    .summary-grid {
        display: none !important;
    }

    .main-content {
        margin: 0;
    }

    .content {
        padding: 0;
    }

    .subscription-card {
        box-shadow: none;
        border: none;
        padding: 0;
    }

    .soa-print {
        border: none;
        padding: 0;
    }

}

</style>

</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

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
            <a href="subscription.php" class="active">
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


<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="main-content">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="resident-profile">

            <div class="profile-icon">
                <i class="fas fa-user"></i>
            </div>

            <div>
                <div class="profile-name">
                    <?= escapeHtml($resident_name); ?>
                </div>

                <span class="profile-role">
                    Resident
                </span>
            </div>

        </div>

    </div>


    <!-- PAGE CONTENT -->

    <main class="content">

        <div class="page-heading">

            <h2>
                <i class="fas fa-file-invoice-dollar me-2"></i>
                Waste Collection Subscription
            </h2>

            <p>
                Manage your waste collection subscription, payment, and Eco Points discount.
            </p>

        </div>


        <!-- ALERT MESSAGES -->

        <?php if (!empty($success_message)): ?>

            <div class="alert alert-success alert-dismissible fade show no-print">

                <i class="fas fa-check-circle me-2"></i>

                <?= escapeHtml($success_message); ?>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <?php if (!empty($error_message)): ?>

            <div class="alert alert-danger alert-dismissible fade show no-print">

                <i class="fas fa-exclamation-circle me-2"></i>

                <?= escapeHtml($error_message); ?>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"></button>

            </div>

        <?php endif; ?>


        <!-- SUMMARY CARDS -->

        <div class="summary-grid">

            <div class="summary-card">

                <div class="summary-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>

                <div class="summary-label">
                    Monthly Service Fee
                </div>

                <div class="summary-value">
                    <?= peso($monthly_fee); ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-icon">
                    <i class="fas fa-leaf"></i>
                </div>

                <div class="summary-label">
                    Available Eco Points
                </div>

                <div class="summary-value">
                    <?= number_format($available_points); ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="summary-icon">
                    <i class="fas fa-tags"></i>
                </div>

                <div class="summary-label">
                    Reserved Eco Points Discount
                </div>

                <div class="summary-value">
                    <?= peso($eco_discount); ?>
                </div>

            </div>

        </div>


        <!-- SUBSCRIPTION APPLICATION -->

        <section class="subscription-card">

            <h4>
                <i class="fas fa-clipboard-list me-2"></i>
                Subscription Application
            </h4>

            <p class="card-description">
                Review the service fee and your available Eco Points before submitting your subscription.
            </p>


            <?php if (!$subscription): ?>

                <div class="info-box">

                    <i class="fas fa-info-circle me-2"></i>

                    The monthly waste collection service fee is
                    <strong><?= peso($monthly_fee); ?></strong>.

                    You may use your reserved Eco Points as a discount.

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Resident Name
                    </span>

                    <span class="detail-value">
                        <?= escapeHtml($resident_name); ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Monthly Service Fee
                    </span>

                    <span class="detail-value">
                        <?= peso($monthly_fee); ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Reserved Eco Points
                    </span>

                    <span class="detail-value">
                        <?= number_format($reserved_points); ?> points
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Eco Points Discount
                    </span>

                    <span class="detail-value text-success">
                        - <?= peso($eco_discount); ?>
                    </span>

                </div>


                <div class="detail-row mb-4">

                    <span class="detail-label">
                        Estimated Amount Due
                    </span>

                    <span class="detail-value">
                        <?= peso(max(0, $monthly_fee - $eco_discount)); ?>
                    </span>

                </div>


                <?php if ($reserved_points <= 0): ?>

                    <div class="warning-box">

                        <i class="fas fa-exclamation-triangle me-2"></i>

                        You have no reserved Eco Points for this subscription.
                        You can still submit your subscription application.

                    </div>

                <?php endif; ?>


                <form method="POST">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= escapeHtml($csrf_token); ?>">

                    <button type="submit"
                            name="create_subscription"
                            class="btn btn-ecotrack">

                        <i class="fas fa-paper-plane me-2"></i>
                        Submit Subscription

                    </button>

                </form>


            <?php else: ?>

                <div class="info-box">

                    <i class="fas fa-check-circle me-2"></i>

                    You already have a subscription record.
                    You can review your subscription details below.

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Subscription ID
                    </span>

                    <span class="detail-value">
                        #<?= (int) $subscription['id']; ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Resident Name
                    </span>

                    <span class="detail-value">
                        <?= escapeHtml($resident_name); ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Application Date
                    </span>

                    <span class="detail-value">
                        <?= !empty($subscription['created_at'])
                            ? date('F d, Y', strtotime($subscription['created_at']))
                            : 'N/A'; ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Subscription Status
                    </span>

                    <span class="detail-value">

                        <?php
                        $subscription_badge = 'status-default';

                        if (stripos($subscription_status, 'pending') !== false) {
                            $subscription_badge = 'status-pending';
                        } elseif (stripos($subscription_status, 'approved') !== false) {
                            $subscription_badge = 'status-paid';
                        }
                        ?>

                        <span class="status-badge <?= $subscription_badge; ?>">
                            <?= escapeHtml($subscription_status); ?>
                        </span>

                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Payment Status
                    </span>

                    <span class="detail-value">

                        <?php
                        $payment_badge = 'status-default';

                        if ($is_paid) {
                            $payment_badge = 'status-paid';
                        } elseif ($is_pending_payment) {
                            $payment_badge = 'status-pending';
                        } elseif ($payment_status === 'Unpaid') {
                            $payment_badge = 'status-unpaid';
                        }
                        ?>

                        <span class="status-badge <?= $payment_badge; ?>">
                            <?= escapeHtml($payment_status); ?>
                        </span>

                    </span>

                </div>

            <?php endif; ?>

        </section>



        <?php if ($subscription): ?>


        <!-- BILLING DETAILS -->

        <section class="subscription-card">

            <h4>
                <i class="fas fa-receipt me-2"></i>
                Billing Details
            </h4>

            <p class="card-description">
                Your subscription charges and Eco Points deductions.
            </p>


            <div class="detail-row">

                <span class="detail-label">
                    Service Fee
                </span>

                <span class="detail-value">
                    <?= peso($original_amount); ?>
                </span>

            </div>


            <div class="detail-row">

                <span class="detail-label">
                    Subscription Duration
                </span>

                <span class="detail-value">
                    <?= $months; ?>
                    <?= $months === 1 ? 'Month' : 'Months'; ?>
                </span>

            </div>


            <div class="detail-row">

                <span class="detail-label">
                    Eco Points Redeemed
                </span>

                <span class="detail-value">
                    <?= number_format($subscription_points); ?> points
                </span>

            </div>


            <div class="detail-row">

                <span class="detail-label">
                    Eco Points Discount
                </span>

                <span class="detail-value text-success">
                    - <?= peso($discount_amount); ?>
                </span>

            </div>


            <div class="detail-row">

                <span class="detail-label">
                    Total Subscription Amount
                </span>

                <span class="detail-value">
                    <?= peso($total_amount); ?>
                </span>

            </div>


            <div class="detail-row">

                <span class="detail-label">
                    Amount Paid
                </span>

                <span class="detail-value text-success">
                    <?= peso($amount_paid); ?>
                </span>

            </div>


            <div class="detail-row">

                <span class="detail-label">
                    Remaining Balance
                </span>

                <span class="detail-value">
                    <?= peso($balance_due); ?>
                </span>

            </div>

        </section>



        <!-- PAYMENT SECTION -->

        <section class="subscription-card no-print">

            <h4>
                <i class="fas fa-wallet me-2"></i>
                Payment Information
            </h4>

            <p class="card-description">
                Review your payment status and submit your cash payment notice when applicable.
            </p>


            <?php if ($is_paid): ?>

                <div class="info-box">

                    <i class="fas fa-check-circle me-2"></i>

                    Your subscription payment has been marked as
                    <strong>Paid</strong>.

                </div>


            <?php elseif ($is_pending_payment): ?>

                <div class="warning-box">

                    <i class="fas fa-clock me-2"></i>

                    Your cash payment notice is currently pending confirmation.
                    Please wait for the administrator to verify your payment.

                </div>


            <?php else: ?>

                <div class="warning-box">

                    <i class="fas fa-info-circle me-2"></i>

                    Your payment is not yet confirmed.

                    Please coordinate your cash payment with the authorized
                    collection personnel.

                </div>


                <?php if ($can_submit_cash_notice): ?>

                    <form method="POST">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escapeHtml($csrf_token); ?>">

                        <input type="hidden"
                               name="subscription_id"
                               value="<?= (int) $subscription['id']; ?>">

                        <button type="submit"
                                name="submit_cash_notice"
                                class="btn btn-ecotrack">

                            <i class="fas fa-money-bill-wave me-2"></i>
                            Submit Cash Payment Notice

                        </button>

                    </form>

                <?php endif; ?>

            <?php endif; ?>


            <?php if ($latest_payment): ?>

                <hr class="my-4">

                <h5 class="mb-3">
                    Latest Payment Record
                </h5>


                <div class="detail-row">

                    <span class="detail-label">
                        Payment Amount
                    </span>

                    <span class="detail-value">
                        <?= peso($latest_payment['amount']); ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Payment Status
                    </span>

                    <span class="detail-value">
                        <?= escapeHtml($latest_payment['status']); ?>
                    </span>

                </div>


                <div class="detail-row">

                    <span class="detail-label">
                        Payment Date
                    </span>

                    <span class="detail-value">

                        <?= !empty($latest_payment['paid_at'])
                            ? date('F d, Y h:i A', strtotime($latest_payment['paid_at']))
                            : 'Pending'; ?>

                    </span>

                </div>

            <?php endif; ?>

        </section>



        <!-- STATEMENT OF ACCOUNT -->

        <?php if ($can_print_soa): ?>

            <section class="subscription-card">

                <div class="d-flex justify-content-between align-items-center mb-3 no-print">

                    <div>

                        <h4 class="mb-1">
                            <i class="fas fa-file-invoice me-2"></i>
                            Statement of Account
                        </h4>

                        <p class="card-description mb-0">
                            View and print your subscription billing statement.
                        </p>

                    </div>

                    <button type="button"
                            onclick="window.print()"
                            class="btn btn-outline-ecotrack">

                        <i class="fas fa-print me-2"></i>
                        Print SOA

                    </button>

                </div>


                <div class="soa-print">

                    <div class="soa-header">

                        <h3>EcoTrack</h3>

                        <p>Waste Collection Management System</p>

                        <p>STATEMENT OF ACCOUNT</p>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Resident Name
                        </span>

                        <span class="detail-value">
                            <?= escapeHtml($resident_name); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Subscription Number
                        </span>

                        <span class="detail-value">
                            #<?= (int) $subscription['id']; ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Statement Date
                        </span>

                        <span class="detail-value">
                            <?= date('F d, Y'); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Service Fee
                        </span>

                        <span class="detail-value">
                            <?= peso($original_amount); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Eco Points Discount
                        </span>

                        <span class="detail-value text-success">
                            - <?= peso($discount_amount); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Total Amount
                        </span>

                        <span class="detail-value">
                            <?= peso($total_amount); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Amount Paid
                        </span>

                        <span class="detail-value">
                            <?= peso($amount_paid); ?>
                        </span>

                    </div>


                    <div class="soa-total">

                        <div class="d-flex justify-content-between align-items-center">

                            <span>
                                <strong>Remaining Balance</strong>
                            </span>

                            <strong>
                                <?= peso($balance_due); ?>
                            </strong>

                        </div>

                    </div>


                    <div class="text-center mt-4">

                        <small class="text-muted">
                            This is a system-generated statement of account.
                        </small>

                    </div>

                </div>

            </section>

        <?php endif; ?>


        <?php endif; ?>


        <!-- FOOTER -->

        <div class="text-center py-3">

            <small class="text-muted">
                © <?= date('Y'); ?> EcoTrack Waste Collection Management System
            </small>

        </div>


    </main>

</div>


<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>