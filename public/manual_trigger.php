<?php
/**
 * Tệp này dùng để kích hoạt các hành động thủ công cho việc test với Sandbox.
 *
 * Cách sử dụng:
 * 1. Sử dụng toàn bộ: /manual_trigger.php?action=consume&otaOrderId={ID_ĐƠN_HÀNG}
 * 2. Sử dụng một phần: /manual_trigger.php?action=consume&otaOrderId={ID_ĐƠN_HÀNG}&quantity=1
 * 3. Từ chối hủy:    /manual_trigger.php?action=reject_cancel&otaOrderId={ID_ĐƠN_HÀNG}
 */

// Nạp các file cần thiết
require_once '../config/db.php';
require_once '../helpers/TripHelper.php';
require_once '../models/Order.php';
require_once '../helpers/TripNotifier.php'; // Sử dụng Notifier mới

// Bật hiển thị lỗi để dễ dàng gỡ lỗi nếu có vấn đề
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Lấy các tham số từ URL
    $action = $_GET['action'] ?? '';
    $otaOrderId = $_GET['otaOrderId'] ?? '';
    $quantity = isset($_GET['quantity']) ? (int)$_GET['quantity'] : null;

    if (empty($action) || empty($otaOrderId)) {
        die("Vui lòng cung cấp 'action' và 'otaOrderId' trên URL.");
    }

    // Khởi tạo các đối tượng cần thiết
    $database = new Database();
    $pdo = $database->getConnection();
    if (!$pdo) {
         die("Lỗi nghiêm trọng: Không thể kết nối đến cơ sở dữ liệu.");
    }
    $orderModel = new Order($pdo);
    $notifier = new TripNotifier($database);

    // Lấy thông tin đơn hàng từ CSDL của bạn
    $order = $orderModel->getByOtaOrderId($otaOrderId);
    if (!$order) {
        die("Lỗi: Không tìm thấy đơn hàng với otaOrderId = " . htmlspecialchars($otaOrderId));
    }

    // Thực hiện hành động dựa trên tham số 'action'
    if ($action === 'consume') {
        echo "<h1>Đang xử lý thông báo ĐÃ SỬ DỤNG...</h1>";

        $totalQuantity = (int)($order['original_quantity'] ?? $order['quantity']);
        $alreadyUsedQuantity = (int)$order['used_quantity'];
        $remainingQuantity = $totalQuantity - $alreadyUsedQuantity;

        // Nếu không chỉ định số lượng, mặc định là dùng hết số còn lại
        $consumeNow = ($quantity === null) ? $remainingQuantity : $quantity;

        if ($consumeNow <= 0 || $consumeNow > $remainingQuantity) {
            die("Lỗi: Số lượng sử dụng không hợp lệ. (Số lượng còn lại: $remainingQuantity, Số lượng yêu cầu: $consumeNow)");
        }

        $newUsedQuantity = $alreadyUsedQuantity + $consumeNow;
        $newStatus = ($newUsedQuantity >= $totalQuantity) ? 'completed' : 'partially-completed';
        
        // Bước 1: Cập nhật trạng thái và số lượng trong CSDL của bạn
        $updateResult = $orderModel->updateUsage($otaOrderId, $newStatus, $newUsedQuantity);
        if (!$updateResult) {
            die("Lỗi nghiêm trọng: Không thể cập nhật trạng thái đơn hàng trong cơ sở dữ liệu của bạn.");
        }
        
        // Bước 2: Gửi callback đến Trip.com
        // Lấy lại thông tin đơn hàng sau khi cập nhật để đảm bảo dữ liệu là mới nhất
        $updatedOrder = $orderModel->getByOtaOrderId($otaOrderId);
        $notifier->sendConsumedNotice($updatedOrder, $consumeNow);

        echo "Đã gửi yêu cầu OrderConsumedNotice đến Trip.com. Trạng thái đơn hàng của bạn đã được cập nhật thành công.";

    } elseif ($action === 'reject_cancel') {
        echo "<h1>Đang gửi thông báo TỪ CHỐI HỦY...</h1>";
        
        // Gửi callback từ chối hủy đến Trip.com
        $notifier->sendCancelConfirmation(
            $order, 
            '2002', // Mã lỗi: Đã sử dụng
            'The order has been used and cannot be canceled.'
        );

        echo "Đã gửi callback TỪ CHỐI hủy đơn đến Trip.com.";
    } else {
        die("Hành động không hợp lệ. Chỉ chấp nhận 'consume' hoặc 'reject_cancel'.");
    }

} catch (Throwable $e) {
    // Bắt tất cả các lỗi để hiển thị chi tiết, giúp gỡ lỗi dễ dàng hơn
    echo "<h1>Lỗi máy chủ nghiêm trọng</h1>";
    echo "<p>Vui lòng kiểm tra lại các tệp require_once và cấu hình cơ sở dữ liệu.</p>";
    echo "<pre>";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace: \n" . $e->getTraceAsString();
    echo "</pre>";
}
