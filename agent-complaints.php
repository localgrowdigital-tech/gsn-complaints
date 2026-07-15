<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agent_id = (int)$_SESSION['agent_id'];
$agent_name = $_SESSION['agent_name'] ?? 'Agent';
$success = '';
$error = '';

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean($conn, $value)
{
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function priority_badge_class($priority)
{
    if ($priority === 'Most Urgent') {
        return 'text-bg-danger';
    }
    if ($priority === 'Urgent') {
        return 'text-bg-warning';
    }
    return 'text-bg-secondary';
}

$allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
$allowed_priorities = ['Normal', 'Urgent', 'Most Urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_complaint'])) {
    $complaint_db_id = (int)($_POST['complaint_db_id'] ?? 0);
    $new_status = clean($conn, $_POST['status'] ?? '');
    $closing_date = clean($conn, $_POST['closing_date'] ?? '');

    if ($complaint_db_id <= 0) {
        $error = 'Invalid complaint selected.';
    } elseif (!in_array($new_status, $allowed_statuses, true)) {
        $error = 'Invalid status selected.';
    } else {
        $allowed_check = mysqli_query($conn, "
            SELECT c.id
            FROM complaints c
            INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
            WHERE c.id = $complaint_db_id
              AND aj.agent_id = $agent_id
            LIMIT 1
        ");

        if (!$allowed_check || mysqli_num_rows($allowed_check) === 0) {
            $error = 'You are not allowed to update this complaint.';
        } else {
            $closing_sql = $closing_date !== '' ? "'$closing_date'" : "NULL";
            $update_sql = "UPDATE complaints SET status = '$new_status', closing_date = $closing_sql WHERE id = $complaint_db_id";

            if (mysqli_query($conn, $update_sql)) {
                $success = 'Complaint updated successfully.';
            } else {
                $error = 'Unable to update complaint. Please try again.';
            }
        }
    }
}

$search = clean($conn, $_GET['search'] ?? '');
$job_filter = (int)($_GET['job_id'] ?? 0);
$vendor_filter = (int)($_GET['vendor_id'] ?? 0);
$status_filter = clean($conn, $_GET['status'] ?? '');
$priority_filter = clean($conn, $_GET['priority'] ?? '');

$where = ["aj.agent_id = $agent_id"];

if ($search !== '') {
    $where[] = "(
        c.complaint_id LIKE '%$search%' OR
        c.tracking_number LIKE '%$search%' OR
        c.customer_name LIKE '%$search%' OR
        c.mobile LIKE '%$search%'
    )";
}

if ($job_filter > 0) {
    $where[] = "c.job_id = $job_filter";
}

if ($vendor_filter > 0) {
    $where[] = "c.vendor_id = $vendor_filter";
}

if ($status_filter !== '' && in_array($status_filter, $allowed_statuses, true)) {
    $where[] = "c.status = '$status_filter'";
}

if ($priority_filter !== '' && in_array($priority_filter, $allowed_priorities, true)) {
    $where[] = "c.priority = '$priority_filter'";
}

$where_sql = implode(' AND ', $where);

$complaints_sql = "
    SELECT
        c.id,
        c.complaint_id,
        c.complaint_date,
        c.tracking_number,
        c.customer_name,
        c.mobile,
        c.priority,
        c.status,
        c.closing_date,

        CASE
            WHEN c.status IN ('Closed','Resolved')
                 AND c.closing_date IS NOT NULL
            THEN DATEDIFF(c.closing_date, c.complaint_date)

            ELSE DATEDIFF(CURDATE(), c.complaint_date)
        END AS pending_days,

        j.job_name,
        v.vendor_name

    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE $where_sql
    ORDER BY c.id DESC
";

if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    $export_result = mysqli_query($conn, $complaints_sql);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=agent-complaints-' . date('Ymd-His') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Complaint ID</th><th>Date</th><th>Job</th><th>Vendor</th><th>Tracking</th><th>Customer</th><th>Mobile</th><th>Priority</th><th>Status</th><th>Pending Days</th><th>Closing Date</th></tr>";

    if ($export_result) {
        while ($row = mysqli_fetch_assoc($export_result)) {
            echo "<tr>";
            echo "<td>" . e($row['id']) . "</td>";
            echo "<td>" . e($row['complaint_id']) . "</td>";
            echo "<td>" . e($row['complaint_date']) . "</td>";
            echo "<td>" . e($row['job_name']) . "</td>";
            echo "<td>" . e($row['vendor_name'] ?: 'No Vendor') . "</td>";
            echo "<td>" . e($row['tracking_number']) . "</td>";
            echo "<td>" . e($row['customer_name']) . "</td>";
            echo "<td>" . e($row['mobile']) . "</td>";
            echo "<td>" . e($row['priority']) . "</td>";
            echo "<td>" . e($row['status']) . "</td>";
            echo "<td>" . e($row['closing_date']) . "</td>";
            echo "</tr>";
        }
    }

    echo "</table>";
    exit;
}

$complaints_result = mysqli_query($conn, $complaints_sql);

$jobs_result = mysqli_query($conn, "
    SELECT j.id, j.job_name, j.job_code
    FROM jobs j
    INNER JOIN agent_jobs aj ON aj.job_id = j.id
    WHERE aj.agent_id = $agent_id
    ORDER BY j.job_name ASC
");

$vendors_result = mysqli_query($conn, "
    SELECT id, vendor_name, vendor_code
    FROM vendors
    WHERE status = 'Active'
    ORDER BY vendor_name ASC
");

$export_query = $_GET;
$export_query['export'] = 'xls';
$export_url = 'agent-complaints.php?' . http_build_query($export_query);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Complaints - GRAND SPEED NETWORK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f7fb; color: #172033; }
        .navbar { box-shadow: 0 10px 28px rgba(15, 23, 42, .12); }
        .brand-box {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: #0d6efd;
            font-weight: 800;
        }
        .page-card {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }
        .table th {
            white-space: nowrap;
            color: #475569;
            font-size: .86rem;
            text-transform: uppercase;
        }
        .table td { vertical-align: middle; }
        .form-control, .form-select { min-height: 42px; }
        .update-status { min-width: 145px; }
        .closing-date { min-width: 145px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="agent-dashboard.php">
            <span class="brand-box">GSN</span>
            <span>GRAND SPEED NETWORK</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#agentNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="agentNav">
            <div class="ms-lg-auto d-flex flex-column flex-lg-row align-items-lg-center gap-2 mt-3 mt-lg-0">
                <div class="text-white me-lg-2">
                    <span class="opacity-75">Welcome,</span>
                    <strong><?php echo e($agent_name); ?></strong>
                </div>
                <a href="agent-dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
                <a href="agent-add-complaint.php" class="btn btn-outline-light btn-sm">Add Complaint</a>
                <a href="agent-import.php" class="btn btn-outline-light btn-sm">Import CSV</a>
                <a href="agent-logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">My Complaints</h1>
            <p class="text-muted mb-0">Search, filter, export, and update complaints from your assigned jobs.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="agent-add-complaint.php" class="btn btn-primary">Add Complaint</a>
            <a href="<?php echo e($export_url); ?>" class="btn btn-success">Export XLS</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card page-card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <div class="col-lg-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" placeholder="Complaint ID, tracking, customer, mobile" value="<?php echo e($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="job_id" class="form-label">Job</label>
                    <select name="job_id" id="job_id" class="form-select">
                        <option value="">All Jobs</option>
                        <?php if ($jobs_result): ?>
                            <?php while ($job = mysqli_fetch_assoc($jobs_result)): ?>
                                <option value="<?php echo (int)$job['id']; ?>" <?php echo $job_filter === (int)$job['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($job['job_name']); ?><?php echo !empty($job['job_code']) ? ' (' . e($job['job_code']) . ')' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="vendor_id" class="form-label">Vendor</label>
                    <select name="vendor_id" id="vendor_id" class="form-select">
                        <option value="">All Vendors</option>
                        <?php if ($vendors_result): ?>
                            <?php while ($vendor = mysqli_fetch_assoc($vendors_result)): ?>
                                <option value="<?php echo (int)$vendor['id']; ?>" <?php echo $vendor_filter === (int)$vendor['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($vendor['vendor_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($allowed_statuses as $status): ?>
                            <option value="<?php echo e($status); ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>><?php echo e($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="priority" class="form-label">Priority</label>
                    <select name="priority" id="priority" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($allowed_priorities as $priority): ?>
                            <option value="<?php echo e($priority); ?>" <?php echo $priority_filter === $priority ? 'selected' : ''; ?>><?php echo e($priority); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="agent-complaints.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card page-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Complaint ID</th>
                        <th>Date</th>
                        <th>Job</th>
                        <th>Vendor</th>
                        <th>Tracking</th>
                        <th>Customer</th>
                        <th>Mobile</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Pending Days</th>
                        <th>Closing Date</th>
                        <th>Details</th>
                        <th>Remarks</th>
                        <th>Update</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($complaints_result && mysqli_num_rows($complaints_result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($complaints_result)): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td class="fw-semibold"><?php echo e($row['complaint_id']); ?></td>
                                <td><?php echo e($row['complaint_date']); ?></td>
                                <td><?php echo e($row['job_name']); ?></td>
                                <td><?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></td><input type="hidden" name="complaint_db_id" value="<?php echo (int)$row['id']; ?>">
                                <td><?php echo e($row['tracking_number']); ?></td>
                                <td><?php echo e($row['customer_name']); ?></td>
                                <td><?php echo e($row['mobile']); ?></td>
                                <td><span class="badge <?php echo priority_badge_class($row['priority']); ?>"><?php echo e($row['priority']); ?></span></td>
                                <form method="post">
                                    <input type="hidden" name="complaint_db_id" value="<?php echo (int)$row['id']; ?>">
                                    <td>
                                        <input
    type="hidden"
    name="complaint_db_id"
    value="<?php echo (int)$row['id']; ?>"
>

<td>
    <select
        name="status"
        class="form-select form-select-sm update-status"
    >
        <?php foreach ($allowed_statuses as $status): ?>
            <option
                value="<?php echo e($status); ?>"
                <?php echo $row['status'] === $status ? 'selected' : ''; ?>
            >
                <?php echo e($status); ?>
            </option>
        <?php endforeach; ?>
    </select>
</td>

<!-- Pending Days -->
<td>
    <?php
    $days = (int)($row['pending_days'] ?? 0);

    if ($days >= 15) {
        echo "<span class='badge bg-danger'>{$days} Days</span>";
    } elseif ($days >= 8) {
        echo "<span class='badge bg-warning text-dark'>{$days} Days</span>";
    } elseif ($days >= 4) {
        echo "<span class='badge bg-info text-dark'>{$days} Days</span>";
    } else {
        echo "<span class='badge bg-success'>{$days} Days</span>";
    }
    ?>
</td>

<td>
    <input
        type="date"
        name="closing_date"
        class="form-control form-control-sm closing-date"
        value="<?php echo e($row['closing_date']); ?>"
    >
</td>

<td>
    <a
        href="agent-complaint-details.php?id=<?php echo (int)$row['id']; ?>"
        class="btn btn-sm btn-outline-secondary"
    >
        Details
    </a>
</td>

<td>
    <a
        href="agent-remarks.php?id=<?php echo (int)$row['id']; ?>&back=<?php echo rawurlencode($_SERVER['REQUEST_URI']); ?>"
        class="btn btn-sm btn-outline-primary"
    >
        Remarks
    </a>
</td>
                                    <td><button type="submit" name="update_complaint" value="1" class="btn btn-sm btn-success">Update</button></td>
                                </form>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="14" class="text-center text-muted py-4">No complaints found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
