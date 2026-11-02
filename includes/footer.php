</main>


<!-- =====================================================
     FOOTER
     ===================================================== -->

<footer class="app-footer mt-auto">

    <div class="container py-4">

        <div
            class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3"
        >

            <div class="text-center text-md-start">

                <strong>
                    <i class="bi bi-heart-pulse-fill text-primary me-1"></i>
                    MediBook
                </strong>

                <span>
                    · Dynamic Doctor Appointment & Healthcare Management System
                </span>

            </div>


            <div class="small">

                © 2026 MediBook. All rights reserved.

            </div>

        </div>

    </div>

</footer>


<!-- Bootstrap JavaScript -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


<!-- MediBook JavaScript -->

<script
    src="<?= h($asset_prefix ?? '') ?>assets/js/main.js"
></script>

<?php
$currentScript = str_replace(
    '\\',
    '/',
    $_SERVER['SCRIPT_NAME'] ?? ''
);

$currentPage = basename($currentScript);

// Patient Pages
$isPatientPage = strpos($currentScript, '/patient/') !== false;

// Root Home Page
$isRootHomePage = (
    $currentPage === 'index.php'
    && !$isPatientPage
    && strpos($currentScript, '/doctor/') === false
    && strpos($currentScript, '/admin/') === false
);

// Clinic Pages
$clinicPages = [
    'clinic-dashboard.php',
    'clinic-login.php',
    'clinic-logout.php',
    'clinic-register.php',
    'clinics.php'
];

$isClinicPage = in_array($currentPage, $clinicPages, true);

// Authentication Pages
$authPages = [
    
    'register.php'
];

$isAuthPage = in_array($currentPage, $authPages, true);

// Show Chatbot
if (
    $isPatientPage ||
    $isRootHomePage ||
    $isClinicPage ||
    $isAuthPage
) {
    require __DIR__ . '/chatbot.php';
}
?>

</body>

</html>