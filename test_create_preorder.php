<?php
require_once '../helpers/TripHelper.php';
require_once '../config/db.php';

// --- DATA SETUP ---
$accountId = '9a61dfc211eab470';
$signKey   = '83b9c5b8ad307f37ffe681c70880fba9';
$aesKey    = 'a6e94d21d64c51d7';
$aesIv     = '27f6f25d501bf1fb';

// --- BODY PLAIN TEXT ---
$bodyPlain = json_encode([
    'otaOrderId' => 'OTA_ORDER_123456789',
    'sequenceId' => 'SEQ_001',
    'contacts' => [[
        'name' => 'John Doe',
        'mobile' => '1234567890'
    ]],
    'items' => [[
        'PLU' => 'AAR', // Đảm bảo PLU này có tồn kho
        'quantity' => 1,
        'useStartDate' => date('Y-m-d'),
        'passengers' => [[
            'passengerName' => 'John Doe',
            'mobile' => '1234567890'
        ]]
    ]]
], JSON_UNESCAPED_UNICODE);

// --- ENCRYPT BODY ---
$encryptedBody = TripHelper::encryptBody($bodyPlain, $aesKey, $aesIv);

// --- HEADER ---
$header = [
    'accountId'    => $accountId,
    'serviceName'  => 'CreatePreOrder',
    'requestTime'  => date('Y-m-d H:i:s'),
    'version'      => '1.0'
];
$header['sign'] = TripHelper::generateSign($header, $encryptedBody, $signKey);

// --- PAYLOAD ---
$payload = json_encode([
    'header' => $header,
    'body' => $encryptedBody
], JSON_UNESCAPED_UNICODE);

// --- SEND REQUEST TO YOUR SYSTEM ---
$ch = curl_init('https://coralmountainltd.com/trip_supplier_system/public/api.php'); // Cập nhật đúng URL endpoint handleRequest
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- SHOW RESPONSE ---
echo "HTTP Code: $httpCode\n";
echo "Response:\n$response\n";
