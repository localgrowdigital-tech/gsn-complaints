<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agentId = (int) $_SESSION['agent_id'];
$agentName = $_SESSION['agent_name'] ?? 'Agent';
$complaintDbId = (int) ($_GET['id'] ?? 0);
$success = '';
$error = '';

if ($complaintDbId <= 0) {
    header('Location: agent-complaints.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'Open' => 'text-bg-primary',
        'In Progress' => 'text-bg-warning',
        'Resolved' => 'text-bg-success',
        'Closed' => 'text-bg-secondary',
        default => 'text-bg-light'
    };
}

function priority_badge_class(string $priority): string
{
    return match ($priority) {
        'Most Urgent' => 'text-bg-danger',
        'Urgent' => 'text-bg-warning',
        default => 'text-bg-secondary'
    };
}

function reminder_status(?string $date): array
{
    if (!$date) {
        return ['No reminder date', 'text-bg-secondary', 'bi-calendar-x'];
    }

    $today = date('Y-m-d');

    if ($date < $today) {
        return ['Overdue', 'text-bg-danger', 'bi-exclamation-triangle-fill'];
    }

    if ($date === $today) {
        return ['Due Today', 'text-bg-warning', 'bi-alarm-fill'];
    }

    return ['Upcoming', 'text-bg-primary', 'bi-calendar-check-fill'];
}

function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return (int) ($row['total'] ?? 0) > 0;
}

function agent_can_access_complaint(mysqli $conn, int $complaintId, int $agentId): bool
{
    $sql = "
        SELECT c.id
        FROM complaints c
        INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
        WHERE c.id = ?
          AND aj.agent_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $complaintId, $agentId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $allowed = mysqli_num_rows($result) === 1;
    mysqli_stmt_close($stmt);

    return $allowed;
}

/*
|--------------------------------------------------------------------------
| Access check
|--------------------------------------------------------------------------
*/
if (!agent_can_access_complaint($conn, $complaintDbId, $agentId)) {
    header('Location: agent-complaints.php?error=access_denied');
    exit;
}

/*
|--------------------------------------------------------------------------
| Save pin and reminder
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reminder'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $isPinned = isset($_POST['is_pinned']) ? 1 : 0;
    $reminderDate = trim((string) ($_POST['reminder_date'] ?? ''));
    $reminderNote = trim((string) ($_POST['reminder_note'] ?? ''));

    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request token. Please refresh and try again.';
    } elseif ($reminderDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reminderDate)) {
        $error = 'Invalid reminder date.';
    } elseif (mb_strlen($reminderNote) > 255) {
        $error = 'Reminder note must be 255 characters or less.';
    } else {
        if ($isPinned === 0) {
            $reminderDate = '';
            $reminderNote = '';
        }

        $updateSql = "
            UPDATE complaints
            SET is_pinned = ?,
                reminder_date = NULLIF(?, ''),
                reminder_note = NULLIF(?, '')
            WHERE id = ?
        ";

        $updateStmt = mysqli_prepare($conn, $updateSql);

        if (!$updateStmt) {
            $error = 'Unable to prepare reminder update.';
        } else {
            mysqli_stmt_bind_param(
                $updateStmt,
                'issi',
                $isPinned,
                $reminderDate,
                $reminderNote,
                $complaintDbId
            );

            if (mysqli_stmt_execute($updateStmt)) {
                $success = $isPinned === 1
                    ? 'Complaint pin and reminder saved successfully.'
                    : 'Complaint unpinned successfully.';
            } else {
                $error = 'Unable to save reminder. Please try again.';
            }

            mysqli_stmt_close($updateStmt);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Complaint details
|--------------------------------------------------------------------------
*/
$complaintSql = "
    SELECT
        c.*,
        j.job_name,
        v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE c.id = ?
      AND aj.agent_id = ?
    LIMIT 1
";

$complaintStmt = mysqli_prepare($conn, $complaintSql);

if (!$complaintStmt) {
    $error = $error ?: 'Unable to load complaint details.';
    $complaint = null;
} else {
    mysqli_stmt_bind_param($complaintStmt, 'ii', $complaintDbId, $agentId);
    mysqli_stmt_execute($complaintStmt);
    $complaintResult = mysqli_stmt_get_result($complaintStmt);
    $complaint = mysqli_fetch_assoc($complaintResult);
    mysqli_stmt_close($complaintStmt);
}

if (!$complaint) {
    header('Location: agent-complaints.php?error=access_denied');
    exit;
}

/*
|--------------------------------------------------------------------------
| Remarks
|--------------------------------------------------------------------------
*/
$remarksHasAgentId = table_has_column($conn, 'complaint_remarks', 'agent_id');

if ($remarksHasAgentId) {
    $remarksSql = "
        SELECT
            cr.remark,
            cr.status,
            cr.remark_date,
            a.agent_name
        FROM complaint_remarks cr
        LEFT JOIN agents a ON a.id = cr.agent_id
        WHERE cr.complaint_id = ?
        ORDER BY cr.remark_date DESC, cr.id DESC
    ";
} else {
    $remarksSql = "
        SELECT
            cr.remark,
            cr.status,
            cr.remark_date
        FROM complaint_remarks cr
        WHERE cr.complaint_id = ?
        ORDER BY cr.remark_date DESC, cr.id DESC
    ";
}

$remarksStmt = mysqli_prepare($conn, $remarksSql);
$remarksResult = false;

if ($remarksStmt) {
    mysqli_stmt_bind_param($remarksStmt, 'i', $complaintDbId);
    mysqli_stmt_execute($remarksStmt);
    $remarksResult = mysqli_stmt_get_result($remarksStmt);
}

[$reminderLabel, $reminderClass, $reminderIcon] = reminder_status($complaint['reminder_date'] ?? null);

$backUrl = 'agent-complaints.php';

if (!empty($_SERVER['HTTP_REFERER']) && str_contains($_SERVER['HTTP_REFERER'], 'agent-complaints.php')) {
    $backUrl = $_SERVER['HTTP_REFERER'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complaint Details - GRAND SPEED NETWORK</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --page-bg: #f4f7fb;
            --main-text: #172033;
            --muted-text: #64748b;
            --card-radius: 18px;
        }

        body {
            background:
                radial-gradient(circle at top right, rgba(13, 110, 253, .08), transparent 30%),
                var(--page-bg);
            color: var(--main-text);
            min-height: 100vh;
        }

        .topbar {
            background: linear-gradient(110deg, #0b5ed7, #4338ca);
            box-shadow: 0 12px 35px rgba(30, 64, 175, .22);
        }

        .brand-box {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: #0d6efd;
            font-weight: 900;
        }

        .page-card {
            border: 0;
            border-radius: var(--card-radius);
            box-shadow: 0 12px 32px rgba(15, 23, 42, .07);
        }

        .info-label {
            color: var(--muted-text);
            font-size: .75rem;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
            margin-bottom: .25rem;
        }

        .info-value {
            font-weight: 650;
            overflow-wrap: anywhere;
        }

        .summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 1rem;
            height: 100%;
        }

        .reminder-card {
            border: 1px solid #fde68a;
            background: linear-gradient(135deg, #fffdf4, #fff7d6);
        }

        .pinned-banner {
            background: linear-gradient(135deg, #fff1f2, #fff7ed);
            border: 1px solid #fecdd3;
            border-radius: 14px;
        }

        .timeline {
            position: relative;
            padding-left: 1.4rem;
        }

        .timeline::before {
            content: "";
            position: absolute;
            top: .4rem;
            bottom: .4rem;
            left: .34rem;
            width: 2px;
            background: #dbeafe;
        }

        .timeline-item {
            position: relative;
            padding-left: 1.35rem;
            padding-bottom: 1rem;
        }

        .timeline-dot {
            position: absolute;
            left: -.04rem;
            top: .35rem;
            width: .75rem;
            height: .75rem;
            border-radius: 50%;
            background: #0d6efd;
            box-shadow: 0 0 0 4px #dbeafe;
        }

        .form-control,
        .form-select {
            min-height: 44px;
            border-radius: 10px;
        }

        .sticky-reminder {
            position: sticky;
            top: 1rem;
        }

        @media print {
            body {
                background: #fff;
            }

            .topbar,
            .no-print,
            .btn,
            form {
                display: none !important;
            }

            .container-fluid {
                padding: 0 !important;
            }

            .page-card {
                box-shadow: none !important;
                border: 1px solid #dee2e6 !important;
                break-inside: avoid;
            }

            a {
                color: #000;
                text-decoration: none;
            }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="agent-dashboard.php">
            <span class="brand-box">GSN</span>
            <span class="d-none d-sm-inline">GRAND SPEED NETWORK</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#agentNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="agentNav">
            <div class="ms-lg-auto d-flex flex-column flex-lg-row align-items-lg-center gap-2 mt-3 mt-lg-0">
                <div class="text-white me-lg-2">
                    <span class="opacity-75">Welcome,</span>
                    <strong><?php echo e($agentName); ?></strong>
                </div>

                <a href="agent-dashboard.php" class="btn btn-light btn-sm">Dashboard</a>
                <a href="agent-complaints.php" class="btn btn-outline-light btn-sm">My Complaints</a>
                <a href="agent-logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </div>
</nav>

<main class="container-fluid px-3 px-md-4 py-4">

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h1 class="h3 fw-bold mb-0">Complaint Details</h1>

                <?php if ((int) ($complaint['is_pinned'] ?? 0) === 1): ?>
                    <span class="badge text-bg-danger">
                        <i class="bi bi-pin-angle-fill me-1"></i> PINNED
                    </span>
                <?php endif; ?>
            </div>

            <p class="text-muted mb-0">Complete complaint information, reminder and remark history.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 no-print">
            <a href="<?php echo e($backUrl); ?>" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>

            <a href="agent-remarks.php?id=<?php echo (int) $complaintDbId; ?>" class="btn btn-primary">
                <i class="bi bi-chat-left-text me-1"></i> Add Remark
            </a>

            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>

            <a href="agent-dashboard.php" class="btn btn-outline-primary">Dashboard</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ((int) ($complaint['is_pinned'] ?? 0) === 1): ?>
        <div class="pinned-banner p-3 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <div class="fw-bold">
                        <i class="bi bi-pin-angle-fill text-danger me-1"></i>
                        This complaint is pinned
                    </div>

                    <div class="small text-muted mt-1">
                        <?php echo e($complaint['reminder_note'] ?: 'No reminder note added.'); ?>
                    </div>
                </div>

                <div class="text-md-end">
                    <span class="badge <?php echo e($reminderClass); ?>">
                        <i class="bi <?php echo e($reminderIcon); ?> me-1"></i>
                        <?php echo e($reminderLabel); ?>
                    </span>

                    <?php if (!empty($complaint['reminder_date'])): ?>
                        <div class="small text-muted mt-1">
                            <?php echo e(date('d M Y', strtotime($complaint['reminder_date']))); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-xl-8">

            <section class="card page-card mb-4">
                <div class="card-body">

                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
                        <div>
                            <div class="info-label">Complaint ID</div>
                            <div class="h4 fw-bold mb-0"><?php echo e($complaint['complaint_id']); ?></div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <span class="badge <?php echo priority_badge_class((string) $complaint['priority']); ?>">
                                <?php echo e($complaint['priority']); ?>
                            </span>

                            <span class="badge <?php echo status_badge_class((string) $complaint['status']); ?>">
                                <?php echo e($complaint['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="summary-box">
                                <div class="info-label">Complaint Date</div>
                                <div class="info-value">
                                    <?php echo e(date('d M Y', strtotime($complaint['complaint_date']))); ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="summary-box">
                                <div class="info-label">Job Name</div>
                                <div class="info-value"><?php echo e($complaint['job_name'] ?: 'No Job'); ?></div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="summary-box">
                                <div class="info-label">Vendor Name</div>
                                <div class="info-value"><?php echo e($complaint['vendor_name'] ?: 'No Vendor'); ?></div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="summary-box">
                                <div class="info-label">Closing Date</div>
                                <div class="info-value">
                                    <?php
                                    echo !empty($complaint['closing_date'])
                                        ? e(date('d M Y', strtotime($complaint['closing_date'])))
                                        : 'Not closed';
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="summary-box">
                                <div class="info-label">Tracking Number</div>
                                <div class="info-value"><?php echo e($complaint['tracking_number']); ?></div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="summary-box">
                                <div class="info-label">Secondary Tracking Number</div>
                                <div class="info-value">
                                    <?php echo e($complaint['secondary_tracking_number'] ?: 'Not available'); ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-box">
                                <div class="info-label">Customer Name</div>
                                <div class="info-value"><?php echo e($complaint['customer_name']); ?></div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-box">
                                <div class="info-label">Mobile</div>
                                <div class="info-value"><?php echo e($complaint['mobile']); ?></div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="summary-box">
                                <div class="info-label">Complaint Type</div>
                                <div class="info-value"><?php echo e($complaint['complaint_type']); ?></div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="summary-box">
                                <div class="info-label">Address</div>
                                <div class="info-value">
                                    <?php echo nl2br(e($complaint['address'] ?: 'Not available')); ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="summary-box">
                                <div class="info-label">Description</div>
                                <div class="info-value">
                                    <?php echo nl2br(e($complaint['description'] ?: 'No description')); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <section class="card page-card">
                <div class="card-body">

                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
                        <h2 class="h5 fw-bold mb-0">Remark History</h2>

                        <a
                            href="agent-remarks.php?id=<?php echo (int) $complaintDbId; ?>"
                            class="btn btn-sm btn-primary no-print"
                        >
                            Add Remark
                        </a>
                    </div>

                    <?php if ($remarksResult && mysqli_num_rows($remarksResult) > 0): ?>
                        <div class="timeline">
                            <?php while ($remark = mysqli_fetch_assoc($remarksResult)): ?>
                                <div class="timeline-item">
                                    <span class="timeline-dot"></span>

                                    <div class="card border-0 shadow-sm">
                                        <div class="card-body">
                                            <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <span class="badge <?php echo status_badge_class((string) $remark['status']); ?>">
                                                        <?php echo e($remark['status']); ?>
                                                    </span>

                                                    <?php if ($remarksHasAgentId && !empty($remark['agent_name'])): ?>
                                                        <span class="text-muted small">
                                                            By <?php echo e($remark['agent_name']); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <small class="text-muted">
                                                    <?php echo e($remark['remark_date']); ?>
                                                </small>
                                            </div>

                                            <div><?php echo nl2br(e($remark['remark'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-chat-left-text display-5 d-block mb-2"></i>
                            No remarks found for this complaint.
                        </div>
                    <?php endif; ?>

                </div>
            </section>

        </div>

        <div class="col-xl-4 no-print">
            <div class="sticky-reminder">

                <section class="card page-card reminder-card">
                    <div class="card-body">

                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="fs-4">📌</div>
                            <div>
                                <h2 class="h5 fw-bold mb-0">Priority Reminder</h2>
                                <div class="small text-muted">Pin this complaint for follow-up.</div>
                            </div>
                        </div>

                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">

                            <div class="form-check form-switch mb-3">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    name="is_pinned"
                                    id="is_pinned"
                                    value="1"
                                    <?php echo (int) ($complaint['is_pinned'] ?? 0) === 1 ? 'checked' : ''; ?>
                                >

                                <label class="form-check-label fw-semibold" for="is_pinned">
                                    Pin Complaint
                                </label>
                            </div>

                            <div id="reminderFields">
                                <div class="mb-3">
                                    <label for="reminder_date" class="form-label fw-semibold">
                                        Reminder Date
                                    </label>

                                    <input
                                        type="date"
                                        name="reminder_date"
                                        id="reminder_date"
                                        class="form-control"
                                        value="<?php echo e($complaint['reminder_date']); ?>"
                                    >
                                </div>

                                <div class="mb-3">
                                    <label for="reminder_note" class="form-label fw-semibold">
                                        Reminder Note
                                    </label>

                                    <textarea
                                        name="reminder_note"
                                        id="reminder_note"
                                        class="form-control"
                                        rows="4"
                                        maxlength="255"
                                        placeholder="Example: Call vendor and ask for POD."
                                    ><?php echo e($complaint['reminder_note']); ?></textarea>

                                    <div class="form-text">
                                        Maximum 255 characters.
                                    </div>
                                </div>
                            </div>

                            <button type="submit" name="save_reminder" value="1" class="btn btn-warning w-100 fw-semibold">
                                <i class="bi bi-save me-1"></i>
                                Save Pin & Reminder
                            </button>

                            <?php if ((int) ($complaint['is_pinned'] ?? 0) === 1): ?>
                                <div class="small text-muted mt-3 text-center">
                                    To unpin, switch off “Pin Complaint” and save.
                                </div>
                            <?php endif; ?>
                        </form>

                    </div>
                </section>

            </div>
        </div>

    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
const pinSwitch = document.getElementById('is_pinned');
const reminderFields = document.getElementById('reminderFields');

function toggleReminderFields() {
    const enabled = pinSwitch.checked;

    reminderFields.querySelectorAll('input, textarea').forEach(function (field) {
        field.disabled = !enabled;
    });

    reminderFields.style.opacity = enabled ? '1' : '.5';
}

pinSwitch.addEventListener('change', toggleReminderFields);
toggleReminderFields();
</script>

</body>
</html>

<?php
if ($remarksStmt) {
    mysqli_stmt_close($remarksStmt);
}
?>
