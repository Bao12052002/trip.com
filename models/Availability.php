<?php
class Availability {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function update($productId, $date, $salePrice, $costPrice, $inventory = 0) {
        $query = "INSERT INTO tbl_trip_availability (product_id, date, sale_price, cost_price, inventory, updated_at)
                  VALUES (:product_id, :date, :sale_price, :cost_price, :inventory, NOW())
                  ON DUPLICATE KEY UPDATE
                      sale_price = VALUES(sale_price),
                      cost_price = VALUES(cost_price),
                      inventory = VALUES(inventory),
                      updated_at = NOW()";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $productId);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':sale_price', $salePrice);
        $stmt->bindParam(':cost_price', $costPrice);
        $stmt->bindParam(':inventory', $inventory);

        return $stmt->execute();
    }
    public function UpdateInventory($productId, $date, $inventory = 0) {
        $query = "INSERT INTO tbl_trip_availability (product_id, date, inventory, updated_at)
                  VALUES (:product_id, :date,  :inventory, NOW())
                  ON DUPLICATE KEY UPDATE
                      inventory = VALUES(inventory),
                      updated_at = NOW()";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $productId);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':inventory', $inventory);
        return $stmt->execute();
    }
    public function UpdatePrice($productId, $date, $salePrice, $costPrice) {
        $query = "INSERT INTO tbl_trip_availability (product_id, date, sale_price, cost_price, updated_at)
                  VALUES (:product_id, :date, :sale_price, :cost_price, NOW())
                  ON DUPLICATE KEY UPDATE
                        sale_price = VALUES(sale_price),
                        cost_price = VALUES(cost_price),
                        updated_at = NOW()";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $productId);
        $stmt->bindParam(':date', $date);
        $stmt->bindParam(':sale_price', $salePrice);
        $stmt->bindParam(':cost_price', $costPrice);
        return $stmt->execute();
    }
    
    public function getByProductAndDate($productId, $date) {
        $query = "SELECT * FROM tbl_trip_availability WHERE product_id = :product_id AND date = :date LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':product_id', $productId);
        $stmt->bindParam(':date', $date);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function reduceInventory($productId, $date, $quantity) {
        $query = "UPDATE tbl_trip_availability SET inventory = inventory - :quantity 
                  WHERE product_id = :product_id AND date = :date AND inventory >= :quantity";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':product_id', $productId);
        $stmt->bindParam(':date', $date);
        return $stmt->execute();
    }
    public function restoreInventory(int $productId, string $date, int $quantity): bool
    {
        // Câu lệnh SQL cộng dồn vào cột inventory
        $query = "UPDATE tbl_trip_availability 
                SET inventory = inventory + :quantity 
                WHERE product_id = :product_id AND date = :date";
                
        $stmt = $this->conn->prepare($query);
        
        return $stmt->execute([
            ':quantity'   => $quantity,
            ':product_id' => $productId,
            ':date'       => $date
        ]);
    }
}
?>