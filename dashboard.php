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

$metrics_sql = "
    SELECT
        COUNT(*) AS total_complaints,
        SUM(CASE WHEN status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count,
        SUM(
            CASE
                WHEN YEAR(complaint_date) = YEAR(CURDATE())
                 AND MONTH(complaint_date) = MONTH(CURDATE())
                THEN 1 ELSE 0
            END
        ) AS month_count
    FROM complaints
";

$metrics_result = mysqli_query($conn, $metrics_sql);
$metrics = $metrics_result ? mysqli_fetch_assoc($metrics_result) : [];

$total_complaints = (int)($metrics['total_complaints'] ?? 0);
$open_count = (int)($metrics['open_count'] ?? 0);
$closed_count = (int)($metrics['closed_count'] ?? 0);
$most_urgent_count = (int)($metrics['most_urgent_count'] ?? 0);
$month_count = (int)($metrics['month_count'] ?? 0);

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

$agent_summary = mysqli_query($conn, "
    SELECT
        a.agent_name,
        GROUP_CONCAT(DISTINCT j.job_name ORDER BY j.job_name SEPARATOR ', ') AS assigned_jobs,
        COUNT(DISTINCT c.id) AS total_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count
    FROM agents a
    LEFT JOIN agent_jobs aj ON aj.agent_id = a.id
    LEFT JOIN jobs j ON j.id = aj.job_id
    LEFT JOIN complaints c ON c.job_id = aj.job_id
    GROUP BY a.id, a.agent_name
    ORDER BY a.agent_name ASC
");

$job_summary = mysqli_query($conn, "
    SELECT
        j.job_name,
        COUNT(c.id) AS total_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count
    FROM jobs j
    LEFT JOIN complaints c ON c.job_id = j.id
    GROUP BY j.id, j.job_name
    ORDER BY j.job_name ASC
");

$vendor_summary = mysqli_query($conn, "
    SELECT
        COALESCE(v.vendor_name, 'No Vendor') AS vendor_name,
        COUNT(c.id) AS total_count,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.priority = 'Most Urgent' THEN 1 ELSE 0 END) AS most_urgent_count
    FROM complaints c
    LEFT JOIN vendors v ON v.id = c.vendor_id
    GROUP BY c.vendor_id, v.vendor_name
    ORDER BY vendor_name ASC
");

$pageTitle = 'Dashboard - GSN';
$pageHeading = 'Dashboard';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="dashboard-welcome mb-4">
    <div>
        <p class="dashboard-eyebrow mb-1">GRAND SPEED NETWORK</p>
        <h2 class="mb-1">Welcome back, <?php echo e($admin_name); ?></h2>
        <p class="text-muted mb-0">Complaint management overview</p>
    </div>

    <div class="dashboard-date">
        <i class="bi bi-calendar3"></i>
        <?php echo date('d M Y'); ?>
    </div>
</div>

<div class="page-card mb-4">
    <form action="view-complaints.php" method="GET" class="row g-2 align-items-center">
        <div class="col-lg-10">
            <div class="input-group input-group-lg">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-primary"></i>
                </span>
                <input
                    type="text"
                    name="search"
                    class="form-control border-start-0"
                    placeholder="Search Complaint ID, Tracking Number, Customer Name or Mobile"
                >
            </div>
        </div>

        <div class="col-lg-2 d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
                Search
            </button>
        </div>
    </form>
</div>

<section class="row g-3 mb-4">
    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php" class="dashboard-stat stat-total">
            <div class="stat-icon"><i class="bi bi-clipboard-data"></i></div>
            <div>
                <span>Total Complaints</span>
                <strong><?php echo $total_complaints; ?></strong>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?status=Open" class="dashboard-stat stat-open">
            <div class="stat-icon"><i class="bi bi-folder2-open"></i></div>
            <div>
                <span>Open</span>
                <strong><?php echo $open_count; ?></strong>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?status=Closed" class="dashboard-stat stat-closed">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div>
                <span>Closed</span>
                <strong><?php echo $closed_count; ?></strong>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?priority=Most+Urgent" class="dashboard-stat stat-urgent">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <span>Most Urgent</span>
                <strong><?php echo $most_urgent_count; ?></strong>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?month=current" class="dashboard-stat stat-month">
            <div class="stat-icon"><i class="bi bi-calendar-month"></i></div>
            <div>
                <span>Current Month</span>
                <strong><?php echo $month_count; ?></strong>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-4 col-xxl-2">
        <a href="view-complaints.php?old=1" class="dashboard-stat stat-old">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <span>Old Complaints</span>
                <strong><?php echo $old_complaints_count; ?></strong>
                <small>Pending 4+ days</small>
            </div>
        </a>
    </div>
</section>

<div class="page-card mb-4">
    <div class="section-heading mb-3">
        <div>
            <h4 class="mb-1">Quick Actions</h4>
            <p class="text-muted mb-0">Open frequently used modules</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-6 col-md-4 col-xl-2">
            <a href="add-complaint.php" class="quick-tile">
                <i class="bi bi-plus-circle"></i>
                <span>Add Complaint</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="view-complaints.php" class="quick-tile">
                <i class="bi bi-list-check"></i>
                <span>Complaints</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="agents.php" class="quick-tile">
                <i class="bi bi-people"></i>
                <span>Agents</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="vendors.php" class="quick-tile">
                <i class="bi bi-building"></i>
                <span>Vendors</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="jobs.php" class="quick-tile">
                <i class="bi bi-briefcase"></i>
                <span>Jobs</span>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="reports.php" class="quick-tile">
                <i class="bi bi-bar-chart"></i>
                <span>Reports</span>
            </a>
        </div>
    </div>
</div>

<div class="page-card mb-4">
    <div class="section-heading mb-3">
        <div>
            <h4 class="mb-1">Agent Wise Summary</h4>
            <p class="text-muted mb-0">Complaint load by assigned agent</p>
        </div>
        <a href="agents.php" class="btn btn-outline-secondary btn-sm">View Agents</a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Agent Name</th>
                    <th>Assigned Jobs</th>
                    <th>Total</th>
                    <th>Open</th>
                    <th>Closed</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($agent_summary && mysqli_num_rows($agent_summary) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($agent_summary)): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($row['agent_name']); ?></td>
                            <td><?php echo e($row['assigned_jobs'] ?: 'No jobs assigned'); ?></td>
                            <td><?php echo (int)$row['total_count']; ?></td>
                            <td><?php echo (int)$row['open_count']; ?></td>
                            <td><?php echo (int)$row['closed_count']; ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No agents found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<section class="row g-4">
    <div class="col-xl-6">
        <div class="page-card h-100">
            <div class="section-heading mb-3">
                <div>
                    <h4 class="mb-1">Job Wise Summary</h4>
                    <p class="text-muted mb-0">Complaint status by job</p>
                </div>
                <a href="jobs.php" class="btn btn-outline-secondary btn-sm">View Jobs</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Job Name</th>
                            <th>Total</th>
                            <th>Open</th>
                            <th>Closed</th>
                            <th>Urgent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($job_summary && mysqli_num_rows($job_summary) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($job_summary)): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($row['job_name']); ?></td>
                                    <td><?php echo (int)$row['total_count']; ?></td>
                                    <td><?php echo (int)$row['open_count']; ?></td>
                                    <td><?php echo (int)$row['closed_count']; ?></td>
                                    <td class="text-danger fw-semibold"><?php echo (int)$row['most_urgent_count']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No jobs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="page-card h-100">
            <div class="section-heading mb-3">
                <div>
                    <h4 class="mb-1">Vendor Wise Summary</h4>
                    <p class="text-muted mb-0">Complaint status by vendor</p>
                </div>
                <a href="vendors.php" class="btn btn-outline-secondary btn-sm">View Vendors</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Vendor Name</th>
                            <th>Total</th>
                            <th>Open</th>
                            <th>Closed</th>
                            <th>Urgent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($vendor_summary && mysqli_num_rows($vendor_summary) > 0): ?>
                            <?php while ($row = mysqli_fetch_assoc($vendor_summary)): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($row['vendor_name']); ?></td>
                                    <td><?php echo (int)$row['total_count']; ?></td>
                                    <td><?php echo (int)$row['open_count']; ?></td>
                                    <td><?php echo (int)$row['closed_count']; ?></td>
                                    <td class="text-danger fw-semibold"><?php echo (int)$row['most_urgent_count']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No vendor complaint data found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
