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
    try { $conn->exec("ALTER TABLE `Invoices` ADD COLUMN `slipRejectReason` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `invoices` ADD COLUMN `slipRejectReason` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Invoices` ADD COLUMN `slipRejectedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `invoices` ADD COLUMN `slipRejectedAt` DATETIME DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Invoices` ADD COLUMN `slipStatus` VARCHAR(30) DEFAULT 'Normal'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `invoices` ADD COLUMN `slipStatus` VARCHAR(30) DEFAULT 'Normal'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Invoices` ADD COLUMN `oldSlipImage` VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `invoices` ADD COLUMN `oldSlipImage` VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}

    try { $conn->exec("ALTER TABLE `LeaseRenewals` ADD COLUMN `leaseImage` VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `leaserenewals` ADD COLUMN `leaseImage` VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `LeaseRenewals` ADD COLUMN `oldLeaseImage` VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `leaserenewals` ADD COLUMN `oldLeaseImage` VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}

    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `vehiclePlate` VARCHAR(50) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `vehiclePlate` VARCHAR(50) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `vehicleProvince` VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `vehicleProvince` VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `vehicleType` VARCHAR(30) DEFAULT 'Car'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `vehicleType` VARCHAR(30) DEFAULT 'Car'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `parkingPlan` VARCHAR(20) DEFAULT 'None'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `parkingPlan` VARCHAR(20) DEFAULT 'None'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `parkingFee` DECIMAL(10,2) DEFAULT 0.00"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `parkingFee` DECIMAL(10,2) DEFAULT 0.00"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `internetPlan` VARCHAR(20) DEFAULT 'None'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `internetPlan` VARCHAR(20) DEFAULT 'None'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `Lease` ADD COLUMN `internetFee` DECIMAL(10,2) DEFAULT 0.00"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `lease` ADD COLUMN `internetFee` DECIMAL(10,2) DEFAULT 0.00"); } catch(PDOException $e) {}

    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `vehiclePlate` VARCHAR(50) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `vehiclePlate` VARCHAR(50) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `vehicleProvince` VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `vehicleProvince` VARCHAR(100) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `vehicleType` VARCHAR(30) DEFAULT 'Car'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `vehicleType` VARCHAR(30) DEFAULT 'Car'"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `idCard` VARCHAR(20) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `idCard` VARCHAR(20) DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `User` ADD COLUMN `address` TEXT DEFAULT NULL"); } catch(PDOException $e) {}
    try { $conn->exec("ALTER TABLE `user` ADD COLUMN `address` TEXT DEFAULT NULL"); } catch(PDOException $e) {}

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS `parking_requests` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `tenantId` INT(11) NOT NULL,
            `roomId` INT(11) NOT NULL,
            `currentPlate` VARCHAR(50) NOT NULL,
            `additionalPlate` VARCHAR(50) NOT NULL,
            `vehicleType` VARCHAR(30) NOT NULL DEFAULT 'Car',
            `brandModel` VARCHAR(100) DEFAULT NULL,
            `province` VARCHAR(100) DEFAULT NULL,
            `vehicleImage` VARCHAR(255) DEFAULT NULL,
            `reason` TEXT DEFAULT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
            `adminRemark` TEXT DEFAULT NULL,
            `cancelReason` TEXT DEFAULT NULL,
            `approvedAt` DATETIME DEFAULT NULL,
            `cancelledAt` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenantId` (`tenantId`),
            KEY `roomId` (`roomId`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch(PDOException $e) {}

    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS `RetroactiveInvoiceRequests` (
            `requestId` INT(11) NOT NULL AUTO_INCREMENT,
            `tenantId` INT(11) NOT NULL,
            `roomId` INT(11) NOT NULL,
            `invoiceId` INT(11) NULL,
            `requestedMonth` VARCHAR(50) NOT NULL,
            `requestType` VARCHAR(50) DEFAULT 'PaidInvoice',
            `reason` TEXT NOT NULL,
            `slipImage` VARCHAR(255) NULL,
            `status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
            `adminRemark` TEXT NULL,
            `createdAt` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updatedAt` DATETIME NULL,
            PRIMARY KEY (`requestId`),
            KEY `tenantId` (`tenantId`),
            KEY `roomId` (`roomId`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch(PDOException $e) {}
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
