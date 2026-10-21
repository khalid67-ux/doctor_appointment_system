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
    background: #f4f7fc;
    padding: 30px 15px;
    min-height: auto;
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-login-layout {
    width: 100%;
    max-width: 850px;
    display: grid;
    grid-template-columns: 0.9fr 1.1fr;
    background: #fff;
    border: 1px solid #e6ebf3;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 16px 45px rgba(25, 50, 90, .09);
}

.user-login-banner {
    padding: 24px 28px;
    color: #fff;
    background: linear-gradient(145deg, #2152df, #35b8f1);
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.user-login-icon {
    width: 54px;
    height: 54px;
    border-radius: 18px;
    background: rgba(255,255,255,.18);
    display: grid;
    place-items: center;
    font-size: 30px;
    margin-bottom: 18px;
}

.user-login-banner h2 {
    font-weight: 800;
    font-size: 30px;
    margin-bottom: 10px;
}

.user-login-banner p {
    color: rgba(255,255,255,.9);
    line-height: 1.5;
    margin-bottom: 14px;
}

.user-login-form {
    padding: 24px 32px;
}

.user-login-banner .d-flex {
    margin-bottom: 10px !important;
}

.user-login-form .form-label {
    font-weight: 600;
}

.user-login-form .input-group-text,
.user-login-form .form-control {
    min-height: 50px;
    border-color: #dce4ef;
}

.user-login-form .input-group-text {
    border-radius: 9px 0 0 9px;
}

.user-login-form .form-control {
    min-width: 0;
}

.user-login-form .input-group > .btn {
    min-width: 55px;
}

.user-login-form .btn-login {
    min-height: 50px;
    border-radius: 10px;
    font-weight: 700;
    background: #2563eb;
    border-color: #2563eb;
}

@media (max-width: 767px) {
    .user-login-page {
        padding: 25px 12px;
    }

    .user-login-layout {
        grid-template-columns: 1fr;
    }

    .user-login-banner {
        padding: 28px 25px;
    }

    .user-login-icon {
        width: 50px;
        height: 50px;
        font-size: 24px;
        margin-bottom: 18px;
    }

    .user-login-banner h2 {
        font-size: 28px;
    }

    .user-login-form {
        padding: 30px 24px;
    }
}
</style>

<div class="user-login-page">
    <div class="user-login-layout">

        <section class="user-login-banner">
            <div class="user-login-icon">
                <i class="bi bi-heart-pulse-fill"></i>
            </div>

            <div class="small fw-bold mb-3">
                MEDIBOOK HEALTHCARE PORTAL
            </div>

            <h2>Welcome back!</h2>

            <p class="mt-3 mb-4">
                Sign in to access your MediBook account and manage
                your healthcare journey.
            </p>

            <div class="d-flex align-items-center gap-3 mb-3">
                <i class="bi bi-shield-check fs-5"></i>
                <span>Secure account access</span>
            </div>

            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-calendar2-check fs-5"></i>
                <span>Convenient appointment management</span>
            </div>
        </section>

        <section class="user-login-form">
            <div class="mb-4">
                <div class="mini-label mb-2">SECURE ACCESS</div>

                <h1 class="h2 fw-bold mb-2">Sign In</h1>

                <p class="text-secondary mb-0">
                    Enter your credentials to continue.
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

                <div class="mb-4">
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

                <div class="mb-3">
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

                <div class="d-flex align-items-center gap-2 mb-4 text-secondary small">
                    <i class="bi bi-shield-check text-success"></i>
                    Your account information is protected.
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
        </section>

    </div>
</div>

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