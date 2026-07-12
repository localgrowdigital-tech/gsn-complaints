<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: view-complaints.php");
    exit();
}

$id = $_GET['id'];

$complaint = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM complaints WHERE id='$id'"));

if (!$complaint) {
    die("Complaint not found");
}

if (isset($_POST['save_remark'])) {

    $remark = mysqli_real_escape_string($conn, $_POST['remark']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $closing_date = mysqli_real_escape_string($conn, $_POST['closing_date']);

    $secondary_tracking_number = mysqli_real_escape_string(
        $conn,
        $_POST['secondary_tracking_number'] ?? ''
    );

    mysqli_query($conn, "
        INSERT INTO complaint_remarks
        (complaint_id, remark, status)
        VALUES
        ('$id', '$remark', '$status')
    ");

    mysqli_query($conn, "
        UPDATE complaints SET
            status='$status',
            secondary_tracking_number='$secondary_tracking_number',
            closing_date=" . ($closing_date !== '' ? "'$closing_date'" : "NULL") . "
        WHERE id='$id'
    ");

    header("Location: remarks.php?id=$id");
    exit();
}

$remarks = mysqli_query($conn, "SELECT * FROM complaint_remarks WHERE complaint_id='$id' ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Complaint Remarks</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<div class="container mt-4 mb-5">

    <h2 class="text-center text-primary">GRAND SPEED NETWORK</h2>
    <h4 class="text-center mb-4">Complaint Remarks</h4>

    <a href="view-complaints.php" class="btn btn-secondary mb-3">Back</a>

    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">
            Complaint Details
        </div>

        <div class="card-body">
            <p><b>Complaint ID:</b> <?php echo $complaint['complaint_id']; ?></p>
            <p><b>Complaint Date:</b> <?php echo $complaint['complaint_date']; ?></p>
            <p><b>Tracking Number:</b> <?php echo $complaint['tracking_number']; ?></p>
            <p><b>Secondary Tracking:</b> <?php echo $complaint['secondary_tracking_number']; ?></p>
            <p><b>Customer:</b> <?php echo $complaint['customer_name']; ?></p>
            <p><b>Mobile:</b> <?php echo $complaint['mobile']; ?></p>
            <p><b>Address:</b> <?php echo $complaint['address']; ?></p>
            <p><b>Complaint Type:</b> <?php echo $complaint['complaint_type']; ?></p>
            <p><b>Description:</b> <?php echo $complaint['description']; ?></p>
            <p><b>Current Status:</b> <?php echo $complaint['status']; ?></p>
            <p><b>Closing Date:</b> <?php echo $complaint['closing_date']; ?></p>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">
            Add Remark / Update Status
        </div>

        <div class="card-body">

            <form method="POST">

                <label class="form-label">Status</label>
                <select name="status" class="form-select mb-3" required>
                    <option value="Open" <?php if($complaint['status']=="Open") echo "selected"; ?>>Open</option>
                    <option value="In Progress" <?php if($complaint['status']=="In Progress") echo "selected"; ?>>In Progress</option>
                    <option value="Resolved" <?php if($complaint['status']=="Resolved") echo "selected"; ?>>Resolved</option>
                    <option value="Closed" <?php if($complaint['status']=="Closed") echo "selected"; ?>>Closed</option>
                </select>
                <label class="form-label">Secondary Tracking Number</label>
<input
    type="text"
    name="secondary_tracking_number"
    class="form-control mb-3"
    placeholder="Enter new docket / redispatch tracking number"
    value="<?php echo htmlspecialchars($complaint['secondary_tracking_number'] ?? ''); ?>"
>
                
                <label class="form-label">Closing Date</label>
                <input type="date" name="closing_date" class="form-control mb-3" value="<?php echo $complaint['closing_date']; ?>">

                <label class="form-label">Remark</label>
                <textarea name="remark" rows="4" class="form-control mb-3" required></textarea>

                <button type="submit" name="save_remark" class="btn btn-success">
                    Save Remark
                </button>

            </form>

        </div>
    </div>

    <div class="card shadow">
        <div class="card-header bg-success text-white">
            Remark History
        </div>

        <div class="card-body">

            <?php if (mysqli_num_rows($remarks) > 0) { ?>

                <?php while($row = mysqli_fetch_assoc($remarks)) { ?>

                    <div class="border rounded p-3 mb-3 bg-white">
                        <p><b>Status:</b> <?php echo $row['status']; ?></p>
                        <p><b>Remark:</b> <?php echo $row['remark']; ?></p>
                        <p><b>Date:</b> <?php echo $row['remark_date']; ?></p>
                    </div>

                <?php } ?>

            <?php } else { ?>

                <p>No remarks added yet.</p>

            <?php } ?>

        </div>
    </div>

</div>

</body>
</html>