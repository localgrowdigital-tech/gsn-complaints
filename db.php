<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
|--------------------------------------------------------------------------
| Railway Database Connection
|--------------------------------------------------------------------------
*/

$host = getenv("MYSQLHOST");
$user = getenv("MYSQLUSER");
$pass = getenv("MYSQLPASSWORD");
$dbname = getenv("MYSQL_DATABASE");

// Agar MYSQL_DATABASE na mile to ye use hoga
if (empty($dbname)) {
    $dbname = getenv("MYSQLDATABASE");
}

$port = getenv("MYSQLPORT");

$conn = mysqli_connect($host, $user, $pass, $dbname, (int)$port);

if (!$conn) {
    die("Database Connection Failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8");

?>