<?php
session_start();

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username == "admin" && $password == "12345") {
        $_SESSION['admin'] = $username;
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Wrong username or password";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>GSN Login</title>
</head>
<body>

<h2>GRAND SPEED NETWORK</h2>
<h3>Complaint Login</h3>

<?php if (isset($error)) { echo "<p style='color:red;'>$error</p>"; } ?>

<form method="POST">
    <input type="text" name="username" placeholder="Username" required><br><br>
    <input type="password" name="password" placeholder="Password" required><br><br>
    <button type="submit" name="login">Login</button>
</form>

</body>
</html>