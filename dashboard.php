<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

$admin_name = is_string($_SESSION['admin']) ? $_SESSION['admin'] : 'Admin';

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function count_value($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    $row = $result ? mysqli_fetch_row($result) : [0];
    return (int)($row[0] ?? 0);
}

function status_badge_class($status)
{
    if ($status === 'Open') {
        return 'text-bg-primary';
    }
    if ($status === 'In Progress') {
        return 'text-bg-warning';
    }
    if ($status === 'Resolved') {
        return 'text-bg-success';
    }
    if ($status === 'Closed') {
        return 'text-bg-secondary';
    }
    return 'text-bg-light';
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

$metrics_sql = "
    SELECT
        COUNT(*) AS total_complaints,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count,
        SUM(CASE WHEN DATE(complaint_date) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
        SUM(CASE WHEN YEAR(complaint_date) = YEAR(CURDATE()) AND MONTH(complaint_date) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_count
    FROM complaints
";
$metrics_result = mysqli_query($conn, $metrics_sql);
$metrics = $metrics_result ? mysqli_fetch_assoc($metrics_result) : [];

$total_complaints = (int)($metrics['total_complaints'] ?? 0);
$open_count = (int)($metrics['open_count'] ?? 0);
$in_progress_count = (int)($metrics['in_progress_count'] ?? 0);
$resolved_count = (int)($metrics['resolved_count'] ?? 0);
$closed_count = (int)($metrics['closed_count'] ?? 0);
$most_urgent_count = (int)($metrics['most_urgent_count'] ?? 0);
$today_count = (int)($metrics['today_count'] ?? 0);
$month_count = (int)($metrics['month_count'] ?? 0);
$total_jobs = count_value($conn, "SELECT COUNT(*) FROM jobs");
$total_vendors = count_value($conn, "SELECT COUNT(*) FROM vendors");
$total_agents = count_value($conn, "SELECT COUNT(*) FROM agents");

$old_complaints_result = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM complaints
    WHERE status IN ('Open', 'In Progress')
      AND DATEDIFF(CURDATE(), complaint_date) >= 4
");

$old_complaints_count = 0;

if ($old_complaints_result) {
    $old_row = mysqli_fetch_assoc($old_complaints_result);
    $old_complaints_count = (int)($old_row['total'] ?? 0);
}

$job_summary = mysqli_query($conn, "
    SELECT j.job_name,
           COUNT(c.id) AS total_count,
           SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
           SUM(CASE WHEN c.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
           SUM(CASE WHEN c.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
           SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
           SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count
    FROM jobs j
    LEFT JOIN complaints c ON c.job_id = j.id
    GROUP BY j.id, j.job_name
    ORDER BY j.job_name ASC
");

$vendor_summary = mysqli_query($conn, "
    SELECT COALESCE(v.vendor_name, 'No Vendor') AS vendor_name,
           COUNT(c.id) AS total_count,
           SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
           SUM(CASE WHEN c.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
           SUM(CASE WHEN c.status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
           SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
           SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count
    FROM complaints c
    LEFT JOIN vendors v ON v.id = c.vendor_id
    GROUP BY c.vendor_id, v.vendor_name
    ORDER BY vendor_name ASC
");

$agent_summary = mysqli_query($conn, "
    SELECT a.agent_name,
           GROUP_CONCAT(DISTINCT j.job_name ORDER BY j.job_name SEPARATOR ', ') AS assigned_jobs,
           COUNT(DISTINCT c.id) AS total_count,
           SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
           SUM(CASE WHEN c.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
           SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count
    FROM agents a
    LEFT JOIN agent_jobs aj ON aj.agent_id = a.id
    LEFT JOIN jobs j ON j.id = aj.job_id
    LEFT JOIN complaints c ON c.job_id = aj.job_id
    GROUP BY a.id, a.agent_name
    ORDER BY a.agent_name ASC
");

$recent_complaints = mysqli_query($conn, "
    SELECT c.id, c.complaint_id, c.customer_name, c.priority, c.status, c.complaint_date,
           j.job_name, v.vendor_name
    FROM complaints c
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    ORDER BY c.id DESC
    LIMIT 10
");

$urgent_complaints = mysqli_query($conn, "
    SELECT c.id, c.complaint_id, c.customer_name, c.status, c.complaint_date,
           j.job_name, v.vendor_name
    FROM complaints c
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE c.priority = 'Most Urgent'
    ORDER BY c.id DESC
    LIMIT 10
");

$open_percent = $total_complaints > 0 ? round(($open_count / $total_complaints) * 100) : 0;
$progress_percent = $total_complaints > 0 ? round(($in_progress_count / $total_complaints) * 100) : 0;
$resolved_percent = $total_complaints > 0 ? round((($resolved_count + $closed_count) / $total_complaints) * 100) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - GRAND SPEED NETWORK</title>
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
        .dashboard-card {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }
        .metric-card {
            min-height: 116px;
            border-left: 5px solid #0d6efd;
        }

        .clickable-card {
            cursor: pointer;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .clickable-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 35px rgba(15, 23, 42, .16);
        }
        .metric-label {
            color: #64748b;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .quick-action {
            min-height: 72px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            text-decoration: none;
        }
        .table th {
            white-space: nowrap;
            color: #475569;
            font-size: .86rem;
            text-transform: uppercase;
        }
        .table td { vertical-align: middle; }
        .progress {
            height: 10px;
            border-radius: 999px;
            background: #e2e8f0;
        }
        .progress-bar { border-radius: 999px; }
        .urgent-card { border-top: 5px solid #dc3545; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="dashboard.php">
            <span class="brand-box">GSN</span>
            <span>GRAND SPEED NETWORK</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <div class="text-white d-none d-sm-block">
                <span class="opacity-75">Welcome,</span>
                <strong><?php echo e($admin_name); ?></strong>
            </div>
            <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Admin Dashboard</h1>
            <p class="text-muted mb-0">Courier CRM overview for complaints, jobs, vendors, and agents.</p>
        </div>
        <div class="text-muted small"><?php echo date('d M Y'); ?></div>
    </div>

<section class="mb-4">
    <div class="card dashboard-card">
        <div class="card-body">
            <form action="view-complaints.php" method="GET" class="row g-2">

                <div class="col-md-10">
                    <input
                        type="text"
                        name="search"
                        class="form-control form-control-lg"
                        placeholder="Search Complaint ID, Tracking Number, Customer Name, Mobile"
                    >
                </div>

                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary btn-lg">
                        🔍 Search
                    </button>
                </div>

            </form>
        </div>
    </div>
</section>

<section class="row g-3 mb-4">

    <div class="col-6 col-md-4 col-xl">
        <a class="quick-action btn btn-primary w-100" href="add-complaint.php">
            Add Complaint
        </a>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <a class="quick-action btn btn-outline-primary w-100" href="view-complaints.php">
            View Complaints
        </a>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <a class="quick-action btn btn-outline-primary w-100" href="jobs.php">
            Jobs
        </a>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <a class="quick-action btn btn-outline-primary w-100" href="vendors.php">
            Vendors
        </a>
    </div>

    <div class="col-6 col-md-4 col-xl">
        <a class="quick-action btn btn-outline-primary w-100" href="agents.php">
            Agents
        </a>
    </div>

    <div class="col-12 col-md-4 col-xl">
        <a class="quick-action btn btn-outline-danger w-100" href="logout.php">
            Logout
        </a>
    </div>

</section>

<section class="row g-3 mb-4">

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Total Complaints</div>
                    <div class="display-6 fw-bold"><?php echo $total_complaints; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?status=Open" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Open</div>
                    <div class="display-6 fw-bold"><?php echo $open_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?status=In+Progress" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">In Progress</div>
                    <div class="display-6 fw-bold"><?php echo $in_progress_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?status=Resolved" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Resolved</div>
                    <div class="display-6 fw-bold"><?php echo $resolved_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?status=Closed" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Closed</div>
                    <div class="display-6 fw-bold"><?php echo $closed_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?priority=Most+Urgent" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Most Urgent</div>
                    <div class="display-6 fw-bold text-danger"><?php echo $most_urgent_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
    <a
        href="view-complaints.php?old=1"
        class="text-decoration-none text-dark"
    >
        <div class="card dashboard-card metric-card clickable-card">
            <div class="card-body">

                <div class="metric-label">
                    Old Complaints
                </div>

                <div class="display-6 fw-bold text-danger">
                    <?php echo $old_complaints_count; ?>
                </div>

                <small class="text-muted">
                    Pending 4+ Days
                </small>

            </div>
        </div>
    </a>
</div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?today=1" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Today Complaints</div>
                    <div class="display-6 fw-bold"><?php echo $today_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?month=current" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Current Month</div>
                    <div class="display-6 fw-bold"><?php echo $month_count; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="jobs.php" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Total Jobs</div>
                    <div class="display-6 fw-bold"><?php echo $total_jobs; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="vendors.php" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Total Vendors</div>
                    <div class="display-6 fw-bold"><?php echo $total_vendors; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agents.php" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Total Agents</div>
                    <div class="display-6 fw-bold"><?php echo $total_agents; ?></div>
                </div>
            </div>
        </a>
    </div>

</section>

    <section class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card dashboard-card urgent-card">
                <div class="card-header bg-danger text-white fw-bold">Most Urgent Complaints</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Complaint ID</th><th>Job</th><th>Vendor</th><th>Customer</th><th>Status</th><th>Date</th><th>View</th></tr></thead>
                            <tbody>
                            <?php if ($urgent_complaints && mysqli_num_rows($urgent_complaints) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($urgent_complaints)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['complaint_id'] ?: $row['id']); ?></td>
                                        <td><?php echo e($row['job_name']); ?></td>
                                        <td><?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></td>
                                        <td><?php echo e($row['customer_name']); ?></td>
                                        <td><span class="badge <?php echo status_badge_class($row['status']); ?>"><?php echo e($row['status']); ?></span></td>
                                        <td><?php echo e($row['complaint_date']); ?></td>
                                        <td><a class="btn btn-sm btn-outline-danger" href="complaint-details.php?id=<?php echo (int)$row['id']; ?>">View</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No most urgent complaints found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <h2 class="h5 fw-bold mb-3">Status Progress</h2>
                    <div class="mb-3"><div class="d-flex justify-content-between mb-1"><span>Open</span><strong><?php echo $open_percent; ?>%</strong></div><div class="progress"><div class="progress-bar bg-primary" style="width: <?php echo $open_percent; ?>%"></div></div></div>
                    <div class="mb-3"><div class="d-flex justify-content-between mb-1"><span>In Progress</span><strong><?php echo $progress_percent; ?>%</strong></div><div class="progress"><div class="progress-bar bg-warning" style="width: <?php echo $progress_percent; ?>%"></div></div></div>
                    <div><div class="d-flex justify-content-between mb-1"><span>Resolved / Closed</span><strong><?php echo $resolved_percent; ?>%</strong></div><div class="progress"><div class="progress-bar bg-success" style="width: <?php echo $resolved_percent; ?>%"></div></div></div>
                </div>
            </div>
        </div>
    </section>

<section class="row g-4 mb-4">

<div class="col-xl-12">

    <div class="card dashboard-card">

        <div class="card-header bg-white fw-bold">
            <a href="agents.php" class="text-decoration-none text-dark d-block">
                Agent Wise Summary
            </a>
        </div>

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Agent Name</th><th>Assigned Jobs</th><th>Total</th><th>Open</th><th>In Progress</th><th>Closed</th></tr></thead>
                            <tbody>
                            <?php if ($agent_summary && mysqli_num_rows($agent_summary) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($agent_summary)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['agent_name']); ?></td>
                                        <td><?php echo e($row['assigned_jobs'] ?: 'No jobs assigned'); ?></td>
                                        <td><?php echo (int)$row['total_count']; ?></td>
                                        <td><?php echo (int)$row['open_count']; ?></td>
                                        <td><?php echo (int)$row['in_progress_count']; ?></td>
                                        <td><?php echo (int)$row['closed_count']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No agents found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-4">
        <div class="col-xl-6">
        <div class="card dashboard-card">
        <div class="card-header bg-white fw-bold">
        <a href="jobs.php" class="text-decoration-none text-dark">
            Job Wise Summary
        </a>
        </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Job Name</th><th>Total</th><th>Open</th><th>In Progress</th><th>Resolved</th><th>Closed</th><th>Most Urgent</th></tr></thead>
                            <tbody>
                            <?php if ($job_summary && mysqli_num_rows($job_summary) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($job_summary)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['job_name']); ?></td>
                                        <td><?php echo (int)$row['total_count']; ?></td>
                                        <td><?php echo (int)$row['open_count']; ?></td>
                                        <td><?php echo (int)$row['in_progress_count']; ?></td>
                                        <td><?php echo (int)$row['resolved_count']; ?></td>
                                        <td><?php echo (int)$row['closed_count']; ?></td>
                                        <td class="text-danger fw-semibold"><?php echo (int)$row['most_urgent_count']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No jobs found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
        <div class="card dashboard-card">
        <div class="card-header bg-white fw-bold">
        <a href="vendors.php" class="text-decoration-none text-dark">
            Vendor Wise Summary
        </a>
        </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Vendor Name</th><th>Total</th><th>Open</th><th>In Progress</th><th>Resolved</th><th>Closed</th><th>Most Urgent</th></tr></thead>
                            <tbody>
                            <?php if ($vendor_summary && mysqli_num_rows($vendor_summary) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($vendor_summary)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['vendor_name']); ?></td>
                                        <td><?php echo (int)$row['total_count']; ?></td>
                                        <td><?php echo (int)$row['open_count']; ?></td>
                                        <td><?php echo (int)$row['in_progress_count']; ?></td>
                                        <td><?php echo (int)$row['resolved_count']; ?></td>
                                        <td><?php echo (int)$row['closed_count']; ?></td>
                                        <td class="text-danger fw-semibold"><?php echo (int)$row['most_urgent_count']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No vendor complaint data found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
