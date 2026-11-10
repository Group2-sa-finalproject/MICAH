<?php
session_start();
include "../config/database.php";

// Only residents can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] != "residents"
) {
    header("Location: ../index.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

$message = "";
$message_type = "";

// Get selected month
$month = $_GET["month"] ?? date("Y-m");

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date("Y-m");
}

$first_day = new DateTime($month . "-01");
$last_day = clone $first_day;
$last_day->modify("last day of this month");

$today = date("Y-m-d");

// Schedule a collection
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["schedule"])) {

    $collection_date = $_POST["collection_date"] ?? "";
    $collection_address = trim($_POST["collection_address"] ?? "");
    $requested_time = trim($_POST["requested_time"] ?? "");
    $notes = trim($_POST["notes"] ?? "");

    $valid_date = DateTime::createFromFormat("!Y-m-d", $collection_date);

    if (
        !$valid_date ||
        $valid_date->format("Y-m-d") !== $collection_date
    ) {
        $message = "Please select a valid collection date.";
        $message_type = "error";

    } elseif ($collection_date < $today) {

        $message = "You cannot schedule a collection on a past date.";
        $message_type = "error";

    } elseif ($collection_address == "") {

        $message = "Please enter your collection address.";
        $message_type = "error";

    } elseif (strlen($collection_address) > 255) {

        $message = "The address must not exceed 255 characters.";
        $message_type = "error";

    } elseif (empty($requested_time)) {

        $message = "Please select your preferred collection time.";
        $message_type = "error";

    } else {

        // Check if the selected date is already booked
        $check = mysqli_prepare(
            $conn,
            "SELECT id
             FROM collection_schedules
             WHERE collection_date = ?
             AND status IN ('Pending', 'Approved')
             LIMIT 1"
        );

        if (!$check) {
            die("Database error: " . mysqli_error($conn));
        }

        mysqli_stmt_bind_param($check, "s", $collection_date);
        mysqli_stmt_execute($check);
        $result = mysqli_stmt_get_result($check);

        if (mysqli_num_rows($result) > 0) {

            $message = "Sorry! This date is already booked. Please choose another date.";
            $message_type = "error";

        } else {

            // Save the schedule
            $insert = mysqli_prepare(
                $conn,
                "INSERT INTO collection_schedules
                (user_id, collection_date, requested_time, collection_address, notes)
                VALUES (?, ?, ?, ?, ?)"
            );

            if (!$insert) {
                die("Database error: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param(
                $insert,
                "issss",
                $user_id,
                $collection_date,
                $requested_time,
                $collection_address,
                $notes
            );

            if (mysqli_stmt_execute($insert)) {

                $message = "Collection scheduled successfully! Your request is Pending approval.";
                $message_type = "success";

            } else {

                if (mysqli_errno($conn) == 1062) {
                    $message = "Sorry! Another resident has already booked this date. Please choose another date.";
                } else {
                    $message = "Unable to save your schedule. Please try again.";
                }

                $message_type = "error";
            }

            mysqli_stmt_close($insert);
        }

        mysqli_stmt_close($check);
    }
}

// Get booked dates in selected month
$booked_dates = [];

$start_date = $first_day->format("Y-m-d");
$end_date = $last_day->format("Y-m-d");

$booked_query = mysqli_prepare(
    $conn,
    "SELECT collection_date
     FROM collection_schedules
     WHERE collection_date BETWEEN ? AND ?
     AND status IN ('Pending', 'Approved')"
);

if (!$booked_query) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($booked_query, "ss", $start_date, $end_date);
mysqli_stmt_execute($booked_query);

$booked_result = mysqli_stmt_get_result($booked_query);

while ($row = mysqli_fetch_assoc($booked_result)) {
    $booked_dates[] = $row["collection_date"];
}

mysqli_stmt_close($booked_query);

// Get resident collection history
$history = mysqli_prepare(
    $conn,
    "SELECT id, collection_date, requested_time,
            collection_address, notes, status
     FROM collection_schedules
     WHERE user_id = ?
     ORDER BY collection_date DESC, id DESC"
);

if (!$history) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($history, "i", $user_id);
mysqli_stmt_execute($history);

$history_result = mysqli_stmt_get_result($history);

// Calendar navigation
$previous_month = clone $first_day;
$previous_month->modify("-1 month");

$next_month = clone $first_day;
$next_month->modify("+1 month");

$starting_day = (int) $first_day->format("w");
$days_in_month = (int) $first_day->format("t");

// Escape output
function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Collection Schedule | EcoTrack</title>

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
            font-family: Arial, sans-serif;
            color: #333;
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

        .resident-info {
            color: #52645a;
            font-size: 14px;
        }

        .content {
            padding: 30px;
            min-width: 0;
        }

        .page-title h2 {
            color: #183d2b;
            font-weight: bold;
        }

        .page-title p {
            color: #77847c;
        }

        /* CARDS */
        .schedule-card {
            background: white;
            border: 1px solid #e8eeea;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .schedule-card h3 {
            color: #198754;
            font-size: 19px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        /* CALENDAR */
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }

        .calendar-header h3 {
            margin: 0;
            color: #183d2b;
            text-align: center;
        }

        .nav-button {
            background: #198754;
            color: white;
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 5px;
            white-space: nowrap;
            border: none;
        }

        .nav-button:hover {
            background: #146c43;
            color: white;
        }

        .calendar {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 5px;
        }

        .day-name {
            text-align: center;
            font-weight: bold;
            padding: 10px 0;
            background: #e9ecef;
            border-radius: 4px;
        }

        .day {
            min-height: 85px;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: white;
        }

        .day-number {
            font-weight: bold;
            display: block;
            margin-bottom: 8px;
        }

        .available {
            background: #e8f7ee;
            border-color: #8bd2a5;
        }

        .booked {
            background: #fdeaea;
            border-color: #e8aaaa;
        }

        .past {
            background: #eeeeee;
            color: #888;
        }

        .available-button {
            display: block;
            width: 100%;
            border: none;
            padding: 6px 2px;
            background: #198754;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .available-button:hover {
            background: #146c43;
        }

        .booked-label,
        .past-label {
            font-size: 11px;
            display: block;
            line-height: 1.4;
        }

        .booked-label {
            color: #b02a37;
        }

        .past-label {
            color: #666;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 18px;
            font-size: 13px;
        }

        /* FORM */
        .message {
            padding: 12px 15px;
            margin-bottom: 18px;
            border-radius: 5px;
        }

        .success {
            background: #d1e7dd;
            color: #0f5132;
        }

        .error {
            background: #f8d7da;
            color: #842029;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin: 15px 0 6px;
            font-weight: bold;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font: inherit;
        }

        .form-group textarea {
            min-height: 90px;
            resize: vertical;
        }

        .submit-button {
            margin-top: 10px;
            padding: 12px 18px;
            background: #198754;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .submit-button:hover {
            background: #146c43;
        }

        /* HISTORY TABLE */
        .table-wrapper {
            overflow-x: auto;
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
            font-size: 14px;
        }

        .history-table th,
        .history-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .history-table th {
            background: #198754;
            color: white;
        }

        .status {
            font-weight: bold;
        }

        .status-pending {
            color: #b58100;
        }

        .status-approved {
            color: #198754;
        }

        .status-rejected {
            color: #dc3545;
        }

        .status-completed {
            color: #0d6efd;
        }

        .small-note {
            color: #666;
            font-size: 13px;
            line-height: 1.5;
            margin-top: 15px;
        }

        .dashboard-footer {
            margin-top: 35px;
            padding: 20px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        /* RESPONSIVE */
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

            .topbar {
                padding: 15px;
            }

            .schedule-card {
                padding: 15px;
            }

            .day {
                min-height: 65px;
                padding: 4px;
            }

            .day-name {
                font-size: 11px;
            }

            .day-number {
                font-size: 12px;
            }

            .available-button {
                font-size: 10px;
            }

            .booked-label,
            .past-label {
                font-size: 9px;
            }

            .calendar-header h3 {
                font-size: 16px;
            }

            .nav-button {
                padding: 8px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>

<!-- RESIDENT SIDEBAR -->
<aside class="sidebar">

    <div class="logo">
        <i class="fas fa-recycle"></i> EcoTrack
    </div>

    <div class="sidebar-title">Main Menu</div>

    <a href="dashboard.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-chart-pie"></i></span>
        <span>Dashboard</span>
    </a>

    <a href="eco_points.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-star"></i></span>
        <span>Eco Points</span>
    </a>

    <a href="collection_schedule.php" class="nav-item active">
        <span class="nav-icon"><i class="fas fa-calendar-alt"></i></span>
        <span>Collection Schedule</span>
    </a>

    <a href="track_truck.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-truck"></i></span>
        <span>Track Garbage Truck</span>
    </a>

    <a href="subscription.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-credit-card"></i></span>
        <span>Subscription</span>
    </a>

    <a href="register_household.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-house"></i></span>
        <span>Register Household</span>
    </a>

    <a href="feedback.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-comments"></i></span>
        <span>Feedback</span>
    </a>

    <a href="qrcode.php" class="nav-item">
        <span class="nav-icon"><i class="fas fa-qrcode"></i></span>
        <span>Resident QR Code</span>
    </a>

    <div class="sidebar-title">Account</div>

    <a href="../logout.php" class="nav-item logout-link">
        <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
        <span>Logout</span>
    </a>

</aside>

<!-- MAIN CONTENT -->
<div class="main-content">

    <div class="topbar">
        <div class="resident-info">
            <i class="fas fa-user-circle"></i>
            Resident Account
        </div>
    </div>

    <div class="content">

        <div class="page-title mb-4">
            <h2>
                <i class="fas fa-calendar-alt me-2"></i>
                Garbage Collection Schedule
            </h2>

            <p>
                Select an available date to request garbage collection.
            </p>
        </div>

        <?php if ($message != ""): ?>
            <div class="message <?= h($message_type) ?>">
                <?= h($message) ?>
            </div>
        <?php endif; ?>

        <!-- CALENDAR CARD -->
        <div class="schedule-card">

            <h3>Available Collection Dates</h3>

            <p>
                Select an available date to request garbage collection.
                Only one resident can reserve each date.
            </p>

            <div class="calendar-header">

                <a class="nav-button"
                   href="?month=<?= h($previous_month->format('Y-m')) ?>">
                    &laquo; Previous
                </a>

                <h3><?= h($first_day->format("F Y")) ?></h3>

                <a class="nav-button"
                   href="?month=<?= h($next_month->format('Y-m')) ?>">
                    Next &raquo;
                </a>

            </div>

            <div class="calendar">

                <?php
                $weekdays = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

                foreach ($weekdays as $weekday):
                ?>
                    <div class="day-name">
                        <?= h($weekday) ?>
                    </div>
                <?php endforeach; ?>

                <?php for ($i = 0; $i < $starting_day; $i++): ?>
                    <div class="day"></div>
                <?php endfor; ?>

                <?php
                for ($day = 1; $day <= $days_in_month; $day++):

                    $date = $first_day->format("Y-m-") .
                        str_pad($day, 2, "0", STR_PAD_LEFT);

                    $is_past = $date < $today;
                    $is_booked = in_array($date, $booked_dates, true);

                    if ($is_past) {
                        $class = "past";
                    } elseif ($is_booked) {
                        $class = "booked";
                    } else {
                        $class = "available";
                    }
                ?>

                    <div class="day <?= h($class) ?>">

                        <span class="day-number">
                            <?= $day ?>
                        </span>

                        <?php if ($is_past): ?>

                            <span class="past-label">Past date</span>

                        <?php elseif ($is_booked): ?>

                            <span class="booked-label">
                                Already booked
                            </span>

                        <?php else: ?>

                            <button
                                type="button"
                                class="available-button"
                                onclick="selectDate('<?= h($date) ?>')">
                                Select date
                            </button>

                        <?php endif; ?>

                    </div>

                <?php endfor; ?>

            </div>

            <div class="legend">
                <span>🟩 Available</span>
                <span>🟥 Already booked</span>
                <span>⬜ Past date</span>
            </div>

            <p class="small-note">
                A date becomes available again after the previous request
                is marked Completed or Rejected by authorized staff.
            </p>

        </div>

        <!-- SCHEDULE FORM -->
        <div class="schedule-card" id="schedule-form-card">

            <h3>Submit Collection Request</h3>

            <p>
                Complete the form below to request a garbage collection.
            </p>

            <form method="POST" action="" id="schedule-form">

                <div class="form-group">
                    <label for="collection_date">Collection Date</label>

                    <input
                        type="date"
                        id="collection_date"
                        name="collection_date"
                        min="<?= h($today) ?>"
                        value="<?= h($_POST['collection_date'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="requested_time">Requested Time</label>

                    <input
                        type="time"
                        id="requested_time"
                        name="requested_time"
                        value="<?= h($_POST['requested_time'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="collection_address">Collection Address</label>

                    <input
                        type="text"
                        id="collection_address"
                        name="collection_address"
                        maxlength="255"
                        value="<?= h($_POST['collection_address'] ?? '') ?>"
                        placeholder="Enter your complete collection address"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="notes">Additional Notes (Optional)</label>

                    <textarea
                        id="notes"
                        name="notes"
                        placeholder="Example: Garbage is placed outside the gate."
                    ><?= h($_POST['notes'] ?? '') ?></textarea>
                </div>

                <button
                    type="submit"
                    name="schedule"
                    class="submit-button">
                    <i class="fas fa-calendar-check me-1"></i>
                    Submit Collection Request
                </button>

            </form>

        </div>

        <!-- COLLECTION HISTORY -->
        <div class="schedule-card">

            <h3>My Collection Requests</h3>

            <div class="table-wrapper">

                <table class="history-table">

                    <thead>
                        <tr>
                            <th>Collection Date</th>
                            <th>Requested Time</th>
                            <th>Address</th>
                            <th>Notes</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (mysqli_num_rows($history_result) > 0): ?>

                            <?php while ($row = mysqli_fetch_assoc($history_result)): ?>

                                <?php
                                $status_class = "status-" .
                                    strtolower(trim($row["status"]));
                                ?>

                                <tr>
                                    <td>
                                        <?= h($row["collection_date"]) ?>
                                    </td>

                                    <td>
                                        <?= h($row["requested_time"]) ?>
                                    </td>

                                    <td>
                                        <?= h($row["collection_address"]) ?>
                                    </td>

                                    <td>
                                        <?= h($row["notes"]) ?>
                                    </td>

                                    <td>
                                        <span class="status <?= h($status_class) ?>">
                                            <?= h($row["status"]) ?>
                                        </span>
                                    </td>
                                </tr>

                            <?php endwhile; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="5" class="text-center">
                                    You have no collection requests yet.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <div class="dashboard-footer">
            EcoTrack Waste Collection Management System
            <br>
            &copy; <?= date("Y") ?>
            <br>
            Resident Dashboard
        </div>

    </div>
</div>

<script>
function selectDate(date) {
    const dateInput = document.getElementById("collection_date");

    dateInput.value = date;

    document.getElementById("schedule-form-card").scrollIntoView({
        behavior: "smooth",
        block: "start"
    });

    dateInput.focus();
}
</script>

<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
mysqli_stmt_close($history);
?>
