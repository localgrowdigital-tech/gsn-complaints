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
$createdComplaintId = '';
$createdComplaintDbId = 0;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function generate_complaint_id(): string
{
    return 'GSN' . random_int(10000, 99999);
}

$allowedPriorities = ['Normal', 'Urgent', 'Most Urgent'];
$allowedTypes = [
    'Shipment Delay',
    'Lost Shipment',
    'Damaged Shipment',
    'Wrong Delivery',
    'POD Required',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $vendorId = (int) ($_POST['vendor_id'] ?? 0);
    $priority = trim((string) ($_POST['priority'] ?? 'Normal'));
    $complaintDate = trim((string) ($_POST['complaint_date'] ?? ''));
    $trackingNumber = trim((string) ($_POST['tracking_number'] ?? ''));
    $secondaryTrackingNumber = trim((string) ($_POST['secondary_tracking_number'] ?? ''));
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $complaintType = trim((string) ($_POST['complaint_type'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } elseif ($jobId <= 0) {
        $error = 'Please select a job.';
    } elseif (!in_array($priority, $allowedPriorities, true)) {
        $error = 'Invalid priority selected.';
    } elseif (!in_array($complaintType, $allowedTypes, true)) {
        $error = 'Invalid complaint type selected.';
    } elseif (
        $complaintDate === '' ||
        $trackingNumber === '' ||
        $customerName === '' ||
        $mobile === ''
    ) {
        $error = 'Please fill all required fields.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $complaintDate)) {
        $error = 'Invalid complaint date.';
    } else {
        $jobCheckSql = "
            SELECT j.id
            FROM jobs j
            INNER JOIN agent_jobs aj ON aj.job_id = j.id
            WHERE aj.agent_id = ?
              AND j.id = ?
              AND j.status = 'Active'
            LIMIT 1
        ";

        $jobCheckStmt = mysqli_prepare($conn, $jobCheckSql);

        if (!$jobCheckStmt) {
            $error = 'Unable to verify selected job.';
        } else {
            mysqli_stmt_bind_param($jobCheckStmt, 'ii', $agentId, $jobId);
            mysqli_stmt_execute($jobCheckStmt);
            $jobCheckResult = mysqli_stmt_get_result($jobCheckStmt);
            $jobAllowed = mysqli_num_rows($jobCheckResult) === 1;
            mysqli_stmt_close($jobCheckStmt);

            if (!$jobAllowed) {
                $error = 'You are not allowed to add complaints for this job.';
            } else {
                if ($vendorId > 0) {
                    $vendorCheckSql = "
                        SELECT id
                        FROM vendors
                        WHERE id = ?
                          AND status = 'Active'
                        LIMIT 1
                    ";

                    $vendorCheckStmt = mysqli_prepare($conn, $vendorCheckSql);

                    if ($vendorCheckStmt) {
                        mysqli_stmt_bind_param($vendorCheckStmt, 'i', $vendorId);
                        mysqli_stmt_execute($vendorCheckStmt);
                        $vendorCheckResult = mysqli_stmt_get_result($vendorCheckStmt);

                        if (mysqli_num_rows($vendorCheckResult) === 0) {
                            $vendorId = 0;
                        }

                        mysqli_stmt_close($vendorCheckStmt);
                    } else {
                        $vendorId = 0;
                    }
                }

                $status = 'Open';
                $complaintId = '';

                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidate = generate_complaint_id();

                    $checkIdStmt = mysqli_prepare(
                        $conn,
                        "SELECT id FROM complaints WHERE complaint_id = ? LIMIT 1"
                    );

                    if (!$checkIdStmt) {
                        break;
                    }

                    mysqli_stmt_bind_param($checkIdStmt, 's', $candidate);
                    mysqli_stmt_execute($checkIdStmt);
                    $checkIdResult = mysqli_stmt_get_result($checkIdStmt);
                    $exists = mysqli_num_rows($checkIdResult) > 0;
                    mysqli_stmt_close($checkIdStmt);

                    if (!$exists) {
                        $complaintId = $candidate;
                        break;
                    }
                }

                if ($complaintId === '') {
                    $error = 'Unable to generate complaint ID. Please try again.';
                } else {
                    $insertSql = "
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
                            ?,
                            ?,
                            NULLIF(?, 0),
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ";

                    $insertStmt = mysqli_prepare($conn, $insertSql);

                    if (!$insertStmt) {
                        $error = 'Unable to prepare complaint submission.';
                    } else {
                        mysqli_stmt_bind_param(
                            $insertStmt,
                            'siissssssssss',
                            $complaintId,
                            $jobId,
                            $vendorId,
                            $priority,
                            $complaintDate,
                            $trackingNumber,
                            $secondaryTrackingNumber,
                            $customerName,
                            $mobile,
                            $address,
                            $complaintType,
                            $description,
                            $status
                        );

                        if (mysqli_stmt_execute($insertStmt)) {
                            $createdComplaintDbId = mysqli_insert_id($conn);
                            $createdComplaintId = $complaintId;
                            $success = 'Complaint added successfully.';
                            $_POST = [];
                        } else {
                            $error = 'Unable to add complaint. Please try again.';
                        }

                        mysqli_stmt_close($insertStmt);
                    }
                }
            }
        }
    }
}

$jobsSql = "
    SELECT j.id, j.job_name, j.job_code
    FROM jobs j
    INNER JOIN agent_jobs aj ON aj.job_id = j.id
    WHERE aj.agent_id = ?
      AND j.status = 'Active'
    ORDER BY j.job_name ASC
";

$jobsStmt = mysqli_prepare($conn, $jobsSql);
$jobsResult = false;

if ($jobsStmt) {
    mysqli_stmt_bind_param($jobsStmt, 'i', $agentId);
    mysqli_stmt_execute($jobsStmt);
    $jobsResult = mysqli_stmt_get_result($jobsStmt);
}

$vendorsResult = mysqli_query(
    $conn,
    "
        SELECT id, vendor_name, vendor_code
        FROM vendors
        WHERE status = 'Active'
        ORDER BY vendor_name ASC
    "
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Complaint - GRAND SPEED NETWORK</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --page-bg: #f4f7fb;
            --main-text: #10213d;
            --muted-text: #64748b;
            --line: #d9e2ef;
            --primary: #0d6efd;
        }

        body {
            background:
                radial-gradient(circle at top right, rgba(13, 110, 253, .09), transparent 30%),
                var(--page-bg);
            color: var(--main-text);
            min-height: 100vh;
        }

        .topbar {
            background: linear-gradient(110deg, #0b5ed7, #4338ca);
            box-shadow: 0 12px 35px rgba(30, 64, 175, .22);
        }

        .brand-box {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: var(--primary);
            font-weight: 900;
        }

        .page-shell {
            padding: 24px 28px 36px;
        }

        .form-card {
            border: 0;
            border-radius: 20px;
            box-shadow: 0 16px 42px rgba(15, 23, 42, .08);
            overflow: hidden;
        }

        .form-card::before {
            content: "";
            display: block;
            width: 180px;
            height: 4px;
            background: linear-gradient(90deg, #0d6efd, #7c3aed);
        }

        .form-card .card-body {
            padding: 30px;
        }

        .section-heading {
            color: var(--primary);
            font-weight: 800;
            font-size: 1rem;
            border-bottom: 1px solid var(--line);
            padding-bottom: 10px;
            margin-bottom: 18px;
        }

        .form-label {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .required {
            color: #dc3545;
        }

        .form-control,
        .form-select {
            min-height: 52px;
            border-radius: 12px;
            border-color: #d7e0eb;
            font-size: 1rem;
            padding-left: 14px;
            padding-right: 14px;
        }

        textarea.form-control {
            min-height: 112px;
            padding-top: 12px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 .22rem rgba(13, 110, 253, .12);
        }

        .submit-bar {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            margin: 28px -30px -30px;
            padding: 22px 30px;
        }

        .success-panel {
            border: 1px solid #bbf7d0;
            background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
            border-radius: 18px;
            padding: 22px;
        }

        .success-icon {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #16a34a;
            color: #fff;
            font-size: 1.5rem;
        }

        .complaint-id-box {
            display: inline-block;
            background: #fff;
            border: 1px dashed #16a34a;
            color: #166534;
            padding: 8px 14px;
            border-radius: 10px;
            font-weight: 800;
            letter-spacing: .03em;
        }

        @media (max-width: 767.98px) {
            .page-shell {
                padding: 16px 12px 26px;
            }

            .form-card .card-body {
                padding: 20px;
            }

            .submit-bar {
                margin: 24px -20px -20px;
                padding: 18px 20px;
            }

            .navbar-brand span:last-child {
                display: none;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark topbar">
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
                    <strong><?php echo e($agentName); ?></strong>
                </div>

                <a href="agent-dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
                <a href="agent-complaints.php" class="btn btn-outline-light btn-sm">My Complaints</a>
                <a href="agent-logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid page-shell">

    <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold mb-1">Add Complaint</h1>
            <p class="text-muted mb-0 fs-5">Create a new courier complaint for your assigned jobs.</p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a href="agent-dashboard.php" class="btn btn-outline-primary px-4">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>

            <a href="agent-complaints.php" class="btn btn-primary px-4">
                <i class="bi bi-list-ul me-1"></i> My Complaints
            </a>
        </div>
    </div>

    <?php if ($success && $createdComplaintId !== ''): ?>
        <div class="success-panel mb-4">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div class="d-flex align-items-start gap-3">
                    <span class="success-icon">
                        <i class="bi bi-check-lg"></i>
                    </span>

                    <div>
                        <h2 class="h4 fw-bold text-success mb-1">Complaint Added Successfully</h2>
                        <p class="text-muted mb-2">The complaint has been saved and is ready for follow-up.</p>
                        <span class="complaint-id-box"><?php echo e($createdComplaintId); ?></span>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="agent-add-complaint.php" class="btn btn-success">
                        <i class="bi bi-plus-circle me-1"></i> Add New Complaint
                    </a>

                    <a href="agent-complaint-details.php?id=<?php echo (int) $createdComplaintDbId; ?>" class="btn btn-outline-success">
                        View Complaint
                    </a>

                    <a href="agent-dashboard.php" class="btn btn-outline-secondary">
                        Dashboard
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Unable to submit:</strong> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="card form-card">
        <div class="card-body">

            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                <div>
                    <h2 class="h3 fw-bold mb-1">Create New Complaint</h2>
                    <p class="text-muted mb-0">Enter complaint, shipment and customer information.</p>
                </div>

                <div class="small text-muted align-self-lg-center">
                    Fields marked <span class="required">*</span> are required.
                </div>
            </div>

            <form method="post" id="complaintForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">

                <div class="section-heading">
                    <i class="bi bi-diagram-3 me-2"></i>Assignment
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-4">
                        <label for="job_id" class="form-label">Job <span class="required">*</span></label>
                        <select name="job_id" id="job_id" class="form-select" required>
                            <option value="">Select Job</option>

                            <?php if ($jobsResult): ?>
                                <?php while ($job = mysqli_fetch_assoc($jobsResult)): ?>
                                    <option
                                        value="<?php echo (int) $job['id']; ?>"
                                        <?php echo (int) ($_POST['job_id'] ?? 0) === (int) $job['id'] ? 'selected' : ''; ?>
                                    >
                                        <?php echo e($job['job_name']); ?>
                                        <?php echo !empty($job['job_code']) ? ' (' . e($job['job_code']) . ')' : ''; ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-lg-4">
                        <label for="vendor_id" class="form-label">Vendor</label>
                        <select name="vendor_id" id="vendor_id" class="form-select">
                            <option value="">Select Vendor</option>

                            <?php if ($vendorsResult): ?>
                                <?php while ($vendor = mysqli_fetch_assoc($vendorsResult)): ?>
                                    <option
                                        value="<?php echo (int) $vendor['id']; ?>"
                                        <?php echo (int) ($_POST['vendor_id'] ?? 0) === (int) $vendor['id'] ? 'selected' : ''; ?>
                                    >
                                        <?php echo e($vendor['vendor_name']); ?>
                                        <?php echo !empty($vendor['vendor_code']) ? ' (' . e($vendor['vendor_code']) . ')' : ''; ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-lg-4">
                        <label for="priority" class="form-label">Priority <span class="required">*</span></label>
                        <select name="priority" id="priority" class="form-select" required>
                            <?php foreach ($allowedPriorities as $priority): ?>
                                <option
                                    value="<?php echo e($priority); ?>"
                                    <?php echo ($_POST['priority'] ?? 'Normal') === $priority ? 'selected' : ''; ?>
                                >
                                    <?php echo e($priority); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="section-heading">
                    <i class="bi bi-box-seam me-2"></i>Complaint Information
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-4">
                        <label for="complaint_date" class="form-label">Complaint Date <span class="required">*</span></label>
                        <input
                            type="date"
                            name="complaint_date"
                            id="complaint_date"
                            class="form-control"
                            required
                            value="<?php echo e($_POST['complaint_date'] ?? date('Y-m-d')); ?>"
                        >
                    </div>

                    <div class="col-lg-4">
                        <label for="tracking_number" class="form-label">Tracking Number <span class="required">*</span></label>
                        <input
                            type="text"
                            name="tracking_number"
                            id="tracking_number"
                            class="form-control"
                            required
                            placeholder="Enter tracking number"
                            value="<?php echo e($_POST['tracking_number'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-lg-4">
                        <label for="secondary_tracking_number" class="form-label">Secondary Tracking Number</label>
                        <input
                            type="text"
                            name="secondary_tracking_number"
                            id="secondary_tracking_number"
                            class="form-control"
                            placeholder="Enter secondary tracking number"
                            value="<?php echo e($_POST['secondary_tracking_number'] ?? ''); ?>"
                        >
                    </div>
                </div>

                <div class="section-heading">
                    <i class="bi bi-person me-2"></i>Customer Information
                </div>

                <div class="row g-4 mb-4">
                    <div class="col-lg-6">
                        <label for="customer_name" class="form-label">Customer Name <span class="required">*</span></label>
                        <input
                            type="text"
                            name="customer_name"
                            id="customer_name"
                            class="form-control"
                            required
                            placeholder="Enter customer name"
                            value="<?php echo e($_POST['customer_name'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-lg-6">
                        <label for="mobile" class="form-label">Mobile Number <span class="required">*</span></label>
                        <input
                            type="text"
                            name="mobile"
                            id="mobile"
                            class="form-control"
                            required
                            inputmode="numeric"
                            maxlength="15"
                            placeholder="Enter mobile number"
                            value="<?php echo e($_POST['mobile'] ?? ''); ?>"
                        >
                    </div>

                    <div class="col-12">
                        <label for="address" class="form-label">Address</label>
                        <textarea
                            name="address"
                            id="address"
                            class="form-control"
                            rows="3"
                            placeholder="Enter customer address"
                        ><?php echo e($_POST['address'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="section-heading">
                    <i class="bi bi-exclamation-circle me-2"></i>Complaint Details
                </div>

                <div class="row g-4">
                    <div class="col-lg-4">
                        <label for="complaint_type" class="form-label">Complaint Type <span class="required">*</span></label>
                        <select name="complaint_type" id="complaint_type" class="form-select" required>
                            <option value="">Select Type</option>

                            <?php foreach ($allowedTypes as $type): ?>
                                <option
                                    value="<?php echo e($type); ?>"
                                    <?php echo ($_POST['complaint_type'] ?? '') === $type ? 'selected' : ''; ?>
                                >
                                    <?php echo e($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-8">
                        <label for="description" class="form-label">Description</label>
                        <textarea
                            name="description"
                            id="description"
                            class="form-control"
                            rows="3"
                            placeholder="Enter complaint description"
                        ><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="submit-bar d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div class="small text-muted">
                        Please verify tracking number and customer details before submitting.
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="reset" class="btn btn-outline-secondary px-4">
                            Reset
                        </button>

                        <a href="agent-complaints.php" class="btn btn-outline-primary px-4">
                            My Complaints
                        </a>

                        <button type="submit" class="btn btn-primary px-5 fw-semibold">
                            <i class="bi bi-plus-circle me-1"></i>
                            Submit Complaint
                        </button>
                    </div>
                </div>
            </form>

        </div>
    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.getElementById('mobile').addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9+\-\s]/g, '');
});
</script>

</body>
</html>

<?php
if ($jobsStmt) {
    mysqli_stmt_close($jobsStmt);
}
?>
