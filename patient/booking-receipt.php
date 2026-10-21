<?php
require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$appointmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$appointmentId) {
    die('Invalid appointment ID.');
}

// Get patient ID from MongoDB
$patientDoc = $mongoDb->selectCollection('patients')->findOne([
    'user_id' => (int) $_SESSION['user_id']
]);

$patient = $patientDoc ? $patientDoc->getArrayCopy() : null;
$patientId = $patient['patient_id'] ?? null;

if (!$patientId) {
    die('Patient not found.');
}

$patientId = (int) $patientId;

// Get appointment and payment details from MongoDB
$appointmentsCollection = $mongoDb->selectCollection('appointments');
$usersCollection = $mongoDb->selectCollection('users');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$branchesCollection = $mongoDb->selectCollection('branches');
$paymentsCollection = $mongoDb->selectCollection('payments');

$appointmentDoc = $appointmentsCollection->findOne([
    'appointment_id' => (int) $appointmentId,
    'patient_id' => $patientId
]);

$booking = null;

if ($appointmentDoc) {
    $appointment = $appointmentDoc->getArrayCopy();

    // Patient information
    $patientUserDoc = $usersCollection->findOne([
        'user_id' => (int) ($patient['user_id'] ?? 0)
    ]);
    $patientUser = $patientUserDoc
        ? $patientUserDoc->getArrayCopy()
        : [];

    // Doctor information
    $doctorDoc = $doctorsCollection->findOne([
        'doctor_id' => (int) ($appointment['doctor_id'] ?? 0)
    ]);

    $doctor = $doctorDoc ? $doctorDoc->getArrayCopy() : [];
    $doctorUserDoc = null;

    if (!empty($doctor['user_id'])) {
        $doctorUserDoc = $usersCollection->findOne([
            'user_id' => (int) $doctor['user_id']
        ]);
    }

    $doctorUser = $doctorUserDoc
        ? $doctorUserDoc->getArrayCopy()
        : [];

    // Branch information
    $branchDoc = null;

    if (isset($appointment['branch_id'])) {
        $branchDoc = $branchesCollection->findOne([
            'branch_id' => (int) $appointment['branch_id']
        ]);
    }

    $branch = $branchDoc ? $branchDoc->getArrayCopy() : [];

    // Payment information
    $paymentDoc = $paymentsCollection->findOne([
        'appointment_id' => (int) $appointmentId
    ]);

    $payment = $paymentDoc ? $paymentDoc->getArrayCopy() : [];

    $booking = [
        'appointment_id' => $appointment['appointment_id'] ?? $appointmentId,
        'appointment_ref' => $appointment['appointment_ref'] ?? '',
        'appointment_date' => $appointment['appointment_date'] ?? '',
        'start_time' => $appointment['start_time'] ?? '',
        'status' => $appointment['status'] ?? 'Unknown',
        'patient_name' => $patientUser['name'] ?? 'N/A',
        'doctor_name' => $doctorUser['name'] ?? 'N/A',
        'payment_method' => $payment['payment_method'] ?? null,
        'amount' => $payment['amount'] ?? 0,
        'payment_status' => $payment['payment_status'] ?? 'Pending',
        'transaction_ref' => $payment['transaction_ref'] ?? null,
        'branch_name' => $branch['branch_name'] ?? 'N/A'
    ];
}

if (!$booking) {
    die('Appointment not found.');
}

// PDF text helper
function pdfText($text) {
    $text = (string) $text;
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text);

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

// Draw text
function drawText($x, $y, $text, $size = 10, $font = 'F1',
                  $color = '0.15 0.20 0.30') {
    return "BT /$font $size Tf $color rg "
        . "1 0 0 1 $x $y Tm "
        . "(" . pdfText($text) . ") Tj ET\n";
}

// Draw rectangle
function drawRect($x, $y, $w, $h, $color) {
    return "q $color rg $x $y $w $h re f Q\n";
}

// Draw horizontal line
function drawLine($x1, $y, $x2, $color = '0.86 0.89 0.93') {
    return "q $color RG 0.7 w $x1 $y m $x2 $y l S Q\n";
}

$reference = $booking['appointment_ref']
    ?: $booking['appointment_id'];

$date = date(
    'd M Y',
    strtotime($booking['appointment_date'])
);

$time = date(
    'h:i A',
    strtotime($booking['start_time'])
);

$amount = number_format((float)($booking['amount'] ?? 0), 2);

$paymentStatus = $booking['payment_status'] ?? 'Pending';

// Start PDF content
$content = "";

// Page background
$content .= drawRect(0, 0, 595, 842, '0.96 0.97 0.99');

// Header
$content .= drawRect(0, 742, 595, 100, '0.10 0.20 0.38');
$content .= drawRect(0, 738, 595, 4, '0.12 0.48 0.95');

// Brand and title
$content .= drawText(45, 805, 'MediBook', 22, 'F2', '1 1 1');
$content .= drawText(45, 784, 'DOCTOR APPOINTMENT SYSTEM', 9, 'F1', '0.82 0.88 0.96');
$content .= drawText(420, 804, 'OFFICIAL RECEIPT', 10, 'F2', '1 1 1');

// Main white card
$content .= drawRect(35, 90, 525, 625, '1 1 1');

// Receipt heading
$content .= drawText(60, 678, 'Appointment Receipt', 20, 'F2', '0.10 0.20 0.38');
$content .= drawText(60, 657, 'Your appointment and payment details', 9, 'F1', '0.45 0.50 0.58');

// Reference
$content .= drawText(60, 620, 'APPOINTMENT REFERENCE', 8, 'F2', '0.45 0.50 0.58');
$content .= drawText(60, 598, $reference, 13, 'F2', '0.10 0.20 0.38');

// Appointment status badge
$content .= drawRect(430, 590, 100, 27, '0.91 0.96 1');
$content .= drawText(441, 600, strtoupper($booking['status']), 8, 'F2', '0.10 0.38 0.78');

$content .= drawLine(60, 576, 535);

// Patient section
$content .= drawText(60, 551, 'PATIENT INFORMATION', 10, 'F2', '0.10 0.20 0.38');
$content .= drawText(60, 526, 'Patient Name', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 526, $booking['patient_name'], 10, 'F2', '0.15 0.20 0.30');

$content .= drawLine(60, 506, 535);

// Appointment section
$content .= drawText(60, 481, 'APPOINTMENT DETAILS', 10, 'F2', '0.10 0.20 0.38');

$content .= drawText(60, 453, 'Doctor', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 453, $booking['doctor_name'], 10, 'F2');

$content .= drawText(60, 427, 'Branch', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 427, $booking['branch_name'] ?? 'N/A', 10, 'F2');

$content .= drawText(60, 401, 'Appointment Date', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 401, $date, 10, 'F2');

$content .= drawText(60, 375, 'Appointment Time', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 375, $time, 10, 'F2');

$content .= drawText(60, 349, 'Appointment Status', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 349, $booking['status'], 10, 'F2');

$content .= drawLine(60, 328, 535);

// Payment section
$content .= drawText(60, 303, 'PAYMENT DETAILS', 10, 'F2', '0.10 0.20 0.38');

$content .= drawText(60, 276, 'Payment Method', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 276, $booking['payment_method'] ?? 'N/A', 10, 'F2');

$content .= drawText(60, 250, 'Payment Status', 9, 'F1', '0.45 0.50 0.58');

$statusColor = $paymentStatus === 'Paid'
    ? '0.05 0.55 0.30'
    : '0.75 0.35 0.05';

$content .= drawText(340, 250, $paymentStatus, 10, 'F2', $statusColor);

$content .= drawText(60, 224, 'Transaction Reference', 9, 'F1', '0.45 0.50 0.58');
$content .= drawText(340, 224, $booking['transaction_ref'] ?? 'N/A', 9, 'F2');

// Amount box
$content .= drawRect(60, 151, 475, 49, '0.93 0.96 1');
$content .= drawText(78, 179, 'TOTAL AMOUNT', 9, 'F2', '0.25 0.35 0.50');
$content .= drawText(390, 170, 'BDT ' . $amount, 17, 'F2', '0.10 0.30 0.75');

// Footer inside card
$content .= drawText(60, 125, 'This is a demo payment confirmation.', 8, 'F1', '0.40 0.45 0.52');
$content .= drawText(60, 111, 'No real money was charged.', 8, 'F1', '0.40 0.45 0.52');

// Bottom footer
$content .= drawText(60, 65, 'MediBook | Appointment Receipt', 8, 'F1', '0.45 0.50 0.58');
$content .= drawText(425, 65, 'Thank you!', 9, 'F2', '0.10 0.30 0.75');

// PDF objects
$objects = [];

$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
$objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
$objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

// Build PDF
$pdf = "%PDF-1.4\n";
$offsets = [0];

foreach ($objects as $i => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
}

$xrefPosition = strlen($pdf);

$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
$pdf .= "0000000000 65535 f \n";

for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
}

$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
$pdf .= "startxref\n" . $xrefPosition . "\n%%EOF";

// Download PDF directly
header('Content-Type: application/pdf');
header(
    'Content-Disposition: attachment; filename="MediBook_Receipt_' .
    $appointmentId . '.pdf"'
);
header('Content-Length: ' . strlen($pdf));

echo $pdf;
exit;