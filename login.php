<?php

require_once __DIR__ . '/config/mongodb.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    redirect_by_role((string)$_SESSION['role']);
}

$page_title = 'Login';
$navbar_type = 'login';
$asset_prefix = '';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $error = 'Please enter your email and password.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = 'Please enter a valid email address.';

    } else {

        $document = $mongoDb->selectCollection('users')->findOne(
            ['email' => $email],
            [
                'projection' => [
                    '_id' => 0,
                    'user_id' => 1,
                    'name' => 1,
                    'email' => 1,
                    'password_hash' => 1,
                    'role' => 1,
                    'status' => 1,
                ],
            ]
        );

        $user = $document !== null
            ? $document->getArrayCopy()
            : null;

        if (!$user) {

            $error = 'Invalid email or password.';

        } elseif (
            isset($user['status']) &&
            !in_array(
                strtolower((string)$user['status']),
                ['active', 'approved', 'verified', '1'],
                true
            )
        ) {

            $error = 'Your account is not active. Please contact the administrator.';

        } elseif ($password !== $user['password_hash']) {

            $error = 'Invalid email or password.';

        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$user['user_id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = strtolower($user['role']);

            audit(
                (int)$user['user_id'],
                'LOGIN',
                'user',
                (int)$user['user_id']
            );

            redirect_by_role((string)$_SESSION['role']);
            exit;
        }
    }
}

require 'includes/header.php';

?>
<style>
.user-login-page {
    min-height: 72vh;
    padding: 40px 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        radial-gradient(circle at 12% 8%, rgba(37, 99, 235, .10), transparent 38%),
        radial-gradient(circle at 88% 92%, rgba(53, 184, 241, .12), transparent 38%),
        #f4f7fc;
}

.user-login-card {
    width: 100%;
    max-width: 440px;
    padding: 30px 32px 26px;
    background: #fff;
    border: 1px solid #e6ebf3;
    border-radius: 20px;
    box-shadow: 0 18px 45px rgba(25, 50, 90, .10);
}

.user-login-head {
    text-align: center;
    margin-bottom: 26px;
}

.user-login-icon {
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

.user-login-eyebrow {
    margin-bottom: 8px;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
    color: #2563eb;
}

.user-login-head h1 {
    margin: 0 0 8px;
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
}

.user-login-head p {
    margin: 0;
    font-size: 15px;
    line-height: 1.55;
    color: #64748b;
}

.user-login-card .form-label {
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}

.user-login-card .input-group-text,
.user-login-card .form-control {
    min-height: 46px;
    font-size: 15px;
    border-color: #dce4ef;
}

.user-login-card .input-group-text {
    border-radius: 10px 0 0 10px;
}

.user-login-card .input-group > .form-control:last-child,
.user-login-card .input-group > .btn:last-child {
    border-radius: 0 10px 10px 0;
}

.user-login-card .input-group > .btn {
    min-width: 48px;
}

.user-login-card .form-control {
    min-width: 0;
}

.user-login-card .form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 .2rem rgba(37, 99, 235, .15);
}

.user-login-card .input-group:focus-within .input-group-text {
    border-color: #2563eb;
}

.user-login-card .btn-login {
    min-height: 48px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    background: #2563eb;
    border-color: #2563eb;
    box-shadow: 0 8px 18px rgba(37, 99, 235, .22);
    transition: transform .15s ease, box-shadow .15s ease;
}

.user-login-card .btn-login:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    transform: translateY(-1px);
}

.user-login-card .btn-login:disabled {
    opacity: .85;
    transform: none;
}

@media (max-width: 575px) {
    .user-login-page {
        padding: 24px 12px;
    }

    .user-login-card {
        padding: 24px 20px 22px;
        border-radius: 16px;
    }

    .user-login-head h1 {
        font-size: 24px;
    }
}
</style>

<div class="user-login-page">
    <div class="user-login-card">

        <div class="user-login-head">
            <div class="user-login-icon">
                <i class="bi bi-heart-pulse-fill"></i>
            </div>

            <div class="user-login-eyebrow">MEDIBOOK HEALTHCARE PORTAL</div>

            <h1>Welcome back!</h1>

            <p>
                Sign in to access your MediBook account and manage
                your healthcare journey.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex gap-2" role="alert">
                <i class="bi bi-exclamation-circle-fill mt-1"></i>
                <div><?= h($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="email" class="form-label">
                    Email Address
                </label>

                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-envelope text-primary"></i>
                    </span>

                    <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        value="<?= h($_POST['email'] ?? '') ?>"
                        placeholder="you@example.com"
                        autocomplete="email"
                        required
                    >
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">
                    Password
                </label>

                <div class="input-group">
                    <span class="input-group-text bg-white">
                        <i class="bi bi-lock text-primary"></i>
                    </span>

                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        id="togglePassword"
                        aria-label="Show password"
                    >
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-login w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>
                Sign In
            </button>
        </form>

        <div class="text-center mt-4">
            <span class="text-secondary">Don't have an account?</span>
            <a href="register.php"
               class="text-primary fw-semibold text-decoration-none">
                Create Account
            </a>
        </div>

    </div>
</div>

<script>
(function () {
    var form = document.querySelector('.user-login-card form');
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.getElementById('password');
    const toggleButton = document.getElementById('togglePassword');

    if (!passwordInput || !toggleButton) return;

    toggleButton.addEventListener('click', function () {
        const icon = this.querySelector('i');
        const show = passwordInput.type === 'password';

        passwordInput.type = show ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !show);
        icon.classList.toggle('bi-eye-slash', show);

        this.setAttribute(
            'aria-label',
            show ? 'Hide password' : 'Show password'
        );
    });
});
</script>
<?php require 'includes/footer.php'; ?>