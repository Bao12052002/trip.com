<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/TripHelper.php';

/**
 * Lớp chuyên dụng để gửi các thông báo cập nhật sản phẩm (Giá & Tồn kho) đến Trip.com
 */
class ProductNotifier {
    private $accountId;
    private $signKey;
    private $aesKey;
    private $aesIv;
    private $priceUrl;
    private $stockUrl;

    public function __construct(Database $database) {
        $this->accountId = $database->accountId;
        $this->signKey = $database->signKey;
        $this->aesKey = $database->aesKey;
        $this->aesIv = $database->aesIv;
        
        // Sử dụng URL chính xác từ email cấu hình cho Product Integration
        $this->priceUrl = 'https://ttdopen.ctrip.com/api/product/price.do';
        $this->stockUrl = 'https://ttdopen.ctrip.com/api/product/stock.do';
    }

    /**
     * Gửi thông báo cập nhật giá
     * @param string $supplierPlu Mã PLU của sản phẩm
     * @param array $priceUpdates Mảng chứa thông tin cập nhật giá
     * @param string $dateType Loại ngày ('DATE_REQUIRED' hoặc 'DATE_NOT_REQUIRED')
     */
    public function sendPriceUpdate(string $supplierPlu, array $priceUpdates, string $dateType = 'DATE_REQUIRED') {
        $body = [
            'sequenceId'       => date('Ymd') . bin2hex(random_bytes(16)),
            'supplierOptionId' => $supplierPlu,
            'dateType'         => $dateType,
            'prices'           => $priceUpdates
        ];
        return $this->send('DatePriceModify', $this->priceUrl, $body);
    }

    /**
     * Gửi thông báo cập nhật tồn kho
     * @param string $supplierPlu Mã PLU của sản phẩm
     * @param array $inventoryUpdates Mảng chứa thông tin cập nhật tồn kho
     * @param string $dateType Loại ngày ('DATE_REQUIRED' hoặc 'DATE_NOT_REQUIRED')
     */
    public function sendInventoryUpdate(string $supplierPlu, array $inventoryUpdates, string $dateType = 'DATE_REQUIRED') {
        $body = [
            'sequenceId'       => date('Ymd') . bin2hex(random_bytes(16)),
            'supplierOptionId' => $supplierPlu,
            'dateType'         => $dateType,
            'inventorys'       => $inventoryUpdates
        ];
        return $this->send('DateInventoryModify', $this->stockUrl, $body);
    }

    /**
     * Hàm private để thực hiện gửi request cURL và trả về kết quả
     */
    private function send(string $serviceName, string $url, array $body) {
        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);
        $bodyEncrypted = TripHelper::encryptBody($bodyJson, $this->aesKey, $this->aesIv);

        $header = [
            'accountId'   => $this->accountId,
            'serviceName' => $serviceName,
            'requestTime' => date('Y-m-d H:i:s'),
            'version'     => '1.0',
        ];
        $header['sign'] = TripHelper::generateSign($header, $bodyEncrypted, $this->signKey);
    
        $payload = json_encode(['header' => $header, 'body' => $bodyEncrypted], JSON_UNESCAPED_UNICODE);
    
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }
}
