<?php

require_once __DIR__ . '/config/mongodb.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'patient';

    $contact = trim($_POST['contact'] ?? '');

    $qualification = trim($_POST['qualification'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $consultation_fee = trim($_POST['consultation_fee'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, ['patient', 'doctor'], true)) {
        $error = 'Invalid account type.';
    } elseif ($role === 'doctor' && $qualification === '') {
        $error = 'Qualification is required for doctor registration.';
    } elseif (
        $experience !== '' &&
        (!ctype_digit($experience))
    ) {
        $error = 'Experience must be a valid non-negative number.';
    } elseif (
        $consultation_fee !== '' &&
        (!is_numeric($consultation_fee) || (float) $consultation_fee < 0)
    ) {
        $error = 'Consultation fee must be a valid non-negative number.';
    } else {
        $users = $mongoDb->selectCollection('users');
        $patients = $mongoDb->selectCollection('patients');
        $doctors = $mongoDb->selectCollection('doctors');

        $userId = null;
        $relatedRecordId = null;
        $relatedCollection = null;

        try {
            $existingUser = $users->findOne(['email' => $email]);

            if ($existingUser !== null) {
                $error = 'An account with this email already exists.';
            } else {
                // Preserve compatibility with the existing login code.
                // Password hashing should be migrated consistently later.
                $passwordHash = $password;

                // Generate the next numeric ID, preserving the existing schema.
                $nextId = static function (
                    MongoDB\Collection $collection,
                    string $field
                ): int {
                    $last = $collection->findOne(
                        [],
                        ['sort' => [$field => -1]]
                    );

                    return $last === null
                        ? 1
                        : ((int) ($last[$field] ?? 0) + 1);
                };

                $userId = $nextId($users, 'user_id');

                $users->insertOne([
                    'user_id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => $role,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                if ($role === 'patient') {
                    $patientId = $nextId($patients, 'patient_id');

                    $patients->insertOne([
                        'patient_id' => $patientId,
                        'user_id' => $userId,
                        'date_of_birth' => null,
                        'gender' => null,
                        'contact' => $contact !== '' ? $contact : null,
                        'address' => null,
                    ]);

                    $relatedCollection = $patients;
                    $relatedRecordId = $patientId;
                } else {
                    $doctorId = $nextId($doctors, 'doctor_id');

                    $fee = $consultation_fee !== ''
                        ? number_format((float) $consultation_fee, 2, '.', '')
                        : '0.00';

                    $doctors->insertOne([
                        'doctor_id' => $doctorId,
                        'user_id' => $userId,
                        'specialization_id' => null,
                        'verification_status' => 'Pending',
                        'qualification' => $qualification,
                        'experience' => $experience !== ''
                            ? (int) $experience
                            : 0,
                        'consultation_fee' => new MongoDB\BSON\Decimal128($fee),
                    ]);

                    $relatedCollection = $doctors;
                    $relatedRecordId = $doctorId;
                }

                $success = 'Registration successful! You can now login.';
            }
        } catch (Throwable $e) {
            error_log('MongoDB registration error: ' . $e->getMessage());

            // Compensate if the related patient/doctor record could not be created.
            if ($userId !== null) {
                try {
                    if ($relatedCollection !== null && $relatedRecordId !== null) {
                        $idField = $role === 'patient'
                            ? 'patient_id'
                            : 'doctor_id';

                        $relatedCollection->deleteOne([
                            $idField => $relatedRecordId,
                        ]);
                    }

                    $users->deleteOne(['user_id' => $userId]);
                } catch (Throwable $cleanupError) {
                    error_log(
                        'Registration cleanup error: '
                        . $cleanupError->getMessage()
                    );
                }
            }

            $error = 'Registration failed. Please try again.';
        }
    }
}
?>

<?php
$navbar_type = 'register';
require_once __DIR__ . '/includes/header.php';
?>

<div class="auth-page">
    <div class="auth-container">

        <div class="auth-card">

            <div class="registration-hero">
                <img
                    src="assets/images/healthcare-registration.jpg"
                    alt="Doctor providing professional healthcare"
                >
                <div class="registration-hero-overlay">
                    <span>MEDIBOOK HEALTHCARE</span>
                    <h2>Your Health, Our Priority</h2>
                    <p>Create your account to get started with your healthcare journey.</p>
                </div>
            </div>

            <div class="auth-header">
                <span class="auth-icon">🏥</span>

                <h1>Create Your Healthcare Account</h1>

                <p>
                    Join MediBook to book appointments or register as a doctor.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <?= htmlspecialchars($success) ?>
                </div>

                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-primary">
                        Go to Login
                    </a>
                </div>
            <?php else: ?>

                <form method="POST" action="">
                    <?= csrf_field() ?>

                    <!-- Name -->
                    <div class="form-group">
                        <label for="name">
                            Full Name <span>*</span>
                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            placeholder="Enter your full name"
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            required
                        >
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label for="email">
                            Email Address <span>*</span>
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            required
                        >
                    </div>

                    <!-- Account Type -->
                    <div class="form-group">
                        <label for="role">
                            Account Type <span>*</span>
                        </label>

                        <select id="role" name="role" required>
                            <option value="patient"
                                <?= (($_POST['role'] ?? '') === 'patient') ? 'selected' : '' ?>>
                                Patient
                            </option>

                            <option value="doctor"
                                <?= (($_POST['role'] ?? '') === 'doctor') ? 'selected' : '' ?>>
                                Doctor
                            </option>
                        </select>
                    </div>

                    <!-- Patient Information -->
                    <div id="patientFields">

                        <div class="form-group">
                            <label for="contact">
                                Contact Number
                            </label>

                            <input
                                type="text"
                                id="contact"
                                name="contact"
                                placeholder="01XXXXXXXXX"
                                value="<?= htmlspecialchars($_POST['contact'] ?? '') ?>"
                            >
                        </div>

                    </div>

                    <!-- Doctor Information -->
                    <div id="doctorFields" style="display:none;">

                        <div class="form-group">
                            <label for="qualification">
                                Qualification <span>*</span>
                            </label>

                            <input
                                type="text"
                                id="qualification"
                                name="qualification"
                                placeholder="e.g. MBBS, FCPS"
                                value="<?= htmlspecialchars($_POST['qualification'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="experience">
                                Experience (Years)
                            </label>

                            <input
                                type="number"
                                id="experience"
                                name="experience"
                                min="0"
                                placeholder="e.g. 5"
                                value="<?= htmlspecialchars($_POST['experience'] ?? '') ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label for="consultation_fee">
                                Consultation Fee
                            </label>

                            <input
                                type="number"
                                id="consultation_fee"
                                name="consultation_fee"
                                min="0"
                                step="0.01"
                                placeholder="e.g. 500"
                                value="<?= htmlspecialchars($_POST['consultation_fee'] ?? '') ?>"
                            >
                        </div>

                        <div class="doctor-note">
                            <strong>Note:</strong>
                            Doctor accounts require admin verification before
                            becoming fully active.
                        </div>

                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label for="password">
                            Password <span>*</span>
                        </label>

                        <div class="password-wrapper">

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Minimum 6 characters"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                onclick="togglePassword('password', this)"
                            >
                                Show
                            </button>

                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="form-group">
                        <label for="confirm_password">
                            Confirm Password <span>*</span>
                        </label>

                        <div class="password-wrapper">

                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Re-enter your password"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                onclick="togglePassword('confirm_password', this)"
                            >
                                Show
                            </button>

                        </div>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        class="btn btn-primary btn-full"
                    >
                        <i class="bi bi-person-plus me-2"></i>
                        Create Your Account
                    </button>

                </form>

                <div class="auth-footer">
                    Already have an account?
                    <a href="login.php">Login here</a>
                </div>

            <?php endif; ?>

        </div>

    </div>
</div>

<style>
.auth-page {
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 34px 20px;
    background: #f4f7fc;
}

.auth-container {
    width: 100%;
    max-width: 620px;
}

.auth-card {
    background: #ffffff;
    border: 1px solid #e4eaf3;
    border-radius: 22px;
    padding: 32px 38px;
    box-shadow: 0 14px 40px rgba(30, 55, 90, 0.08);
}

.auth-header {
    text-align: center;
    margin-bottom: 28px;
}

.auth-icon {
    display: inline-flex;
    width: 60px;
    height: 60px;
    align-items: center;
    justify-content: center;
    background: #eaf2ff;
    border-radius: 18px;
    font-size: 29px;
    margin-bottom: 14px;
}

.auth-header h1 {
    margin: 0 0 9px;
    font-size: clamp(25px, 4vw, 32px);
    line-height: 1.25;
    font-weight: 800;
    letter-spacing: -0.7px;
    color: #17243b;
}

.auth-header p {
    margin: 0;
    color: #687991;
    font-size: 16px;
    line-height: 1.6;
}

.form-group {
    margin-bottom: 19px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 15px;
    font-weight: 650;
    color: #24334b;
}

.form-group label span {
    color: #e04444;
}

.form-group input,
.form-group select {
    width: 100%;
    min-height: 51px;
    box-sizing: border-box;
    padding: 12px 15px;
    border: 1px solid #d8e1ee;
    border-radius: 11px;
    font-family: inherit;
    font-size: 15px;
    color: #1d2b42;
    outline: none;
    background: #fff;
    transition: border-color .2s, box-shadow .2s;
}

.form-group input::placeholder {
    color: #9aa8ba;
}

.form-group input:hover,
.form-group select:hover {
    border-color: #b8c9e2;
}

.form-group input:focus,
.form-group select:focus {
    border-color: #2864ed;
    box-shadow: 0 0 0 4px rgba(40, 100, 237, .11);
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 76px;
}

.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    border: none;
    background: transparent;
    color: #285fe5;
    font-size: 14px;
    font-weight: 650;
    cursor: pointer;
}

.password-toggle:hover {
    color: #1746b7;
}

.btn-full {
    width: 100%;
    min-height: 52px;
    margin-top: 5px;
    padding: 13px 18px;
    border: none;
    border-radius: 11px;
    background: #2864ed;
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    transition: background .2s, transform .2s, box-shadow .2s;
}

.btn-full:hover {
    background: #1d50cb;
    box-shadow: 0 7px 18px rgba(40, 100, 237, .19);
    transform: translateY(-1px);
}

.alert {
    padding: 13px 15px;
    border-radius: 11px;
    margin-bottom: 20px;
    line-height: 1.5;
}

.alert-danger {
    background: #fff1f1;
    color: #b42323;
    border: 1px solid #ffd5d5;
}

.alert-success {
    background: #edfbf2;
    color: #187743;
    border: 1px solid #c7efd5;
}

.text-center {
    text-align: center;
}

.mt-3 {
    margin-top: 15px;
}

.auth-footer {
    text-align: center;
    margin-top: 23px;
    color: #6c7a90;
    font-size: 14px;
}

.auth-footer a {
    color: #2864ed;
    font-weight: 700;
    text-decoration: none;
}

.auth-footer a:hover {
    text-decoration: underline;
}

.doctor-note {
    background: #f0f6ff;
    border: 1px solid #d9e7ff;
    color: #31517f;
    padding: 13px 15px;
    border-radius: 11px;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 19px;
}

@media (max-width: 600px) {
    .auth-page {
        min-height: auto;
        padding: 24px 14px;
    }

    .auth-card {
        padding: 25px 20px;
        border-radius: 18px;
    }

    .auth-header {
        margin-bottom: 24px;
    }

    .auth-header h1 {
        font-size: 26px;
    }

    .form-group {
        margin-bottom: 17px;
    }
}

.registration-hero {
    position: relative;
    height: 220px;
    overflow: hidden;
    border-radius: 15px;
    margin-bottom: 28px;
    background: #183c70;
}

.registration-hero img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.registration-hero-overlay {
    position: absolute;
    inset: 0;
    padding: 25px 28px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    color: #fff;
    background: linear-gradient(
        90deg,
        rgba(10, 35, 75, .88),
        rgba(10, 35, 75, .25)
    );
}

.registration-hero-overlay span {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin-bottom: 10px;
}

.registration-hero-overlay h2 {
    font-size: 25px;
    font-weight: 800;
    line-height: 1.25;
    margin: 0 0 8px;
    color: #fff;
}

.registration-hero-overlay p {
    max-width: 350px;
    font-size: 13px;
    line-height: 1.6;
    margin: 0;
    color: #eef4ff;
}

@media (max-width: 600px) {
    .registration-hero {
        height: 190px;
    }

    .registration-hero-overlay {
        padding: 20px;
    }

    .registration-hero-overlay h2 {
        font-size: 22px;
    }
}
</style>

<script>
const roleSelect = document.getElementById('role');
const patientFields = document.getElementById('patientFields');
const doctorFields = document.getElementById('doctorFields');
const qualificationInput = document.getElementById('qualification');

function updateRoleFields() {

    if (roleSelect.value === 'doctor') {

        doctorFields.style.display = 'block';
        patientFields.style.display = 'none';

        qualificationInput.required = true;

    } else {

        doctorFields.style.display = 'none';
        patientFields.style.display = 'block';

        qualificationInput.required = false;
    }
}

roleSelect.addEventListener('change', updateRoleFields);

// Keep correct fields visible after validation error
updateRoleFields();

function togglePassword(inputId, button) {

    const input = document.getElementById(inputId);

    if (input.type === 'password') {

        input.type = 'text';
        button.textContent = 'Hide';

    } else {

        input.type = 'password';
        button.textContent = 'Show';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>