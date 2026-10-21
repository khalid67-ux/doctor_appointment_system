<?php
require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('patient');

$patient_id = get_patient_id((int) $_SESSION['user_id']);

$success = '';
$error = '';

$categories = [
    'Appointment Issue',
    'Payment Issue',
    'Doctor-related Issue',
    'Technical Issue',
    'Account & Login Issue',
    'Other'
];

// Fetch patient's appointments from MongoDB
$appointments = [];

$appointmentCursor = $mongoDb->selectCollection('appointments')->find(
    ['patient_id' => (int) $patient_id],
    ['sort' => ['appointment_date' => -1]]
);

foreach ($appointmentCursor as $appointmentDoc) {
    $appointments[] = $appointmentDoc->getArrayCopy();
}

// Handle complaint submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $category = trim($_POST['category'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $appointment_id = !empty($_POST['appointment_id'])
        ? (int) $_POST['appointment_id']
        : null;

    if (!in_array($category, $categories, true)) {
        $error = 'Please select a valid category.';
    } elseif (strlen($subject) < 3 || strlen($subject) > 200) {
        $error = 'Subject must be between 3 and 200 characters.';
    } elseif (strlen($description) < 10) {
        $error = 'Description must be at least 10 characters.';
    } else {
        // Verify that the appointment belongs to this patient
        if ($appointment_id !== null) {
            $check = $mongoDb->selectCollection('appointments')->findOne([
                'appointment_id' => (int) $appointment_id,
                'patient_id' => (int) $patient_id
            ]);

            if (!$check) {
                $error = 'Invalid appointment selected.';
            }
        }

        if (!$error) {
            $complaint_ref = 'CMP-' . date('Y') . '-'
                . strtoupper(bin2hex(random_bytes(4)));

            $complaintDocument = [
                'complaint_ref' => $complaint_ref,
                'patient_id' => (int) $patient_id,
                'appointment_id' => $appointment_id !== null
                    ? (int) $appointment_id
                    : null,
                'category' => $category,
                'subject' => $subject,
                'description' => $description,
                'status' => 'Pending',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $insertResult = $mongoDb->selectCollection('complaints')->insertOne(
                $complaintDocument
            );

            // Use the generated MongoDB document ID for the audit record.
            $complaint_id = (string) $insertResult->getInsertedId();

            create_notification(
                (int) $_SESSION['user_id'],
                'Complaint',
                'Complaint Submitted',
                "Your complaint $complaint_ref has been submitted successfully."
            );

            audit(
                (int) $_SESSION['user_id'],
                'Submitted complaint',
                'complaints',
                $complaint_id
            );

            $success = "Complaint submitted successfully! Reference: $complaint_ref";
        }
    }
}

$asset_prefix = '../';
require '../includes/header.php';
?>

<style>
/* Page Background */
body {
    background: #f5f7fb;
}

/* Main Container */
.complaint-page {
    max-width: 820px;
    margin: 45px auto;
    padding: 0 20px;
}

/* Complaint Card */
.complaint-container {
    background: #ffffff;
    border: 1px solid #e8edf5;
    border-radius: 18px;
    padding: 38px 40px;
    box-shadow: 0 8px 35px rgba(15, 23, 42, 0.07);
}

/* Header - Center Alignment */
.complaint-header {
    margin-bottom: 35px;
    text-align: center;
}

.complaint-icon {
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2563eb, #38bdf8);
    color: #ffffff;
    border-radius: 15px;
    font-size: 28px;
    margin: 0 auto 20px;
    box-shadow: 0 5px 14px rgba(37, 99, 235, 0.2);
}

.complaint-header h2 {
    color: #172554;
    font-size: 30px;
    font-weight: 700;
    margin: 0 0 12px;
}

.complaint-header p {
    color: #64748b;
    font-size: 16px;
    line-height: 1.7;
    margin: 0;
}

/* Form */
.form-group {
    margin-bottom: 23px;
}

.form-group label {
    display: block;
    color: #25334d;
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 10px;
}

.label-required {
    color: #ef4444;
    margin-left: 3px;
}

.label-optional {
    color: #94a3b8;
    font-size: 12px;
    font-weight: 400;
    margin-left: 5px;
}

/* Input Fields */
.form-group input,
.form-group select,
.form-group textarea {
    display: block;
    width: 100%;
    padding: 15px 16px;
    border: 1px solid #dbe2ea;
    border-radius: 9px;
    background: #ffffff;
    color: #1e293b;
    font-size: 16px;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-group input::placeholder,
.form-group textarea::placeholder {
    color: #94a3b8;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.form-group select {
    cursor: pointer;
}

.form-group textarea {
    min-height: 170px;
    resize: vertical;
    line-height: 1.7;
}

/* Helper Text */
.field-help {
    display: block;
    color: #94a3b8;
    font-size: 14px;
    margin-top: 8px;
}

/* Description Counter */
.description-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.char-counter {
    color: #94a3b8;
    font-size: 14px;
    white-space: nowrap;
}

/* Information Box */
.complaint-info {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    background: #eff6ff;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    padding: 14px 16px;
    margin: 27px 0;
}

.complaint-info i {
    color: #2563eb;
    font-size: 18px;
    margin-top: 1px;
}

.complaint-info p {
    color: #475569;
    font-size: 14px;
    line-height: 1.7;
    margin: 0;
}

/* Alerts */
.alert {
    padding: 14px 16px;
    border-radius: 9px;
    margin-bottom: 24px;
    font-size: 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.alert-success {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.alert-error {
    background: #fef2f2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

/* Form Actions */
.form-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    border-top: 1px solid #edf0f5;
    padding-top: 24px;
    margin-top: 10px;
}

.submit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    border: none;
    padding: 15px 26px;
    border-radius: 9px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.submit-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.25);
}

.submit-btn:active {
    transform: translateY(0);
}

.back-link {
    color: #64748b;
    text-decoration: none;
    font-size: 15px;
    font-weight: 500;
    transition: color 0.2s ease;
}

.back-link:hover {
    color: #2563eb;
}

/* Responsive */
@media (max-width: 600px) {
    .complaint-page {
        margin: 25px auto;
        padding: 0 14px;
    }

    .complaint-container {
        padding: 25px 20px;
        border-radius: 14px;
    }

    .complaint-header h2 {
        font-size: 23px;
    }

    .form-actions {
        flex-direction: column-reverse;
        align-items: stretch;
    }

    .submit-btn {
        width: 100%;
    }

    .back-link {
        text-align: center;
    }
}
</style>

<div class="complaint-page">
    <div class="complaint-container">

        <div class="complaint-header">
            <div class="complaint-icon">
                <i class="bi bi-chat-square-text"></i>
            </div>

            <h2>Submit a Complaint</h2>

            <p>
                Tell us about your issue. Our support team will review
                your complaint and get back to you.
            </p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <div>
                    <?= htmlspecialchars($success) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="bi bi-exclamation-circle-fill"></i>
                <div>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>

            <!-- Complaint Category -->
            <div class="form-group">
                <label for="category">
                    Complaint Category
                    <span class="label-required">*</span>
                </label>

                <select name="category" id="category" required>
                    <option value="">Select a category</option>

                    <?php foreach ($categories as $item): ?>
                        <option
                            value="<?= htmlspecialchars($item) ?>"
                            <?= (($_POST['category'] ?? '') === $item) ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($item) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small class="field-help">
                    Choose the category that best describes your issue.
                </small>
            </div>

            <!-- Related Appointment -->
            <div class="form-group">
                <label for="appointment_id">
                    Related Appointment
                    <span class="label-optional">(Optional)</span>
                </label>

                <select name="appointment_id" id="appointment_id">
                    <option value="">No appointment selected</option>

                    <?php foreach ($appointments as $appointment): ?>
                        <option
                            value="<?= (int) $appointment['appointment_id'] ?>"
                            <?= (($_POST['appointment_id'] ?? '') == $appointment['appointment_id']) ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars(
                                $appointment['appointment_ref']
                                . ' - '
                                . $appointment['appointment_date']
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small class="field-help">
                    Select an appointment if your complaint is related to one.
                </small>
            </div>

            <!-- Subject -->
            <div class="form-group">
                <label for="subject">
                    Subject
                    <span class="label-required">*</span>
                </label>

                <input
                    type="text"
                    id="subject"
                    name="subject"
                    maxlength="200"
                    minlength="3"
                    value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                    placeholder="Enter complaint subject"
                    required
                >

                <small class="field-help">
                    Write a short title that describes your complaint.
                </small>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label for="description">
                    Description
                    <span class="label-required">*</span>
                </label>

                <textarea
                    id="description"
                    name="description"
                    minlength="10"
                    placeholder="Describe your issue in detail..."
                    required
                ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

                <div class="description-footer">
                    <small class="field-help">
                        Please provide at least 10 characters.
                    </small>

                    <span class="char-counter" id="charCounter">
                        0 characters
                    </span>
                </div>
            </div>

            <!-- Information -->
            <div class="complaint-info">
                <i class="bi bi-info-circle-fill"></i>
                <p>
                    Your complaint will be reviewed by our support team.
                    You can track its status and view the response from
                    the My Complaints page.
                </p>
            </div>

            <!-- Actions -->
            <div class="form-actions">
                <a href="my-complaints.php" class="back-link">
                    <i class="bi bi-arrow-left me-1"></i>
                    My Complaints
                </a>

                <button type="submit" class="submit-btn">
                    <i class="bi bi-send-fill"></i>
                    Submit Complaint
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const description = document.getElementById('description');
const charCounter = document.getElementById('charCounter');

function updateCharacterCount() {
    const count = description.value.length;
    charCounter.textContent = count + ' characters';
}

description.addEventListener('input', updateCharacterCount);
updateCharacterCount();
</script>

<?php require '../includes/footer.php'; ?>