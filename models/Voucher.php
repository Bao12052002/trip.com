<?php
class Voucher {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($orderId, $voucherCode) {
        $query = "INSERT INTO tbl_trip_vouchers (order_id, voucher_code, delivered) VALUES (:order_id, :voucher_code, 1)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':order_id', $orderId);
        $stmt->bindParam(':voucher_code', $voucherCode);
        return $stmt->execute();
    }

    public function markDelivered($id) {
        $query = "UPDATE tbl_trip_vouchers SET delivered = 1 WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
     public function getByOrderId($orderId) {
        $query = "SELECT * FROM tbl_trip_vouchers WHERE order_id = :orderId LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':orderId', $orderId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function invalidateByOrderId($orderId) {
        $query = "UPDATE tbl_trip_vouchers SET delivered = '0' WHERE order_id = :orderId";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':orderId', $orderId);
        return $stmt->execute();
    }
}
?>