<?php

$page_title = 'My Profile';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

$patient_id = get_patient_id((int) $_SESSION['user_id']);

if (!$patient_id) {
    flash('error', 'Patient profile not found.');
    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle Profile Update
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals(
        $_SESSION['csrf_token'] ?? '',
        $_POST['csrf_token'] ?? ''
    )) {
        flash('error', 'Invalid security token. Please try again.');
        header('Location: profile.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $errors = [];

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */
    if ($name === '') {
        $errors[] = 'Name is required.';
    } elseif (mb_strlen($name) < 2) {
        $errors[] = 'Name must contain at least 2 characters.';
    } elseif (mb_strlen($name) > 100) {
        $errors[] = 'Name cannot exceed 100 characters.';
    }

    if ($date_of_birth !== '') {
        $date_object = DateTime::createFromFormat('Y-m-d', $date_of_birth);

        if (
            !$date_object ||
            $date_object->format('Y-m-d') !== $date_of_birth
        ) {
            $errors[] = 'Please enter a valid date of birth.';
        } elseif ($date_of_birth > date('Y-m-d')) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
    }

    $allowed_genders = ['Male', 'Female', 'Other'];

    if ($gender !== '' && !in_array($gender, $allowed_genders, true)) {
        $errors[] = 'Please select a valid gender.';
    }

    if ($contact !== '' && mb_strlen($contact) > 30) {
        $errors[] = 'Contact number cannot exceed 30 characters.';
    }

    if (mb_strlen($address) > 500) {
        $errors[] = 'Address cannot exceed 500 characters.';
    }

    /*
    |--------------------------------------------------------------------------
    | Save Profile
    |--------------------------------------------------------------------------
    */
    if (!empty($errors)) {

        flash('error', implode(' ', $errors));

    } else {

        try {
            // Update user name
            $userResult = $mongoDb->users->updateOne(
                [
                    'user_id' => (int) $_SESSION['user_id']
                ],
                [
                    '$set' => [
                        'name' => $name
                    ]
                ]
            );

            // Update patient information
            $patientResult = $mongoDb->patients->updateOne(
                [
                    'patient_id' => (int) $patient_id,
                    'user_id' => (int) $_SESSION['user_id']
                ],
                [
                    '$set' => [
                        'date_of_birth' =>
                            $date_of_birth !== '' ? $date_of_birth : null,
                        'gender' =>
                            $gender !== '' ? $gender : null,
                        'contact' =>
                            $contact !== '' ? $contact : null,
                        'address' =>
                            $address !== '' ? $address : null
                    ]
                ]
            );

            if ($patientResult->getMatchedCount() === 0) {
                throw new RuntimeException(
                    'Patient profile was not found.'
                );
            }

            // Audit log
            if (function_exists('audit')) {
                audit(
                    (int) $_SESSION['user_id'],
                    'Updated patient profile',
                    'patient',
                    $patient_id
                );
            }

            flash(
                'success',
                'Your profile has been updated successfully.'
            );
        } catch (Throwable $e) {
            error_log('Profile update error: ' . $e->getMessage());

            flash(
                'error',
                'Unable to update your profile right now. Please try again.'
            );
        }
    }

    header('Location: profile.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Current Profile
|--------------------------------------------------------------------------
*/
$patientDoc = $mongoDb->patients->findOne([
    'patient_id' => (int) $patient_id,
    'user_id' => (int) $_SESSION['user_id']
]);

$patient = null;

if ($patientDoc) {
    $userDoc = $mongoDb->users->findOne([
        'user_id' => (int) $_SESSION['user_id']
    ]);

    if ($userDoc) {
        $createdAt = $userDoc->created_at ?? '';

        if ($createdAt instanceof MongoDB\BSON\UTCDateTime) {
            $createdAt = $createdAt->toDateTime()
                ->format('Y-m-d H:i:s');
        }

        $patient = [
            'patient_id' => $patientDoc->patient_id ?? $patient_id,
            'user_id' => $patientDoc->user_id ?? $_SESSION['user_id'],
            'date_of_birth' => $patientDoc->date_of_birth ?? '',
            'gender' => $patientDoc->gender ?? '',
            'contact' => $patientDoc->contact ?? '',
            'address' => $patientDoc->address ?? '',
            'name' => $userDoc->name ?? '',
            'email' => $userDoc->email ?? '',
            'status' => $userDoc->status ?? 'Active',
            'created_at' => (string) $createdAt
        ];
    }
}

if (!$patient) {
    flash('error', 'Unable to load your profile.');
    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Initials
|--------------------------------------------------------------------------
*/
$name_parts = preg_split('/\s+/', trim($patient['name']));

$initials = '';

if (!empty($name_parts[0])) {
    $initials .= strtoupper(substr($name_parts[0], 0, 1));
}

if (
    count($name_parts) > 1 &&
    !empty($name_parts[count($name_parts) - 1])
) {
    $initials .= strtoupper(
        substr($name_parts[count($name_parts) - 1], 0, 1)
    );
}

if ($initials === '') {
    $initials = 'P';
}

?>

<div class="profile-page">

    <!-- Header -->
    <div class="profile-header">

        <div>
            <span class="page-eyebrow">
                PATIENT ACCOUNT
            </span>

            <h1>My Profile</h1>

            <p>
                Manage your personal information and keep your account details up to date.
            </p>
        </div>

        <a href="dashboard.php" class="btn btn-light profile-back-btn">
            <i class="bi bi-arrow-left"></i>
            Dashboard
        </a>

    </div>


    <div class="row g-4">

        <!-- Left Profile Card -->
        <div class="col-lg-4">

            <div class="card profile-summary-card">

                <div class="profile-cover"></div>

                <div class="profile-summary-body">

                    <div class="profile-avatar">
                        <?= h($initials) ?>
                    </div>

                    <h2>
                        <?= h($patient['name']) ?>
                    </h2>

                    <p class="profile-email">
                        <?= h($patient['email']) ?>
                    </p>

                    <span class="account-status">
                        <span class="status-dot"></span>
                        <?= h(ucfirst($patient['status'] ?? 'Active')) ?>
                    </span>


                    <div class="profile-divider"></div>


                    <div class="profile-info-item">

                        <div class="profile-info-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>

                        <div>
                            <span>Patient ID</span>
                            <strong>
                                #<?= h($patient['patient_id']) ?>
                            </strong>
                        </div>

                    </div>


                    <div class="profile-info-item">

                        <div class="profile-info-icon">
                            <i class="bi bi-envelope"></i>
                        </div>

                        <div>
                            <span>Email</span>
                            <strong>
                                <?= h($patient['email']) ?>
                            </strong>
                        </div>

                    </div>


                    <div class="profile-info-item">

                        <div class="profile-info-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>

                        <div>
                            <span>Member Since</span>
                            <strong>
                                <?= !empty($patient['created_at'])
                                    ? date('d M Y', strtotime($patient['created_at']))
                                    : 'N/A'
                                ?>
                            </strong>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- Right Edit Section -->
        <div class="col-lg-8">

            <div class="card profile-form-card">

                <div class="card-header-custom">

                    <div>

                        <span class="section-label">
                            PERSONAL INFORMATION
                        </span>

                        <h2>Profile Details</h2>

                        <p>
                            Update your personal information below.
                        </p>

                    </div>

                    <div class="edit-icon">
                        <i class="bi bi-pencil-square"></i>
                    </div>

                </div>


                <form method="post" class="profile-form">

                    <?= csrf_field() ?>


                    <!-- Basic Information -->
                    <div class="form-section">

                        <h3>
                            <i class="bi bi-person"></i>
                            Basic Information
                        </h3>

                        <div class="row g-4">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Full Name
                                    <span>*</span>
                                </label>

                                <div class="input-wrapper">

                                    <i class="bi bi-person"></i>

                                    <input
                                        type="text"
                                        name="name"
                                        class="form-control"
                                        value="<?= h($patient['name']) ?>"
                                        maxlength="100"
                                        placeholder="Enter your full name"
                                        required
                                    >

                                </div>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">
                                    Email Address
                                </label>

                                <div class="input-wrapper">

                                    <i class="bi bi-envelope"></i>

                                    <input
                                        type="email"
                                        class="form-control"
                                        value="<?= h($patient['email']) ?>"
                                        disabled
                                    >

                                </div>

                                <small class="form-help">
                                    Email address cannot be changed here.
                                </small>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">
                                    Date of Birth
                                </label>

                                <div class="input-wrapper">

                                    <i class="bi bi-calendar3"></i>

                                    <input
                                        type="date"
                                        name="date_of_birth"
                                        class="form-control"
                                        value="<?= h($patient['date_of_birth'] ?? '') ?>"
                                        max="<?= date('Y-m-d') ?>"
                                    >

                                </div>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">
                                    Gender
                                </label>

                                <div class="input-wrapper">

                                    <i class="bi bi-gender-ambiguous"></i>

                                    <select
                                        name="gender"
                                        class="form-select"
                                    >

                                        <option value="">
                                            Select gender
                                        </option>

                                        <option
                                            value="Male"
                                            <?= ($patient['gender'] ?? '') === 'Male' ? 'selected' : '' ?>
                                        >
                                            Male
                                        </option>

                                        <option
                                            value="Female"
                                            <?= ($patient['gender'] ?? '') === 'Female' ? 'selected' : '' ?>
                                        >
                                            Female
                                        </option>

                                        <option
                                            value="Other"
                                            <?= ($patient['gender'] ?? '') === 'Other' ? 'selected' : '' ?>
                                        >
                                            Other
                                        </option>

                                    </select>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- Contact Information -->
                    <div class="form-section">

                        <h3>
                            <i class="bi bi-telephone"></i>
                            Contact Information
                        </h3>

                        <div class="row g-4">

                            <div class="col-md-6">

                                <label class="form-label">
                                    Contact Number
                                </label>

                                <div class="input-wrapper">

                                    <i class="bi bi-phone"></i>

                                    <input
                                        type="text"
                                        name="contact"
                                        class="form-control"
                                        value="<?= h($patient['contact'] ?? '') ?>"
                                        maxlength="30"
                                        placeholder="e.g. 01XXXXXXXXX"
                                    >

                                </div>

                            </div>


                            <div class="col-md-6">

                                <label class="form-label">
                                    Account Status
                                </label>

                                <div class="input-wrapper">

                                    <i class="bi bi-shield-check"></i>

                                    <input
                                        type="text"
                                        class="form-control"
                                        value="<?= h(ucfirst($patient['status'] ?? 'Active')) ?>"
                                        disabled
                                    >

                                </div>

                            </div>


                            <div class="col-12">

                                <label class="form-label">
                                    Address
                                </label>

                                <div class="textarea-wrapper">

                                    <i class="bi bi-geo-alt"></i>

                                    <textarea
                                        name="address"
                                        class="form-control"
                                        rows="4"
                                        maxlength="500"
                                        placeholder="Enter your current address"
                                    ><?= h($patient['address'] ?? '') ?></textarea>

                                </div>

                                <small class="form-help">
                                    Maximum 500 characters.
                                </small>

                            </div>

                        </div>

                    </div>


                    <!-- Actions -->
                    <div class="form-actions">

                        <a
                            href="security.php"
                            class="btn btn-outline-primary"
                        >
                            <i class="bi bi-shield-lock"></i>
                            Security
                        </a>

                        <a
                            href="dashboard.php"
                            class="btn btn-outline-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary save-profile-btn"
                        >
                            <i class="bi bi-check2-circle"></i>
                            Save Changes
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>


<style>

.profile-page {
    padding-bottom: 40px;
}

.profile-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 28px;
}

.page-eyebrow,
.section-label {
    display: block;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.4px;
    color: #2563eb;
    margin-bottom: 7px;
}

.profile-header h1 {
    margin: 0;
    font-size: 32px;
    font-weight: 800;
    color: #172033;
}

.profile-header p {
    margin: 8px 0 0;
    color: #64748b;
}

.profile-back-btn {
    border: 1px solid #e2e8f0;
    font-weight: 600;
    padding: 10px 16px;
    white-space: nowrap;
}

.profile-summary-card,
.profile-form-card {
    border: 1px solid #e8edf4;
    border-radius: 18px;
    background: #fff;
    overflow: hidden;
}

.profile-cover {
    height: 105px;
    background:
        linear-gradient(
            135deg,
            #2563eb 0%,
            #3b82f6 50%,
            #60a5fa 100%
        );
}

.profile-summary-body {
    text-align: center;
    padding: 0 28px 30px;
}

.profile-avatar {
    width: 88px;
    height: 88px;
    margin: -44px auto 16px;
    border-radius: 50%;
    background: #fff;
    border: 5px solid #fff;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .14);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2563eb;
    font-size: 27px;
    font-weight: 800;
}

.profile-summary-body h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.profile-email {
    color: #64748b;
    font-size: 14px;
    margin: 6px 0 13px;
    word-break: break-word;
}

.account-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 6px 11px;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    font-size: 12px;
    font-weight: 700;
}

.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
}

.profile-divider {
    height: 1px;
    background: #edf1f5;
    margin: 25px 0 10px;
}

.profile-info-item {
    display: flex;
    align-items: center;
    gap: 13px;
    text-align: left;
    padding: 14px 0;
}

.profile-info-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    border-radius: 11px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
}

.profile-info-item span {
    display: block;
    font-size: 11px;
    color: #94a3b8;
    margin-bottom: 3px;
}

.profile-info-item strong {
    display: block;
    color: #334155;
    font-size: 13px;
    word-break: break-word;
}

.card-header-custom {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 26px 28px;
    border-bottom: 1px solid #edf1f5;
}

.card-header-custom h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.card-header-custom p {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 13px;
}

.edit-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.profile-form {
    padding: 28px;
}

.form-section {
    padding-bottom: 28px;
    margin-bottom: 28px;
    border-bottom: 1px solid #edf1f5;
}

.form-section:last-of-type {
    border-bottom: 0;
    margin-bottom: 0;
}

.form-section h3 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 20px;
    color: #334155;
    font-size: 16px;
    font-weight: 750;
}

.form-section h3 i {
    color: #2563eb;
}

.form-label {
    font-size: 13px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 8px;
}

.form-label span {
    color: #ef4444;
}

.input-wrapper,
.textarea-wrapper {
    position: relative;
}

.input-wrapper > i,
.textarea-wrapper > i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    z-index: 2;
}

.textarea-wrapper > i {
    top: 18px;
    transform: none;
}

.input-wrapper .form-control,
.input-wrapper .form-select {
    min-height: 47px;
    padding-left: 42px;
    border: 1px solid #dce3ec;
    border-radius: 11px;
    color: #334155;
    background: #fff;
}

.textarea-wrapper .form-control {
    padding: 13px 14px 13px 42px;
    border: 1px solid #dce3ec;
    border-radius: 11px;
    resize: vertical;
}

.input-wrapper .form-control:focus,
.input-wrapper .form-select:focus,
.textarea-wrapper .form-control:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .09);
}

.input-wrapper .form-control:disabled {
    background: #f8fafc;
    color: #64748b;
    cursor: not-allowed;
}

.form-help {
    display: block;
    margin-top: 6px;
    color: #94a3b8;
    font-size: 11px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 8px;
}

.form-actions .btn {
    padding: 11px 18px;
    border-radius: 10px;
    font-weight: 700;
}

.save-profile-btn {
    min-width: 145px;
}


/* Responsive */

@media (max-width: 991px) {

    .profile-header {
        align-items: flex-start;
    }

}

@media (max-width: 767px) {

    .profile-header {
        flex-direction: column;
        align-items: stretch;
    }

    .profile-header h1 {
        font-size: 27px;
    }

    .profile-back-btn {
        width: fit-content;
    }

    .card-header-custom {
        padding: 22px 20px;
    }

    .profile-form {
        padding: 22px 20px;
    }

    .profile-summary-body {
        padding-left: 20px;
        padding-right: 20px;
    }

}

@media (max-width: 480px) {

    .profile-header h1 {
        font-size: 24px;
    }

    .card-header-custom h2 {
        font-size: 19px;
    }

    .form-actions {
        flex-direction: column-reverse;
    }

    .form-actions .btn {
        width: 100%;
    }

}

</style>


<?php require '../includes/footer.php'; ?>