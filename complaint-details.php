<?php
session_start();
require_once 'db.php';
require_once 'includes/timeline.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function status_badge_class($status)
{
    return match ($status) {
        'Open' => 'text-bg-primary',
        'In Progress' => 'text-bg-warning',
        'Resolved' => 'text-bg-success',
        'Closed' => 'text-bg-secondary',
        default => 'text-bg-light',
    };
}

function priority_badge_class($priority)
{
    return match ($priority) {
        'Most Urgent' => 'text-bg-danger',
        'Urgent' => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
}

function display_date($value, $with_time = false)
{
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '—';
    }

    $timestamp = strtotime($value);
    if (!$timestamp) {
        return e($value);
    }

    return date($with_time ? 'd M Y, h:i A' : 'd M Y', $timestamp);
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    header('Location: view-complaints.php');
    exit;
}

$stmt = mysqli_prepare($conn, "
    SELECT c.*, j.job_name, v.vendor_name,
           CASE
               WHEN c.status IN ('Closed', 'Resolved') AND c.closing_date IS NOT NULL
               THEN DATEDIFF(c.closing_date, c.complaint_date)
               ELSE DATEDIFF(CURDATE(), c.complaint_date)
           END AS pending_days
    FROM complaints c
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE c.id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Unable to load complaint.');
}

mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$complaint = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($stmt);

if (!$complaint) {
    http_response_code(404);
    die('Complaint not found.');
}

$remarks_stmt = mysqli_prepare($conn, "
    SELECT id, status, remark, remark_date
    FROM complaint_remarks
    WHERE complaint_id = ?
    ORDER BY id DESC
");
$remarks = null;
if ($remarks_stmt) {
    mysqli_stmt_bind_param($remarks_stmt, 'i', $id);
    mysqli_stmt_execute($remarks_stmt);
    $remarks = mysqli_stmt_get_result($remarks_stmt);
}

$timeline_stmt = mysqli_prepare($conn, "
    SELECT action, details, user_type, user_name, created_at
    FROM complaint_timeline
    WHERE complaint_id = ?
    ORDER BY created_at DESC
");
$timeline = null;
if ($timeline_stmt) {
    mysqli_stmt_bind_param($timeline_stmt, 'i', $id);
    mysqli_stmt_execute($timeline_stmt);
    $timeline = mysqli_stmt_get_result($timeline_stmt);
}

$pending_days = max(0, (int)($complaint['pending_days'] ?? 0));
$back_url = 'view-complaints.php';
if (!empty($_GET['back']) && is_string($_GET['back']) && str_starts_with($_GET['back'], '/')) {
    $back_url = $_GET['back'];
}

$pageTitle = 'Complaint Details';
$pageHeading = 'Complaint Details';
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<style>
    .details-hero {
        position: relative;
        overflow: hidden;
        border: 0;
        border-radius: 24px;
        color: #fff;
        background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 58%, #2563eb 100%);
        box-shadow: 0 22px 55px rgba(15, 23, 42, .18);
    }
    .details-hero::after {
        content: "";
        position: absolute;
        width: 230px;
        height: 230px;
        right: -80px;
        top: -100px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .1);
    }
    .hero-stat {
        padding: 15px 18px;
        border: 1px solid rgba(255, 255, 255, .16);
        border-radius: 16px;
        background: rgba(255, 255, 255, .09);
        backdrop-filter: blur(8px);
    }
    .hero-stat-label {
        display: block;
        margin-bottom: 4px;
        color: rgba(255, 255, 255, .7);
        font-size: .74rem;
        font-weight: 700;
        letter-spacing: .08em;
        text-transform: uppercase;
    }
    .info-card,
    .timeline-card {
        border: 0;
        border-radius: 20px;
        box-shadow: 0 14px 34px rgba(15, 23, 42, .07);
    }
    .section-heading {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 22px;
    }
    .section-icon {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 13px;
        color: #1d4ed8;
        background: #eaf1ff;
        font-size: 1.1rem;
    }
    .detail-item {
        height: 100%;
        padding: 16px 17px;
        border: 1px solid #e8edf5;
        border-radius: 15px;
        background: #fbfcff;
    }
    .detail-label {
        margin-bottom: 6px;
        color: #748097;
        font-size: .73rem;
        font-weight: 800;
        letter-spacing: .07em;
        text-transform: uppercase;
    }
    .detail-value {
        color: #172033;
        font-weight: 650;
        overflow-wrap: anywhere;
    }
    .text-panel {
        min-height: 150px;
        padding: 20px;
        border: 1px solid #e8edf5;
        border-radius: 16px;
        color: #334155;
        background: #fbfcff;
        line-height: 1.75;
    }
    .activity-item {
        position: relative;
        padding-left: 44px;
        padding-bottom: 28px;
    }
    .activity-item:not(:last-child)::before {
        content: "";
        position: absolute;
        left: 15px;
        top: 32px;
        bottom: -2px;
        width: 2px;
        background: #dbe5f3;
    }
    .activity-dot {
        position: absolute;
        left: 0;
        top: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #fff;
        background: #2563eb;
        box-shadow: 0 0 0 6px #edf4ff;
    }
    .activity-box {
        padding: 17px 18px;
        border: 1px solid #e5ebf4;
        border-radius: 16px;
        background: #fff;
    }
    .empty-state {
        padding: 44px 20px;
        text-align: center;
        color: #7c879b;
    }
    @media print {
        .no-print,
        .sidebar,
        .topbar,
        .mobile-overlay { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .details-hero { color: #111827 !important; background: #fff !important; box-shadow: none; border: 1px solid #dbe2ea; }
        .hero-stat { border-color: #dbe2ea; background: #fff; }
        .hero-stat-label { color: #64748b; }
        .info-card, .timeline-card { box-shadow: none; border: 1px solid #dbe2ea; break-inside: avoid; }
    }
</style>

<div class="content-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 no-print">
    <div>
        <span class="page-eyebrow">GRAND SPEED NETWORK</span>
        <h1 class="page-title mb-1">Complaint Details</h1>
        <p class="page-subtitle mb-0">Full complaint information, remarks and activity timeline.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo e($back_url); ?>" class="btn btn-light px-3">
            <i class="bi bi-arrow-left me-2"></i>Back
        </a>
        <a href="remarks.php?id=<?php echo (int)$complaint['id']; ?>&back=<?php echo rawurlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-primary px-3">
            <i class="bi bi-chat-left-text me-2"></i>Add Remark
        </a>
        <button type="button" onclick="window.print()" class="btn btn-dark px-3">
            <i class="bi bi-printer me-2"></i>Print
        </button>
    </div>
</div>

<section class="details-hero mb-4">
    <div class="position-relative p-4 p-xl-5" style="z-index:1;">
        <div class="d-flex flex-column flex-xl-row justify-content-between gap-4">
            <div>
                <div class="text-uppercase fw-bold small opacity-75 mb-2">Complaint Reference</div>
                <h2 class="display-6 fw-bold mb-3"><?php echo e($complaint['complaint_id'] ?: ('#' . $complaint['id'])); ?></h2>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge rounded-pill <?php echo status_badge_class($complaint['status']); ?> px-3 py-2">
                        <?php echo e($complaint['status']); ?>
                    </span>
                    <span class="badge rounded-pill <?php echo priority_badge_class($complaint['priority'] ?? 'Normal'); ?> px-3 py-2">
                        <?php echo e($complaint['priority'] ?? 'Normal'); ?>
                    </span>
                </div>
            </div>

            <div class="row g-2 flex-grow-1" style="max-width:680px;">
                <div class="col-12 col-sm-4">
                    <div class="hero-stat h-100">
                        <span class="hero-stat-label">Complaint Date</span>
                        <strong><?php echo display_date($complaint['complaint_date']); ?></strong>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="hero-stat h-100">
                        <span class="hero-stat-label">Closing Date</span>
                        <strong><?php echo display_date($complaint['closing_date']); ?></strong>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="hero-stat h-100">
                        <span class="hero-stat-label">Pending Days</span>
                        <strong><?php echo $pending_days; ?> Day<?php echo $pending_days === 1 ? '' : 's'; ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <section class="card info-card h-100">
            <div class="card-body p-4">
                <div class="section-heading">
                    <span class="section-icon"><i class="bi bi-box-seam"></i></span>
                    <div><h3 class="h5 fw-bold mb-1">Shipment Information</h3><div class="text-muted small">Complaint and courier reference details</div></div>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Complaint ID</div><div class="detail-value"><?php echo e($complaint['complaint_id']); ?></div></div></div>
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Complaint Type</div><div class="detail-value"><?php echo e($complaint['complaint_type'] ?: '—'); ?></div></div></div>
                    <div class="col-12"><div class="detail-item"><div class="detail-label">Tracking Number</div><div class="detail-value"><?php echo e($complaint['tracking_number'] ?: '—'); ?></div></div></div>
                    <div class="col-12"><div class="detail-item"><div class="detail-label">Secondary Tracking</div><div class="detail-value"><?php echo e($complaint['secondary_tracking_number'] ?: '—'); ?></div></div></div>
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Job</div><div class="detail-value"><?php echo e($complaint['job_name'] ?: 'No Job'); ?></div></div></div>
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Vendor</div><div class="detail-value"><?php echo e($complaint['vendor_name'] ?: 'No Vendor'); ?></div></div></div>
                </div>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card info-card h-100">
            <div class="card-body p-4">
                <div class="section-heading">
                    <span class="section-icon"><i class="bi bi-person-vcard"></i></span>
                    <div><h3 class="h5 fw-bold mb-1">Customer Information</h3><div class="text-muted small">Customer contact and complaint status</div></div>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Customer Name</div><div class="detail-value"><?php echo e($complaint['customer_name'] ?: '—'); ?></div></div></div>
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Mobile</div><div class="detail-value"><?php echo e($complaint['mobile'] ?: '—'); ?></div></div></div>
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Status</div><div class="detail-value"><span class="badge <?php echo status_badge_class($complaint['status']); ?>"><?php echo e($complaint['status']); ?></span></div></div></div>
                    <div class="col-sm-6"><div class="detail-item"><div class="detail-label">Priority</div><div class="detail-value"><span class="badge <?php echo priority_badge_class($complaint['priority'] ?? 'Normal'); ?>"><?php echo e($complaint['priority'] ?? 'Normal'); ?></span></div></div></div>
                    <div class="col-12"><div class="detail-item"><div class="detail-label">Address</div><div class="detail-value fw-normal"><?php echo nl2br(e($complaint['address'] ?: '—')); ?></div></div></div>
                </div>
            </div>
        </section>
    </div>
</div>

<section class="card info-card mb-4">
    <div class="card-body p-4">
        <div class="section-heading">
            <span class="section-icon"><i class="bi bi-file-earmark-text"></i></span>
            <div><h3 class="h5 fw-bold mb-1">Complaint Description</h3><div class="text-muted small">Issue details entered with the complaint</div></div>
        </div>
        <div class="text-panel"><?php echo nl2br(e($complaint['description'] ?: 'No description provided.')); ?></div>
    </div>
</section>

<div class="row g-4">
    <div class="col-xl-6">
        <section class="card timeline-card h-100">
            <div class="card-body p-4">
                <div class="section-heading">
                    <span class="section-icon"><i class="bi bi-chat-square-text"></i></span>
                    <div><h3 class="h5 fw-bold mb-1">Remark History</h3><div class="text-muted small">Latest remarks appear first</div></div>
                </div>

                <?php if ($remarks && mysqli_num_rows($remarks) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($remarks)): ?>
                        <div class="activity-item">
                            <span class="activity-dot"><i class="bi bi-chat-dots"></i></span>
                            <div class="activity-box">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-2">
                                    <span class="badge <?php echo status_badge_class($row['status'] ?? ''); ?> align-self-start"><?php echo e($row['status'] ?: 'Remark'); ?></span>
                                    <span class="text-muted small"><i class="bi bi-clock me-1"></i><?php echo display_date($row['remark_date'], true); ?></span>
                                </div>
                                <div class="text-secondary"><?php echo nl2br(e($row['remark'])); ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-chat-square-dots fs-1 d-block mb-3"></i>No remarks found.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="col-xl-6">
        <section class="card timeline-card h-100">
            <div class="card-body p-4">
                <div class="section-heading">
                    <span class="section-icon"><i class="bi bi-clock-history"></i></span>
                    <div><h3 class="h5 fw-bold mb-1">Complaint Timeline</h3><div class="text-muted small">Complete activity history</div></div>
                </div>

                <?php if ($timeline && mysqli_num_rows($timeline) > 0): ?>
                    <?php while ($row = mysqli_fetch_assoc($timeline)): ?>
                        <div class="activity-item">
                            <span class="activity-dot"><i class="bi bi-check2"></i></span>
                            <div class="activity-box">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-2">
                                    <strong><?php echo e($row['action']); ?></strong>
                                    <span class="text-muted small"><i class="bi bi-clock me-1"></i><?php echo display_date($row['created_at'], true); ?></span>
                                </div>
                                <div class="small text-muted mb-2">
                                    <?php echo e($row['user_type'] ?: 'User'); ?>: <strong><?php echo e($row['user_name'] ?: 'Unknown'); ?></strong>
                                </div>
                                <?php if (!empty($row['details'])): ?>
                                    <div class="text-secondary"><?php echo nl2br(e($row['details'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-clock-history fs-1 d-block mb-3"></i>No timeline available.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php
if ($remarks_stmt) {
    mysqli_stmt_close($remarks_stmt);
}
if ($timeline_stmt) {
    mysqli_stmt_close($timeline_stmt);
}
include 'includes/footer.php';
?>
