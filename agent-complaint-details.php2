<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agent_id = (int)$_SESSION['agent_id'];
$agent_name = $_SESSION['agent_name'] ?? 'Agent';
$complaint_db_id = (int)($_GET['id'] ?? 0);

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function table_has_column($conn, $table, $column)
{
    $table = mysqli_real_escape_string($conn, $table);
    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && mysqli_num_rows($result) > 0;
}

if ($complaint_db_id <= 0) {
    header('Location: agent-complaints.php');
    exit;
}

$complaint_sql = "
    SELECT c.*, j.job_name, v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE c.id = $complaint_db_id
      AND aj.agent_id = $agent_id
    LIMIT 1
";
$complaint_result = mysqli_query($conn, $complaint_sql);
$complaint = $complaint_result ? mysqli_fetch_assoc($complaint_result) : null;

if (!$complaint) {
    header('Location: agent-complaints.php?error=access_denied');
    exit;
}

$remarks_has_agent_id = table_has_column($conn, 'complaint_remarks', 'agent_id');

if ($remarks_has_agent_id) {
    $remarks_sql = "
        SELECT cr.remark, cr.status, cr.remark_date, a.agent_name
        FROM complaint_remarks cr
        LEFT JOIN agents a ON a.id = cr.agent_id
        WHERE cr.complaint_id = $complaint_db_id
        ORDER BY cr.remark_date DESC, cr.id DESC
    ";
} else {
    $remarks_sql = "
        SELECT cr.remark, cr.status, cr.remark_date
        FROM complaint_remarks cr
        WHERE cr.complaint_id = $complaint_db_id
        ORDER BY cr.remark_date DESC, cr.id DESC
    ";
}
$remarks_result = mysqli_query($conn, $remarks_sql);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complaint Details - GRAND SPEED NETWORK</title>
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
        .info-label {
            color: #64748b;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .info-value {
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .timeline {
            position: relative;
            padding-left: 1.25rem;
        }
        .timeline::before {
            content: "";
            position: absolute;
            top: .25rem;
            bottom: .25rem;
            left: .25rem;
            width: 2px;
            background: #dbeafe;
        }
        .timeline-item {
            position: relative;
            padding-left: 1.25rem;
            padding-bottom: 1rem;
        }
        .timeline-dot {
            position: absolute;
            left: -.08rem;
            top: .25rem;
            width: .72rem;
            height: .72rem;
            border-radius: 50%;
            background: #0d6efd;
            box-shadow: 0 0 0 4px #dbeafe;
        }
        @media print {
            body { background: #fff; }
            .navbar,
            .no-print,
            .btn { display: none !important; }
            .container-fluid { padding: 0 !important; }
            .page-card {
                box-shadow: none !important;
                border: 1px solid #dee2e6 !important;
                break-inside: avoid;
            }
            a { color: #000; text-decoration: none; }
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
                <a href="agent-logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Complaint Details</h1>
            <p class="text-muted mb-0">Complete complaint information and remark history.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="agent-complaints.php" class="btn btn-outline-primary">Back to My Complaints</a>
            <a href="agent-remarks.php?id=<?php echo (int)$complaint_db_id; ?>" class="btn btn-primary">Add Remark</a>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            <a href="agent-dashboard.php" class="btn btn-outline-primary">Dashboard</a>
        </div>
    </div>

    <div class="card page-card mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
                <div>
                    <div class="info-label">Complaint ID</div>
                    <div class="h4 fw-bold mb-0"><?php echo e($complaint['complaint_id']); ?></div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    <span class="badge <?php echo priority_badge_class($complaint['priority']); ?>"><?php echo e($complaint['priority']); ?></span>
                    <span class="badge <?php echo status_badge_class($complaint['status']); ?>"><?php echo e($complaint['status']); ?></span>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="info-label">Complaint Date</div>
                    <div class="info-value"><?php echo e($complaint['complaint_date']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Job Name</div>
                    <div class="info-value"><?php echo e($complaint['job_name']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Vendor Name</div>
                    <div class="info-value"><?php echo e($complaint['vendor_name'] ?: 'No Vendor'); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Closing Date</div>
                    <div class="info-value"><?php echo e($complaint['closing_date'] ?: 'Not closed'); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Tracking Number</div>
                    <div class="info-value"><?php echo e($complaint['tracking_number']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Secondary Tracking Number</div>
                    <div class="info-value"><?php echo e($complaint['secondary_tracking_number']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Customer Name</div>
                    <div class="info-value"><?php echo e($complaint['customer_name']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Mobile</div>
                    <div class="info-value"><?php echo e($complaint['mobile']); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="info-label">Complaint Type</div>
                    <div class="info-value"><?php echo e($complaint['complaint_type']); ?></div>
                </div>
                <div class="col-md-8">
                    <div class="info-label">Address</div>
                    <div class="info-value"><?php echo nl2br(e($complaint['address'])); ?></div>
                </div>
                <div class="col-12">
                    <div class="info-label">Description</div>
                    <div class="border rounded bg-light p-3"><?php echo nl2br(e($complaint['description'])); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card page-card">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
                <h2 class="h5 fw-bold mb-0">Remark History</h2>
                <a href="agent-remarks.php?id=<?php echo (int)$complaint_db_id; ?>" class="btn btn-sm btn-primary no-print">Add Remark</a>
            </div>

            <?php if ($remarks_result && mysqli_num_rows($remarks_result) > 0): ?>
                <div class="timeline">
                    <?php while ($remark = mysqli_fetch_assoc($remarks_result)): ?>
                        <div class="timeline-item">
                            <span class="timeline-dot"></span>
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="badge <?php echo status_badge_class($remark['status']); ?>"><?php echo e($remark['status']); ?></span>
                                            <?php if ($remarks_has_agent_id && !empty($remark['agent_name'])): ?>
                                                <span class="text-muted small">By <?php echo e($remark['agent_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?php echo e($remark['remark_date']); ?></small>
                                    </div>
                                    <div><?php echo nl2br(e($remark['remark'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="text-center text-muted py-4">No remarks found for this complaint.</div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
