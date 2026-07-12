<?php
session_start();
require_once 'db.php';

// Agent-only access. This does not affect the existing admin login/session.
if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agentId = (int)$_SESSION['agent_id'];
$agentName = $_SESSION['agent_name'] ?? 'Agent';

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function badge_class($status)
{
    switch ($status) {
        case 'Open':
            return 'text-bg-primary';
        case 'In Progress':
            return 'text-bg-warning';
        case 'Resolved':
            return 'text-bg-success';
        case 'Closed':
            return 'text-bg-secondary';
        default:
            return 'text-bg-light';
    }
}

function priority_class($priority)
{
    switch ($priority) {
        case 'Most Urgent':
            return 'text-bg-danger';
        case 'Urgent':
            return 'text-bg-warning';
        default:
            return 'text-bg-secondary';
    }
}

$metricsSql = "
    SELECT
        COUNT(c.id) AS total_complaints,
        SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN c.status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count,
        SUM(CASE WHEN DATE(c.complaint_date) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
        SUM(CASE WHEN YEAR(c.complaint_date) = YEAR(CURDATE()) AND MONTH(c.complaint_date) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_count
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    WHERE aj.agent_id = $agentId
";
$metricsResult = mysqli_query($conn, $metricsSql);
$metrics = $metricsResult ? mysqli_fetch_assoc($metricsResult) : [];

$totalComplaints = (int)($metrics['total_complaints'] ?? 0);
$pendingCount = (int)($metrics['pending_count'] ?? 0);
$openCount = (int)($metrics['open_count'] ?? 0);
$inProgressCount = (int)($metrics['in_progress_count'] ?? 0);
$resolvedCount = (int)($metrics['resolved_count'] ?? 0);
$closedCount = (int)($metrics['closed_count'] ?? 0);
$mostUrgentCount = (int)($metrics['most_urgent_count'] ?? 0);
$todayCount = (int)($metrics['today_count'] ?? 0);
$monthCount = (int)($metrics['month_count'] ?? 0);

$mostUrgentSql = "
    SELECT c.id, c.complaint_id, c.customer_name, c.status, c.complaint_date,
           j.job_name, v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE aj.agent_id = $agentId
      AND c.priority = 'Most Urgent'
    ORDER BY c.id DESC
    LIMIT 10
";
$mostUrgentResult = mysqli_query($conn, $mostUrgentSql);

$recentSql = "
    SELECT c.id, c.complaint_id, c.customer_name, c.priority, c.status,
           v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE aj.agent_id = $agentId
    ORDER BY c.id DESC
    LIMIT 10
";
$recentResult = mysqli_query($conn, $recentSql);

$jobSummarySql = "
    SELECT j.id, j.job_name,
           COUNT(c.id) AS total_count,
           SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count,
           SUM(CASE WHEN c.status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS closed_count
    FROM agent_jobs aj
    INNER JOIN jobs j ON j.id = aj.job_id
    LEFT JOIN complaints c ON c.job_id = j.id
    WHERE aj.agent_id = $agentId
    GROUP BY j.id, j.job_name
    ORDER BY j.job_name ASC
";
$jobSummaryResult = mysqli_query($conn, $jobSummarySql);

$vendorSummarySql = "
    SELECT COALESCE(v.vendor_name, 'No Vendor') AS vendor_name,
           SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
           SUM(CASE WHEN c.status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS closed_count,
           SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE aj.agent_id = $agentId
    GROUP BY c.vendor_id, v.vendor_name
    ORDER BY vendor_name ASC
";
$vendorSummaryResult = mysqli_query($conn, $vendorSummarySql);

$openPercent = $totalComplaints > 0 ? round(($openCount / $totalComplaints) * 100) : 0;
$inProgressPercent = $totalComplaints > 0 ? round(($inProgressCount / $totalComplaints) * 100) : 0;
$resolvedPercent = $totalComplaints > 0 ? round(($resolvedCount / $totalComplaints) * 100) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Dashboard - GRAND SPEED NETWORK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f4f7fb;
            color: #162033;
        }
        .navbar {
            box-shadow: 0 10px 28px rgba(15, 23, 42, .12);
        }
        .brand-box {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            color: #0d6efd;
            font-weight: 800;
        }
        .dashboard-card {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }
        .metric-card {
            min-height: 118px;
            border-left: 5px solid #0d6efd;
        }
        .metric-label {
            color: #64748b;
            font-size: .88rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .quick-action {
            border-radius: 10px;
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            text-decoration: none;
        }
        .urgent-card {
            border-top: 5px solid #dc3545;
        }
        .table th {
            white-space: nowrap;
            color: #475569;
            font-size: .86rem;
            text-transform: uppercase;
        }
        .progress {
            height: 10px;
            border-radius: 999px;
            background: #e2e8f0;
        }
        .progress-bar {
            border-radius: 999px;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="agent-dashboard.php">
            <span class="brand-box">GSN</span>
            <span>GRAND SPEED NETWORK</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-3">
            <div class="text-white d-none d-sm-block">
                <span class="opacity-75">Welcome,</span>
                <strong><?php echo e($agentName); ?></strong>
            </div>
            <a href="agent-logout.php" class="btn btn-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Agent Dashboard</h1>
            <p class="text-muted mb-0">Courier complaint overview for your assigned jobs.</p>
        </div>
        <div class="text-muted small"><?php echo date('d M Y'); ?></div>
    </div>

    <section class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl">
            <a class="quick-action btn btn-primary w-100" href="agent-add-complaint.php">Add Complaint</a>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <a class="quick-action btn btn-outline-primary w-100" href="agent-complaints.php">My Complaints</a>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <a class="quick-action btn btn-outline-primary w-100" href="agent-import.php">Import CSV</a>
        </div>
        <div class="col-6 col-md-4 col-xl">
            <button class="quick-action btn btn-outline-secondary w-100" type="button" disabled>Profile Coming Soon</button>
        </div>
        <div class="col-12 col-md-4 col-xl">
            <a class="quick-action btn btn-outline-danger w-100" href="agent-logout.php">Logout</a>
        </div>
    </section>

    <section class="row g-3 mb-4">

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Total Complaints</div>
                    <div class="display-6 fw-bold"><?php echo $totalComplaints; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?status=Pending" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Pending</div>
                    <div class="display-6 fw-bold"><?php echo $pendingCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?status=Open" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Open</div>
                    <div class="display-6 fw-bold"><?php echo $openCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?status=In Progress" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">In Progress</div>
                    <div class="display-6 fw-bold"><?php echo $inProgressCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?status=Resolved" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Resolved</div>
                    <div class="display-6 fw-bold"><?php echo $resolvedCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?status=Closed" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Closed</div>
                    <div class="display-6 fw-bold"><?php echo $closedCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?priority=Most Urgent" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Most Urgent</div>
                    <div class="display-6 fw-bold text-danger"><?php echo $mostUrgentCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?today=1" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Today's Complaints</div>
                    <div class="display-6 fw-bold"><?php echo $todayCount; ?></div>
                </div>
            </div>
        </a>
    </div>

    <div class="col-12 col-lg-4 col-xxl-2">
        <a href="agent-complaints.php?month=1" class="text-decoration-none text-dark">
            <div class="card dashboard-card metric-card clickable-card">
                <div class="card-body">
                    <div class="metric-label">Current Month</div>
                    <div class="display-6 fw-bold"><?php echo $monthCount; ?></div>
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
                            <thead>
                            <tr>
                                <th>Complaint ID</th>
                                <th>Customer</th>
                                <th>Vendor</th>
                                <th>Job</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>View</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($mostUrgentResult && mysqli_num_rows($mostUrgentResult) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($mostUrgentResult)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['complaint_id'] ?: $row['id']); ?></td>
                                        <td><?php echo e($row['customer_name']); ?></td>
                                        <td><?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></td>
                                        <td><?php echo e($row['job_name']); ?></td>
                                        <td><span class="badge <?php echo badge_class($row['status']); ?>"><?php echo e($row['status']); ?></span></td>
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
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>Open</span><strong><?php echo $openPercent; ?>%</strong></div>
                        <div class="progress"><div class="progress-bar bg-primary" style="width: <?php echo $openPercent; ?>%"></div></div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>In Progress</span><strong><?php echo $inProgressPercent; ?>%</strong></div>
                        <div class="progress"><div class="progress-bar bg-warning" style="width: <?php echo $inProgressPercent; ?>%"></div></div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between mb-1"><span>Resolved / Closed</span><strong><?php echo $resolvedPercent; ?>%</strong></div>
                        <div class="progress"><div class="progress-bar bg-success" style="width: <?php echo $resolvedPercent; ?>%"></div></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-4">
        <div class="col-xl-6">
            <div class="card dashboard-card">
                <div class="card-header bg-white fw-bold">Recent Complaints</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Complaint ID</th>
                                <th>Customer</th>
                                <th>Vendor</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>View</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($recentResult && mysqli_num_rows($recentResult) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($recentResult)): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['complaint_id'] ?: $row['id']); ?></td>
                                        <td><?php echo e($row['customer_name']); ?></td>
                                        <td><?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></td>
                                        <td><span class="badge <?php echo priority_class($row['priority']); ?>"><?php echo e($row['priority']); ?></span></td>
                                        <td><span class="badge <?php echo badge_class($row['status']); ?>"><?php echo e($row['status']); ?></span></td>
                                        <td><a class="btn btn-sm btn-outline-primary" href="complaint-details.php?id=<?php echo (int)$row['id']; ?>">View</a></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No recent complaints found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3">
            <div class="card dashboard-card h-100">
                <div class="card-header bg-white fw-bold">Job Summary</div>
                <div class="card-body">
                    <?php if ($jobSummaryResult && mysqli_num_rows($jobSummaryResult) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($jobSummaryResult)): ?>
                            <div class="border-bottom pb-3 mb-3">
                                <div class="fw-semibold mb-2"><?php echo e($row['job_name']); ?></div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge text-bg-primary">Total <?php echo (int)$row['total_count']; ?></span>
                                    <span class="badge text-bg-warning">Pending <?php echo (int)$row['pending_count']; ?></span>
                                    <span class="badge text-bg-success">Closed <?php echo (int)$row['closed_count']; ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-muted">No assigned jobs found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-3">
            <div class="card dashboard-card h-100">
                <div class="card-header bg-white fw-bold">Vendor Summary</div>
                <div class="card-body">
                    <?php if ($vendorSummaryResult && mysqli_num_rows($vendorSummaryResult) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($vendorSummaryResult)): ?>
                            <div class="border-bottom pb-3 mb-3">
                                <div class="fw-semibold mb-2"><?php echo e($row['vendor_name']); ?></div>
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge text-bg-primary">Open <?php echo (int)$row['open_count']; ?></span>
                                    <span class="badge text-bg-secondary">Closed <?php echo (int)$row['closed_count']; ?></span>
                                    <span class="badge text-bg-warning">Pending <?php echo (int)$row['pending_count']; ?></span>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-muted">No vendor complaints found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
