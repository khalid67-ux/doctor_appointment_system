<?php

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

verify_csrf();

$page_title = $page_title ?? 'MediBook';
$asset_prefix = $asset_prefix ?? '';

$role = $_SESSION['role'] ?? null;

$dashboard =
    $role === 'patient'
        ? 'patient/dashboard.php'
        : (
            $role === 'doctor'
                ? 'doctor/dashboard.php'
                : (
                    $role === 'admin'
                        ? 'admin/dashboard.php'
                        : 'index.php'
                )
        );

$notificationLink =
    $role === 'patient'
        ? 'patient/notifications.php'
        : (
            $role === 'doctor'
                ? 'doctor/appointments.php'
                : (
                    $role === 'admin'
                        ? 'admin/dashboard.php'
                        : 'login.php'
                )
        );

?>

<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="description"
        content="MediBook - Dynamic Doctor Appointment and Healthcare Management System"
    >

    <meta
        name="theme-color"
        content="#2563eb"
    >

    <title>
        <?= h($page_title) ?> · MediBook
    </title>

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- MediBook Global CSS -->
    <link
        rel="stylesheet"
        href="<?= h($asset_prefix) ?>assets/css/style.css"
    >

</head>


<body class="d-flex flex-column">


<!-- =====================================================
     NAVBAR
     ===================================================== -->

<?php if (!empty($clinic_dashboard_nav)): ?>
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top app-navbar">
    <div class="container">

        <!-- Same logo as Doctor Dashboard -->
        <a class="navbar-brand" href="clinic-dashboard.php">
            <span class="brand-mark">
                <i class="bi bi-heart-pulse-fill"></i>
            </span>
            <span>Medi<span>Book</span></span>
        </a>

        <!-- Clinic navigation -->
        <div class="d-flex align-items-center gap-3">
            <span class="nav-link">
                <i class="bi bi-hospital me-1"></i>
                <?= h($clinic['name'] ?? 'Clinic') ?>
            </span>

            <a class="btn btn-outline-danger btn-sm px-3 py-2"
               href="clinic-logout.php">
                <i class="bi bi-box-arrow-right me-1"></i>
                Logout
            </a>
        </div>

    </div>
</nav>
<?php else: ?>
<nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top app-navbar">

    <div class="container">

        <!-- Brand -->

        <a
            class="navbar-brand"
            href="<?= h($asset_prefix) ?>index.php"
        >

            <span class="brand-mark">
                <i class="bi bi-heart-pulse-fill"></i>
            </span>

            <span>
                Medi<span>Book</span>
            </span>

        </a>


        <!-- Mobile Toggle -->

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#mainNav"
            aria-controls="mainNav"
            aria-expanded="false"
            aria-label="Toggle navigation"
        >

            <span class="navbar-toggler-icon"></span>

        </button>


        <!-- Navigation -->

        <div
            class="collapse navbar-collapse"
            id="mainNav"
        >

            <ul class="navbar-nav ms-auto align-items-lg-center">

                <?php if ($role): ?>

                    <!-- Dashboard -->

                    <li class="nav-item">

                        <a
                            class="nav-link"
                            href="<?= h($asset_prefix . $dashboard) ?>"
                        >

                            <i class="bi bi-grid-1x2 me-1"></i>

                            Dashboard

                        </a>

                    </li>


                    <!-- Patient Navigation -->

                    <?php if ($role === 'patient'): ?>

                        


                        <li class="nav-item">

                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>patient/appointments.php"
                            >

                                <i class="bi bi-calendar2-check me-1"></i>

                                Appointments

                            </a>

                        </li>


                        <li class="nav-item">
                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>patient/my-complaints.php"
                            >
                                <i class="bi bi-chat-square-text me-1"></i>
                                My Complaints
                            </a>
                        </li>


                        <li class="nav-item">

                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>patient/notifications.php"
                            >

                                <i class="bi bi-bell me-1"></i>

                                Notifications

                            </a>

                        </li>


                    <!-- Doctor Navigation -->

                    <?php elseif ($role === 'doctor'): ?>

                        <li class="nav-item">

                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>doctor/schedule.php"
                            >

                                <i class="bi bi-clock me-1"></i>

                                Schedule

                            </a>

                        </li>


                        <li class="nav-item">

                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>doctor/appointments.php"
                            >

                                <i class="bi bi-calendar2-check me-1"></i>

                                Appointments

                            </a>

                        </li>


                    <!-- Admin Navigation -->

                    <?php elseif ($role === 'admin'): ?>

                        <li class="nav-item">

                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>admin/doctors.php"
                            >

                                <i class="bi bi-person-badge me-1"></i>

                                Doctors

                            </a>

                        </li>


                        <li class="nav-item">

                            <a
                                class="nav-link"
                                href="<?= h($asset_prefix) ?>admin/appointments.php"
                            >

                                <i class="bi bi-calendar2-check me-1"></i>

                                Appointments

                            </a>

                        </li>

                    <?php endif; ?>


                    <!-- Logout -->

                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">

                        <a
                            class="btn btn-outline-danger btn-sm px-3"
                            href="<?= h($asset_prefix) ?>logout.php"
                        >

                            <i class="bi bi-box-arrow-right me-1"></i>

                            Logout

                        </a>

                    </li>


                <?php else: ?>


                    <!-- Guest Navigation -->

                    <?php if (($navbar_type ?? '') === 'login'): ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>index.php">
                                <i class="bi bi-house me-1"></i> Home
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>clinic-login.php">
                                <i class="bi bi-hospital me-1"></i> Clinic Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="btn btn-primary px-4"
                               href="<?= h($asset_prefix) ?>register.php">
                                <i class="bi bi-person-plus me-1"></i> Create Account
                            </a>
                        </li>

                    <?php elseif (($navbar_type ?? '') === 'register'): ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>index.php">
                                <i class="bi bi-house me-1"></i> Home
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>login.php">
                                <i class="bi bi-person me-1"></i> User Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>clinic-login.php">
                                <i class="bi bi-hospital me-1"></i> Clinic Login
                            </a>
                        </li>

                    <?php elseif (($navbar_type ?? '') === 'clinic-register'): ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>index.php">
                                <i class="bi bi-house me-1"></i> Home
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>login.php">
                                <i class="bi bi-person me-1"></i> User Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>clinic-login.php">
                                <i class="bi bi-hospital me-1"></i> Clinic Login
                            </a>
                        </li>

                    <?php elseif (($navbar_type ?? '') === 'clinics'): ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>index.php">
                                <i class="bi bi-house me-1"></i> Home
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>login.php">
                                <i class="bi bi-person me-1"></i> User Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>clinic-login.php">
                                <i class="bi bi-hospital me-1"></i> Clinic Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="btn btn-primary px-4"
                               href="<?= h($asset_prefix) ?>clinic-register.php">
                                <i class="bi bi-hospital me-1"></i> Register Your Clinic
                            </a>
                        </li>

                    <?php elseif (($navbar_type ?? '') === 'doctors'): ?>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>index.php">
                                <i class="bi bi-house me-1"></i> Home
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>login.php">
                                <i class="bi bi-person me-1"></i> User Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= h($asset_prefix) ?>clinic-login.php">
                                <i class="bi bi-hospital me-1"></i> Clinic Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="btn btn-primary px-4"
                               href="<?= h($asset_prefix) ?>register.php">
                                <i class="bi bi-person-plus me-1"></i> Create Account
                            </a>
                        </li>

                    <?php else: ?>

                    <!-- Public Home Page Navigation -->

                    <li class="nav-item">
                        <a
                            class="nav-link"
                            href="<?= h($asset_prefix) ?>index.php#about"
                        >
                            <i class="bi bi-info-circle me-1"></i>
                            About Us
                        </a>
                    </li>

              

                    <li class="nav-item">
                        <a
                            class="nav-link"
                            href="<?= h($asset_prefix) ?>index.php#contact"
                        >
                            <i class="bi bi-telephone me-1"></i>
                            Contact Us
                        </a>
                    </li>

                   


                    <li class="nav-item">
        <a class="nav-link" href="<?= h($asset_prefix) ?>login.php">
            <i class="bi bi-person me-1"></i> User Login
        </a>
    </li>

                    </li>

                    <li class="nav-item">
                        <a
                            class="nav-link"
                            href="<?= h($asset_prefix) ?>clinic-login.php"
                        >
                            <i class="bi bi-hospital me-1"></i>
                            Clinic Login
                        </a>
                    </li>

                    <li class="nav-item ms-lg-2 mt-2 mt-lg-0">

                        <a
                            class="btn btn-primary btn-sm px-3"
                            href="<?= h($asset_prefix) ?>register.php"
                        >

                            <i class="bi bi-person-plus me-1"></i>

                            Create Account

                        </a>

                    </li>

                    <?php endif; ?>

                <?php endif; ?>

            </ul>

        </div>

    </div>

</nav>
<?php endif; ?>


<!-- =====================================================
     MAIN CONTENT
     ===================================================== -->

<main class="container py-4 py-lg-5">

    <?php show_flash(); ?>