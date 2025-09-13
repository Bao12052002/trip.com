<?php

class Order {

    private $conn;
    public function __construct(PDO $pdo_connection) {
        $this->conn = $pdo_connection;
    }

    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    public function commit() {
        return $this->conn->commit();
    }

    public function rollBack() {
        return $this->conn->rollBack();
    }

    public function getByOtaOrderId($otaOrderId) {
        $query = "SELECT * FROM tbl_trip_orders WHERE ota_order_id = :otaOrderId LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':otaOrderId' => $otaOrderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function updateStatus($ota_order_id, $status) {
        $query = "UPDATE tbl_trip_orders SET status = :status WHERE ota_order_id = :ota_order_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':status' => $status, ':ota_order_id' => $ota_order_id]);
    }

    /**
     * Cập nhật trạng thái và số lượng đã sử dụng của đơn hàng.
     * Hàm này được bọc trong một transaction để đảm bảo tính toàn vẹn dữ liệu.
     * @param string $otaOrderId
     * @param string $newStatus
     * @param int $newUsedQuantity
     * @return bool
     */
    public function updateUsage(string $otaOrderId, string $newStatus, int $newUsedQuantity): bool
    {
        $this->conn->beginTransaction();
        try {
            $query = "UPDATE tbl_trip_orders 
                      SET status = :status, used_quantity = :used_quantity 
                      WHERE ota_order_id = :ota_order_id";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindValue(':status', $newStatus, PDO::PARAM_STR);
            $stmt->bindValue(':used_quantity', $newUsedQuantity, PDO::PARAM_INT);
            $stmt->bindValue(':ota_order_id', $otaOrderId, PDO::PARAM_STR);

            $success = $stmt->execute();
            
            // Chỉ commit nếu update thành công VÀ có dòng bị ảnh hưởng
            if ($success && $stmt->rowCount() > 0) {
                $this->conn->commit();
                return true;
            } else {
                // Nếu không có dòng nào được cập nhật, rollback
                $this->conn->rollBack();
                return false;
            }
        } catch (PDOException $e) {
            // Nếu có lỗi CSDL, rollback
            $this->conn->rollBack();
            // Ghi lại lỗi để gỡ lỗi (tùy chọn)
            // error_log('Update Usage Failed: ' . $e->getMessage());
            return false;
        }
    }

    public function partialCancel(string $otaOrderId, int $cancelQuantity): bool
    {
        $query = "UPDATE tbl_trip_orders 
                  SET quantity = quantity - :cancelQuantity, status = 'partially-canceled' 
                  WHERE ota_order_id = :otaOrderId";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':cancelQuantity' => $cancelQuantity,
            ':otaOrderId' => $otaOrderId
        ]);
    }

    public function getBySupplierOrderId($supplierOrderId) {
        $query = "SELECT * FROM tbl_trip_orders WHERE supplier_order_id = :supplierOrderId LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':supplierOrderId', $supplierOrderId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getBySequenceId($sequence_id) {
        $query = "SELECT * FROM tbl_trip_orders WHERE sequence_id = :sequence_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sequence_id', $sequence_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateOrderOnPayment(string $otaOrderId, string $itemId, string $status): bool
    {
        $query = "UPDATE tbl_trip_orders SET status = :status, item_id = :item_id 
                  WHERE ota_order_id = :ota_order_id AND (item_id IS NULL OR item_id = '0' OR item_id = '')";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':status'       => $status,
            ':item_id'      => $itemId,
            ':ota_order_id' => $otaOrderId
        ]);
    }

    public function updateUseStartDate($otaOrderId, $newDate) {
        $query = "UPDATE tbl_trip_orders SET use_start_date = :newDate WHERE ota_order_id = :otaOrderId";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':newDate', $newDate);
        $stmt->bindParam(':otaOrderId', $otaOrderId);
        return $stmt->execute();
    }
    
    public function createOrderAndItems(array $data): string
    {
        $item = $data['items'][0];
        $status = $data['status'] ?? 'pending';
        $otaOrderId = $data['otaOrderId'] ?? null;
        $sequenceId = $data['sequenceId'] ?? null;
        $supplierOrderId = 'SUP_' . uniqid();
        $quantity = (int)($item['quantity'] ?? 0);
        $totalAmount = (float)($item['price'] ?? 0.0) * $quantity;
        $useStartDate = $item['useStartDate'] ?? date('Y-m-d');

        $sqlOrder = "
            INSERT INTO tbl_trip_orders (
                ota_order_id, supplier_order_id, sequence_id, plu, 
                quantity, original_quantity, total_amount, use_start_date, status, used_quantity
            ) VALUES (
                :ota_order_id, :supplier_order_id, :sequence_id, :plu, 
                :quantity, :original_quantity, :total_amount, :use_start_date, :status, 0
            )";
        $stmtOrder = $this->conn->prepare($sqlOrder);
        $stmtOrder->execute([
            ':ota_order_id'      => $otaOrderId,
            ':supplier_order_id' => $supplierOrderId,
            ':sequence_id'       => $sequenceId,
            ':plu'               => $item['PLU'],
            ':quantity'          => $quantity,
            ':original_quantity' => $quantity,
            ':total_amount'      => $totalAmount,
            ':use_start_date'    => $useStartDate,
            ':status'            => $status,
        ]);

        $orderId = $this->conn->lastInsertId();
        if (!$orderId) {
            throw new RuntimeException("Failed to get last insert ID for order.");
        }

        if (!empty($item['itemId'])) {
            $sqlUpdate = "UPDATE tbl_trip_orders SET item_id = :item_id WHERE id = :id";
            $stmtUpdate = $this->conn->prepare($sqlUpdate);
            $stmtUpdate->execute([':item_id' => $item['itemId'], ':id' => $orderId]);
        }

        if (!empty($data['contacts']) && is_array($data['contacts'])) {
            $sqlContact = "INSERT INTO tbl_trip_contacts (order_id, name, mobile) VALUES (:order_id, :name, :mobile)";
            $stmtContact = $this->conn->prepare($sqlContact);
            foreach ($data['contacts'] as $c) {
                $stmtContact->execute([':order_id' => $orderId, ':name' => $c['name'] ?? null, ':mobile' => $c['mobile'] ?? null]);
            }
        }

        if (!empty($item['passengers']) && is_array($item['passengers'])) {
            $sqlPassenger = "INSERT INTO tbl_trip_passengers (order_id, name, card_type, card_no) VALUES (:order_id, :name, :card_type, :card_no)";
            $stmtPassenger = $this->conn->prepare($sqlPassenger);
            foreach ($item['passengers'] as $p) {
                $stmtPassenger->execute([':order_id' => $orderId, ':name' => $p['name'] ?? null, ':card_type' => $p['cardType'] ?? null, ':card_no' => $p['cardNo'] ?? null]);
            }
        }
        
        return $supplierOrderId;
    }

    public function getOrderForResponse($otaOrderId) {
        $order = $this->getByOtaOrderId($otaOrderId);
        if (!$order) return null;

        return [
            'otaOrderId' => $order['ota_order_id'],
            'supplierOrderId' => $order['supplier_order_id'],
            'itemId' => $order['item_id']
        ];
    }
    public function fullCancel(string $otaOrderId): bool
{
    $query = "UPDATE tbl_trip_orders 
              SET quantity = 0, status = 'canceled' 
              WHERE ota_order_id = :otaOrderId";
    $stmt = $this->conn->prepare($query);
    return $stmt->execute([':otaOrderId' => $otaOrderId]);
}
}
