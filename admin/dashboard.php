
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

// ADMIN LOGIN CHECK
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

$conn->set_charset("utf8mb4");

// GET ADMIN INFORMATION
$admin_id = (int) $_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT id, fullname
    FROM users
    WHERE id = ? AND role = 'admin'
    LIMIT 1
");

$stmt->bind_param("i", $admin_id);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// ALLOWED DASHBOARD PAGES
$page = $_GET["page"] ?? "overview";

$allowed_pages = [
    "overview",
    "accounts",
    "routes",
    "feedback",
    "tracking",
    "pickup_schedule",
    "special_pickup_requests"
];

if (!in_array($page, $allowed_pages, true)) {
    $page = "overview";
}

// DASHBOARD STATISTICS
$total_residents = 0;
$total_collectors = 0;
$total_households = 0;
$total_feedback = 0;

$queries = [
    "total_residents" =>
        "SELECT COUNT(*) AS total FROM users WHERE role='residents'",

    "total_collectors" =>
        "SELECT COUNT(*) AS total FROM users WHERE role IN ('collector','collectors')",

    "total_households" =>
        "SELECT COUNT(*) AS total FROM households",

    "total_feedback" =>
        "SELECT COUNT(*) AS total FROM feedback"
];

foreach ($queries as $key => $sql) {
    $result = $conn->query($sql);

    if ($result) {
        $$key = (int) $result->fetch_assoc()["total"];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Admin Dashboard | EcoTrack</title>

<link rel="stylesheet" href="../assets/vendor/bootstrap/css/bootstrap.min.css">

<link rel="stylesheet" href="../assets/css/style.css">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<!-- LEAFLET: LOAD ONLY ON TRACKING PAGE -->
<?php if ($page === "tracking"): ?>

<link rel="stylesheet"
href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<?php endif; ?>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f7f5;
    font-family: Arial, sans-serif;
}

/* SIDEBAR */

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

.logo {
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 35px;
    padding-left: 12px;
}

.logo i {
    color: #80d89b;
    margin-right: 8px;
}

.sidebar-title {
    color: #a9c4b4;
    font-size: 11px;
    margin: 20px 12px 10px;
    text-transform: uppercase;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 14px;
    margin-bottom: 7px;
    border-radius: 8px;
    color: #dcebe1;
    text-decoration: none;
    font-size: 14px;
    transition: .2s;
}

.nav-item:hover,
.nav-item.active {
    background: #24764b;
    color: white;
}

.nav-icon {
    width: 22px;
    text-align: center;
}

.logout-link {
    margin-top: 35px;
    color: #ffd5d5;
}

/* MAIN CONTENT */

.main-content {
    margin-left: 250px;
    min-height: 100vh;
}

.topbar {
    background: white;
    padding: 18px 30px;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    border-bottom: 1px solid #e5eae6;
}

.admin-info {
    color: #52645a;
    font-size: 14px;
}

.content {
    padding: 30px;
    min-width: 0;
}

.welcome {
    margin-bottom: 25px;
}

.welcome h2 {
    color: #183d2b;
    font-size: 25px;
    font-weight: bold;
}

.welcome p {
    color: #77847c;
}

/* DASHBOARD CARDS */

.dashboard-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-top: 25px;
}

.dashboard-card {
    background: white;
    border-radius: 12px;
    padding: 23px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.dashboard-card h6 {
    color: #7b8980;
    font-size: 13px;
    margin-bottom: 12px;
}

.dashboard-card h2 {
    color: #183d2b;
    font-size: 28px;
    font-weight: bold;
    margin: 0;
}

.card-icon {
    width: 43px;
    height: 43px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e5f5e9;
    color: #23834b;
    margin-bottom: 15px;
    font-size: 19px;
}

/* RESPONSIVE */

@media(max-width: 1000px) {
    .dashboard-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 700px) {

    .sidebar {
        width: 70px;
        padding: 20px 8px;
    }

    .logo {
        font-size: 0;
        padding-left: 13px;
    }

    .logo i {
        font-size: 23px;
    }

    .sidebar-title,
    .nav-item span:not(.nav-icon) {
        display: none;
    }

    .nav-item {
        justify-content: center;
    }

    .main-content {
        margin-left: 70px;
    }

    .content {
        padding: 15px;
    }

    .dashboard-cards {
        grid-template-columns: 1fr;
    }

    .topbar {
        padding: 15px;
    }
}

</style>

</head>

<body>

<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="logo">
        <i class="fas fa-recycle"></i> EcoTrack
    </div>

    <div class="sidebar-title">Main Menu</div>

    <?php

    $nav = [

        "overview" => [
            "chart-pie",
            "Dashboard"
        ],

        "accounts" => [
            "users",
            "Accounts"
        ],

        "routes" => [
            "route",
            "Collection Routes"
        ],

        "tracking" => [
            "truck",
            "Live Collector Tracking"
        ],

        "pickup_schedule" => [
            "calendar-alt",
            "Pickup Schedule"
        ],

        "special_pickup_requests" => [
            "calendar-check",
            "Special Pickup Requests"
        ],

        "feedback" => [
            "comments",
            "Resident Feedback"
        ]

    ];

    foreach ($nav as $key => $item):

    ?>

    <a href="dashboard.php?page=<?= htmlspecialchars($key) ?>"
       class="nav-item <?= $page === $key ? 'active' : '' ?>">

        <span class="nav-icon">
            <i class="fas fa-<?= htmlspecialchars($item[0]) ?>"></i>
        </span>

        <span>
            <?= htmlspecialchars($item[1]) ?>
        </span>

    </a>

    <?php endforeach; ?>

    <div class="sidebar-title">Account</div>

    <a href="../logout.php" class="nav-item logout-link">

        <span class="nav-icon">
            <i class="fas fa-sign-out-alt"></i>
        </span>

        <span>Logout</span>

    </a>

</aside>

<!-- MAIN CONTENT -->

<div class="main-content">

    <!-- TOPBAR -->

    <div class="topbar">

        <div class="admin-info">

            <i class="fas fa-user-circle"></i>

            <?= htmlspecialchars(
                $admin["fullname"],
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    </div>

    <!-- PAGE CONTENT -->

    <div class="content">

        <?php if ($page === "overview"): ?>

            <div class="welcome">

                <h2>
                    Welcome, <?= htmlspecialchars($admin["fullname"]) ?>!
                </h2>

                <p>
                    Monitor and manage your waste collection system.
                </p>

            </div>

            <div class="dashboard-cards">

                <?php

                $cards = [

                    ["users", "Total Residents", $total_residents],

                    ["truck", "Total Collectors", $total_collectors],

                    ["house", "Total Households", $total_households],

                    ["comments", "Total Feedback", $total_feedback]

                ];

                foreach ($cards as $c):

                ?>

                <div class="dashboard-card">

                    <div class="card-icon">
                        <i class="fas fa-<?= htmlspecialchars($c[0]) ?>"></i>
                    </div>

                    <h6><?= htmlspecialchars($c[1]) ?></h6>

                    <h2><?= (int) $c[2] ?></h2>

                </div>

                <?php endforeach; ?>

            </div>

        <?php elseif ($page === "accounts"): ?>

            <?php include __DIR__ . "/accounts.php"; ?>

        <?php elseif ($page === "feedback"): ?>

            <?php include __DIR__ . "/feedback.php"; ?>

        <?php elseif ($page === "routes"): ?>

            <?php include __DIR__ . "/admin_routes.php"; ?>

        <?php elseif ($page === "tracking"): ?>

            <!-- CONNECTED TRACKING PAGE -->
            <?php include __DIR__ . "/track_trucks.php"; ?>

        <?php elseif ($page === "pickup_schedule"): ?>

            <?php include __DIR__ . "/pickup_schedule.php"; ?>

        <?php elseif ($page === "special_pickup_requests"): ?>

            <?php include __DIR__ . "/special_pickup_requests.php"; ?>

        <?php endif; ?>

    </div>

</div>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>
