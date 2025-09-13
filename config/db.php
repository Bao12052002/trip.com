<?php
class Database {
    private $host = "localhost";
    private $db_name = "manhphuo_crm_phuquoc";
    private $username = "manhphuo_crm_phuquoc";
    private $password = "@u]yJr{#6@oH";
    public $conn;

   // Chỉ giữ accountId để xác thực đơn giản
    public $accountId = "c05285034ce982da"; // Thay bằng accountId từ Trip.com
    public $signKey = '093e6f5504b2849d98ba7caa02070946';
    public $aesKey = '3fa1ece00b5fefe5'; // 16 ký tự
    public $aesIv = '83206a5f5174cd6e';  // 16 ký tự
    public $tripCallbackUrl = 'https://ttdopen.ctrip.com/api/order'; 
    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            echo json_encode(["error" => $e->getMessage()]);
        }
        return $this->conn;
    }
}
?>