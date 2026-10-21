<?php
require_once '../config/mongodb.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

require_role('patient');

$patient_id = get_patient_id((int) $_SESSION['user_id']);

/*
|--------------------------------------------------------------------------
| My Complaints - MongoDB
|--------------------------------------------------------------------------
*/

$complaints = [];

$complaintCursor = $mongoDb->complaints->find(
    ['patient_id' => (int) $patient_id],
    ['sort' => ['created_at' => -1]]
);

foreach ($complaintCursor as $complaint) {
    $appointmentRef = null;
    $appointmentId = $complaint->appointment_id ?? null;

    // Find linked appointment, if available
    if ($appointmentId !== null && $appointmentId !== '') {
        $appointment = $mongoDb->appointments->findOne([
            'appointment_id' => (int) $appointmentId
        ]);

        if ($appointment) {
            $appointmentRef = $appointment->appointment_ref ?? null;
        }
    }

    $createdAt = $complaint->created_at ?? '';
    $updatedAt = $complaint->updated_at ?? '';

    // Convert MongoDB UTCDateTime values to readable strings
    if ($createdAt instanceof MongoDB\BSON\UTCDateTime) {
        $createdAt = $createdAt->toDateTime()
            ->format('Y-m-d H:i:s');
    }

    if ($updatedAt instanceof MongoDB\BSON\UTCDateTime) {
        $updatedAt = $updatedAt->toDateTime()
            ->format('Y-m-d H:i:s');
    }

    $complaints[] = [
        'complaint_id' => (string) $complaint->_id,
        'complaint_ref' => (string) ($complaint->complaint_ref ?? ''),
        'category' => (string) ($complaint->category ?? ''),
        'subject' => (string) ($complaint->subject ?? ''),
        'description' => (string) ($complaint->description ?? ''),
        'priority' => (string) ($complaint->priority ?? 'Normal'),
        'status' => (string) ($complaint->status ?? 'Open'),
        'admin_reply' => (string) ($complaint->admin_reply ?? ''),
        'created_at' => (string) $createdAt,
        'updated_at' => (string) $updatedAt,
        'appointment_ref' => $appointmentRef
    ];
}

$status_classes = [
    'Open' => 'open',
    'In Progress' => 'progress',
    'Waiting for Patient' => 'waiting',
    'Resolved' => 'resolved',
    'Closed' => 'closed'
];

$asset_prefix = '../';
$page_title = 'My Complaints';

require '../includes/header.php';
?>

<style>
/* Page */
.complaints-page {
    max-width: 1050px;
    margin: 45px auto;
    padding: 0 22px;
}

/* Page Header */
.complaints-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 28px;
}

.header-title {
    display: flex;
    align-items: center;
    gap: 14px;
}

.header-icon {
    width: 52px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2563eb, #38bdf8);
    color: #fff;
    border-radius: 13px;
    font-size: 24px;
    box-shadow: 0 5px 14px rgba(37, 99, 235, 0.18);
}

.header-title h2 {
    margin: 0 0 5px;
    color: #172554;
    font-size: 28px;
    font-weight: 700;
}

.header-title p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
}

/* New Complaint Button */
.new-complaint-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    padding: 13px 19px;
    border-radius: 9px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: 0.2s ease;
    white-space: nowrap;
}

.new-complaint-btn:hover {
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(37, 99, 235, 0.22);
}

/* Complaint Card */
.complaint-card {
    background: #fff;
    border: 1px solid #e8edf5;
    border-radius: 15px;
    padding: 25px 27px;
    margin-bottom: 22px;
    box-shadow: 0 5px 22px rgba(15, 23, 42, 0.055);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.complaint-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 9px 28px rgba(15, 23, 42, 0.09);
}

/* Card Top */
.complaint-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 19px;
}

.complaint-ref {
    color: #2563eb;
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 6px;
}

.submitted-date {
    color: #94a3b8;
    font-size: 13px;
}

/* Status Badge */
.status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 13px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.status::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}

.open {
    background: #dbeafe;
    color: #1d4ed8;
}

.progress {
    background: #fef3c7;
    color: #92400e;
}

.waiting {
    background: #ffedd5;
    color: #9a3412;
}

.resolved {
    background: #dcfce7;
    color: #166534;
}

.closed {
    background: #e5e7eb;
    color: #374151;
}

/* Complaint Subject */
.complaint-subject {
    color: #172554;
    font-size: 21px;
    font-weight: 700;
    margin: 0 0 20px;
    overflow-wrap: anywhere;
}

/* Details Grid */
.complaint-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.info-item {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 13px;
    background: #f8fafc;
    border: 1px solid #edf1f6;
    border-radius: 9px;
    min-width: 0;
}

.info-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
    flex-shrink: 0;
    background: #eaf2ff;
    color: #2563eb;
    border-radius: 8px;
    font-size: 16px;
}

.info-label {
    color: #94a3b8;
    font-size: 12px;
    font-weight: 500;
    margin-bottom: 4px;
}

.info-value {
    color: #334155;
    font-size: 14px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

/* Description */
.description-section {
    padding: 17px;
    background: #f8fafc;
    border: 1px solid #edf1f6;
    border-radius: 10px;
    margin-bottom: 18px;
}

.section-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #334155;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 9px;
}

.section-heading i {
    color: #2563eb;
    font-size: 16px;
}

.description-text {
    color: #475569;
    font-size: 14px;
    line-height: 1.8;
    margin: 0;
    overflow-wrap: anywhere;
}

/* Last Updated */
.last-updated {
    color: #94a3b8;
    font-size: 12px;
    margin-top: 14px;
}

/* Admin Reply */
.admin-reply {
    padding: 17px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-left: 4px solid #16a34a;
    border-radius: 9px;
    margin-top: 20px;
}

.reply-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #166534;
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 9px;
}

.reply-heading i {
    font-size: 17px;
}

.admin-reply p {
    color: #166534;
    font-size: 14px;
    line-height: 1.8;
    margin: 0;
    overflow-wrap: anywhere;
}

/* No Reply */
.no-reply {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 13px 15px;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 9px;
    color: #64748b;
    font-size: 13px;
    margin-top: 18px;
}

.no-reply i {
    color: #94a3b8;
    font-size: 17px;
}

/* Empty State */
.no-complaints {
    background: #fff;
    border: 1px solid #e8edf5;
    border-radius: 15px;
    padding: 55px 25px;
    text-align: center;
    box-shadow: 0 5px 22px rgba(15, 23, 42, 0.055);
}

.empty-icon {
    width: 70px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 18px;
    background: #eff6ff;
    color: #2563eb;
    border-radius: 50%;
    font-size: 30px;
}

.no-complaints h3 {
    color: #172554;
    font-size: 21px;
    font-weight: 700;
    margin-bottom: 9px;
}

.no-complaints p {
    color: #64748b;
    font-size: 14px;
    margin-bottom: 22px;
}

/* Responsive */
@media (max-width: 650px) {
    .complaints-page {
        margin: 28px auto;
        padding: 0 14px;
    }

    .complaints-header {
        align-items: stretch;
        flex-direction: column;
    }

    .header-title h2 {
        font-size: 24px;
    }

    .new-complaint-btn {
        width: 100%;
    }

    .complaint-card {
        padding: 20px 17px;
    }

    .complaint-info-grid {
        grid-template-columns: 1fr;
    }

    .complaint-subject {
        font-size: 19px;
    }

    .complaint-top {
        flex-direction: column;
    }

    .status {
        align-self: flex-start;
    }
}
</style>

<div class="complaints-page">

    <div class="complaints-header">
        <div class="header-title">
            <div class="header-icon">
                <i class="bi bi-chat-square-text"></i>
            </div>

            <div>
                <h2>My Complaints</h2>
                <p>Track your complaints and view support responses.</p>
            </div>
        </div>

        <a href="complaints.php" class="new-complaint-btn">
            <i class="bi bi-plus-lg"></i>
            New Complaint
        </a>
    </div>

    <?php if (empty($complaints)): ?>

        <div class="no-complaints">
            <div class="empty-icon">
                <i class="bi bi-chat-square-text"></i>
            </div>

            <h3>No Complaints Found</h3>

            <p>
                You have not submitted any complaints yet.
            </p>

            <a href="complaints.php" class="new-complaint-btn">
                <i class="bi bi-plus-lg"></i>
                Submit Your First Complaint
            </a>
        </div>

    <?php else: ?>

        <?php foreach ($complaints as $complaint): ?>
            <?php
                $status = $complaint['status'];
                $status_class = $status_classes[$status] ?? 'open';
            ?>

            <div class="complaint-card">

                <div class="complaint-top">
                    <div>
                        <div class="complaint-ref">
                            <i class="bi bi-ticket-perforated me-1"></i>
                            <?= htmlspecialchars($complaint['complaint_ref']) ?>
                        </div>

                        <div class="submitted-date">
                            <i class="bi bi-calendar3 me-1"></i>
                            Submitted:
                            <?= htmlspecialchars($complaint['created_at']) ?>
                        </div>
                    </div>

                    <span class="status <?= htmlspecialchars($status_class) ?>">
                        <?= htmlspecialchars($status) ?>
                    </span>
                </div>

                <h3 class="complaint-subject">
                    <?= htmlspecialchars($complaint['subject']) ?>
                </h3>

                <div class="complaint-info-grid">

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-tag"></i>
                        </div>

                        <div>
                            <div class="info-label">Category</div>
                            <div class="info-value">
                                <?= htmlspecialchars($complaint['category']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-flag"></i>
                        </div>

                        <div>
                            <div class="info-label">Priority</div>
                            <div class="info-value">
                                <?= htmlspecialchars($complaint['priority']) ?>
                            </div>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>

                        <div>
                            <div class="info-label">Appointment</div>
                            <div class="info-value">
                                <?= htmlspecialchars(
                                    $complaint['appointment_ref'] ?? 'Not linked'
                                ) ?>
                            </div>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icon">
                            <i class="bi bi-clock-history"></i>
                        </div>

                        <div>
                            <div class="info-label">Last Updated</div>
                            <div class="info-value">
                                <?= htmlspecialchars($complaint['updated_at']) ?>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="description-section">
                    <div class="section-heading">
                        <i class="bi bi-card-text"></i>
                        Complaint Description
                    </div>

                    <p class="description-text">
                        <?= nl2br(htmlspecialchars($complaint['description'])) ?>
                    </p>
                </div>

                <?php if (!empty($complaint['admin_reply'])): ?>

                    <div class="admin-reply">
                        <div class="reply-heading">
                            <i class="bi bi-chat-left-text-fill"></i>
                            Admin Reply
                        </div>

                        <p>
                            <?= nl2br(htmlspecialchars($complaint['admin_reply'])) ?>
                        </p>
                    </div>

                <?php else: ?>

                    <div class="no-reply">
                        <i class="bi bi-hourglass-split"></i>
                        Admin has not replied yet.
                    </div>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php require '../includes/footer.php'; ?>