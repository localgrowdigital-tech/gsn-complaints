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

function generate_complaint_id()
{
    return 'GSN' . random_int(10000, 99999);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_id = (int)($_POST['job_id'] ?? 0);
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $priority = clean($conn, $_POST['priority'] ?? 'Normal');
    $complaint_date = clean($conn, $_POST['complaint_date'] ?? '');
    $tracking_number = clean($conn, $_POST['tracking_number'] ?? '');
    $secondary_tracking_number = clean($conn, $_POST['secondary_tracking_number'] ?? '');
    $customer_name = clean($conn, $_POST['customer_name'] ?? '');
    $mobile = clean($conn, $_POST['mobile'] ?? '');
    $address = clean($conn, $_POST['address'] ?? '');
    $complaint_type = clean($conn, $_POST['complaint_type'] ?? '');
    $description = clean($conn, $_POST['description'] ?? '');

    $allowed_priorities = ['Normal', 'Urgent', 'Most Urgent'];
    $allowed_types = ['Shipment Delay', 'Lost Shipment', 'Damaged Shipment', 'Wrong Delivery', 'POD Required', 'Other'];

    if ($job_id <= 0) {
        $error = 'Please select a job.';
    } elseif (!in_array($priority, $allowed_priorities, true)) {
        $error = 'Invalid priority selected.';
    } elseif (!in_array($complaint_type, $allowed_types, true)) {
        $error = 'Invalid complaint type selected.';
    } elseif ($complaint_date === '' || $tracking_number === '' || $customer_name === '' || $mobile === '') {
        $error = 'Complaint date, tracking number, customer name, and mobile number are required.';
    } else {
        // Security check: selected job must be assigned to the logged-in agent and active.
        $job_check = mysqli_query($conn, "
            SELECT j.id
            FROM jobs j
            INNER JOIN agent_jobs aj ON aj.job_id = j.id
            WHERE aj.agent_id = $agent_id
              AND j.id = $job_id
              AND j.status = 'Active'
            LIMIT 1
        ");

        if (!$job_check || mysqli_num_rows($job_check) === 0) {
            $error = 'You are not allowed to add complaints for this job.';
        } else {
            if ($vendor_id > 0) {
                $vendor_check = mysqli_query($conn, "SELECT id FROM vendors WHERE id = $vendor_id AND status = 'Active' LIMIT 1");
                if (!$vendor_check || mysqli_num_rows($vendor_check) === 0) {
                    $vendor_id = 0;
                }
            }

            $complaint_id = generate_complaint_id();
            $status = 'Open';
            $vendor_sql_value = $vendor_id > 0 ? (string)$vendor_id : 'NULL';

            $insert_sql = "
                INSERT INTO complaints
                (
                    complaint_id,
                    job_id,
                    vendor_id,
                    priority,
                    complaint_date,
                    tracking_number,
                    secondary_tracking_number,
                    customer_name,
                    mobile,
                    address,
                    complaint_type,
                    description,
                    status
                )
                VALUES
                (
                    '$complaint_id',
                    $job_id,
                    $vendor_sql_value,
                    '$priority',
                    '$complaint_date',
                    '$tracking_number',
                    '$secondary_tracking_number',
                    '$customer_name',
                    '$mobile',
                    '$address',
                    '$complaint_type',
                    '$description',
                    '$status'
                )
            ";

            if (mysqli_query($conn, $insert_sql)) {
                $success = 'Complaint Added Successfully. Complaint ID: ' . $complaint_id;
                $_POST = [];
            } else {
                $error = 'Unable to add complaint. Please try again.';
            }
        }
    }
}

$jobs_result = mysqli_query($conn, "
    SELECT j.id, j.job_name, j.job_code
    FROM jobs j
    INNER JOIN agent_jobs aj ON aj.job_id = j.id
    WHERE aj.agent_id = $agent_id
      AND j.status = 'Active'
    ORDER BY j.job_name ASC
");

$vendors_result = mysqli_query($conn, "
    SELECT id, vendor_name, vendor_code
    FROM vendors
    WHERE status = 'Active'
    ORDER BY vendor_name ASC
");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Complaint - GRAND SPEED NETWORK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f4f7fb;
            color: #172033;
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
        .page-card {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }
        .form-control,
        .form-select {
            min-height: 44px;
        }
        .section-title {
            color: #475569;
            font-size: .88rem;
            font-weight: 700;
            letter-spacing: .02em;
            text-transform: uppercase;
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
        <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
            <div class="text-white d-none d-md-block">
                <span class="opacity-75">Welcome,</span>
                <strong><?php echo e($agent_name); ?></strong>
            </div>
            <a href="agent-dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
            <a href="agent-logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Add Complaint</h1>
            <p class="text-muted mb-0">Create a new courier complaint for your assigned jobs.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="agent-dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
            <a href="agent-complaints.php" class="btn btn-primary">My Complaints</a>
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

    <div class="card page-card">
        <div class="card-body p-3 p-md-4">
            <form method="post" class="row g-3">
                <div class="col-12">
                    <div class="section-title">Assignment</div>
                </div>

                <div class="col-md-4">
                    <label for="job_id" class="form-label">Job</label>
                    <select name="job_id" id="job_id" class="form-select" required>
                        <option value="">Select Job</option>
                        <?php if ($jobs_result): ?>
                            <?php while ($job = mysqli_fetch_assoc($jobs_result)): ?>
                                <?php $selected = ((int)($_POST['job_id'] ?? 0) === (int)$job['id']) ? 'selected' : ''; ?>
                                <option value="<?php echo (int)$job['id']; ?>" <?php echo $selected; ?>>
                                    <?php echo e($job['job_name']); ?><?php echo !empty($job['job_code']) ? ' (' . e($job['job_code']) . ')' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="vendor_id" class="form-label">Vendor</label>
                    <select name="vendor_id" id="vendor_id" class="form-select">
                        <option value="">Select Vendor</option>
                        <?php if ($vendors_result): ?>
                            <?php while ($vendor = mysqli_fetch_assoc($vendors_result)): ?>
                                <?php $selected = ((int)($_POST['vendor_id'] ?? 0) === (int)$vendor['id']) ? 'selected' : ''; ?>
                                <option value="<?php echo (int)$vendor['id']; ?>" <?php echo $selected; ?>>
                                    <?php echo e($vendor['vendor_name']); ?><?php echo !empty($vendor['vendor_code']) ? ' (' . e($vendor['vendor_code']) . ')' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="priority" class="form-label">Priority</label>
                    <select name="priority" id="priority" class="form-select" required>
                        <?php foreach (['Normal', 'Urgent', 'Most Urgent'] as $priority): ?>
                            <?php $selected = (($_POST['priority'] ?? 'Normal') === $priority) ? 'selected' : ''; ?>
                            <option value="<?php echo e($priority); ?>" <?php echo $selected; ?>><?php echo e($priority); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 mt-4">
                    <div class="section-title">Complaint Details</div>
                </div>

                <div class="col-md-4">
                    <label for="complaint_date" class="form-label">Complaint Date</label>
                    <input type="date" name="complaint_date" id="complaint_date" class="form-control" required value="<?php echo e($_POST['complaint_date'] ?? date('Y-m-d')); ?>">
                </div>

                <div class="col-md-4">
                    <label for="tracking_number" class="form-label">Tracking Number</label>
                    <input type="text" name="tracking_number" id="tracking_number" class="form-control" required value="<?php echo e($_POST['tracking_number'] ?? ''); ?>">
                </div>

                <div class="col-md-4">
                    <label for="secondary_tracking_number" class="form-label">Secondary Tracking Number</label>
                    <input type="text" name="secondary_tracking_number" id="secondary_tracking_number" class="form-control" value="<?php echo e($_POST['secondary_tracking_number'] ?? ''); ?>">
                </div>

                <div class="col-md-4">
                    <label for="customer_name" class="form-label">Customer Name</label>
                    <input type="text" name="customer_name" id="customer_name" class="form-control" required value="<?php echo e($_POST['customer_name'] ?? ''); ?>">
                </div>

                <div class="col-md-4">
                    <label for="mobile" class="form-label">Mobile Number</label>
                    <input type="text" name="mobile" id="mobile" class="form-control" required value="<?php echo e($_POST['mobile'] ?? ''); ?>">
                </div>

                <div class="col-md-4">
                    <label for="complaint_type" class="form-label">Complaint Type</label>
                    <select name="complaint_type" id="complaint_type" class="form-select" required>
                        <option value="">Select Type</option>
                        <?php foreach (['Shipment Delay', 'Lost Shipment', 'Damaged Shipment', 'Wrong Delivery', 'POD Required', 'Other'] as $type): ?>
                            <?php $selected = (($_POST['complaint_type'] ?? '') === $type) ? 'selected' : ''; ?>
                            <option value="<?php echo e($type); ?>" <?php echo $selected; ?>><?php echo e($type); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label for="address" class="form-label">Address</label>
                    <textarea name="address" id="address" rows="3" class="form-control"><?php echo e($_POST['address'] ?? ''); ?></textarea>
                </div>

                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" rows="4" class="form-control"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="col-12 d-flex flex-column flex-sm-row gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4">Submit Complaint</button>
                    <a href="agent-dashboard.php" class="btn btn-outline-secondary px-4">Back to Dashboard</a>
                    <a href="agent-complaints.php" class="btn btn-outline-primary px-4">My Complaints</a>
                </div>
            </form>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
