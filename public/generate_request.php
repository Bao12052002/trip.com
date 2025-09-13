<?php
require_once '../lib/utils.php';
require_once '../config/db.php';

$database = new Database();
$aesKey = $database->aesKey;
$aesIv = $database->aesIv;
$signKey = $database->signKey;
$accountId = $database->accountId;

// Dữ liệu mẫu cho CreatePreOrder
$body = [
    "sequenceId" => "20171010abcd95774f17c3e354e73f7aaf21b5ec",
    "otaOrderId" => "123456",
    "items" => [
        [
            "itemId" => "123456",
            "PLU" => "test-plu-1",
            "quantity" => 2,
            "useStartDate" => "2025-06-01"
        ]
    ]
];
$bodyStr = json_encode($body);
$encryptedBody = encryptAES($bodyStr, $aesKey, $aesIv);

$header = [
    "accountId" => $accountId,
    "serviceName" => "CreatePreOrder",
    "requestTime" => date("Y-m-d H:i:s"),
    "version" => "1.0"
];
$header['sign'] = generateSignature($header['accountId'], $header['serviceName'], $header['requestTime'], $bodyStr, $header['version'], $signKey);

$request = [
    'header' => $header,
    'body' => $encryptedBody
];

echo json_encode($request, JSON_PRETTY_PRINT);
?>