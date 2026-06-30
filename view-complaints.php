<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}

if (isset($_POST['update'])) {

    $id = $_POST['id'];
    $status = $_POST['status'];
    $closing_date = $_POST['closing_date'];

    mysqli_query($conn, "UPDATE complaints SET
    status='$status',
    closing_date='$closing_date'
    WHERE id='$id'");
}

$result = mysqli_query($conn, "SELECT * FROM complaints ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Complaints</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body class="bg-light">

<div class="container mt-4">

<h2 class="text-center text-primary">GRAND SPEED NETWORK</h2>
<h4 class="text-center mb-4">View Complaints</h4>

<a href="dashboard.php" class="btn btn-secondary mb-3">Dashboard</a>

<div class="table-responsive">

<table class="table table-bordered table-striped table-hover">

<thead class="table-dark">

<tr>

<th>ID</th>
<th>Complaint ID</th>
<th>Date</th>
<th>Tracking</th>
<th>2nd Tracking</th>
<th>Customer</th>
<th>Mobile</th>
<th>Status</th>
<th>Closing Date</th>
<th>Remarks</th>
<th>Action</th>

</tr>

</thead>

<tbody>

<?php while($row=mysqli_fetch_assoc($result)){ ?>

<tr>

<form method="POST">

<td><?php echo $row['id']; ?></td>

<td><?php echo $row['complaint_id']; ?></td>

<td><?php echo $row['complaint_date']; ?></td>

<td><?php echo $row['tracking_number']; ?></td>

<td><?php echo $row['secondary_tracking_number']; ?></td>

<td><?php echo $row['customer_name']; ?></td>

<td><?php echo $row['mobile']; ?></td>

<td>

<select name="status" class="form-select">

<option value="Open" <?php if($row['status']=="Open") echo "selected"; ?>>Open</option>

<option value="In Progress" <?php if($row['status']=="In Progress") echo "selected"; ?>>In Progress</option>

<option value="Resolved" <?php if($row['status']=="Resolved") echo "selected"; ?>>Resolved</option>

<option value="Closed" <?php if($row['status']=="Closed") echo "selected"; ?>>Closed</option>

</select>

</td>

<td>

<input
type="date"
name="closing_date"
class="form-control"
value="<?php echo $row['closing_date']; ?>">

</td>

<td>

<a href="remarks.php?id=<?php echo $row['id']; ?>" class="btn btn-primary btn-sm">
Remarks
</a>

</td>

<td>

<input
type="hidden"
name="id"
value="<?php echo $row['id']; ?>">

<button
type="submit"
name="update"
class="btn btn-success btn-sm">

Update

</button>

</td>

</form>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</body>
</html>