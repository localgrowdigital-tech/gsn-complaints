<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agent_id = (int)$_SESSION['agent_id'];
$agent_name = $_SESSION['agent_name'] ?? 'Agent';
$success_count = 0;
$failed_rows = [];
$message = '';
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

if (isset($_GET['sample']) && $_GET['sample'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=agent-complaint-sample.csv');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'job_code',
        'vendor_code',
        'complaint_date',
        'tracking_number',
        'secondary_tracking_number',
        'customer_name',
        'mobile',
        'address',
        'complaint_type',
        'description',
        'priority'
    ]);
    fputcsv($output, [
        'JOB001',
        'VEN001',
        date('Y-m-d'),
        'TRK123456',
        'ALT123456',
        'Sample Customer',
        '9876543210',
        'Sample delivery address',
        'Shipment Delay',
        'Shipment delayed beyond expected delivery date.',
        'Normal'
    ]);
    fclose($output);
    exit;
}

$required_columns = [
    'job_code',
    'vendor_code',
    'complaint_date',
    'tracking_number',
    'secondary_tracking_number',
    'customer_name',
    'mobile',
    'address',
    'complaint_type',
    'description',
    'priority'
];

$allowed_priorities = ['Normal', 'Urgent', 'Most Urgent'];
$allowed_types = ['Shipment Delay', 'Lost Shipment', 'Damaged Shipment', 'Wrong Delivery', 'POD Required', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload a valid CSV file.';
    } else {
        $file_name = $_FILES['csv_file']['name'];
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            $error = 'Only CSV files are allowed.';
        } elseif (($handle = fopen($file_tmp, 'r')) === false) {
            $error = 'Unable to read uploaded CSV file.';
        } else {
            $headers = fgetcsv($handle);
            $headers = array_map('trim', $headers ?: []);
            $missing_columns = array_diff($required_columns, $headers);

            if (!empty($missing_columns)) {
                $error = 'Missing required columns: ' . implode(', ', $missing_columns);
            } else {
                $row_number = 1;

                while (($row = fgetcsv($handle)) !== false) {
                    $row_number++;
                    $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                    $data = array_combine($headers, $row);

                    $job_code = clean($conn, $data['job_code'] ?? '');
                    $vendor_code = clean($conn, $data['vendor_code'] ?? '');
                    $complaint_date = clean($conn, $data['complaint_date'] ?? '');
                    $tracking_number = clean($conn, $data['tracking_number'] ?? '');
                    $secondary_tracking_number = clean($conn, $data['secondary_tracking_number'] ?? '');
                    $customer_name = clean($conn, $data['customer_name'] ?? '');
                    $mobile = clean($conn, $data['mobile'] ?? '');
                    $address = clean($conn, $data['address'] ?? '');
                    $complaint_type = clean($conn, $data['complaint_type'] ?? '');
                    $description = clean($conn, $data['description'] ?? '');
                    $priority = clean($conn, $data['priority'] ?? '');

                    if ($job_code === '') {
                        $failed_rows[] = "Row $row_number: job_code is required.";
                        continue;
                    }

                    if ($complaint_date === '' || $tracking_number === '' || $customer_name === '' || $mobile === '') {
                        $failed_rows[] = "Row $row_number: complaint_date, tracking_number, customer_name, and mobile are required.";
                        continue;
                    }

                    if (!in_array($priority, $allowed_priorities, true)) {
                        $failed_rows[] = "Row $row_number: invalid priority.";
                        continue;
                    }

                    if (!in_array($complaint_type, $allowed_types, true)) {
                        $failed_rows[] = "Row $row_number: invalid complaint_type.";
                        continue;
                    }

                    $job_result = mysqli_query($conn, "
                        SELECT j.id
                        FROM jobs j
                        INNER JOIN agent_jobs aj ON aj.job_id = j.id
                        WHERE j.job_code = '$job_code'
                          AND aj.agent_id = $agent_id
                        LIMIT 1
                    ");
                    $job = $job_result ? mysqli_fetch_assoc($job_result) : null;

                    if (!$job) {
                        $failed_rows[] = "Row $row_number: job_code is not assigned to this agent or does not exist.";
                        continue;
                    }

                    $job_id = (int)$job['id'];
                    $vendor_id = 'NULL';

                    if ($vendor_code !== '') {
                        $vendor_result = mysqli_query($conn, "SELECT id FROM vendors WHERE vendor_code = '$vendor_code' LIMIT 1");
                        $vendor = $vendor_result ? mysqli_fetch_assoc($vendor_result) : null;

                        if ($vendor) {
                            $vendor_id = (string)(int)$vendor['id'];
                        }
                    }

                    $complaint_id = generate_complaint_id();
                    $status = 'Open';

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
                            $vendor_id,
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
                        $success_count++;
                    } else {
                        $failed_rows[] = "Row $row_number: database insert failed.";
                    }
                }

                $message = 'CSV import completed.';
            }

            fclose($handle);
        }
    }
}

$failed_count = count($failed_rows);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import CSV - GRAND SPEED NETWORK</title>
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
        .metric-card {
            border-left: 5px solid #0d6efd;
        }
        .form-control {
            min-height: 44px;
        }
        code {
            color: #0d6efd;
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

<main class="container py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Import Complaints CSV</h1>
            <p class="text-muted mb-0">Upload complaints for your assigned job codes only.</p>
        </div>
        <a href="agent-import.php?sample=1" class="btn btn-outline-primary">Download Sample Format</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo e($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card page-card metric-card">
                    <div class="card-body">
                        <div class="text-muted fw-semibold">Successful Imports</div>
                        <div class="display-6 fw-bold text-success"><?php echo (int)$success_count; ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card page-card metric-card">
                    <div class="card-body">
                        <div class="text-muted fw-semibold">Failed Rows</div>
                        <div class="display-6 fw-bold text-danger"><?php echo (int)$failed_count; ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card page-card">
                <div class="card-body">
                    <h2 class="h5 fw-bold mb-3">Upload CSV File</h2>
                    <form method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="csv_file" class="form-label">Upload CSV file</label>
                            <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Import CSV</button>
                        <a href="agent-import.php?sample=1" class="btn btn-outline-secondary">Download Sample Format</a>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card page-card">
                <div class="card-body">
                    <h2 class="h5 fw-bold mb-3">Required CSV Columns</h2>
                    <p class="text-muted">The first row must contain these headers exactly:</p>
                    <div class="bg-light border rounded p-3 mb-3">
                        <code>job_code, vendor_code, complaint_date, tracking_number, secondary_tracking_number, customer_name, mobile, address, complaint_type, description, priority</code>
                    </div>
                    <div class="small text-muted">
                        Allowed priority values: Normal, Urgent, Most Urgent<br>
                        Allowed complaint types: Shipment Delay, Lost Shipment, Damaged Shipment, Wrong Delivery, POD Required, Other
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($failed_rows)): ?>
        <div class="card page-card mt-4">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Failed Row Reasons</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Reason</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($failed_rows as $index => $reason): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo e($reason); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
