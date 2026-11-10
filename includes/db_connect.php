<?php
date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/time_helper.php';

$host = "localhost";
$dbname = "property_management"; 
$username = "root";              
$password = "";                  

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $conn->exec("SET time_zone = '+07:00'");
    
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `completedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `completedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `assignedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `assignedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `commentedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `commentedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `estimatedHours` INT DEFAULT 2"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `estimatedHours` INT DEFAULT 2"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `staffComment` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `staffComment` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `staffCommentedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `staffCommentedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'Pending'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` MODIFY COLUMN `status` VARCHAR(50) DEFAULT 'Pending'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `adminApprovedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `adminApprovedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `ServiceRequest` ADD COLUMN `adminRemark` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `servicerequest` ADD COLUMN `adminRemark` TEXT DEFAULT NULL"); } catch(PDOException $e) {}

    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `emergencyName` VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `emergencyName` VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `emergencyPhone` VARCHAR(20) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `emergencyPhone` VARCHAR(20) DEFAULT NULL"); } catch(PDOException $e) {}

    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `initialWaterMeter` DECIMAL(10,2) DEFAULT 0"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `initialWaterMeter` DECIMAL(10,2) DEFAULT 0"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `initialElectricMeter` DECIMAL(10,2) DEFAULT 0"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `initialElectricMeter` DECIMAL(10,2) DEFAULT 0"); } catch(PDOException $e) {}

    try { $conn->exec("ALTER TABLE `Invoices` ADD COLUMN `otherFeeNote` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `invoices` ADD COLUMN `otherFeeNote` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
