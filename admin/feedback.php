
<?php
// Get all feedback from residents
$sql = "SELECT id, user_id, feedback_type, message, created_at
        FROM feedback
        ORDER BY created_at DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Database error: " . $conn->error);
}
?>

<style>
.feedback-container {
    width: 100%;
}

.feedback-container .page-title {
    color: #183e2c;
    font-weight: 700;
    font-size: 25px;
    margin-bottom: 8px;
}

.feedback-container .page-description {
    color: #829087;
    font-size: 13px;
    margin-bottom: 25px;
}

.feedback-card {
    background: white;
    border: 1px solid #eaf0eb;
    border-radius: 15px;
    padding: 22px;
    box-shadow: 0 3px 12px rgba(30, 70, 45, 0.03);
}

.feedback-container .table-responsive {
    width: 100%;
    overflow-x: auto;
}

.feedback-container table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.feedback-container table th {
    background: #e3f3e9;
    color: #17633e;
    padding: 14px;
    text-align: left;
    white-space: nowrap;
}

.feedback-container table td {
    padding: 14px;
    border-bottom: 1px solid #edf1ee;
    color: #52645a;
    vertical-align: middle;
}

.feedback-message {
    min-width: 200px;
    max-width: 400px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.type-badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 5px;
    font-size: 11px;
    font-weight: 600;
}

.complaint {
    background: #f8d7da;
    color: #842029;
}

.suggestion {
    background: #cff4fc;
    color: #055160;
}

.satisfaction {
    background: #d1e7dd;
    color: #0f5132;
}

.feedback-empty {
    text-align: center;
    padding: 35px !important;
    color: #89978e !important;
}
</style>

<div class="feedback-container">

    <h1 class="page-title">
        <i class="fas fa-comments"></i>
        Resident Feedback
    </h1>

    <p class="page-description">
        View all complaints, suggestions, and satisfaction
        messages submitted by residents.
    </p>

    <div class="feedback-card">

        <div class="table-responsive">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Resident ID</th>
                        <th>Feedback Type</th>
                        <th>Message</th>
                        <th>Date Submitted</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($result->num_rows > 0): ?>

                    <?php while ($row = $result->fetch_assoc()): ?>

                        <?php
                        $type = $row["feedback_type"];
                        $class = strtolower($type);
                        ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars($row["id"]) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($row["user_id"]) ?>
                            </td>

                            <td>
                                <span class="type-badge <?= htmlspecialchars($class) ?>">
                                    <?= htmlspecialchars($type) ?>
                                </span>
                            </td>

                            <td>
                                <div class="feedback-message">
                                    <?= htmlspecialchars($row["message"]) ?>
                                </div>
                            </td>

                            <td>
                                <?= htmlspecialchars(date(
                                    "M d, Y h:i A",
                                    strtotime($row["created_at"])
                                )) ?>
                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" class="feedback-empty">
                            <i class="fas fa-inbox fa-2x"></i>
                            <br><br>
                            No feedback submitted yet.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php
$result->free();
?>
