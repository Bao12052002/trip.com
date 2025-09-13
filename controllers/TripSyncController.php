<?php
/**
 * Tự động gửi DatePriceModify và DateInventoryModify lên Trip
 */

require_once '../helpers/TripHelper.php';
require_once '../config/db.php';

class TripSyncController {
    private $accountId;
    private $signKey;
    private $aesKey;
    private $aesIv;
    private $tripUrl;
    private $conn;

    public function __construct() {
        $db = new Database();
        $this->conn = $db->connect();
        $this->accountId = $db->accountId;
        $this->signKey = $db->signKey;
        $this->aesKey = $db->aesKey;
        $this->aesIv = $db->aesIv;
        $this->tripUrl = 'https://ttdopen.ctrip.com/api'; // Thay bằng endpoint thật
    }

    public function syncPriceAndInventory() {
        $products = $this->getProductsToSync();

        foreach ($products as $product) {
            $availability = $this->getAvailabilityByProduct($product['id']);
            if (empty($availability)) continue;

            $this->postDatePriceModify($product, $availability);
            $this->postDateInventoryModify($product, $availability);
        }
    }

    private function getProductsToSync() {
        $stmt = $this->conn->prepare("SELECT * FROM tbl_trip_products WHERE is_active = 1");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getAvailabilityByProduct($productId) {
        $stmt = $this->conn->prepare("SELECT * FROM tbl_trip_availability WHERE product_id = :id");
        $stmt->bindParam(':id', $productId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function postDatePriceModify($product, $availability) {
        $prices = [];
        foreach ($availability as $a) {
            $prices[] = [
                'date' => $a['date'],
                'salePrice' => number_format($a['sale_price'], 2),
                'costPrice' => number_format($a['cost_price'], 2)
            ];
        }

        $body = [
            'sequenceId' => date('Ymd') . '-' . bin2hex(random_bytes(8)),
            'otaOptionId' => $product['plu'],
            'supplierOptionId' => $product['plu'],
            'dateType' => 'DATE_REQUIRED',
            'prices' => $prices
        ];

        $this->postToTrip('DatePriceModify', $body);
    }

    private function postDateInventoryModify($product, $availability) {
        $inventories = [];
        foreach ($availability as $a) {
            $inventories[] = [
                'date' => $a['date'],
                'quantity' => (int)$a['inventory']
            ];
        }

        $body = [
            'sequenceId' => date('Ymd') . '-' . bin2hex(random_bytes(8)),
            'otaOptionId' => $product['plu'],
            'supplierOptionId' => $product['plu'],
            'dateType' => 'DATE_REQUIRED',
            'inventorys' => $inventories
        ];

        $this->postToTrip('DateInventoryModify', $body);
    }

    private function postToTrip($serviceName, $body) {
        $requestTime = date('Y-m-d H:i:s');
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
        $bodyEncrypted = TripHelper::encryptBody($bodyJson, $this->aesKey, $this->aesIv);

        $header = [
            'accountId' => $this->accountId,
            'serviceName' => $serviceName,
            'requestTime' => $requestTime,
            'version' => '1.0',
            'sign' => TripHelper::generateSign([
                'accountId' => $this->accountId,
                'serviceName' => $serviceName,
                'requestTime' => $requestTime,
                'version' => '1.0'
            ], $bodyEncrypted, $this->signKey)
        ];

        $payload = json_encode([
            'header' => $header,
            'body' => $bodyEncrypted
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->tripUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        curl_close($ch);

        file_put_contents(__DIR__ . '/../log/trip_sync.log', "[$serviceName] " . $response . PHP_EOL, FILE_APPEND);
    }
}

// Khởi chạy
$sync = new TripSyncController();
$sync->syncPriceAndInventory();
