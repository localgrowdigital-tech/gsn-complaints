<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agentId = (int) $_SESSION['agent_id'];
$agentName = $_SESSION['agent_name'] ?? 'Agent';
$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'Open' => 'text-bg-primary',
        'In Progress' => 'text-bg-warning',
        'Resolved' => 'text-bg-success',
        'Closed' => 'text-bg-secondary',
        default => 'text-bg-light'
    };
}

function priority_badge_class(string $priority): string
{
    return match ($priority) {
        'Most Urgent' => 'text-bg-danger',
        'Urgent' => 'text-bg-warning',
        default => 'text-bg-secondary'
    };
}

function reminder_badge(?string $date): array
{
    if (!$date) {
        return ['No Date', 'text-bg-secondary'];
    }

    $today = date('Y-m-d');

    if ($date < $today) {
        return ['Overdue', 'text-bg-danger'];
    }

    if ($date === $today) {
        return ['Due Today', 'text-bg-warning'];
    }

    return ['Upcoming', 'text-bg-primary'];
}

$allowedStatuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
$allowedPriorities = ['Normal', 'Urgent', 'Most Urgent'];
$allowedComplaintTypes = [
    'POD Required',
    'Wrong Delivery',
    'Lost Shipment',
    'Damaged Shipment'
];

/*
|--------------------------------------------------------------------------
| Update complaint status
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_complaint'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $complaintId = (int) ($_POST['complaint_db_id'] ?? 0);
    $newStatus = trim((string) ($_POST['status'] ?? ''));
    $closingDate = trim((string) ($_POST['closing_date'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } elseif ($complaintId <= 0) {
        $error = 'Invalid complaint selected.';
    } elseif (!in_array($newStatus, $allowedStatuses, true)) {
        $error = 'Invalid status selected.';
    } elseif ($closingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $closingDate)) {
        $error = 'Invalid closing date.';
    } else {
        $accessSql = "
            SELECT c.id
            FROM complaints c
            INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
            WHERE c.id = ?
              AND aj.agent_id = ?
            LIMIT 1
        ";

        $accessStmt = mysqli_prepare($conn, $accessSql);

        if (!$accessStmt) {
            $error = 'Unable to verify complaint access.';
        } else {
            mysqli_stmt_bind_param($accessStmt, 'ii', $complaintId, $agentId);
            mysqli_stmt_execute($accessStmt);
            $accessResult = mysqli_stmt_get_result($accessStmt);
            $hasAccess = mysqli_num_rows($accessResult) === 1;
            mysqli_stmt_close($accessStmt);

            if (!$hasAccess) {
                $error = 'You are not allowed to update this complaint.';
            } else {
                if (in_array($newStatus, ['Resolved', 'Closed'], true) && $closingDate === '') {
                    $closingDate = date('Y-m-d');
                }

                if (!in_array($newStatus, ['Resolved', 'Closed'], true)) {
                    $closingDate = '';
                }

                $updateSql = "
                    UPDATE complaints
                    SET status = ?,
                        closing_date = NULLIF(?, '')
                    WHERE id = ?
                ";

                $updateStmt = mysqli_prepare($conn, $updateSql);

                if (!$updateStmt) {
                    $error = 'Unable to prepare complaint update.';
                } else {
                    mysqli_stmt_bind_param($updateStmt, 'ssi', $newStatus, $closingDate, $complaintId);

                    if (mysqli_stmt_execute($updateStmt)) {
                        $success = 'Complaint updated successfully.';
                    } else {
                        $error = 'Unable to update complaint. Please try again.';
                    }

                    mysqli_stmt_close($updateStmt);
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/
$search = trim((string) ($_GET['search'] ?? ''));
$jobFilter = (int) ($_GET['job_id'] ?? 0);
$vendorFilter = (int) ($_GET['vendor_id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$priorityFilter = trim((string) ($_GET['priority'] ?? ''));
$complaintTypeFilter = trim((string) ($_GET['complaint_type'] ?? ''));
$oldFilter = ($_GET['old'] ?? '') === '1';
$todayFilter = ($_GET['today'] ?? '') === '1';
$monthFilter = ($_GET['month'] ?? '') === '1';
$materialFilter = ($_GET['material'] ?? '') === '1';
$pinnedFilter = ($_GET['pinned'] ?? '') === '1';

$where = ['aj.agent_id = ?'];
$params = [$agentId];
$types = 'i';

if ($oldFilter) {
    $where[] = "c.status IN ('Open', 'In Progress') AND DATEDIFF(CURDATE(), c.complaint_date) >= 4";
}

if ($todayFilter) {
    $where[] = "DATE(c.complaint_date) = CURDATE()";
}

if ($monthFilter) {
    $where[] = "YEAR(c.complaint_date) = YEAR(CURDATE()) AND MONTH(c.complaint_date) = MONTH(CURDATE())";
}

if ($materialFilter) {
    $where[] = "c.complaint_type IN ('Lost Shipment', 'Damaged Shipment') AND c.status NOT IN ('Resolved', 'Closed')";
}

if ($pinnedFilter) {
    $where[] = "c.is_pinned = 1";
}

if ($search !== '') {
    $where[] = "(
        c.complaint_id LIKE ? OR
        c.tracking_number LIKE ? OR
        c.secondary_tracking_number LIKE ? OR
        c.customer_name LIKE ? OR
        c.mobile LIKE ?
    )";

    $likeSearch = '%' . $search . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $likeSearch;
        $types .= 's';
    }
}

if ($jobFilter > 0) {
    $where[] = 'c.job_id = ?';
    $params[] = $jobFilter;
    $types .= 'i';
}

if ($vendorFilter > 0) {
    $where[] = 'c.vendor_id = ?';
    $params[] = $vendorFilter;
    $types .= 'i';
}

if ($statusFilter !== '') {
    if ($statusFilter === 'Pending') {
        $where[] = "c.status IN ('Open','In Progress')";
    } elseif ($statusFilter === 'Closed') {
        $where[] = "c.status IN ('Resolved','Closed')";
    } elseif (in_array($statusFilter, $allowedStatuses, true)) {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }
}

if ($priorityFilter !== '' && in_array($priorityFilter, $allowedPriorities, true)) {
    $where[] = 'c.priority = ?';
    $params[] = $priorityFilter;
    $types .= 's';
}

if ($complaintTypeFilter !== '') {
    $where[] = 'c.complaint_type = ?';
    $params[] = $complaintTypeFilter;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

/*
|--------------------------------------------------------------------------
| Complaints query
|--------------------------------------------------------------------------
*/
$complaintsSql = "
    SELECT
        c.id,
        c.complaint_id,
        c.complaint_date,
        c.tracking_number,
        c.secondary_tracking_number,
        c.customer_name,
        c.mobile,
        c.complaint_type,
        c.priority,
        c.status,
        c.closing_date,
        c.is_pinned,
        c.reminder_date,
        c.reminder_note,
        CASE
            WHEN c.status IN ('Closed', 'Resolved') AND c.closing_date IS NOT NULL
                THEN DATEDIFF(c.closing_date, c.complaint_date)
            ELSE DATEDIFF(CURDATE(), c.complaint_date)
        END AS pending_days,
        j.job_name,
        v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE $whereSql
    ORDER BY
        c.is_pinned DESC,
        CASE
            WHEN c.reminder_date IS NULL THEN 2
            WHEN c.reminder_date <= CURDATE() THEN 0
            ELSE 1
        END,
        c.reminder_date ASC,
        c.id DESC
";

$complaintsStmt = mysqli_prepare($conn, $complaintsSql);

if (!$complaintsStmt) {
    $error = $error ?: 'Unable to load complaints.';
    $complaintsResult = false;
} else {
    mysqli_stmt_bind_param($complaintsStmt, $types, ...$params);
    mysqli_stmt_execute($complaintsStmt);
    $complaintsResult = mysqli_stmt_get_result($complaintsStmt);
}

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/
if (isset($_GET['export']) && $_GET['export'] === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=agent-complaints-' . date('Ymd-His') . '.xls');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<table border='1'>";
    echo "<tr>
            <th>ID</th>
            <th>Complaint ID</th>
            <th>Date</th>
            <th>Job</th>
            <th>Vendor</th>
            <th>Tracking</th>
            <th>Secondary Tracking</th>
            <th>Customer</th>
            <th>Mobile</th>
            <th>Complaint Type</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Pending Days</th>
            <th>Closing Date</th>
            <th>Pinned</th>
            <th>Reminder Date</th>
            <th>Reminder Note</th>
          </tr>";

    if ($complaintsResult) {
        while ($row = mysqli_fetch_assoc($complaintsResult)) {
            echo '<tr>';
            echo '<td>' . e($row['id']) . '</td>';
            echo '<td>' . e($row['complaint_id']) . '</td>';
            echo '<td>' . e($row['complaint_date']) . '</td>';
            echo '<td>' . e($row['job_name']) . '</td>';
            echo '<td>' . e($row['vendor_name'] ?: 'No Vendor') . '</td>';
            echo '<td>' . e($row['tracking_number']) . '</td>';
            echo '<td>' . e($row['secondary_tracking_number']) . '</td>';
            echo '<td>' . e($row['customer_name']) . '</td>';
            echo '<td>' . e($row['mobile']) . '</td>';
            echo '<td>' . e($row['complaint_type']) . '</td>';
            echo '<td>' . e($row['priority']) . '</td>';
            echo '<td>' . e($row['status']) . '</td>';
            echo '<td>' . e($row['pending_days']) . '</td>';
            echo '<td>' . e($row['closing_date']) . '</td>';
            echo '<td>' . ((int) $row['is_pinned'] === 1 ? 'Yes' : 'No') . '</td>';
            echo '<td>' . e($row['reminder_date']) . '</td>';
            echo '<td>' . e($row['reminder_note']) . '</td>';
            echo '</tr>';
        }
    }

    echo '</table>';

    if ($complaintsStmt) {
        mysqli_stmt_close($complaintsStmt);
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| Dropdown data
|--------------------------------------------------------------------------
*/
$jobsSql = "
    SELECT j.id, j.job_name, j.job_code
    FROM jobs j
    INNER JOIN agent_jobs aj ON aj.job_id = j.id
    WHERE aj.agent_id = ?
    ORDER BY j.job_name ASC
";

$jobsStmt = mysqli_prepare($conn, $jobsSql);
$jobsResult = false;

if ($jobsStmt) {
    mysqli_stmt_bind_param($jobsStmt, 'i', $agentId);
    mysqli_stmt_execute($jobsStmt);
    $jobsResult = mysqli_stmt_get_result($jobsStmt);
}

$vendorsSql = "
    SELECT id, vendor_name, vendor_code
    FROM vendors
    WHERE status = 'Active'
    ORDER BY vendor_name ASC
";

$vendorsResult = mysqli_query($conn, $vendorsSql);

$complaintTypesSql = "
    SELECT DISTINCT complaint_type
    FROM complaints
    WHERE complaint_type IS NOT NULL
      AND complaint_type <> ''
    ORDER BY complaint_type ASC
";

$complaintTypesResult = mysqli_query($conn, $complaintTypesSql);

$exportQuery = $_GET;
$exportQuery['export'] = 'xls';
$exportUrl = 'agent-complaints.php?' . http_build_query($exportQuery);

$activeFilterLabel = '';
$activeVendorName = '';
if ($vendorFilter > 0 && $vendorsResult) {
    mysqli_data_seek($vendorsResult,0);
    while($v=mysqli_fetch_assoc($vendorsResult)){
        if((int)$v['id']===$vendorFilter){ $activeVendorName=$v['vendor_name']; break; }
    }
    mysqli_data_seek($vendorsResult,0);
}



if ($materialFilter) {
    $activeFilterLabel = 'New Material: Lost Shipment + Damaged Shipment';
} elseif ($complaintTypeFilter !== '') {
    $activeFilterLabel = 'Complaint Type: ' . $complaintTypeFilter;
} elseif ($oldFilter) {
    $activeFilterLabel = 'Old Complaints: Pending 4+ Days';
} elseif ($todayFilter) {
    $activeFilterLabel = "Today's Complaints";
} elseif ($monthFilter) {
    $activeFilterLabel = 'Current Month';
} elseif ($pinnedFilter) {
    $activeFilterLabel = 'Pinned Complaints';
}
if($activeVendorName!==''){
    $activeFilterLabel .= ($activeFilterLabel!==''?' | ':'').'Vendor: '.$activeVendorName;
}
if($statusFilter==='Pending'){
    $activeFilterLabel .= ($activeFilterLabel!==''?' | ':'').'Showing: Pending Complaints';
}elseif($statusFilter==='Closed'){
    $activeFilterLabel .= ($activeFilterLabel!==''?' | ':'').'Showing: Closed/Resolved Complaints';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Complaints - GRAND SPEED NETWORK</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --page-bg: #f4f7fb;
            --main-text: #172033;
            --muted-text: #64748b;
            --card-radius: 18px;
        }

        body {
            background:
                radial-gradient(circle at top right, rgba(13, 110, 253, .08), transparent 28%),
                var(--page-bg);
            color: var(--main-text);
            min-height: 100vh;
        }

        .topbar {
            background: linear-gradient(110deg, #0b5ed7, #4338ca);
            box-shadow: 0 12px 35px rgba(30, 64, 175, .22);
        }

        .brand-box {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: #0d6efd;
            font-weight: 900;
        }

        .page-card {
            border: 0;
            border-radius: var(--card-radius);
            box-shadow: 0 12px 32px rgba(15, 23, 42, .07);
        }

        .filter-label {
            font-size: .82rem;
            font-weight: 700;
            color: #475569;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-radius: 10px;
        }

        .table-wrap {
            max-height: 70vh;
            overflow: auto;
        }

        .table {
            min-width: 1650px;
        }

        .table thead th {
            position: sticky;
            top: 0;
            z-index: 3;
            background: #f8fafc;
            color: #64748b;
            font-size: .76rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
            border-bottom: 1px solid #e2e8f0;
        }

        .table td {
            vertical-align: middle;
            white-space: nowrap;
        }

        .update-status {
            min-width: 145px;
        }

        .closing-date {
            min-width: 145px;
        }

        .type-badge {
            background: #eef2ff;
            color: #4338ca;
            border: 1px solid #c7d2fe;
        }

        .pinned-row {
            background: #fffaf0;
        }

        .pin-icon {
            color: #dc3545;
            font-size: 1.05rem;
        }

        .empty-state {
            min-height: 240px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: var(--muted-text);
        }

        .quick-filter {
            border-radius: 999px;
        }

        @media print {
            .no-print,
            nav {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .page-card {
                box-shadow: none;
            }

            .table-wrap {
                max-height: none;
                overflow: visible;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="agent-dashboard.php">
            <span class="brand-box">GSN</span>
            <span class="d-none d-sm-inline">GRAND SPEED NETWORK</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#agentNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="agentNav">
            <div class="ms-lg-auto d-flex flex-column flex-lg-row align-items-lg-center gap-2 mt-3 mt-lg-0">
                <div class="text-white me-lg-2">
                    <span class="opacity-75">Welcome,</span>
                    <strong><?php echo e($agentName); ?></strong>
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
            <p class="text-muted mb-0">Search, filter, export and update complaints assigned to your jobs.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="agent-add-complaint.php" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Add Complaint
            </a>

            <a href="<?php echo e($exportUrl); ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-excel me-1"></i> Export XLS
            </a>

            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($activeFilterLabel !== ''): ?>
        <div class="alert alert-primary d-flex justify-content-between align-items-center flex-wrap gap-2 no-print">
            <div>
                <strong>Active Dashboard Filter:</strong>
                <?php echo e($activeFilterLabel); ?>
            </div>
            <a href="agent-complaints.php" class="btn btn-sm btn-outline-primary">Clear Filter</a>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2 mb-3 no-print">
        <a href="agent-complaints.php?complaint_type=POD+Required" class="btn btn-outline-primary btn-sm quick-filter">POD Required</a>
        <a href="agent-complaints.php?complaint_type=Wrong+Delivery" class="btn btn-outline-danger btn-sm quick-filter">Wrong Delivery</a>
        <a href="agent-complaints.php?material=1" class="btn btn-outline-warning btn-sm quick-filter">New Material</a>
        <a href="agent-complaints.php?old=1" class="btn btn-outline-secondary btn-sm quick-filter">Old Complaints</a>
        <a href="agent-complaints.php?pinned=1" class="btn btn-outline-dark btn-sm quick-filter">Pinned</a>
    </div>

    <section class="card page-card mb-4 no-print">
        <div class="card-body">
            <form method="get" class="row g-3">

                <div class="col-lg-4">
                    <label for="search" class="filter-label mb-1">Search</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        class="form-control"
                        placeholder="Complaint ID, tracking, customer or mobile"
                        value="<?php echo e($search); ?>"
                    >
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="job_id" class="filter-label mb-1">Job</label>
                    <select name="job_id" id="job_id" class="form-select">
                        <option value="">All Jobs</option>

                        <?php if ($jobsResult): ?>
                            <?php while ($job = mysqli_fetch_assoc($jobsResult)): ?>
                                <option
                                    value="<?php echo (int) $job['id']; ?>"
                                    <?php echo $jobFilter === (int) $job['id'] ? 'selected' : ''; ?>
                                >
                                    <?php echo e($job['job_name']); ?>
                                    <?php echo !empty($job['job_code']) ? ' (' . e($job['job_code']) . ')' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="vendor_id" class="filter-label mb-1">Vendor</label>
                    <select name="vendor_id" id="vendor_id" class="form-select">
                        <option value="">All Vendors</option>

                        <?php if ($vendorsResult): ?>
                            <?php while ($vendor = mysqli_fetch_assoc($vendorsResult)): ?>
                                <option
                                    value="<?php echo (int) $vendor['id']; ?>"
                                    <?php echo $vendorFilter === (int) $vendor['id'] ? 'selected' : ''; ?>
                                >
                                    <?php echo e($vendor['vendor_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="status" class="filter-label mb-1">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Statuses</option>

                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?php echo e($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                <?php echo e($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-sm-6 col-lg-2">
                    <label for="priority" class="filter-label mb-1">Priority</label>
                    <select name="priority" id="priority" class="form-select">
                        <option value="">All Priorities</option>

                        <?php foreach ($allowedPriorities as $priority): ?>
                            <option value="<?php echo e($priority); ?>" <?php echo $priorityFilter === $priority ? 'selected' : ''; ?>>
                                <?php echo e($priority); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6 col-lg-4">
                    <label for="complaint_type" class="filter-label mb-1">Complaint Type</label>
                    <select name="complaint_type" id="complaint_type" class="form-select">
                        <option value="">All Complaint Types</option>

                        <?php if ($complaintTypesResult): ?>
                            <?php while ($typeRow = mysqli_fetch_assoc($complaintTypesResult)): ?>
                                <?php $typeValue = (string) $typeRow['complaint_type']; ?>
                                <option value="<?php echo e($typeValue); ?>" <?php echo $complaintTypeFilter === $typeValue ? 'selected' : ''; ?>>
                                    <?php echo e($typeValue); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-12 d-flex flex-wrap align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i> Apply Filters
                    </button>

                    <a href="agent-complaints.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </section>

    <section class="card page-card">
        <div class="card-body p-0">
            <div class="table-wrap">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Pin</th>
                            <th>Complaint ID</th>
                            <th>Date</th>
                            <th>Job</th>
                            <th>Vendor</th>
                            <th>Complaint Type</th>
                            <th>Tracking</th>
                            <th>Customer</th>
                            <th>Mobile</th>
                            <th>Priority</th>
                            <th>Reminder</th>
                            <th>Status</th>
                            <th>Pending Days</th>
                            <th>Closing Date</th>
                            <th>Details</th>
                            <th>Remarks</th>
                            <th>Update</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($complaintsResult && mysqli_num_rows($complaintsResult) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($complaintsResult)): ?>
                            <?php
                            $days = max(0, (int) ($row['pending_days'] ?? 0));
                            [$reminderText, $reminderClass] = reminder_badge($row['reminder_date']);
                            ?>
                            <tr class="<?php echo (int) $row['is_pinned'] === 1 ? 'pinned-row' : ''; ?>">
                                <td>
                                    <?php if ((int) $row['is_pinned'] === 1): ?>
                                        <i class="bi bi-pin-angle-fill pin-icon" title="Pinned complaint"></i>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td class="fw-semibold">
                                    <a
                                        href="agent-complaint-details.php?id=<?php echo (int) $row['id']; ?>"
                                        class="text-primary text-decoration-none"
                                    >
                                        <?php echo e($row['complaint_id']); ?>
                                    </a>
                                </td>

                                <td><?php echo e(date('d M Y', strtotime($row['complaint_date']))); ?></td>
                                <td><?php echo e($row['job_name'] ?: 'No Job'); ?></td>
                                <td><?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></td>

                                <td>
                                    <span class="badge type-badge">
                                        <?php echo e($row['complaint_type']); ?>
                                    </span>
                                </td>

                                <td><?php echo e($row['tracking_number']); ?></td>
                                <td><?php echo e($row['customer_name']); ?></td>
                                <td><?php echo e($row['mobile']); ?></td>

                                <td>
                                    <span class="badge <?php echo priority_badge_class($row['priority']); ?>">
                                        <?php echo e($row['priority']); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ((int) $row['is_pinned'] === 1): ?>
                                        <span class="badge <?php echo e($reminderClass); ?>">
                                            <?php echo e($reminderText); ?>
                                        </span>

                                        <?php if (!empty($row['reminder_date'])): ?>
                                            <div class="small text-muted mt-1">
                                                <?php echo e(date('d M Y', strtotime($row['reminder_date']))); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not pinned</span>
                                    <?php endif; ?>
                                </td>

                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="complaint_db_id" value="<?php echo (int) $row['id']; ?>">

                                    <td>
                                        <select name="status" class="form-select form-select-sm update-status">
                                            <?php foreach ($allowedStatuses as $status): ?>
                                                <option value="<?php echo e($status); ?>" <?php echo $row['status'] === $status ? 'selected' : ''; ?>>
                                                    <?php echo e($status); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>

                                    <td>
                                        <?php if ($days >= 15): ?>
                                            <span class="badge text-bg-danger"><?php echo $days; ?> Days</span>
                                        <?php elseif ($days >= 8): ?>
                                            <span class="badge text-bg-warning"><?php echo $days; ?> Days</span>
                                        <?php elseif ($days >= 4): ?>
                                            <span class="badge text-bg-info"><?php echo $days; ?> Days</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success"><?php echo $days; ?> Days</span>
                                        <?php endif; ?>
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
                                            href="agent-complaint-details.php?id=<?php echo (int) $row['id']; ?>"
                                            class="btn btn-sm btn-outline-secondary"
                                        >
                                            Details
                                        </a>
                                    </td>

                                    <td>
                                        <a
                                            href="agent-remarks.php?id=<?php echo (int) $row['id']; ?>&back=<?php echo rawurlencode($_SERVER['REQUEST_URI']); ?>"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            Remarks
                                        </a>
                                    </td>

                                    <td>
                                        <button type="submit" name="update_complaint" value="1" class="btn btn-sm btn-success">
                                            Update
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="17">
                                <div class="empty-state">
                                    <i class="bi bi-inbox display-5 mb-2"></i>
                                    <div class="fw-semibold">No complaints found</div>
                                    <div class="small">Try clearing or changing the filters.</div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.querySelectorAll('.update-status').forEach(function (select) {
    select.addEventListener('change', function () {
        const row = this.closest('tr');
        const dateInput = row.querySelector('.closing-date');

        if (!dateInput) {
            return;
        }

        if (this.value === 'Closed' || this.value === 'Resolved') {
            if (!dateInput.value) {
                dateInput.value = new Date().toISOString().slice(0, 10);
            }
        } else {
            dateInput.value = '';
        }
    });
});
</script>

</body>
</html>

<?php
if ($complaintsStmt) {
    mysqli_stmt_close($complaintsStmt);
}

if (isset($jobsStmt) && $jobsStmt) {
    mysqli_stmt_close($jobsStmt);
}
?>
