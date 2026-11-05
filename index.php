
<?php
$page_title = 'Home';
$asset_prefix = '';

require 'includes/header.php';

/*
|--------------------------------------------------------------------------
| Homepage Statistics
|--------------------------------------------------------------------------
| Counts are loaded from MongoDB.
*/

$homeStats = [
    'doctors' => 0,
    'patients' => 0,
    'appointments' => 0,
    'clinics' => 0,
];

try {
    if (isset($mongoDb)) {
        $homeStats['doctors'] = $mongoDb
            ->selectCollection('doctors')
            ->countDocuments([]);

        $homeStats['patients'] = $mongoDb
            ->selectCollection('patients')
            ->countDocuments([]);

        $homeStats['appointments'] = $mongoDb
            ->selectCollection('appointments')
            ->countDocuments([]);

        $homeStats['clinics'] = $mongoDb
            ->selectCollection('clinics')
            ->countDocuments([]);
    }
} catch (Throwable $e) {
    error_log('Homepage statistics error: ' . $e->getMessage());
}

?>

<style>
/* Homepage styles */
.mb-home {
    color: #17233b;
}

.mb-home .home-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    min-height: 540px;
    display: flex;
    align-items: center;
    border: 1px solid #dce8fb;
    border-radius: 26px;
    padding: clamp(28px, 5vw, 60px);
    background:
        linear-gradient(
            90deg,
            rgba(239, 246, 255, 0.62) 0%,
            rgba(239, 246, 255, 0.42) 35%,
            rgba(239, 246, 255, 0.12) 65%,
            rgba(239, 246, 255, 0.02) 100%
        ),
        url("assets/images/medibook-hero.png")
        center / cover no-repeat;
    box-shadow: 0 18px 50px rgba(37, 99, 235, .08);
}

.mb-home .home-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 50px;
    color: #245eea;
    background: #e8f1ff;
    font-weight: 700;
    font-size: .88rem;
}

.mb-home .home-title {
    max-width: 760px;
    margin: 20px 0 16px;
    font-size: clamp(2.3rem, 5.2vw, 4.3rem);
    font-weight: 800;
    line-height: 1.08;
    letter-spacing: -.04em;
}

.mb-home .home-copy {
    max-width: 650px;
    color: #526580;
    font-size: 1.08rem;
    line-height: 1.8;
}

.mb-home .home-btn {
    min-height: 52px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 700;
}

.mb-home .trust-line {
    display: flex;
    flex-wrap: wrap;
    gap: 14px 22px;
    margin-top: 25px;
    color: #64748b;
    font-size: .92rem;
}

.mb-home .trust-line i {
    margin-right: 5px;
    color: #2563eb;
}

.mb-home .stat-card {
    height: 100%;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 21px;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: white;
}

.mb-home .stat-icon {
    flex: 0 0 52px;
    width: 52px;
    height: 52px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    color: #2563eb;
    background: #edf4ff;
    font-size: 1.35rem;
}

.mb-home .stat-number {
    font-size: 1.75rem;
    line-height: 1.1;
    font-weight: 800;
}

.mb-home .stat-label {
    margin-top: 5px;
    color: #64748b;
    font-size: .88rem;
}

.mb-home .home-section {
    margin-top: 52px;
}

.mb-home .section-heading {
    font-size: clamp(1.7rem, 3vw, 2.3rem);
    font-weight: 800;
    letter-spacing: -.025em;
}

.mb-home .service-card {
    height: 100%;
    padding: 24px;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: white;
    transition: transform .2s ease, box-shadow .2s ease;
}

.mb-home .service-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 34px rgba(15,23,42,.07);
}

.mb-home #specialties a:focus-visible {
    outline: 3px solid #93c5fd;
    outline-offset: 4px;
    border-radius: 20px;
}

.mb-home #specialties a .service-card {
    height: 100%;
    border: 1px solid #e2e8f0;
    transition:
        transform .25s ease,
        box-shadow .25s ease,
        border-color .25s ease;
}

.mb-home #specialties a:hover .service-card,
.mb-home #specialties a:focus-visible .service-card {
    transform: translateY(-5px);
    border-color: #93c5fd;
    box-shadow:
        0 0 0 3px rgba(59, 130, 246, .08),
        0 12px 30px rgba(37, 99, 235, .16);
}

.mb-home #specialties a:active .service-card {
    transform: translateY(-1px);
}

@media (prefers-reduced-motion: reduce) {
    .mb-home #specialties a .service-card {
        transition: none;
    }
}

.mb-home .service-icon {
    width: 50px;
    height: 50px;
    display: grid;
    place-items: center;
    margin-bottom: 18px;
    border-radius: 15px;
    color: #2563eb;
    background: #edf4ff;
    font-size: 1.35rem;
}

.mb-home .contact-banner {
    padding: clamp(24px, 4vw, 40px);
    border-radius: 22px;
    color: white;
    background: linear-gradient(120deg, #1749bd, #2563eb 58%, #1689c9);
}

.mb-home .contact-banner p {
    color: rgba(255,255,255,.85);
}

.mb-home .contact-banner .btn-light {
    padding: 12px 18px;
    border-radius: 11px;
    color: #1749bd;
    font-weight: 700;
}

@media (max-width: 767.98px) {
    .mb-home .home-hero {
        min-height: 500px;
        padding: 28px 20px;
        border-radius: 20px;

        background:
            linear-gradient(
                90deg,
                rgba(255, 255, 255, .96),
                rgba(245, 249, 255, .82)
            ),
            url("assets/images/medibook-hero.png")
            65% center / cover no-repeat;
    }

    .mb-home .home-title {
        font-size: clamp(2.15rem, 9vw, 3.2rem);
    }

    .mb-home .home-copy {
        font-size: 1rem;
    }

    .mb-home .home-btn {
        width: 100%;
    }

    .mb-home .trust-line {
        gap: 12px 16px;
    }
}


.mb-home .home-hero a[href="register.php"] {
    background: #2563eb !important;
    color: #ffffff !important;
    border: 1px solid #2563eb !important;
    border-radius: 14px;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.20);
}

.mb-home .home-hero a[href="register.php"] i {
    color: #ffffff !important;
}

.mb-home .home-hero a[href="register.php"]:hover {
    background: #1d4ed8 !important;
    border-color: #1d4ed8 !important;
    color: #ffffff !important;
}



/* MediBook Statistics Cards */
.mb-home .stat-card {
    border: 1px solid #dbeafe;
    border-radius: 22px;
    box-shadow: 0 8px 24px rgba(37, 99, 235, 0.08);
    transition: transform 0.25s ease,
                box-shadow 0.25s ease;
}

.mb-home .stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 30px rgba(37, 99, 235, 0.14);
}

/* 1. Doctor Profiles — Light Blue */
.mb-home .stat-card:nth-child(1) {
    background: linear-gradient(135deg, #e7f1ff, #f5f9ff);
    border-color: #c5dcff;
}

/* 2. Registered Patients — Light Green */
.mb-home .stat-card:nth-child(2) {
    background: linear-gradient(135deg, #e2f8ee, #f4fcf8);
    border-color: #b9ebd5;
}

/* 3. Appointments — Light Orange */
.mb-home .stat-card:nth-child(3) {
    background: linear-gradient(135deg, #fff0e3, #fff9f3);
    border-color: #ffdcc0;
}

/* 4. Total Clinics & Hospitals — Light Purple */
.mb-home .stat-card:nth-child(4) {
    background: linear-gradient(135deg, #f0eaff, #faf8ff);
    border-color: #d9ccff;
}






/* Explore Care - All Four Cards */
.mb-home #specialties a .service-card {
    position: relative;
    overflow: hidden;
    padding: 24px 24px 76px;
    border: 1px solid #cfe1ff !important;
    border-radius: 22px;
    background: linear-gradient(135deg, #eaf3ff, #f7faff) !important;
    box-shadow: 0 8px 24px rgba(37, 99, 235, .06);
    transition: transform .25s ease, box-shadow .25s ease;
}

/* Same icon color */
.mb-home #specialties a .service-icon {
    color: #2563eb !important;
    background: #d8e8ff !important;
}

/* Same arrow color */
.mb-home #specialties a .service-card::after {
    content: "\2192";
    position: absolute;
    right: 22px;
    bottom: 20px;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2563eb !important;
    border: 2px solid #2563eb;
    border-radius: 50%;
    background: #ffffff;
    font-size: 25px;
    font-weight: 600;
    transition: transform .2s ease;
}

/* Hover */
.mb-home #specialties a:hover .service-card {
    background: linear-gradient(135deg, #eaf3ff, #f7faff) !important;
    border-color: #93c5fd !important;
    transform: translateY(-5px);
    box-shadow: 0 14px 30px rgba(37, 99, 235, .12);
}

.mb-home #specialties a:hover .service-card::after {
    transform: translateX(3px);
}

/* =========================================
   MediBook Scroll Reveal Animation
========================================= */

.mb-home .scroll-reveal {
    opacity: 0;
    transform: translateY(55px);
    transition:
        opacity 0.85s ease,
        transform 0.85s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: opacity, transform;
}

.mb-home .scroll-reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

/* Stagger effect for cards */
.mb-home .scroll-reveal.delay-1 {
    transition-delay: 0.12s;
}

.mb-home .scroll-reveal.delay-2 {
    transition-delay: 0.24s;
}

.mb-home .scroll-reveal.delay-3 {
    transition-delay: 0.36s;
}

@media (prefers-reduced-motion: reduce) {
    .mb-home .scroll-reveal {
        opacity: 1;
        transform: none;
        transition: none;
    }
}

</style>

<main class="mb-home">

    <!-- HERO SECTION -->
    <section class="home-hero mb-4 mb-lg-5">
    <div class="row align-items-center">
        <div class="col-12">

            <span class="home-kicker">
                <i class="bi bi-heart-pulse-fill"></i>
                Your Healthcare, Made Simpler
            </span>

            <h1 class="home-title">
                Better care starts with the
                <span class="text-primary">right doctor.</span>
            </h1>

            <p class="home-copy">
                Find doctors, explore specialties, check appointment
                availability and manage your healthcare visits—all
                in one convenient place with MediBook.
            </p>

            <div class="d-flex flex-wrap gap-3 mt-4">
                <a href="clinics.php"
                   class="btn btn-primary home-btn">
                    <i class="bi bi-hospital"></i>
                    View Clinics &amp; Hospitals
                </a>

                <a href="register.php"
                   class="btn btn-outline-primary home-btn">
                    <i class="bi bi-calendar2-check"></i>
                    Book an Appointment
                </a>
            </div>

            <div class="trust-line">
                <span>
                    <i class="bi bi-patch-check-fill"></i>
                    Doctor Profiles
                </span>

                <span>
                    <i class="bi bi-calendar-check-fill"></i>
                    Appointment Management
                </span>

                <span>
                    <i class="bi bi-shield-lock-fill"></i>
                    Secure Access
                </span>
            </div>

        </div>
    </section>


    <!-- PLATFORM STATISTICS -->
    <section class="mb-4 mb-lg-5"
             aria-label="MediBook platform statistics">

        <div class="row g-3">

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-person-badge"></i>
                    </div>

                    <div>
                        <div class="stat-number"
                             data-target="<?= (int) $homeStats['doctors'] ?>">0</div>
                        <div class="stat-label">Doctor Profiles</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>

                    <div>
                        <div class="stat-number"
                             data-target="<?= (int) $homeStats['patients'] ?>">0</div>
                        <div class="stat-label">Registered Patients</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-calendar2-check"></i>
                    </div>

                    <div>
                        <div class="stat-number"
                             data-target="<?= (int) $homeStats['appointments'] ?>">0</div>
                        <div class="stat-label">Appointments Recorded</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-hospital"></i>
                    </div>

                    <div>
                        <div class="stat-number"
                             data-target="<?= (int) $homeStats['clinics'] ?>">0</div>
                        <div class="stat-label">Total Clinics & Hospitals</div>
                    </div>
                </div>
            </div>

        </div>
    </section>


    <!-- ABOUT US -->
    <section class="home-section" id="about">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <span class="text-primary fw-bold small text-uppercase">
                    About MediBook
                </span>

                <h2 class="section-heading mt-2">
                    Healthcare Access Made Simpler
                </h2>

                <p class="text-secondary mb-0">
                    MediBook is a digital appointment management platform
                    that helps patients explore doctor profiles, find
                    available appointments and manage their healthcare visits.
                    Doctors can manage their schedules and appointment status,
                    while administrators oversee platform operations.
                </p>
            </div>

            <div class="col-lg-4">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="bi bi-shield-check"></i>
                    </div>

                    <h5 class="fw-bold">Convenient Care Management</h5>

                    <p class="text-secondary mb-0">
                        Explore doctors, book appointments and track visits
                        from one convenient platform.
                    </p>
                </div>
            </div>
        </div>
    </section>


    <!-- SPECIALTIES -->
    <section class="home-section" id="specialties">

        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">

            <div>
                <span class="text-primary fw-bold small text-uppercase">
                    Explore Care
                </span>

                <h2 class="section-heading mt-2 mb-0">
                    Find Care for Your Needs
                </h2>

                <p class="text-secondary mt-2 mb-0">
                    Start with a doctor or explore available care options.
                </p>
            </div>

          

        </div>

        <div class="row g-3">

            <div class="col-sm-6 col-lg-3">
                <a href="patient/doctors.php?department=medicine&amp;view=department"
                   class="text-decoration-none text-reset">
                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-heart-pulse"></i>
                        </div>

                        <h5 class="fw-bold">Medicine & General Care</h5>

                        <p class="text-secondary mb-0">
                            Find doctors for general health concerns
                            and consultations.
                        </p>
                    </div>
                </a>
            </div>

            <div class="col-sm-6 col-lg-3">
                <a href="patient/doctors.php?department=cardiology&amp;view=department"
                   class="text-decoration-none text-reset">
                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-heart"></i>
                        </div>

                        <h5 class="fw-bold">Cardiology</h5>

                        <p class="text-secondary mb-0">
                            Explore doctor profiles for heart health
                            consultations.
                        </p>
                    </div>
                </a>
            </div>

            <div class="col-sm-6 col-lg-3">
                <a href="patient/doctors.php?department=gynecology&amp;view=department"
                   class="text-decoration-none text-reset">
                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-gender-female"></i>
                        </div>

                        <h5 class="fw-bold">Gynecology</h5>

                        <p class="text-secondary mb-0">
                            Find doctors for women's health consultations.
                        </p>
                    </div>
                </a>
            </div>

            <div class="col-sm-6 col-lg-3">
                <a href="patient/doctors.php?department=other&amp;view=department"
                   class="text-decoration-none text-reset">
                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-plus-square"></i>
                        </div>

                        <h5 class="fw-bold">Other Specialties</h5>

                        <p class="text-secondary mb-0">
                            Browse the doctor directory to find a suitable specialty.
                        </p>
                    </div>
                </a>
            </div>

        </div>
    </section>


    <!-- HOW IT WORKS -->
    <section class="home-section">

        <div class="row g-4 align-items-center">

            <div class="col-lg-4">

                <span class="text-primary fw-bold small text-uppercase">
                    How MediBook Works
                </span>

                <h2 class="section-heading mt-2">
                    Your Next Appointment in Three Steps
                </h2>

                <p class="text-secondary mb-0">
                    A clear and simple way to plan and manage your
                    healthcare visits.
                </p>

            </div>

            <div class="col-lg-8">

                <div class="row g-3">

                    <div class="col-md-4">
                        <div class="service-card">
                            <div class="service-icon">
                                <i class="bi bi-search"></i>
                            </div>

                            <h5 class="fw-bold">1. Find</h5>

                            <p class="text-secondary mb-0">
                                Explore doctor profiles and specialties.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="service-card">
                            <div class="service-icon">
                                <i class="bi bi-calendar2-week"></i>
                            </div>

                            <h5 class="fw-bold">2. Book</h5>

                            <p class="text-secondary mb-0">
                                Choose an available date and time.
                            </p>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="service-card">
                            <div class="service-icon">
                                <i class="bi bi-clipboard2-check"></i>
                            </div>

                            <h5 class="fw-bold">3. Manage</h5>

                            <p class="text-secondary mb-0">
                                Track appointments and queue status.
                            </p>
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </section>


    <!-- CONTACT AND HOTLINE -->
    <section class="home-section mb-2" id="contact">
        <div class="contact-banner">
            <div class="row align-items-center g-4">

                <div class="col-lg-8">
                    <span class="small text-uppercase fw-bold">
                        MEDIBOOK SUPPORT
                    </span>

                    <h2 class="fw-bold mt-2 mb-2 text-white">
                        Need Help? We're Here for You.
                    </h2>

                    <p class="mb-3">
                        Contact our team for appointment and account assistance.
                    </p>

                    <div class="d-flex flex-wrap gap-3">
                        <a href="tel:+8801889106270" class="btn btn-light">
                            <i class="bi bi-telephone-fill me-2"></i>
                            Hotline: 01889106270
                        </a>

                        <button type="button"
                                class="btn btn-outline-light"
                                data-bs-toggle="modal"
                                data-bs-target="#contactSupportModal">
                            <i class="bi bi-envelope-fill me-2"></i>
                            Contact Support
                        </button>
                    </div>
                </div>

                <div class="col-lg-4 text-lg-end">
                    <div class="p-3 rounded-4"
                         style="background:rgba(255,255,255,.12)">
                        <i class="bi bi-headset fs-2"></i>
                        <h5 class="fw-bold mt-2 text-white">
                            Support Team
                        </h5>
                        <p class="mb-0">
                            Send us your question and our team can respond
                            by email.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- CONTACT SUPPORT FORM -->
    <div class="modal fade"
         id="contactSupportModal"
         tabindex="-1"
         aria-labelledby="contactSupportTitle"
         aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold"
                            id="contactSupportTitle">
                            Contact MediBook Support
                        </h5>
                        <p class="text-secondary small mb-0">
                            Complete the form to email our support team.
                        </p>
                    </div>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>

                <form action="send-support.php"
                      method="post">

                    <div class="modal-body">
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label" for="support_name">
                                Your Name
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="support_name"
                                   name="name"
                                   maxlength="100"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="support_email">
                                Your Email
                            </label>
                            <input type="email"
                                   class="form-control"
                                   id="support_email"
                                   name="email"
                                   maxlength="254"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="support_subject">
                                Subject
                            </label>
                            <input type="text"
                                   class="form-control"
                                   id="support_subject"
                                   name="subject"
                                   maxlength="150"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="support_message">
                                Message
                            </label>
                            <textarea class="form-control"
                                      id="support_message"
                                      name="message"
                                      rows="5"
                                      maxlength="3000"
                                      required></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button"
                                class="btn btn-light"
                                data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit"
                                class="btn btn-primary">
                            <i class="bi bi-send me-2"></i>
                            Send Message
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

</main>

<style>
#support-toast {
    position: fixed;
    top: 24px;
    right: 24px;
    z-index: 99999;
    background: #fff;
    color: #163b2b;
    padding: 16px 22px;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,.14);
    border-left: 5px solid #16a34a;
    display: none;
    max-width: calc(100vw - 32px);
    font-size: 15px;
}
#support-toast.show {
    display: block;
    animation: toastIn .3s ease;
}
@keyframes toastIn {
    from { opacity: 0; transform: translateY(-12px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div id="support-toast" role="status" aria-live="polite">
    <strong>✓ Message sent successfully!</strong>
    <div style="margin-top:4px;font-size:13px;color:#64748b">
        MediBook Support has received your message.
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);

    if (params.get('contact') === 'success') {
        const toast = document.getElementById('support-toast');
        toast.classList.add('show');

        setTimeout(function () {
            const helpSection =
                document.querySelector('#contact') ||
                document.querySelector('#help-support');

            if (helpSection) {
                helpSection.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }, 700);

        setTimeout(function () {
            toast.classList.remove('show');
        }, 3500);

        window.history.replaceState(
            {},
            document.title,
            window.location.pathname + window.location.hash
        );
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const counters = document.querySelectorAll(
        '.mb-home .stat-number[data-target]'
    );

    const duration = 1400;

    counters.forEach(function (counter) {
        const target = Number(counter.dataset.target);

        if (!Number.isSafeInteger(target) || target < 0) {
            return;
        }

        const startTime = performance.now();

        function animate(currentTime) {
            const progress = Math.min(
                (currentTime - startTime) / duration,
                1
            );

            const eased = 1 - Math.pow(1 - progress, 4);
            const current = Math.floor(eased * target);

            counter.textContent = current.toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(animate);
            } else {
                counter.textContent = target.toLocaleString();
            }
        }

        counter.textContent = '0';
        requestAnimationFrame(animate);
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const revealCandidates = document.querySelectorAll(`
        .mb-home .home-kicker,
        .mb-home .home-title,
        .mb-home .home-copy,
        .mb-home .home-hero .btn,
        .mb-home .trust-line,
        .mb-home .home-visual,
        .mb-home .stat-card,
        .mb-home .home-section h2,
        .mb-home .home-section > .row > div > p,
        .mb-home .home-section .service-card,
        .mb-home .home-section .contact-banner
    `);

    revealCandidates.forEach(function (element, index) {
        element.classList.add('scroll-reveal');

        if (element.classList.contains('service-card') ||
            element.classList.contains('stat-card')) {

            const delay = index % 4;

            if (delay > 0) {
                element.classList.add('delay-' + Math.min(delay, 3));
            }
        }
    });

    const targets = document.querySelectorAll('.mb-home .scroll-reveal');

    let lastScrollY = window.scrollY;
    let scrollingDown = false;

    // Detect scroll direction
    window.addEventListener('scroll', function () {
        const currentY = window.scrollY;

        scrollingDown = currentY > lastScrollY;
        lastScrollY = currentY;
    }, { passive: true });

    function revealPreviousSections() {
        targets.forEach(function (element) {
            const rect = element.getBoundingClientRect();

            // Current viewport and all sections above it are visible
            if (rect.top < window.innerHeight) {
                element.classList.add('is-visible');
            }
        });
    }

    window.addEventListener('load', function () {
        requestAnimationFrame(function () {
            requestAnimationFrame(revealPreviousSections);
        });
    });

    window.addEventListener('pageshow', revealPreviousSections);

    const observer = new IntersectionObserver(function (entries) {

        entries.forEach(function (entry) {

            if (!entry.isIntersecting) return;

            // Reveal only when scrolling downward
            if (scrollingDown) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }

        });

    }, {
        threshold: 0.12,
        rootMargin: '0px 0px -40px 0px'
    });

    targets.forEach(function (element) {
        if (!element.classList.contains('is-visible')) {
            observer.observe(element);
        }
    });

});
</script>

<?php require 'includes/footer.php'; ?>
