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
    header('Location: agent-complaints.php?error=not_allowed');
    exit;
}

$allowed_statuses = ['Open', 'In Progress', 'Resolved', 'Closed'];
$remarks_has_agent_id = table_has_column($conn, 'complaint_remarks', 'agent_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $remark = clean($conn, $_POST['remark'] ?? '');
    $status = clean($conn, $_POST['status'] ?? '');
    $closing_date = clean($conn, $_POST['closing_date'] ?? '');

    if ($remark === '') {
        $error = 'Remark is required.';
    } elseif (!in_array($status, $allowed_statuses, true)) {
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
            $error = 'You are not allowed to add remarks for this complaint.';
        } else {
            if ($remarks_has_agent_id) {
                $insert_sql = "
                    INSERT INTO complaint_remarks (complaint_id, agent_id, remark, status, remark_date)
                    VALUES ($complaint_db_id, $agent_id, '$remark', '$status', NOW())
                ";
            } else {
                $insert_sql = "
                    INSERT INTO complaint_remarks (complaint_id, remark, status, remark_date)
                    VALUES ($complaint_db_id, '$remark', '$status', NOW())
                ";
            }

            if (mysqli_query($conn, $insert_sql)) {
                $closing_sql = $closing_date !== '' ? ", closing_date = '$closing_date'" : "";
                $update_sql = "UPDATE complaints SET status = '$status' $closing_sql WHERE id = $complaint_db_id";

                if (mysqli_query($conn, $update_sql)) {
                    $success = 'Remark added successfully.';
                    $complaint_result = mysqli_query($conn, $complaint_sql);
                    $complaint = $complaint_result ? mysqli_fetch_assoc($complaint_result) : $complaint;
                } else {
                    $error = 'Remark saved, but complaint status could not be updated.';
                }
            } else {
                $error = 'Unable to save remark. Please try again.';
            }
        }
    }
}

$remarks_result = mysqli_query($conn, "
    SELECT remark, status, remark_date
    FROM complaint_remarks
    WHERE complaint_id = $complaint_db_id
    ORDER BY remark_date DESC, id DESC
");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complaint Remarks - GRAND SPEED NETWORK</title>
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
        .form-control,
        .form-select {
            min-height: 42px;
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
                <a href="agent-complaints.php" class="btn btn-outline-light btn-sm">My Complaints</a>
                <a href="agent-add-complaint.php" class="btn btn-outline-light btn-sm">Add Complaint</a>
                <a href="agent-logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Complaint Remarks</h1>
            <p class="text-muted mb-0">Update status and add follow-up remarks.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="agent-complaints.php" class="btn btn-outline-primary">Back to My Complaints</a>
            <a href="complaint-details.php?id=<?php echo (int)$complaint_db_id; ?>" class="btn btn-primary">View Details</a>
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
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="info-label">Complaint ID</div>
                    <div class="fw-semibold"><?php echo e($complaint['complaint_id']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Job Name</div>
                    <div class="fw-semibold"><?php echo e($complaint['job_name']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Vendor Name</div>
                    <div class="fw-semibold"><?php echo e($complaint['vendor_name'] ?: 'No Vendor'); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Customer Name</div>
                    <div class="fw-semibold"><?php echo e($complaint['customer_name']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Tracking Number</div>
                    <div class="fw-semibold"><?php echo e($complaint['tracking_number']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Mobile</div>
                    <div class="fw-semibold"><?php echo e($complaint['mobile']); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Current Status</div>
                    <span class="badge <?php echo status_badge_class($complaint['status']); ?>"><?php echo e($complaint['status']); ?></span>
                </div>
                <div class="col-md-3">
                    <div class="info-label">Priority</div>
                    <span class="badge <?php echo priority_badge_class($complaint['priority']); ?>"><?php echo e($complaint['priority']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card page-card">
                <div class="card-body">
                    <h2 class="h5 fw-bold mb-3">Add Remark</h2>
                    <form method="post">
                        <div class="mb-3">
                            <label for="remark" class="form-label">Remark</label>
                            <textarea name="remark" id="remark" rows="5" class="form-control" required><?php echo e($_POST['remark'] ?? ''); ?></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select name="status" id="status" class="form-select" required>
                                    <?php foreach ($allowed_statuses as $status): ?>
                                        <?php $selected = (($_POST['status'] ?? $complaint['status']) === $status) ? 'selected' : ''; ?>
                                        <option value="<?php echo e($status); ?>" <?php echo $selected; ?>><?php echo e($status); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="closing_date" class="form-label">Closing Date</label>
                                <input type="date" name="closing_date" id="closing_date" class="form-control" value="<?php echo e($_POST['closing_date'] ?? ($complaint['closing_date'] ?? '')); ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary mt-4">Submit Remark</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card page-card">
                <div class="card-body">
                    <h2 class="h5 fw-bold mb-3">Remark History</h2>
                    <?php if ($remarks_result && mysqli_num_rows($remarks_result) > 0): ?>
                        <div class="timeline">
                            <?php while ($remark_row = mysqli_fetch_assoc($remarks_result)): ?>
                                <div class="timeline-item">
                                    <span class="timeline-dot"></span>
                                    <div class="card border-0 shadow-sm">
                                        <div class="card-body">
                                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                                                <span class="badge <?php echo status_badge_class($remark_row['status']); ?>"><?php echo e($remark_row['status']); ?></span>
                                                <small class="text-muted"><?php echo e($remark_row['remark_date']); ?></small>
                                            </div>
                                            <div><?php echo nl2br(e($remark_row['remark'])); ?></div>
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
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
