<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
$success = '';
$error = '';

if (isset($_POST['update'])) {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $closing_date = trim($_POST['closing_date'] ?? '');
        $secondary_tracking_number = trim($_POST['secondary_tracking_number'] ?? '');
        $vendor_id = !empty($_POST['vendor_id']) ? (int)$_POST['vendor_id'] : 0;

        if ($id <= 0 || !in_array($status, $allowed_statuses, true)) {
            $error = 'Please select a valid complaint and status.';
        } else {
            $closing_date_value = $closing_date !== '' ? $closing_date : null;

            $stmt = mysqli_prepare($conn, "
                UPDATE complaints
                SET status = ?,
                    closing_date = ?,
                    secondary_tracking_number = ?,
                    vendor_id = ?
                WHERE id = ?
            ");

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'sssii',
                    $status,
                    $closing_date_value,
                    $secondary_tracking_number,
                    $vendor_id,
                    $id
                );

                if (mysqli_stmt_execute($stmt)) {
                    $success = 'Complaint updated successfully.';
                } else {
                    $error = 'Complaint could not be updated.';
                }

                mysqli_stmt_close($stmt);
            } else {
                $error = 'Database request could not be prepared.';
            }
        }
    }
}

$vendor_options = [];
$vendors_result = mysqli_query($conn, "
    SELECT id, vendor_name
    FROM vendors
    WHERE status = 'Active'
    ORDER BY vendor_name ASC
");

if ($vendors_result) {
    while ($vendor = mysqli_fetch_assoc($vendors_result)) {
        $vendor_options[] = $vendor;
    }
}

$job_options = [];
$jobs_result = mysqli_query($conn, "SELECT id, job_name FROM jobs ORDER BY job_name ASC");
if ($jobs_result) {
    while ($job_row = mysqli_fetch_assoc($jobs_result)) {
        $job_options[] = $job_row;
    }
}

$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['status'] ?? '');
$job = (int)($_GET['job'] ?? 0);
$priority = trim($_GET['priority'] ?? '');
$today = ($_GET['today'] ?? '') === '1';
$old = ($_GET['old'] ?? '') === '1';
$month = trim($_GET['month'] ?? '');

$where_parts = ['1=1'];

if ($job > 0) {
    $where_parts[] = 'complaints.job_id = ' . $job;
}

if ($search !== '') {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $where_parts[] = "(
        complaint_id LIKE '%{$safe_search}%' OR
        tracking_number LIKE '%{$safe_search}%' OR
        secondary_tracking_number LIKE '%{$safe_search}%' OR
        customer_name LIKE '%{$safe_search}%' OR
        mobile LIKE '%{$safe_search}%'
    )";
}

if ($filter !== '' && $filter !== 'All' && in_array($filter, $allowed_statuses, true)) {
    $safe_filter = mysqli_real_escape_string($conn, $filter);
    $where_parts[] = "complaints.status = '{$safe_filter}'";
}

if ($priority !== '') {
    $safe_priority = mysqli_real_escape_string($conn, $priority);
    $where_parts[] = "complaints.priority = '{$safe_priority}'";
}

if ($today) {
    $where_parts[] = 'DATE(complaints.complaint_date) = CURDATE()';
}

if ($old) {
    $where_parts[] = "complaints.status IN ('Open', 'In Progress')";
    $where_parts[] = 'DATEDIFF(CURDATE(), complaints.complaint_date) >= 4';
}

if ($month === 'current') {
    $where_parts[] = 'YEAR(complaints.complaint_date) = YEAR(CURDATE())';
    $where_parts[] = 'MONTH(complaints.complaint_date) = MONTH(CURDATE())';
}

$where = 'WHERE ' . implode(' AND ', $where_parts);

if (isset($_GET['export'])) {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename=complaints.xls');

    echo "ID\tComplaint ID\tDate\tJob\tVendor\tTracking\tSecondary Tracking\tCustomer\tMobile\tStatus\tClosing Date\n";

    $export = mysqli_query($conn, "
        SELECT complaints.*, jobs.job_name, vendors.vendor_name
        FROM complaints
        LEFT JOIN jobs ON complaints.job_id = jobs.id
        LEFT JOIN vendors ON complaints.vendor_id = vendors.id
        {$where}
        ORDER BY complaints.id DESC
    ");

    if ($export) {
        while ($row = mysqli_fetch_assoc($export)) {
            $values = [
                $row['id'],
                $row['complaint_id'],
                $row['complaint_date'],
                $row['job_name'],
                $row['vendor_name'] ?: 'No Vendor',
                $row['tracking_number'],
                $row['secondary_tracking_number'],
                $row['customer_name'],
                $row['mobile'],
                $row['status'],
                $row['closing_date'],
            ];

            $values = array_map(static function ($value) {
                return str_replace(["\t", "\r", "\n"], ' ', (string)$value);
            }, $values);

            echo implode("\t", $values) . "\n";
        }
    }

    exit;
}

$result = mysqli_query($conn, "
    SELECT
        complaints.*,
        jobs.job_name,
        vendors.vendor_name,
        CASE
            WHEN complaints.status IN ('Closed', 'Resolved')
                 AND complaints.closing_date IS NOT NULL
            THEN DATEDIFF(complaints.closing_date, complaints.complaint_date)
            ELSE DATEDIFF(CURDATE(), complaints.complaint_date)
        END AS pending_days
    FROM complaints
    LEFT JOIN jobs ON complaints.job_id = jobs.id
    LEFT JOIN vendors ON complaints.vendor_id = vendors.id
    {$where}
    ORDER BY complaints.id DESC
");

$total_rows = $result ? mysqli_num_rows($result) : 0;

$query_params = [
    'search' => $search,
    'job' => $job ?: '',
    'status' => $filter,
    'priority' => $priority,
    'today' => $today ? '1' : '',
    'old' => $old ? '1' : '',
    'month' => $month,
];
$export_url = 'view-complaints.php?' . http_build_query(array_filter($query_params, static fn($v) => $v !== '')) . '&export=1';

$pageTitle = 'Complaints';
$pageHeading = 'Complaints';
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="content-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
    <div>
        <span class="page-eyebrow">GRAND SPEED NETWORK</span>
        <h1 class="page-title mb-1">Complaint Management</h1>
        <p class="page-subtitle mb-0">Search, filter, review and update all complaints.</p>
    </div>

    <a href="add-complaint.php" class="btn btn-primary px-4">
        <i class="bi bi-plus-circle me-2"></i>Add Complaint
    </a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo e($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo e($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<section class="premium-card mb-4 no-print">
    <div class="card-accent"></div>
    <div class="p-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-12 col-xl-5">
                <label class="form-label">Search Complaint</label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="Complaint ID, tracking, customer or mobile"
                        value="<?php echo e($search); ?>"
                    >
                </div>
            </div>

            <div class="col-12 col-md-4 col-xl-2">
                <label class="form-label">Job</label>
                <select name="job" class="form-select form-select-lg">
                    <option value="">All Jobs</option>
                    <?php foreach ($job_options as $job_row): ?>
                        <option value="<?php echo (int)$job_row['id']; ?>" <?php echo $job === (int)$job_row['id'] ? 'selected' : ''; ?>>
                            <?php echo e($job_row['job_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-4 col-xl-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select form-select-lg">
                    <option value="All">All Status</option>
                    <?php foreach ($allowed_statuses as $status_option): ?>
                        <option value="<?php echo e($status_option); ?>" <?php echo $filter === $status_option ? 'selected' : ''; ?>>
                            <?php echo e($status_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-4 col-xl-3">
                <div class="d-grid d-sm-flex gap-2">
                    <button class="btn btn-primary btn-lg flex-fill" type="submit">
                        <i class="bi bi-funnel me-2"></i>Apply
                    </button>
                    <a href="view-complaints.php" class="btn btn-light btn-lg" title="Reset">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</section>

<section class="premium-card">
    <div class="card-accent"></div>
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 p-4 border-bottom">
        <div>
            <h2 class="h5 fw-bold mb-1">All Complaints</h2>
            <p class="text-muted mb-0"><?php echo $total_rows; ?> complaint(s) found</p>
        </div>

        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="<?php echo e($export_url); ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-excel me-2"></i>Excel
            </a>
            <button type="button" class="btn btn-dark" onclick="window.print();">
                <i class="bi bi-printer me-2"></i>Print
            </button>
        </div>
    </div>

    <div class="table-responsive complaint-table-wrap">
        <table class="table premium-table align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Complaint</th>
                    <th>Date</th>
                    <th>Job</th>
                    <th>Vendor</th>
                    <th>Tracking</th>
                    <th>Customer</th>
                    <th>Mobile</th>
                    <th>Status</th>
                    <th>Pending</th>
                    <th>Secondary Tracking</th>
                    <th>Closing Date</th>
                    <th class="no-print">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result && mysqli_num_rows($result) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <?php
                    $days = max(0, (int)($row['pending_days'] ?? 0));
                    $pending_class = 'success';
                    if ($days >= 15) {
                        $pending_class = 'danger';
                    } elseif ($days >= 8) {
                        $pending_class = 'warning';
                    } elseif ($days >= 4) {
                        $pending_class = 'info';
                    }

                    $status_class = match ($row['status']) {
                        'Open' => 'status-open',
                        'In Progress' => 'status-progress',
                        'Resolved' => 'status-resolved',
                        'Closed' => 'status-closed',
                        default => 'status-default',
                    };
                    ?>
                    <tr>
                        <form method="POST">
                            <td class="text-muted">#<?php echo (int)$row['id']; ?></td>
                            <td>
                                <a class="complaint-id-link" href="complaint-details.php?id=<?php echo (int)$row['id']; ?>">
                                    <?php echo e($row['complaint_id'] ?: $row['id']); ?>
                                </a>
                            </td>
                            <td><?php echo e($row['complaint_date']); ?></td>
                            <td><?php echo e($row['job_name'] ?: 'No Job'); ?></td>
                            <td style="min-width: 170px;">
                                <select name="vendor_id" class="form-select form-select-sm">
                                    <option value="0">No Vendor</option>
                                    <?php foreach ($vendor_options as $vendor): ?>
                                        <option value="<?php echo (int)$vendor['id']; ?>" <?php echo (int)($row['vendor_id'] ?? 0) === (int)$vendor['id'] ? 'selected' : ''; ?>>
                                            <?php echo e($vendor['vendor_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="fw-semibold"><?php echo e($row['tracking_number']); ?></td>
                            <td><?php echo e($row['customer_name']); ?></td>
                            <td><?php echo e($row['mobile']); ?></td>
                            <td style="min-width: 155px;">
                                <select name="status" class="form-select form-select-sm <?php echo e($status_class); ?>">
                                    <?php foreach ($allowed_statuses as $status_option): ?>
                                        <option value="<?php echo e($status_option); ?>" <?php echo $row['status'] === $status_option ? 'selected' : ''; ?>>
                                            <?php echo e($status_option); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <span class="pending-badge pending-<?php echo e($pending_class); ?>">
                                    <?php echo $days; ?> Days
                                </span>
                            </td>
                            <td style="min-width: 175px;">
                                <input
                                    type="text"
                                    name="secondary_tracking_number"
                                    class="form-control form-control-sm"
                                    placeholder="Secondary tracking"
                                    value="<?php echo e($row['secondary_tracking_number'] ?? ''); ?>"
                                >
                            </td>
                            <td style="min-width: 155px;">
                                <input
                                    type="date"
                                    name="closing_date"
                                    class="form-control form-control-sm"
                                    value="<?php echo e($row['closing_date']); ?>"
                                >
                            </td>
                            <td class="no-print" style="min-width: 210px;">
                                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                <div class="d-flex gap-2">
                                    <a href="complaint-details.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-light btn-sm" title="Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="remarks.php?id=<?php echo (int)$row['id']; ?>&back=<?php echo rawurlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-outline-primary btn-sm" title="Remarks">
                                        <i class="bi bi-chat-left-text"></i>
                                    </a>
                                    <button type="submit" name="update" class="btn btn-primary btn-sm px-3">
                                        Update
                                    </button>
                                </div>
                            </td>
                        </form>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="13" class="text-center py-5">
                        <div class="empty-state-icon"><i class="bi bi-inbox"></i></div>
                        <h3 class="h6 fw-bold mt-3">No complaints found</h3>
                        <p class="text-muted mb-0">Try changing your search or filters.</p>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
.complaint-table-wrap { max-height: 72vh; }
.premium-table thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    background: #f7f9fc;
    color: #526079;
    border-bottom: 1px solid #e5eaf2;
    font-size: .75rem;
    letter-spacing: .04em;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 1rem .9rem;
}
.premium-table tbody td {
    padding: .85rem .9rem;
    border-color: #edf1f6;
    white-space: nowrap;
}
.premium-table tbody tr:hover { background: #f8fbff; }
.complaint-id-link { color: #0b63f6; font-weight: 800; text-decoration: none; }
.complaint-id-link:hover { text-decoration: underline; }
.pending-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .38rem .65rem;
    font-size: .75rem;
    font-weight: 800;
}
.pending-success { background: #e7f8ef; color: #087443; }
.pending-info { background: #e9f5ff; color: #075985; }
.pending-warning { background: #fff5d9; color: #9a6700; }
.pending-danger { background: #ffe8e8; color: #c91919; }
.status-open { background-color: #fff6e3; border-color: #ffd88a; }
.status-progress { background-color: #eaf3ff; border-color: #aacbff; }
.status-resolved { background-color: #e9f9f0; border-color: #a8e3c1; }
.status-closed { background-color: #f0f2f5; border-color: #cbd1d9; }
.empty-state-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto;
    display: grid;
    place-items: center;
    border-radius: 18px;
    background: #edf4ff;
    color: #0b63f6;
    font-size: 1.5rem;
}
@media print {
    .sidebar, .topbar, .content-header .btn, .no-print { display: none !important; }
    .main-content { margin-left: 0 !important; }
    .premium-card { box-shadow: none !important; border: 0 !important; }
    .complaint-table-wrap { max-height: none; overflow: visible; }
    .premium-table thead th { position: static; }
}
</style>

<?php include 'includes/footer.php'; ?>
