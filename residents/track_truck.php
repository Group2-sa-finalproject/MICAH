
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

$conn->set_charset("utf8mb4");

$resident_id = (int) $_SESSION["user_id"];

// GET RESIDENT INFORMATION
$stmt = $conn->prepare("
    SELECT id, fullname
    FROM users
    WHERE id = ?
    AND role = 'residents'
    LIMIT 1
");

if (!$stmt) {
    die("Resident query failed: " . $conn->error);
}

$stmt->bind_param("i", $resident_id);
$stmt->execute();

$resident = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resident) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

// ESCAPE OUTPUT
function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Track Garbage Truck | EcoTrack</title>

<link rel="stylesheet"
href="../assets/vendor/bootstrap/css/bootstrap.min.css">

<link rel="stylesheet"
href="../assets/css/style.css">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

/* GENERAL */

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

.resident-info {
    color: #52645a;
    font-size: 14px;
}

.content {
    padding: 30px;
    min-width: 0;
}

/* PAGE HEADER */

.page-header {
    margin-bottom: 25px;
}

.page-header h2 {
    color: #183d2b;
    font-size: 25px;
    font-weight: bold;
}

.page-header p {
    color: #77847c;
    margin-bottom: 0;
}

/* SECTION TITLE */

.section-title {
    color: #45564d;
    font-size: 17px;
    font-weight: 700;
    margin: 30px 0 18px;
}

/* TRACKING CARDS */

.tracking-card {
    background: white;
    border-radius: 12px;
    padding: 23px;
    border: 1px solid #e8eeea;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
    margin-bottom: 23px;
}

.tracking-card h5 {
    color: #2d4036;
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 15px;
}

/* TRACKING ICON */

.dashboard-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e5f5e9;
    color: #23834b;
    margin-bottom: 17px;
    font-size: 21px;
}

/* COLLECTOR INFORMATION */

.collector-name {
    font-size: 16px;
    font-weight: 700;
    color: #263b32;
    margin-bottom: 8px;
}

.collector-status {
    font-size: 13px;
    margin-bottom: 0;
}

.status-online {
    color: #198754;
}

.status-offline {
    color: #dc3545;
}

.status-waiting {
    color: #77847c;
}

/* REFRESH BUTTON */

.refresh-btn {
    background: #198754;
    color: white;
    border: none;
    border-radius: 7px;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    transition: .2s;
}

.refresh-btn:hover {
    background: #146c43;
    color: white;
}

/* MAP */

#map {
    width: 100%;
    height: 500px;
    border-radius: 10px;
    overflow: hidden;
    background: #e9efeb;
}

.map-container {
    width: 100%;
    overflow: hidden;
    border-radius: 10px;
}

/* TRACKING DETAILS */

.tracking-description {
    color: #77847c;
    font-size: 13px;
    line-height: 1.6;
}

/* FOOTER */

.dashboard-footer {
    margin-top: 35px;
    padding: 20px 0;
    border-top: 1px solid #e2e9e5;
    color: #929b96;
    font-size: 12px;
    text-align: center;
}

/* RESPONSIVE */

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

    .page-header h2 {
        font-size: 21px;
    }

    #map {
        height: 400px;
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

    <div class="sidebar-title">
        Main Menu
    </div>

    <a href="dashboard.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-chart-pie"></i>
        </span>

        <span>Dashboard</span>

    </a>

    <a href="eco_points.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-star"></i>
        </span>

        <span>Eco Points</span>

    </a>

    <a href="collection_schedule.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-calendar-alt"></i>
        </span>

        <span>Collection Schedule</span>

    </a>

    <a href="track_truck.php" class="nav-item active">

        <span class="nav-icon">
            <i class="fas fa-truck"></i>
        </span>

        <span>Track Garbage Truck</span>

    </a>

    <a href="subscription.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-credit-card"></i>
        </span>

        <span>Subscription</span>

    </a>

    <a href="register_household.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-house"></i>
        </span>

        <span>Register Household</span>

    </a>

    <a href="feedback.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-comments"></i>
        </span>

        <span>Feedback</span>

    </a>

    <a href="qrcode.php" class="nav-item">

        <span class="nav-icon">
            <i class="fas fa-qrcode"></i>
        </span>

        <span>Resident QR Code</span>

    </a>

    <div class="sidebar-title">
        Account
    </div>

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

        <div class="resident-info">

            <i class="fas fa-user-circle"></i>

            <?= e($resident["fullname"]); ?>

        </div>

    </div>


    <!-- PAGE CONTENT -->

    <div class="content">

        <!-- PAGE HEADER -->

        <div class="page-header">

            <h2>
                <i class="fas fa-truck me-2"></i>
                Track Garbage Truck
            </h2>

            <p>
                Monitor the collector assigned to your household.
            </p>

        </div>


        <!-- COLLECTOR INFORMATION -->

        <h6 class="section-title">

            <i class="fas fa-user me-2"></i>

            Assigned Collector Information

        </h6>

        <div class="tracking-card">

            <div class="row align-items-center">

                <div class="col-md-8">

                    <div class="d-flex align-items-start gap-3">

                        <div class="dashboard-icon">
                            <i class="fas fa-truck"></i>
                        </div>

                        <div>

                            <h5 class="collector-name"
                                id="collectorName">

                                Searching for assigned collector...

                            </h5>

                            <p id="collectorStatus"
                               class="collector-status status-waiting">

                                <i class="fas fa-clock me-1"></i>
                                Waiting for location...

                            </p>

                        </div>

                    </div>

                </div>

               

            </div>

        </div>


        <!-- LIVE COLLECTOR LOCATION -->

        <h6 class="section-title">

            <i class="fas fa-map-marker-alt me-2"></i>

            Live Collector Location

        </h6>

        <div class="tracking-card">

            <h5>
                <i class="fas fa-location-dot me-2 text-success"></i>
                Collector Tracking Map
            </h5>

            <p class="tracking-description">
                View the real-time location of your assigned
                garbage collector. The map automatically updates
                every 5 seconds.
            </p>

            <div class="map-container">

                <div id="map"></div>

            </div>

        </div>


        <!-- FOOTER -->

        <div class="dashboard-footer">

            EcoTrack Waste Collection Management System

            <br>

            &copy; <?= date("Y"); ?>

            <br>

            Resident Tracking Page

        </div>

    </div>

</div>


<script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>


<!-- GOOGLE MAPS TRACKING -->

<script>

let map;

let collectorMarker = null;

let firstLocationLoaded = false;

let trackingInterval = null;

let isLoading = false;


// LOAD COLLECTOR LOCATION

async function loadCollectorLocation() {

    if (isLoading || !map) {
        return;
    }

    isLoading = true;

    const nameElement =
        document.getElementById("collectorName");

    const statusElement =
        document.getElementById("collectorStatus");

    try {

        const response = await fetch(
            "get_assigned_collector_location.php",
            {
                method: "GET",
                cache: "no-store",
                headers: {
                    "Accept": "application/json"
                }
            }
        );

        const responseText = await response.text();

        let collector;

        try {

            collector = JSON.parse(responseText);

        } catch (jsonError) {

            console.error(
                "Invalid API response:",
                responseText
            );

            throw new Error(
                "The tracking API returned an invalid response. Check the PHP file for errors."
            );

        }

        if (!response.ok) {

            throw new Error(
                collector.message || "Unable to load collector."
            );

        }

        if (!collector.success) {

            nameElement.textContent =
                collector.message || "No assigned collector found.";

            statusElement.textContent =
                "Waiting for an assigned collector.";

            statusElement.className =
                "collector-status status-waiting";

            if (collectorMarker) {

                collectorMarker.setMap(null);

                collectorMarker = null;

            }

            firstLocationLoaded = false;

            return;

        }

        // COLLECTOR NAME

        nameElement.textContent =
            "Collector: " + collector.fullname;


        // COLLECTOR COORDINATES

        const position = {

            lat: Number(collector.latitude),

            lng: Number(collector.longitude)

        };


        if (
            !Number.isFinite(position.lat) ||
            !Number.isFinite(position.lng) ||
            Math.abs(position.lat) > 90 ||
            Math.abs(position.lng) > 180
        ) {

            throw new Error(
                "Invalid collector coordinates."
            );

        }


        // ONLINE / OFFLINE STATUS

        const isOnline =
            Number(collector.is_online) === 1;


        if (isOnline) {

            statusElement.innerHTML =
                '<i class="fas fa-circle me-1"></i> Online • GPS location updating';

            statusElement.className =
                "collector-status status-online";

        } else {

            statusElement.innerHTML =
                '<i class="fas fa-circle me-1"></i> Offline • Showing last known location';

            statusElement.className =
                "collector-status status-offline";

        }


        // CREATE OR UPDATE MARKER

        if (!collectorMarker) {

            collectorMarker = new google.maps.Marker({

                position: position,

                map: map,

                title: collector.fullname,

                label: "T"

            });

        } else {

            collectorMarker.setPosition(position);

        }


        // CENTER MAP ON FIRST LOCATION

        if (!firstLocationLoaded) {

            map.setCenter(position);

            map.setZoom(16);

            firstLocationLoaded = true;

        }

    } catch (error) {

        console.error(
            "Tracking error:",
            error
        );

        nameElement.textContent =
            "Collector Information";

        statusElement.textContent =
            error.message || "Unable to retrieve collector location. Retrying...";

        statusElement.className =
            "collector-status status-offline";

    } finally {

        isLoading = false;

    }

}


// INITIALIZE GOOGLE MAP

function initMap() {

    map = new google.maps.Map(

        document.getElementById("map"),

        {

            center: {
                lat: 15.4802,
                lng: 120.5979
            },

            zoom: 13,

            mapTypeControl: true,

            streetViewControl: false,

            fullscreenControl: true

        }

    );


    // LOAD INITIAL LOCATION

    loadCollectorLocation();


    // REFRESH EVERY 5 SECONDS

    if (trackingInterval) {

        clearInterval(trackingInterval);

    }

    trackingInterval = setInterval(

        loadCollectorLocation,

        5000

    );

}

</script>


<!-- GOOGLE MAPS API -->

<script async defer
    src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap">
</script>

</body>
</html>
