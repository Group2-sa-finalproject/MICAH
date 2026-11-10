
<?php
session_start();
require_once __DIR__ . "/../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

function respond($success, $message = "", $data = [], $status = 200)
{
    http_response_code($status);

    echo json_encode(array_merge([
        "success" => $success,
        "message" => $message
    ], $data));

    exit;
}

// Check resident session
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "residents"
) {
    respond(false, "Unauthorized access.", [], 403);
}

$resident_id = (int) $_SESSION["user_id"];

// Get collector assigned to resident household
$sql = "
    SELECT
        u.id AS collector_id,
        u.fullname,
        cl.latitude,
        cl.longitude,
        cl.updated_at,
        CASE
            WHEN cl.updated_at >= NOW() - INTERVAL 30 SECOND
            THEN 1
            ELSE 0
        END AS is_online
    FROM households h
    INNER JOIN route_stops rs
        ON rs.household_id = h.id
    INNER JOIN collection_routes cr
        ON cr.id = rs.route_id
    INNER JOIN users u
        ON u.id = cr.collector_id
    LEFT JOIN collector_locations cl
        ON cl.collector_id = u.id
    WHERE h.user_id = ?
      AND u.role = 'collector'
      AND cr.status IN ('assigned', 'in_progress')
      AND rs.status = 'pending'
    ORDER BY cr.created_at DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    respond(
        false,
        "Database query failed: " . $conn->error,
        [],
        500
    );
}

$stmt->bind_param("i", $resident_id);

if (!$stmt->execute()) {
    respond(false, "Unable to retrieve collector information.", [], 500);
}

$result = $stmt->get_result();
$collector = $result->fetch_assoc();

$stmt->close();

if (!$collector) {
    respond(false, "No collector assigned to your household yet.");
}

// Validate coordinates
$latitude = $collector["latitude"];
$longitude = $collector["longitude"];

if (
    $latitude === null ||
    $longitude === null ||
    !is_numeric($latitude) ||
    !is_numeric($longitude)
) {
    respond(true, "Collector assigned, but location is not available yet.", [
        "collector_id" => (int) $collector["collector_id"],
        "fullname" => $collector["fullname"],
        "latitude" => null,
        "longitude" => null,
        "updated_at" => $collector["updated_at"],
        "is_online" => 0
    ]);
}

// Return collector information
respond(true, "Collector found.", [
    "collector_id" => (int) $collector["collector_id"],
    "fullname" => $collector["fullname"],
    "latitude" => (float) $latitude,
    "longitude" => (float) $longitude,
    "updated_at" => $collector["updated_at"],
    "is_online" => (int) $collector["is_online"]
]);
