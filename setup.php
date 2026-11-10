<?php
// บังคับให้แสดง Error ทุกชนิด
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล
require_once 'includes/db_connect.php';

$user = "admin";
$pass = "123456"; 
$hashed_password = password_hash($pass, PASSWORD_DEFAULT); 
$name = "ผู้ดูแลระบบสูงสุด";
$role = "Admin";

try {
    $stmt = $conn->prepare("INSERT INTO `User` (username, password, fullName, role) VALUES (:user, :pass, :name, :role)");
    $stmt->execute([':user' => $user, ':pass' => $hashed_password, ':name' => $name, ':role' => $role]);
    
    echo "<h2>สร้างบัญชีสำเร็จ!</h2>";
    echo "Username: <b>admin</b><br>";
    echo "Password: <b>123456</b><br>";
    echo "<br><a href='login.php'>คลิกที่นี่เพื่อไปหน้าเข้าสู่ระบบ</a>";
} catch(PDOException $e) {
    echo "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
}
?>