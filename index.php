<?php
session_start();

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === "admin" && $password === "12345") {
        $_SESSION['admin'] = $username;
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Wrong username or password";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin Login - GRAND SPEED NETWORK</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(255,255,255,.16), transparent 32%),
                linear-gradient(135deg, #0d6efd 0%, #084298 100%);
            font-family: Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-card {
            width: 100%;
            max-width: 460px;
            border: 0;
            border-radius: 22px;
            box-shadow: 0 25px 65px rgba(0, 0, 0, .25);
            overflow: hidden;
        }

        .login-card .card-body {
            padding: 42px;
        }

        .logo-box {
            width: 72px;
            height: 72px;
            margin: 0 auto 18px;
            border-radius: 18px;
            background: #0d6efd;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            font-weight: 800;
            box-shadow: 0 12px 28px rgba(13, 110, 253, .28);
        }

        .company-name {
            color: #0d6efd;
            font-weight: 800;
            letter-spacing: .5px;
        }

        .form-control {
            min-height: 52px;
            border-radius: 12px;
        }

        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 .22rem rgba(13, 110, 253, .15);
        }

        .login-btn {
            min-height: 52px;
            border-radius: 12px;
            font-weight: 700;
        }

        .agent-link {
            text-decoration: none;
            font-weight: 600;
        }

        .agent-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 576px) {
            .login-card .card-body {
                padding: 30px 22px;
            }
        }
    </style>
</head>

<body>

<div class="card login-card">
    <div class="card-body">

        <div class="text-center mb-4">
            <div class="logo-box">GSN</div>

            <div class="company-name mb-2">
                GRAND SPEED NETWORK
            </div>

            <h1 class="h3 fw-bold mb-1">
                Admin Login
            </h1>

            <p class="text-muted mb-0">
                Complaint Management Portal
            </p>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="mb-3">
                <label for="username" class="form-label">
                    Username
                </label>

                <input
                    type="text"
                    name="username"
                    id="username"
                    class="form-control"
                    placeholder="Enter admin username"
                    value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                    autocomplete="username"
                    required
                    autofocus
                >
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">
                    Password
                </label>

                <input
                    type="password"
                    name="password"
                    id="password"
                    class="form-control"
                    placeholder="Enter password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="form-check mb-4">
                <input
                    class="form-check-input"
                    type="checkbox"
                    id="showPassword"
                    onclick="togglePassword()"
                >

                <label class="form-check-label" for="showPassword">
                    Show Password
                </label>
            </div>

            <button
                type="submit"
                name="login"
                class="btn btn-primary login-btn w-100"
            >
                Login to Dashboard
            </button>

        </form>

        <div class="text-center mt-4">
            <span class="text-muted">Agent account?</span>

            <a href="agent-login.php" class="agent-link ms-1">
                Agent Login
            </a>
        </div>

    </div>
</div>

<script>
function togglePassword() {
    const passwordField = document.getElementById('password');

    passwordField.type =
        passwordField.type === 'password' ? 'text' : 'password';
}
</script>

</body>
</html>