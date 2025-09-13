<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error_log.txt');
header('Content-Type: application/json');
require_once '../controllers/ApiController.php';

$controller = new ApiController();
echo $controller->handleRequest();
?>