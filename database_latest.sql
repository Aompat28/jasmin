-- MariaDB dump 10.19-11.8.6-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: property_management
-- ------------------------------------------------------
-- Server version	11.8.6-MariaDB-5ubuntu0.1 from Ubuntu

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `admin_read_notifications`
--

DROP TABLE IF EXISTS `admin_read_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_read_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `notif_id` varchar(100) NOT NULL,
  `read_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_notif` (`admin_id`,`notif_id`)
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_read_notifications`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `admin_read_notifications` WRITE;
/*!40000 ALTER TABLE `admin_read_notifications` DISABLE KEYS */;
INSERT INTO `admin_read_notifications` VALUES
(1,1,'mo_36','2026-11-08 12:32:16'),
(2,1,'rc_9','2026-11-08 12:32:16'),
(3,1,'req_34','2026-11-08 12:32:16'),
(4,1,'req_33','2026-11-08 12:32:16'),
(5,1,'req_32','2026-11-08 12:32:16'),
(6,1,'req_31','2026-11-08 12:32:16'),
(7,1,'req_30','2026-11-08 12:32:16'),
(8,1,'req_28','2026-11-08 12:32:16'),
(9,1,'req_27','2026-11-08 12:32:16'),
(10,1,'req_25','2026-11-08 12:32:16'),
(11,1,'req_24','2026-11-08 12:32:16'),
(12,1,'req_23','2026-11-08 12:32:16'),
(13,1,'req_22','2026-11-08 12:32:16'),
(14,1,'req_21','2026-11-08 12:32:16'),
(15,1,'req_20','2026-11-08 12:32:16'),
(16,1,'req_19','2026-11-08 12:32:16'),
(17,1,'req_18','2026-11-08 12:32:16'),
(18,1,'req_17','2026-11-08 12:32:16'),
(19,1,'slip_5','2026-11-08 12:32:16'),
(20,1,'co_11','2026-11-08 12:32:16'),
(21,1,'co_10','2026-11-08 12:32:16'),
(24,1,'req_39','2026-11-08 15:55:20'),
(30,1,'req_50','2026-11-23 05:59:13'),
(31,1,'req_47','2026-11-23 05:59:13'),
(32,1,'req_46','2026-11-23 05:59:13'),
(33,1,'req_45','2026-11-23 05:59:13'),
(34,1,'req_43','2026-11-23 05:59:13'),
(35,1,'req_42','2026-11-23 05:59:13'),
(37,1,'req_51','2026-11-23 05:59:13'),
(38,1,'req_49','2026-11-23 05:59:13'),
(39,1,'req_48','2026-11-23 05:59:13'),
(40,1,'req_44','2026-11-23 05:59:13'),
(41,1,'req_41','2026-11-23 05:59:13'),
(42,1,'req_40','2026-11-23 05:59:13'),
(43,1,'req_38','2026-11-23 05:59:13'),
(77,1,'req_52','2026-11-23 06:31:07'),
(113,1,'req_53','2026-11-23 07:17:32');
/*!40000 ALTER TABLE `admin_read_notifications` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `administrator`
--

DROP TABLE IF EXISTS `administrator`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `administrator` (
  `adminId` int(11) NOT NULL,
  PRIMARY KEY (`adminId`),
  CONSTRAINT `administrator_ibfk_1` FOREIGN KEY (`adminId`) REFERENCES `user` (`userId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `administrator`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `administrator` WRITE;
/*!40000 ALTER TABLE `administrator` DISABLE KEYS */;
/*!40000 ALTER TABLE `administrator` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `announcement`
--

DROP TABLE IF EXISTS `announcement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcement` (
  `announcementId` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `content` text DEFAULT NULL,
  `createdAt` datetime DEFAULT current_timestamp(),
  `updatedAt` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `adminId` int(11) DEFAULT NULL,
  PRIMARY KEY (`announcementId`),
  KEY `adminId` (`adminId`),
  CONSTRAINT `announcement_ibfk_1` FOREIGN KEY (`adminId`) REFERENCES `administrator` (`adminId`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcement`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `announcement` WRITE;
/*!40000 ALTER TABLE `announcement` DISABLE KEYS */;
/*!40000 ALTER TABLE `announcement` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `announcementId` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` varchar(50) DEFAULT 'info',
  `message` text DEFAULT NULL,
  PRIMARY KEY (`announcementId`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
INSERT INTO `announcements` VALUES
(5,'ไฟจะดับ','10-11 ตุลาคม 2569','2026-09-30 13:45:26','2026-09-30 13:45:26','warning','10-11 ตุลาคม 2569'),
(6,'หม้อแปลงระเบิดไฟจะดับถึง18:00','เตรียมตัวในการทำรองจ่ายไฟด้วยตัวเอง','2026-10-03 10:51:06','2026-10-03 10:51:06','warning','เตรียมตัวในการทำรองจ่ายไฟด้วยตัวเอง'),
(7,'ทดสอบระบบประกาศ','ยินดีต้อนรับลูกบ้านทุกท่าน ระบบประกาศเปิดใช้งานปกติแล้ว','2026-11-05 14:24:36','2026-11-05 14:24:36','info','ยินดีต้อนรับลูกบ้านทุกท่าน ระบบประกาศเปิดใช้งานปกติแล้ว'),
(8,'เด็กคิดถึงพี่มากกกกกกกก','คิดถึงมากๆๆๆ','2026-11-05 14:26:26','2026-11-05 14:26:26','info','คิดถึงมากๆๆๆ'),
(9,'เด็กคิดถึงพี่มากกกกก','เมื่อไรจะได้เจอคะ','2026-11-05 14:26:55','2026-11-05 14:26:55','info','เมื่อไรจะได้เจอคะ'),
(10,'ทดสอบระบบประกาศ','ยินดีต้อนรับลูกบ้านทุกท่าน ระบบประกาศเปิดใช้งานปกติแล้ว','2026-11-05 14:31:09','2026-11-05 14:31:09','info','ยินดีต้อนรับลูกบ้านทุกท่าน ระบบประกาศเปิดใช้งานปกติแล้ว'),
(11,'เด็กคิดถึงพี่มากกกกก','เมื่อไรจะได้เจอคะ','2026-11-05 14:33:28','2026-11-05 14:33:28','info','เมื่อไรจะได้เจอคะ'),
(12,'ไฟดับ','แล้ว','2026-11-05 15:24:31','2026-11-05 15:24:31','warning','แล้ว'),
(13,'ประกาศด่วน','น้ำไม่ไหล ตอนนี้','2026-11-23 05:47:28','2026-11-23 05:47:28','warning','น้ำไม่ไหล ตอนนี้'),
(14,'น้ำ','น้ำไหลแล้ว แต่ไม่สะอาด','2026-11-25 10:58:39','2026-11-25 10:58:39','warning','น้ำไหลแล้ว แต่ไม่สะอาด'),
(15,'ไฟ','ไฟจะดับนานถึง1วัน','2026-11-26 07:02:17','2026-11-26 07:02:17','warning','ไฟจะดับนานถึง1วัน'),
(16,'ไฟ','ไฟจะดับนานถึง1วัน','2026-11-26 07:02:22','2026-11-26 07:02:22','warning','ไฟจะดับนานถึง1วัน'),
(17,'หิว','อยากกินเตี๋ยว','2026-11-26 12:22:06','2026-11-26 12:22:06','info','อยากกินเตี๋ยว'),
(18,'อาหาร','ได้กินเตี๋ยว','2026-11-26 16:17:41','2026-11-26 16:17:41','success','ได้กินเตี๋ยว');
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `coresidents`
--

DROP TABLE IF EXISTS `coresidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `coresidents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenantId` int(11) NOT NULL,
  `roomId` int(11) NOT NULL,
  `fullName` varchar(100) NOT NULL,
  `relationship` varchar(50) NOT NULL,
  `idCardNumber` varchar(20) NOT NULL,
  `phoneNumber` varchar(20) NOT NULL,
  `idCardImage` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'Pending',
  `adminRemark` text DEFAULT NULL,
  `cancelReason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `coresidents`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `coresidents` WRITE;
/*!40000 ALTER TABLE `coresidents` DISABLE KEYS */;
INSERT INTO `coresidents` VALUES
(10,51,34,'ไข่เจียว ข้าวโพด','พี่ / น้อง','1111111111111','0000000000','uploads/1791559777_coresident_1265540d.jpg','Pending',NULL,NULL,'2026-10-09 15:29:37'),
(11,50,47,'อารียา พงษืสมบัติ','บิดา / มารดา','1111111111111','0123456789','uploads/1791560894_coresident_8a26a654.jpg','Pending',NULL,NULL,'2026-10-09 15:48:14'),
(12,54,47,'ggg','อื่นๆ','4444444444444','4444444444','uploads/1793596946_coresident_c67d91e7.jpg','Rejected','ไม่ให้อยู่แล้ว',NULL,'2026-11-02 05:22:26'),
(14,54,59,'mee','แฟน / คู่สมรส','1220555555555','0542315353','uploads/1795700255_coresident_5d015b95.png','Approved','อนุมัติเรียบร้อยแล้ว',NULL,'2026-11-26 13:37:35'),
(16,69,34,'nee','พี่ / น้อง','1222244455555','0665554423','uploads/1795718868_coresident_f83e9c04.png','Approved','อนุมัติเรียบร้อยแล้ว',NULL,'2026-11-26 18:47:48');
/*!40000 ALTER TABLE `coresidents` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `housekeeper`
--

DROP TABLE IF EXISTS `housekeeper`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `housekeeper` (
  `housekeeperId` int(11) NOT NULL,
  `status` varchar(20) DEFAULT 'Available',
  PRIMARY KEY (`housekeeperId`),
  CONSTRAINT `housekeeper_ibfk_1` FOREIGN KEY (`housekeeperId`) REFERENCES `user` (`userId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `housekeeper`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `housekeeper` WRITE;
/*!40000 ALTER TABLE `housekeeper` DISABLE KEYS */;
/*!40000 ALTER TABLE `housekeeper` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `invoice`
--

DROP TABLE IF EXISTS `invoice`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice` (
  `invoiceId` int(11) NOT NULL AUTO_INCREMENT,
  `leaseId` int(11) NOT NULL,
  `billingMonth` int(11) NOT NULL,
  `billingYear` int(11) NOT NULL,
  `issueDate` date NOT NULL,
  `dueDate` date NOT NULL,
  `rentAmount` decimal(10,2) NOT NULL,
  `waterAmount` decimal(10,2) NOT NULL,
  `electricAmount` decimal(10,2) NOT NULL,
  `otherAmount` decimal(10,2) DEFAULT 0.00,
  `totalAmount` decimal(10,2) NOT NULL,
  `paymentStatus` varchar(20) DEFAULT 'Unpaid',
  PRIMARY KEY (`invoiceId`),
  KEY `leaseId` (`leaseId`),
  CONSTRAINT `invoice_ibfk_1` FOREIGN KEY (`leaseId`) REFERENCES `lease` (`leaseId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `invoice` WRITE;
/*!40000 ALTER TABLE `invoice` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoice` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `invoiceId` int(11) NOT NULL AUTO_INCREMENT,
  `roomId` int(11) NOT NULL,
  `tenantId` int(11) NOT NULL,
  `billingMonth` varchar(50) NOT NULL COMMENT 'เช่น 2026-06 (มิถุนายน 2026)',
  `rentPrice` decimal(10,2) NOT NULL DEFAULT 0.00,
  `waterPrev` int(11) NOT NULL DEFAULT 0,
  `waterCurr` int(11) NOT NULL DEFAULT 0,
  `waterUnitRate` decimal(10,2) NOT NULL DEFAULT 18.00 COMMENT 'ราคาต่อหน่วยน้ำ',
  `electricPrev` int(11) NOT NULL DEFAULT 0,
  `electricCurr` int(11) NOT NULL DEFAULT 0,
  `electricUnitRate` decimal(10,2) NOT NULL DEFAULT 8.00 COMMENT 'ราคาต่อหน่วยไฟ',
  `otherFee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าใช้จ่ายอื่นๆ เช่น ค่าส่วนกลาง',
  `totalAmount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Unpaid','Pending Approval','Paid') NOT NULL DEFAULT 'Unpaid',
  `slipImage` varchar(255) DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `otherFeeNote` text DEFAULT NULL,
  `slipRejectReason` text DEFAULT NULL,
  `slipRejectedAt` datetime DEFAULT NULL,
  `slipStatus` varchar(30) DEFAULT 'Normal',
  `oldSlipImage` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`invoiceId`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES
(1,47,50,'ตุลาคม 2026',3000.00,35,37,18.00,10966,11128,8.00,500.00,4832.00,'Paid','uploads/1791558151_slip_kk.jpg','2026-10-09 14:34:46',NULL,NULL,NULL,'Normal',NULL),
(3,47,54,'ตุลาคม 2026',3000.00,35,37,18.00,10966,11128,8.00,500.00,4832.00,'Paid','uploads/1793596628_slip_1791789808_slip_kk.jpg','2026-10-12 07:21:19',NULL,NULL,NULL,'Normal',NULL),
(4,36,56,'ตุลาคม 2026',3000.00,123,130,18.00,123,200,8.00,0.00,3742.00,'Paid',NULL,'2026-10-12 09:51:22',NULL,NULL,NULL,'Normal',NULL),
(5,47,54,'พฤศจิกายน 2026',3000.00,3722,3730,18.00,1128,1200,8.00,20.00,3740.00,'Paid','uploads/1794302592_slip_8f7e7470f39f735939287befe7347223.jpg','2026-11-02 06:31:36','ค่าขยะ (20.00 บาท)',NULL,NULL,'Normal',NULL),
(6,34,51,'พฤศจิกายน 2026',3000.00,12,20,18.00,109,112,8.00,0.00,3168.00,'Paid',NULL,'2026-11-07 11:11:25',NULL,NULL,NULL,'Normal',NULL),
(7,47,54,'ธันวาคม 2026',3000.00,3730,4000,18.00,1200,1300,8.00,170.00,8830.00,'Paid','uploads/1794304909_slip_0bc219b75a63efef5588456681f4283a.jpg','2026-11-10 10:01:30','ค่าขยะ (฿20.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00)',NULL,NULL,'Normal',NULL),
(8,47,54,'ธันวาคม 2026',3000.00,3730,3740,18.00,1200,1250,8.00,320.00,3900.00,'Paid','uploads/1795413147_slip_สลิป1.png','2026-11-23 05:52:12','ค่าขยะ (฿20.00), ค่าที่จอดรถยนต์ (฿300.00)',NULL,NULL,'Normal',NULL),
(9,59,54,'ธันวาคม 2026',4000.00,1000,1010,18.00,1000,1020,8.00,320.00,4660.00,'Paid','uploads/1795414338_slip_สลิป1.png','2026-11-23 06:12:04','ค่าขยะ (฿20.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Normal',NULL),
(10,59,54,'มกราคม 2027',4000.00,1010,1020,18.00,1020,1100,8.00,420.00,5240.00,'Paid','uploads/1795415158_slip_สลิป1.png','2026-11-23 06:25:44','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าที่จอดรถยนต์ (฿300.00)',NULL,NULL,'Normal',NULL),
(11,59,54,'กุมภาพันธ์ 2027',4000.00,1020,1035,18.00,1100,1160,8.00,320.00,5070.00,'Paid','uploads/1795417984_slip_สลิป1.png','2026-11-23 07:12:50','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), คีย์การ์ด (฿200.00)',NULL,NULL,'Normal',NULL),
(12,59,54,'มกราคม 2027',4000.00,1010,1020,18.00,1020,1030,8.00,470.00,4730.00,'Paid','uploads/1795420060_slip_สลิป1.png','2026-11-23 07:39:13','ค่าขยะ (฿20.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Normal',NULL),
(13,59,54,'ธันวาคม 2026',4000.00,1020,1030,18.00,1030,1060,8.00,370.00,4790.00,'Paid','uploads/1795674031_slip_สลิป1.png','2026-11-23 08:29:39','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าที่จอดมอเตอร์ไซค์ (฿100.00)',NULL,NULL,'Pending',NULL),
(14,59,54,'มกราคม 2027',4000.00,1030,1040,18.00,1039,1050,8.00,570.00,4838.00,'Paid','uploads/1795602498_slip_สลิป1.png','2026-11-25 10:25:56','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Normal',NULL),
(15,59,54,'มกราคม 2027',4000.00,1030,1040,18.00,1039,1050,8.00,520.00,4788.00,'Paid','uploads/1795608257_slip_สลิป1.png','2026-11-25 10:30:06','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าที่จอดมอเตอร์ไซค์ (฿100.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Normal',NULL),
(16,59,54,'พฤศจิกายน 2026',4000.00,1000,1020,18.00,1000,1030,8.00,870.00,5470.00,'Paid','uploads/1795622156_slip_สลิป1.png','2026-11-25 15:44:45','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าที่จอดรถยนต์ (฿300.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)','สลิปซ้ำ หรือวันเวลาโอนไม่ตรงกับรอบบิลปัจจุบัน',NULL,'Normal',NULL),
(17,59,54,'ธันวาคม 2026',4000.00,1020,1040,18.00,1030,1050,8.00,670.00,5190.00,'Paid','uploads/1795674883_slip_สลิป1.png','2026-11-26 06:33:32','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าที่จอดมอเตอร์ไซค์ (฿100.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Pending',NULL),
(18,59,54,'ธันวาคม 2026',4000.00,1020,1040,18.00,1030,1060,8.00,670.00,5270.00,'Paid','uploads/1795675077_slip_สลิป1.png','2026-11-26 06:37:37','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าที่จอดมอเตอร์ไซค์ (฿100.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Pending',NULL),
(19,59,54,'มกราคม 2027',4000.00,1040,1050,18.00,1060,1080,8.00,670.00,5010.00,'Paid','uploads/1795675221_slip_สลิป1.png','2026-11-26 06:39:58','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าที่จอดมอเตอร์ไซค์ (฿100.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Pending',NULL),
(20,59,54,'กุมภาพันธ์ 2027',4000.00,1050,1060,18.00,1080,1100,8.00,370.00,4710.00,'Unpaid',NULL,'2026-11-26 06:42:11','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (฿150.00), ค่าที่จอดมอเตอร์ไซค์ (฿100.00)',NULL,NULL,'Normal',NULL),
(21,59,54,'พฤศจิกายน 2026',4000.00,1000,1020,18.00,1000,1040,8.00,120.00,4800.00,'Paid','uploads/1795709677_slip_สลิป1.png','2026-11-26 16:12:11','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00)',NULL,NULL,'Pending','uploads/1795709624_slip_สลิป1.png'),
(22,34,69,'พฤศจิกายน 2026',3000.00,1000,1020,18.00,1000,1040,8.00,370.00,4050.00,'Paid','uploads/1795718077_slip_สลิป1.png','2026-11-26 18:33:14','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (ตามสัญญารายปี) (฿150.00), ค่าที่จอดรถยนต์ (ตามสัญญา) (฿100.00)',NULL,NULL,'Pending','uploads/1795718033_slip_สลิป1.png'),
(23,34,69,'พฤศจิกายน 2026',3000.00,1000,1020,18.00,1000,1040,8.00,670.00,4350.00,'Paid','uploads/1795719873_slip_สลิป1.png','2026-11-26 19:03:22','ค่าขยะ (฿20.00), ค่าส่วนกลาง (฿100.00), ค่าอินเทอร์เน็ต / Wi-Fi (ตามสัญญารายปี) (฿150.00), ค่าที่จอดรถยนต์ (ตามสัญญา) (฿100.00), ค่าล้างแอร์ / บำรุงรักษา (฿300.00)',NULL,NULL,'Pending',NULL);
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `lease`
--

DROP TABLE IF EXISTS `lease`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lease` (
  `leaseId` int(11) NOT NULL AUTO_INCREMENT,
  `tenantId` int(11) DEFAULT NULL,
  `roomId` int(11) DEFAULT NULL,
  `startDate` date NOT NULL,
  `endDate` date NOT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `leaseImage` varchar(255) DEFAULT NULL,
  `depositAmount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `moveOutRequestDate` date DEFAULT NULL,
  `bankName` varchar(100) DEFAULT NULL,
  `bankAccount` varchar(50) DEFAULT NULL,
  `accountName` varchar(100) DEFAULT NULL,
  `moveOutReason` text DEFAULT NULL,
  `moveOutStatus` varchar(50) DEFAULT NULL,
  `finalElectricFee` decimal(10,2) DEFAULT 0.00,
  `finalWaterFee` decimal(10,2) DEFAULT 0.00,
  `cleaningFee` decimal(10,2) DEFAULT 0.00,
  `damageFee` decimal(10,2) DEFAULT 0.00,
  `breachFee` decimal(10,2) DEFAULT 0.00,
  `damageDetail` text DEFAULT NULL,
  `netRefundAmount` decimal(10,2) DEFAULT 0.00,
  `settledAt` datetime DEFAULT NULL,
  `refundReceipt` varchar(255) DEFAULT NULL,
  `tenantPaymentSlip` varchar(255) DEFAULT NULL,
  `initialWaterMeter` decimal(10,2) DEFAULT 0.00,
  `initialElectricMeter` decimal(10,2) DEFAULT 0.00,
  `vehiclePlate` varchar(50) DEFAULT NULL,
  `parkingPlan` varchar(20) DEFAULT 'None',
  `parkingFee` decimal(10,2) DEFAULT 0.00,
  `internetPlan` varchar(20) DEFAULT 'None',
  `internetFee` decimal(10,2) DEFAULT 0.00,
  `vehicleProvince` varchar(100) DEFAULT NULL,
  `vehicleType` varchar(30) DEFAULT 'Car',
  `moveOutCreatedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`leaseId`),
  KEY `tenantId` (`tenantId`),
  KEY `roomId` (`roomId`),
  CONSTRAINT `lease_ibfk_1` FOREIGN KEY (`tenantId`) REFERENCES `user` (`userId`) ON DELETE CASCADE,
  CONSTRAINT `lease_ibfk_2` FOREIGN KEY (`roomId`) REFERENCES `room` (`roomId`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lease`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `lease` WRITE;
/*!40000 ALTER TABLE `lease` DISABLE KEYS */;
INSERT INTO `lease` VALUES
(41,63,48,'2026-11-10','2027-11-09','Active','uploads/1794286308_lease_room48.jpg',4500.00,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.00,0.00,NULL,0.00,NULL,NULL,NULL,28.00,37.00,NULL,'None',0.00,'None',0.00,NULL,'Car',NULL),
(43,65,47,'2026-11-25','2027-11-24','Active','uploads/1795601782_lease_room47.png',4500.00,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.00,0.00,NULL,0.00,NULL,NULL,NULL,1000.00,1000.00,NULL,'None',0.00,'None',0.00,NULL,'Car',NULL),
(44,66,51,'2026-10-10','2027-10-09','Active','uploads/1795673211_lease_room51.png',4500.00,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.00,0.00,NULL,0.00,NULL,NULL,NULL,1000.00,1000.00,'9ขร  879','Monthly',100.00,'Monthly',150.00,'กรุงเทพมหานคร','Car',NULL),
(45,67,52,'2026-11-26','2027-11-25','Active','uploads/1795696506_lease_room52.png',4500.00,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.00,0.00,NULL,0.00,NULL,NULL,NULL,1000.00,1000.00,'1กอ  123','Monthly',100.00,'Monthly',150.00,'กระบี่','Motorcycle',NULL),
(46,68,64,'2026-11-27','2027-11-26','Active','uploads/1795713711_lease_room64.png',6000.00,NULL,NULL,NULL,NULL,NULL,NULL,0.00,0.00,0.00,0.00,0.00,NULL,0.00,NULL,NULL,NULL,1000.00,1000.00,'กจ 5477','Yearly',1200.00,'Yearly',1500.00,'อุตรดิตถ์','Car',NULL),
(47,69,34,'2026-11-27','2028-05-26','Active','uploads/1795718634_renew_lease_47.png',4500.00,NULL,'ธนาคารกสิกรไทย (KBANK)','1654782158','อทิตยา พงษ์สมบัติ',NULL,NULL,0.00,0.00,0.00,0.00,0.00,NULL,0.00,NULL,NULL,'uploads/tenant_slip_47_1795718993.png',1000.00,1000.00,'1กอ  123','Monthly',100.00,'Yearly',1500.00,'กรุงเทพมหานคร','Car',NULL);
/*!40000 ALTER TABLE `lease` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `leaserenewals`
--

DROP TABLE IF EXISTS `leaserenewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leaserenewals` (
  `renewalId` int(11) NOT NULL AUTO_INCREMENT,
  `leaseId` int(11) NOT NULL,
  `tenantId` int(11) NOT NULL,
  `roomId` int(11) NOT NULL,
  `extensionMonths` int(11) NOT NULL,
  `status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `adminRemark` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `leaseImage` varchar(255) DEFAULT NULL,
  `oldLeaseImage` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`renewalId`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leaserenewals`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `leaserenewals` WRITE;
/*!40000 ALTER TABLE `leaserenewals` DISABLE KEYS */;
INSERT INTO `leaserenewals` VALUES
(1,31,46,34,6,'Approved','อนุมัติการต่อสัญญาเช่าเรียบร้อย','2026-10-08 13:01:16',NULL,NULL),
(7,34,49,47,6,'Approved','อนุมัติต่อสัญญาเช่าเพิ่ม 6 เดือนเรียบร้อยแล้ว','2026-10-09 13:02:37','uploads/1791551494_renew_lease_34.png',NULL),
(8,35,50,47,6,'Approved','อนุมัติต่อสัญญาเช่าเพิ่ม 6 เดือนเรียบร้อยแล้ว','2026-10-09 15:55:27','uploads/1791561462_renew_lease_35.png',NULL),
(9,37,54,59,12,'Rejected','ไม่ให้อยู่','2026-11-23 06:02:02',NULL,NULL),
(10,37,54,59,3,'Rejected','ไม่ให้ต่อ','2026-11-25 10:52:29','uploads/1795604156_renew_lease_37.png',NULL),
(11,37,54,59,12,'Approved','อนุมัติต่อสัญญาเช่าเพิ่ม 12 เดือนเรียบร้อยแล้ว','2026-11-26 07:03:09','uploads/1795676825_renew_lease_37.png','uploads/1795604156_renew_lease_37.png'),
(12,37,54,59,3,'Approved','อนุมัติต่อสัญญาเช่าเพิ่ม 3 เดือนเรียบร้อยแล้ว','2026-11-26 13:34:21','uploads/1795700114_renew_lease_37.png','uploads/1795676825_renew_lease_37.png'),
(13,47,69,34,6,'Rejected','ไม่','2026-11-26 18:41:32',NULL,'uploads/1795717784_lease_room34.png'),
(14,47,69,34,6,'Approved','อนุมัติต่อสัญญาเช่าเพิ่ม 6 เดือนเรียบร้อยแล้ว','2026-11-26 18:43:06','uploads/1795718634_renew_lease_47.png','uploads/1795717784_lease_room34.png');
/*!40000 ALTER TABLE `leaserenewals` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `meterreading`
--

DROP TABLE IF EXISTS `meterreading`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `meterreading` (
  `meterReadingId` int(11) NOT NULL AUTO_INCREMENT,
  `leaseId` int(11) NOT NULL,
  `billingMonth` int(11) NOT NULL,
  `billingYear` int(11) NOT NULL,
  `previousWaterUnit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currentWaterUnit` decimal(10,2) NOT NULL,
  `waterUsage` decimal(10,2) NOT NULL,
  `previousElectricUnit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currentElectricUnit` decimal(10,2) NOT NULL,
  `electricUsage` decimal(10,2) NOT NULL,
  `recordedAt` datetime DEFAULT current_timestamp(),
  `roomId` int(11) NOT NULL,
  `readingDate` date DEFAULT NULL,
  PRIMARY KEY (`meterReadingId`),
  KEY `leaseId` (`leaseId`),
  CONSTRAINT `meterreading_ibfk_1` FOREIGN KEY (`leaseId`) REFERENCES `lease` (`leaseId`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meterreading`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `meterreading` WRITE;
/*!40000 ALTER TABLE `meterreading` DISABLE KEYS */;
/*!40000 ALTER TABLE `meterreading` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `notification`
--

DROP TABLE IF EXISTS `notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification` (
  `notificationId` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `message` text DEFAULT NULL,
  `notificationType` varchar(50) DEFAULT NULL,
  `isRead` tinyint(1) DEFAULT 0,
  `createdAt` datetime DEFAULT current_timestamp(),
  `userId` int(11) DEFAULT NULL,
  PRIMARY KEY (`notificationId`),
  KEY `userId` (`userId`),
  CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `user` (`userId`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `notification` WRITE;
/*!40000 ALTER TABLE `notification` DISABLE KEYS */;
INSERT INTO `notification` VALUES
(9,'ลูกบ้านยกเลิกคำขอย้ายออกแล้ว','ลูกบ้านห้อง 101 (อทิตยา พงษ์สมบัติ) ได้ยกเลิกคำขอแจ้งย้ายออกแล้ว สถานะห้องพักกลับสู่ภาวะปกติ','move_out_cancelled',0,'2026-10-11 22:07:13',1),
(10,'ลูกบ้านยกเลิกคำขอย้ายออกแล้ว','ลูกบ้านห้อง 102 (ข้าวเหนียว หมูปิ้ง) ได้ยกเลิกคำขอแจ้งย้ายออกแล้ว สถานะห้องพักกลับสู่ภาวะปกติ','move_out_cancelled',0,'2026-10-11 22:07:19',1),
(11,'คำขอแจ้งย้ายออกใหม่','ลูกบ้านห้อง 102 (ข้าวเหนียว หมูปิ้ง) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ 11/11/2026','move_out',0,'2026-10-12 14:02:34',1),
(13,'คำขอแจ้งย้ายออกใหม่','ลูกบ้านห้อง 102 (ข้าวเหนียว หมูปิ้ง) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ 11/11/2026','move_out',0,'2026-10-12 14:08:29',1),
(14,'ลูกบ้านแนบสลิปคืนห้องแล้ว','ลูกบ้านได้แนบสลิปโอนเงินส่วนต่างคืนห้องพักเรียบร้อยแล้ว กรุณาเข้าตรวจสอบ','move_out_slip',0,'2026-10-12 14:09:44',1),
(17,'คำขอแจ้งย้ายออกใหม่','ลูกบ้านห้อง 103 (Pat) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ 02/12/2026','move_out',0,'2026-11-02 15:22:14',1),
(19,'คำขอแจ้งย้ายออกใหม่','ลูกบ้านห้อง 103 (Pat) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ 02/12/2026','move_out',0,'2026-11-02 15:26:44',1),
(20,'ลูกบ้านแนบสลิปคืนห้องแล้ว','ลูกบ้านได้แนบสลิปโอนเงินส่วนต่างคืนห้องพักเรียบร้อยแล้ว กรุณาเข้าตรวจสอบ','move_out_slip',0,'2026-11-02 15:27:40',1),
(23,'ลูกบ้านแนบสลิปคืนห้องแล้ว','ลูกบ้านได้แนบสลิปโอนเงินส่วนต่างคืนห้องพักเรียบร้อยแล้ว กรุณาเข้าตรวจสอบ','move_out_slip',0,'2026-11-02 15:31:45',1),
(28,'คำขอแจ้งย้ายออกใหม่','ลูกบ้านห้อง 115 (อทิตยา พงษ์สมบัติ) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ 01/02/2027','move_out',0,'2026-11-26 19:39:17',1),
(29,'ลูกบ้านแนบสลิปคืนห้องแล้ว','ลูกบ้านได้แนบสลิปโอนเงินส่วนต่างคืนห้องพักเรียบร้อยแล้ว กรุณาเข้าตรวจสอบ','move_out_slip',0,'2026-11-26 19:39:52',1),
(30,'คำขอแจ้งย้ายออกใหม่','ลูกบ้านห้อง 102 (อทิตยา พงษ์สมบัติ) ได้ส่งคำขอแจ้งย้ายออก ในวันที่ 27/02/2027','move_out',0,'2026-11-27 01:49:30',1),
(31,'ลูกบ้านแนบสลิปคืนห้องแล้ว','ลูกบ้านได้แนบสลิปโอนเงินส่วนต่างคืนห้องพักเรียบร้อยแล้ว กรุณาเข้าตรวจสอบ','move_out_slip',0,'2026-11-27 01:49:53',1);
/*!40000 ALTER TABLE `notification` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `parking_requests`
--

DROP TABLE IF EXISTS `parking_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parking_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenantId` int(11) NOT NULL,
  `roomId` int(11) NOT NULL,
  `currentPlate` varchar(50) NOT NULL,
  `additionalPlate` varchar(50) NOT NULL,
  `vehicleType` varchar(30) NOT NULL DEFAULT 'Car',
  `brandModel` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `vehicleImage` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Pending',
  `adminRemark` text DEFAULT NULL,
  `cancelReason` text DEFAULT NULL,
  `approvedAt` datetime DEFAULT NULL,
  `cancelledAt` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenantId` (`tenantId`),
  KEY `roomId` (`roomId`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parking_requests`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `parking_requests` WRITE;
/*!40000 ALTER TABLE `parking_requests` DISABLE KEYS */;
INSERT INTO `parking_requests` VALUES
(1,54,59,'1กม 5488','8กร 84','Car','Honda สีดำด้าน','เชียงราย','uploads/1795604725_car_8dac7069.webp','มีผู้ร่วมอาศัยนำรถมาทำงาน','Approved','อนุญาตให้จอดที่ช่อง2',NULL,'2026-11-25 18:09:33',NULL,'2026-11-25 11:05:25','2026-11-25 11:09:33'),
(2,66,51,'9ขร  879','9ขร  879','Car',NULL,'กรุงเทพมหานคร',NULL,'รถยนต์คันแรกตามสัญญาเช่าห้องพัก','Approved','อนุมัติพร้อมเปิดสัญญาเช่าห้องพัก',NULL,'2026-11-26 13:06:51',NULL,'2026-11-26 06:06:51','2026-11-26 06:06:51'),
(3,54,59,'1กม 5488','3กข 5877','Car','City สีดำ','น่าน','uploads/1795677379_car_4f062e0d.webp','มีผู้ร่วมอาศัยเอารถมาทำงาน','Cancelled','ไม่ให้','ไม่ให้',NULL,'2026-11-26 14:17:22','2026-11-26 07:16:19','2026-11-26 07:17:22'),
(4,67,52,'1กอ  123','1กอ  123','Motorcycle',NULL,'กระบี่',NULL,'ยานพาหนะคันแรกตามสัญญาเช่าห้องพัก','Approved','อนุมัติพร้อมเปิดสัญญาเช่าห้องพัก',NULL,'2026-11-26 19:35:06',NULL,'2026-11-26 12:35:06','2026-11-26 12:35:06'),
(5,54,59,'1กม 5488','กค 5448','Car','Honda สีดำ','เชียงใหม่','uploads/1795700636_car_4a5f85c3.webp','คนที่มาอยู่ด้วยนำรถมาทำงานเอง','Approved','อนุญาต',NULL,'2026-11-26 20:56:48',NULL,'2026-11-26 13:43:56','2026-11-26 13:56:48'),
(6,68,64,'กจ 5477','กจ 5477','Car',NULL,'อุตรดิตถ์',NULL,'ยานพาหนะคันแรกตามสัญญาเช่าห้องพัก','Approved','อนุมัติพร้อมเปิดสัญญาเช่าห้องพัก',NULL,'2026-11-27 00:21:51',NULL,'2026-11-26 17:21:51','2026-11-26 17:21:51'),
(7,69,34,'1กอ  123','1กอ  123','Car',NULL,'กรุงเทพมหานคร',NULL,'ยานพาหนะคันแรกตามสัญญาเช่าห้องพัก','Approved','อนุมัติพร้อมเปิดสัญญาเช่าห้องพัก',NULL,'2026-11-27 01:29:44',NULL,'2026-11-26 18:29:44','2026-11-26 18:29:44'),
(8,69,34,'1กอ  123','กค 5447','Car','Honda สีดำ','เชียงใหม่','uploads/1795719240_car_d766b657.webp','คนที่พักอาศัยด้วยนำรถมาทำงานเอง','Approved','อนุญาตให้จอดที่ช่อง2',NULL,'2026-11-27 01:54:29',NULL,'2026-11-26 18:54:00','2026-11-26 18:54:29');
/*!40000 ALTER TABLE `parking_requests` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `payment`
--

DROP TABLE IF EXISTS `payment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment` (
  `paymentId` int(11) NOT NULL AUTO_INCREMENT,
  `invoiceId` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paymentDate` datetime DEFAULT current_timestamp(),
  `paymentMethod` varchar(50) DEFAULT 'Bank Transfer',
  `transactionRef` varchar(100) DEFAULT NULL,
  `slipImage` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  PRIMARY KEY (`paymentId`),
  KEY `invoiceId` (`invoiceId`),
  CONSTRAINT `payment_ibfk_1` FOREIGN KEY (`invoiceId`) REFERENCES `invoice` (`invoiceId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `payment` WRITE;
/*!40000 ALTER TABLE `payment` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `retroactiveinvoicerequests`
--

DROP TABLE IF EXISTS `retroactiveinvoicerequests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `retroactiveinvoicerequests` (
  `requestId` int(11) NOT NULL AUTO_INCREMENT,
  `tenantId` int(11) NOT NULL,
  `roomId` int(11) NOT NULL,
  `requestedMonth` varchar(50) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Pending',
  `adminRemark` text DEFAULT NULL,
  `createdAt` datetime DEFAULT current_timestamp(),
  `updatedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`requestId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `retroactiveinvoicerequests`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `retroactiveinvoicerequests` WRITE;
/*!40000 ALTER TABLE `retroactiveinvoicerequests` DISABLE KEYS */;
/*!40000 ALTER TABLE `retroactiveinvoicerequests` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `room`
--

DROP TABLE IF EXISTS `room`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `room` (
  `roomId` int(11) NOT NULL AUTO_INCREMENT,
  `roomNumber` varchar(10) NOT NULL,
  `roomType` varchar(50) DEFAULT NULL,
  `floor` int(11) DEFAULT NULL,
  `rentPrice` decimal(10,2) NOT NULL,
  `status` varchar(20) DEFAULT 'Available',
  PRIMARY KEY (`roomId`),
  UNIQUE KEY `roomNumber` (`roomNumber`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `room`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `room` WRITE;
/*!40000 ALTER TABLE `room` DISABLE KEYS */;
INSERT INTO `room` VALUES
(34,'102','Fan',1,3000.00,'Occupied'),
(36,'104','Fan',1,3000.00,'Available'),
(38,'201','Fan',2,4000.00,'Available'),
(40,'203','Fan',1,3000.00,'Available'),
(43,'301','Air',3,4000.00,'Available'),
(46,'404','Air',4,5000.00,'Available'),
(47,'101','Fan',1,3000.00,'Occupied'),
(48,'103','Fan',1,3000.00,'Occupied'),
(49,'105','Fan',1,3000.00,'Available'),
(50,'106','Fan',1,3000.00,'Available'),
(51,'107','Fan',1,3000.00,'Occupied'),
(52,'108','Fan',1,3000.00,'Occupied'),
(53,'109','Fan',1,3000.00,'Available'),
(54,'110','Fan',1,3000.00,'Available'),
(55,'111','Fan',1,3000.00,'Available'),
(56,'112','Fan',1,3000.00,'Available'),
(57,'113','Fan',1,3000.00,'Available'),
(58,'114','Fan',1,3000.00,'Available'),
(59,'115','Air',1,4000.00,'Available'),
(60,'116','Air',1,4000.00,'Available'),
(61,'204','Fan',2,3000.00,'Available'),
(62,'206','Fan',2,3000.00,'Available'),
(63,'205','Fan',2,3000.00,'Available'),
(64,'207','Air',2,4000.00,'Occupied'),
(65,'208','Air',2,4000.00,'Available'),
(66,'209','Air',2,4000.00,'Available'),
(67,'210','Air',2,4000.00,'Available'),
(68,'211','Air',2,4000.00,'Available'),
(69,'212','Air',2,4000.00,'Available'),
(70,'213','Air',2,4000.00,'Available'),
(71,'214','Air',2,4000.00,'Available'),
(72,'302','Fan',3,3000.00,'Available'),
(73,'303','Fan',3,3000.00,'Available'),
(74,'304','Fan',3,3000.00,'Available'),
(75,'305','Fan',3,3000.00,'Available'),
(76,'306','Fan',3,3000.00,'Available'),
(77,'307','Air',3,4000.00,'Available'),
(78,'308','Air',3,4000.00,'Available'),
(79,'309','Fan',3,4000.00,'Available'),
(80,'310','Air',3,4000.00,'Available'),
(81,'311','Air',3,4000.00,'Available'),
(82,'312','Air',3,4000.00,'Available'),
(83,'313','Air',3,4000.00,'Available'),
(84,'314','Air',3,4000.00,'Available'),
(85,'401','Fan',4,3000.00,'Available'),
(86,'402','Fan',4,3000.00,'Available'),
(87,'403','Fan',4,3000.00,'Available'),
(88,'405','Fan',4,3000.00,'Available'),
(89,'406','Air',4,4000.00,'Available'),
(90,'407','Air',4,4000.00,'Available'),
(91,'408','Air',4,4000.00,'Available'),
(92,'409','Air',4,4000.00,'Available'),
(93,'410','Air',4,4000.00,'Available'),
(94,'411','Air',4,4000.00,'Available'),
(95,'412','Air',4,4000.00,'Available'),
(96,'413','Air',4,4000.00,'Available'),
(97,'414','Air',4,4000.00,'Available'),
(98,'415','Air',4,4000.00,'Available'),
(99,'416','Air',4,4000.00,'Available'),
(100,'215','Air',2,4000.00,'Available'),
(102,'502','Air',5,4000.00,'Available');
/*!40000 ALTER TABLE `room` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `roomchangerequests`
--

DROP TABLE IF EXISTS `roomchangerequests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roomchangerequests` (
  `requestId` int(11) NOT NULL AUTO_INCREMENT,
  `tenantId` int(11) NOT NULL,
  `currentRoomId` int(11) NOT NULL,
  `targetRoomType` varchar(50) DEFAULT NULL,
  `targetRoomId` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `preferredMoveDate` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `adminRemark` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paymentSlip` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`requestId`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roomchangerequests`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `roomchangerequests` WRITE;
/*!40000 ALTER TABLE `roomchangerequests` DISABLE KEYS */;
INSERT INTO `roomchangerequests` VALUES
(1,46,34,'Fan',38,'หนาวอยากไปอยู่ในใจพี่มากกว่า','2026-10-30','Approved','หากต้องการย้ายอาจมีค่าทำความสะอาดเพิ่มเติม','2026-10-08 13:48:20',NULL),
(4,49,60,'Air',59,'ต้องการเปลี่ยนห้องใหม่','2026-10-31','Approved','อนุมัติการย้ายห้องพักเรียบร้อย','2026-10-09 13:15:44',NULL),
(9,51,34,'Air',NULL,'ร้อน','2026-10-17','Pending',NULL,'2026-10-09 15:32:17',NULL),
(10,50,47,'Air',38,'ต้องการย้ายไปอยู่ห้องแอร์ขอย้ายไปอยู่ชั้น2นะคะ','2026-10-31','Approved','ยินดีนะคะ อิอิ','2026-10-09 16:12:22','uploads/1791562342_roomchange_slip_20afc345.jpg'),
(12,54,59,'Air',70,'ฮ้อนแก่','2027-01-01','Rejected','ไม่ให้ย้ายจ้าาาา','2026-11-25 10:34:28','uploads/1795602868_roomchange_slip_72896e43.png'),
(13,54,59,'Air',60,'ร้อนนนนนนนนนนนนนนนนนนนนนนนนนนนนน','2027-01-01','Rejected','มะห้าย','2026-11-25 12:06:49','uploads/1795608409_roomchange_slip_0dcf6334.png'),
(14,54,59,'Air',NULL,'ร้อนเกินไปทนไม่ไหวจ้าาา','2026-12-01','Rejected','ไม่ให้ย้ายจ้า นอนร้อนๆไปนะ','2026-11-26 06:12:32','uploads/1795673552_roomchange_slip_c048e33f.png'),
(15,54,59,'Air',82,'ร้อนใจ ร้อนตับ','2026-12-01','Rejected','บ่','2026-11-26 12:48:46','uploads/1795697326_roomchange_slip_b58e3246.png'),
(16,54,59,'Fan',53,'มิรู้ๆ','2026-12-01','Rejected','ไม่ให้ค่าาา','2026-11-26 16:05:20','uploads/1795709120_roomchange_slip_9676a084.png'),
(17,69,34,'Air',59,'ร้อน','2026-12-01','Rejected','ไม่','2026-11-26 18:44:47','uploads/1795718687_roomchange_slip_cd46d9e8.png');
/*!40000 ALTER TABLE `roomchangerequests` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `roomchecklist`
--

DROP TABLE IF EXISTS `roomchecklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roomchecklist` (
  `checklistId` int(11) NOT NULL AUTO_INCREMENT,
  `roomId` int(11) NOT NULL,
  `itemName` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT 'เฟอร์นิเจอร์',
  `quantity` int(11) DEFAULT 1,
  `itemStatus` varchar(50) DEFAULT 'Good',
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `estimatedPrice` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`checklistId`),
  KEY `roomId` (`roomId`),
  CONSTRAINT `roomchecklist_ibfk_1` FOREIGN KEY (`roomId`) REFERENCES `room` (`roomId`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=671 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roomchecklist`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `roomchecklist` WRITE;
/*!40000 ALTER TABLE `roomchecklist` DISABLE KEYS */;
INSERT INTO `roomchecklist` VALUES
(1,38,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(2,38,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(3,38,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(4,38,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(5,38,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(7,38,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(8,38,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(9,38,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(10,38,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Damaged','ซักทำความสะอาดแล้ว','2026-10-09 17:34:25',200.00),
(11,36,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','','2026-10-09 16:55:00',500.00),
(12,36,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(13,36,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(14,36,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(15,36,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(16,36,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(17,36,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(18,36,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(19,36,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(20,36,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(51,49,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(52,49,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(53,49,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(54,49,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(55,49,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(56,49,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(57,49,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(58,49,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(59,49,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(60,49,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(81,54,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(82,54,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(83,54,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(84,54,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(85,54,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(86,54,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(87,54,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(88,54,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(89,54,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(90,54,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(91,55,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(92,55,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(93,55,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(94,55,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(95,55,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(96,55,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(97,55,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(98,55,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(99,55,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(100,55,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(111,56,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(112,56,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(113,56,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(114,56,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(115,56,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(116,56,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(117,56,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(118,56,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(119,56,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(120,56,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(121,53,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(122,53,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(123,53,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(124,53,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(125,53,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(126,53,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(127,53,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(128,53,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(129,53,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(130,53,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(131,57,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(132,57,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(133,57,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(134,57,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(135,57,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(136,57,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(137,57,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(138,57,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(139,57,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(140,57,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(141,99,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(142,99,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(143,99,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(144,99,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(145,99,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(146,99,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(147,99,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(148,99,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(149,99,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(150,99,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(151,98,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(152,98,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(153,98,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(154,98,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(155,98,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(156,98,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(157,98,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(158,98,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(159,98,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(160,98,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(161,97,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(162,97,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(163,97,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(164,97,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(165,97,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(166,97,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(167,97,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(168,97,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(169,97,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(170,97,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(171,96,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(172,96,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(173,96,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(174,96,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(175,96,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(176,96,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(177,96,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(178,96,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(179,96,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(180,96,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(181,95,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(182,95,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(183,95,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(184,95,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(185,95,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(186,95,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(187,95,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(188,95,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(189,95,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(190,95,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(191,92,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(192,92,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(193,92,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(194,92,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(195,92,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(196,92,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(197,92,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(198,92,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(199,92,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(200,92,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(201,63,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(202,63,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(203,63,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(204,63,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(205,63,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(206,63,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(207,63,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(208,63,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(209,63,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(210,63,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(211,59,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(212,59,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(213,59,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(214,59,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(215,59,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(216,59,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(217,59,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(218,59,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(219,59,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(220,59,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(221,58,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(222,58,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(223,58,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(224,58,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(225,58,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(226,58,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(227,58,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(228,58,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(229,58,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(230,58,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(231,40,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(232,40,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(233,40,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(234,40,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(235,40,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(236,40,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(237,40,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(238,40,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(239,40,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(240,40,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(241,61,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(242,61,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(243,61,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(244,61,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(245,61,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(246,61,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(247,61,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(248,61,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(249,61,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(250,61,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(251,62,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(252,62,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(253,62,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(254,62,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(255,62,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(256,62,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(257,62,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(258,62,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(259,62,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(260,62,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(261,67,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(262,67,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(263,67,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(264,67,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(265,67,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(266,67,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(267,67,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(268,67,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(269,67,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(270,67,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(271,65,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(272,65,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(273,65,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(274,65,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(275,65,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(276,65,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(277,65,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(278,65,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(279,65,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(280,65,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(291,66,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(292,66,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(293,66,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(294,66,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(295,66,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(296,66,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(297,66,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(298,66,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(299,66,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(300,66,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(301,60,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(302,60,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(303,60,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(304,60,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(305,60,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(306,60,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(307,60,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(308,60,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(309,60,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(310,60,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(311,76,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(312,76,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(313,76,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(314,76,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(315,76,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(316,76,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(317,76,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(318,76,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(319,76,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(320,76,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(321,68,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(322,68,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(323,68,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(324,68,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(325,68,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(326,68,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(327,68,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(328,68,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(329,68,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(330,68,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(331,69,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(332,69,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(333,69,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(334,69,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(335,69,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(336,69,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(337,69,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(338,69,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(339,69,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(340,69,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(341,70,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(342,70,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(343,70,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(344,70,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(345,70,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(346,70,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(347,70,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(348,70,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(349,70,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(350,70,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(351,71,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(352,71,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(353,71,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(354,71,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(355,71,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(356,71,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(357,71,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(358,71,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(359,71,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(360,71,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(361,43,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(362,43,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(363,43,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(364,43,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(365,43,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(366,43,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(367,43,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(368,43,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(369,43,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(370,43,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(371,100,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(372,100,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(373,100,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(374,100,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(375,100,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(376,100,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(377,100,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(378,100,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(379,100,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(380,100,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(381,72,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(382,72,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(383,72,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(384,72,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(385,72,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(386,72,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(387,72,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(388,72,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(389,72,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(390,72,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(391,77,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(392,77,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(393,77,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(394,77,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(395,77,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(396,77,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(397,77,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(398,77,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(399,77,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(400,77,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(401,73,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(402,73,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(403,73,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(404,73,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(405,73,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(406,73,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(407,73,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(408,73,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(409,73,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(410,73,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(411,74,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(412,74,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(413,74,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(414,74,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(415,74,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(416,74,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(417,74,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(418,74,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(419,74,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(420,74,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(421,81,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(422,81,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(423,81,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(424,81,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(425,81,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(426,81,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(427,81,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(428,81,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(429,81,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(430,81,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(431,75,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(432,75,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(433,75,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(434,75,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(435,75,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(436,75,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(437,75,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(438,75,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(439,75,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(440,75,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(441,78,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(442,78,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(443,78,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(444,78,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(445,78,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(446,78,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(447,78,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(448,78,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(449,78,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(450,78,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(451,79,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(452,79,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(453,79,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(454,79,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(455,79,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(456,79,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(457,79,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(458,79,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(459,79,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(460,79,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(461,82,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(462,82,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(463,82,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(464,82,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(465,82,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(466,82,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(467,82,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(468,82,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(469,82,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(470,82,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(471,83,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(472,83,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(473,83,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(474,83,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(475,83,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(476,83,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(477,83,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(478,83,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(479,83,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(480,83,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(481,84,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(482,84,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(483,84,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(484,84,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(485,84,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(486,84,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(487,84,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(488,84,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(489,84,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(490,84,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(491,80,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(492,80,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(493,80,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(494,80,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(495,80,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(496,80,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(497,80,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(498,80,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(499,80,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(500,80,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(501,85,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(502,85,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(503,85,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(504,85,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(505,85,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(506,85,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(507,85,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(508,85,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(509,85,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(510,85,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(511,86,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(512,86,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(513,86,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(514,86,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(515,86,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(516,86,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(517,86,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(518,86,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(519,86,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(520,86,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(521,87,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(522,87,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(523,87,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(524,87,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(525,87,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(526,87,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(527,87,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(528,87,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(529,87,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(530,87,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(531,46,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(532,46,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(533,46,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(534,46,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(535,46,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(536,46,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(537,46,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(538,46,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(539,46,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(540,46,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(541,93,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(542,93,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(543,93,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(544,93,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(545,93,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(546,93,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(547,93,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(548,93,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(549,93,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(550,93,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(551,88,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(552,88,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(553,88,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(554,88,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(555,88,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(556,88,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(557,88,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(558,88,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(559,88,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(560,88,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(561,94,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(562,94,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(563,94,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(564,94,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(565,94,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(566,94,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(567,94,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(568,94,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(569,94,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(570,94,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(571,91,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(572,91,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(573,91,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(574,91,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(575,91,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(576,91,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(577,91,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(578,91,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(579,91,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(580,91,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(581,89,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(582,89,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(583,89,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(584,89,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(585,89,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(586,89,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(587,89,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(588,89,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(589,89,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(590,89,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(591,90,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-10-09 16:55:00',500.00),
(592,90,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-10-09 16:55:00',450.00),
(593,90,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-10-09 16:55:00',350.00),
(594,90,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-10-09 16:55:00',250.00),
(595,90,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-10-09 16:55:00',500.00),
(596,90,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-10-09 16:55:00',400.00),
(597,90,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-10-09 16:55:00',300.00),
(598,90,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-10-09 16:55:00',150.00),
(599,90,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-10-09 16:55:00',100.00),
(600,90,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-10-09 16:55:00',200.00),
(601,48,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-10 04:51:48',500.00),
(602,48,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-10 04:51:48',450.00),
(603,48,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-10 04:51:48',350.00),
(604,48,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-10 04:51:48',250.00),
(605,48,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-10 04:51:48',200.00),
(606,48,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-10 04:51:48',500.00),
(607,48,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-11-10 04:51:48',400.00),
(608,48,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-10 04:51:48',100.00),
(609,48,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-10 04:51:48',300.00),
(610,48,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-10 04:51:48',150.00),
(611,50,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-22 13:21:20',500.00),
(612,50,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-22 13:21:20',450.00),
(613,50,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-22 13:21:20',350.00),
(614,50,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-22 13:21:20',250.00),
(615,50,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-22 13:21:20',200.00),
(616,50,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-22 13:21:20',500.00),
(617,50,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-11-22 13:21:20',400.00),
(618,50,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-22 13:21:20',100.00),
(619,50,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-22 13:21:20',300.00),
(620,50,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-22 13:21:20',150.00),
(621,47,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-25 10:16:22',500.00),
(622,47,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-25 10:16:22',450.00),
(623,47,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-25 10:16:22',350.00),
(624,47,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-25 10:16:22',250.00),
(625,47,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-25 10:16:22',200.00),
(626,47,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-25 10:16:22',500.00),
(627,47,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-11-25 10:16:22',400.00),
(628,47,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-25 10:16:22',100.00),
(629,47,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-25 10:16:22',300.00),
(630,47,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-25 10:16:22',150.00),
(631,51,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-26 06:06:51',500.00),
(632,51,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-26 06:06:51',450.00),
(633,51,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-26 06:06:51',350.00),
(634,51,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-26 06:06:51',250.00),
(635,51,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-26 06:06:51',200.00),
(636,51,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-26 06:06:51',500.00),
(637,51,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-11-26 06:06:51',400.00),
(638,51,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-26 06:06:51',100.00),
(639,51,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-26 06:06:51',300.00),
(640,51,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-26 06:06:51',150.00),
(641,52,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-26 12:35:06',500.00),
(642,52,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-26 12:35:06',450.00),
(643,52,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-26 12:35:06',350.00),
(644,52,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-26 12:35:06',250.00),
(645,52,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-26 12:35:06',200.00),
(646,52,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-26 12:35:06',500.00),
(647,52,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-11-26 12:35:06',400.00),
(648,52,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-26 12:35:06',100.00),
(649,52,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-26 12:35:06',300.00),
(650,52,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-26 12:35:06',150.00),
(651,64,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-26 17:21:51',500.00),
(652,64,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-26 17:21:51',450.00),
(653,64,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-26 17:21:51',350.00),
(654,64,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-26 17:21:51',250.00),
(655,64,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-26 17:21:51',200.00),
(656,64,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-26 17:21:51',500.00),
(657,64,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Good','มีสายดินและตัดไฟ ELCB','2026-11-26 17:21:51',400.00),
(658,64,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-26 17:21:51',100.00),
(659,64,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-26 17:21:51',300.00),
(660,64,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-26 17:21:51',150.00),
(661,34,'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)','เฟอร์นิเจอร์',1,'Good','สภาพใหม่ ไม่เลอะ','2026-11-26 18:29:44',500.00),
(662,34,'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน','เฟอร์นิเจอร์',1,'Good','มีไม้แขวนเสื้อ 5 อัน','2026-11-26 18:29:44',450.00),
(663,34,'โต๊ะทำงาน / โต๊ะเขียนหนังสือ','เฟอร์นิเจอร์',1,'Good','มีลิ้นชักพร้อมกุญแจ','2026-11-26 18:29:44',350.00),
(664,34,'เก้าอี้ทำงาน','เฟอร์นิเจอร์',1,'Good','ขาเก้าอี้แข็งแรง','2026-11-26 18:29:44',250.00),
(665,34,'ผ้าม่านกันแสงหน้าต่าง/ระเบียง','เฟอร์นิเจอร์',1,'Good','ซักทำความสะอาดแล้ว','2026-11-26 18:29:44',200.00),
(666,34,'เครื่องปรับอากาศ (รีโมท 1 อัน)','เครื่องใช้ไฟฟ้า',1,'Good','ล้างแอร์เรียบร้อย','2026-11-26 18:29:44',500.00),
(667,34,'เครื่องทำน้ำอุ่น','เครื่องใช้ไฟฟ้า',1,'Damaged','มีสายดินและตัดไฟ ELCB','2026-11-26 18:29:44',400.00),
(668,34,'หลอดไฟส่องสว่าง LED','ระบบไฟฟ้า',2,'Good','เปิดติดครบทุกจุด','2026-11-26 18:29:44',100.00),
(669,34,'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ','ห้องน้ำ',1,'Good','ชักโครก สายฉีดชำระปกติ','2026-11-26 18:29:44',300.00),
(670,34,'คีย์การ์ด / กุญแจเข้าห้องพัก','ระบบความปลอดภัย',2,'Good','กุญแจ 2 ดอก คีย์การ์ด 1 ใบ','2026-11-26 18:29:44',150.00);
/*!40000 ALTER TABLE `roomchecklist` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `servicerequest`
--

DROP TABLE IF EXISTS `servicerequest`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `servicerequest` (
  `requestId` int(11) NOT NULL AUTO_INCREMENT,
  `tenantId` int(11) NOT NULL,
  `roomId` int(11) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `preferredDate` date DEFAULT NULL,
  `preferredTime` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `allowEntry` tinyint(1) DEFAULT 0,
  `requestDate` datetime DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT 'Pending',
  `requestImage` varchar(255) DEFAULT NULL,
  `completionImage` varchar(255) DEFAULT NULL,
  `tenantComment` text DEFAULT NULL,
  `assignedTo` int(11) DEFAULT NULL,
  `commentedAt` datetime DEFAULT NULL,
  `completedAt` datetime DEFAULT NULL,
  `assignedAt` datetime DEFAULT NULL,
  `estimatedHours` int(11) DEFAULT 2,
  `staffComment` text DEFAULT NULL,
  `staffCommentedAt` datetime DEFAULT NULL,
  `adminApprovedAt` datetime DEFAULT NULL,
  `adminRemark` text DEFAULT NULL,
  PRIMARY KEY (`requestId`),
  KEY `tenantId` (`tenantId`),
  KEY `roomId` (`roomId`),
  KEY `assignedTo` (`assignedTo`),
  CONSTRAINT `servicerequest_ibfk_1` FOREIGN KEY (`tenantId`) REFERENCES `user` (`userId`) ON DELETE CASCADE,
  CONSTRAINT `servicerequest_ibfk_2` FOREIGN KEY (`roomId`) REFERENCES `room` (`roomId`) ON DELETE CASCADE,
  CONSTRAINT `servicerequest_ibfk_3` FOREIGN KEY (`assignedTo`) REFERENCES `user` (`userId`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `servicerequest`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `servicerequest` WRITE;
/*!40000 ALTER TABLE `servicerequest` DISABLE KEYS */;
INSERT INTO `servicerequest` VALUES
(61,69,34,'ไฟฟ้า/หลอดไฟ','ไฟอ่อน','2026-11-27','ช่วงบ่าย-เย็น (เวลา 13:00 - 15:00 น.)',0,'2026-11-27 01:36:55','Completed','uploads/1795718215_req_6b087c47ec588.webp','uploads/completions/1795718332_done_5.png','ประทับใจ',42,'2026-11-27 01:40:13','2026-11-27 01:38:52','2026-11-27 01:37:22',2,'ดีงาม','2026-11-27 01:38:52','2026-11-27 01:39:52',NULL);
/*!40000 ALTER TABLE `servicerequest` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `technician`
--

DROP TABLE IF EXISTS `technician`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `technician` (
  `technicianId` int(11) NOT NULL,
  `skill` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Available',
  PRIMARY KEY (`technicianId`),
  CONSTRAINT `technician_ibfk_1` FOREIGN KEY (`technicianId`) REFERENCES `user` (`userId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technician`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `technician` WRITE;
/*!40000 ALTER TABLE `technician` DISABLE KEYS */;
/*!40000 ALTER TABLE `technician` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `tenant`
--

DROP TABLE IF EXISTS `tenant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant` (
  `tenantId` int(11) NOT NULL,
  PRIMARY KEY (`tenantId`),
  CONSTRAINT `tenant_ibfk_1` FOREIGN KEY (`tenantId`) REFERENCES `user` (`userId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tenant`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `tenant` WRITE;
/*!40000 ALTER TABLE `tenant` DISABLE KEYS */;
/*!40000 ALTER TABLE `tenant` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `userId` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `fullName` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Active',
  `role` enum('Admin','Tenant','Technician','Cleaner') NOT NULL DEFAULT 'Tenant',
  `emergencyName` varchar(100) DEFAULT NULL,
  `emergencyPhone` varchar(20) DEFAULT NULL,
  `vehiclePlate` varchar(50) DEFAULT NULL,
  `idCard` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `vehicleProvince` varchar(100) DEFAULT NULL,
  `vehicleType` varchar(30) DEFAULT 'Car',
  PRIMARY KEY (`userId`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES
(1,'admin','$2y$10$uMJyB3Wm3uZ29U.cseJYue/zbabWP.xyMH95LHuWESQV5D3aQoa9.','ผู้ดูแลระบบสูงสุด',NULL,NULL,'Active','Admin',NULL,NULL,NULL,NULL,NULL,NULL,'Car'),
(42,'wit','$2y$10$IsjoUPKK.YeLsF89C5JNPONcABkIXE2inlrGXxiIn93WNq9Q5qtSi','wit','09568223378',NULL,'Active','Technician',NULL,NULL,NULL,NULL,NULL,NULL,'Car'),
(59,'dabee','$2y$12$Y.Hod2cazlrpfGSrs6Uj/ewhLJ1og1ZVDz3PNvGA4I2yWI1PDPlI.','นางสาวดาบี พงษ์สมบัติ','098765321',NULL,'Active','Cleaner',NULL,NULL,NULL,NULL,NULL,NULL,'Car'),
(61,'fluke','$2y$12$s3/L39BPVdL5GKdVWwb0vejxuyfDt4YWuqUEcsWXff4NDQeAtoqDK','ฟลุ๊คกี้','0987654321','','Active','Technician',NULL,NULL,NULL,NULL,NULL,NULL,'Car'),
(63,'testtest','$2y$12$1LLCNiu9bAxCUrOCA.clieStwXmnAb5KMBsQQIa5EZd9y66FF.9gi','test','0999999999','done34gs@gmail.com','Active','Tenant','','',NULL,NULL,NULL,NULL,'Car'),
(65,'aya','$2y$12$KDcIlbdvhzVBT8bWvoJDp.0eUo1wEIVj0woapMoDEUQVd7ouxytqi','เทส เทส','0222222222','test@gmail.com','Active','Tenant','','',NULL,NULL,NULL,NULL,'Car'),
(66,'test','$2y$12$B8BhDD8HS0flT4wxjS2.3OgIUznjxGEGoN3xFPKpGJp5JRLc1SlYu','หนู เทส','0255444444','test1@gmail.com','Active','Tenant','','','9ขร  879','1444444444444','','กรุงเทพมหานคร','Car'),
(67,'testjaaa','$2y$12$GF4SuBO5IItnAbRORvvCfORw12fM2UZwJcBJxK.68xYY7VzPBMLF.','หนู เทสเทสๆ','0255444477','test2@gmail.com','Active','Tenant','','','1กอ  123','1444444444441','','กระบี่','Motorcycle'),
(68,'testtttt','$2y$12$btlQzKKE6zISS9VQ0L9At./oV/0J7FbTPKqfnUI3EOqrMHZskCSqq','เทสสสสส','0666666611','test3@gmail.com','Active','Tenant','','','กจ 5477','1444444447777','','อุตรดิตถ์','Car'),
(69,'preaw26','$2y$12$DAB.cDkTXjeBqHA/6Z/k2OLVZ8XHOZkDCaw2MkE0nMoniU4AD4/7C','อทิตยา พงษ์สมบัติ','0882815948','preawxx26@gmail.com','Active','Tenant','','','1กอ  123','1550012398745','','กรุงเทพมหานคร','Car');
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `utilityrate`
--

DROP TABLE IF EXISTS `utilityrate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilityrate` (
  `utilityRateId` int(11) NOT NULL AUTO_INCREMENT,
  `waterRatePerUnit` decimal(10,2) NOT NULL,
  `electricRatePerUnit` decimal(10,2) NOT NULL,
  `effectiveDate` date NOT NULL,
  PRIMARY KEY (`utilityRateId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `utilityrate`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `utilityrate` WRITE;
/*!40000 ALTER TABLE `utilityrate` DISABLE KEYS */;
/*!40000 ALTER TABLE `utilityrate` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-11-27 14:57:50
