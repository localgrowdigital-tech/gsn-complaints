<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['agent_id'])) {
    header('Location: agent-login.php');
    exit;
}

$agentId = (int) $_SESSION['agent_id'];
$agentName = $_SESSION['agent_name'] ?? 'Agent';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function status_badge(string $status): string
{
    return match ($status) {
        'Open' => 'text-bg-primary',
        'In Progress' => 'text-bg-warning',
        'Resolved' => 'text-bg-success',
        'Closed' => 'text-bg-secondary',
        default => 'text-bg-light'
    };
}

$metricsSql = "
    SELECT
        COUNT(DISTINCT c.id) AS total_complaints,
        SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN c.status = 'Closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.priority = 'Most Urgent' AND c.status NOT IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS most_urgent_count,
        SUM(CASE WHEN c.status IN ('Open', 'In Progress') AND DATEDIFF(CURDATE(), c.complaint_date) >= 4 THEN 1 ELSE 0 END) AS old_complaints_count,
        SUM(CASE WHEN YEAR(c.complaint_date) = YEAR(CURDATE()) AND MONTH(c.complaint_date) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_count,
        SUM(CASE WHEN DATE(c.complaint_date) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
        SUM(CASE WHEN c.complaint_type = 'POD Required' AND c.status NOT IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS pod_required_count,
        SUM(CASE WHEN c.complaint_type = 'Wrong Delivery' AND c.status NOT IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS wrong_delivery_count,
        SUM(CASE WHEN c.complaint_type IN ('Lost Shipment', 'Damaged Shipment') AND c.status NOT IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS new_material_count,
        SUM(CASE WHEN c.is_pinned = 1 AND c.status NOT IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS pinned_count
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    WHERE aj.agent_id = ?
";

$metrics = [];
$metricsStmt = mysqli_prepare($conn, $metricsSql);
if ($metricsStmt) {
    mysqli_stmt_bind_param($metricsStmt, 'i', $agentId);
    mysqli_stmt_execute($metricsStmt);
    $metricsResult = mysqli_stmt_get_result($metricsStmt);
    $metrics = mysqli_fetch_assoc($metricsResult) ?: [];
    mysqli_stmt_close($metricsStmt);
}

$totalComplaints = (int) ($metrics['total_complaints'] ?? 0);
$openCount = (int) ($metrics['open_count'] ?? 0);
$closedCount = (int) ($metrics['closed_count'] ?? 0);
$mostUrgentCount = (int) ($metrics['most_urgent_count'] ?? 0);
$oldComplaintsCount = (int) ($metrics['old_complaints_count'] ?? 0);
$monthCount = (int) ($metrics['month_count'] ?? 0);
$todayCount = (int) ($metrics['today_count'] ?? 0);
$podRequiredCount = (int) ($metrics['pod_required_count'] ?? 0);
$wrongDeliveryCount = (int) ($metrics['wrong_delivery_count'] ?? 0);
$newMaterialCount = (int) ($metrics['new_material_count'] ?? 0);
$pinnedCount = (int) ($metrics['pinned_count'] ?? 0);

$pinnedSql = "
    SELECT c.id, c.complaint_id, c.customer_name, c.tracking_number,
           c.complaint_type, c.priority, c.status, c.reminder_date,
           c.reminder_note, j.job_name, v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE aj.agent_id = ?
      AND c.is_pinned = 1
      AND c.status NOT IN ('Resolved', 'Closed')
    ORDER BY
        CASE
            WHEN c.reminder_date IS NULL THEN 3
            WHEN c.reminder_date < CURDATE() THEN 0
            WHEN c.reminder_date = CURDATE() THEN 1
            ELSE 2
        END,
        c.reminder_date ASC,
        c.id DESC
    LIMIT 12
";

$pinnedResult = false;
$pinnedStmt = mysqli_prepare($conn, $pinnedSql);
if ($pinnedStmt) {
    mysqli_stmt_bind_param($pinnedStmt, 'i', $agentId);
    mysqli_stmt_execute($pinnedStmt);
    $pinnedResult = mysqli_stmt_get_result($pinnedStmt);
}

$urgentSql = "
    SELECT c.id, c.complaint_id, c.customer_name, c.status,
           c.complaint_date, c.complaint_type, j.job_name, v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE aj.agent_id = ?
      AND c.priority = 'Most Urgent'
      AND c.status NOT IN ('Resolved', 'Closed')
    ORDER BY c.complaint_date ASC, c.id DESC
    LIMIT 10
";

$urgentResult = false;
$urgentStmt = mysqli_prepare($conn, $urgentSql);
if ($urgentStmt) {
    mysqli_stmt_bind_param($urgentStmt, 'i', $agentId);
    mysqli_stmt_execute($urgentStmt);
    $urgentResult = mysqli_stmt_get_result($urgentStmt);
}

$jobSummarySql = "
    SELECT j.id, j.job_name,
           COUNT(c.id) AS total_count,
           SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count,
           SUM(CASE WHEN c.status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS closed_count
    FROM agent_jobs aj
    INNER JOIN jobs j ON j.id = aj.job_id
    LEFT JOIN complaints c ON c.job_id = j.id
    WHERE aj.agent_id = ?
    GROUP BY j.id, j.job_name
    ORDER BY j.job_name ASC
";

$jobSummaryResult = false;
$jobStmt = mysqli_prepare($conn, $jobSummarySql);
if ($jobStmt) {
    mysqli_stmt_bind_param($jobStmt, 'i', $agentId);
    mysqli_stmt_execute($jobStmt);
    $jobSummaryResult = mysqli_stmt_get_result($jobStmt);
}

$vendorSummarySql = "
    SELECT
           c.vendor_id,
           COALESCE(v.vendor_name, 'No Vendor') AS vendor_name,
           COUNT(c.id) AS total_count,
           SUM(CASE WHEN c.status = 'Open' THEN 1 ELSE 0 END) AS open_count,
           SUM(CASE WHEN c.status IN ('Open', 'In Progress') THEN 1 ELSE 0 END) AS pending_count,
           SUM(CASE WHEN c.status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS closed_count
    FROM complaints c
    INNER JOIN agent_jobs aj ON aj.job_id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE aj.agent_id = ?
    GROUP BY c.vendor_id, v.vendor_name
    ORDER BY vendor_name ASC
";

$vendorSummaryResult = false;
$vendorStmt = mysqli_prepare($conn, $vendorSummarySql);
if ($vendorStmt) {
    mysqli_stmt_bind_param($vendorStmt, 'i', $agentId);
    mysqli_stmt_execute($vendorStmt);
    $vendorSummaryResult = mysqli_stmt_get_result($vendorStmt);
}

$cards = [
    ['label' => 'Total Complaints', 'value' => $totalComplaints, 'icon' => 'bi-files', 'class' => 'blue', 'url' => 'agent-complaints.php'],
    ['label' => 'Open', 'value' => $openCount, 'icon' => 'bi-folder2-open', 'class' => 'cyan', 'url' => 'agent-complaints.php?status=Open'],
    ['label' => 'Closed', 'value' => $closedCount, 'icon' => 'bi-check-circle', 'class' => 'green', 'url' => 'agent-complaints.php?status=Closed'],
    ['label' => 'Most Urgent', 'value' => $mostUrgentCount, 'icon' => 'bi-exclamation-triangle', 'class' => 'red', 'url' => 'agent-complaints.php?priority=Most+Urgent'],
    ['label' => 'Old Complaints', 'value' => $oldComplaintsCount, 'icon' => 'bi-hourglass-split', 'class' => 'orange', 'url' => 'agent-complaints.php?old=1', 'small' => 'Pending 4+ days'],
    ['label' => 'Current Month', 'value' => $monthCount, 'icon' => 'bi-calendar3', 'class' => 'purple', 'url' => 'agent-complaints.php?month=1'],
    ['label' => "Today's Complaints", 'value' => $todayCount, 'icon' => 'bi-calendar-check', 'class' => 'indigo', 'url' => 'agent-complaints.php?today=1'],
    ['label' => 'POD Required', 'value' => $podRequiredCount, 'icon' => 'bi-file-earmark-check', 'class' => 'teal', 'url' => 'agent-complaints.php?complaint_type=POD+Required'],
    ['label' => 'Wrong Delivery', 'value' => $wrongDeliveryCount, 'icon' => 'bi-geo-alt', 'class' => 'pink', 'url' => 'agent-complaints.php?complaint_type=Wrong+Delivery'],
    ['label' => 'New Material', 'value' => $newMaterialCount, 'icon' => 'bi-box-seam', 'class' => 'brown', 'url' => 'agent-complaints.php?material=1', 'small' => 'Lost + Damaged'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Dashboard - GRAND SPEED NETWORK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --page-bg:#f4f7fb; --text-main:#172033; --text-soft:#64748b; --card-radius:18px; }
        body { background:radial-gradient(circle at top right,rgba(13,110,253,.08),transparent 28%),var(--page-bg); color:var(--text-main); min-height:100vh; }
        .topbar { background:linear-gradient(110deg,#0b5ed7,#4338ca); box-shadow:0 12px 35px rgba(30,64,175,.22); }
        .brand-box { width:44px; height:44px; border-radius:13px; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,.96); color:#0d6efd; font-weight:900; }
        .dashboard-card { border:0; border-radius:var(--card-radius); box-shadow:0 12px 32px rgba(15,23,42,.07); }
        .metric-link { text-decoration:none; color:inherit; }
        .metric-card { position:relative; overflow:hidden; min-height:138px; transition:transform .2s ease,box-shadow .2s ease; }
        .metric-card:hover { transform:translateY(-5px); box-shadow:0 18px 38px rgba(15,23,42,.14); }
        .metric-card::after { content:""; position:absolute; right:-35px; bottom:-45px; width:110px; height:110px; border-radius:50%; background:currentColor; opacity:.07; }
        .metric-icon { width:46px; height:46px; border-radius:14px; display:inline-flex; align-items:center; justify-content:center; font-size:1.25rem; background:currentColor; }
        .metric-icon i { color:#fff; }
        .metric-label { color:var(--text-soft); font-size:.78rem; font-weight:800; letter-spacing:.045em; text-transform:uppercase; }
        .metric-number { font-size:2rem; font-weight:800; line-height:1; }
        .blue{color:#2563eb}.cyan{color:#0891b2}.green{color:#16a34a}.red{color:#dc2626}.orange{color:#ea580c}.purple{color:#9333ea}.indigo{color:#4f46e5}.teal{color:#0f766e}.pink{color:#db2777}.brown{color:#9a3412}
        .quick-btn { min-height:70px; border-radius:14px; font-weight:700; display:flex; align-items:center; justify-content:center; gap:8px; }
        .section-title { font-weight:800; margin-bottom:.25rem; }
        .reminder-card { border:1px solid #e2e8f0; border-radius:16px; padding:16px; height:100%; background:#fff; transition:transform .2s ease,border-color .2s ease; }
        .reminder-card:hover { transform:translateY(-3px); border-color:#93c5fd; }
        .reminder-card.overdue { border-left:5px solid #dc3545; background:#fff8f8; }
        .reminder-card.today { border-left:5px solid #f59e0b; background:#fffdf4; }
        .reminder-card.upcoming { border-left:5px solid #0d6efd; }
        .reminder-card.no-date { border-left:5px solid #64748b; }
        .table thead th { color:#64748b; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
        .summary-item { padding:14px 0; border-bottom:1px solid #eef2f7; }
        .summary-item:last-child { border-bottom:0; padding-bottom:0; }
        .vendor-table thead th { background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        .vendor-table td, .vendor-table th { vertical-align:middle; }
        .vendor-link { color:inherit; text-decoration:none; font-weight:700; }
        .vendor-link:hover { color:#0d6efd; text-decoration:underline; }
        .count-link { min-width:54px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
        .count-link:hover { transform:translateY(-1px); }
        @media(max-width:576px){.metric-card{min-height:126px}.metric-number{font-size:1.75rem}}
    </style>
</head>
<body>
<nav class="navbar navbar-dark topbar">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="agent-dashboard.php">
            <span class="brand-box">GSN</span>
            <span class="d-none d-sm-inline">GRAND SPEED NETWORK</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
            <div class="text-white text-end d-none d-sm-block"><div class="small opacity-75">Welcome</div><strong><?php echo e($agentName); ?></strong></div>
            <a href="agent-logout.php" class="btn btn-light btn-sm px-3"><i class="bi bi-box-arrow-right me-1"></i>Logout</a>
        </div>
    </div>
</nav>
<main class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
        <div><h1 class="h3 fw-bold mb-1">Agent Dashboard</h1><p class="text-muted mb-0">Your assigned complaints, priorities and daily reminders.</p></div>
        <div class="badge rounded-pill text-bg-light border px-3 py-2"><i class="bi bi-calendar3 me-1"></i><?php echo date('d M Y'); ?></div>
    </div>

    <section class="row g-3 mb-4">
        <div class="col-6 col-md-3"><a class="btn btn-primary quick-btn w-100" href="agent-add-complaint.php"><i class="bi bi-plus-circle"></i>Add Complaint</a></div>
        <div class="col-6 col-md-3"><a class="btn btn-outline-primary quick-btn w-100" href="agent-complaints.php"><i class="bi bi-list-check"></i>My Complaints</a></div>
        <div class="col-6 col-md-3"><a class="btn btn-outline-success quick-btn w-100" href="agent-import.php"><i class="bi bi-file-earmark-arrow-up"></i>Import CSV</a></div>
        <div class="col-6 col-md-3"><a class="btn btn-outline-danger quick-btn w-100" href="agent-logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a></div>
    </section>

    <div class="card dashboard-card mb-4"><div class="card-body"><form action="agent-complaints.php" method="GET"><div class="row g-2"><div class="col-md-10"><div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span><input type="text" name="search" class="form-control" placeholder="Search Complaint ID, Tracking Number, Customer Name or Mobile"></div></div><div class="col-md-2 d-grid"><button type="submit" class="btn btn-primary">Search</button></div></div></form></div></div>

    <section class="row g-3 mb-4">
        <?php foreach ($cards as $card): ?>
            <div class="col-6 col-md-4 col-xl-3 col-xxl">
                <a href="<?php echo e($card['url']); ?>" class="metric-link">
                    <div class="card dashboard-card metric-card h-100 <?php echo e($card['class']); ?>"><div class="card-body position-relative">
                        <div class="d-flex justify-content-between align-items-start mb-3"><div class="metric-label"><?php echo e($card['label']); ?></div><div class="metric-icon"><i class="bi <?php echo e($card['icon']); ?>"></i></div></div>
                        <div class="metric-number text-dark"><?php echo (int)$card['value']; ?></div>
                        <?php if (!empty($card['small'])): ?><small class="text-muted"><?php echo e($card['small']); ?></small><?php endif; ?>
                    </div></div>
                </a>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="card dashboard-card mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4"><div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2"><div><h2 class="h5 section-title"><i class="bi bi-pin-angle-fill text-danger me-1"></i>Pinned Complaints & Reminders</h2><p class="text-muted small mb-0">Complaints marked for follow-up or completion.</p></div><span class="badge rounded-pill text-bg-danger px-3 py-2"><?php echo $pinnedCount; ?> Pinned</span></div></div>
        <div class="card-body px-4 pb-4"><div class="row g-3">
            <?php if ($pinnedResult && mysqli_num_rows($pinnedResult) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($pinnedResult)): ?>
                    <?php
                    $reminderDate = $row['reminder_date'] ?? null;
                    $today = date('Y-m-d');
                    if (!$reminderDate) { $reminderClass='no-date'; $reminderBadge='No Date'; $badgeClass='text-bg-secondary'; }
                    elseif ($reminderDate < $today) { $reminderClass='overdue'; $reminderBadge='Overdue'; $badgeClass='text-bg-danger'; }
                    elseif ($reminderDate === $today) { $reminderClass='today'; $reminderBadge='Due Today'; $badgeClass='text-bg-warning'; }
                    else { $reminderClass='upcoming'; $reminderBadge='Upcoming'; $badgeClass='text-bg-primary'; }
                    ?>
                    <div class="col-md-6 col-xl-4"><div class="reminder-card <?php echo e($reminderClass); ?>">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2"><div><div class="fw-bold text-primary"><?php echo e($row['complaint_id'] ?: $row['id']); ?></div><div class="small text-muted"><?php echo e($row['complaint_type']); ?></div></div><span class="badge <?php echo e($badgeClass); ?>"><?php echo e($reminderBadge); ?></span></div>
                        <div class="fw-semibold mb-1"><?php echo e($row['customer_name']); ?></div>
                        <div class="small text-muted mb-2"><?php echo e($row['job_name'] ?: 'No Job'); ?> · <?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></div>
                        <?php if (!empty($row['reminder_note'])): ?><div class="p-2 rounded bg-light small mb-3"><i class="bi bi-journal-text me-1"></i><?php echo e($row['reminder_note']); ?></div><?php endif; ?>
                        <div class="d-flex justify-content-between align-items-center gap-2"><div class="small"><i class="bi bi-calendar-event me-1"></i><?php echo $reminderDate ? e(date('d M Y', strtotime($reminderDate))) : 'Not set'; ?></div><a class="btn btn-sm btn-outline-primary" href="agent-complaint-details.php?id=<?php echo (int)$row['id']; ?>">Open</a></div>
                    </div></div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12"><div class="text-center text-muted py-5"><i class="bi bi-pin-angle display-5 d-block mb-2"></i>No pinned complaints yet.</div></div>
            <?php endif; ?>
        </div></div>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-8"><div class="card dashboard-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 section-title"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Most Urgent Complaints</h2></div><div class="card-body px-4 pb-4"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Complaint ID</th><th>Customer</th><th>Type</th><th>Vendor</th><th>Job</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>
            <?php if ($urgentResult && mysqli_num_rows($urgentResult) > 0): ?>
                <?php while ($row = mysqli_fetch_assoc($urgentResult)): ?><tr><td class="fw-semibold"><?php echo e($row['complaint_id'] ?: $row['id']); ?></td><td><?php echo e($row['customer_name']); ?></td><td><?php echo e($row['complaint_type']); ?></td><td><?php echo e($row['vendor_name'] ?: 'No Vendor'); ?></td><td><?php echo e($row['job_name'] ?: 'No Job'); ?></td><td><span class="badge <?php echo status_badge($row['status']); ?>"><?php echo e($row['status']); ?></span></td><td><?php echo e(date('d M Y', strtotime($row['complaint_date']))); ?></td><td><a class="btn btn-sm btn-outline-danger" href="agent-complaint-details.php?id=<?php echo (int)$row['id']; ?>">View</a></td></tr><?php endwhile; ?>
            <?php else: ?><tr><td colspan="8" class="text-center text-muted py-5">No pending most urgent complaints found.</td></tr><?php endif; ?>
        </tbody></table></div></div></div></div>

        <div class="col-xl-4"><div class="card dashboard-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 section-title">Work Snapshot</h2></div><div class="card-body px-4">
            <div class="summary-item d-flex justify-content-between"><span class="text-muted">Pinned Work</span><strong><?php echo $pinnedCount; ?></strong></div>
            <div class="summary-item d-flex justify-content-between"><span class="text-muted">Open Complaints</span><strong><?php echo $openCount; ?></strong></div>
            <div class="summary-item d-flex justify-content-between"><span class="text-muted">Old Pending</span><strong class="text-danger"><?php echo $oldComplaintsCount; ?></strong></div>
            <div class="summary-item d-flex justify-content-between"><span class="text-muted">New Material</span><strong><?php echo $newMaterialCount; ?></strong></div>
            <div class="summary-item d-flex justify-content-between"><span class="text-muted">Closed</span><strong class="text-success"><?php echo $closedCount; ?></strong></div>
        </div></div></div>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-6"><div class="card dashboard-card h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 section-title">Job Summary</h2></div><div class="card-body px-4">
            <?php if ($jobSummaryResult && mysqli_num_rows($jobSummaryResult) > 0): ?><?php while ($row = mysqli_fetch_assoc($jobSummaryResult)): ?><div class="summary-item"><div class="fw-semibold mb-2"><?php echo e($row['job_name']); ?></div><div class="d-flex flex-wrap gap-2"><span class="badge text-bg-primary">Total <?php echo (int)$row['total_count']; ?></span><span class="badge text-bg-warning">Pending <?php echo (int)$row['pending_count']; ?></span><span class="badge text-bg-success">Closed <?php echo (int)$row['closed_count']; ?></span></div></div><?php endwhile; ?><?php else: ?><div class="text-muted py-4 text-center">No assigned jobs found.</div><?php endif; ?>
        </div></div></div>
        <div class="col-xl-6">
            <div class="card dashboard-card h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <h2 class="h5 section-title">Vendor Summary</h2>
                            <p class="text-muted small mb-0">Click any vendor or count to open filtered complaints.</p>
                        </div>
                        <i class="bi bi-truck fs-3 text-primary"></i>
                    </div>
                </div>

                <div class="card-body px-4 pb-4">
                    <?php if ($vendorSummaryResult && mysqli_num_rows($vendorSummaryResult) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover vendor-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Vendor Name</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">Open</th>
                                        <th class="text-center">Pending</th>
                                        <th class="text-center">Closed</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = mysqli_fetch_assoc($vendorSummaryResult)): ?>
                                        <?php
                                            $vendorId = (int)($row['vendor_id'] ?? 0);
                                            $baseUrl = 'agent-complaints.php?vendor_id=' . $vendorId;
                                        ?>
                                        <tr>
                                            <td>
                                                <a class="vendor-link" href="<?php echo e($baseUrl); ?>">
                                                    <?php echo e($row['vendor_name']); ?>
                                                </a>
                                            </td>

                                            <td class="text-center">
                                                <a class="badge rounded-pill text-bg-dark count-link" href="<?php echo e($baseUrl); ?>">
                                                    <?php echo (int)$row['total_count']; ?>
                                                </a>
                                            </td>

                                            <td class="text-center">
                                                <a class="badge rounded-pill text-bg-primary count-link" href="<?php echo e($baseUrl . '&status=Open'); ?>">
                                                    <?php echo (int)$row['open_count']; ?>
                                                </a>
                                            </td>

                                            <td class="text-center">
                                                <a class="badge rounded-pill text-bg-warning count-link" href="<?php echo e($baseUrl . '&status=Pending'); ?>">
                                                    <?php echo (int)$row['pending_count']; ?>
                                                </a>
                                            </td>

                                            <td class="text-center">
                                                <a class="badge rounded-pill text-bg-success count-link" href="<?php echo e($baseUrl . '&status=Closed'); ?>">
                                                    <?php echo (int)$row['closed_count']; ?>
                                                </a>
                                            </td>

                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo e($baseUrl); ?>">
                                                    View <i class="bi bi-arrow-right ms-1"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-muted py-4 text-center">No vendor complaints found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
if (isset($pinnedStmt) && $pinnedStmt) mysqli_stmt_close($pinnedStmt);
if (isset($urgentStmt) && $urgentStmt) mysqli_stmt_close($urgentStmt);
if (isset($jobStmt) && $jobStmt) mysqli_stmt_close($jobStmt);
if (isset($vendorStmt) && $vendorStmt) mysqli_stmt_close($vendorStmt);
?>
