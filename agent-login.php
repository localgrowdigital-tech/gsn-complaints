<?php
session_start();

// If the agent is already logged in, send them to the agent dashboard.
if (isset($_SESSION['agent_id'])) {
    header('Location: agent-dashboard.php');
    exit;
}

$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$loggedOut = isset($_GET['logout']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Login - GRAND SPEED NETWORK</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background:
                linear-gradient(135deg, rgba(13, 110, 253, .94), rgba(8, 47, 105, .96)),
                radial-gradient(circle at top left, rgba(255, 255, 255, .22), transparent 34%);
        }
        .login-shell {
            min-height: 100vh;
        }
        .brand-mark {
            width: 58px;
            height: 58px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #0d6efd;
            color: #fff;
            font-weight: 800;
            letter-spacing: .5px;
            box-shadow: 0 12px 28px rgba(13, 110, 253, .25);
        }
        .login-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 24px 60px rgba(2, 8, 23, .24);
        }
        .form-control {
            min-height: 46px;
        }
    </style>
</head>
<body>
<main class="container login-shell d-flex align-items-center justify-content-center py-5">
    <div class="row justify-content-center w-100">
        <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">
            <div class="card login-card">
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="brand-mark mb-3">GSN</div>
                        <div class="text-primary fw-bold text-uppercase small">GRAND SPEED NETWORK</div>
                        <h1 class="h3 mt-2 mb-0">Agent Login</h1>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($loggedOut): ?>
                        <div class="alert alert-success" role="alert">
                            You have been logged out successfully.
                        </div>
                    <?php endif; ?>

                    <form method="post" action="agent-auth.php">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" autocomplete="username" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me">
                                <label class="form-check-label" for="remember_me">Remember Me</label>
                            </div>
                            <span class="text-muted small">Forgot Password? Coming Soon</span>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
