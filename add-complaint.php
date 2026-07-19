<?php
session_start();
include 'db.php';

require_once 'includes/timeline.php';

$jobs = mysqli_query($conn, "SELECT * FROM jobs WHERE status='Active' ORDER BY job_name ASC");

$vendors = mysqli_query(
    $conn,
    "SELECT id, vendor_name
     FROM vendors
     WHERE status='Active'
     ORDER BY vendor_name ASC"
);

if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}

if (isset($_POST['save'])) {

    $complaint_id = "GSN" . rand(10000, 99999);

    $complaint_date = trim($_POST['complaint_date'] ?? '');
    $tracking_number = trim($_POST['tracking_number'] ?? '');
    $secondary_tracking_number = trim($_POST['secondary_tracking_number'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $complaint_type = trim($_POST['complaint_type'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $status = "Open";
    $priority = $_POST['priority'] ?? 'Normal';
    $job_id = (int)($_POST['job_id'] ?? 0);

    $allowedPriorities = ['Normal', 'Urgent', 'Most Urgent'];

    if (!in_array($priority, $allowedPriorities, true)) {
        $priority = 'Normal';
    }

    $vendor_id = !empty($_POST['vendor_id'])
    ? (int)$_POST['vendor_id']
    : 0;

/* Required fields validation */
if (
    $job_id <= 0 ||
    $complaint_date === '' ||
    $tracking_number === '' ||
    $customer_name === '' ||
    $mobile === '' ||
    $complaint_type === ''
) {
    $error = "Please fill all required fields.";
}

/* Active Job validation */
if (!isset($error)) {

    $jobCheck = mysqli_prepare(
        $conn,
        "SELECT id FROM jobs WHERE id = ? AND status = 'Active' LIMIT 1"
    );

    if (!$jobCheck) {

        $error = "Unable to validate selected job.";

    } else {

        mysqli_stmt_bind_param($jobCheck, "i", $job_id);
        mysqli_stmt_execute($jobCheck);
        mysqli_stmt_store_result($jobCheck);

        if (mysqli_stmt_num_rows($jobCheck) !== 1) {
            $error = "Invalid or inactive job selected.";
        }

        mysqli_stmt_close($jobCheck);
    }
}

/* Optional Active Vendor validation */
if (!isset($error) && $vendor_id > 0) {

    $vendorCheck = mysqli_prepare(
        $conn,
        "SELECT id FROM vendors WHERE id = ? AND status = 'Active' LIMIT 1"
    );

    if (!$vendorCheck) {

        $error = "Unable to validate selected vendor.";

    } else {

        mysqli_stmt_bind_param($vendorCheck, "i", $vendor_id);
        mysqli_stmt_execute($vendorCheck);
        mysqli_stmt_store_result($vendorCheck);

        if (mysqli_stmt_num_rows($vendorCheck) !== 1) {
            $error = "Invalid or inactive vendor selected.";
        }

        mysqli_stmt_close($vendorCheck);
    }
}

if (!isset($error)) {

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO complaints
        (
            complaint_id,
            job_id,
            vendor_id,
            complaint_date,
            tracking_number,
            secondary_tracking_number,
            customer_name,
            mobile,
            address,
            complaint_type,
            description,
            status,
            priority
        )
        VALUES
        (
            ?,?,?,?,?,?,?,?,?,?,?,?,?
        )"
    );

    if (!$stmt) {

        $error = "Database Prepare Error.";

    } else {

        mysqli_stmt_bind_param(
            $stmt,
            "siissssssssss",
            $complaint_id,
            $job_id,
            $vendor_id,
            $complaint_date,
            $tracking_number,
            $secondary_tracking_number,
            $customer_name,
            $mobile,
            $address,
            $complaint_type,
            $description,
            $status,
            $priority
        );

        if (mysqli_stmt_execute($stmt)) {

            $newComplaintId = mysqli_insert_id($conn);

            addTimeline(
                $conn,
                $newComplaintId,
                'Complaint Created',
                'Complaint ID: ' . $complaint_id,
                'Admin',
                $_SESSION['admin'] ?? 'Admin'
            );

            $success = "Complaint Added Successfully. Complaint ID: " . $complaint_id;

        } else {

            $error = "Unable to save complaint.";

        }

                mysqli_stmt_close($stmt);
    }

}

}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Complaint - GSN</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f4f7fb;
            font-family: Arial, sans-serif;
        }

        .page-wrapper {
            max-width: 1100px;
            margin: 40px auto;
        }

        .page-header {
            background: linear-gradient(135deg, #0d6efd, #084298);
            color: white;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 12px 30px rgba(13, 110, 253, 0.18);
            margin-bottom: 24px;
        }

        .page-header h2 {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
        }

        .form-card {
            background: white;
            border: none;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.07);
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #0d6efd;
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }

        .form-label {
            font-weight: 600;
            color: #344054;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            min-height: 48px;
            border-radius: 10px;
            border: 1px solid #dce2ea;
        }

        textarea.form-control {
            min-height: auto;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.10);
        }

        .btn-back {
            border-radius: 10px;
            padding: 10px 18px;
        }

        .btn-save {
            min-height: 50px;
            border-radius: 10px;
            font-weight: 700;
            padding-left: 30px;
            padding-right: 30px;
        }

        .required {
            color: #dc3545;
        }

        .alert {
            border-radius: 12px;
        }

        @media (max-width: 768px) {
            .page-wrapper {
                margin: 20px auto;
            }

            .page-header,
            .form-card {
                border-radius: 14px;
            }

            .form-card {
                padding: 20px;
            }

            .btn-save {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="container page-wrapper">

    <div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">

        <div>
            <h2>GRAND SPEED NETWORK</h2>
            <p>Create and register a new complaint</p>
        </div>

        <a href="dashboard.php" class="btn btn-light btn-back">
            Back to Dashboard
        </a>

    </div>

    <?php if (isset($success)) { ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php } ?>

    <?php if (isset($error)) { ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php } ?>

    <form method="POST" class="form-card">

        <div class="section-title">
            Complaint Information
        </div>

        <div class="row">

            <div class="col-md-4 mb-3">
                <label class="form-label">
                    Job <span class="required">*</span>
                </label>

                <select name="job_id" class="form-select" required>
                    <option value="">Select Job</option>

                    <?php while ($job = mysqli_fetch_assoc($jobs)) { ?>
                        <option value="<?php echo (int)$job['id']; ?>">
                            <?php echo htmlspecialchars($job['job_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">
                    Vendor
                </label>

                <select name="vendor_id" class="form-select">
                    <option value="">Select Vendor</option>

                    <?php while ($vendor = mysqli_fetch_assoc($vendors)) { ?>
                        <option value="<?php echo (int)$vendor['id']; ?>">
                            <?php echo htmlspecialchars($vendor['vendor_name']); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="form-label">
                    Complaint Date <span class="required">*</span>
                </label>

                <input
                    type="date"
                    name="complaint_date"
                    class="form-control"
                    required
                >
            </div>

        </div>

        <div class="row">

            <div class="col-md-6 mb-3">
                <label class="form-label">
                    Tracking Number <span class="required">*</span>
                </label>

                <input
                    type="text"
                    name="tracking_number"
                    class="form-control"
                    placeholder="Enter tracking number"
                    required
                >
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">
                    Secondary Tracking Number
                </label>

                <input
                    type="text"
                    name="secondary_tracking_number"
                    class="form-control"
                    placeholder="Enter secondary tracking number"
                >
            </div>

        </div>

        <div class="section-title mt-3">
            Customer Details
        </div>

        <div class="row">

            <div class="col-md-6 mb-3">
                <label class="form-label">
                    Customer Name <span class="required">*</span>
                </label>

                <input
                    type="text"
                    name="customer_name"
                    class="form-control"
                    placeholder="Enter customer name"
                    required
                >
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">
                    Mobile Number <span class="required">*</span>
                </label>

                <input
                    type="text"
                    name="mobile"
                    class="form-control"
                    placeholder="Enter mobile number"
                    required
                >
            </div>

        </div>

        <div class="mb-3">
            <label class="form-label">Address</label>

            <textarea
                name="address"
                rows="3"
                class="form-control"
                placeholder="Enter customer address"
            ></textarea>
        </div>

        <div class="section-title mt-3">
            Complaint Details
        </div>

        <div class="row">

            <div class="col-md-6 mb-3">
                <label class="form-label">
                    Complaint Type <span class="required">*</span>
                </label>

                <select name="complaint_type" class="form-select" required>
                    <option value="">Select Type</option>
                    <option value="Shipment Delay">Shipment Delay</option>
                    <option value="Lost Shipment">Lost Shipment</option>
                    <option value="Damaged Shipment">Damaged Shipment</option>
                    <option value="Wrong Delivery">Wrong Delivery</option>
                    <option value="POD Required">POD Required</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label">
                    Priority <span class="required">*</span>
                </label>

                <select name="priority" class="form-select" required>
                    <option value="Normal">Normal</option>
                    <option value="Urgent">Urgent</option>
                    <option value="Most Urgent">Most Urgent</option>
                </select>
            </div>

        </div>

        <div class="mb-4">
            <label class="form-label">Description</label>

            <textarea
                name="description"
                rows="4"
                class="form-control"
                placeholder="Enter complaint description"
            ></textarea>
        </div>

        <div class="d-flex justify-content-end">
            <button
                type="submit"
                name="save"
                class="btn btn-primary btn-save"
            >
                Save Complaint
            </button>
        </div>

    </form>

</div>

</body>
</html>