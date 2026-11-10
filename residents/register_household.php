
<?php
session_start();
require_once __DIR__ . "/../config/db_system.php";

// Check resident login
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'residents'
) {
    header("Location: ../index.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "";
$resident_name = "Resident";

// Get resident information
$nameQuery = $pdo->prepare("
    SELECT fullname
    FROM users
    WHERE id = ?
      AND role = 'residents'
    LIMIT 1
");
$nameQuery->execute([$user_id]);

$resident = $nameQuery->fetch(PDO::FETCH_ASSOC);

if ($resident && !empty($resident['fullname'])) {
    $resident_name = $resident['fullname'];
}

// Check if resident already has a household
$check = $pdo->prepare(
    "SELECT id FROM households WHERE user_id = ? LIMIT 1"
);
$check->execute([$user_id]);

$alreadyRegistered = (bool) $check->fetch();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($alreadyRegistered) {
        $message = "You have already registered a household.";
        $message_type = "warning";

    } else {
        $house_address = trim($_POST['house_address'] ?? '');
        $latitude = $_POST['latitude'] ?? '';
        $longitude = $_POST['longitude'] ?? '';

        if (
            $house_address === '' ||
            !is_numeric($latitude) ||
            !is_numeric($longitude) ||
            $latitude < -90 || $latitude > 90 ||
            $longitude < -180 || $longitude > 180
        ) {
            $message = "Please enter a valid address and select your house location on the map.";
            $message_type = "danger";

        } else {
            try {
                $sql = "INSERT INTO households
                        (user_id, house_address, latitude, longitude)
                        VALUES (?, ?, ?, ?)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $user_id,
                    $house_address,
                    $latitude,
                    $longitude
                ]);

                $message = "Household registered successfully!";
                $message_type = "success";
                $alreadyRegistered = true;

            } catch (PDOException $e) {
                $message = "Unable to register household. Please try again.";
                $message_type = "danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Register Household | EcoTrack</title>

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
            padding: 30px;
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
           FORM CARDS
        ========================================= */

        .form-card {
            background: #fff;
            padding: 25px;
            border-radius: 13px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
            border: 1px solid #edf1ee;
            margin-bottom: 22px;
        }

        .section-heading {
            font-size: 18px;
            font-weight: 800;
            color: #183d2b;
            margin-bottom: 20px;
        }

        .section-heading i {
            color: #17643b;
        }

        .form-label {
            font-size: 13px;
            color: #40594a;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .form-control {
            border: 1px solid #dce5df;
            border-radius: 8px;
            padding: 11px 13px;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: #17643b;
            box-shadow: 0 0 0 .15rem rgba(23,100,59,.12);
        }

        .input-group-text {
            background: #e7f5eb;
            color: #17643b;
            border-color: #dce5df;
        }

        /* =========================================
           MAP
        ========================================= */

        .map-container {
            border: 1px solid #dce5df;
            border-radius: 10px;
            overflow: hidden;
            background: #f8faf9;
        }

        #map {
            width: 100%;
            height: 400px;
        }

        .map-instructions {
            background: #edf7ef;
            border-left: 4px solid #21834d;
            color: #355b43;
            padding: 15px;
            font-size: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .coordinate-box {
            background: #f7faf8;
            border: 1px solid #e8eeea;
            border-radius: 8px;
            padding: 14px;
            font-size: 13px;
            color: #65736a;
        }

        .coordinate-box strong {
            color: #2d4036;
        }

        /* =========================================
           SUCCESS STATE
        ========================================= */

        .success-icon {
            width: 75px;
            height: 75px;
            border-radius: 50%;
            background: #e6f4ec;
            color: #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            margin: 0 auto 20px;
        }

        /* =========================================
           BUTTONS
        ========================================= */

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

        /* =========================================
           FOOTER
        ========================================= */

        .dashboard-footer {
            margin-top: 30px;
            padding: 18px 0;
            border-top: 1px solid #e2e9e5;
            color: #929b96;
            font-size: 12px;
            text-align: center;
        }

        /* =========================================
           RESPONSIVE
        ========================================= */

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

            .form-card {
                padding: 18px;
            }

            #map {
                height: 300px;
            }

        }

    </style>

</head>

<body>

    <!-- =========================================
         SIDEBAR
    ========================================= -->

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
                <a href="register_household.php" class="active">
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


    <!-- =========================================
         MAIN CONTENT
    ========================================= -->

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
                    <i class="fas fa-house-user me-2"></i>
                    Register Your Household
                </h2>

                <p>
                    Register your household address and mark its exact location
                    to help our garbage collection team find your residence.
                </p>

            </div>


            <!-- MESSAGE -->

            <?php if ($message !== ""): ?>

                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show">

                    <?php if ($message_type === "success"): ?>
                        <i class="fas fa-check-circle me-2"></i>
                    <?php elseif ($message_type === "warning"): ?>
                        <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php else: ?>
                        <i class="fas fa-exclamation-circle me-2"></i>
                    <?php endif; ?>

                    <?php echo htmlspecialchars($message); ?>

                    <button type="button"
                        class="btn-close"
                        data-bs-dismiss="alert"></button>

                </div>

            <?php endif; ?>


            <?php if (!$alreadyRegistered): ?>

                <form method="POST" id="household-form">

                    <!-- HOUSEHOLD INFORMATION -->

                    <section class="form-card">

                        <h5 class="section-heading">
                            <i class="fas fa-house-door me-2"></i>
                            Household Information
                        </h5>

                        <div class="mb-3">

                            <label for="house_address" class="form-label">
                                House Address
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    <i class="fas fa-map-marker-alt"></i>
                                </span>

                                <input
                                    type="text"
                                    name="house_address"
                                    id="house_address"
                                    class="form-control"
                                    placeholder="Enter your complete household address"
                                    value="<?php echo htmlspecialchars($_POST['house_address'] ?? ''); ?>"
                                    required
                                >

                            </div>

                            <small class="text-muted">
                                Include your street, barangay, and other address details.
                            </small>

                        </div>

                    </section>


                    <!-- MAP LOCATION -->

                    <section class="form-card">

                        <h5 class="section-heading">
                            <i class="fas fa-map-marked-alt me-2"></i>
                            Select Your House Location
                        </h5>

                        <div class="map-instructions">

                            <i class="fas fa-info-circle me-2"></i>

                            Click on the map where your house is located.
                            A marker will appear at your selected location.

                        </div>


                        <div class="map-container mb-3">

                            <div id="map"></div>

                        </div>


                        <!-- COORDINATES -->

                        <div class="row g-3">

                            <div class="col-md-6">

                                <label for="latitude" class="form-label">
                                    Latitude
                                </label>

                                <input
                                    type="number"
                                    step="any"
                                    name="latitude"
                                    id="latitude"
                                    class="form-control"
                                    placeholder="Select location on map"
                                    value="<?php echo htmlspecialchars($_POST['latitude'] ?? ''); ?>"
                                    required
                                    readonly
                                >

                            </div>


                            <div class="col-md-6">

                                <label for="longitude" class="form-label">
                                    Longitude
                                </label>

                                <input
                                    type="number"
                                    step="any"
                                    name="longitude"
                                    id="longitude"
                                    class="form-control"
                                    placeholder="Select location on map"
                                    value="<?php echo htmlspecialchars($_POST['longitude'] ?? ''); ?>"
                                    required
                                    readonly
                                >

                            </div>

                        </div>


                        <div class="coordinate-box mt-3">

                            <i class="fas fa-map-pin text-success me-2"></i>

                            <strong>Selected Location:</strong>

                            <span id="location-status">
                                No location selected yet.
                            </span>

                        </div>

                    </section>


                    <!-- SUBMIT BUTTONS -->

                    <div class="d-flex flex-wrap justify-content-end gap-2 mb-4">

                        <a href="dashboard.php"
                            class="btn btn-outline-secondary px-4">

                            <i class="fas fa-times me-1"></i>
                            Cancel

                        </a>

                        <button type="submit" class="btn-ecotrack">

                            <i class="fas fa-check-circle me-1"></i>
                            Register Household

                        </button>

                    </div>

                </form>


            <?php else: ?>


                <!-- ALREADY REGISTERED -->

                <section class="form-card text-center py-5">

                    <div class="success-icon">

                        <i class="fas fa-house-user"></i>

                    </div>

                    <h4 class="mb-2" style="color:#183d2b;font-weight:800;">

                        Household Already Registered

                    </h4>

                    <p class="text-muted mb-4">

                        Your household is already registered in the system.
                        You can now access the available resident services.

                    </p>

                    <a href="dashboard.php" class="btn-ecotrack">

                        <i class="fas fa-tachometer-alt me-1"></i>
                        Back to Dashboard

                    </a>

                </section>


            <?php endif; ?>


            <!-- FOOTER -->

            <footer class="dashboard-footer">

                Waste Collection Management System by Group 2

                &copy; <?php echo date("Y"); ?>

                <br>

                Household Registration

            </footer>

        </main>

    </div>


    <!-- BOOTSTRAP JS -->

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>


    <!-- GOOGLE MAPS -->

    <script>

        let map;
        let marker;

        function initMap() {

            const defaultLocation = {
                lat: 15.2200,
                lng: 120.5700
            };

            map = new google.maps.Map(
                document.getElementById("map"),
                {
                    center: defaultLocation,
                    zoom: 15,
                    mapTypeControl: true,
                    streetViewControl: false,
                    fullscreenControl: true
                }
            );

            // Restore previously selected location after validation errors

            const savedLat = document.getElementById("latitude").value;
            const savedLng = document.getElementById("longitude").value;

            if (savedLat !== "" && savedLng !== "") {

                const savedLocation = {
                    lat: parseFloat(savedLat),
                    lng: parseFloat(savedLng)
                };

                marker = new google.maps.Marker({
                    position: savedLocation,
                    map: map,
                    title: "Your Household Location"
                });

                map.setCenter(savedLocation);

                document.getElementById("location-status").textContent =
                    savedLocation.lat.toFixed(6) + ", " +
                    savedLocation.lng.toFixed(6);

            }

            // Select location by clicking the map

            map.addListener("click", function(event) {

                const latitude = event.latLng.lat();
                const longitude = event.latLng.lng();

                const selectedLocation = {
                    lat: latitude,
                    lng: longitude
                };

                if (marker) {

                    marker.setPosition(selectedLocation);

                } else {

                    marker = new google.maps.Marker({
                        position: selectedLocation,
                        map: map,
                        title: "Your Household Location"
                    });

                }

                document.getElementById("latitude").value =
                    latitude.toFixed(8);

                document.getElementById("longitude").value =
                    longitude.toFixed(8);

                document.getElementById("location-status").textContent =
                    latitude.toFixed(6) + ", " +
                    longitude.toFixed(6);

            });

        }


        // Prevent submission without selecting a map location

        document.getElementById("household-form")?.addEventListener(
            "submit",
            function(event) {

                const latitude =
                    document.getElementById("latitude").value;

                const longitude =
                    document.getElementById("longitude").value;

                if (latitude === "" || longitude === "") {

                    event.preventDefault();

                    alert(
                        "Please select your house location on the map first."
                    );

                }

            }
        );

    </script>


    <?php if (!$alreadyRegistered): ?>

        <script
            src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCz8mz80N6FN03Q1q1nQFGeZLduXsZpNHY&callback=initMap"
            async
            defer>
        </script>

    <?php endif; ?>


</body>
</html>
