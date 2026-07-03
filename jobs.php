<?php
session_start();
include 'db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: index.php");
    exit();
}

if (isset($_POST['add_job'])) {
    $job_name = mysqli_real_escape_string($conn, $_POST['job_name']);
    $job_code = mysqli_real_escape_string($conn, $_POST['job_code']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    mysqli_query($conn, "INSERT INTO jobs (job_name, job_code, status) VALUES ('$job_name', '$job_code', '$status')");
    header("Location: jobs.php");
    exit();
}

if (isset($_POST['update_job'])) {
    $id = $_POST['id'];
    $job_name = mysqli_real_escape_string($conn, $_POST['job_name']);
    $job_code = mysqli_real_escape_string($conn, $_POST['job_code']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);

    mysqli_query($conn, "UPDATE jobs SET job_name='$job_name', job_code='$job_code', status='$status' WHERE id='$id'");
    header("Location: jobs.php");
    exit();
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    mysqli_query($conn, "DELETE FROM jobs WHERE id='$id'");
    header("Location: jobs.php");
    exit();
}

$jobs = mysqli_query($conn, "SELECT * FROM jobs ORDER BY id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Jobs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background: #f4f6f9; }
        .brand { font-weight: 700; letter-spacing: 1px; }
        .card {
            border: 0;
            border-radius: 18px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container">
        <span class="navbar-brand brand">GRAND SPEED NETWORK</span>
        <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
    </div>
</nav>

<div class="container py-4">

    <h3 class="brand mb-1">Jobs</h3>
    <p class="text-muted">Manage bank/client jobs</p>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            Add New Job
        </div>

        <div class="card-body">
            <form method="POST" class="row g-3">

                <div class="col-md-5">
                    <label class="form-label">Job Name</label>
                    <input type="text" name="job_name" class="form-control" placeholder="HDFC Stationery" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Job Code</label>
                    <input type="text" name="job_code" class="form-control" placeholder="HDFC">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_job" class="btn btn-success w-100">
                        Add Job
                    </button>
                </div>

            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-dark text-white">
            Job List
        </div>

        <div class="card-body">
            <div class="table-responsive">

                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Job Name</th>
                            <th>Job Code</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (mysqli_num_rows($jobs) > 0) { ?>

                        <?php while($row = mysqli_fetch_assoc($jobs)) { ?>

                            <tr>
                                <form method="POST">

                                    <td><?php echo $row['id']; ?></td>

                                    <td>
                                        <input type="text" name="job_name" class="form-control form-control-sm"
                                               value="<?php echo $row['job_name']; ?>" required>
                                    </td>

                                    <td>
                                        <input type="text" name="job_code" class="form-control form-control-sm"
                                               value="<?php echo $row['job_code']; ?>">
                                    </td>

                                    <td>
                                        <select name="status" class="form-select form-select-sm">
                                            <option value="Active" <?php if($row['status']=="Active") echo "selected"; ?>>Active</option>
                                            <option value="Inactive" <?php if($row['status']=="Inactive") echo "selected"; ?>>Inactive</option>
                                        </select>
                                    </td>

                                    <td><?php echo $row['created_at']; ?></td>

                                    <td>
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">

                                        <button type="submit" name="update_job" class="btn btn-success btn-sm">
                                            Update
                                        </button>

                                        <a href="jobs.php?delete=<?php echo $row['id']; ?>"
                                           onclick="return confirm('Delete this job?')"
                                           class="btn btn-danger btn-sm">
                                            Delete
                                        </a>
                                    </td>

                                </form>
                            </tr>

                        <?php } ?>

                    <?php } else { ?>

                        <tr>
                            <td colspan="6" class="text-center text-muted">No jobs found</td>
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