<?php
/**
 * @file api.php
 * Handles dynamic configuration and Apple Wallet (.pkpass) generation for Fuel Pass.
 */

define('ENABLE_ODD_EVEN_SYSTEM', true); // Flip to false to disable odd/even license plate rules
define('CARD_NAME', 'National Fuel Pass');

// =========================================================================
// 1. CONFIGURATION
// =========================================================================

$vehicleAllowances = [
    'Motor Car'             => '25L',
    'Motorcycle'            => '4L',
    'Three Wheeler'         => '8L',
    'Dual Purpose Vehicle'  => '20L',
    'Bus'                   => '40L',
    'Lorry'                 => '50L',
    'Other'                 => '20L'
];

$startingLetterMapping = [
    'A' => 'Three Wheeler', 'Q' => 'Three Wheeler',
    'B' => 'Motorcycle',    'M' => 'Motorcycle', 'U' => 'Motorcycle',
    'C' => 'Motor Car',     'K' => 'Motor Car',  'W' => 'Motor Car',
];

$evenDigits = [0, 2, 4, 6, 8];
$oddDigits  = [1, 3, 5, 7, 9];

// =========================================================================
// 2. ROUTING
// =========================================================================

// Handle dynamic config request from frontend
if (isset($_GET['action']) && $_GET['action'] === 'config') {
    header('Content-Type: application/json');
    echo json_encode([
        'allowances' => $vehicleAllowances,
        'startingLetters' => $startingLetterMapping,
        'evenDigits' => $evenDigits,
        'oddDigits' => $oddDigits,
        'enableOddEven' => ENABLE_ODD_EVEN_SYSTEM
    ]);
    exit;
}



// =========================================================================
// 3. PASS DATA PREPARATION
// =========================================================================

$vehicleNumber = $_GET['vehicleNumber'] ?? '';
$permitCode = $_GET['permitCode'] ?? '';

// Auto-detect type
$cleanPlate = trim(preg_replace('/^(?:WP|CP|SP|EP|NW|NC|UP|VA|RU)\s+/i', '', $vehicleNumber));
$firstLetter = strtoupper(substr($cleanPlate, 0, 1));
$detectedType = $startingLetterMapping[$firstLetter] ?? 'Motor Car';

$vehicleTypeLabel = $_GET['vehicleTypeLabel'] ?? $detectedType;
$quotaLabel = $_GET['quotaLabel'] ?? $vehicleAllowances[$vehicleTypeLabel] ?? '20L';
$qrValue = $_GET['qrValue'] ?? "$vehicleNumber | $permitCode";

// Paths
$certsDir = __DIR__ . '/../certificates';
$wwdrPath = realpath($certsDir . '/WWDR.pem');
$signerCertPath = realpath($certsDir . '/signerCert.pem');
$signerKeyPath = realpath($certsDir . '/signerKey.pem');

$hasCerts = $wwdrPath && $signerCertPath && $signerKeyPath && file_exists($wwdrPath) && file_exists($signerCertPath) && file_exists($signerKeyPath);

if (!$hasCerts) {
    header('Content-Type: text/html');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Developer Mode</title></head>
          <body style="background:#0b0f19;color:#fff;font-family:sans-serif;text-align:center;padding:50px;">
          <div style="background:rgba(255,255,255,0.05);padding:30px;border-radius:20px;display:inline-block;">
          <h1 style="color:#00f0ff;">🚀 Developer Mode Active</h1>
          <p>Apple Wallet Certificates are missing in the <code>/certificates</code> folder. Cannot generate a signed <code>.pkpass</code>.</p>
          <a href="index.php" style="color:#00f0ff;text-decoration:none;border:1px solid #00f0ff;padding:10px;border-radius:10px;">Go Back</a>
          </div></body></html>';
    exit;
}

// =========================================================================
// 4. PKPASS GENERATION
// =========================================================================

$certData = openssl_x509_parse(file_get_contents($signerCertPath));
$passTypeIdentifier = $certData['subject']['UID'] ?? 'pass.com.example.fuelpass';
$teamIdentifier = $certData['subject']['OU'] ?? 'TEAMID1234';

$tempId = uniqid('pass_');
$tempDir = sys_get_temp_dir() . '/' . $tempId;
mkdir($tempDir);

/**
 * Calculates if today is an eligible refueling day.
 */
function getEligibilityText($vehicleNumber, $evenDigits) {
    if (!ENABLE_ODD_EVEN_SYSTEM) return "Any Day";
    
    preg_match('/(\d)(?!.*\d)/', $vehicleNumber, $matches);
    $lastDigit = isset($matches[1]) ? (int)$matches[1] : null;

    if ($lastDigit === null) return "Any Day";
    
    $isEvenPlate = in_array($lastDigit, $evenDigits);

    return $isEvenPlate ? "Even Days" : "Odd Days";
}

$nextEligibleText = getEligibilityText($vehicleNumber, $evenDigits);

// Dynamic base64 serial
$serialData = "$vehicleNumber|$permitCode|$vehicleTypeLabel|$quotaLabel|$qrValue";
$serialNumber = "pass_" . rtrim(strtr(base64_encode($serialData), '+/', '-_'), '=');

$passJson = [
    "formatVersion" => 1,
    "passTypeIdentifier" => $passTypeIdentifier,
    "teamIdentifier" => $teamIdentifier,
    "organizationName" => CARD_NAME,
    "description" => "Sri Lankan Fuel Pass Wallet Card",
    "logoText" => CARD_NAME,
    "serialNumber" => $serialNumber,
    "backgroundColor" => "rgb(220, 38, 38)",
    "foregroundColor" => "rgb(255, 255, 255)",
    "labelColor" => "rgba(255, 255, 255, 0.7)",
    "tintColor" => "rgb(255, 255, 255)",
    "barcodes" => [
        ["format" => "PKBarcodeFormatQR", "message" => $qrValue, "messageEncoding" => "iso-8859-1"]
    ],
    "barcode" => [
        "format" => "PKBarcodeFormatQR", "message" => $qrValue, "messageEncoding" => "iso-8859-1"
    ],
    "generic" => [
        "primaryFields" => [
            ["key" => "vehicleNumber", "label" => "VEHICLE NUMBER", "value" => $vehicleNumber]
        ],
        "secondaryFields" => [
            ["key" => "quota", "label" => "WEEKLY QUOTA", "value" => $quotaLabel],
            ["key" => "eligibleDay", "label" => "ALLOWED ON", "value" => $nextEligibleText, "textAlignment" => "PKTextAlignmentRight"]
        ],
        "auxiliaryFields" => [
            ["key" => "permitCode", "label" => "CODE", "value" => $permitCode],
            ["key" => "vehicleType", "label" => "VEHICLE TYPE", "value" => $vehicleTypeLabel, "textAlignment" => "PKTextAlignmentRight"]
        ],
        "backFields" => [
            ["key" => "instructions", "label" => "INSTRUCTIONS", "value" => "Present this QR code to the pump operator at any registered filling station. Ensure the vehicle plate matches this card."],
            ["key" => "quotaRules", "label" => "WEEKLY QUOTA RENEWAL", "value" => "Your weekly quota ($quotaLabel) is renewed automatically every Saturday at midnight (12:00 AM). Unused quota does not roll over to the next week."],
            ["key" => "support", "label" => "SUPPORT & HELPLINE", "value" => "For any issues regarding vehicle verification, quota errors, or registration updates, call the National Fuel Pass Helpline at 1919 (Toll-Free) or visit fuelpass.gov.lk."],
            ["key" => "validity", "label" => "PERMIT DETAILS", "value" => "Registered Code: $permitCode\nDevice Sync: " . date('Y-m-d H:i:s T')],
            ["key" => "disclaimer", "label" => "DISCLAIMER", "value" => "This card is a digital representation of your National Fuel Pass. Misuse of the QR code or fuel permit is a punishable national offense."]
        ]
    ]
];

file_put_contents("$tempDir/pass.json", json_encode($passJson));

$imageFiles = ['icon.png', 'icon@2x.png', 'icon@3x.png'];
$transparentPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
foreach ($imageFiles as $img) {
    file_put_contents("$tempDir/$img", $transparentPng);
}

$nfpPath = __DIR__ . '/nfp.png';
if (file_exists($nfpPath)) {
    $nfpContent = file_get_contents($nfpPath);
    file_put_contents("$tempDir/logo.png", $nfpContent);
    file_put_contents("$tempDir/logo@2x.png", $nfpContent);
    $imageFiles[] = 'logo.png';
    $imageFiles[] = 'logo@2x.png';
}

$manifest = [];
$files = array_merge(['pass.json'], $imageFiles);
foreach ($files as $file) {
    $manifest[$file] = sha1_file("$tempDir/$file");
}
file_put_contents("$tempDir/manifest.json", json_encode($manifest));

// Sign manifest
$signerCert = str_replace("\r\n", "\n", file_get_contents($signerCertPath));
$signerKey = str_replace("\r\n", "\n", file_get_contents($signerKeyPath));
$wwdrContent = str_replace("\r\n", "\n", file_get_contents($wwdrPath));

$tempWwdrPath = "$tempDir/wwdr.pem";
file_put_contents($tempWwdrPath, $wwdrContent);

$manifestPath = "$tempDir/manifest.json";
$signaturePath = "$tempDir/signature";
$tempSigFile = "$tempDir/sig.tmp";

if (openssl_pkcs7_sign($manifestPath, $tempSigFile, $signerCert, [$signerKey, ''], [], PKCS7_BINARY | PKCS7_DETACHED, $tempWwdrPath)) {
    $sigData = file_get_contents($tempSigFile);
    preg_match('/boundary="([^"]+)"/', $sigData, $matches);
    $boundary = $matches[1] ?? '';
    
    if ($boundary) {
        $parts = explode('--' . $boundary, $sigData);
        foreach ($parts as $part) {
            if (stripos($part, 'Content-Transfer-Encoding: base64') !== false || stripos($part, 'base64') !== false) {
                $subparts = explode("\n\n", trim(str_replace("\r\n", "\n", $part)), 2);
                if (count($subparts) == 2) {
                    $base64 = preg_replace('/--$/', '', trim($subparts[1]));
                    file_put_contents($signaturePath, base64_decode(trim($base64)));
                    break;
                }
            }
        }
    }
} else {
    die("OpenSSL Signature Failed on Server: " . openssl_error_string());
}

// Zip
$zipPath = sys_get_temp_dir() . "/$tempId.pkpass";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
    foreach ($files as $file) $zip->addFile("$tempDir/$file", $file);
    if (file_exists("$tempDir/manifest.json")) $zip->addFile("$tempDir/manifest.json", 'manifest.json');
    if (file_exists($signaturePath)) $zip->addFile($signaturePath, 'signature');
    $zip->close();
}

// Serve
header('Pragma: no-cache');
header('Content-type: application/vnd.apple.pkpass');
header('Content-length: ' . filesize($zipPath));
header('Content-Disposition: attachment; filename="fuelpass-' . preg_replace('/\s+/', '-', $vehicleNumber) . '.pkpass"');
readfile($zipPath);

// Cleanup
@unlink($zipPath);
array_map('unlink', glob("$tempDir/*.*"));
@unlink("$tempDir/signature");
@unlink("$tempDir/sig.tmp");
@rmdir($tempDir);
