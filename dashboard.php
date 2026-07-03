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
$progress = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM complaints WHERE status='In Progress'"))['total'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f6f9;
        }
        .brand {
            font-weight: 700;
            letter-spacing: 1px;
        }
        .stat-card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .stat-number {
            font-size: 42px;
            font-weight: 700;
        }
        .action-btn {
            border-radius: 12px;
            padding: 12px 20px;
            font-weight: 600;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand brand">GRAND SPEED NETWORK</span>
        <a href="logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container py-4">

    <div class="mb-4">
        <h3 class="brand">Complaint Dashboard</h3>
        <p class="text-muted mb-0">Welcome, <b><?php echo $_SESSION['admin']; ?></b></p>
    </div>

    <div class="row g-3">

        <div class="col-12 col-md-3">
            <div class="card stat-card text-bg-primary">
                <div class="card-body text-center">
                    <div class="stat-number"><?php echo $total; ?></div>
                    <div>Total Complaints</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-3">
            <div class="card stat-card text-bg-warning">
                <div class="card-body text-center">
                    <div class="stat-number"><?php echo $open; ?></div>
                    <div>Open Complaints</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-3">
            <div class="card stat-card text-bg-info">
                <div class="card-body text-center">
                    <div class="stat-number"><?php echo $progress; ?></div>
                    <div>In Progress</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-3">
            <div class="card stat-card text-bg-success">
                <div class="card-body text-center">
                    <div class="stat-number"><?php echo $closed; ?></div>
                    <div>Closed Complaints</div>
                </div>
            </div>
        </div>

    </div>

    <div class="card mt-4 stat-card">
        <div class="card-body">
            <h5 class="mb-3">Quick Actions</h5>

            <div class="d-grid gap-2 d-md-flex">
                <a href="add-complaint.php" class="btn btn-primary action-btn">Add Complaint</a>
                <a href="view-complaints.php" class="btn btn-success action-btn">View Complaints</a>
                <a href="jobs.php" class="btn btn-warning action-btn">
                   Jobs
                </a>

                <a href="logout.php" class="btn btn-danger action-btn">
                   Logout
                </a>
              
            </div>
        </div>
    </div>

</div>

</body>
</html>