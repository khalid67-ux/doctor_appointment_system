<?php

$page_title = 'Account Security';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/
$user_id = (int) $_SESSION['user_id'];

$userDoc = $mongoDb->users->findOne([
    'user_id' => $user_id
]);

$user = $userDoc ? (array) $userDoc : null;

if (!$user) {
    flash('error', 'User account not found.');
    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Password Change
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    | CSRF protection
    */
    if (
        !hash_equals(
            $_SESSION['csrf_token'] ?? '',
            $_POST['csrf_token'] ?? ''
        )
    ) {
        flash('error', 'Invalid security token. Please try again.');
        header('Location: security.php');
        exit;
    }

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($current_password === '') {
        $errors[] = 'Current password is required.';
    }

    if ($new_password === '') {
        $errors[] = 'New password is required.';
    }

    if ($confirm_password === '') {
        $errors[] = 'Please confirm your new password.';
    }

    /*
    | Password length
    */
    if (
        $new_password !== '' &&
        strlen($new_password) < 8
    ) {
        $errors[] = 'New password must contain at least 8 characters.';
    }

    /*
    | Maximum password length
    */
    if (
        $new_password !== '' &&
        strlen($new_password) > 72
    ) {
        $errors[] = 'New password cannot exceed 72 characters.';
    }

    /*
    | Confirm password
    */
    if (
        $new_password !== '' &&
        $confirm_password !== '' &&
        !hash_equals($new_password, $confirm_password)
    ) {
        $errors[] = 'New password and confirmation password do not match.';
    }

    /*
    | Check current password
    */
    if (
        empty($errors) &&
        !password_verify(
            $current_password,
            $user['password_hash']
        )
    ) {
        $errors[] = 'Current password is incorrect.';
    }

    /*
    | Prevent same password
    */
    if (
        empty($errors) &&
        password_verify(
            $new_password,
            $user['password_hash']
        )
    ) {
        $errors[] = 'New password must be different from your current password.';
    }

    /*
    |--------------------------------------------------------------------------
    | Update Password
    |--------------------------------------------------------------------------
    */
    if (!empty($errors)) {

        flash('error', implode(' ', $errors));

    } else {

        try {
            // Create a secure password hash
            $new_password_hash = password_hash(
                $new_password,
                PASSWORD_DEFAULT
            );

            if ($new_password_hash === false) {
                throw new RuntimeException(
                    'Password hashing failed.'
                );
            }

            // Update only if the stored password hash has not changed
            $updateResult = $mongoDb->users->updateOne(
                [
                    'user_id' => $user_id,
                    'password_hash' => $user['password_hash']
                ],
                [
                    '$set' => [
                        'password_hash' => $new_password_hash
                    ]
                ]
            );

            if ($updateResult->getModifiedCount() !== 1) {
                throw new RuntimeException(
                    'Your account password changed or the account was updated. Please sign in again and retry.'
                );
            }

            // Audit log
            if (function_exists('audit')) {
                audit(
                    $user_id,
                    'Changed account password',
                    'user',
                    $user_id
                );
            }

            flash(
                'success',
                'Your password has been changed successfully.'
            );
        } catch (Throwable $e) {
            error_log('Password Change Error: ' . $e->getMessage());

            flash(
                'error',
                'Unable to change your password right now. Please try again.'
            );
        }
    }

    header('Location: security.php');
    exit;
}

?>

<div class="security-page">

    <!-- Page Header -->
    <div class="security-header">

        <div>

            <span class="page-eyebrow">
                ACCOUNT SECURITY
            </span>

            <h1>Security Settings</h1>

            <p>
                Protect your healthcare account by keeping your password secure.
            </p>

        </div>

        <a
            href="profile.php"
            class="btn btn-light back-btn"
        >
            <i class="bi bi-arrow-left"></i>
            My Profile
        </a>

    </div>


    <div class="row g-4">

        <!-- Security Overview -->
        <div class="col-lg-4">

            <div class="card security-info-card">

                <div class="security-icon">

                    <i class="bi bi-shield-lock"></i>

                </div>

                <h2>
                    Keep Your Account Secure
                </h2>

                <p>
                    Your healthcare account contains personal information.
                    Use a strong and unique password to protect your account.
                </p>


                <div class="security-divider"></div>


                <div class="security-tip">

                    <div class="tip-icon">
                        <i class="bi bi-check2"></i>
                    </div>

                    <div>
                        <strong>Use at least 8 characters</strong>
                        <span>
                            Longer passwords are harder to guess.
                        </span>
                    </div>

                </div>


                <div class="security-tip">

                    <div class="tip-icon">
                        <i class="bi bi-check2"></i>
                    </div>

                    <div>
                        <strong>Use a unique password</strong>
                        <span>
                            Avoid reusing passwords from other accounts.
                        </span>
                    </div>

                </div>


                <div class="security-tip">

                    <div class="tip-icon">
                        <i class="bi bi-check2"></i>
                    </div>

                    <div>
                        <strong>Never share your password</strong>
                        <span>
                            Keep your account credentials private.
                        </span>
                    </div>

                </div>


                <div class="security-tip">

                    <div class="tip-icon">
                        <i class="bi bi-check2"></i>
                    </div>

                    <div>
                        <strong>Avoid simple passwords</strong>
                        <span>
                            Do not use names, dates or common words.
                        </span>
                    </div>

                </div>

            </div>

        </div>


        <!-- Change Password -->
        <div class="col-lg-8">

            <div class="card password-card">

                <div class="password-card-header">

                    <div>

                        <span class="section-label">
                            PASSWORD MANAGEMENT
                        </span>

                        <h2>
                            Change Password
                        </h2>

                        <p>
                            Enter your current password and choose a new password.
                        </p>

                    </div>

                    <div class="password-header-icon">

                        <i class="bi bi-key"></i>

                    </div>

                </div>


                <form
                    method="post"
                    class="password-form"
                    id="passwordForm"
                >

                    <?= csrf_field() ?>


                    <!-- Current Password -->
                    <div class="password-field">

                        <label class="form-label">
                            Current Password
                            <span>*</span>
                        </label>

                        <div class="password-input">

                            <i class="bi bi-lock"></i>

                            <input
                                type="password"
                                name="current_password"
                                id="currentPassword"
                                class="form-control"
                                placeholder="Enter your current password"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                data-target="currentPassword"
                                aria-label="Show or hide password"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                        </div>

                    </div>


                    <!-- New Password -->
                    <div class="password-field">

                        <label class="form-label">
                            New Password
                            <span>*</span>
                        </label>

                        <div class="password-input">

                            <i class="bi bi-shield-lock"></i>

                            <input
                                type="password"
                                name="new_password"
                                id="newPassword"
                                class="form-control"
                                placeholder="Enter a new password"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                data-target="newPassword"
                                aria-label="Show or hide password"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                        </div>

                        <!-- Password Strength -->
                        <div
                            class="password-strength"
                            id="passwordStrength"
                        >

                            <div class="strength-bars">

                                <span></span>
                                <span></span>
                                <span></span>
                                <span></span>

                            </div>

                            <span
                                class="strength-text"
                                id="strengthText"
                            >
                                Enter a password
                            </span>

                        </div>

                    </div>


                    <!-- Confirm Password -->
                    <div class="password-field">

                        <label class="form-label">
                            Confirm New Password
                            <span>*</span>
                        </label>

                        <div class="password-input">

                            <i class="bi bi-check2-square"></i>

                            <input
                                type="password"
                                name="confirm_password"
                                id="confirmPassword"
                                class="form-control"
                                placeholder="Confirm your new password"
                                minlength="8"
                                maxlength="72"
                                autocomplete="new-password"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle"
                                data-target="confirmPassword"
                                aria-label="Show or hide password"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                        </div>

                        <div
                            class="password-match"
                            id="passwordMatch"
                        ></div>

                    </div>


                    <!-- Requirements -->
                    <div class="password-requirements">

                        <div class="requirements-title">
                            <i class="bi bi-info-circle"></i>
                            Password requirements
                        </div>

                        <div class="requirements-grid">

                            <div id="reqLength">
                                <i class="bi bi-circle"></i>
                                At least 8 characters
                            </div>

                            <div id="reqUpper">
                                <i class="bi bi-circle"></i>
                                One uppercase letter
                            </div>

                            <div id="reqLower">
                                <i class="bi bi-circle"></i>
                                One lowercase letter
                            </div>

                            <div id="reqNumber">
                                <i class="bi bi-circle"></i>
                                One number
                            </div>

                        </div>

                    </div>


                    <!-- Actions -->
                    <div class="form-actions">

                        <a
                            href="profile.php"
                            class="btn btn-outline-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-primary change-password-btn"
                            id="changePasswordBtn"
                        >
                            <i class="bi bi-shield-check"></i>
                            Change Password
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>


<style>

.security-page {
    padding-bottom: 40px;
}

.security-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 28px;
}

.page-eyebrow,
.section-label {
    display: block;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.4px;
    margin-bottom: 7px;
}

.security-header h1 {
    margin: 0;
    color: #172033;
    font-size: 32px;
    font-weight: 800;
}

.security-header p {
    margin: 8px 0 0;
    color: #64748b;
}

.back-btn {
    border: 1px solid #e2e8f0;
    font-weight: 700;
    padding: 10px 16px;
    white-space: nowrap;
}


/* Security Info */

.security-info-card,
.password-card {
    border: 1px solid #e8edf4;
    border-radius: 18px;
    background: #fff;
    overflow: hidden;
}

.security-info-card {
    padding: 30px;
    height: 100%;
}

.security-icon {
    width: 58px;
    height: 58px;
    border-radius: 15px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 25px;
    margin-bottom: 20px;
}

.security-info-card h2 {
    margin: 0;
    color: #172033;
    font-size: 21px;
    font-weight: 800;
}

.security-info-card > p {
    margin: 10px 0 0;
    color: #64748b;
    font-size: 13px;
    line-height: 1.7;
}

.security-divider {
    height: 1px;
    background: #edf1f5;
    margin: 25px 0 8px;
}

.security-tip {
    display: flex;
    gap: 12px;
    padding: 13px 0;
}

.tip-icon {
    width: 30px;
    height: 30px;
    flex: 0 0 30px;
    border-radius: 50%;
    background: #ecfdf5;
    color: #059669;
    display: flex;
    align-items: center;
    justify-content: center;
}

.security-tip strong {
    display: block;
    color: #334155;
    font-size: 13px;
    margin-bottom: 3px;
}

.security-tip span {
    display: block;
    color: #94a3b8;
    font-size: 11px;
    line-height: 1.5;
}


/* Password Card */

.password-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 27px 30px;
    border-bottom: 1px solid #edf1f5;
}

.password-card-header h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.password-card-header p {
    margin: 6px 0 0;
    color: #64748b;
    font-size: 13px;
}

.password-header-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.password-form {
    padding: 30px;
}

.password-field {
    margin-bottom: 22px;
}

.form-label {
    display: block;
    color: #334155;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}

.form-label span {
    color: #ef4444;
}

.password-input {
    position: relative;
}

.password-input > i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    z-index: 2;
}

.password-input .form-control {
    min-height: 48px;
    padding: 0 47px 0 43px;
    border: 1px solid #dce3ec;
    border-radius: 11px;
}

.password-input .form-control:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .09);
}

.password-toggle {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 34px;
    height: 34px;
    border: 0;
    background: transparent;
    color: #94a3b8;
    border-radius: 8px;
    cursor: pointer;
}

.password-toggle:hover {
    background: #f1f5f9;
    color: #2563eb;
}


/* Strength */

.password-strength {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 9px;
}

.strength-bars {
    display: flex;
    gap: 4px;
}

.strength-bars span {
    width: 30px;
    height: 4px;
    border-radius: 4px;
    background: #e2e8f0;
}

.strength-text {
    color: #94a3b8;
    font-size: 11px;
    font-weight: 600;
}


/* Requirements */

.password-requirements {
    background: #f8fafc;
    border: 1px solid #e8edf4;
    border-radius: 12px;
    padding: 17px;
    margin: 3px 0 25px;
}

.requirements-title {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #475569;
    font-size: 12px;
    font-weight: 800;
    margin-bottom: 12px;
}

.requirements-title i {
    color: #2563eb;
}

.requirements-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px 20px;
}

.requirements-grid div {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #94a3b8;
    font-size: 11px;
}

.requirements-grid div.valid {
    color: #059669;
}

.requirements-grid div.valid i::before {
    content: "\f26b";
}

.requirements-grid div.valid i {
    color: #059669;
}


/* Password Match */

.password-match {
    min-height: 17px;
    margin-top: 7px;
    font-size: 11px;
    font-weight: 600;
}

.password-match.match {
    color: #059669;
}

.password-match.no-match {
    color: #ef4444;
}


/* Actions */

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 5px;
}

.form-actions .btn {
    border-radius: 10px;
    padding: 11px 18px;
    font-weight: 700;
}

.change-password-btn {
    min-width: 165px;
}


/* Responsive */

@media (max-width: 991px) {

    .security-header {
        align-items: flex-start;
    }

}

@media (max-width: 767px) {

    .security-header {
        flex-direction: column;
        align-items: stretch;
    }

    .security-header h1 {
        font-size: 27px;
    }

    .back-btn {
        width: fit-content;
    }

    .security-info-card {
        padding: 23px;
    }

    .password-card-header {
        padding: 23px;
    }

    .password-form {
        padding: 23px;
    }

}

@media (max-width: 480px) {

    .security-header h1 {
        font-size: 24px;
    }

    .password-card-header h2 {
        font-size: 19px;
    }

    .requirements-grid {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column-reverse;
    }

    .form-actions .btn {
        width: 100%;
    }

}

</style>


<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | Show / Hide Password
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.password-toggle').forEach(function (button) {

        button.addEventListener('click', function () {

            const targetId = this.dataset.target;
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');

            if (!input) {
                return;
            }

            if (input.type === 'password') {

                input.type = 'text';

                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');

            } else {

                input.type = 'password';

                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');

            }

        });

    });


    /*
    |--------------------------------------------------------------------------
    | Password Strength
    |--------------------------------------------------------------------------
    */

    const newPassword = document.getElementById('newPassword');
    const strengthText = document.getElementById('strengthText');
    const strengthBars = document.querySelectorAll(
        '.strength-bars span'
    );

    const reqLength = document.getElementById('reqLength');
    const reqUpper = document.getElementById('reqUpper');
    const reqLower = document.getElementById('reqLower');
    const reqNumber = document.getElementById('reqNumber');


    function updateRequirement(element, valid) {

        if (!element) {
            return;
        }

        if (valid) {

            element.classList.add('valid');

        } else {

            element.classList.remove('valid');

        }

    }


    function updatePasswordStrength() {

        const password = newPassword.value;

        const hasLength = password.length >= 8;
        const hasUpper = /[A-Z]/.test(password);
        const hasLower = /[a-z]/.test(password);
        const hasNumber = /[0-9]/.test(password);

        updateRequirement(reqLength, hasLength);
        updateRequirement(reqUpper, hasUpper);
        updateRequirement(reqLower, hasLower);
        updateRequirement(reqNumber, hasNumber);


        let score = 0;

        if (hasLength) score++;
        if (hasUpper) score++;
        if (hasLower) score++;
        if (hasNumber) score++;

        strengthBars.forEach(function (bar) {
            bar.style.background = '#e2e8f0';
        });


        if (password.length === 0) {

            strengthText.textContent = 'Enter a password';

        } else if (score <= 2) {

            strengthText.textContent = 'Weak';

            for (let i = 0; i < score; i++) {
                strengthBars[i].style.background = '#ef4444';
            }

        } else if (score === 3) {

            strengthText.textContent = 'Good';

            for (let i = 0; i < score; i++) {
                strengthBars[i].style.background = '#f59e0b';
            }

        } else {

            strengthText.textContent = 'Strong';

            for (let i = 0; i < score; i++) {
                strengthBars[i].style.background = '#10b981';
            }

        }

    }


    if (newPassword) {
        newPassword.addEventListener(
            'input',
            updatePasswordStrength
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Password Confirmation
    |--------------------------------------------------------------------------
    */

    const confirmPassword =
        document.getElementById('confirmPassword');

    const passwordMatch =
        document.getElementById('passwordMatch');


    function checkPasswordMatch() {

        if (!confirmPassword.value) {

            passwordMatch.textContent = '';
            passwordMatch.className = 'password-match';

            return;
        }

        if (
            newPassword.value ===
            confirmPassword.value
        ) {

            passwordMatch.textContent =
                'Passwords match.';

            passwordMatch.className =
                'password-match match';

        } else {

            passwordMatch.textContent =
                'Passwords do not match.';

            passwordMatch.className =
                'password-match no-match';

        }

    }


    if (confirmPassword) {

        confirmPassword.addEventListener(
            'input',
            checkPasswordMatch
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Form Submit Protection
    |--------------------------------------------------------------------------
    */

    const form =
        document.getElementById('passwordForm');

    const submitButton =
        document.getElementById('changePasswordBtn');


    if (form) {

        form.addEventListener('submit', function (event) {

            if (
                newPassword.value !==
                confirmPassword.value
            ) {

                event.preventDefault();

                alert(
                    'New password and confirmation password do not match.'
                );

                confirmPassword.focus();

                return;
            }


            if (newPassword.value.length < 8) {

                event.preventDefault();

                alert(
                    'New password must contain at least 8 characters.'
                );

                newPassword.focus();

                return;
            }


            if (submitButton) {

                submitButton.disabled = true;

                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2"></span>' +
                    'Changing Password...';

            }

        });

    }

});

</script>


<?php require '../includes/footer.php'; ?>