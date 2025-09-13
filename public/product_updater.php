<?php
/**
 * Tệp này dùng để kích hoạt việc cập nhật Giá và Tồn kho thủ công.
 *
 * Cách sử dụng:
 * 1. Cập nhật giá (CÓ NGÀY): /product_updater.php?action=price&plu={PLU}&date=2025-07-10&salePrice=150&costPrice=120
 * 2. Cập nhật giá (KHÔNG NGÀY): /product_updater.php?action=price&plu={PLU}&salePrice=150&costPrice=120
 * 3. Cập nhật tồn kho (CÓ NGÀY): /product_updater.php?action=inventory&plu={PLU}&date=2025-07-10&quantity=100
 * 4. Cập nhật tồn kho (KHÔNG NGÀY): /product_updater.php?action=inventory&plu={PLU}&quantity=100
 */

// Bật hiển thị lỗi để dễ dàng gỡ lỗi
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Nạp các file cần thiết
require_once '../config/db.php';
require_once '../helpers/TripHelper.php';
require_once '../helpers/ProductNotifier.php';

try {
    // Lấy các tham số từ URL
    $action = $_GET['action'] ?? '';
    $plu = $_GET['plu'] ?? '';
    $dates = isset($_GET['date']) ? (array)$_GET['date'] : []; // Có thể rỗng

    if (empty($action) || empty($plu)) {
        die("Vui lòng cung cấp 'action' và 'plu'.");
    }

    // Khởi tạo Notifier
    $database = new Database();
    $notifier = new ProductNotifier($database);

    echo "<h1>Đang thực hiện: " . htmlspecialchars(ucfirst($action)) . " Update</h1>";
    echo "<p>PLU: " . htmlspecialchars($plu) . "</p>";

    $response = null;
    $isDated = !empty($dates);
    $dateType = $isDated ? 'DATE_REQUIRED' : 'DATE_NOT_REQUIRED';
    echo "<p>Date Type: " . $dateType . "</p>";

    if ($action === 'price') {
        $salePrice = $_GET['salePrice'] ?? null;
        $costPrice = $_GET['costPrice'] ?? null;
        if ($salePrice === null || $costPrice === null) {
            die("Vui lòng cung cấp 'salePrice' và 'costPrice'.");
        }
        
        $priceUpdates = [];
        if ($isDated) {
            echo "<p>Dates: " . htmlspecialchars(implode(', ', $dates)) . "</p>";
            foreach ($dates as $date) {
                $priceUpdates[] = ['date' => $date, 'salePrice' => (float)$salePrice, 'costPrice' => (float)$costPrice];
            }
        } else {
            // Không có ngày, chỉ có giá
            $priceUpdates[] = ['salePrice' => (float)$salePrice, 'costPrice' => (float)$costPrice];
        }
        
        echo "<p>Sale Price: " . htmlspecialchars($salePrice) . "</p>";
        echo "<p>Cost Price: " . htmlspecialchars($costPrice) . "</p>";
        
        $response = $notifier->sendPriceUpdate($plu, $priceUpdates, $dateType);

    } elseif ($action === 'inventory') {
        $quantity = $_GET['quantity'] ?? null;
        if ($quantity === null) {
            die("Vui lòng cung cấp 'quantity'.");
        }
        
        $inventoryUpdates = [];
        if ($isDated) {
            echo "<p>Dates: " . htmlspecialchars(implode(', ', $dates)) . "</p>";
            foreach ($dates as $date) {
                $inventoryUpdates[] = ['date' => $date, 'quantity' => (int)$quantity];
            }
        } else {
            // Không có ngày, chỉ có tồn kho
            $inventoryUpdates[] = ['quantity' => (int)$quantity];
        }

        echo "<p>Quantity: " . htmlspecialchars($quantity) . "</p>";
        
        $response = $notifier->sendInventoryUpdate($plu, $inventoryUpdates, $dateType);

    } else {
        die("Hành động không hợp lệ.");
    }

    echo "<h2>Phản hồi từ Trip.com:</h2>";
    echo "<pre>" . htmlspecialchars(json_encode(json_decode($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

} catch (Throwable $e) {
    echo "<h1>Lỗi máy chủ nghiêm trọng</h1>";
    echo "<pre>";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "</pre>";
}
