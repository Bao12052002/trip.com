<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/TripHelper.php';

class TripNotifier {
    private $accountId;
    private $signKey;
    private $aesKey;
    private $aesIv;
    private $callbackBaseUrl;

    public function __construct(Database $database) {
        $this->accountId = $database->accountId;
        $this->signKey = $database->signKey;
        $this->aesKey = $database->aesKey;
        $this->aesIv = $database->aesIv;
        $this->callbackBaseUrl = rtrim($database->tripCallbackUrl, '/');
    }

    /**
     * Gửi thông báo đã sử dụng (Consumed Notice)
     * @param array $orderData Dữ liệu đơn hàng từ CSDL của bạn
     * @param int $consumedNow Số lượng vừa mới sử dụng trong lần này
     */
    public function sendConsumedNotice(array $orderData, int $consumedNow) {
        $url = $this->callbackBaseUrl . '/notice.do';
        $body = [
            'sequenceId'      => date('Ymd') . bin2hex(random_bytes(16)),
            'otaOrderId'      => $orderData['ota_order_id'],
            'supplierOrderId' => $orderData['supplier_order_id'],
            'items' => [[
                'itemId'      => $orderData['item_id'],
                'quantity'    => (int)($orderData['original_quantity'] ?? $orderData['quantity']),
                'useQuantity' => $consumedNow,
                'passengers'  => [['passengerId' => $orderData['item_id']]]
            ]]
        ];
        $this->send('OrderConsumedNotice', $url, $body);
    }

    /**
     * Gửi thông báo xác nhận hủy đơn (từ chối hoặc chấp nhận)
     * @param array $orderData Dữ liệu đơn hàng từ CSDL của bạn
     * @param string $resultCode Mã kết quả ('2002' cho từ chối, '0000' cho thành công)
     * @param string $message Lý do
     */
    public function sendCancelConfirmation(array $orderData, string $resultCode, string $message) {
        $url = $this->callbackBaseUrl . '/notice.do';
        $body = [
            'sequenceId'           => date('Ymd') . bin2hex(random_bytes(16)),
            'otaOrderId'           => $orderData['ota_order_id'],
            'supplierOrderId'      => $orderData['supplier_order_id'],
            'confirmResultCode'    => str_pad($resultCode, 4, '0', STR_PAD_LEFT),
            'confirmResultMessage' => $message,
            'items' => [['itemId' => $orderData['item_id']]]
        ];
        $this->send('CancelOrderConfirm', $url, $body);
    }

    /**
     * Hàm private để thực hiện gửi request cURL
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
            CURLOPT_SSL_VERIFYPEER => false, // Bỏ qua xác thực SSL cho môi trường test
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        curl_exec($ch);
        curl_close($ch);
    }
}
