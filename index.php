
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
    overflow: hidden;
    border: 1px solid #dce8fb;
    border-radius: 26px;
    padding: clamp(28px, 5vw, 60px);
    background: linear-gradient(
        120deg,
        #eff6ff 0%,
        #ffffff 58%,
        #e9fbf7 100%
    );
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
    color: #64748b;
    font-size: 1.08rem;
    line-height: 1.8;
}

.mb-home .home-btn {
    min-height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 12px 20px;
    border-radius: 12px;
    font-weight: 700;
}

.mb-home .home-visual {
    min-height: 320px;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 25px;
    border-radius: 24px;
    background:
        radial-gradient(circle at 25% 20%, #d8e9ff, transparent 40%),
        linear-gradient(145deg, #eaf3ff, #e8fbf6);
}

.mb-home .medical-card {
    width: min(100%, 330px);
    min-height: 280px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px;
    border: 1px solid #ffffff;
    border-radius: 28px;
    background: rgba(255,255,255,.9);
    box-shadow: 0 24px 55px rgba(32,78,145,.12);
    text-align: center;
}

.mb-home .medical-icon {
    width: 100px;
    height: 100px;
    display: grid;
    place-items: center;
    margin-bottom: 20px;
    border-radius: 28px;
    color: white;
    font-size: 3rem;
    background: linear-gradient(135deg, #2463eb, #35b8e8);
    box-shadow: 0 14px 28px rgba(37,99,235,.24);
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
        border-radius: 20px;
    }

    .mb-home .home-visual {
        min-height: 260px;
    }

    .mb-home .medical-card {
        max-width: 260px;
    }

    .mb-home .trust-line {
        gap: 12px 16px;
    }
}
</style>

<main class="mb-home">

    <!-- HERO SECTION -->
    <section class="home-hero mb-4 mb-lg-5">
        <div class="row align-items-center g-4 g-xl-5">

            <div class="col-lg-7">

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

                    <a href="clinics.php" class="btn btn-primary">
                        <i class="bi bi-hospital me-2"></i>
                        View Clinics
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

            <div class="col-lg-5">

                <div class="home-visual">
                    <div class="medical-card">

                        <div class="medical-icon">
                            <i class="bi bi-heart-pulse-fill"></i>
                        </div>

                        <h3 class="fw-bold mb-2">
                            Care That Fits Your Life
                        </h3>

                        <p class="text-secondary mb-0">
                            Discover care. Choose a time.
                            Manage your visit.
                        </p>

                        <div class="d-flex gap-2 mt-4 flex-wrap justify-content-center">
                            <span class="badge rounded-pill text-bg-primary px-3 py-2">
                                Find Care
                            </span>

                            <span class="badge rounded-pill text-bg-light border text-primary px-3 py-2">
                                Book Online
                            </span>
                        </div>

                    </div>
                </div>

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
                        <div class="stat-number">
                            <?= number_format($homeStats['doctors']) ?>
                        </div>
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
                        <div class="stat-number">
                            <?= number_format($homeStats['patients']) ?>
                        </div>
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
                        <div class="stat-number">
                            <?= number_format($homeStats['appointments']) ?>
                        </div>
                        <div class="stat-label">Appointments Recorded</div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="bi bi-headset"></i>
                    </div>

                    <div>
                        <div class="fw-bold fs-5">Support</div>
                        <div class="stat-label">Contact Our Team</div>
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

            <a href="patient/doctors.php"
               class="btn btn-outline-primary home-btn">
                Browse Doctors
                <i class="bi bi-arrow-right"></i>
            </a>

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

<?php require 'includes/footer.php'; ?>
