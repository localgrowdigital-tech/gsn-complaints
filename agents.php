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

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function selected_status(string $current, string $status): string
{
    return $current === $status ? 'selected' : '';
}

function redirect_with_message(string $message): void
{
    header('Location: agents.php?success=' . urlencode($message));
    exit;
}

/*
|--------------------------------------------------------------------------
| Delete Agent
|--------------------------------------------------------------------------
| Delete ab GET link se nahi, secure POST request se hoga.
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete'
) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    $agentId = (int)($_POST['agent_id'] ?? 0);

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif ($agentId <= 0) {
        $error = 'Invalid agent selected.';
    } else {
        mysqli_begin_transaction($conn);

        try {
            $deleteJobsStmt = mysqli_prepare(
                $conn,
                'DELETE FROM agent_jobs WHERE agent_id = ?'
            );

            if (!$deleteJobsStmt) {
                throw new Exception(mysqli_error($conn));
            }

            mysqli_stmt_bind_param($deleteJobsStmt, 'i', $agentId);
            mysqli_stmt_execute($deleteJobsStmt);
            mysqli_stmt_close($deleteJobsStmt);

            $deleteAgentStmt = mysqli_prepare(
                $conn,
                'DELETE FROM agents WHERE id = ?'
            );

            if (!$deleteAgentStmt) {
                throw new Exception(mysqli_error($conn));
            }

            mysqli_stmt_bind_param($deleteAgentStmt, 'i', $agentId);
            mysqli_stmt_execute($deleteAgentStmt);

            if (mysqli_stmt_affected_rows($deleteAgentStmt) < 1) {
                mysqli_stmt_close($deleteAgentStmt);
                throw new Exception('Agent not found.');
            }

            mysqli_stmt_close($deleteAgentStmt);

            mysqli_commit($conn);
            redirect_with_message('Agent deleted successfully.');
        } catch (Throwable $exception) {
            mysqli_rollback($conn);

            error_log(
                'Agent delete failed: ' . $exception->getMessage()
            );

            $error = 'Unable to delete agent.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Add / Update Agent
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save'
) {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');

    $agentId = (int)($_POST['agent_id'] ?? 0);
    $agentName = trim((string)($_POST['agent_name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $status = trim((string)($_POST['status'] ?? 'Active'));

    $jobs = isset($_POST['jobs']) && is_array($_POST['jobs'])
        ? array_values(
            array_unique(
                array_filter(
                    array_map('intval', $_POST['jobs']),
                    static fn (int $jobId): bool => $jobId > 0
                )
            )
        )
        : [];

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Invalid security token. Please refresh the page.';
    } elseif ($agentName === '' || $username === '') {
        $error = 'Agent name and username are required.';
    } elseif (strlen($agentName) > 100) {
        $error = 'Agent name is too long.';
    } elseif (strlen($username) > 100) {
        $error = 'Username is too long.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
        $error = 'Username can contain letters, numbers, dot, dash and underscore only.';
    } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
        $error = 'Invalid status selected.';
    } elseif ($agentId === 0 && $password === '') {
        $error = 'Password is required for a new agent.';
    } elseif ($password !== '' && strlen($password) < 6) {
        $error = 'Password must contain at least 6 characters.';
    } else {
        /*
        |--------------------------------------------------------------------------
        | Check duplicate username
        |--------------------------------------------------------------------------
        */
        if ($agentId > 0) {
            $usernameStmt = mysqli_prepare(
                $conn,
                'SELECT id
                 FROM agents
                 WHERE username = ?
                   AND id != ?
                 LIMIT 1'
            );

            if ($usernameStmt) {
                mysqli_stmt_bind_param(
                    $usernameStmt,
                    'si',
                    $username,
                    $agentId
                );
            }
        } else {
            $usernameStmt = mysqli_prepare(
                $conn,
                'SELECT id
                 FROM agents
                 WHERE username = ?
                 LIMIT 1'
            );

            if ($usernameStmt) {
                mysqli_stmt_bind_param(
                    $usernameStmt,
                    's',
                    $username
                );
            }
        }

        if (!$usernameStmt) {
            error_log(
                'Username check prepare failed: ' . mysqli_error($conn)
            );

            $error = 'Unable to process request.';
        } else {
            mysqli_stmt_execute($usernameStmt);
            mysqli_stmt_store_result($usernameStmt);

            $usernameExists =
                mysqli_stmt_num_rows($usernameStmt) > 0;

            mysqli_stmt_close($usernameStmt);

            if ($usernameExists) {
                $error =
                    'Username already exists. Please choose another username.';
            } else {
                mysqli_begin_transaction($conn);

                try {
                    /*
                    |--------------------------------------------------------------------------
                    | Update Agent
                    |--------------------------------------------------------------------------
                    */
                    if ($agentId > 0) {
                        if ($password !== '') {
                            $hashedPassword = password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );

                            $updateStmt = mysqli_prepare(
                                $conn,
                                'UPDATE agents
                                 SET agent_name = ?,
                                     username = ?,
                                     password = ?,
                                     status = ?
                                 WHERE id = ?'
                            );

                            if (!$updateStmt) {
                                throw new Exception(mysqli_error($conn));
                            }

                            mysqli_stmt_bind_param(
                                $updateStmt,
                                'ssssi',
                                $agentName,
                                $username,
                                $hashedPassword,
                                $status,
                                $agentId
                            );
                        } else {
                            $updateStmt = mysqli_prepare(
                                $conn,
                                'UPDATE agents
                                 SET agent_name = ?,
                                     username = ?,
                                     status = ?
                                 WHERE id = ?'
                            );

                            if (!$updateStmt) {
                                throw new Exception(mysqli_error($conn));
                            }

                            mysqli_stmt_bind_param(
                                $updateStmt,
                                'sssi',
                                $agentName,
                                $username,
                                $status,
                                $agentId
                            );
                        }

                        mysqli_stmt_execute($updateStmt);

                        if (mysqli_stmt_affected_rows($updateStmt) < 0) {
                            mysqli_stmt_close($updateStmt);
                            throw new Exception('Agent update failed.');
                        }

                        mysqli_stmt_close($updateStmt);

                        $savedAgentId = $agentId;
                        $successMessage =
                            'Agent updated successfully.';
                    } else {
                        /*
                        |--------------------------------------------------------------------------
                        | Add Agent
                        |--------------------------------------------------------------------------
                        */
                        $hashedPassword = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $insertStmt = mysqli_prepare(
                            $conn,
                            'INSERT INTO agents
                                (agent_name, username, password, status)
                             VALUES (?, ?, ?, ?)'
                        );

                        if (!$insertStmt) {
                            throw new Exception(mysqli_error($conn));
                        }

                        mysqli_stmt_bind_param(
                            $insertStmt,
                            'ssss',
                            $agentName,
                            $username,
                            $hashedPassword,
                            $status
                        );

                        mysqli_stmt_execute($insertStmt);

                        $savedAgentId =
                            (int)mysqli_insert_id($conn);

                        mysqli_stmt_close($insertStmt);

                        if ($savedAgentId <= 0) {
                            throw new Exception(
                                'Agent insert failed.'
                            );
                        }

                        $successMessage =
                            'Agent added successfully.';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Remove previous job assignments
                    |--------------------------------------------------------------------------
                    */
                    $deleteAssignmentsStmt = mysqli_prepare(
                        $conn,
                        'DELETE FROM agent_jobs WHERE agent_id = ?'
                    );

                    if (!$deleteAssignmentsStmt) {
                        throw new Exception(mysqli_error($conn));
                    }

                    mysqli_stmt_bind_param(
                        $deleteAssignmentsStmt,
                        'i',
                        $savedAgentId
                    );

                    mysqli_stmt_execute($deleteAssignmentsStmt);
                    mysqli_stmt_close($deleteAssignmentsStmt);

                    /*
                    |--------------------------------------------------------------------------
                    | Add selected job assignments
                    |--------------------------------------------------------------------------
                    */
                    if (!empty($jobs)) {
                        $jobExistsStmt = mysqli_prepare(
                            $conn,
                            'SELECT id
                             FROM jobs
                             WHERE id = ?
                             LIMIT 1'
                        );

                        $assignJobStmt = mysqli_prepare(
                            $conn,
                            'INSERT INTO agent_jobs
                                (agent_id, job_id)
                             VALUES (?, ?)'
                        );

                        if (!$jobExistsStmt || !$assignJobStmt) {
                            throw new Exception(mysqli_error($conn));
                        }

                        foreach ($jobs as $jobId) {
                            mysqli_stmt_bind_param(
                                $jobExistsStmt,
                                'i',
                                $jobId
                            );

                            mysqli_stmt_execute($jobExistsStmt);
                            mysqli_stmt_store_result($jobExistsStmt);

                            if (
                                mysqli_stmt_num_rows(
                                    $jobExistsStmt
                                ) < 1
                            ) {
                                mysqli_stmt_free_result(
                                    $jobExistsStmt
                                );
                                continue;
                            }

                            mysqli_stmt_free_result(
                                $jobExistsStmt
                            );

                            mysqli_stmt_bind_param(
                                $assignJobStmt,
                                'ii',
                                $savedAgentId,
                                $jobId
                            );

                            mysqli_stmt_execute($assignJobStmt);
                        }

                        mysqli_stmt_close($jobExistsStmt);
                        mysqli_stmt_close($assignJobStmt);
                    }

                    mysqli_commit($conn);

                    redirect_with_message($successMessage);
                } catch (Throwable $exception) {
                    mysqli_rollback($conn);

                    error_log(
                        'Agent save failed: ' .
                        $exception->getMessage()
                    );

                    $error = 'Unable to save agent.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Agent for Editing
|--------------------------------------------------------------------------
*/
if (isset($_GET['edit'])) {
    $agentId = (int)$_GET['edit'];

    if ($agentId > 0) {
        $agentStmt = mysqli_prepare(
            $conn,
            'SELECT id, agent_name, username, status
             FROM agents
             WHERE id = ?
             LIMIT 1'
        );

        if ($agentStmt) {
            mysqli_stmt_bind_param($agentStmt, 'i', $agentId);
            mysqli_stmt_execute($agentStmt);

            $agentResult = mysqli_stmt_get_result($agentStmt);
            $editAgent = mysqli_fetch_assoc($agentResult);

            mysqli_stmt_close($agentStmt);
        }

        if ($editAgent) {
            $assignedStmt = mysqli_prepare(
                $conn,
                'SELECT job_id
                 FROM agent_jobs
                 WHERE agent_id = ?'
            );

            if ($assignedStmt) {
                mysqli_stmt_bind_param(
                    $assignedStmt,
                    'i',
                    $agentId
                );

                mysqli_stmt_execute($assignedStmt);

                $assignedResult =
                    mysqli_stmt_get_result($assignedStmt);

                while (
                    $row = mysqli_fetch_assoc($assignedResult)
                ) {
                    $assignedJobs[] = (int)$row['job_id'];
                }

                mysqli_stmt_close($assignedStmt);
            }
        } else {
            $error = 'Agent not found.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
*/
if (isset($_GET['success'])) {
    $success = trim((string)$_GET['success']);
}

/*
|--------------------------------------------------------------------------
| Jobs List
|--------------------------------------------------------------------------
*/
$jobsResult = mysqli_query(
    $conn,
    'SELECT id, job_name
     FROM jobs
     ORDER BY job_name ASC'
);

/*
|--------------------------------------------------------------------------
| Agents List
|--------------------------------------------------------------------------
*/
$agentsResult = mysqli_query(
    $conn,
    "SELECT
        a.id,
        a.agent_name,
        a.username,
        a.status,
        a.created_at,
        GROUP_CONCAT(
            j.job_name
            ORDER BY j.job_name
            SEPARATOR ', '
        ) AS job_names
     FROM agents a
     LEFT JOIN agent_jobs aj
        ON aj.agent_id = a.id
     LEFT JOIN jobs j
        ON j.id = aj.job_id
     GROUP BY
        a.id,
        a.agent_name,
        a.username,
        a.status,
        a.created_at
     ORDER BY a.id DESC"
);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Agent Management</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background: #f5f7fb;
        }

        .page-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .08);
        }

        .form-check-list {
            max-height: 230px;
            overflow-y: auto;
        }

        .table th {
            white-space: nowrap;
        }

        .delete-form {
            display: inline-block;
            margin: 0;
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg bg-primary navbar-dark">
    <div class="container-fluid">

        <span class="navbar-brand fw-semibold">
            Admin Agent Management
        </span>

        <a href="dashboard.php" class="btn btn-light btn-sm">
            Dashboard
        </a>

    </div>
</nav>

<main class="container-fluid py-4">

    <div class="row g-4">

        <div class="col-lg-4">

            <div class="card page-card">

                <div class="card-body">

                    <h1 class="h4 mb-3">
                        <?php
                        echo $editAgent
                            ? 'Update Agent'
                            : 'Add Agent';
                        ?>
                    </h1>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success">
                            <?php
                            echo htmlspecialchars(
                                $success,
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger">
                            <?php
                            echo htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">

                        <input
                            type="hidden"
                            name="action"
                            value="save"
                        >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php
                            echo htmlspecialchars(
                                $csrfToken,
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>"
                        >

                        <input
                            type="hidden"
                            name="agent_id"
                            value="<?php
                            echo htmlspecialchars(
                                (string)($editAgent['id'] ?? ''),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                            ?>"
                        >

                        <div class="mb-3">

                            <label class="form-label">
                                Agent Name
                            </label>

                            <input
                                type="text"
                                name="agent_name"
                                class="form-control"
                                maxlength="100"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $editAgent['agent_name'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>"
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Username
                            </label>

                            <input
                                type="text"
                                name="username"
                                class="form-control"
                                maxlength="100"
                                autocomplete="username"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $editAgent['username'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>"
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">

                                Password

                                <?php if ($editAgent): ?>
                                    <span
                                        class="text-muted fw-normal"
                                    >
                                        (fill only to reset)
                                    </span>
                                <?php endif; ?>

                            </label>

                            <input
                                type="password"
                                name="password"
                                class="form-control"
                                minlength="6"
                                autocomplete="new-password"
                                <?php
                                echo $editAgent ? '' : 'required';
                                ?>
                            >

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Status
                            </label>

                            <select
                                name="status"
                                class="form-select"
                            >

                                <?php
                                $currentStatus =
                                    $editAgent['status']
                                    ?? 'Active';
                                ?>

                                <option
                                    value="Active"
                                    <?php
                                    echo selected_status(
                                        $currentStatus,
                                        'Active'
                                    );
                                    ?>
                                >
                                    Active
                                </option>

                                <option
                                    value="Inactive"
                                    <?php
                                    echo selected_status(
                                        $currentStatus,
                                        'Inactive'
                                    );
                                    ?>
                                >
                                    Inactive
                                </option>

                            </select>

                        </div>

                        <div class="mb-3">

                            <label class="form-label">
                                Assigned Jobs
                            </label>

                            <div
                                class="border rounded bg-light p-3 form-check-list"
                            >

                                <?php
                                if (
                                    $jobsResult
                                    && mysqli_num_rows(
                                        $jobsResult
                                    ) > 0
                                ):
                                ?>

                                    <?php
                                    while (
                                        $job =
                                        mysqli_fetch_assoc(
                                            $jobsResult
                                        )
                                    ):
                                    ?>

                                        <?php
                                        $jobId = (int)$job['id'];
                                        ?>

                                        <div class="form-check">

                                            <input
                                                class="form-check-input"
                                                type="checkbox"
                                                name="jobs[]"
                                                id="job_<?php echo $jobId; ?>"
                                                value="<?php echo $jobId; ?>"
                                                <?php
                                                echo in_array(
                                                    $jobId,
                                                    $assignedJobs,
                                                    true
                                                )
                                                    ? 'checked'
                                                    : '';
                                                ?>
                                            >

                                            <label
                                                class="form-check-label"
                                                for="job_<?php echo $jobId; ?>"
                                            >
                                                <?php
                                                echo htmlspecialchars(
                                                    $job['job_name'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                );
                                                ?>
                                            </label>

                                        </div>

                                    <?php endwhile; ?>

                                <?php else: ?>

                                    <div class="text-muted">
                                        No jobs found. Add jobs first.
                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="d-grid gap-2 d-md-flex">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                <?php
                                echo $editAgent
                                    ? 'Update Agent'
                                    : 'Add Agent';
                                ?>
                            </button>

                            <?php if ($editAgent): ?>

                                <a
                                    href="agents.php"
                                    class="btn btn-outline-secondary"
                                >
                                    Cancel
                                </a>

                            <?php endif; ?>

                        </div>

                    </form>

                </div>

            </div>

        </div>

        <div class="col-lg-8">

            <div class="card page-card">

                <div class="card-body">

                    <div
                        class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3"
                    >

                        <div>

                            <h2 class="h4 mb-1">
                                Agents
                            </h2>

                            <p class="text-muted mb-0">
                                Manage agent accounts and job assignments.
                            </p>

                        </div>

                        <a
                            href="agents.php"
                            class="btn btn-outline-primary btn-sm"
                        >
                            Add New
                        </a>

                    </div>

                    <div class="table-responsive">

                        <table
                            class="table table-hover align-middle"
                        >

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

                            <?php
                            if (
                                $agentsResult
                                && mysqli_num_rows(
                                    $agentsResult
                                ) > 0
                            ):
                            ?>

                                <?php
                                while (
                                    $agent =
                                    mysqli_fetch_assoc(
                                        $agentsResult
                                    )
                                ):
                                ?>

                                    <tr>

                                        <td>
                                            <?php
                                            echo (int)$agent['id'];
                                            ?>
                                        </td>

                                        <td class="fw-semibold">
                                            <?php
                                            echo htmlspecialchars(
                                                $agent['agent_name'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo htmlspecialchars(
                                                $agent['username'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            <?php
                                            echo htmlspecialchars(
                                                $agent['job_names']
                                                    ?: 'No jobs assigned',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>
                                        </td>

                                        <td>

                                            <?php
                                            if (
                                                $agent['status']
                                                === 'Active'
                                            ):
                                            ?>

                                                <span
                                                    class="badge text-bg-success"
                                                >
                                                    Active
                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="badge text-bg-secondary"
                                                >
                                                    Inactive
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?php
                                            echo htmlspecialchars(
                                                $agent['created_at']
                                                    ?? '',
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>
                                        </td>

                                        <td class="text-nowrap">

                                            <a
                                                href="agents.php?edit=<?php
                                                echo (int)$agent['id'];
                                                ?>"
                                                class="btn btn-sm btn-outline-primary"
                                            >
                                                Edit
                                            </a>

                                            <form
                                                method="post"
                                                class="delete-form"
                                                onsubmit="return confirm('Delete this agent?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php
                                                    echo htmlspecialchars(
                                                        $csrfToken,
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    );
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="agent_id"
                                                    value="<?php
                                                    echo (int)$agent['id'];
                                                    ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                >
                                                    Delete
                                                </button>

                                            </form>

                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php else: ?>

                                <tr>

                                    <td
                                        colspan="7"
                                        class="text-center text-muted py-4"
                                    >
                                        No agents found.
                                    </td>

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

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>
</html>