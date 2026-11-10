<?php
// Included by dashboard.php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/send_email.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

// HANDLE ADMIN ACTIONS
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {

        $error = "Invalid request. Please try again.";

    } else {

        $action = $_POST["action"] ?? "";
        $schedule_id = (int)($_POST["schedule_id"] ?? 0);

        // APPROVE, COMPLETE, OR REJECT
        if ($action === "update_status") {

            $new_status = $_POST["status"] ?? "";

            if (
                $schedule_id < 1 ||
                !in_array($new_status, ["Approved", "Completed", "Rejected"], true)
            ) {

                $error = "Invalid schedule or status.";

            } else {

                $check = $conn->prepare("
                    SELECT
                        cs.status,
                        cs.collection_date,
                        cs.requested_time,
                        cs.collection_address,
                        u.fullname,
                        u.email,
                        u.email_verified
                    FROM collection_schedules cs
                    LEFT JOIN users u ON cs.user_id = u.id
                    WHERE cs.id = ?
                    LIMIT 1
                ");

                if (!$check) {

                    $error = "Database error: " . $conn->error;

                } else {

                    $check->bind_param("i", $schedule_id);
                    $check->execute();

                    $request = $check->get_result()->fetch_assoc();
                    $check->close();

                    if (!$request) {

                        $error = "Pickup request not found.";

                    } else {

                        $old_status = $request["status"];

                        $stmt = $conn->prepare("
                            UPDATE collection_schedules
                            SET status = ?
                            WHERE id = ?
                        ");

                        if (!$stmt) {

                            $error = "Database error: " . $conn->error;

                        } else {

                            $stmt->bind_param("si", $new_status, $schedule_id);

                            if ($stmt->execute()) {

                                $message = "Collection schedule status updated successfully.";

                                // SEND EMAIL FOR NEW APPROVAL OR REJECTION
                                if (
                                    $old_status !== $new_status &&
                                    in_array($new_status, ["Approved", "Rejected"], true) &&
                                    !empty($request["email"]) &&
                                    (int)$request["email_verified"] === 1
                                ) {

                                    $resident = htmlspecialchars(
                                        $request["fullname"] ?? "Resident",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    $date = htmlspecialchars(
                                        date("F d, Y", strtotime($request["collection_date"])),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    $time = htmlspecialchars(
                                        $request["requested_time"] ?? "Not specified",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    $address = htmlspecialchars(
                                        $request["collection_address"] ?? "Not specified",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    );

                                    if ($new_status === "Approved") {

                                        $subject = "Your Special Pickup Request Has Been Approved";
                                        $color = "#198754";

                                        $status_message = "
                                            <p>Good news! Your special pickup request
                                            has been <strong>approved</strong>.</p>

                                            <p>Please be ready on your scheduled
                                            collection date.</p>
                                        ";

                                    } else {

                                        $subject = "Your Special Pickup Request Has Been Rejected";
                                        $color = "#dc3545";

                                        $status_message = "
                                            <p>We regret to inform you that your
                                            special pickup request has been
                                            <strong>rejected/cancelled</strong>.</p>

                                            <p>Please contact the Waste Collection
                                            Management Office if you need assistance.</p>
                                        ";
                                    }

                                    $body = "
                                        <div style='font-family:Arial,sans-serif;
                                                    max-width:600px;
                                                    margin:auto;
                                                    padding:20px;
                                                    border:1px solid #ddd;
                                                    border-radius:10px;'>

                                            <h2 style='color:#198754;'>
                                                Waste Collection Management System
                                            </h2>

                                            <p>Hello $resident,</p>

                                            $status_message

                                            <div style='background:#f5f7f6;
                                                        padding:15px;
                                                        border-radius:8px;'>

                                                <h3>Pickup Details</h3>

                                                <p><strong>Date:</strong> $date</p>
                                                <p><strong>Time:</strong> $time</p>
                                                <p><strong>Address:</strong> $address</p>

                                                <p>
                                                    <strong>Status:</strong>
                                                    <span style='color:$color;font-weight:bold;'>
                                                        $new_status
                                                    </span>
                                                </p>

                                            </div>

                                            <p>Thank you for using our
                                            Waste Collection Management System.</p>

                                            <p>Regards,<br>
                                            Waste Collection Management Team</p>

                                        </div>
                                    ";

                                    if (sendEmail($request["email"], $subject, $body)) {

                                        $message .= " Notification email sent successfully.";

                                    } else {

                                        $message .= " However, the status was updated but the notification email could not be sent.";
                                    }
                                }

                            } else {

                                $error = "Unable to update the collection schedule.";
                            }

                            $stmt->close();
                        }
                    }
                }
            }

        // ASSIGN COLLECTOR
        } elseif ($action === "assign_collector") {

            $collector_id = (int)($_POST["collector_id"] ?? 0);

            if ($schedule_id < 1 || $collector_id < 1) {

                $error = "Please select a valid collector.";

            } else {

                $check = $conn->prepare("
                    SELECT id
                    FROM collection_schedules
                    WHERE id = ? AND status = 'Approved'
                ");

                $check->bind_param("i", $schedule_id);
                $check->execute();

                $approved = $check->get_result()->fetch_assoc();
                $check->close();

                $cc = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE id = ? AND role = 'collector'
                ");

                $cc->bind_param("i", $collector_id);
                $cc->execute();

                $valid = $cc->get_result()->fetch_assoc();
                $cc->close();

                if (!$approved) {

                    $error = "Only approved requests can be assigned.";

                } elseif (!$valid) {

                    $error = "Invalid collector account.";

                } else {

                    $stmt = $conn->prepare("
                        UPDATE collection_schedules
                        SET collector_id = ?
                        WHERE id = ? AND status = 'Approved'
                    ");

                    $stmt->bind_param("ii", $collector_id, $schedule_id);

                    $message = $stmt->execute()
                        ? "Collector assigned successfully."
                        : "Unable to assign collector.";

                    $stmt->close();
                }
            }

        } else {

            $error = "Invalid action.";
        }
    }
}


// CALENDAR MONTH
$month = $_GET["month"] ?? date("Y-m");

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

$month_start = $month . "-01";

$date_object = DateTime::createFromFormat("!Y-m-d", $month_start);

if (!$date_object || $date_object->format("Y-m-d") !== $month_start) {

    $month = date("Y-m");
    $month_start = $month . "-01";
    $date_object = new DateTime($month_start);
}

$month_end = date("Y-m-t", strtotime($month_start));
$days_in_month = (int)date("t", strtotime($month_start));
$first_day = (int)date("w", strtotime($month_start));

$previous_month = date("Y-m", strtotime($month_start . " -1 month"));
$next_month = date("Y-m", strtotime($month_start . " +1 month"));

$month_label = $date_object->format("F Y");
$today = date("Y-m-d");


// DATABASE HELPER
function ecotrackRows($conn, $sql, $types = null, $params = []) {

    $rows = [];

    if ($types !== null) {

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return $rows;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $result = $stmt->get_result();

    } else {

        $result = $conn->query($sql);
    }

    if ($result) {

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    if (isset($stmt)) {
        $stmt->close();
    }

    return $rows;
}


// GET MONTH SCHEDULES
$schedules = ecotrackRows(
    $conn,
    "SELECT
        cs.id,
        cs.user_id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,
        cs.notes,
        cs.status,
        cs.created_at,
        u.fullname,
        u.users
     FROM collection_schedules cs
     LEFT JOIN users u ON cs.user_id = u.id
     WHERE cs.collection_date BETWEEN ? AND ?
     ORDER BY cs.collection_date ASC, cs.id DESC",
    "ss",
    [$month_start, $month_end]
);


// GET PENDING REQUESTS
$pending_schedules = ecotrackRows(
    $conn,
    "SELECT
        cs.id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,
        cs.notes,
        cs.status,
        cs.created_at,
        u.fullname,
        u.users
     FROM collection_schedules cs
     LEFT JOIN users u ON cs.user_id = u.id
     WHERE cs.status = 'Pending'
     ORDER BY cs.collection_date ASC"
);


// GET COLLECTORS
$collectors = ecotrackRows(
    $conn,
    "SELECT id, fullname, users
     FROM users
     WHERE role = 'collector'
     ORDER BY fullname ASC"
);


// GET APPROVED REQUESTS
$approved_schedules = ecotrackRows(
    $conn,
    "SELECT
        cs.id,
        cs.collection_date,
        cs.requested_time,
        cs.collection_address,
        cs.notes,
        cs.collector_id,
        u.fullname,
        u.users,
        c.fullname AS collector_name
     FROM collection_schedules cs
     LEFT JOIN users u ON cs.user_id = u.id
     LEFT JOIN users c ON cs.collector_id = c.id
     WHERE cs.status = 'Approved'
     ORDER BY cs.collection_date ASC, cs.requested_time ASC"
);


// BOOKED DATES
$booked_dates = [];

foreach ($schedules as $s) {

    if (in_array($s["status"], ["Pending", "Approved"], true)) {

        $booked_dates[$s["collection_date"]][] = $s;
    }
}
?>

<style>
.schedule-page {
    width: 100%;
    min-width: 0;
    color: #333;
}

.schedule-page .schedule-card {
    background: white;
    border: 1px solid #e8eeea;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    padding: 25px;
    margin-bottom: 25px;
}

.schedule-page .page-title {
    color: #263b32;
    font-weight: 700;
}

.schedule-page .calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 5px;
}

.schedule-page .weekday {
    text-align: center;
    padding: 10px 2px;
    background: #e9ecef;
    border-radius: 4px;
    font-size: 13px;
    font-weight: bold;
}

.schedule-page .calendar-day {
    min-height: 100px;
    padding: 7px;
    border: 1px solid #dfe7e2;
    border-radius: 5px;
    background: white;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.schedule-page .available {
    background: #e9f7ef;
    border-color: #8bd3a7;
}

.schedule-page .booked {
    background: #ffe8e8;
    border-color: #ef9a9a;
}

.schedule-page .past {
    background: #eee;
    color: #888;
}

.schedule-page .day-number {
    font-weight: bold;
    margin-bottom: 5px;
}

.schedule-page .calendar-name {
    display: block;
    margin-top: 4px;
    font-size: 11px;
    line-height: 1.4;
}

.schedule-page .table th {
    background: #e9f5ee;
    white-space: nowrap;
}

.schedule-page .table td {
    vertical-align: middle;
}

.schedule-page .status-badge {
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    display: inline-block;
}

.schedule-page .status-pending {
    background: #fff3cd;
    color: #856404;
}

.schedule-page .status-approved {
    background: #cfe2ff;
    color: #084298;
}

.schedule-page .status-completed {
    background: #d1e7dd;
    color: #0f5132;
}

.schedule-page .status-rejected {
    background: #f8d7da;
    color: #842029;
}

@media(max-width:600px) {

    .schedule-page .schedule-card {
        padding: 12px;
    }

    .schedule-page .calendar-grid {
        gap: 3px;
    }

    .schedule-page .calendar-day {
        min-height: 65px;
        padding: 3px;
        font-size: 10px;
    }

    .schedule-page .weekday {
        font-size: 10px;
        padding: 6px 1px;
    }

    .schedule-page .calendar-name {
        font-size: 9px;
    }
}
</style>


<div class="schedule-page">

    <h2 class="page-title">Collection Schedule</h2>

    <p class="text-muted">
        View residents' collection bookings and manage their request status.
    </p>

    <?php if ($message !== ""): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>


    <!-- CALENDAR -->
    <section class="schedule-card">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <a class="btn btn-success btn-sm"
               href="dashboard.php?page=pickup_schedule&amp;month=<?= htmlspecialchars($previous_month) ?>">
                &laquo; Previous
            </a>

            <h4 class="mb-0">
                <?= htmlspecialchars($month_label) ?>
            </h4>

            <a class="btn btn-success btn-sm"
               href="dashboard.php?page=pickup_schedule&amp;month=<?= htmlspecialchars($next_month) ?>">
                Next &raquo;
            </a>

        </div>

        <div class="calendar-grid">

            <?php foreach (["Sun","Mon","Tue","Wed","Thu","Fri","Sat"] as $w): ?>
                <div class="weekday"><?= $w ?></div>
            <?php endforeach; ?>

            <?php for ($i = 0; $i < $first_day; $i++): ?>
                <div></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $days_in_month; $day++): ?>

                <?php
                $d = $month . "-" . str_pad((string)$day, 2, "0", STR_PAD_LEFT);

                $past = $d < $today;
                $booked = isset($booked_dates[$d]);

                $cls = $past ? "past" : ($booked ? "booked" : "available");
                ?>

                <div class="calendar-day <?= $cls ?>">

                    <div class="day-number">
                        <?= $day ?>
                    </div>

                    <?php if ($booked): ?>

                        <?php foreach ($booked_dates[$d] as $b): ?>

                            <span class="calendar-name">

                                <strong>
                                    <?= htmlspecialchars($b["fullname"] ?? "Unknown Resident") ?>
                                </strong>

                                <br>

                                <span class="status-badge status-<?= strtolower(htmlspecialchars($b["status"])) ?>">
                                    <?= htmlspecialchars($b["status"]) ?>
                                </span>

                            </span>

                        <?php endforeach; ?>

                    <?php elseif ($past): ?>

                        <small>Past date</small>

                    <?php else: ?>

                        <small>Available</small>

                    <?php endif; ?>

                </div>

            <?php endfor; ?>

        </div>

        <div class="d-flex flex-wrap gap-3 small mt-3">
            <span>🟩 Available</span>
            <span>🟥 Already booked</span>
            <span>⬜ Past date</span>
        </div>

        <p class="text-muted small mt-3 mb-0">
            Pending and approved requests reserve their dates.
            Completed or rejected requests no longer reserve a date.
        </p>

    </section>


    <!-- PENDING REQUESTS -->
    <section class="schedule-card">

        <h4 class="mb-3">
            <i class="bi bi-clock-history text-success me-2"></i>
            Pending Collection Requests
        </h4>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Resident</th>
                        <th>Username</th>
                        <th>Collection Date</th>
                        <th>Requested Time</th>
                        <th>Address</th>
                        <th>Notes</th>
                        <th>Status</th>
                        <th>Admin Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($pending_schedules): ?>

                        <?php foreach ($pending_schedules as $s): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($s["fullname"] ?? "Unknown Resident") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["users"] ?? "N/A") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(date("F d, Y", strtotime($s["collection_date"]))) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["requested_time"] ?? "") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["collection_address"] ?? "") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["notes"] ?? "") ?>
                                </td>

                                <td>
                                    <span class="status-badge status-pending">
                                        Pending
                                    </span>
                                </td>

                                <td>

                                    <form method="POST"
                                          action="dashboard.php?page=pickup_schedule"
                                          class="d-flex flex-column gap-2">

                                        <input type="hidden"
                                               name="action"
                                               value="update_status">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                        <input type="hidden"
                                               name="schedule_id"
                                               value="<?= (int)$s["id"] ?>">

                                        <select name="status"
                                                class="form-select form-select-sm"
                                                required>

                                            <option value="">Choose Action</option>
                                            <option value="Approved">Approve</option>
                                            <option value="Completed">Mark Completed</option>
                                            <option value="Rejected">Reject</option>

                                        </select>

                                        <button class="btn btn-success btn-sm" type="submit">
                                            Update Status
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="8" class="text-center text-muted">
                                There are no pending collection requests.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- APPROVED REQUESTS - ASSIGN COLLECTOR -->
    <section class="schedule-card">

        <h4 class="mb-3">
            <i class="bi bi-truck text-success me-2"></i>
            Approved Requests - Assign Collector
        </h4>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Resident</th>
                        <th>Collection Date</th>
                        <th>Requested Time</th>
                        <th>Address</th>
                        <th>Assigned Collector</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($approved_schedules): ?>

                        <?php foreach ($approved_schedules as $s): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($s["fullname"] ?? "Unknown Resident") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(date("F d, Y", strtotime($s["collection_date"]))) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["requested_time"] ?? "") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["collection_address"] ?? "") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["collector_name"] ?? "Not assigned") ?>
                                </td>

                                <td>

                                    <form method="POST"
                                          action="dashboard.php?page=pickup_schedule"
                                          class="d-flex flex-column gap-2">

                                        <input type="hidden"
                                               name="action"
                                               value="assign_collector">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>">

                                        <input type="hidden"
                                               name="schedule_id"
                                               value="<?= (int)$s["id"] ?>">

                                        <select name="collector_id"
                                                class="form-select form-select-sm"
                                                required>

                                            <option value="">Choose Collector</option>

                                            <?php foreach ($collectors as $c): ?>

                                                <option value="<?= (int)$c["id"] ?>"
                                                    <?= ((int)$s["collector_id"] === (int)$c["id"]) ? "selected" : "" ?>>

                                                    <?= htmlspecialchars($c["fullname"]) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <button class="btn btn-success btn-sm" type="submit">
                                            <i class="bi bi-person-check me-1"></i>
                                            Assign Collector
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                No approved collection requests available for assignment.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- ALL SCHEDULES -->
    <section class="schedule-card">

        <h4 class="mb-3">
            <i class="bi bi-calendar-week text-success me-2"></i>
            All Collection Schedules
        </h4>

        <p class="text-muted small">
            All collection requests for <?= htmlspecialchars($month_label) ?>.
        </p>

        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead>
                    <tr>
                        <th>Resident</th>
                        <th>Collection Date</th>
                        <th>Address</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if ($schedules): ?>

                        <?php foreach ($schedules as $s): ?>

                            <?php
                            $sc = "status-pending";

                            if ($s["status"] === "Approved") {
                                $sc = "status-approved";
                            } elseif ($s["status"] === "Completed") {
                                $sc = "status-completed";
                            } elseif ($s["status"] === "Rejected") {
                                $sc = "status-rejected";
                            }
                            ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($s["fullname"] ?? "Unknown Resident") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(date("F d, Y", strtotime($s["collection_date"]))) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($s["collection_address"] ?? "") ?>
                                </td>

                                <td>
                                    <span class="status-badge <?= $sc ?>">
                                        <?= htmlspecialchars($s["status"]) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4" class="text-center text-muted">
                                No collection schedules found for this month.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>