<?php
// TripRequestBuilder.php

// Cấu hình thông tin bí mật
$accountId = '9a61dfc211eab470';
$serviceName = 'DateInventoryModify';
$requestTime = '2025-05-12 10:00:00';
$version = '1.0';
$signKey = '83b9c5b8ad307f37ffe681c70880fba9';
$aesKey = 'a6e94d21d64c51d7'; // 16 ký tự
$aesIv = '27f6f25d501bf1fb';  // 16 ký tự

$body = [
    "sequenceId" => "20171010abcd95774f17c3e354e73f7aaf21b5e1",
    "otaOptionId" => "test-plu-1",
    "supplierOptionId" => "test-plu-1",
    "dateType" => "DATE_REQUIRED",
    "inventorys" => [
        [
            "date" => "2025-06-01",
            "quantity" => 120
        ]
    ]
];

// Bước 1: Mã hóa body
$bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
$encryptedBody = base64_encode(openssl_encrypt($bodyJson, 'AES-128-CBC', $aesKey, OPENSSL_RAW_DATA, $aesIv));

// Bước 2: Tính sign
$sign = md5($accountId . $serviceName . $requestTime . $encryptedBody . $version . $signKey);

// Bước 3: Gộp header và body thành JSON
$request = [
    "header" => [
        "accountId" => $accountId,
        "serviceName" => $serviceName,
        "requestTime" => $requestTime,
        "version" => $version,
        "sign" => $sign
    ],
    "body" => $encryptedBody
];

// In ra JSON hoàn chỉnh để copy vào Postman
header('Content-Type: application/json');
echo json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
