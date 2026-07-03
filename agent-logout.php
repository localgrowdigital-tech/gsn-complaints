<?php
session_start();

// Clear agent session values and destroy the current session.
unset($_SESSION['agent_id'], $_SESSION['agent_name']);
session_destroy();

header('Location: agent-login.php?logout=1');
exit;
