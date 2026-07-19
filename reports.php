<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_int($value)
{
    return is_numeric($value) ? (int)$value : 0;
}

function safe_date($value)
{
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return ($date && $date->format('Y-m-d') === $value)
        ? $value
        : '';
}

$from_date = safe_date(trim($_GET['from_date'] ?? ''));
$to_date = safe_date(trim($_GET['to_date'] ?? ''));
$job_id = get_int($_GET['job_id'] ?? 0);
$vendor_id = get_int($_GET['vendor_id'] ?? 0);
$status = trim($_GET['status'] ?? '');

$allowed_statuses = ['', 'Open', 'In Progress', 'Resolved', 'Closed'];

if (!in_array($status, $allowed_statuses, true)) {
    $status = '';
}

$where_parts = [];
$params = [];
$types = '';

if ($from_date !== '') {
    $where_parts[] = 'c.complaint_date >= ?';
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date !== '') {
    $where_parts[] = 'c.complaint_date <= ?';
    $params[] = $to_date;
    $types .= 's';
}

if ($job_id > 0) {
    $where_parts[] = 'c.job_id = ?';
    $params[] = $job_id;
    $types .= 'i';
}

if ($vendor_id > 0) {
    $where_parts[] = 'c.vendor_id = ?';
    $params[] = $vendor_id;
    $types .= 'i';
}

if ($status !== '') {
    $where_parts[] = 'c.status = ?';
    $params[] = $status;
    $types .= 's';
}

$where_sql = $where_parts
    ? ' WHERE ' . implode(' AND ', $where_parts)
    : '';

function run_prepared_query($conn, $sql, $types = '', $params = [])
{
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException('Database query preparation failed.');
    }

    if ($types !== '' && $params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException('Database query execution failed.');
    }

    return mysqli_stmt_get_result($stmt);
}

$jobs = [];
$jobs_result = mysqli_query(
    $conn,
    "SELECT id, job_name FROM jobs ORDER BY job_name ASC"
);

if ($jobs_result) {
    while ($row = mysqli_fetch_assoc($jobs_result)) {
        $jobs[] = $row;
    }
}

$vendors = [];
$vendors_result = mysqli_query(
    $conn,
    "SELECT id, vendor_name FROM vendors ORDER BY vendor_name ASC"
);

if ($vendors_result) {
    while ($row = mysqli_fetch_assoc($vendors_result)) {
        $vendors[] = $row;
    }
}

$metrics_sql = "
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS urgent_count,
        SUM(
            CASE
                WHEN c.status IN ('Open', 'In Progress')
                AND DATEDIFF(CURDATE(), c.complaint_date) >= 4
                THEN 1
                ELSE 0
            END
        ) AS old_count,
        ROUND(
            AVG(
                CASE
                    WHEN c.closing_date IS NOT NULL
                    THEN DATEDIFF(c.closing_date, c.complaint_date)
                END
            ),
            1
        ) AS avg_closing_days
    FROM complaints c
    $where_sql
";

$metrics_result = run_prepared_query(
    $conn,
    $metrics_sql,
    $types,
    $params
);

$metrics = mysqli_fetch_assoc($metrics_result) ?: [];

$total_count = (int)($metrics['total_count'] ?? 0);
$open_count = (int)($metrics['open_count'] ?? 0);
$closed_count = (int)($metrics['closed_count'] ?? 0);
$urgent_count = (int)($metrics['urgent_count'] ?? 0);
$old_count = (int)($metrics['old_count'] ?? 0);
$avg_closing_days = $metrics['avg_closing_days'] !== null
    ? (float)$metrics['avg_closing_days']
    : 0;

$job_report_sql = "
    SELECT
        COALESCE(j.job_name, 'No Job') AS job_name,
        COUNT(c.id) AS total_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS urgent_count
    FROM complaints c
    LEFT JOIN jobs j ON j.id = c.job_id
    $where_sql
    GROUP BY c.job_id, j.job_name
    ORDER BY total_count DESC, job_name ASC
";

$job_report = run_prepared_query(
    $conn,
    $job_report_sql,
    $types,
    $params
);

$vendor_report_sql = "
    SELECT
        COALESCE(v.vendor_name, 'No Vendor') AS vendor_name,
        COUNT(c.id) AS total_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count,
        ROUND(
            AVG(
                CASE
                    WHEN c.closing_date IS NOT NULL
                    THEN DATEDIFF(c.closing_date, c.complaint_date)
                END
            ),
            1
        ) AS avg_closing_days
    FROM complaints c
    LEFT JOIN vendors v ON v.id = c.vendor_id
    $where_sql
    GROUP BY c.vendor_id, v.vendor_name
    ORDER BY total_count DESC, vendor_name ASC
";

$vendor_report = run_prepared_query(
    $conn,
    $vendor_report_sql,
    $types,
    $params
);

$agent_where_parts = $where_parts;
$agent_params = $params;
$agent_types = $types;

$agent_where_sql = $agent_where_parts
    ? ' WHERE ' . implode(' AND ', $agent_where_parts)
    : '';

$agent_report_sql = "
    SELECT
        a.agent_name,
        GROUP_CONCAT(DISTINCT j.job_name ORDER BY j.job_name SEPARATOR ', ') AS assigned_jobs,
        COUNT(DISTINCT c.id) AS total_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Closed' THEN c.id END) AS closed_count,
        COUNT(DISTINCT CASE WHEN c.status IN ('Open', 'In Progress') THEN c.id END) AS pending_count
    FROM agents a
    LEFT JOIN agent_jobs aj ON aj.agent_id = a.id
    LEFT JOIN jobs j ON j.id = aj.job_id
    LEFT JOIN complaints c ON c.job_id = aj.job_id
    $agent_where_sql
    GROUP BY a.id, a.agent_name
    ORDER BY total_count DESC, a.agent_name ASC
";

$agent_report = run_prepared_query(
    $conn,
    $agent_report_sql,
    $agent_types,
    $agent_params
);

$monthly_sql = "
    SELECT
        DATE_FORMAT(c.complaint_date, '%Y-%m') AS month_key,
        DATE_FORMAT(c.complaint_date, '%b %Y') AS month_label,
        COUNT(*) AS total_count
    FROM complaints c
    $where_sql
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
";

$monthly_result = run_prepared_query(
    $conn,
    $monthly_sql,
    $types,
    $params
);

$monthly_labels = [];
$monthly_values = [];

while ($row = mysqli_fetch_assoc($monthly_result)) {
    $monthly_labels[] = $row['month_label'];
    $monthly_values[] = (int)$row['total_count'];
}

$status_sql = "
    SELECT c.status, COUNT(*) AS total_count
    FROM complaints c
    $where_sql
    GROUP BY c.status
    ORDER BY total_count DESC
";

$status_result = run_prepared_query(
    $conn,
    $status_sql,
    $types,
    $params
);

$status_labels = [];
$status_values = [];

while ($row = mysqli_fetch_assoc($status_result)) {
    $status_labels[] = $row['status'] ?: 'Unknown';
    $status_values[] = (int)$row['total_count'];
}

$top_pending_sql = "
    SELECT
        c.id,
        c.complaint_id,
        c.complaint_date,
        c.customer_name,
        c.status,
        c.priority,
        DATEDIFF(CURDATE(), c.complaint_date) AS pending_days,
        COALESCE(j.job_name, 'No Job') AS job_name,
        COALESCE(v.vendor_name, 'No Vendor') AS vendor_name
    FROM complaints c
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    $where_sql
    " . ($where_sql ? " AND " : " WHERE ") . "
        c.status IN ('Open', 'In Progress')
    ORDER BY pending_days DESC, c.id DESC
    LIMIT 10
";

$top_pending = run_prepared_query(
    $conn,
    $top_pending_sql,
    $types,
    $params
);

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename=gsn-reports-' . date('Y-m-d') . '.xls');

    echo "Complaint ID\tComplaint Date\tJob\tVendor\tCustomer\tMobile\tStatus\tPriority\tClosing Date\tPending Days\n";

    $export_sql = "
        SELECT
            c.complaint_id,
            c.complaint_date,
            COALESCE(j.job_name, 'No Job') AS job_name,
            COALESCE(v.vendor_name, 'No Vendor') AS vendor_name,
            c.customer_name,
            c.mobile,
            c.status,
            c.priority,
            c.closing_date,
            CASE
                WHEN c.status IN ('Closed', 'Resolved') AND c.closing_date IS NOT NULL
                THEN DATEDIFF(c.closing_date, c.complaint_date)
                ELSE DATEDIFF(CURDATE(), c.complaint_date)
            END AS pending_days
        FROM complaints c
        LEFT JOIN jobs j ON j.id = c.job_id
        LEFT JOIN vendors v ON v.id = c.vendor_id
        $where_sql
        ORDER BY c.id DESC
    ";

    $export_result = run_prepared_query(
        $conn,
        $export_sql,
        $types,
        $params
    );

    while ($row = mysqli_fetch_assoc($export_result)) {
        $values = [
            $row['complaint_id'],
            $row['complaint_date'],
            $row['job_name'],
            $row['vendor_name'],
            $row['customer_name'],
            $row['mobile'],
            $row['status'],
            $row['priority'],
            $row['closing_date'],
            $row['pending_days'],
        ];

        $values = array_map(
            static fn($value) => str_replace(["\t", "\r", "\n"], ' ', (string)$value),
            $values
        );

        echo implode("\t", $values) . "\n";
    }

    exit;
}

$query_params = [
    'from_date' => $from_date,
    'to_date' => $to_date,
    'job_id' => $job_id ?: '',
    'vendor_id' => $vendor_id ?: '',
    'status' => $status,
];

$export_url = 'reports.php?' . http_build_query(
    array_filter(
        array_merge($query_params, ['export' => 'excel']),
        static fn($value) => $value !== ''
    )
);

$pageTitle = 'Reports';
$pageHeading = 'Reports';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .report-hero {
        background: linear-gradient(135deg, #0b1f44 0%, #1769ff 100%);
        border-radius: 24px;
        color: #fff;
        padding: 28px;
        overflow: hidden;
        position: relative;
    }

    .report-hero::after {
        content: "";
        width: 240px;
        height: 240px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .08);
        position: absolute;
        right: -70px;
        top: -100px;
    }

    .report-card {
        border: 1px solid #e5eaf3;
        border-radius: 20px;
        box-shadow: 0 12px 32px rgba(25, 46, 89, .08);
        background: #fff;
    }

    .metric-box {
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        padding: 20px;
        background: #fff;
        height: 100%;
        transition: .2s ease;
    }

    .metric-box:hover {
        transform: translateY(-3px);
        box-shadow: 0 14px 30px rgba(15, 23, 42, .10);
    }

    .metric-icon {
        width: 46px;
        height: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 14px;
        background: #eef4ff;
        color: #1769ff;
        font-size: 1.35rem;
    }

    .metric-label {
        color: #64748b;
        font-size: .78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .metric-value {
        font-size: 2rem;
        line-height: 1;
        font-weight: 800;
        color: #0f172a;
    }

    .section-title {
        font-weight: 800;
        color: #0f172a;
    }

    .table thead th {
        white-space: nowrap;
        font-size: .78rem;
        text-transform: uppercase;
        color: #475569;
        background: #f8fafc;
    }

    .table td {
        vertical-align: middle;
    }

    .chart-wrap {
        min-height: 330px;
        position: relative;
    }

    .filter-card .form-label {
        font-size: .78rem;
        font-weight: 800;
        color: #334155;
    }

    @media print {
        .no-print,
        .sidebar,
        .topbar {
            display: none !important;
        }

        .main-content {
            margin-left: 0 !important;
        }

        .report-card,
        .metric-box {
            box-shadow: none !important;
            break-inside: avoid;
        }

        body {
            background: #fff !important;
        }
    }
</style>

<div class="page-content">
    <div class="container-fluid px-3 px-lg-4 py-4">

        <section class="report-hero mb-4">
            <div class="position-relative" style="z-index:2">
                <div class="text-uppercase small fw-bold opacity-75 mb-2">
                    GRAND SPEED NETWORK
                </div>

                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3">
                    <div>
                        <h1 class="display-6 fw-bold mb-2">Reports & Analytics</h1>
                        <p class="mb-0 opacity-75">
                            Complaint performance, workload and closing analysis.
                        </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2 no-print">
                        <a href="<?php echo e($export_url); ?>" class="btn btn-success">
                            <i class="bi bi-file-earmark-excel me-1"></i> Excel
                        </a>

                        <button type="button" class="btn btn-light" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> Print / PDF
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="report-card filter-card mb-4 no-print">
            <div class="card-body p-4">
                <form method="GET" class="row g-3 align-items-end">

                    <div class="col-12 col-sm-6 col-xl-2">
                        <label class="form-label">From Date</label>
                        <input
                            type="date"
                            name="from_date"
                            class="form-control"
                            value="<?php echo e($from_date); ?>"
                        >
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2">
                        <label class="form-label">To Date</label>
                        <input
                            type="date"
                            name="to_date"
                            class="form-control"
                            value="<?php echo e($to_date); ?>"
                        >
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2">
                        <label class="form-label">Job</label>
                        <select name="job_id" class="form-select">
                            <option value="">All Jobs</option>

                            <?php foreach ($jobs as $job): ?>
                                <option
                                    value="<?php echo (int)$job['id']; ?>"
                                    <?php echo $job_id === (int)$job['id'] ? 'selected' : ''; ?>
                                >
                                    <?php echo e($job['job_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2">
                        <label class="form-label">Vendor</label>
                        <select name="vendor_id" class="form-select">
                            <option value="">All Vendors</option>

                            <?php foreach ($vendors as $vendor): ?>
                                <option
                                    value="<?php echo (int)$vendor['id']; ?>"
                                    <?php echo $vendor_id === (int)$vendor['id'] ? 'selected' : ''; ?>
                                >
                                    <?php echo e($vendor['vendor_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Status</option>

                            <?php foreach (['Open', 'In Progress', 'Resolved', 'Closed'] as $status_option): ?>
                                <option
                                    value="<?php echo e($status_option); ?>"
                                    <?php echo $status === $status_option ? 'selected' : ''; ?>
                                >
                                    <?php echo e($status_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-2 d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel me-1"></i> Generate
                        </button>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <a href="reports.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                        </a>
                    </div>

                </form>
            </div>
        </section>

        <section class="row g-3 mb-4">

            <?php
            $metric_items = [
                ['label' => 'Total Complaints', 'value' => $total_count, 'icon' => 'bi-clipboard-data'],
                ['label' => 'Open', 'value' => $open_count, 'icon' => 'bi-folder2-open'],
                ['label' => 'Closed', 'value' => $closed_count, 'icon' => 'bi-check-circle'],
                ['label' => 'Most Urgent', 'value' => $urgent_count, 'icon' => 'bi-exclamation-triangle'],
                ['label' => 'Old Complaints', 'value' => $old_count, 'icon' => 'bi-hourglass-split'],
                ['label' => 'Avg. Closing Days', 'value' => $avg_closing_days, 'icon' => 'bi-speedometer2'],
            ];
            ?>

            <?php foreach ($metric_items as $item): ?>
                <div class="col-6 col-lg-4 col-xxl-2">
                    <div class="metric-box">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="metric-label mb-2">
                                    <?php echo e($item['label']); ?>
                                </div>

                                <div class="metric-value">
                                    <?php echo e($item['value']); ?>
                                </div>
                            </div>

                            <span class="metric-icon">
                                <i class="bi <?php echo e($item['icon']); ?>"></i>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        </section>

        <section class="row g-4 mb-4">
            <div class="col-xl-8">
                <div class="report-card h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 section-title mb-1">Monthly Complaints</h2>
                        <p class="text-muted small mb-4">Complaint volume by month</p>

                        <div class="chart-wrap">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="report-card h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 section-title mb-1">Status Distribution</h2>
                        <p class="text-muted small mb-4">Current filtered status mix</p>

                        <div class="chart-wrap">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="report-card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 section-title mb-1">Job Wise Report</h2>
                <p class="text-muted small mb-3">Complaint performance by job</p>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Total</th>
                                <th>Open</th>
                                <th>Closed</th>
                                <th>Pending</th>
                                <th>Most Urgent</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($job_report && mysqli_num_rows($job_report) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($job_report)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['job_name']); ?></td>
                                        <td><?php echo (int)$row['total_count']; ?></td>
                                        <td><?php echo (int)$row['open_count']; ?></td>
                                        <td><?php echo (int)$row['closed_count']; ?></td>
                                        <td><?php echo (int)$row['pending_count']; ?></td>
                                        <td class="text-danger fw-bold"><?php echo (int)$row['urgent_count']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No job data found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="row g-4 mb-4">
            <div class="col-xl-6">
                <div class="report-card h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 section-title mb-1">Vendor Wise Report</h2>
                        <p class="text-muted small mb-3">Vendor workload and closing time</p>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Vendor</th>
                                        <th>Total</th>
                                        <th>Open</th>
                                        <th>Closed</th>
                                        <th>Pending</th>
                                        <th>Avg. Days</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($vendor_report && mysqli_num_rows($vendor_report) > 0): ?>
                                        <?php while ($row = mysqli_fetch_assoc($vendor_report)): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo e($row['vendor_name']); ?></td>
                                                <td><?php echo (int)$row['total_count']; ?></td>
                                                <td><?php echo (int)$row['open_count']; ?></td>
                                                <td><?php echo (int)$row['closed_count']; ?></td>
                                                <td><?php echo (int)$row['pending_count']; ?></td>
                                                <td><?php echo e($row['avg_closing_days'] ?? 0); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                No vendor data found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="report-card h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 section-title mb-1">Agent Performance</h2>
                        <p class="text-muted small mb-3">Assigned workload by agent</p>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Assigned Jobs</th>
                                        <th>Total</th>
                                        <th>Closed</th>
                                        <th>Pending</th>
                                        <th>Closed %</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if ($agent_report && mysqli_num_rows($agent_report) > 0): ?>
                                        <?php while ($row = mysqli_fetch_assoc($agent_report)): ?>
                                            <?php
                                            $agent_total = (int)$row['total_count'];
                                            $agent_closed = (int)$row['closed_count'];
                                            $closed_percent = $agent_total > 0
                                                ? round(($agent_closed / $agent_total) * 100)
                                                : 0;
                                            ?>

                                            <tr>
                                                <td class="fw-semibold"><?php echo e($row['agent_name']); ?></td>
                                                <td style="min-width:180px"><?php echo e($row['assigned_jobs'] ?: 'No jobs'); ?></td>
                                                <td><?php echo $agent_total; ?></td>
                                                <td><?php echo $agent_closed; ?></td>
                                                <td><?php echo (int)$row['pending_count']; ?></td>
                                                <td>
                                                    <span class="badge text-bg-primary">
                                                        <?php echo $closed_percent; ?>%
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                No agent data found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="report-card mb-4">
            <div class="card-body p-4">
                <h2 class="h5 section-title mb-1">Top 10 Pending Complaints</h2>
                <p class="text-muted small mb-3">Oldest open or in-progress complaints</p>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Complaint</th>
                                <th>Date</th>
                                <th>Job</th>
                                <th>Vendor</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Pending</th>
                                <th class="no-print">View</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($top_pending && mysqli_num_rows($top_pending) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($top_pending)): ?>
                                    <tr>
                                        <td class="fw-bold text-primary">
                                            <?php echo e($row['complaint_id']); ?>
                                        </td>
                                        <td><?php echo e($row['complaint_date']); ?></td>
                                        <td><?php echo e($row['job_name']); ?></td>
                                        <td><?php echo e($row['vendor_name']); ?></td>
                                        <td><?php echo e($row['customer_name']); ?></td>
                                        <td>
                                            <span class="badge text-bg-warning">
                                                <?php echo e($row['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo e($row['priority']); ?></td>
                                        <td class="fw-bold text-danger">
                                            <?php echo (int)$row['pending_days']; ?> Days
                                        </td>
                                        <td class="no-print">
                                            <a
                                                href="complaint-details.php?id=<?php echo (int)$row['id']; ?>"
                                                class="btn btn-sm btn-outline-primary"
                                            >
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        No pending complaints found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const monthlyCanvas = document.getElementById('monthlyChart');

    if (monthlyCanvas) {
        new Chart(monthlyCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($monthly_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    label: 'Complaints',
                    data: <?php echo json_encode($monthly_values); ?>,
                    borderWidth: 3,
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    const statusCanvas = document.getElementById('statusChart');

    if (statusCanvas) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_values); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
</script>

<?php include 'includes/footer.php'; ?>
