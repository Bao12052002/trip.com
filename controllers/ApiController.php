<?php



require_once '../models/Order.php';



require_once '../models/Product.php';



require_once '../models/Availability.php';



require_once '../models/Voucher.php';



require_once '../helpers/TripHelper.php';



require_once '../config/db.php';







class ApiController {



    private $orderModel;



    private $productModel;



    private $availabilityModel;



    private $voucherModel;



    private $accountId;



    private $signKey;



    private $aesKey;



    private $aesIv;



    private $tripCallbackUrl;







    public function __construct() {
        // 1. Tạo đối tượng Database một lần duy nhất
        $database = new Database();
        $pdo_connection = $database->getConnection(); // Lấy kết nối PDO

        // 2. "Tiêm" kết nối này vào tất cả các Model khi khởi tạo
        $this->orderModel = new Order($pdo_connection);
        $this->productModel = new Product($pdo_connection);
        $this->availabilityModel = new Availability($pdo_connection);
        $this->voucherModel = new Voucher($pdo_connection);

        // 3. Các biến còn lại giữ nguyên
        $this->accountId = $database->accountId;
        $this->signKey = $database->signKey;
        $this->aesKey = $database->aesKey;
        $this->aesIv = $database->aesIv;
        $this->tripCallbackUrl = $database->tripCallbackUrl;
    }









    public function handleRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            // Phản hồi lỗi chung cho phương thức không hợp lệ
            header("HTTP/1.1 405 Method Not Allowed");
            echo json_encode(['error' => 'Method Not Allowed']);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $request = json_decode($rawInput, true);

        // Theo tài liệu, lỗi parsing trả về mã 0001
        if (json_last_error() !== JSON_ERROR_NONE || !isset($request['header'], $request['body'])) {
            return $this->response('0001', "Message parsing failed");
        }

        $header = $request['header'];
        $encryptedBody = $request['body'];

        // Kiểm tra Account ID, trả về mã 0003
        if (!isset($header['accountId']) || $header['accountId'] !== $this->accountId) {
            return $this->response('0003', "Incorrect supplier account information");
        }

        // Kiểm tra Signature, trả về mã 0002
        if (!isset($header['sign'])) {
            return $this->response('0002', "Wrong Signature: sign is missing");
        }
        $calculatedSign = TripHelper::generateSign($header, $encryptedBody, $this->signKey);
        if (strtolower($header['sign']) !== strtolower($calculatedSign)) {
            return $this->response('0002', "Wrong Signature: sign does not match");
        }

        $decrypted = TripHelper::decryptBody($encryptedBody, $this->aesKey, $this->aesIv);
        if ($decrypted === false) {
            // Lỗi BadPaddingException thường xảy ra ở đây. Trả về mã lỗi chung.
            return $this->response('0001', "Message parsing failed, possibly due to key mismatch.");
        }

        $body = json_decode($decrypted, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->response('0001', "Message parsing failed: Invalid JSON in body");
        }

        // Dispatch request
        $service = $header['serviceName'] ?? '';
        switch ($service) {
            case 'CreatePreOrder':        return $this->createPreOrder($body);
            case 'PayPreOrder':           return $this->payPreOrder($body);
            case 'CancelPreOrder':        return $this->cancelPreOrder($body);
            case 'CancelOrder':           return $this->cancelOrder($body);
            case 'SupplierBalanceQuery':  return $this->supplierBalanceQuery($body);
            case 'CreateOrder':           return $this->createOrder($body);
            case 'VerifyOrder':           return $this->verifyOrder($body);
            case 'SendVoucher':           return $this->sendVoucher($body);
            case 'DatePriceModify':       return $this->datePriceModify($body);
            case 'DateInventoryModify':   return $this->dateInventoryModify($body);
            case 'EditOrder':             return $this->editOrder($body);
            case 'EditOrderConfirm':      return $this->orderModifyConfirm($body);
            case 'QueryOrder':            return $this->orderInquiry($body);
            default:                      return $this->response('9999', "Unknown service: " . $service);
        }
    }
    private function createOrder($body)
    {
        // 1. KIỂM TRA TRÙNG LẶP (IDEMPOTENCY)
        if (!empty($body['otaOrderId'])) {
            $existingOrder = $this->orderModel->getByOtaOrderId($body['otaOrderId']);
            if ($existingOrder) {
                return $this->response(0, "Duplication request handled", [
                    'otaOrderId'          => $existingOrder['ota_order_id'],
                    'supplierOrderId'     => $existingOrder['supplier_order_id'],
                    'supplierConfirmType' => 1,
                    'voucherSender'       => 1,
                    'items' => [[
                        'itemId'               => $existingOrder['item_id'],
                        'isCredentialVouchers' => 0
                    ]]
                ]);
            }
        }

        // 2. XÁC THỰC DỮ LIỆU ĐẦU VÀO
        if (empty($body['items'][0])) { 
            return $this->response(1005, "Missing items array."); 
        }
        $item = $body['items'][0];
        if (empty($item['PLU']) || empty($item['quantity']) || empty($item['itemId'])) {
            return $this->response(1006, "PLU, quantity, or itemId is missing.");
        }
        
        // --- SỬA LỖI: LOGIC KIỂM TRA HÀNH KHÁCH LINH HOẠT ---
            $item = $body['items'][0];
            // 1. Kiểm tra xem mảng 'passengers' có tồn tại và có ít nhất một phần tử không.
            if (empty($item['passengers']) || !is_array($item['passengers']) || count($item['passengers']) === 0) {
                return $this->response('1005', "Missing Traveler Info: 'passengers' array is required.");
            }

            // 2. Lấy thông tin của hành khách đầu tiên.
            $firstPassenger = $item['passengers'][0];

            // 3. Theo cài đặt mới, chỉ cần kiểm tra tên, không cần ID (cardNo).
            if (empty($firstPassenger['name'])) {
                return $this->response('1005', "Missing Traveler Info: At least one passenger's name is required.");
            }
        // --- KẾT THÚC SỬA LỖI ---


        // 3. KIỂM TRA NGHIỆP VỤ (SẢN PHẨM, TỒN KHO, GIÁ)
        $product = $this->productModel->getByPlu($item['PLU']);
        if (!$product) { return $this->response('1001', "Product PLU does not exist."); }

        $useDate = $item['useStartDate'] ?? date('Y-m-d');
        $availability = $this->availabilityModel->getByProductAndDate($product['id'], $useDate);
        if (!$availability || $availability['inventory'] < $item['quantity']) {
            return $this->response(1003, "Insufficient inventory.");
        }
        if (isset($item['salePrice']) && abs((float)$item['salePrice'] - (float)$availability['sale_price']) > 0.01) {
            return $this->response('1007', "Product price does not match.");
        }

        // 4. TẠO ĐƠN HÀNG
        $this->orderModel->beginTransaction();
        try {
            $orderData = array_merge($body, ['status' => 'confirmed']);
            $supplierOrderId = $this->orderModel->createOrderAndItems($orderData);
            
            $this->availabilityModel->reduceInventory($product['id'], $useDate, $item['quantity']);
            $this->orderModel->commit();
        } catch (Exception $e) {
            $this->orderModel->rollBack();
            return $this->response(9999, "Failed to create order: " . $e->getMessage());
        }
        
        // 5. TRẢ VỀ PHẢN HỒI THÀNH CÔNG
        return $this->response(0, "create order succeed", [
            'otaOrderId'          => $body['otaOrderId'],
            'supplierOrderId'     => $supplierOrderId,
            'supplierConfirmType' => 1,
            'voucherSender'       => 1,
            'items'               => [[
                'itemId'               => $item['itemId'],
                'isCredentialVouchers' => 0 
            ]]
        ]);
    }

    private function createPreOrder(array $body)
    {
        // 1. Kiểm tra trùng lặp
        $existing = $this->orderModel->getBySequenceId($body['sequenceId']);
        if ($existing) {
            return $this->response(0, "Create pre-order succeed (Duplication)", [
                'otaOrderId'      => $body['otaOrderId'],
                'supplierOrderId' => $existing['supplier_order_id'],
            ]);
        }

        // 2. Validate dữ liệu
        if (empty($body['items'][0]['PLU'])) {
            return $this->response(1001, "Product PLU does not exist.");
        }
        $item = $body['items'][0];
        if (empty($item['quantity']) || $item['quantity'] <= 0) {
            return $this->response(1006, "Invalid item quantity.");
        }

        // 3. Kiểm tra sản phẩm và tồn kho
        $product = $this->productModel->getByPlu($item['PLU']);
        if (!$product) {
            return $this->response(1001, "Product not found");
        }
        $useDate = $item['useStartDate'] ?? date('Y-m-d');
        $avail = $this->availabilityModel->getByProductAndDate($product['id'], $useDate);
        if (!$avail || $avail['inventory'] < $item['quantity']) {
            return $this->response(1003, "Insufficient inventory");
        }

        // 4. Tạo đơn hàng
        $this->orderModel->beginTransaction();
        try {
            // Gọi hàm Model đã được chuẩn hóa. Status mặc định sẽ là 'pending'.
            $supplierOrderId = $this->orderModel->createOrderAndItems($body);
            $this->availabilityModel->reduceInventory($product['id'], $useDate, $item['quantity']);
            $this->orderModel->commit();
        } catch (\Exception $e) {
            $this->orderModel->rollBack();
            return $this->response(1006, "Failed to create pre-order: " . $e->getMessage());
        }

        return $this->response(0, "Create pre-order succeed", [
            'otaOrderId'      => $body['otaOrderId'],
            'supplierOrderId' => $supplierOrderId,
        ]);
    }

    private function supplierBalanceQuery($body)
    {
        // Lấy vendorId từ request nếu cần
        $vendorId = $body['vendorId'] ?? '';

        // Chuẩn bị dữ liệu phản hồi với các giá trị giả định
        $responseData = [
            'vendorId' => $vendorId,
            'prepaidAccountBalance' => 10000.00, // Số dư trả trước (ví dụ)
            'prepaidAccountBalanceCurrency' => 'CNY',
            'creditAccountBalance' => 50000.00, // Hạn mức tín dụng (ví dụ)
            'creditAccountBalanceCurrency' => 'CNY',
        ];

        return $this->response(0, "Operation succeeded", $responseData);
    }

    private function cancelOrder($body) {
        if (empty($body['otaOrderId']) || empty($body['items'][0]) || !isset($body['items'][0]['itemId']) || !isset($body['items'][0]['quantity'])) {
            return $this->response('2001', "Missing required fields: otaOrderId, itemId, or quantity.");
        }
        $otaOrderId = $body['otaOrderId'];
        $item = $body['items'][0];
        $itemId = $item['itemId'];
        $cancelQuantity = (int)$item['quantity'];
        $order = $this->orderModel->getByOtaOrderId($otaOrderId);
        if (!$order) {
            return $this->response('2001', "The order number does not exist.");
        }
        if (in_array($order['status'], ['completed', 'partially-completed'])) {
            return $this->response('2002', 'The order has been used and cannot be canceled.');
        }
        if (in_array($order['status'], ['canceled', 'partially-canceled'])) {
            return $this->response('0000', "Order already in a canceled state", [
                'supplierConfirmType' => 1,
                'items' => [['itemId' => $itemId, 'vouchers' => [['voucherId' => $itemId]]]]
            ]);
        }
        $currentQuantity = (int)$order['quantity'];
        if ($cancelQuantity <= 0 || $cancelQuantity > $currentQuantity) {
            return $this->response('2004', "Canceled quantity is incorrect. Attempted to cancel $cancelQuantity but only $currentQuantity remain.");
        }
        $this->orderModel->beginTransaction();
        try {
            $product = $this->productModel->getByPlu($order['plu']);
            if ($product) {
                $this->availabilityModel->restoreInventory($product['id'], $order['use_start_date'], $cancelQuantity);
            }
            
            // SỬA LỖI: Phân biệt rõ ràng việc cập nhật CSDL khi hủy toàn bộ và hủy một phần
            if ($cancelQuantity < $currentQuantity) {
                // Hủy một phần: Cập nhật cả số lượng và trạng thái
                $this->orderModel->partialCancel($otaOrderId, $cancelQuantity);
            } else {
                // Hủy toàn bộ: Cập nhật trạng thái và SET số lượng về 0
                $this->orderModel->fullCancel($otaOrderId);
            }

            $this->voucherModel->invalidateByOrderId($order['id']);
            $this->orderModel->commit();
        } catch (Exception $e) {
            $this->orderModel->rollBack();
            return $this->response('9999', "An internal error occurred during order cancellation.");
        }
        return $this->response('0000', "cancel succeed", [
            'supplierConfirmType' => 1,
            'items' => [[ 'itemId' => $itemId, 'vouchers' => [['voucherId' => $itemId]]]]
        ]);
    }
     private function orderInquiry(array $body) {
        if (empty($body['otaOrderId'])) { return $this->response('4001', "Missing otaOrderId"); }
        $order = $this->orderModel->getByOtaOrderId($body['otaOrderId']);
        if (!$order) { return $this->response('4001', "The order number does not exist"); }
        
        $map = [
            'pending' => 11, 'confirmed' => 2, 'partially-completed' => 7, 'completed' => 8,
            'canceled' => 5, 'precanceled' => 14, 'partially-canceled' => 4,
        ];
        $orderStatus = $map[$order['status']] ?? 2;
        $itemId = (!empty($order['item_id']) && $order['item_id'] != '0') ? (string)$order['item_id'] : "0";
        $originalQuantity = (int)($order['original_quantity'] ?? $order['quantity']);
        $useQuantity = (int)$order['used_quantity'];
        $currentQuantity = (int)$order['quantity'];
        $cancelQuantity = $originalQuantity - $currentQuantity;
        $passengerStatus = (in_array($orderStatus, [5, 14])) ? 2 : 0;
        
        $items = [[
            'itemId' => $itemId, 'useStartDate' => $order['use_start_date'],
            'useEndDate' => $order['use_end_date'] ?? $order['use_start_date'],
            'orderStatus' => $orderStatus, 'quantity' => $originalQuantity,
            'useQuantity' => $useQuantity, 'cancelQuantity' => $cancelQuantity,
            'passengers' => [['passengerId' => $itemId, 'passengerStatus' => $passengerStatus]],
            'vouchers' => [],
        ]];

        return $this->response('0000', "Operation succeeded", [
            'otaOrderId' => $order['ota_order_id'],
            'supplierOrderId' => $order['supplier_order_id'],
            'items' => $items,
        ]);
    }

    private function payPreOrder(array $body)
    {
        // 1. Tìm bản ghi pre-order theo sequenceId
        $order = $this->orderModel->getBySequenceId($body['sequenceId']);
        if (!$order) {
            return $this->response(2001, "The order number does not exist");
        }

        // 2. Lấy itemId từ request body
        $itemId = $body['items'][0]['itemId'] ?? null;
        if (empty($itemId)) {
            return $this->response(1005, "Missing required information: itemId");
        }

        // 3. Cập nhật status='confirmed' và item_id vào CSDL
        // Hãy đảm bảo bạn có hàm này trong lớp Order
        $this->orderModel->updateOrderOnPayment(
            $order['ota_order_id'],
            $itemId,
            'confirmed' // <-- SỬA THÀNH 'confirmed' ĐỂ KHỚP VỚI CSDL
        );

        // Cập nhật lại thông tin order sau khi update
        $updatedOrder = $this->orderModel->getByOtaOrderId($order['ota_order_id']);

        // 4. Build mảng items để phản hồi
        $responseItems = [[
            'itemId'                => $updatedOrder['item_id'], // Lấy itemId chắc chắn từ CSDL
            'isCredentialVouchers'  => 0,
            'passengerVouchers'     => [],
            'inventorys'            => []
        ]];

        // 5. Trả về response thành công
        return $this->response(0, "Pay pre-order succeed", [
            'otaOrderId'            => $updatedOrder['ota_order_id'],
            'supplierOrderId'       => $updatedOrder['supplier_order_id'],
            'supplierConfirmType'   => 1,
            'voucherSender'         => 1,
            'items'                 => $responseItems,
        ]);
    }





    private function sendCancelOrderConfirmation($otaOrderId, $supplierOrderId, $itemId, $resultCode, $resultMessage)
    {
        $url = rtrim($this->tripCallbackUrl, '/') . "/CancelOrderConfirm.do";

        $body = [
            'otaOrderId'           => $otaOrderId,
            'supplierOrderId'      => $supplierOrderId,
            'confirmResultCode'    => str_pad($resultCode, 4, '0', STR_PAD_LEFT),
            'confirmResultMessage' => $resultMessage,
            'items' => [['itemId' => $itemId]]
        ];

        $this->sendNotificationToTrip('CancelOrderConfirm', $url, $body);
    }



    private function cancelPreOrder(array $body): void
    {
        // 1. Validate input
        if (empty($body['sequenceId']) || empty($body['otaOrderId'])) {
            $this->response(1001, "Missing required fields");
            return; // Thêm return để chắc chắn dừng lại
        }

        // 2. Lấy order theo sequenceId
        $order = $this->orderModel->getBySequenceId($body['sequenceId']);
        if (!$order) {
            $this->response(2001, "Order not found");
            return;
        }

        // 3. Nếu chưa hủy thì chuyển trạng thái, còn đã hủy rồi thì coi như duplicate
        if ($order['status'] !== 'canceled' && $order['status'] !== 'precanceled') {
            // ---- SỬA LỖI TẠI ĐÂY ----
            // Sử dụng trạng thái mới cho việc hủy đơn hàng nháp
            $this->orderModel->updateStatus($order['ota_order_id'], 'precanceled');
        }

        // 4. Build response payload
        $payload = [
            'otaOrderId'      => $order['ota_order_id'],
            'supplierOrderId' => $order['supplier_order_id'],
        ];

        // 5. Trả về 0000 + encrypted body
        $this->response(0, "Cancel pre-order succeed", $payload);
    }



    private function dateInventoryModify($body) {



        if (!isset($body['supplierOptionId'], $body['dateType'], $body['inventorys'])) {



            return $this->response(1001, "Missing required fields");



        }







        $optionId = $body['supplierOptionId'] ?? $body['otaOptionId'] ?? null;



        if (!$optionId) {



            return $this->response(1002, "Missing option ID");



        }







        $product = $this->productModel->getByPlu($optionId);



        if (!$product) {



            return $this->response(1003, "Product not found");



        }







        $dateType = $body['dateType'];



        $processed = 0;







        foreach ($body['inventorys'] as $inv) {



            if (!isset($inv['quantity'])) {



                return $this->response(1004, "Missing inventory quantity");



            }







            if ($dateType === 'DATE_REQUIRED') {



                if (empty($inv['date'])) {



                    return $this->response(1005, "Missing date for DATE_REQUIRED");



                }



                $this->availabilityModel->UpdateInventory(



                    $product['id'],



                    $inv['date'],



                    $inv['quantity']



                );



            } else if ($dateType === 'DATE_NOT_REQUIRED') {



                $this->availabilityModel->update(



                    $product['id'],



                    null,



                    null,



                    null,



                    $inv['quantity']



                );



            } else {



                return $this->response(1006, "Invalid dateType");



            }



            $processed++;



        }







        return $this->response(0, "Operation succeeded", ['updated' => $processed]);



    }



    private function datePriceModify($body) {



        if (empty($body['sequenceId']) || empty($body['dateType']) || empty($body['prices'])) {



            return $this->response(1001, "Missing required fields");



        }







        // Ưu tiên supplierOptionId, nếu không có thì dùng otaOptionId



        $optionId = $body['supplierOptionId'] ?? $body['otaOptionId'] ?? null;



        if (!$optionId) {



            return $this->response(1002, "Missing option ID");



        }







        $product = $this->productModel->getByPlu($optionId);



        if (!$product) {



            return $this->response(1003, "Product not found");



        }







        $dateType = $body['dateType'];



        $processed = 0;







        foreach ($body['prices'] as $price) {



            if (!isset($price['salePrice'], $price['costPrice'])) {



                return $this->response(1004, "Missing price fields");



            }







            // Nếu là DATE_REQUIRED thì phải có date



            if ($dateType === 'DATE_REQUIRED') {



                if (empty($price['date'])) {



                    return $this->response(1005, "Missing date for DATE_REQUIRED");



                }







                $this->availabilityModel->UpdatePrice(



                    $product['id'],



                    $price['date'],



                    $price['salePrice'],



                    $price['costPrice'],



                    isset($price['inventory']) ? $price['inventory'] : 0



                );



                $processed++;



            } else if ($dateType === 'DATE_NOT_REQUIRED') {



                // Đối với sản phẩm không cần ngày, lưu theo NULL hoặc ngày ảo



                $this->availabilityModel->update(



                    $product['id'],



                    null,



                    $price['salePrice'],



                    $price['costPrice'],



                    isset($price['inventory']) ? $price['inventory'] : 0



                );



                $processed++;



            } else {



                return $this->response(1006, "Invalid dateType");



            }



        }







        return $this->response(0, "Operation succeeded", ['updated' => $processed]);



    }


   private function editOrder($body) {



        if (!isset($body['otaOrderId'], $body['items'][0]['originUseStartDate'], $body['items'][0]['targetUseStartDate'])) {



            return $this->response(7001, "Missing required fields");



        }







        $order = $this->orderModel->getByOtaOrderId($body['otaOrderId']);



        if (!$order) {



            return $this->response(7002, "Order not found");



        }







        $origin = $body['items'][0]['originUseStartDate'];



        $target = $body['items'][0]['targetUseStartDate'];







        if ($order['use_start_date'] !== $origin) {



            return $this->response(7003, "Original date does not match order");



        }







        $product = $this->productModel->getByPlu($order['plu']);



        if (!$product) {



            return $this->response(7004, "Product not found");



        }







        $availability = $this->availabilityModel->getByProductAndDate($product['id'], $target);



        if (!$availability || $availability['inventory'] < $order['quantity']) {



            return $this->response(7005, "Target date has insufficient inventory");



        }







        $this->availabilityModel->restoreInventory($product['id'], $origin, $order['quantity']);



        $this->availabilityModel->reduceInventory($product['id'], $target, $order['quantity']);



        $this->orderModel->updateUseStartDate($order['ota_order_id'], $target);







        // Gọi callback xác nhận về Trip



        $this->confirmOrderModification(



            $order['ota_order_id'],



            $order['supplier_order_id'],



            $body['items'][0]['itemId'],



            1 // 1 = accept



        );







        return $this->response(0, "Operation succeeded", [



            'supplierConfirmType' => 1,



            'items' => [



                ['itemId' => $body['items'][0]['itemId']]



            ]



        ]);



    }



     private function orderModifyConfirm($body) {



        if (!isset($body['otaOrderId'], $body['items'], $body['confirmResultCode'])) {



            return $this->response(9001, "Missing required fields");



        }







        $order = $this->orderModel->getByOtaOrderId($body['otaOrderId']);



        if (!$order) {



            return $this->response(9002, "Order not found");



        }







        // Ghi log thông tin xác nhận chỉnh sửa đơn hàng



        $log = "[" . date('Y-m-d H:i:s') . "] EditOrderConfirm received: " . json_encode($body, JSON_UNESCAPED_UNICODE) . "\n";



        file_put_contents(__DIR__ . '/../log/order_edit_confirm.log', $log, FILE_APPEND);







        return $this->response(0, "Operation succeeded");



    }







    private function confirmOrderModification($otaOrderId, $supplierOrderId, $itemId, $result, $failReason = '') {



        $url = rtrim($this->tripCallbackUrl, '/') . "/OrderModifyConfirm.do";







        $body = [



            'otaOrderId' => $otaOrderId,



            'supplierOrderId' => $supplierOrderId,



            'result' => $result, // 1 = accept, 2 = reject



            'failReason' => $failReason,



            'items' => [



                [



                    'itemId' => $itemId,



                    'confirmResult' => $result



                ]



            ]



        ];







        $requestTime = date('Y-m-d H:i:s');



        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);



        $bodyEncrypted = TripHelper::encryptBody($bodyJson, $this->aesKey, $this->aesIv);







        $header = [



            'accountId' => $this->accountId,



            'serviceName' => 'OrderModifyConfirm',



            'requestTime' => $requestTime,



            'version' => '1.0',



            'sign' => TripHelper::generateSign([



                'accountId' => $this->accountId,



                'serviceName' => 'OrderModifyConfirm',



                'requestTime' => $requestTime,



                'version' => '1.0'



            ], $bodyEncrypted, $this->signKey)



        ];







        $payload = json_encode([



            'header' => $header,



            'body' => $bodyEncrypted



        ], JSON_UNESCAPED_UNICODE);







        $ch = curl_init($url);



        curl_setopt($ch, CURLOPT_POST, true);



        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);



        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);



        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);



        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);



        $response = curl_exec($ch);



        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);



        $error = curl_error($ch);



        curl_close($ch);







        // Ghi log rõ hơn



        $log = "[" . date('Y-m-d H:i:s') . "] OrderModifyConfirm response:\n";



        $log .= "HTTP Code: $httpCode\n";



        $log .= "Curl Error: $error\n";



        $log .= "Response: $response\n";



        file_put_contents(__DIR__ . '/../log/order_modify_confirm.log', $log, FILE_APPEND);



    }



      private function sendVoucher($body) {



        if (empty($body['otaOrderId'])) {



            return $this->response(5001, "Missing otaOrderId");



        }







        $order = $this->orderModel->getByOtaOrderId($body['otaOrderId']);



        if (!$order) {



            return $this->response(5002, "Order not found");



        }







        if ($order['status'] !== 'confirmed') {



            return $this->response(5003, "Order not confirmed");



        }







        $voucherCode = 'VCHR_' . time();



        $this->voucherModel->create($order['id'], $voucherCode);







        return $this->response(0, "Operation succeeded", [



            'voucherCode' => $voucherCode,



            'status' => 'delivered'



        ]);



    }







    private function verifyOrder($body)
    {
        // 1. Basic field checks
        if (empty($body['items'][0])) {
            return $this->response(1005, "Missing items array.");
        }
        $item = $body['items'][0];
        if (empty($item['PLU']) || empty($item['quantity'])) {
            return $this->response(1006, "PLU or quantity is missing.");
        }

        $item = $body['items'][0];
        if (empty($item['passengers']) || !is_array($item['passengers']) || count($item['passengers']) === 0) {
            return $this->response('1005', "Missing Traveler Info: 'passengers' array is required.");
        }
        $firstPassenger = $item['passengers'][0];
        if (empty($firstPassenger['name'])) {
            return $this->response('1005', "Missing Traveler Info: At least one passenger's name is required.");
        }

        // 4. Other checks (Product, Inventory, Price)
        $product = $this->productModel->getByPlu($item['PLU']);
        if (!$product) {
            return $this->response('1001', "Product PLU does not exist.");
        }
        
        $useDate = $item['useStartDate'] ?? date('Y-m-d');
        $availability = $this->availabilityModel->getByProductAndDate($product['id'], $useDate);
        if (!$availability) {
            return $this->response(1003, "Product is not available for the selected date.");
        }
        
        if (isset($item['salePrice']) && abs((float)$item['salePrice'] - (float)$availability['sale_price']) > 0.01) {
            return $this->response('1007', "Product price does not match.");
        }

        if ($availability['inventory'] < $item['quantity']) {
            return $this->response(1003, "Insufficient inventory.");
        }

        // 5. If all checks pass, return success
        $responseData = [
            'items' => [[
                'PLU' => $item['PLU'],
                'inventorys' => [[
                    'useDate' => $useDate,
                    'quantity' => $availability['inventory'] - $item['quantity']
                ]]
            ]]
        ];

        return $this->response(0, "verify succeed", $responseData);
    }


     private function notifyTrip($otaOrderId, $supplierOrderId, $voucherCode) {



        $url = rtrim($this->tripCallbackUrl, '/') . "/PayPreOrderConfirm.do";







        $body = [



            'otaOrderId' => $otaOrderId,



            'supplierOrderId' => $supplierOrderId,



            'voucherCode' => $voucherCode,



            'status' => 'confirmed'



        ];







        $requestTime = date('Y-m-d H:i:s');



        $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);



        $bodyEncrypted = TripHelper::encryptBody($bodyJson, $this->aesKey, $this->aesIv);







        $header = [



            'accountId' => $this->accountId,



            'serviceName' => 'PayPreOrderConfirm',



            'requestTime' => $requestTime,



            'version' => '1.0',



            'sign' => TripHelper::generateSign([



                'accountId' => $this->accountId,



                'serviceName' => 'PayPreOrderConfirm',



                'requestTime' => $requestTime,



                'version' => '1.0'



            ], $bodyEncrypted, $this->signKey)



        ];







        $payload = json_encode([



            'header' => $header,



            'body' => $bodyEncrypted



        ], JSON_UNESCAPED_UNICODE);







        $ch = curl_init($url);



        curl_setopt($ch, CURLOPT_POST, true);



        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);



        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);



        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);



        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Cho phép theo dõi redirect







        $response = curl_exec($ch);



        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);



        curl_close($ch);







        // Ghi log để kiểm tra kết quả phản hồi



        $log = "\n[" . date('Y-m-d H:i:s') . "] URL: $url\n";



        $log .= "Payload: $payload\n";



        $log .= "HTTP Code: $httpCode\n";



        $log .= "Response: $response\n";



        file_put_contents(__DIR__ . '/../log/pay_callback.log', $log, FILE_APPEND);



    }





 private function response($code, $message, $data = []) {
        header('Content-Type: application/json');
        
        // Body của phản hồi luôn là một đối tượng JSON được mã hóa AES
        $bodyJson = empty($data) ? '{}' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bodyEncrypted = TripHelper::encryptBody($bodyJson, $this->aesKey, $this->aesIv);

        if ($bodyEncrypted === false) {
            // Xử lý lỗi mã hóa nội bộ
            $header = [
                'resultCode'    => '9999',
                'resultMessage' => 'Internal encryption error'
            ];
            echo json_encode(['header' => $header, 'body' => ''], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ======================== SỬA LỖI TẠI ĐÂY ========================
        // Header của một PHẢN HỒI chỉ cần chứa resultCode và resultMessage.
        // Nó không cần accountId, serviceName, sign...
        $responseHeader = [
            'resultCode'    => str_pad($code, 4, '0', STR_PAD_LEFT),
            'resultMessage' => $message,
        ];
        // ====================== KẾT THÚC SỬA LỖI ======================

        $finalResponse = [
            'header' => $responseHeader,
            'body'   => $bodyEncrypted
        ];

        echo json_encode($finalResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }



}



?>