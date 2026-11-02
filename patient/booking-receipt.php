
<?php
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =====================================================
   1. AUTHENTICATION
===================================================== */

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$appointmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$appointmentId || $appointmentId <= 0) {
    http_response_code(400);
    exit('Invalid appointment ID.');
}

/* =====================================================
   2. DATABASE COLLECTIONS
===================================================== */

$patientsCollection = $mongoDb->selectCollection('patients');
$appointmentsCollection = $mongoDb->selectCollection('appointments');
$usersCollection = $mongoDb->selectCollection('users');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');
$paymentsCollection = $mongoDb->selectCollection('payments');

/* =====================================================
   3. GET PATIENT
===================================================== */

$patientDoc = $patientsCollection->findOne([
    'user_id' => (int) $_SESSION['user_id']
]);

$patient = $patientDoc ? $patientDoc->getArrayCopy() : [];

$patientId = (int) ($patient['patient_id'] ?? 0);

if (!$patientId) {
    http_response_code(404);
    exit('Patient not found.');
}

/* =====================================================
   4. GET APPOINTMENT
===================================================== */

$appointmentDoc = $appointmentsCollection->findOne([
    'appointment_id' => (int) $appointmentId,
    'patient_id' => $patientId
]);

if (!$appointmentDoc) {
    http_response_code(404);
    exit('Appointment not found.');
}

$appointment = $appointmentDoc->getArrayCopy();

/* =====================================================
   5. GET PATIENT USER
===================================================== */

$patientUserDoc = $usersCollection->findOne([
    'user_id' => (int) $_SESSION['user_id']
]);

$patientUser = $patientUserDoc
    ? $patientUserDoc->getArrayCopy()
    : [];

/* =====================================================
   6. GET DOCTOR
===================================================== */

$doctorDoc = $doctorsCollection->findOne([
    'doctor_id' => (int) ($appointment['doctor_id'] ?? 0)
]);

$doctor = $doctorDoc ? $doctorDoc->getArrayCopy() : [];

$doctorUser = [];

if (!empty($doctor['user_id'])) {
    $doctorUserDoc = $usersCollection->findOne([
        'user_id' => (int) $doctor['user_id']
    ]);

    $doctorUser = $doctorUserDoc
        ? $doctorUserDoc->getArrayCopy()
        : [];
}

/* =====================================================
   7. GET BRANCH
===================================================== */

$branch = [];

if (isset($appointment['branch_id'])) {
    $branchDoc = $branchesCollection->findOne([
        'branch_id' => (int) $appointment['branch_id']
    ]);

    $branch = $branchDoc
        ? $branchDoc->getArrayCopy()
        : [];
}

// Get Hospital / Clinic
$clinicDoc = !empty($branch['clinic_id'])
    ? $clinicsCollection->findOne([
        'clinic_id' => (int) $branch['clinic_id']
    ])
    : null;

$clinic = $clinicDoc
    ? $clinicDoc->getArrayCopy()
    : [];

$hospitalClinicName = $clinic['name'] ?? 'N/A';
$branchName = $branch['branch_name'] ?? 'N/A';

/* =====================================================
   8. GET PAYMENT
===================================================== */

$paymentDoc = $paymentsCollection->findOne([
    'appointment_id' => (int) $appointmentId
]);

$payment = $paymentDoc
    ? $paymentDoc->getArrayCopy()
    : [];

/* =====================================================
   9. HELPER FUNCTIONS
===================================================== */

function receiptValue($array, $keys, $default = 'N/A')
{
    foreach ($keys as $key) {
        if (
            isset($array[$key]) &&
            !is_array($array[$key]) &&
            !is_object($array[$key]) &&
            trim((string) $array[$key]) !== ''
        ) {
            return trim((string) $array[$key]);
        }
    }

    return $default;
}

function receiptDate($value, $format = 'd F Y')
{
    try {
        if ($value instanceof MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()
                ->setTimezone(
                    new DateTimeZone(date_default_timezone_get())
                )
                ->format($format);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTime::createFromInterface($value)
                ->format($format);
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);

            if ($timestamp !== false) {
                return date($format, $timestamp);
            }
        }
    } catch (Throwable $e) {
        // Return fallback.
    }

    return 'N/A';
}

function pdfText($text)
{
    $text = (string) $text;

    if (function_exists('iconv')) {
        $converted = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $text
        );

        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = preg_replace('/[^\x20-\x7E]/', '', $text);

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $text
    );
}

function pdfDrawText(
    $x,
    $y,
    $text,
    $size = 11,
    $font = 'F1',
    $color = '0.08 0.16 0.30'
) {
    return "BT /$font $size Tf $color rg "
        . "1 0 0 1 $x $y Tm "
        . "(" . pdfText($text) . ") Tj ET\n";
}

function pdfRect($x, $y, $w, $h, $color)
{
    return "q $color rg $x $y $w $h re f Q\n";
}

function pdfCircle($cx, $cy, $r, $color)
{
    $k = 0.55228475 * $r;

    return "q $color rg "
        . ($cx + $r) . " $cy m "
        . ($cx + $r) . " " . ($cy + $k) . " "
        . ($cx + $k) . " " . ($cy + $r) . " "
        . "$cx " . ($cy + $r) . " c "
        . ($cx - $k) . " " . ($cy + $r) . " "
        . ($cx - $r) . " " . ($cy + $k) . " "
        . ($cx - $r) . " $cy c "
        . ($cx - $r) . " " . ($cy - $k) . " "
        . ($cx - $k) . " " . ($cy - $r) . " "
        . "$cx " . ($cy - $r) . " c "
        . ($cx + $k) . " " . ($cy - $r) . " "
        . ($cx + $r) . " " . ($cy - $k) . " "
        . ($cx + $r) . " $cy c f Q\n";
}

function pdfLine($x1, $y, $x2, $color = '0.82 0.87 0.93')
{
    return "q $color RG 0.9 w $x1 $y m $x2 $y l S Q\n";
}

function pdfImage($name, $x, $y, $w, $h)
{
    return "q $w 0 0 $h $x $y cm /$name Do Q\n";
}

/* =====================================================
   10. PREPARE RECEIPT DATA
===================================================== */

// Appointment reference: APT-DATE-ID

$rawDate = $appointment['appointment_date'] ?? '';

$referenceDate = '00000000';

if (is_string($rawDate) && trim($rawDate) !== '') {
    $timestamp = strtotime($rawDate);

    if ($timestamp !== false) {
        $referenceDate = date('Ymd', $timestamp);
    }
} elseif (
    $rawDate instanceof MongoDB\BSON\UTCDateTime
) {
    $referenceDate = $rawDate->toDateTime()->format('Ymd');
}

$reference = 'APT-'
    . $referenceDate
    . '-'
    . (int) ($appointment['appointment_id'] ?? $appointmentId);

// Patient information

$patientName = receiptValue(
    $patientUser,
    ['name', 'full_name', 'username']
);

$patientNumber = receiptValue(
    $patient,
    ['contact'],
    'N/A'
);

$patientCode = receiptValue(
    $patient,
    ['patient_ref', 'patient_code'],
    'PAT-' . str_pad(
        (string) $patientId,
        6,
        '0',
        STR_PAD_LEFT
    )
);

// Doctor information

$doctorName = receiptValue(
    $doctorUser,
    ['name', 'full_name', 'username'],
    receiptValue(
        $doctor,
        ['doctor_name', 'name', 'full_name']
    )
);

// Appointment date and time

$appointmentDate = receiptDate(
    $appointment['appointment_date'] ?? '',
    'd F Y'
);

$appointmentTime = 'N/A';

if (!empty($appointment['start_time'])) {
    $parsedTime = strtotime(
        (string) $appointment['start_time']
    );

    $appointmentTime = $parsedTime !== false
        ? date('h:i A', $parsedTime)
        : (string) $appointment['start_time'];
}

$appointmentStatus = receiptValue(
    $appointment,
    ['status'],
    'Unknown'
);

// Payment information

$paymentMethod = receiptValue(
    $payment,
    ['payment_method', 'method'],
    'Cash'
);

$paymentStatus = receiptValue(
    $payment,
    ['payment_status', 'status'],
    'Unpaid'
);

$transactionReference = receiptValue(
    $payment,
    ['transaction_ref', 'transaction_id', 'invoice_id'],
    'N/A'
);

$paymentDateRaw = $payment['payment_date']
    ?? $payment['paid_at']
    ?? $payment['created_at']
    ?? null;

$paymentDate = receiptDate(
    $paymentDateRaw,
    'd F Y, h:i A'
);

$amount = number_format(
    (float) (
        $payment['amount']
        ?? $appointment['consultation_fee']
        ?? 0
    ),
    2
);

/* =====================================================
   11. LOGO - PNG WITH TRANSPARENCY (NO GD REQUIRED)
===================================================== */

$logoPath = __DIR__ . '/../assets/images/medibook-logo.png';

if (!is_file($logoPath)) {
    http_response_code(500);
    exit('MediBook PNG logo not found.');
}

$imageInfo = @getimagesize($logoPath);

if (!$imageInfo || $imageInfo['mime'] !== 'image/png') {
    http_response_code(500);
    exit('Logo must be a valid PNG image.');
}

/* =====================================================
   PNG PDF CACHE - FASTER RECEIPT DOWNLOAD
===================================================== */

$cachePath = $logoPath . '.pdfcache';

$useCache = false;

if (
    is_file($cachePath) &&
    filemtime($cachePath) >= filemtime($logoPath)
) {
    $cachedData = file_get_contents($cachePath);

    if (
        $cachedData !== false &&
        strlen($cachedData) >= 16
    ) {
        $cacheHeader = unpack(
            'Nwidth/Nheight/NlogoLength/NalphaLength',
            substr($cachedData, 0, 16)
        );

        $expectedLength = 16
            + $cacheHeader['logoLength']
            + $cacheHeader['alphaLength'];

        if (
            strlen($cachedData) === $expectedLength &&
            $cacheHeader['width'] > 0 &&
            $cacheHeader['height'] > 0
        ) {
            $width = $cacheHeader['width'];
            $height = $cacheHeader['height'];

            $logoData = substr(
                $cachedData,
                16,
                $cacheHeader['logoLength']
            );

            $logoAlphaData = substr(
                $cachedData,
                16 + $cacheHeader['logoLength'],
                $cacheHeader['alphaLength']
            );

            $useCache = true;
        }
    }
}

if (!$useCache) {

/*
   Decode PNG manually.
   Supports 8-bit RGB and RGBA, non-interlaced PNG.
*/

$pngData = file_get_contents($logoPath);

if ($pngData === false) {
    http_response_code(500);
    exit('Unable to read MediBook logo.');
}

$pngSignature = "\x89PNG\r\n\x1a\n";

if (substr($pngData, 0, 8) !== $pngSignature) {
    exit('Invalid PNG file.');
}

$width = $imageInfo[0];
$height = $imageInfo[1];

$offset = 8;
$idatData = '';
$colorType = null;
$bitDepth = null;
$interlace = null;

while ($offset < strlen($pngData)) {
    $length = unpack(
        'N',
        substr($pngData, $offset, 4)
    )[1];

    $type = substr($pngData, $offset + 4, 4);

    $chunkData = substr(
        $pngData,
        $offset + 8,
        $length
    );

    if ($type === 'IHDR') {
        $header = unpack(
            'Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace',
            $chunkData
        );

        $bitDepth = $header['bitDepth'];
        $colorType = $header['colorType'];
        $interlace = $header['interlace'];
    }

    if ($type === 'IDAT') {
        $idatData .= $chunkData;
    }

    $offset += 12 + $length;

    if ($type === 'IEND') {
        break;
    }
}

if (
    $bitDepth !== 8 ||
    !in_array($colorType, [2, 6], true) ||
    $interlace !== 0
) {
    exit('PNG must be 8-bit RGB/RGBA and non-interlaced.');
}

$channels = $colorType === 6 ? 4 : 3;
$bytesPerPixel = $channels;
$rowBytes = $width * $channels;

$rawData = @gzuncompress($idatData);

if ($rawData === false) {
    exit('Unable to decode PNG image data.');
}

$rgbRows = '';
$alphaRows = '';

$previousRow = array_fill(0, $rowBytes, 0);
$position = 0;

for ($y = 0; $y < $height; $y++) {
    $filter = ord($rawData[$position]);
    $position++;

    $row = [];

    for ($x = 0; $x < $rowBytes; $x++) {
        $value = ord($rawData[$position + $x]);

        $left = $x >= $bytesPerPixel
            ? $row[$x - $bytesPerPixel]
            : 0;

        $above = $previousRow[$x];

        $upperLeft = $x >= $bytesPerPixel
            ? $previousRow[$x - $bytesPerPixel]
            : 0;

        if ($filter === 1) {
            $value += $left;
        } elseif ($filter === 2) {
            $value += $above;
        } elseif ($filter === 3) {
            $value += floor(($left + $above) / 2);
        } elseif ($filter === 4) {
            $p = $left + $above - $upperLeft;

            $pa = abs($p - $left);
            $pb = abs($p - $above);
            $pc = abs($p - $upperLeft);

            if ($pa <= $pb && $pa <= $pc) {
                $value += $left;
            } elseif ($pb <= $pc) {
                $value += $above;
            } else {
                $value += $upperLeft;
            }
        } elseif ($filter !== 0) {
            exit('Unsupported PNG filter.');
        }

        $row[$x] = $value & 255;
    }

    $position += $rowBytes;

    $rgbRow = '';
    $alphaRow = '';

    for ($x = 0; $x < $width; $x++) {
        $base = $x * $channels;

        $rgbRow .= chr($row[$base]);
        $rgbRow .= chr($row[$base + 1]);
        $rgbRow .= chr($row[$base + 2]);

        if ($channels === 4) {
            $alphaRow .= chr($row[$base + 3]);
        } else {
            $alphaRow .= "\xFF";
        }
    }

    // PNG Predictor filter 0
    $rgbRows .= "\x00" . $rgbRow;
    $alphaRows .= "\x00" . $alphaRow;

    $previousRow = $row;
}

$logoData = gzcompress($rgbRows);
$logoAlphaData = gzcompress($alphaRows);

if ($logoData === false || $logoAlphaData === false) {
    exit('Unable to prepare PNG logo for PDF.');
}

    // Save processed logo for future downloads

    $cacheData = pack(
        'N4',
        $width,
        $height,
        strlen($logoData),
        strlen($logoAlphaData)
    );

    $cacheData .= $logoData;
    $cacheData .= $logoAlphaData;

    @file_put_contents(
        $cachePath,
        $cacheData,
        LOCK_EX
    );
}

/*
   Load Call Icon directly as JPEG (No GD required)
*/

$callIconPath = __DIR__ . '/../assets/images/call.jpg';

if (!is_file($callIconPath)) {
    http_response_code(500);
    exit('Call icon not found.');
}

$callIconInfo = @getimagesize($callIconPath);

if (!$callIconInfo || $callIconInfo['mime'] !== 'image/jpeg') {
    http_response_code(500);
    exit('Call icon must be a valid JPEG image.');
}

$callIconData = file_get_contents($callIconPath);

if ($callIconData === false) {
    exit('Unable to read call icon.');
}

/* =====================================================
   12. BUILD PDF
   A4: 595 x 842
===================================================== */

$content = '';

/* Pure white page background */

$content .= pdfRect(
    0, 0, 595, 842,
    '1 1 1'
);

/* Header - white background */

$content .= pdfRect(
    0, 738, 595, 104,
    '0.88 0.94 1'
);

// Blue top accent

$content .= pdfRect(
    0, 738, 595, 4,
    '0.12 0.37 0.95'
);

// Logo

$content .= pdfImage(
    'Im1',
    48, 765, 60, 60
);

// MediBook Brand Name

$content .= pdfDrawText(
    120, 798,
    'Medi',
    32,
    'F2',
    '0.06 0.17 0.36'
);

$content .= pdfDrawText(
    194, 798,
    'Book',
    32,
    'F2',
    '0.04 0.35 0.95'
);

// Subtitle

$content .= pdfDrawText(
    122, 778,
    'D O C T O R   A P P O I N T M E N T   S Y S T E M',
    8,
    'F2',
    '0.30 0.39 0.56'
);

// Receipt title

$content .= pdfDrawText(
    350, 798,
    'APPOINTMENT RECEIPT',
    18,
    'F2',
    '0.06 0.17 0.36'
);

// Receipt subtitle

$content .= pdfDrawText(
    355, 778,
    'O F F I C I A L   B O O K I N G   C O N F I R M A T I O N',
    7,
    'F2',
    '0.30 0.39 0.56'
);


/* =====================================================
   13. APPOINTMENT REFERENCE
===================================================== */

// Very light blue reference panel

$content .= pdfRect(
    42, 650, 511, 78,
    '0.94 0.97 1'
);

$content .= pdfDrawText(
    65, 704,
    'APPOINTMENT REFERENCE',
    12,
    'F2',
    '0.06 0.17 0.36'
);

$content .= pdfDrawText(
    65, 675,
    $reference,
    21,
    'F2',
    '0.05 0.34 0.90'
);

$content .= pdfDrawText(
    65, 659,
    'Please keep this receipt for your records.',
    10,
    'F1',
    '0.18 0.28 0.43'
);

// Appointment status

$content .= pdfRect(
    440, 675, 96, 27,
    '0.84 0.92 1'
);

$content .= pdfDrawText(
    448, 684,
    strtoupper($appointmentStatus),
    10,
    'F2',
    '0.04 0.30 0.82'
);

/* =====================================================
   14. WATERMARK
===================================================== */

// Diagonal MediBook watermark

$content .= "q\n";
$content .= "BT\n";
$content .= "/F2 65 Tf\n";
$content .= "0.94 0.97 1 rg\n";
$content .= "0.82 0.57 -0.57 0.82 125 350 Tm\n";
$content .= "(" . pdfText('MediBook') . ") Tj\n";
$content .= "ET\n";
$content .= "Q\n";

/* =====================================================
   15. PATIENT INFORMATION
===================================================== */

$content .= pdfLine(48, 630, 547);

$content .= pdfDrawText(
    65, 604,
    'PATIENT INFORMATION',
    14,
    'F2',
    '0.04 0.16 0.36'
);

$content .= pdfDrawText(
    65, 577,
    'Patient Name',
    11,
    'F1',
    '0.12 0.22 0.38'
);

$content .= pdfDrawText(245, 577, ':', 11, 'F2');
$content .= pdfDrawText(270, 577, $patientName, 11, 'F2');

$content .= pdfDrawText(
    65, 551,
    'Patient ID',
    11,
    'F1',
    '0.12 0.22 0.38'
);

$content .= pdfDrawText(245, 551, ':', 11, 'F2');
$content .= pdfDrawText(270, 551, $patientCode, 11, 'F1');

$content .= pdfDrawText(
    65, 525,
    'Contact Number',
    11,
    'F1',
    '0.12 0.22 0.38'
);

$content .= pdfDrawText(245, 525, ':', 11, 'F2');
$content .= pdfDrawText(270, 525, $patientNumber, 11, 'F1');

/* =====================================================
   16. APPOINTMENT DETAILS
===================================================== */

$content .= pdfLine(48, 504, 547);

$content .= pdfDrawText(
    65, 478,
    'APPOINTMENT DETAILS',
    14,
    'F2',
    '0.04 0.16 0.36'
);

$content .= pdfDrawText(65, 451, 'Doctor', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 451, ':', 11, 'F2');
$content .= pdfDrawText(270, 451, $doctorName, 11, 'F2');

// Hospital / Clinic
$content .= pdfDrawText(
    65, 425,
    'Hospital / Clinic',
    11,
    'F1',
    '0.12 0.22 0.38'
);

$content .= pdfDrawText(
    245, 425,
    ':',
    11,
    'F2'
);

$content .= pdfDrawText(
    270, 425,
    $hospitalClinicName,
    11,
    'F1'
);

$content .= pdfDrawText(65, 399, 'Branch', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 399, ':', 11, 'F2');
$content .= pdfDrawText(270, 399, $branchName, 11, 'F1');

$content .= pdfDrawText(65, 373, 'Appointment Date', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 373, ':', 11, 'F2');
$content .= pdfDrawText(270, 373, $appointmentDate, 11, 'F1');

$content .= pdfDrawText(65, 347, 'Appointment Time', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 347, ':', 11, 'F2');
$content .= pdfDrawText(270, 347, $appointmentTime, 11, 'F1');

$content .= pdfDrawText(65, 321, 'Appointment Status', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 321, ':', 11, 'F2');
$content .= pdfDrawText(270, 321, $appointmentStatus, 11, 'F2');

/* =====================================================
   17. PAYMENT DETAILS
===================================================== */

$content .= pdfLine(48, 299, 547);

$content .= pdfDrawText(
    65, 273,
    'PAYMENT DETAILS',
    14,
    'F2',
    '0.04 0.16 0.36'
);

$content .= pdfDrawText(65, 246, 'Payment Method', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 246, ':', 11, 'F2');
$content .= pdfDrawText(270, 246, $paymentMethod, 11, 'F2');

$content .= pdfDrawText(65, 220, 'Payment Status', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 220, ':', 11, 'F2');

$paymentColor = strtolower($paymentStatus) === 'paid'
    ? '0.02 0.48 0.25'
    : '0.75 0.25 0.08';

$content .= pdfDrawText(
    270, 220,
    $paymentStatus,
    11,
    'F2',
    $paymentColor
);

$content .= pdfDrawText(65, 194, 'Transaction Reference', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 194, ':', 11, 'F2');
$content .= pdfDrawText(270, 194, $transactionReference, 10, 'F1');

$content .= pdfDrawText(65, 168, 'Payment Date & Time', 11, 'F1', '0.12 0.22 0.38');
$content .= pdfDrawText(245, 168, ':', 11, 'F2');
$content .= pdfDrawText(270, 168, $paymentDate, 10, 'F1');

/* =====================================================
   18. TOTAL AMOUNT
===================================================== */

$content .= pdfRect(
    48, 105, 499, 48,
    '0.91 0.96 1'
);

$content .= pdfDrawText(
    68, 134,
    'TOTAL AMOUNT',
    13,
    'F2',
    '0.04 0.16 0.36'
);

$content .= pdfDrawText(
    68, 117,
    'Amount for this appointment',
    9,
    'F1',
    '0.18 0.28 0.43'
);

$content .= pdfDrawText(
    385, 120,
    'BDT ' . $amount,
    20,
    'F2',
    '0.04 0.32 0.90'
);

/* =====================================================
   19. FOOTER
===================================================== */

$content .= pdfLine(
    48, 85, 547,
    '0.76 0.83 0.92'
);

// Call icon background
$content .= pdfCircle(
    75, 60, 20,
    '0.90 0.95 1'
);

// New MediBook Call Icon
$content .= pdfImage(
    'CallIcon',
    55, 40,
    40, 40
);

$content .= pdfDrawText(
    105, 56,
    '01889106270',
    10,
    'F1',
    '0.06 0.17 0.36'
);

// Divider
$content .= "q 0.75 0.82 0.92 RG 0.9 w 205 43 m 205 77 l S Q\n";

// Email icon background
$content .= pdfCircle(
    240, 60, 20,
    '0.90 0.95 1'
);

// Clean Email Envelope Icon
$content .= "q 0.04 0.35 0.95 RG 1.8 w 1 J 1 j ";
$content .= "230 52 20 15 re S ";
$content .= "230 66 m 240 58 l 250 66 l S Q\n";

$content .= pdfDrawText(
    270, 56,
    'medibook.help@gmail.com',
    10,
    'F1',
    '0.06 0.17 0.36'
);



// Thank You - Right Aligned
$content .= pdfDrawText(
    438, 66,
    'Thank you!',
    13,
    'F2',
    '0.04 0.32 0.90'
);

$content .= pdfDrawText(
    430, 48,
    'For choosing MediBook',
    8,
    'F1',
    '0.20 0.30 0.45'
);

/* =====================================================
   20. BUILD PDF OBJECTS
===================================================== */

$objects = [];

// Object 1: Catalog

$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

// Object 2: Pages

$objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

// Object 3: Page

$objects[] =
    '<< /Type /Page /Parent 2 0 R '
    . '/MediaBox [0 0 595 842] '
    . '/Resources << '
    . '/Font << /F1 4 0 R /F2 5 0 R >> '
    . '/XObject << /Im1 6 0 R /CallIcon 8 0 R >> '
    . '>> /Contents 7 0 R >>';

// Object 4: Helvetica

$objects[] =
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

// Object 5: Helvetica Bold

$objects[] =
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

// Object 6: MediBook PNG Logo with transparency

$objects[] =
    '<< /Type /XObject /Subtype /Image '
    . '/Width ' . $width
    . ' /Height ' . $height
    . ' /ColorSpace /DeviceRGB '
    . '/BitsPerComponent 8 '
    . '/Filter /FlateDecode '
    . '/DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns '
    . $width . ' >> '
    . '/SMask 9 0 R '
    . '/Length ' . strlen($logoData)
    . " >>\nstream\n"
    . $logoData
    . "\nendstream";

// Object 7: Page content

$objects[] =
    '<< /Length ' . strlen($content) . " >>\nstream\n"
    . $content
    . "\nendstream";

// Object 8: Call Icon JPEG

$objects[] =
    '<< /Type /XObject /Subtype /Image '
    . '/Width ' . $callIconInfo[0]
    . ' /Height ' . $callIconInfo[1]
    . ' /ColorSpace /DeviceRGB '
    . '/BitsPerComponent 8 '
    . '/Filter /DCTDecode '
    . '/Length ' . strlen($callIconData)
    . " >>\nstream\n"
    . $callIconData
    . "\nendstream";

// Object 9: PNG Alpha Transparency Mask

$objects[] =
    '<< /Type /XObject /Subtype /Image '
    . '/Width ' . $width
    . ' /Height ' . $height
    . ' /ColorSpace /DeviceGray '
    . '/BitsPerComponent 8 '
    . '/Filter /FlateDecode '
    . '/DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns '
    . $width . ' >> '
    . '/Length ' . strlen($logoAlphaData)
    . " >>\nstream\n"
    . $logoAlphaData
    . "\nendstream";

/* =====================================================
   21. GENERATE PDF
===================================================== */

$pdf = "%PDF-1.4\n";

$offsets = [0];

foreach ($objects as $index => $object) {
    $offsets[] = strlen($pdf);

    $pdf .= ($index + 1)
        . " 0 obj\n"
        . $object
        . "\nendobj\n";
}

// Cross-reference table

$xrefPosition = strlen($pdf);

$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";

$pdf .= "0000000000 65535 f \n";

for ($i = 1; $i <= count($objects); $i++) {
    $pdf .= sprintf(
        "%010d 00000 n \n",
        $offsets[$i]
    );
}

// Trailer

$pdf .=
    "trailer\n<< /Size "
    . (count($objects) + 1)
    . " /Root 1 0 R >>\n";

$pdf .=
    "startxref\n"
    . $xrefPosition
    . "\n%%EOF";

/* =====================================================
   22. DOWNLOAD PDF
===================================================== */

if (headers_sent()) {
    exit('PDF download failed: Output was already sent.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = 'MediBook_Receipt_' . $appointmentId . '.pdf';

header('Content-Type: application/pdf');

header(
    'Content-Disposition: attachment; filename="' . $filename . '"'
);

header('Content-Length: ' . strlen($pdf));

header('Cache-Control: private, max-age=0, must-revalidate');

header('Pragma: public');

echo $pdf;
exit;
