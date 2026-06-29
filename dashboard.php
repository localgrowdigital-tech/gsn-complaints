<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}

$total = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints"))['total'];
$open = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE status='Open'"))['total'];
$closed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE status='Closed'"))['total'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

</head>
<body class="bg-light">

<div class="container mt-5">

    <h2 class="text-center text-primary">GRAND SPEED NETWORK</h2>
    <h5 class="text-center mb-4">Complaint Dashboard</h5>

    <div class="alert alert-success">
        Welcome, <b><?php echo $_SESSION['admin']; ?></b>
    </div>

    <div class="row">

        <div class="col-md-4">
            <div class="card text-bg-primary mb-3">
                <div class="card-body text-center">
                    <h1><?php echo $total; ?></h1>
                    <h5>Total Complaints</h5>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card text-bg-warning mb-3">
                <div class="card-body text-center">
                    <h1><?php echo $open; ?></h1>
                    <h5>Open Complaints</h5>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card text-bg-success mb-3">
                <div class="card-body text-center">
                    <h1><?php echo $closed; ?></h1>
                    <h5>Closed Complaints</h5>
                </div>
            </div>
        </div>

    </div>

    <div class="mt-4">

        <a href="add-complaint.php" class="btn btn-primary">
            Add Complaint
        </a>

        <a href="view-complaints.php" class="btn btn-success">
            View Complaints
        </a>

        <a href="logout.php" class="btn btn-danger">
            Logout
        </a>

    </div>

</div>

</body>
</html>