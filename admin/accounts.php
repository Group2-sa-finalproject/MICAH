
<?php

// Account categories
$roles = [
    "admin" => "Administrators",
    "collector" => "Garbage Collectors",
    "residents" => "Residents"
];

// Prepare account arrays
$accounts = [
    "admin" => [],
    "collector" => [],
    "residents" => []
];

// Get all accounts from users table
$sql = "SELECT id, fullname, users, role
        FROM users
        ORDER BY fullname ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo '<p class="account-error">Database query failed: ' .
        htmlspecialchars(mysqli_error($conn)) . '</p>';
} else {

    // Separate accounts according to their roles
    while ($row = mysqli_fetch_assoc($result)) {

        $role = $row["role"];

        if (isset($accounts[$role])) {
            $accounts[$role][] = $row;
        }
    }
}

// Total number of accounts
$total_accounts = 0;

foreach ($accounts as $account_list) {
    $total_accounts += count($account_list);
}

?>

<style>

    .accounts-page {
        width: 100%;
    }

    .accounts-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 25px;
    }

    .accounts-header h1 {
        font-size: 25px;
        color: #183e2c;
        margin-bottom: 5px;
    }

    .accounts-header p {
        color: #829087;
        font-size: 12px;
        margin: 0;
    }

    .add-account-btn {
        display: inline-block;
        background: #238653;
        color: white;
        padding: 11px 17px;
        border-radius: 9px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
    }

    .add-account-btn:hover {
        background: #176b40;
        color: white;
    }

    .accounts-summary {
        background: white;
        border: 1px solid #eaf0eb;
        border-radius: 15px;
        padding: 20px 22px;
        margin-bottom: 22px;
        box-shadow: 0 3px 12px rgba(30,70,45,.03);
    }

    .accounts-summary span {
        color: #89978e;
        font-size: 12px;
    }

    .accounts-summary h2 {
        color: #1b4430;
        font-size: 28px;
        margin: 7px 0 0;
    }

    .account-section {
        background: white;
        border: 1px solid #eaf0eb;
        border-radius: 15px;
        padding: 22px;
        margin-bottom: 20px;
        box-shadow: 0 3px 12px rgba(30,70,45,.03);
    }

    .account-section h2 {
        font-size: 16px;
        font-weight: 700;
        color: #214b34;
        margin: 0 0 18px;
    }

    .account-count {
        display: inline-block;
        background: #e3f5e9;
        color: #238653;
        font-size: 11px;
        padding: 4px 10px;
        border-radius: 20px;
        margin-left: 7px;
    }

    .account-table-wrap {
        width: 100%;
        overflow-x: auto;
    }

    .account-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .account-table th {
        background: #f4f7f6;
        color: #45564d;
        font-weight: 600;
        text-align: left;
        padding: 13px;
        border-bottom: 2px solid #e2e9e5;
        white-space: nowrap;
    }

    .account-table td {
        padding: 13px;
        border-bottom: 1px solid #eef2ef;
        color: #52645a;
    }

    .account-table tbody tr:hover {
        background: #f8fbf9;
    }

    .role-badge {
        display: inline-block;
        background: #e6f4ec;
        color: #198754;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
    }

    .empty-message {
        text-align: center;
        color: #929e96 !important;
        padding: 25px !important;
    }

    .account-error {
        color: #b42318;
        background: #fff1f0;
        padding: 12px;
        border-radius: 8px;
    }

    @media (max-width: 600px) {

        .account-section {
            padding: 15px;
        }

        .accounts-header h1 {
            font-size: 21px;
        }

    }

</style>


<div class="accounts-page">

    <!-- PAGE HEADER -->

    <div class="accounts-header">

        <div>

            <h1>Manage Accounts</h1>

            <p>
                View and monitor all registered accounts in the system.
            </p>

        </div>

        <a href="add_account.php" class="add-account-btn">
            + Add Account
        </a>

    </div>


    <!-- TOTAL ACCOUNTS -->

    <div class="accounts-summary">

        <span>Total Registered Accounts</span>

        <h2>
            <?php echo $total_accounts; ?>
        </h2>

    </div>


    <!-- ACCOUNT TABLES -->

    <?php foreach ($roles as $role => $title) { ?>

        <section class="account-section">

            <h2>

                <?php

                if ($role === "admin") {
                    echo "⚙ ";
                } elseif ($role === "collector") {
                    echo "🚛 ";
                } else {
                    echo "👥 ";
                }

                ?>

                <?php echo htmlspecialchars($title); ?>

                <span class="account-count">
                    <?php echo count($accounts[$role]); ?>
                </span>

            </h2>


            <div class="account-table-wrap">

                <table class="account-table">

                    <thead>

                        <tr>
                            <th>Account ID</th>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Account Type</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (count($accounts[$role]) > 0) { ?>

                            <?php foreach ($accounts[$role] as $account) { ?>

                                <tr>

                                    <td>
                                        <?php echo htmlspecialchars((string) $account["id"]); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($account["fullname"]); ?>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($account["users"]); ?>
                                    </td>

                                    <td>

                                        <span class="role-badge">
                                            <?php echo htmlspecialchars(ucfirst($account["role"])); ?>
                                        </span>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td colspan="4" class="empty-message">

                                    No <?php echo htmlspecialchars(strtolower($title)); ?> accounts found.

                                </td>

                            </tr>

                        <?php } ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php } ?>

</div>
