<?php
session_start();
require_once 'db.php';

// This file authenticates agents only. It does not read or modify admin sessions.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: agent-login.php');
    exit;
}

$username = mysqli_real_escape_string($conn, trim((string)($_POST['username'] ?? '')));
$password = trim((string)($_POST['password'] ?? ''));

if ($username === '' || $password === '') {
    header('Location: agent-login.php?error=' . urlencode('Username and password are required.'));
    exit;
}

// Only Active agents are allowed to log in.
$sql = "SELECT id, agent_name, username, password, status
        FROM agents
        WHERE username = '$username' AND status = 'Active'
        LIMIT 1";
$result = mysqli_query($conn, $sql);
$agent = $result ? mysqli_fetch_assoc($result) : null;

if ($agent && password_verify($password, $agent['password'])) {
    session_regenerate_id(true);

    $_SESSION['agent_id'] = (int)$agent['id'];
    $_SESSION['agent_name'] = $agent['agent_name'];

    header('Location: agent-dashboard.php');
    exit;
}

header('Location: agent-login.php?error=' . urlencode('Invalid username or password, or your account is inactive.'));
exit;
