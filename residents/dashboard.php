
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

// RESIDENT LOGIN CHECK
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "residents"
) {
    header("Location: ../index.php");
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is unavailable.");
}

$conn->set_charset("utf8mb4");

$user_id = (int) $_SESSION["user_id"];

// GET RESIDENT INFORMATION
$stmt = $conn->prepare("
    SELECT id, fullname, users, role
    FROM users
    WHERE id = ?
      AND role = 'residents'
    LIMIT 1
");

if (!$stmt) {
    die("Unable to retrieve resident information.");
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// GET SUBSCRIPTION INFORMATION
$subscription_sql = $conn->prepare("
    SELECT *
    FROM subscriptions
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if (!$subscription_sql) {
    die("Subscription query failed: " . $conn->error);
}

$subscription_sql->bind_param("i", $user_id);
$subscription_sql->execute();

$subscription = $subscription_sql->get_result()->fetch_assoc();
$subscription_sql->close();

// SUBSCRIPTION DEFAULT VALUES
$subscription_payment_status = $subscription['payment_status']
    ?? 'No Subscription';

$subscription_status = $subscription['subscription_status']
    ?? 'Not Subscribed';

$subscription_amount_paid = (float) (
    $subscription['amount_paid'] ?? 0
);

$subscription_balance_due = (float) (
    $subscription['balance_due'] ?? 0
);

$subscription_discount = (float) (
    $subscription['discount_amount'] ?? 0
);

if (isset($subscription['total_amount'])) {
    $subscription_total_amount = (float) $subscription['total_amount'];
} else {
    $original_amount = (float) (
        $subscription['original_amount'] ?? 0
    );

    $subscription_total_amount = max(
        0,
        $original_amount - $subscription_discount
    );
}

$subscription_date = $subscription['created_at'] ?? null;

$subscription_is_paid =
    strtolower((string) $subscription_payment_status) === 'paid';

// FINAL SUBSCRIPTION DEFAULT VALUES
if (!$subscription) {
    $subscription_payment_status = "No Subscription";
    $subscription_total_amount = 0;
    $subscription_amount_paid = 0;
    $subscription_balance_due = 0;
    $subscription_status = "Not Subscribed";
    $subscription_date = null;
} else {
    $subscription_payment_status = $subscription["payment_status"] ?? "Unpaid";
    $subscription_total_amount = (float) (
        $subscription["total_amount"] ?? 0
    );
    $subscription_amount_paid = (float) (
        $subscription["amount_paid"] ?? 0
    );
    $subscription_balance_due = (float) (
        $subscription["balance_due"] ?? 0
    );
    $subscription_status = $subscription["subscription_status"] ?? "Pending";
    $subscription_date = $subscription["created_at"] ?? null;
}

$subscription_is_paid =
    strtolower((string) $subscription_payment_status) === "paid";

// GET LATEST PAYMENT
$payment_sql = $conn->prepare("
    SELECT amount, status, paid_at
    FROM resident_payments
    WHERE user_id = ?
    ORDER BY id DESC
    LIMIT 1
");

if (!$payment_sql) {
    die("Payment query failed.");
}

$payment_sql->bind_param("i", $user_id);
$payment_sql->execute();

$payment = $payment_sql->get_result()->fetch_assoc();
$payment_sql->close();

// PAYMENT DEFAULT VALUES
if (!$payment) {
    $payment_status = "Unpaid";
    $payment_amount = 0;
    $paid_at = null;
} else {
    $payment_status = $payment["status"] ?? "Unpaid";
    $payment_amount = (float) ($payment["amount"] ?? 0);
    $paid_at = $payment["paid_at"] ?? null;
}

$is_paid = strtolower((string) $payment_status) === "paid";

// ESCAPE OUTPUT HELPER
function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// STATUS BADGE HELPERS
$subscription_payment_badge = "status-unpaid";

if ($subscription_is_paid) {
    $subscription_payment_badge = "status-paid";
} elseif (
    stripos($subscription_payment_status, "pending") !== false
) {
    $subscription_payment_badge = "status-pending";
}

$latest_payment_badge = "status-unpaid";

if ($is_paid) {
    $latest_payment_badge = "status-paid";
} elseif (
    stripos($payment_status, "pending") !== false
) {
    $latest_payment_badge = "status-pending";
}

$subscription_status_badge = "status-default";

if (stripos($subscription_status, "pending") !== false) {
    $subscription_status_badge = "status-pending";
} elseif (
    stripos($subscription_status, "approved") !== false
    || stripos($subscription_status, "active") !== false
) {
    $subscription_status_badge = "status-paid";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Resident Dashboard | EcoTrack</title>

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
   SECTION TITLE
========================================================= */

.section-title {
    color: #45564d;
    font-size: 17px;
    font-weight: 700;
    margin: 30px 0 18px;
}

/* =========================================================
   DASHBOARD CARDS
========================================================= */

.dashboard-card {
    background: #fff;
    padding: 25px;
    border-radius: 13px;
    box-shadow: 0 3px 12px rgba(0,0,0,.05);
    border: 1px solid #edf1ee;
    height: 100%;
    transition: .2s ease;
}

.dashboard-card h5 {
    font-size: 18px;
    font-weight: 800;
    color: #183d2b;
    margin-bottom: 12px;
}

.dashboard-card p {
    color: #78877e;
    font-size: 13px;
    line-height: 1.6;
}

.dashboard-icon {
    width: 46px;
    height: 46px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e7f5eb;
    color: #17643b;
    margin-bottom: 15px;
    font-size: 19px;
}

/* =========================================================
   SERVICE CARDS
========================================================= */

.service-card {
    display: flex;
    flex-direction: column;
}

.service-card p {
    flex-grow: 1;
}

.service-card .btn {
    margin-top: 12px;
}

/* =========================================================
   ACCOUNT TABLE
========================================================= */

.info-table {
    margin-bottom: 0;
    border-color: #edf1ee;
}

.info-table th {
    width: 35%;
    color: #52635a;
    font-weight: 600;
    font-size: 14px;
    padding: 12px;
    background: #f8faf8;
}

.info-table td {
    color: #40594a;
    font-size: 14px;
    padding: 12px;
}

/* =========================================================
   DETAIL ROWS
========================================================= */

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
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
    overflow-wrap: anywhere;
}

/* =========================================================
   STATUS BADGES
========================================================= */

.payment-status,
.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: 700;
}

.status-paid {
    background: #d8f3df;
    color: #17643b;
}

.status-unpaid {
    background: #f8d7da;
    color: #842029;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-default {
    background: #e9ecef;
    color: #495057;
}

/* =========================================================
   PAYMENT
========================================================= */

.payment-amount {
    font-size: 26px;
    font-weight: 800;
    color: #183d2b;
    margin-top: 3px;
}

.payment-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #183d2b;
    margin-bottom: 15px;
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

/* =========================================================
   ALERTS
========================================================= */

.alert {
    border-radius: 10px;
    font-size: 13px;
}

/* =========================================================
   FOOTER
========================================================= */

.dashboard-footer {
    margin-top: 35px;
    padding: 20px 0;
    border-top: 1px solid #e2e9e5;
    color: #929b96;
    font-size: 12px;
    text-align: center;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {

    .content {
        padding: 20px;
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

    .section-title {
        font-size: 16px;
    }

    .dashboard-card {
        padding: 18px;
    }

    .payment-amount {
        font-size: 23px;
    }

    .info-table th,
    .info-table td {
        font-size: 12px;
        padding: 9px;
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
            <a href="dashboard.php" class="active">
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
                    <?= e($user["fullname"]); ?>
                </div>

                <span class="profile-role">
                    Resident
                </span>
            </div>

        </div>

    </div>


    <!-- PAGE CONTENT -->

    <main class="content">

        <!-- PAGE HEADER -->

        <div class="page-heading">

            <h2>
                <i class="fas fa-th-large me-2"></i>
                Resident Dashboard
            </h2>

            <p>
                Monitor your resident account, subscription,
                and available waste collection services.
            </p>

        </div>


        <!-- ACCOUNT INFORMATION -->

        <h6 class="section-title">
            <i class="fas fa-user-circle me-2"></i>
            My Account
        </h6>

        <div class="row g-4 mb-4">

            <!-- ACCOUNT DETAILS -->

            <div class="col-lg-7">

                <div class="dashboard-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-id-card"></i>
                    </div>

                    <h5>Account Information</h5>

                    <p>
                        Your registered resident account details.
                    </p>

                    <div class="table-responsive">

                        <table class="table table-bordered info-table mt-3">

                            <tbody>

                                <tr>
                                    <th>Resident ID</th>
                                    <td><?= e($user["id"]); ?></td>
                                </tr>

                                <tr>
                                    <th>Full Name</th>
                                    <td><?= e($user["fullname"]); ?></td>
                                </tr>

                                <tr>
                                    <th>Username</th>
                                    <td><?= e($user["users"]); ?></td>
                                </tr>

                                <tr>
                                    <th>Account Type</th>
                                    <td>Resident</td>
                                </tr>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>


            <!-- SUBSCRIPTION AND PAYMENT -->

            <div class="col-lg-5">

                <div class="dashboard-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-wallet"></i>
                    </div>

                    <h5>Subscription Billing & Payment</h5>

                    <p>
                        View your subscription balance
                        and latest payment.
                    </p>

                    <hr>

                    <h6 class="payment-section-title">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Subscription Billing
                    </h6>

                    <p class="mb-2">
                        Subscription Payment Status
                    </p>

                    <?php if (!$subscription): ?>

                        <span class="payment-status status-unpaid">
                            NO SUBSCRIPTION
                        </span>

                    <?php else: ?>

                        <span class="payment-status <?= $subscription_payment_badge; ?>">

                            <?php if ($subscription_is_paid): ?>
                                <i class="fas fa-check-circle me-1"></i>
                            <?php endif; ?>

                            <?= e(strtoupper($subscription_payment_status)); ?>

                        </span>

                    <?php endif; ?>


                    <p class="mt-3 mb-1">
                        Subscription Status
                    </p>

                    <span class="status-badge <?= $subscription_status_badge; ?>">
                        <?= e($subscription_status); ?>
                    </span>


                    <p class="mt-3 mb-1">
                        Subscription Amount
                    </p>

                    <div class="payment-amount">
                        ₱<?= number_format((float) $subscription_total_amount, 2); ?>
                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Amount Paid
                        </span>

                        <span class="detail-value text-success">
                            ₱<?= number_format((float) $subscription_amount_paid, 2); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Remaining Balance
                        </span>

                        <span class="detail-value">
                            ₱<?= number_format((float) $subscription_balance_due, 2); ?>
                        </span>

                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Subscription Date
                        </span>

                        <span class="detail-value">

                            <?= $subscription_date
                                ? e(date("F d, Y", strtotime($subscription_date)))
                                : "No subscription yet"; ?>

                        </span>

                    </div>


                    <?php if (!$subscription): ?>

                        <div class="warning-box mt-3">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            You have not subscribed yet.
                            Please visit the Subscription page to register.
                        </div>

                    <?php elseif (!$subscription_is_paid): ?>

                        <div class="warning-box mt-3">
                            <i class="fas fa-clock me-2"></i>
                            Your subscription payment is not yet fully recorded.
                            Please visit the Subscription page for payment information.
                        </div>

                    <?php else: ?>

                        <div class="info-box mt-3">
                            <i class="fas fa-check-circle me-2"></i>
                            Your subscription payment is recorded as paid.
                        </div>

                    <?php endif; ?>


                    <a href="subscription.php"
                       class="btn btn-ecotrack w-100 mt-2">

                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        View Subscription Details

                    </a>


                    <hr class="my-4">


                    <!-- LATEST PAYMENT -->

                    <h6 class="payment-section-title">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        Latest Recorded Payment
                    </h6>

                    <p class="mb-2">
                        Payment Status
                    </p>

                    <span class="payment-status <?= $latest_payment_badge; ?>">
                        <?= e(strtoupper($payment_status)); ?>
                    </span>


                    <p class="mt-3 mb-1">
                        Payment Amount
                    </p>

                    <div class="payment-amount">
                        ₱<?= number_format((float) $payment_amount, 2); ?>
                    </div>


                    <div class="detail-row">

                        <span class="detail-label">
                            Date Paid
                        </span>

                        <span class="detail-value">

                            <?= ($is_paid && !empty($paid_at))
                                ? e(date("F d, Y h:i A", strtotime($paid_at)))
                                : "No payment recorded"; ?>

                        </span>

                    </div>


                    <?php if (!$is_paid): ?>

                        <div class="warning-box mt-3">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Your payment has not yet been recorded as paid.
                            Please contact the administrator if you have already paid.
                        </div>

                    <?php else: ?>

                        <div class="info-box mt-3">
                            <i class="fas fa-check-circle me-2"></i>
                            Your payment is recorded as paid.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- RESIDENT SERVICES -->

        <h6 class="section-title">

            <i class="fas fa-th-large me-2"></i>

            Resident Services

        </h6>

        <div class="row g-4">

            <!-- ECO POINTS -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-star"></i>
                    </div>

                    <h5>Eco Points</h5>

                    <p>
                        Check your earned Eco Points and monitor
                        your environmental contributions.
                    </p>

                    <a href="eco_points.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>


            <!-- COLLECTION SCHEDULE -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>

                    <h5>Collection Schedule</h5>

                    <p>
                        View your garbage collection schedule
                        and upcoming collection dates.
                    </p>

                    <a href="collection_schedule.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>


            <!-- TRACK GARBAGE TRUCK -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-truck"></i>
                    </div>

                    <h5>Track Garbage Truck</h5>

                    <p>
                        Monitor the garbage truck's location
                        and collection progress.
                    </p>

                    <a href="track_truck.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>


            <!-- SUBSCRIPTION -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>

                    <h5>Subscription</h5>

                    <p>
                        Manage your garbage collection subscription
                        and view your billing information.
                    </p>

                    <a href="subscription.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>


            <!-- REGISTER HOUSEHOLD -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-house-user"></i>
                    </div>

                    <h5>Register Household</h5>

                    <p>
                        Register your household address and mark
                        your house location on the map.
                    </p>

                    <a href="register_household.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>


            <!-- FEEDBACK -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-comment-dots"></i>
                    </div>

                    <h5>Send Feedback</h5>

                    <p>
                        Share your suggestions and comments
                        to improve garbage collection services.
                    </p>

                    <a href="feedback.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>


            <!-- QR CODE -->

            <div class="col-md-6 col-lg-4">

                <div class="dashboard-card service-card">

                    <div class="dashboard-icon">
                        <i class="fas fa-qrcode"></i>
                    </div>

                    <h5>Resident QR Code</h5>

                    <p>
                        View your unique resident QR code
                        for quick identification and verification.
                    </p>

                    <a href="qrcode.php"
                       class="btn btn-ecotrack w-100">

                        <i class="fas fa-arrow-right me-2"></i>
                        View Details

                    </a>

                </div>

            </div>

        </div>


        <!-- FOOTER -->

        <div class="dashboard-footer">

            © <?= date("Y"); ?> EcoTrack Waste Collection Management System

            <br>

            Resident Dashboard

        </div>

    </main>

</div>


<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
