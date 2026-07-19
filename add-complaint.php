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

<?php
$pageTitle = 'Add Complaint - GSN';
$pageHeading = 'Add Complaint';

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="page-card">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">

        <div>
            <h4 class="mb-1">Create New Complaint</h4>
            <p class="text-muted mb-0">
                Enter complaint and customer information
            </p>
        </div>

        <a href="dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Back to Dashboard
        </a>

    </div>

    <?php if (isset($success)) { ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php } ?>

    <?php if (isset($error)) { ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php } ?>

    <form method="POST">

        <h6 class="text-primary fw-bold border-bottom pb-2 mb-3">
            Complaint Information
        </h6>

        <div class="row">

            <div class="col-lg-4 col-md-6 mb-3">
                <label class="form-label fw-semibold">Job *</label>

                <select name="job_id" class="form-select" required>
                    <option value="">Select Job</option>

                    <?php while ($job = mysqli_fetch_assoc($jobs)) { ?>
                        <option value="<?php echo (int)$job['id']; ?>">
                            <?php echo htmlspecialchars($job['job_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="col-lg-4 col-md-6 mb-3">
                <label class="form-label fw-semibold">Vendor</label>

                <select name="vendor_id" class="form-select">
                    <option value="">Select Vendor</option>

                    <?php while ($vendor = mysqli_fetch_assoc($vendors)) { ?>
                        <option value="<?php echo (int)$vendor['id']; ?>">
                            <?php echo htmlspecialchars($vendor['vendor_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php } ?>
                </select>
            </div>

            <div class="col-lg-4 col-md-6 mb-3">
                <label class="form-label fw-semibold">Complaint Date *</label>

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
                <label class="form-label fw-semibold">Tracking Number *</label>

                <input
                    type="text"
                    name="tracking_number"
                    class="form-control"
                    placeholder="Enter tracking number"
                    required
                >
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">
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

        <h6 class="text-primary fw-bold border-bottom pb-2 mt-3 mb-3">
            Customer Information
        </h6>

        <div class="row">

            <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">Customer Name *</label>

                <input
                    type="text"
                    name="customer_name"
                    class="form-control"
                    placeholder="Enter customer name"
                    required
                >
            </div>

            <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">Mobile Number *</label>

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
            <label class="form-label fw-semibold">Address</label>

            <textarea
                name="address"
                rows="3"
                class="form-control"
                placeholder="Enter customer address"
            ></textarea>
        </div>

        <h6 class="text-primary fw-bold border-bottom pb-2 mt-3 mb-3">
            Complaint Details
        </h6>

        <div class="row">

            <div class="col-md-6 mb-3">
                <label class="form-label fw-semibold">Complaint Type *</label>

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
                <label class="form-label fw-semibold">Priority *</label>

                <select name="priority" class="form-select" required>
                    <option value="Normal">Normal</option>
                    <option value="Urgent">Urgent</option>
                    <option value="Most Urgent">Most Urgent</option>
                </select>
            </div>

        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">Description</label>

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
                class="btn btn-primary px-4 py-2"
            >
                <i class="bi bi-check-circle"></i>
                Save Complaint
            </button>

        </div>

    </form>

</div>

<?php include 'includes/footer.php'; ?>