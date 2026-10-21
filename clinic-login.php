
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
        'status' => 'Active'
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
    <title>Clinic Login | MediBook</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
          rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f4f7fb; }
        .clinic-login-card {
            max-width: 960px;
            overflow: hidden;
            border: 0;
            border-radius: 20px;
            box-shadow: 0 16px 48px rgba(20, 40, 80, .12);
        }
        .clinic-login-intro {
            min-height: 100%;
            padding: 48px;
            color: #fff;
            background: linear-gradient(145deg, #1d4ed8, #38bdf8);
        }
        .clinic-login-form { padding: 48px; }
        .clinic-login-form .form-control,
        .clinic-login-form .input-group-text,
        .clinic-login-form .btn { min-height: 48px; }
        .clinic-login-main { min-height: calc(100vh - 72px); }
        @media (max-width: 767.98px) {
            .clinic-login-intro,
            .clinic-login-form { padding: 30px 24px; }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="index.php">
            <span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span>
            <span>Medi<span class="text-primary">Book</span></span>
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
                    <i class="bi bi-hospital me-1"></i>Register Clinic
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="container clinic-login-main d-flex align-items-center justify-content-center py-5">
    <div class="card clinic-login-card w-100">
        <div class="row g-0">
            <section class="col-md-5 clinic-login-intro d-flex flex-column justify-content-center">
                <div class="mb-4">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-white bg-opacity-25"
                          style="width:58px;height:58px">
                        <i class="bi bi-hospital fs-3"></i>
                    </span>
                </div>
                <h1 class="h2 fw-bold mb-3">Welcome back</h1>
                <p class="mb-0 text-white-50">
                    Sign in to manage your clinic profile and branch locations.
                </p>
            </section>

            <section class="col-md-7 clinic-login-form">
                <h2 class="h3 fw-bold mb-2">Clinic Portal Login</h2>
                <p class="text-secondary mb-4">Use your registered clinic account.</p>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="email" class="form-label fw-semibold">Registered Email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white">
                                <i class="bi bi-envelope text-primary"></i>
                            </span>
                            <input type="email" id="email" name="email" class="form-control"
                                   autocomplete="username" placeholder="name@clinic.com" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold">Password</label>
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

                    <button class="btn btn-primary btn-lg w-100" type="submit">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Login
                    </button>
                </form>

                <p class="text-center text-secondary small mt-4 mb-0">
                    Use the email and password submitted during clinic registration.
                    <a href="clinic-register.php" class="text-decoration-none fw-semibold">Register Clinic</a>
                </p>
            </section>
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
</body>
</html>
