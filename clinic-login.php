
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/mongodb.php';
require_once __DIR__ . '/includes/functions.php';

$accounts = $mongoDb->selectCollection('clinic_accounts');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $account = $accounts->findOne([
        'email' => $email,
        'status' => ['$in' => ['Active', 'Approved']]
    ]);

    if ($account && password_verify(
        $password,
        (string) ($account['password_hash'] ?? '')
    )) {
        session_regenerate_id(true);

        $_SESSION['clinic_logged_in'] = true;
        $_SESSION['clinic_id'] = (int) $account['clinic_id'];
        $_SESSION['clinic_email'] = $email;

        header('Location: clinic-dashboard.php');
        exit;
    }

    $error = 'Invalid email or password, or your account is inactive.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hospital Login | MediBook</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background: #f4f7fc;
        }

        .clinic-login-main {
            min-height: calc(100vh - 72px);
            padding: 40px 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at 12% 8%, rgba(37, 99, 235, .10), transparent 38%),
                radial-gradient(circle at 88% 92%, rgba(53, 184, 241, .12), transparent 38%),
                #f4f7fc;
        }

        .clinic-login-card {
            width: 100%;
            max-width: 440px;
            padding: 30px 32px 26px;
            background: #fff;
            border: 1px solid #e6ebf3;
            border-radius: 20px;
            box-shadow: 0 18px 45px rgba(25, 50, 90, .10);
        }

        .clinic-login-head {
            text-align: center;
            margin-bottom: 26px;
        }

        .clinic-login-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 14px;
            display: grid;
            place-items: center;
            font-size: 26px;
            color: #2563eb;
            background: #eaf2ff;
            border-radius: 16px;
        }

        .clinic-login-eyebrow {
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .08em;
            color: #2563eb;
        }

        .clinic-login-head h1 {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
        }

        .clinic-login-head p {
            margin: 0;
            font-size: 15px;
            line-height: 1.55;
            color: #64748b;
        }

        .clinic-login-card .form-label {
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        .clinic-login-card .input-group-text,
        .clinic-login-card .form-control {
            min-height: 46px;
            font-size: 15px;
            border-color: #dce4ef;
        }

        .clinic-login-card .input-group-text {
            border-radius: 10px 0 0 10px;
        }

        .clinic-login-card .input-group > .form-control:last-child,
        .clinic-login-card .input-group > .btn:last-child {
            border-radius: 0 10px 10px 0;
        }

        .clinic-login-card .input-group > .btn {
            min-width: 48px;
        }

        .clinic-login-card .form-control {
            min-width: 0;
        }

        .clinic-login-card .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .15);
        }

        .clinic-login-card .input-group:focus-within .input-group-text {
            border-color: #2563eb;
        }

        .clinic-login-card .btn-login {
            min-height: 48px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            background: #2563eb;
            border-color: #2563eb;
            box-shadow: 0 8px 18px rgba(37, 99, 235, .22);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .clinic-login-card .btn-login:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            transform: translateY(-1px);
        }

        .clinic-login-card .btn-login:disabled {
            opacity: .85;
            transform: none;
        }

        @media (max-width: 575px) {
            .clinic-login-main {
                padding: 24px 12px;
            }

            .clinic-login-card {
                padding: 24px 20px 22px;
                border-radius: 16px;
            }

            .clinic-login-head h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <span class="brand-mark">
                <i class="bi bi-heart-pulse-fill"></i>
            </span>
            <span>
                Medi<span>Book</span>
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#clinicLoginNav" aria-controls="clinicLoginNav"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="clinicLoginNav">
            <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <a class="nav-link" href="index.php">
                    <i class="bi bi-house me-1"></i>Home
                </a>
                <a class="nav-link" href="login.php">
                    <i class="bi bi-person me-1"></i>User Login
                </a>
                <a class="btn btn-outline-primary px-3 ms-lg-2" href="clinic-register.php">
                    <i class="bi bi-hospital me-1"></i>Register Hospital
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="clinic-login-main">
    <div class="clinic-login-card">

        <div class="clinic-login-head">
            <div class="clinic-login-icon">
                <i class="bi bi-hospital"></i>
            </div>

            <div class="clinic-login-eyebrow">HOSPITAL PORTAL</div>

            <h1>Welcome back</h1>

            <p>Sign in to manage your clinic profile and branch locations.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="email" class="form-label">Registered Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-envelope text-primary"></i>
                    </span>
                    <input type="email" id="email" name="email" class="form-control"
                           autocomplete="username" placeholder="name@clinic.com" required>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-lock text-primary"></i>
                    </span>
                    <input type="password" id="password" name="password" class="form-control"
                           autocomplete="current-password" placeholder="Enter your password" required>
                    <button type="button" class="btn btn-outline-secondary" id="togglePassword"
                            aria-label="Show password">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button class="btn btn-primary btn-login w-100" type="submit">
                <i class="bi bi-box-arrow-in-right me-2"></i>Login
            </button>
        </form>

        <div class="text-center mt-4">
            <span class="text-secondary">New hospital?</span>
            <a href="clinic-register.php"
               class="text-primary fw-semibold text-decoration-none">
                Register Hospital
            </a>
        </div>

    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const input = document.getElementById('password');
    const icon = this.querySelector('i');

    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('bi-eye');
    icon.classList.toggle('bi-eye-slash');
});
</script>
<script>
(function () {
    var form = document.querySelector('.clinic-login-card form');
    if (!form) return;

    var button = form.querySelector('button[type="submit"]');
    var original = button.innerHTML;

    form.addEventListener('submit', function () {
        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Signing in...';
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            button.disabled = false;
            button.innerHTML = original;
        }
    });
})();
</script>
</body>
</html>
