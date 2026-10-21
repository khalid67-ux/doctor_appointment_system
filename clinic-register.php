
<?php

require_once __DIR__ . '/includes/functions.php';

$applicationCollection = $mongoDb->selectCollection(
    'clinic_applications'
);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(419);
        exit('Invalid form token. Please refresh the page and try again.');
    }

    $institutionType = trim($_POST['institution_type'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $allowedTypes = ['Clinic', 'Hospital', 'Diagnostic Center'];

    if (
        !in_array($institutionType, $allowedTypes, true) ||
        $name === '' ||
        $address === '' ||
        $contact === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $error = 'Please fill in all fields with valid information.';
    } elseif (strlen($name) > 150 || strlen($address) > 500) {
        $error = 'Institution name or address is too long.';
    } elseif (strlen($contact) > 30) {
        $error = 'Contact number is too long.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Password and Confirm Password do not match.';
    } else {
        try {
            $existing = $applicationCollection->findOne([
                'email' => $email
            ]);

            $existingClinic = $mongoDb
                ->selectCollection('clinics')
                ->findOne([
                    'email' => $email
                ]);

            if ($existing !== null || $existingClinic !== null) {
                $error = 'This email is already registered. Please use another email.';
            } else {
                $applicationCollection->insertOne([
                    'institution_type' => $institutionType,
                    'name' => $name,
                    'address' => $address,
                    'contact' => $contact,
                    'email' => $email,
                    'password_hash' => password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    'status' => 'Pending',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $success = 'Your application has been submitted successfully. Please wait for Admin approval.';
                $_POST = [];
            }
        } catch (Throwable $e) {
            error_log('Clinic registration error: ' . $e->getMessage());
            $error = 'Registration could not be completed. Please try again.';
        }
    }
}

$navbar_type = 'clinic-register';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.clinic-register-page {
    min-height: 80vh;
    padding: 45px 16px;
    background: #f4f7fb;
}
.clinic-register-card {
    max-width: 760px;
    margin: 0 auto;
    padding: 28px 32px;
    background: #fff;
    border: 1px solid #e3eaf4;
    border-radius: 22px;
    box-shadow: 0 12px 36px rgba(20, 40, 80, .08);
}
.clinic-register-hero {
    position: relative;
    height: 205px;
    overflow: hidden;
    border-radius: 15px;
    margin-bottom: 26px;
    background: #173b72;
}
.clinic-register-hero img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    object-position: center;
}
.clinic-register-hero-content {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 24px 28px;
    color: #fff;
    background: linear-gradient(
        90deg,
        rgba(12, 36, 76, .90),
        rgba(12, 36, 76, .22)
    );
}
.clinic-register-hero-content .eyebrow {
    display: inline-block;
    align-self: flex-start;
    width: fit-content;
    max-width: 100%;
    background: rgba(255, 255, 255, 0.92);
    color: #2058ad;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.3px;
    line-height: 1.5;
    margin-bottom: 10px;
}
.clinic-register-hero-content h2 {
    max-width: 500px;
    color: #fff;
    font-size: 27px;
    line-height: 1.25;
    font-weight: 800;
    margin: 0 0 8px;
}
.clinic-register-hero-content p {
    max-width: 430px;
    color: #eef4ff;
    font-size: 14px;
    line-height: 1.6;
    margin: 0;
}
.clinic-register-card h1 {
    color: #173b72;
    font-size: 27px;
    line-height: 1.3;
    margin-bottom: 7px;
}
.clinic-register-card .subtitle {
    color: #64748b;
    margin-bottom: 25px;
}
.clinic-register-card label {
    display: block;
    font-weight: 600;
    margin-bottom: 7px;
    color: #26364b;
}
.clinic-register-card input,
.clinic-register-card select,
.clinic-register-card textarea {
    width: 100%;
    padding: 12px 13px;
    margin-bottom: 18px;
    border: 1px solid #d7deea;
    border-radius: 8px;
    font: inherit;
    box-sizing: border-box;
}
.clinic-register-card input:focus,
.clinic-register-card select:focus,
.clinic-register-card textarea:focus {
    outline: none;
    border-color: #2864c5;
    box-shadow: 0 0 0 3px rgba(40, 100, 197, .12);
}
.clinic-register-card button {
    width: 100%;
    padding: 13px;
    border: none;
    border-radius: 8px;
    background: #2058ad;
    color: white;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
}
.clinic-register-card button:hover {
    background: #174589;
}
.clinic-register-alert {
    padding: 12px 14px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.clinic-register-error {
    color: #842029;
    background: #f8d7da;
}
.clinic-register-success {
    color: #0f5132;
    background: #d1e7dd;
}
.clinic-register-note {
    margin-top: 18px;
    color: #64748b;
    font-size: 14px;
    text-align: center;
}
@media (max-width: 600px) {
    .clinic-register-card {
        padding: 20px 17px;
    }

    .clinic-register-hero {
        height: 180px;
        margin-bottom: 22px;
    }

    .clinic-register-hero-content {
        padding: 18px;
    }

    .clinic-register-hero-content h2 {
        font-size: 22px;
    }

    .clinic-register-hero-content p {
        font-size: 12px;
    }

    .clinic-register-card h1 {
        font-size: 24px;
    }
}

.clinic-register-card > h1,
.clinic-register-card > .subtitle {
    text-align: center;
}

.clinic-register-card > h1 {
    margin-top: 4px;
    margin-bottom: 8px;
}

.clinic-register-card > .subtitle {
    margin-bottom: 28px;
}
</style>

<main class="clinic-register-page">
    <section class="clinic-register-card">
        <div class="clinic-register-hero">
            <img
                src="assets/images/clinic-registration.jpg"
                alt="Doctor consulting a patient at a healthcare facility"
            >

            <div class="clinic-register-hero-content">
                <span class="eyebrow">MEDIBOOK HEALTHCARE</span>
                <h2>Grow Your Healthcare Institution</h2>
                <p>Connect your clinic or hospital with patients through MediBook.</p>
            </div>
        </div>

        <h1>Institution Registration</h1>
        <p class="subtitle">
            Enter your institution details to apply for registration.
        </p>

        <?php if ($error !== ''): ?>
            <div class="clinic-register-alert clinic-register-error">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="clinic-register-alert clinic-register-success">
                <?= h($success) ?>
            </div>
            <p class="clinic-register-note">
                You can log in after your application is approved by the Admin.
            </p>
        <?php else: ?>
            <form method="POST" action="">
                <?= csrf_field() ?>

                <label for="institution_type">Institution Type</label>
                <select id="institution_type" name="institution_type" required>
                    <option value="">Select institution type</option>
                    <?php foreach (['Clinic', 'Hospital', 'Diagnostic Center'] as $type): ?>
                        <option value="<?= h($type) ?>"
                            <?= (($_POST['institution_type'] ?? '') === $type) ? 'selected' : '' ?>>
                            <?= h($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="name">Institution Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="150"
                    placeholder="Enter institution name"
                    value="<?= h($_POST['name'] ?? '') ?>"
                    required
                >

                <label for="address">Address</label>
                <textarea
                    id="address"
                    name="address"
                    maxlength="500"
                    rows="3"
                    placeholder="Enter full address"
                    required
                ><?= h($_POST['address'] ?? '') ?></textarea>

                <label for="contact">Contact Number</label>
                <input
                    type="tel"
                    id="contact"
                    name="contact"
                    maxlength="30"
                    placeholder="Enter contact number"
                    value="<?= h($_POST['contact'] ?? '') ?>"
                    required
                >

                <label for="email">Official Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="254"
                    placeholder="Enter official email"
                    value="<?= h($_POST['email'] ?? '') ?>"
                    required
                >

                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Minimum 8 characters"
                    required
                >

                <label for="confirm_password">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    autocomplete="new-password"
                    placeholder="Enter password again"
                    required
                >

                <button type="submit">Submit Registration</button>
            </form>

            <p class="clinic-register-note">
                Your account will remain pending until Admin approval.
            </p>
        <?php endif; ?>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
