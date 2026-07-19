<?php
session_start();
include 'db.php';

require_once 'includes/timeline.php';

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

$remarks = mysqli_query(
    $conn,
    "SELECT * FROM complaint_remarks
     WHERE complaint_id='$id'
     ORDER BY id DESC"
);

$timeline = mysqli_query(
    $conn,
    "SELECT *
     FROM complaint_timeline
     WHERE complaint_id='$id'
     ORDER BY created_at DESC"
);

if (!$timeline) {
    die("Timeline query error: " . mysqli_error($conn));
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Complaint Details</title>
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
        .label {
            font-weight: 700;
            color: #555;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
            }
            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark bg-primary no-print">
    <div class="container">
        <span class="navbar-brand brand">GRAND SPEED NETWORK</span>
        <a href="view-complaints.php" class="btn btn-light btn-sm">Back</a>
    </div>
</nav>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="brand mb-0">Complaint Details</h3>
            <p class="text-muted mb-0">Full complaint information and remark history</p>
        </div>

        <div class="no-print">
            <button onclick="window.print()" class="btn btn-dark btn-sm">Print</button>
            <a href="remarks.php?id=<?php echo $complaint['id']; ?>" class="btn btn-primary btn-sm">Add Remark</a>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            Complaint Information
        </div>

        <div class="card-body">

            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="label">Complaint ID</div>
                    <div><?php echo $complaint['complaint_id']; ?></div>
                </div>

                <div class="col-md-4">
                    <div class="label">Complaint Date</div>
                    <div><?php echo $complaint['complaint_date']; ?></div>
                </div>

                <div class="col-md-4">
                    <div class="label">Status</div>
                    <div>
                        <span class="badge bg-primary">
                            <?php echo $complaint['status']; ?>
                        </span>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="label">Tracking Number</div>
                    <div><?php echo $complaint['tracking_number']; ?></div>
                </div>

                <div class="col-md-4">
                    <div class="label">Secondary Tracking Number</div>
                    <div><?php echo $complaint['secondary_tracking_number']; ?></div>
                </div>

                <div class="col-md-4">
                    <div class="label">Closing Date</div>
                    <div><?php echo $complaint['closing_date']; ?></div>
                </div>
            </div>

            <hr>

            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="label">Customer Name</div>
                    <div><?php echo $complaint['customer_name']; ?></div>
                </div>

                <div class="col-md-4">
                    <div class="label">Mobile</div>
                    <div><?php echo $complaint['mobile']; ?></div>
                </div>

                <div class="col-md-4">
                    <div class="label">Complaint Type</div>
                    <div><?php echo $complaint['complaint_type']; ?></div>
                </div>
            </div>

            <hr>

            <div class="mb-3">
                <div class="label">Address</div>
                <div><?php echo nl2br($complaint['address']); ?></div>
            </div>

            <div class="mb-3">
                <div class="label">Description</div>
                <div><?php echo nl2br($complaint['description']); ?></div>
            </div>

        </div>
    </div>

    <div class="card">
        <div class="card-header bg-success text-white">
            Remark History
        </div>

        <div class="card-body">

            <?php if (mysqli_num_rows($remarks) > 0) { ?>

                <?php while($row = mysqli_fetch_assoc($remarks)) { ?>

                    <div class="border rounded p-3 mb-3 bg-white">
                        <div class="d-flex justify-content-between">
                            <b>Status: <?php echo $row['status']; ?></b>
                            <span class="text-muted"><?php echo $row['remark_date']; ?></span>
                        </div>

                        <p class="mt-2 mb-0">
                            <?php echo nl2br($row['remark']); ?>
                        </p>
                    </div>

                <?php } ?>

            <?php } else { ?>

                <p class="text-muted">No remarks found.</p>

            <?php } ?>

        </div>
    </div>

<div class="card mt-4">
    <div class="card-header bg-dark text-white">
        📜 Complaint Timeline
    </div>

    <div class="card-body">

        <?php if (mysqli_num_rows($timeline) > 0) { ?>

            <?php while ($row = mysqli_fetch_assoc($timeline)) { ?>

                <div class="border-start border-4 border-primary ps-3 mb-4">

                    <h6 class="fw-bold mb-1">
                        <?php echo htmlspecialchars($row['action']); ?>
                    </h6>

                    <div class="text-muted small mb-2">
                        <?php echo date('d M Y h:i A', strtotime($row['created_at'])); ?>
                        |
                        <?php echo htmlspecialchars($row['user_type']); ?>
                        :
                        <?php echo htmlspecialchars($row['user_name']); ?>
                    </div>

                    <?php if (!empty($row['details'])) { ?>
                        <div>
                            <?php echo nl2br(htmlspecialchars($row['details'])); ?>
                        </div>
                    <?php } ?>

                </div>

            <?php } ?>

        <?php } else { ?>

            <p class="text-muted">No timeline available.</p>

        <?php } ?>

    </div>
</div>

</div>

</body>
</html>