<?php

$page_title = 'Complaint Management';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('admin');

$complaintsCollection = $mongoDb->selectCollection('complaints');
$patientsCollection = $mongoDb->selectCollection('patients');
$usersCollection = $mongoDb->selectCollection('users');
$appointmentsCollection = $mongoDb->selectCollection('appointments');

$statuses = [
    'Open',
    'In Progress',
    'Waiting for Patient',
    'Resolved',
    'Closed',
];

$success = '';
$error = '';

/*
|--------------------------------------------------------------------------
| Update Complaint
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $complaint_id = (int) ($_POST['complaint_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $admin_reply = trim($_POST['admin_reply'] ?? '');

    if ($complaint_id <= 0) {
        $error = 'Invalid complaint.';
    } elseif (!in_array($status, $statuses, true)) {
        $error = 'Invalid complaint status.';
    } else {
        $complaintDocument = $complaintsCollection->findOne([
            'complaint_id' => $complaint_id,
        ]);

        if ($complaintDocument === null) {
            $error = 'Complaint not found.';
        } else {
            $complaint = $complaintDocument->getArrayCopy();

            $patientDocument = $patientsCollection->findOne([
                'patient_id' => (int) ($complaint['patient_id'] ?? 0),
            ]);

            if ($patientDocument === null) {
                $error = 'Patient associated with this complaint was not found.';
            } else {
                $patient = $patientDocument->getArrayCopy();

                $updateResult = $complaintsCollection->updateOne(
                    [
                        'complaint_id' => $complaint_id,
                    ],
                    [
                        '$set' => [
                            'status' => $status,
                            'admin_reply' => $admin_reply !== ''
                                ? $admin_reply
                                : null,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ],
                    ]
                );

                if ($updateResult->getMatchedCount() === 0) {
                    $error = 'Complaint could not be updated.';
                } else {
                    $admin_id = (int) $_SESSION['user_id'];

                    create_notification(
                        (int) ($patient['user_id'] ?? 0),
                        'Complaint',
                        'Complaint Updated',
                        'Your complaint ' .
                        ($complaint['complaint_ref'] ?? '') .
                        " status is now: {$status}."
                    );

                    audit(
                        $admin_id,
                        'Updated complaint',
                        'complaints',
                        $complaint_id
                    );

                    $success = 'Complaint updated successfully.';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch All Complaints
|--------------------------------------------------------------------------
*/

$complaints = [];

$complaintCursor = $complaintsCollection->find([]);

foreach ($complaintCursor as $complaintDocument) {
    $complaint = $complaintDocument->getArrayCopy();

    $patientDocument = $patientsCollection->findOne([
        'patient_id' => (int) ($complaint['patient_id'] ?? 0),
    ]);

    $patient = $patientDocument !== null
        ? $patientDocument->getArrayCopy()
        : [];

    $userDocument = null;

    if (isset($patient['user_id'])) {
        $userDocument = $usersCollection->findOne([
            'user_id' => (int) $patient['user_id'],
        ]);
    }

    $user = $userDocument !== null
        ? $userDocument->getArrayCopy()
        : [];

    $complaint['patient_name'] = $user['name'] ?? 'Unknown Patient';
    $complaint['patient_email'] = $user['email'] ?? '';

    $appointmentDocument = null;

    if (!empty($complaint['appointment_id'])) {
        $appointmentDocument = $appointmentsCollection->findOne([
            'appointment_id' => (int) $complaint['appointment_id'],
        ]);
    }

    $appointment = $appointmentDocument !== null
        ? $appointmentDocument->getArrayCopy()
        : [];

    $complaint['appointment_ref'] =
        $appointment['appointment_ref'] ?? null;

    foreach (['created_at', 'updated_at'] as $dateField) {
        $dateValue = $complaint[$dateField] ?? '';

        if ($dateValue instanceof MongoDB\BSON\UTCDateTime) {
            $complaint[$dateField] = $dateValue
                ->toDateTime()
                ->format('Y-m-d H:i:s');
        } elseif ($dateValue instanceof DateTimeInterface) {
            $complaint[$dateField] = $dateValue->format('Y-m-d H:i:s');
        } else {
            $complaint[$dateField] = is_scalar($dateValue)
                ? (string) $dateValue
                : '';
        }
    }

    $complaints[] = $complaint;
}

$statusPriority = [
    'Open' => 1,
    'In Progress' => 2,
    'Waiting for Patient' => 3,
    'Resolved' => 4,
    'Closed' => 5,
];

usort($complaints, function ($a, $b) use ($statusPriority) {
    $priorityA = $statusPriority[$a['status'] ?? ''] ?? 99;
    $priorityB = $statusPriority[$b['status'] ?? ''] ?? 99;

    if ($priorityA !== $priorityB) {
        return $priorityA <=> $priorityB;
    }

    return strcmp(
        (string) ($b['created_at'] ?? ''),
        (string) ($a['created_at'] ?? '')
    );
});

require __DIR__ . '/../includes/header.php';
?>

<style>
    .complaints-page {
        max-width: 1150px;
        margin: 20px auto;
    }

    .page-heading {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 28px;
    }

    .heading-icon {
        width: 58px;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #eaf1ff;
        color: #2563eb;
        border-radius: 16px;
        font-size: 27px;
        flex-shrink: 0;
    }

    .page-heading h2 {
        font-size: 28px;
        font-weight: 700;
        margin: 0 0 5px;
        color: #172033;
    }

    .page-description {
        margin: 0;
        color: #64748b;
        font-size: 15px;
    }

    .complaint-card {
        background: #fff;
        border: 1px solid #e8edf4;
        border-radius: 16px;
        padding: 25px;
        margin-bottom: 22px;
        box-shadow: 0 5px 20px rgba(15, 23, 42, 0.05);
        transition: box-shadow 0.2s ease, transform 0.2s ease;
    }

    .complaint-card:hover {
        box-shadow: 0 9px 26px rgba(15, 23, 42, 0.09);
        transform: translateY(-2px);
    }

    .complaint-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 15px;
        margin-bottom: 20px;
    }

    .complaint-ref {
        color: #2563eb;
        font-weight: 700;
        font-size: 15px;
        margin-bottom: 5px;
    }

    .submitted-date {
        color: #64748b;
        font-size: 13px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 13px;
        border-radius: 30px;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
    }

    .status-progress {
        background: #fef3c7;
        color: #92400e;
    }

    .status-waiting {
        background: #ffedd5;
        color: #9a3412;
    }

    .status-resolved {
        background: #dcfce7;
        color: #166534;
    }

    .status-closed {
        background: #e5e7eb;
        color: #374151;
    }

    .complaint-subject {
        color: #172033;
        font-size: 21px;
        font-weight: 700;
        margin-bottom: 20px;
        overflow-wrap: anywhere;
    }

    .complaint-info {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px 25px;
        padding: 18px;
        background: #f8fafc;
        border: 1px solid #eef2f7;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .info-item {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        min-width: 0;
        color: #475569;
        font-size: 14px;
        overflow-wrap: anywhere;
    }

    .info-item i {
        color: #64748b;
        font-size: 16px;
        margin-top: 1px;
    }

    .info-item strong {
        color: #334155;
        font-weight: 600;
    }

    .description-box {
        padding: 18px;
        background: #f8fafc;
        border-left: 4px solid #3b82f6;
        border-radius: 8px;
        margin-bottom: 22px;
        overflow-wrap: anywhere;
    }

    .section-label {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #334155;
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 9px;
    }

    .description-text {
        color: #475569;
        font-size: 15px;
        line-height: 1.7;
        margin: 0;
        white-space: pre-wrap;
    }

    .reply-box {
        padding: 18px;
        background: #f8fafc;
        border: 1px solid #e8edf4;
        border-radius: 12px;
        margin-bottom: 22px;
    }

    .reply-text {
        color: #475569;
        font-size: 14px;
        line-height: 1.7;
        margin: 0;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .admin-form {
        border-top: 1px solid #e8edf4;
        padding-top: 22px;
    }

    .form-section-title {
        display: flex;
        align-items: center;
        gap: 9px;
        color: #172033;
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 18px;
    }

    .admin-form label {
        display: block;
        color: #334155;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .admin-form select,
    .admin-form textarea {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 9px;
        background: #fff;
        color: #334155;
        font-size: 15px;
        box-sizing: border-box;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .admin-form select:focus,
    .admin-form textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
    }

    .admin-form textarea {
        min-height: 115px;
        resize: vertical;
        line-height: 1.6;
    }

    .update-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 17px;
        padding: 11px 20px;
        border: none;
        border-radius: 9px;
        background: #2563eb;
        color: #fff;
        cursor: pointer;
        font-size: 15px;
        font-weight: 600;
        transition: background 0.2s ease;
    }

    .update-btn:hover {
        background: #1d4ed8;
    }

    .alert {
        padding: 13px 16px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-size: 15px;
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    .empty-state {
        text-align: center;
        background: #fff;
        border: 1px dashed #cbd5e1;
        border-radius: 16px;
        padding: 55px 20px;
        color: #64748b;
    }

    .empty-state i {
        display: block;
        font-size: 42px;
        color: #94a3b8;
        margin-bottom: 14px;
    }

    .empty-state h3 {
        color: #334155;
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 7px;
    }

    @media (max-width: 600px) {
        .complaints-page {
            margin: 5px auto;
        }

        .page-heading h2 {
            font-size: 23px;
        }

        .heading-icon {
            width: 48px;
            height: 48px;
            font-size: 23px;
        }

        .complaint-card {
            padding: 17px;
            border-radius: 12px;
        }

        .complaint-info {
            grid-template-columns: 1fr;
            gap: 12px;
            padding: 14px;
        }

        .complaint-subject {
            font-size: 19px;
        }

        .complaint-header {
            gap: 10px;
        }

        .status-badge {
            font-size: 12px;
            padding: 6px 10px;
        }

        .update-btn {
            width: 100%;
        }
    }
</style>

<div class="complaints-page">

    <div class="page-heading">
        <div class="heading-icon">
            <i class="bi bi-chat-square-text"></i>
        </div>

        <div>
            <h2>Complaint Management</h2>
            <p class="page-description">
                Review patient complaints and provide replies.
            </p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="bi bi-exclamation-circle me-2"></i>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (empty($complaints)): ?>

        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h3>No Complaints Found</h3>
            <p class="mb-0">
                There are currently no patient complaints.
            </p>
        </div>

    <?php else: ?>

        <?php foreach ($complaints as $complaint): ?>

            <?php
            $status_class = match ($complaint['status']) {
                'In Progress' => 'status-progress',
                'Waiting for Patient' => 'status-waiting',
                'Resolved' => 'status-resolved',
                'Closed' => 'status-closed',
                default => ''
            };
            ?>

            <div class="complaint-card">

                <div class="complaint-header">
                    <div>
                        <div class="complaint-ref">
                            <i class="bi bi-ticket-perforated me-1"></i>
                            <?= htmlspecialchars($complaint['complaint_ref'], ENT_QUOTES, 'UTF-8') ?>
                        </div>

                        <div class="submitted-date">
                            <i class="bi bi-clock me-1"></i>
                            Submitted:
                            <?= htmlspecialchars($complaint['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>

                    <span class="status-badge <?= $status_class ?>">
                        <i class="bi bi-circle-fill" style="font-size: 7px;"></i>
                        <?= htmlspecialchars($complaint['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <h3 class="complaint-subject">
                    <?= htmlspecialchars($complaint['subject'], ENT_QUOTES, 'UTF-8') ?>
                </h3>

                <div class="complaint-info">

                    <div class="info-item">
                        <i class="bi bi-person"></i>
                        <span>
                            <strong>Patient:</strong>
                            <?= htmlspecialchars($complaint['patient_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <i class="bi bi-envelope"></i>
                        <span>
                            <strong>Email:</strong>
                            <?= htmlspecialchars($complaint['patient_email'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <i class="bi bi-tag"></i>
                        <span>
                            <strong>Category:</strong>
                            <?= htmlspecialchars($complaint['category'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <i class="bi bi-flag"></i>
                        <span>
                            <strong>Priority:</strong>
                            <?= htmlspecialchars($complaint['priority'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <i class="bi bi-calendar-check"></i>
                        <span>
                            <strong>Appointment:</strong>
                            <?= htmlspecialchars($complaint['appointment_ref'] ?? 'Not linked', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>
                            <strong>Last Updated:</strong>
                            <?= htmlspecialchars($complaint['updated_at'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                </div>

                <div class="description-box">
                    <div class="section-label">
                        <i class="bi bi-file-text"></i>
                        Complaint Description
                    </div>

                    <p class="description-text"><?= htmlspecialchars($complaint['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>

                <?php if (!empty($complaint['admin_reply'])): ?>
                    <div class="reply-box">
                        <div class="section-label">
                            <i class="bi bi-reply"></i>
                            Previous Admin Reply
                        </div>

                        <p class="reply-text"><?= htmlspecialchars($complaint['admin_reply'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endif; ?>

                <form method="POST" class="admin-form">
                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="complaint_id"
                        value="<?= (int) $complaint['complaint_id'] ?>"
                    >

                    <div class="form-section-title">
                        <i class="bi bi-pencil-square"></i>
                        Update Complaint
                    </div>

                    <div class="mb-3">
                        <label for="status-<?= (int) $complaint['complaint_id'] ?>">
                            Update Status
                        </label>

                        <select
                            name="status"
                            id="status-<?= (int) $complaint['complaint_id'] ?>"
                            required
                        >
                            <?php foreach ($statuses as $status): ?>
                                <option
                                    value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $complaint['status'] === $status ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="reply-<?= (int) $complaint['complaint_id'] ?>">
                            Admin Reply
                        </label>

                        <textarea
                            name="admin_reply"
                            id="reply-<?= (int) $complaint['complaint_id'] ?>"
                            placeholder="Write a reply to the patient..."
                        ><?= htmlspecialchars($complaint['admin_reply'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>

                    <button type="submit" class="update-btn">
                        <i class="bi bi-check2-circle"></i>
                        Update Complaint
                    </button>
                </form>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php require '../includes/footer.php'; ?>