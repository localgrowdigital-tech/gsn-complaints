<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit();
}

$success = '';
$error = '';

if (isset($_POST['add_vendor'])) {
    $vendor_name = mysqli_real_escape_string(
        $conn,
        trim($_POST['vendor_name'] ?? '')
    );

    if ($vendor_name === '') {
        $error = 'Vendor name is required.';
    } else {
        $insert = mysqli_query($conn, "
            INSERT INTO vendors (vendor_name, status)
            VALUES ('$vendor_name', 'Active')
        ");

        if ($insert) {
            $success = 'Vendor added successfully.';
        } else {
            $error = 'Unable to add vendor: ' . mysqli_error($conn);
        }
    }
}

if (isset($_POST['update_status'])) {
    $vendor_id = (int)($_POST['vendor_id'] ?? 0);
    $status = $_POST['status'] ?? 'Active';

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = 'Active';
    }

    mysqli_query($conn, "
        UPDATE vendors
        SET status='$status'
        WHERE id=$vendor_id
    ");

    $success = 'Vendor status updated.';
}

$vendors = mysqli_query($conn, "
    SELECT id, vendor_name, status, created_at
    FROM vendors
    ORDER BY vendor_name ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vendors - GRAND SPEED NETWORK</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f4f7fb;
        }

        .card {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
        }

        .brand {
            font-weight: 800;
            letter-spacing: .5px;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid px-4">
        <span class="navbar-brand brand">
            GSN GRAND SPEED NETWORK
        </span>

        <a href="dashboard.php" class="btn btn-light btn-sm">
            Dashboard
        </a>
    </div>
</nav>

<main class="container py-4">

    <div class="mb-4">
        <h2 class="fw-bold mb-1">Vendor Management</h2>
        <p class="text-muted mb-0">
            Add vendors and manage their status.
        </p>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="fw-bold mb-3">Add Vendor</h5>

            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-9">
                        <label class="form-label">Vendor Name</label>

                        <input
                            type="text"
                            name="vendor_name"
                            class="form-control"
                            placeholder="Enter vendor name"
                            required
                        >
                    </div>

                    <div class="col-md-3 d-grid align-items-end">
                        <button
                            type="submit"
                            name="add_vendor"
                            class="btn btn-primary"
                        >
                            Add Vendor
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="fw-bold mb-3">Vendor List</h5>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Vendor Name</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($vendors && mysqli_num_rows($vendors) > 0): ?>

                        <?php while ($vendor = mysqli_fetch_assoc($vendors)): ?>
                            <tr>
                                <form method="POST">
                                    <td>
                                        <?php echo (int)$vendor['id']; ?>
                                    </td>

                                    <td class="fw-semibold">
                                        <?php echo htmlspecialchars($vendor['vendor_name']); ?>
                                    </td>

                                    <td>
                                        <select
                                            name="status"
                                            class="form-select form-select-sm"
                                        >
                                            <option
                                                value="Active"
                                                <?php echo $vendor['status'] === 'Active' ? 'selected' : ''; ?>
                                            >
                                                Active
                                            </option>

                                            <option
                                                value="Inactive"
                                                <?php echo $vendor['status'] === 'Inactive' ? 'selected' : ''; ?>
                                            >
                                                Inactive
                                            </option>
                                        </select>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($vendor['created_at']); ?>
                                    </td>

                                    <td>
                                        <input
                                            type="hidden"
                                            name="vendor_id"
                                            value="<?php echo (int)$vendor['id']; ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="update_status"
                                            class="btn btn-success btn-sm"
                                        >
                                            Update
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endwhile; ?>

                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No vendors found.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

</body>
</html>