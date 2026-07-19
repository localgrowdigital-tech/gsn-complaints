<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: agent-login.php');
    exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header(
        'Location: agent-login.php?error=' .
        urlencode('Username and password are required.')
    );
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT id, agent_name, username, password, status
     FROM agents
     WHERE username = ? AND status = 'Active'
     LIMIT 1"
);

if (!$stmt) {
    error_log('Agent login prepare failed: ' . mysqli_error($conn));

    header(
        'Location: agent-login.php?error=' .
        urlencode('Login temporarily unavailable. Please try again.')
    );
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$agent = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if ($agent && password_verify($password, $agent['password'])) {
    session_regenerate_id(true);

    $_SESSION['agent_id'] = (int)$agent['id'];
    $_SESSION['agent_name'] = $agent['agent_name'];
    $_SESSION['agent_last_activity'] = time();

    header('Location: agent-dashboard.php');
    exit;
}

header(
    'Location: agent-login.php?error=' .
    urlencode('Invalid username or password, or your account is inactive.')
);
exit;