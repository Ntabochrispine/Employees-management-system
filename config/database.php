<?php

$host = "localhost";
$dbname = "employee_management";
$username = "root";
$password = ""; // Typical local XAMPP default

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    exit("Database connection failed. Check your XAMPP settings.");
}