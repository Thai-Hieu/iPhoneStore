<?php

$host = "localhost";
$username = "root";
$password = "";        
$database = "iPhoneStore";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Ket noi that bai: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

?>
