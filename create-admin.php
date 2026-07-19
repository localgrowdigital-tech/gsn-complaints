<?php

$password = '12345';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

echo '<h2>Admin Password Hash</h2>';
echo '<textarea style="width:100%;height:120px;">';
echo htmlspecialchars($passwordHash, ENT_QUOTES, 'UTF-8');
echo '</textarea>';