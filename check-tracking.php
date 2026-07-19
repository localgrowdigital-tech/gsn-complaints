<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['agent_id'])) {
    respond_json(401, [
        'ok' => false,
        'message' => 'Your session has expired. Please log in again.'
    ]);
}

$agentId = (int) $_SESSION['agent_id'];
$trackingNumber = trim((string) ($_GET['tracking_number'] ?? ''));

if ($trackingNumber === '' || mb_strlen($trackingNumber) < 3) {
    respond_json(422, [
        'ok' => false,
        'message' => 'Please enter at least 3 tracking characters.'
    ]);
}

if (mb_strlen($trackingNumber) > 150) {
    respond_json(422, [
        'ok' => false,
        'message' => 'Tracking number is too long.'
    ]);
}

$sql = "
    SELECT
        c.id,
        c.complaint_id,
        c.customer_name,
        c.status,
        c.complaint_date,
        j.job_name,
        v.vendor_name
    FROM complaints c
    INNER JOIN agent_jobs aj
        ON aj.job_id = c.job_id
       AND aj.agent_id = ?
    LEFT JOIN jobs j ON j.id = c.job_id
    LEFT JOIN vendors v ON v.id = c.vendor_id
    WHERE TRIM(c.tracking_number) = ?
      AND c.status IN ('Open', 'In Progress')
    ORDER BY c.id DESC
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    respond_json(500, [
        'ok' => false,
        'message' => 'Unable to prepare tracking check.'
    ]);
}

mysqli_stmt_bind_param($stmt, 'is', $agentId, $trackingNumber);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$complaint = mysqli_fetch_assoc($result) ?: null;
mysqli_stmt_close($stmt);

if (!$complaint) {
    respond_json(200, [
        'ok' => true,
        'exists' => false,
        'complaint' => null
    ]);
}

$complaint['id'] = (int) $complaint['id'];
$complaint['job_name'] = $complaint['job_name'] ?: 'No Job';
$complaint['vendor_name'] = $complaint['vendor_name'] ?: 'No Vendor';
$complaint['complaint_date_formatted'] = !empty($complaint['complaint_date'])
    ? date('d M Y', strtotime($complaint['complaint_date']))
    : '';

respond_json(200, [
    'ok' => true,
    'exists' => true,
    'complaint' => $complaint
]);
