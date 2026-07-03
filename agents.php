<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

$success = '';
$error = '';
$editAgent = null;
$assignedJobs = [];

function clean_input($conn, $value)
{
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function selected_status($current, $status)
{
    return $current === $status ? 'selected' : '';
}

if (isset($_GET['delete'])) {
    $agentId = (int)$_GET['delete'];

    if ($agentId > 0) {
        mysqli_query($conn, "DELETE FROM agent_jobs WHERE agent_id = $agentId");
        if (mysqli_query($conn, "DELETE FROM agents WHERE id = $agentId")) {
            header('Location: agents.php?success=Agent deleted successfully');
            exit;
        }
        $error = 'Unable to delete agent: ' . mysqli_error($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agentId = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : 0;
    $agentName = clean_input($conn, $_POST['agent_name'] ?? '');
    $username = clean_input($conn, $_POST['username'] ?? '');
    $password = trim((string)($_POST['password'] ?? ''));
    $status = clean_input($conn, $_POST['status'] ?? 'Active');
    $jobs = isset($_POST['jobs']) && is_array($_POST['jobs']) ? array_map('intval', $_POST['jobs']) : [];

    if ($agentName === '' || $username === '') {
        $error = 'Agent name and username are required.';
    } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
        $error = 'Invalid status selected.';
    } elseif ($agentId === 0 && $password === '') {
        $error = 'Password is required for a new agent.';
    } else {
        $usernameCheckSql = "SELECT id FROM agents WHERE username = '$username'" . ($agentId > 0 ? " AND id != $agentId" : '') . " LIMIT 1";
        $usernameCheck = mysqli_query($conn, $usernameCheckSql);

        if ($usernameCheck && mysqli_num_rows($usernameCheck) > 0) {
            $error = 'Username already exists. Please choose another username.';
        } else {
            if ($agentId > 0) {
                $passwordSql = '';
                if ($password !== '') {
                    $hashedPassword = clean_input($conn, password_hash($password, PASSWORD_DEFAULT));
                    $passwordSql = ", password = '$hashedPassword'";
                }

                $sql = "UPDATE agents
                        SET agent_name = '$agentName',
                            username = '$username',
                            status = '$status'
                            $passwordSql
                        WHERE id = $agentId";

                if (mysqli_query($conn, $sql)) {
                    mysqli_query($conn, "DELETE FROM agent_jobs WHERE agent_id = $agentId");
                    foreach ($jobs as $jobId) {
                        if ($jobId > 0) {
                            mysqli_query($conn, "INSERT INTO agent_jobs (agent_id, job_id) VALUES ($agentId, $jobId)");
                        }
                    }
                    header('Location: agents.php?success=Agent updated successfully');
                    exit;
                }
                $error = 'Unable to update agent: ' . mysqli_error($conn);
            } else {
                $hashedPassword = clean_input($conn, password_hash($password, PASSWORD_DEFAULT));
                $sql = "INSERT INTO agents (agent_name, username, password, status)
                        VALUES ('$agentName', '$username', '$hashedPassword', '$status')";

                if (mysqli_query($conn, $sql)) {
                    $newAgentId = (int)mysqli_insert_id($conn);
                    foreach ($jobs as $jobId) {
                        if ($jobId > 0) {
                            mysqli_query($conn, "INSERT INTO agent_jobs (agent_id, job_id) VALUES ($newAgentId, $jobId)");
                        }
                    }
                    header('Location: agents.php?success=Agent added successfully');
                    exit;
                }
                $error = 'Unable to add agent: ' . mysqli_error($conn);
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $agentId = (int)$_GET['edit'];
    $agentResult = mysqli_query($conn, "SELECT * FROM agents WHERE id = $agentId LIMIT 1");
    $editAgent = $agentResult ? mysqli_fetch_assoc($agentResult) : null;

    if ($editAgent) {
        $assignedResult = mysqli_query($conn, "SELECT job_id FROM agent_jobs WHERE agent_id = $agentId");
        while ($row = mysqli_fetch_assoc($assignedResult)) {
            $assignedJobs[] = (int)$row['job_id'];
        }
    }
}

if (isset($_GET['success'])) {
    $success = trim((string)$_GET['success']);
}

$jobsResult = mysqli_query($conn, "SELECT id, job_name FROM jobs ORDER BY job_name ASC");
$agentsResult = mysqli_query($conn, "
    SELECT a.id, a.agent_name, a.username, a.status, a.created_at,
           GROUP_CONCAT(j.job_name ORDER BY j.job_name SEPARATOR ', ') AS job_names
    FROM agents a
    LEFT JOIN agent_jobs aj ON aj.agent_id = a.id
    LEFT JOIN jobs j ON j.id = aj.job_id
    GROUP BY a.id, a.agent_name, a.username, a.status, a.created_at
    ORDER BY a.id DESC
");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; }
        .page-card { border: 0; border-radius: 8px; box-shadow: 0 10px 28px rgba(15, 23, 42, .08); }
        .form-check-list { max-height: 230px; overflow-y: auto; }
        .table th { white-space: nowrap; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-primary navbar-dark">
    <div class="container-fluid">
        <span class="navbar-brand fw-semibold">Admin Agent Management</span>
        <a href="dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
    </div>
</nav>

<main class="container-fluid py-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card page-card">
                <div class="card-body">
                    <h1 class="h4 mb-3"><?php echo $editAgent ? 'Update Agent' : 'Add Agent'; ?></h1>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="agent_id" value="<?php echo htmlspecialchars($editAgent['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label class="form-label">Agent Name</label>
                            <input type="text" name="agent_name" class="form-control" required value="<?php echo htmlspecialchars($editAgent['agent_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($editAgent['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Password
                                <?php if ($editAgent): ?>
                                    <span class="text-muted fw-normal">(fill only to reset)</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="password" class="form-control" <?php echo $editAgent ? '' : 'required'; ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php $currentStatus = $editAgent['status'] ?? 'Active'; ?>
                                <option value="Active" <?php echo selected_status($currentStatus, 'Active'); ?>>Active</option>
                                <option value="Inactive" <?php echo selected_status($currentStatus, 'Inactive'); ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Assigned Jobs</label>
                            <div class="border rounded bg-light p-3 form-check-list">
                                <?php if ($jobsResult && mysqli_num_rows($jobsResult) > 0): ?>
                                    <?php while ($job = mysqli_fetch_assoc($jobsResult)): ?>
                                        <?php $jobId = (int)$job['id']; ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="jobs[]" id="job_<?php echo $jobId; ?>" value="<?php echo $jobId; ?>" <?php echo in_array($jobId, $assignedJobs, true) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="job_<?php echo $jobId; ?>">
                                                <?php echo htmlspecialchars($job['job_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </label>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-muted">No jobs found. Add jobs first.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="d-grid gap-2 d-md-flex">
                            <button type="submit" class="btn btn-primary"><?php echo $editAgent ? 'Update Agent' : 'Add Agent'; ?></button>
                            <?php if ($editAgent): ?>
                                <a href="agents.php" class="btn btn-outline-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card page-card">
                <div class="card-body">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                        <div>
                            <h2 class="h4 mb-1">Agents</h2>
                            <p class="text-muted mb-0">Manage agent accounts and job assignments.</p>
                        </div>
                        <a href="agents.php" class="btn btn-outline-primary btn-sm">Add New</a>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Agent</th>
                                <th>Username</th>
                                <th>Assigned Jobs</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($agentsResult && mysqli_num_rows($agentsResult) > 0): ?>
                                <?php while ($agent = mysqli_fetch_assoc($agentsResult)): ?>
                                    <tr>
                                        <td><?php echo (int)$agent['id']; ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($agent['agent_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($agent['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($agent['job_names'] ?: 'No jobs assigned', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($agent['status'] === 'Active'): ?>
                                                <span class="badge text-bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($agent['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-nowrap">
                                            <a href="agents.php?edit=<?php echo (int)$agent['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                            <a href="agents.php?delete=<?php echo (int)$agent['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this agent?')">Delete</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No agents found.</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
