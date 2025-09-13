<?php
class Product {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function create($data) {
        $query = "INSERT INTO tbl_trip_products (item_id, plu, name, description, use_start_date) 
                  VALUES (:item_id, :plu, :name, :description, :use_start_date)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':item_id', $data['item_id']);
        $stmt->bindParam(':plu', $data['plu']);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':use_start_date', $data['use_start_date']);
        return $stmt->execute();
    }

    public function getByPlu($plu) {
        $query = "SELECT * FROM tbl_trip_products WHERE plu = :plu LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':plu', $plu);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>