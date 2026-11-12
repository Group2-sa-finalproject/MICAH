<?php
session_start();
require_once "../config/database.php";

/* Allow administrators only. */
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../index.php");
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log("Review Sales: database connection is unavailable.");
    http_response_code(500);
    exit("Unable to connect to the database. Please check config/database.php.");
}

$conn->set_charset("utf8mb4");

function e($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/* Build a safe date range. The actual subscriptions table has created_at,
   but does not have paid_at, so created_at is used consistently. */
$today = new DateTimeImmutable("today");
$timeframe = $_GET["timeframe"] ?? "month";

if (!in_array($timeframe, ["week", "month", "custom"], true)) {
    $timeframe = "month";
}

$startDate = $today->modify("first day of this month")->format("Y-m-d");
$endDate = $today->format("Y-m-d");

if ($timeframe === "week") {
    $startDate = $today->modify("monday this week")->format("Y-m-d");
    $endDate = $today->modify("sunday this week")->format("Y-m-d");
} elseif ($timeframe === "custom") {
    $requestedStart = $_GET["start_date"] ?? "";
    $requestedEnd = $_GET["end_date"] ?? "";

    $startObj = DateTime::createFromFormat("!Y-m-d", $requestedStart);
    $endObj = DateTime::createFromFormat("!Y-m-d", $requestedEnd);

    if (
        $startObj && $startObj->format("Y-m-d") === $requestedStart &&
        $endObj && $endObj->format("Y-m-d") === $requestedEnd &&
        $requestedStart <= $requestedEnd
    ) {
        $startDate = $requestedStart;
        $endDate = $requestedEnd;
    } else {
        $timeframe = "month";
    }
}

$search = trim((string)($_GET["search"] ?? ""));
$statusFilter = trim((string)($_GET["status"] ?? ""));

/* Read only payment statuses that actually exist in subscriptions. */
$statusOptions = [];
$statusResult = $conn->query("
    SELECT DISTINCT payment_status
    FROM subscriptions
    WHERE payment_status IS NOT NULL AND payment_status <> ''
    ORDER BY payment_status
");

if (!$statusResult) {
    error_log("Review Sales status query failed: " . $conn->error);
    http_response_code(500);
    exit("Unable to load payment statuses. Please check the database schema.");
}

while ($statusRow = $statusResult->fetch_assoc()) {
    $statusOptions[] = $statusRow["payment_status"];
}
$statusResult->free();

if ($statusFilter !== "" && !in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = "";
}

/* Prepared query conditions shared by the summary and transaction table. */
$where = ["DATE(s.created_at) BETWEEN ? AND ?"];
$params = [$startDate, $endDate];
$types = "ss";

if ($search !== "") {
    $where[] = "(
        u.fullname LIKE ?
        OR u.users LIKE ?
        OR CAST(s.id AS CHAR) LIKE ?
    )";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($statusFilter !== "") {
    $where[] = "s.payment_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$whereSql = "WHERE " . implode(" AND ", $where);

/* Prepare and execute a query using the dynamic filter parameters. */
function runPreparedQuery(mysqli $conn, string $sql, string $types, array $params): mysqli_stmt
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("Review Sales prepare failed: " . $conn->error);
        http_response_code(500);
        exit("Unable to load the sales report. Check the PHP error log for details.");
    }

    if ($types !== "") {
        $bindArgs = [$types];
        foreach ($params as $key => $value) {
            $bindArgs[] = &$params[$key];
        }
        call_user_func_array([$stmt, "bind_param"], $bindArgs);
    }

    if (!$stmt->execute()) {
        error_log("Review Sales execute failed: " . $stmt->error);
        $stmt->close();
        http_response_code(500);
        exit("Unable to load the sales report. Check the PHP error log for details.");
    }

    return $stmt;
}

/* Summary uses only subscriptions and users.
   Revenue is counted only when payment_status is Paid. */
$summarySql = "
    SELECT
        COALESCE(SUM(
            CASE
                WHEN LOWER(s.payment_status) = 'paid'
                THEN s.amount_paid
                ELSE 0
            END
        ), 0) AS revenue_collected,
        COUNT(*) AS transaction_count,
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count,
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid_count,
        COALESCE(SUM(CASE WHEN LOWER(s.payment_status) LIKE '%pending%' THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(GREATEST(s.balance_due, 0)), 0) AS total_balance
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    $whereSql
";

$summaryStmt = runPreparedQuery($conn, $summarySql, $types, $params);
$summaryResult = $summaryStmt->get_result();
$summary = $summaryResult->fetch_assoc() ?: [];
$summaryStmt->close();

$revenueCollected = (float)($summary["revenue_collected"] ?? 0);
$transactionCount = (int)($summary["transaction_count"] ?? 0);
$paidCount = (int)($summary["paid_count"] ?? 0);
$unpaidCount = (int)($summary["unpaid_count"] ?? 0);
$pendingCount = (int)($summary["pending_count"] ?? 0);
$totalBalance = (float)($summary["total_balance"] ?? 0);

/* Transaction records. These are the exact columns present in your SQL dump. */
$listSql = "
    SELECT
        s.id AS subscription_id,
        s.service_fee,
        s.months,
        s.original_amount,
        s.points_redeemed,
        s.discount_amount,
        s.total_amount,
        s.amount_paid,
        s.balance_due,
        s.subscription_status,
        s.payment_status,
        s.created_at,
        u.fullname,
        u.users AS username
    FROM subscriptions s
    INNER JOIN users u ON u.id = s.user_id
    $whereSql
    ORDER BY s.created_at DESC, s.id DESC
";

$listStmt = runPreparedQuery($conn, $listSql, $types, $params);
$listResult = $listStmt->get_result();
$transactions = [];

while ($row = $listResult->fetch_assoc()) {
    $transactions[] = $row;
}
$listStmt->close();

/* CSV export uses the same filtered records. */
if (($_GET["export"] ?? "") === "csv") {
    $filename = "sales-report-" . $startDate . "-to-" . $endDate . ".csv";

    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen("php://output", "w");
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        "Subscription ID", "Resident Full Name", "Username",
        "Service Fee", "Months", "Original Amount", "Points Redeemed",
        "Discount Amount", "Total Amount", "Amount Paid", "Balance Due",
        "Subscription Status", "Payment Status", "Created At"
    ]);

    foreach ($transactions as $row) {
        fputcsv($output, [
            $row["subscription_id"],
            $row["fullname"],
            $row["username"],
            $row["service_fee"],
            $row["months"],
            $row["original_amount"],
            $row["points_redeemed"],
            $row["discount_amount"],
            $row["total_amount"],
            $row["amount_paid"],
            $row["balance_due"],
            $row["subscription_status"],
            $row["payment_status"],
            $row["created_at"]
        ]);
    }

    fclose($output);
    exit;
}

function paymentBadgeClass(string $status): string
{
    $status = strtolower(trim($status));

    if ($status === "paid") {
        return "bg-success";
    }
    if ($status === "unpaid") {
        return "bg-danger";
    }
    if (strpos($status, "pending") !== false) {
        return "bg-warning text-dark";
    }
    return "bg-secondary";
}

$exportParams = $_GET;
unset($exportParams["export"]);
$csvUrl = "sales_report.php?" . http_build_query(
    array_merge($exportParams, ["export" => "csv"])
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review Sales - Waste Collection Management System</title>

    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="../assets/css/style.css" rel="stylesheet">

    <style>
        body {
            background: #f4f7f6;
            font-family: Arial, sans-serif;
            color: #333;
            padding-top: 85px;
        }
        .navbar {
            background: #198754;
            padding: 12px 25px;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .page-container { max-width: 1400px; }
        .page-title { color: #263b32; font-weight: 700; }
        .summary-card, .main-card {
            background: #fff;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }
        .summary-card { padding: 20px; height: 100%; }
        .summary-label { color: #777; font-size: 13px; margin-bottom: 7px; }
        .summary-value { color: #263b32; font-size: 24px; font-weight: 700; }
        .summary-icon {
            width: 44px; height: 44px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            background: #e6f4ec; color: #198754; font-size: 22px;
        }
        .main-card { padding: 22px; margin-bottom: 22px; }
        .section-heading { color: #2d4036; font-size: 17px; font-weight: 700; }
        .form-label { color: #45564d; font-size: 13px; font-weight: 600; }
        .table { font-size: 13px; vertical-align: middle; }
        .table thead th {
            background: #198754;
            color: #fff;
            white-space: nowrap;
            border-color: #198754;
        }
        .table td { padding-top: 12px; padding-bottom: 12px; }
        .resident-name { color: #2d4036; font-weight: 700; }
        .username { color: #818b85; font-size: 12px; }
        .money { white-space: nowrap; }
        .footer {
            color: #929b96; font-size: 12px; text-align: center;
            border-top: 1px solid #e2e9e5; padding: 18px 0; margin-top: 25px;
        }
        @media print {
            body { background: #fff; padding-top: 0; }
            .navbar, .filter-card, .no-print, .footer { display: none !important; }
            .page-container { max-width: 100%; }
            .summary-card, .main-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark">
    <div class="container-fluid">
        <a href="dashboard.php" class="navbar-brand text-white text-decoration-none">
            <i class="bi bi-recycle me-2"></i>Waste Collection Admin
        </a>
        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Dashboard
            </a>
            <a href="../logout.php" class="btn btn-light btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Logout
            </a>
        </div>
    </div>
</nav>

<main class="container page-container py-4">
    <div class="mb-4">
        <h2 class="page-title mb-1">
            <i class="bi bi-cash-coin text-success me-2"></i>Review Sales
        </h2>
        <p class="text-muted mb-0">
            Review monthly or weekly sales reports and tracked subscription payment transactions.
        </p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="summary-label">Total Revenue Collected</div>
                        <div class="summary-value">₱<?= number_format($revenueCollected, 2) ?></div>
                    </div>
                    <div class="summary-icon"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="summary-label">Subscription Transactions</div>
                        <div class="summary-value"><?= number_format($transactionCount) ?></div>
                    </div>
                    <div class="summary-icon"><i class="bi bi-receipt"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="summary-label">Paid Transactions</div>
                        <div class="summary-value"><?= number_format($paidCount) ?></div>
                    </div>
                    <div class="summary-icon"><i class="bi bi-check-circle"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="summary-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="summary-label">Unpaid / Pending</div>
                        <div class="summary-value"><?= number_format($unpaidCount + $pendingCount) ?></div>
                    </div>
                    <div class="summary-icon"><i class="bi bi-hourglass-split"></i></div>
                </div>
            </div>
        </div>
    </div>

    <section class="main-card filter-card">
        <h5 class="section-heading mb-3">
            <i class="bi bi-funnel text-success me-2"></i>Search and Filter
        </h5>

        <form method="get" action="sales_report.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="timeframe" class="form-label">Timeframe</label>
                <select name="timeframe" id="timeframe" class="form-select" onchange="toggleCustomDates()">
                    <option value="week" <?= $timeframe === "week" ? "selected" : "" ?>>This Week</option>
                    <option value="month" <?= $timeframe === "month" ? "selected" : "" ?>>This Month</option>
                    <option value="custom" <?= $timeframe === "custom" ? "selected" : "" ?>>Custom Date Range</option>
                </select>
            </div>
            <div class="col-md-2 custom-date">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" name="start_date" id="start_date" class="form-control" value="<?= e($startDate) ?>">
            </div>
            <div class="col-md-2 custom-date">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" name="end_date" id="end_date" class="form-control" value="<?= e($endDate) ?>">
            </div>
            <div class="col-md-3">
                <label for="search" class="form-label">Search Resident / ID</label>
                <input type="search" name="search" id="search" class="form-control"
                       placeholder="Name, username, or subscription ID" value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Payment Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <option value="<?= e($option) ?>" <?= $statusFilter === $option ? "selected" : "" ?>>
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-search me-1"></i>Apply Filters
                </button>
                <a href="sales_report.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                </a>
                <div class="ms-md-auto d-flex gap-2 no-print">
                    <a href="<?= e($csvUrl) ?>" class="btn btn-outline-success">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                    </a>
                    <button type="button" onclick="window.print()" class="btn btn-outline-dark">
                        <i class="bi bi-printer me-1"></i>Print
                    </button>
                </div>
            </div>
        </form>
    </section>

    <section class="main-card">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <h5 class="section-heading mb-0">Subscription Transactions</h5>
            <span class="small text-muted">
                <?= e(date("M d, Y", strtotime($startDate))) ?> –
                <?= e(date("M d, Y", strtotime($endDate))) ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
                <thead>
                    <tr>
                        <th>Subscription ID</th>
                        <th>Resident</th>
                        <th>Package / Fee Breakdown</th>
                        <th>Amount Paid</th>
                        <th>Balance Due</th>
                        <th>Payment Status</th>
                        <th>Date Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            No subscription transactions found for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php $status = (string)$transaction["payment_status"]; ?>
                        <tr>
                            <td class="fw-bold">#<?= (int)$transaction["subscription_id"] ?></td>
                            <td>
                                <div class="resident-name"><?= e($transaction["fullname"]) ?></div>
                                <div class="username">@<?= e($transaction["username"]) ?></div>
                            </td>
                            <td>
                                <div>Service Fee: <strong>₱<?= number_format((float)$transaction["service_fee"], 2) ?></strong></div>
                                <div>Months: <?= (int)$transaction["months"] ?></div>
                                <div>Original Amount: ₱<?= number_format((float)$transaction["original_amount"], 2) ?></div>
                                <div>Points Redeemed: <?= (int)$transaction["points_redeemed"] ?></div>
                                <div>Discount: ₱<?= number_format((float)$transaction["discount_amount"], 2) ?></div>
                                <div>Total: <strong>₱<?= number_format((float)$transaction["total_amount"], 2) ?></strong></div>
                            </td>
                            <td class="money">₱<?= number_format((float)$transaction["amount_paid"], 2) ?></td>
                            <td class="money">₱<?= number_format((float)$transaction["balance_due"], 2) ?></td>
                            <td>
                                <span class="badge <?= e(paymentBadgeClass($status)) ?>">
                                    <?= e($status !== "" ? $status : "Not Set") ?>
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <?= e(date("M d, Y", strtotime($transaction["created_at"]))) ?>
                                <div class="username"><?= e(date("h:i A", strtotime($transaction["created_at"]))) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="small text-muted mt-3">
            Showing <?= number_format(count($transactions)) ?> subscription record(s).
            Revenue includes only records marked Paid and uses subscriptions.amount_paid.
            Total outstanding balance: ₱<?= number_format($totalBalance, 2) ?>.
        </div>
    </section>

    <div class="footer">
        Waste Collection Management System by Group 2 &copy; <?= date("Y") ?>
        <br>Admin - Review Sales
    </div>
</main>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
function toggleCustomDates() {
    const custom = document.getElementById("timeframe").value === "custom";
    document.querySelectorAll(".custom-date").forEach(function (item) {
        item.style.display = custom ? "" : "none";
    });
}
toggleCustomDates();
</script>
</body>
</html>
