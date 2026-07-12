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

    mysqli_query($conn, "UPDATE complaints SET status='$status', closing_date='$closing_date' WHERE id='$id'");
}

$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';
$job = isset($_GET['job']) ? $_GET['job'] : "";

$where = "WHERE 1";
if($job != ""){
    $where .= " AND complaints.job_id='$job'";
}

if ($search != '') {
    $where .= " AND (
        complaint_id LIKE '%$search%' OR
        tracking_number LIKE '%$search%' OR
        customer_name LIKE '%$search%' OR

        mobile LIKE '%$search%'
    )";
}

if ($filter != '' && $filter != 'All') {
    $where .= " AND complaints.status='$filter'";
}

if ($priority != "") {
    $where .= " AND complaints.priority='$priority'";
}

if ($today == "1") {
    $where .= " AND DATE(complaints.complaint_date)=CURDATE()";
}

if (isset($_GET['export'])) {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=complaints.xls");

    echo "ID\tComplaint ID\tDate\tJob\tTracking\tCustomer\tMobile\tStatus\tClosing Date\n";

    $export = mysqli_query($conn, "
SELECT complaints.*, jobs.job_name
FROM complaints
LEFT JOIN jobs ON complaints.job_id = jobs.id
$where
ORDER BY complaints.id DESC
");
    while ($row = mysqli_fetch_assoc($export)) {
        echo $row['id']."\t".$row['complaint_id']."\t".$row['complaint_date']."\t".$row['job_name']."\t".$row['tracking_number']."\t".$row['customer_name']."\t".$row['mobile']."\t".$row['status']."\t".$row['closing_date']."\n";
    }
    exit();
}

$result = mysqli_query($conn, "
SELECT complaints.*, jobs.job_name
FROM complaints
LEFT JOIN jobs ON complaints.job_id = jobs.id
$where
ORDER BY complaints.id DESC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Complaints</title>
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
        .card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .table th {
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            white-space: nowrap;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-primary no-print">
    <div class="container">
        <span class="navbar-brand brand">GRAND SPEED NETWORK</span>
        <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="brand mb-0">View Complaints</h3>
            <p class="text-muted mb-0">Search, filter, update and manage complaints</p>
        </div>
    </div>

    <div class="card mb-4 no-print">
        <div class="card-body">

            <form method="GET" class="row g-3">

                <div class="col-12 col-md-5">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search Complaint ID, Tracking, Customer, Mobile"
                           value="<?php echo $search; ?>">
                </div>

                <div class="col-12 col-md-5">
    <input type="text" name="search" class="form-control"
           placeholder="Search Complaint ID, Tracking, Customer, Mobile"
           value="<?php echo $search; ?>">
</div>

<!-- Job Dropdown START -->
<div class="col-12 col-md-2">
    <select name="job" class="form-select">
        <option value="">All Jobs</option>

        <?php
$jobsList = mysqli_query($conn, "SELECT * FROM jobs ORDER BY job_name ASC");
while($jobRow = mysqli_fetch_assoc($jobsList)) {
?>
    <option value="<?php echo $jobRow['id']; ?>" <?php if($job == $jobRow['id']) echo "selected"; ?>>
        <?php echo $jobRow['job_name']; ?>
    </option>
<?php } ?>
    </select>
</div>
<!-- Job Dropdown END -->

<!-- Status Dropdown START -->
<div class="col-12 col-md-2">
    <select name="status" class="form-select">
        <option value="All">All Status</option>
        <option value="Open" <?php if($filter=="Open") echo "selected"; ?>>Open</option>
        <option value="In Progress" <?php if($filter=="In Progress") echo "selected"; ?>>In Progress</option>
        <option value="Resolved" <?php if($filter=="Resolved") echo "selected"; ?>>Resolved</option>
        <option value="Closed" <?php if($filter=="Closed") echo "selected"; ?>>Closed</option>
    </select>
</div>
<!-- Status Dropdown END -->

<div class="col-12 col-md-3 d-flex gap-2">
    <button class="btn btn-primary" type="submit">Search</button>
    <a href="view-complaints.php" class="btn btn-secondary">Reset</a>
    <a href="view-complaints.php?search=<?php echo $search; ?>&job=<?php echo $job; ?>&status=<?php echo $filter; ?>&export=1"
    <button type="button" onclick="window.print()" class="btn btn-dark">Print</button>
</div>

            </form>

        </div>
    </div>

    <div class="card">
        <div class="card-body">

            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Complaint ID</th>
                        <th>Date</th>
                        <th>Job</th>
                        <th>Tracking</th>
                        <th>Customer</th>
                        <th>Mobile</th>
                        <th>Status</th>
                        <th>Closing Date</th>
                        <th>Details</th>
                        <th>Remarks</th>
                        <th>Action</th>
                        
                    </tr>
                    </thead>

                    <tbody>

                    <?php if (mysqli_num_rows($result) > 0) { ?>

                        <?php while($row = mysqli_fetch_assoc($result)) { ?>

                            <tr>
                                <form method="POST">

                                    <td><?php echo $row['id']; ?></td>
                                    <td><b><?php echo $row['complaint_id']; ?></b></td>
                                    <td><?php echo $row['complaint_date']; ?></td>
                                    <td><?php echo $row['job_name'] ? $row['job_name'] : 'No Job'; ?></td>
                                    <td><?php echo $row['job_name']; ?></td>
                                    <td><?php echo $row['tracking_number']; ?></td>
                                    <td><?php echo $row['customer_name']; ?></td>
                                    <td><?php echo $row['mobile']; ?></td>

                                    <td>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="Open" <?php if($row['status']=="Open") echo "selected"; ?>>Open</option>
                                            <option value="In Progress" <?php if($row['status']=="In Progress") echo "selected"; ?>>In Progress</option>
                                            <option value="Resolved" <?php if($row['status']=="Resolved") echo "selected"; ?>>Resolved</option>
                                            <option value="Closed" <?php if($row['status']=="Closed") echo "selected"; ?>>Closed</option>
                                        </select>
                                    </td>

                                    <td>
                                        <input type="date" name="closing_date" class="form-control form-control-sm"
                                               value="<?php echo $row['closing_date']; ?>">
                                    </td>

                                    <td class="no-print">

                                    <a href="complaint-details.php?id=<?php echo $row['id']; ?>" class="btn btn-info btn-sm mb-1">
                                            Details
                                        </a>
                                    </td>

                                    <td class="no-print">
                                        <a href="remarks.php?id=<?php echo $row['id']; ?>" class="btn btn-primary btn-sm">
                                            Remarks
                                        </a>
                                    </td>

                                    <td class="no-print">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="update" class="btn btn-success btn-sm">
                                            Update
                                        </button>
                                    </td>

                                </form>
                            </tr>

                        <?php } ?>

                    <?php } else { ?>

                        <tr>
                            <td colspan="10" class="text-center text-muted">No complaints found</td>
                        </tr>

                    <?php } ?>

                    </tbody>
                </table>

            </div>

        </div>
    </div>

</div>

</body>
</html>