<?php
session_start();
include 'db.php';

$jobs = mysqli_query($conn, "SELECT * FROM jobs WHERE status='Active' ORDER BY job_name ASC");

if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}

if (isset($_POST['save'])) {
    $complaint_id = "GSN" . rand(10000, 99999);
    $complaint_date = $_POST['complaint_date'];
    $tracking_number = $_POST['tracking_number'];
    $secondary_tracking_number = $_POST['secondary_tracking_number'];
    $customer_name = $_POST['customer_name'];
    $mobile = $_POST['mobile'];
    $address = $_POST['address'];
    $complaint_type = $_POST['complaint_type'];
    $description = $_POST['description'];
    $status = "Open";
    $job_id = $_POST['job_id'];

$sql = "INSERT INTO complaints
(complaint_id, job_id, complaint_date, tracking_number, secondary_tracking_number, customer_name, mobile, address, complaint_type, description, status)
VALUES
('$complaint_id', '$job_id', '$complaint_date', '$tracking_number', '$secondary_tracking_number', '$customer_name', '$mobile', '$address', '$complaint_type', '$description', '$status')";

if (mysqli_query($conn, $sql)) {
    $success = "Complaint Added Successfully. Complaint ID: " . $complaint_id;
} else {
    $error = "Error: " . mysqli_error($conn);
}

}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Complaint</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-4 mb-5">

    <h2 class="text-center text-primary">GRAND SPEED NETWORK</h2>
    <h4 class="text-center mb-4">Add Complaint</h4>

    <a href="dashboard.php" class="btn btn-secondary mb-3">Back to Dashboard</a>

    <?php
    if (isset($success)) {
        echo "<div class='alert alert-success'>$success</div>";
    }
    if (isset($error)) {
        echo "<div class='alert alert-danger'>$error</div>";
    }
    ?>

    <form method="POST" class="card p-4 shadow">

        <label class="form-label">Complaint Date</label>
        <input type="date" name="complaint_date" required class="form-control mb-3">

        <label class="form-label">Tracking Number</label>
        <input type="text" name="tracking_number" required class="form-control mb-3">

        <label class="form-label">Secondary Tracking Number</label>
        <input type="text" name="secondary_tracking_number" class="form-control mb-3">

        <label class="form-label">Customer Name</label>
        <input type="text" name="customer_name" required class="form-control mb-3">

        <label class="form-label">Mobile Number</label>
        <input type="text" name="mobile" required class="form-control mb-3">

        <label class="form-label">Address</label>
        <textarea name="address" rows="3" class="form-control mb-3"></textarea>

        <label class="form-label">Complaint Type</label>
        <select name="complaint_type" required class="form-control mb-3">
            <option value="">Select Type</option>
            <option value="Shipment Delay">Shipment Delay</option>
            <option value="Lost Shipment">Lost Shipment</option>
            <option value="Damaged Shipment">Damaged Shipment</option>
            <option value="Wrong Delivery">Wrong Delivery</option>
            <option value="POD Required">POD Required</option>
            <option value="Other">Other</option>
        </select>

        <label class="form-label">Description</label>
        <textarea name="description" rows="4" class="form-control mb-3"></textarea>

        <button type="submit" name="save" class="btn btn-primary">
            Save Complaint
        </button>

    </form>

</div>

</body>
</html>