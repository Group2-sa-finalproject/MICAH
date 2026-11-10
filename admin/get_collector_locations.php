
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

header("Content-Type: application/json; charset=utf-8");

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

header("Pragma: no-cache");

// Admin access only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {

    http_response_code(403);

    echo json_encode([
        "error" => "Unauthorized access. Please log in as admin."
    ]);

    exit;

}

$conn->set_charset("utf8mb4");

// Get collector GPS locations
$sql = "
    SELECT
        u.id,
        u.fullname,
        cl.latitude,
        cl.longitude,
        cl.updated_at,

        CASE
            WHEN cl.updated_at >= (NOW() - INTERVAL 30 SECOND)
            THEN 1
            ELSE 0
        END AS is_online

    FROM collector_locations AS cl

    INNER JOIN users AS u
        ON cl.collector_id = u.id

    WHERE u.role IN ('collector', 'collectors')

      AND cl.latitude IS NOT NULL

      AND cl.longitude IS NOT NULL

      AND cl.latitude BETWEEN -90 AND 90

      AND cl.longitude BETWEEN -180 AND 180

    ORDER BY cl.updated_at DESC
";

$result = $conn->query($sql);

// Database error
if (!$result) {

    http_response_code(500);

    echo json_encode([
        "error" => "Database query failed.",
        "details" => $conn->error
    ]);

    $conn->close();

    exit;

}

// Prepare response
$collectors = [];

while ($row = $result->fetch_assoc()) {

    $collectors[] = [

        "id" => (int) $row["id"],

        "fullname" => $row["fullname"],

        "latitude" => (float) $row["latitude"],

        "longitude" => (float) $row["longitude"],

        "updated_at" => $row["updated_at"],

        "is_online" => (int) $row["is_online"]

    ];

}

echo json_encode(
    $collectors,
    JSON_UNESCAPED_UNICODE
);

$result->free();

$conn->close();

?>
