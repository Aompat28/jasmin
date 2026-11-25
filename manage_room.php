<?php

session_start();

require_once 'includes/db_connect.php';



// ตรวจสอบสิทธิ์ ต้องเป็น Admin

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {

    header("Location: login.php");

    exit();

}



$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';

$initials = strtoupper(mb_substr($adminName, 0, 2, 'UTF-8'));

$message = "";



// 🌟 สร้างตาราง LeaseRenewals อัตโนมัติ (ถ้ายังไม่มี)

try {

    $conn->exec("CREATE TABLE IF NOT EXISTS LeaseRenewals (

        renewalId INT AUTO_INCREMENT PRIMARY KEY,

        leaseId INT NOT NULL,

        tenantId INT NOT NULL,

        roomId INT NOT NULL,

        extensionMonths INT NOT NULL,

        status VARCHAR(50) DEFAULT 'Pending',

        adminRemark TEXT DEFAULT NULL,

        leaseImage VARCHAR(255) DEFAULT NULL,

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");



    $conn->exec("ALTER TABLE LeaseRenewals ADD COLUMN leaseImage VARCHAR(255) DEFAULT NULL;");

} catch(PDOException $e) {}



// 🌟 สร้างตาราง RoomChangeRequests อัตโนมัติ (ถ้ายังไม่มี)

try {

    $conn->exec("CREATE TABLE IF NOT EXISTS RoomChangeRequests (

        requestId INT AUTO_INCREMENT PRIMARY KEY,

        tenantId INT NOT NULL,

        currentRoomId INT NOT NULL,

        targetRoomType VARCHAR(50) DEFAULT NULL,

        targetRoomId INT DEFAULT NULL,

        reason TEXT NOT NULL,

        preferredMoveDate DATE DEFAULT NULL,

        status VARCHAR(50) DEFAULT 'Pending',

        adminRemark TEXT DEFAULT NULL,

        paymentSlip VARCHAR(255) DEFAULT NULL,

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $conn->exec("ALTER TABLE RoomChangeRequests ADD COLUMN paymentSlip VARCHAR(255) DEFAULT NULL;");

} catch(PDOException $e) {}



try { $conn->exec("ALTER TABLE Lease ADD COLUMN tenantPaymentSlip VARCHAR(255) DEFAULT NULL;"); } catch(PDOException $e) {}



// ==========================================

// 🌟 1. ระบบจัดการห้องพัก (ROOM ACTIONS) 🌟

// ==========================================



// --- 1.1 เพิ่มห้องพักใหม่ ---

if (isset($_POST['add_room'])) {

    $roomNumber = trim($_POST['roomNumber']);

    $roomType = $_POST['roomType'];

    $floor = intval($_POST['floor']);

    // ล็อกราคาห้องพักตายตัว: ห้องพัดลม 3,500 บาท / ห้องแอร์ 4,000 บาท

    $rentPrice = ($roomType === 'Fan') ? 3500.00 : 4000.00;



    try {

        $stmt = $conn->prepare("INSERT INTO Room (roomNumber, roomType, floor, rentPrice, status) VALUES (?, ?, ?, ?, 'Available')");

        $stmt->execute([$roomNumber, $roomType, $floor, $rentPrice]);

        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> เพิ่มห้องพักใหม่ {$roomNumber} สำเร็จ! (สถานะ: ห้องว่าง พร้อมให้เช่า)</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่สามารถเพิ่มห้องได้: เลขห้องนี้อาจมีอยู่ในระบบแล้ว</div>";

    }

}



// --- 1.2 แก้ไขข้อมูลห้องพัก ---

if (isset($_POST['edit_room'])) {

    $roomId = intval($_POST['roomId']);

    $roomNumber = trim($_POST['roomNumber']);

    $roomType = $_POST['roomType'];

    $floor = intval($_POST['floor']);

    // ล็อกราคาห้องพักตายตัว: ห้องพัดลม 3,500 บาท / ห้องแอร์ 4,000 บาท

    $rentPrice = ($roomType === 'Fan') ? 3500.00 : 4000.00;



    try {

        $stmt = $conn->prepare("UPDATE Room SET roomNumber = ?, roomType = ?, floor = ?, rentPrice = ? WHERE roomId = ?");

        $stmt->execute([$roomNumber, $roomType, $floor, $rentPrice, $roomId]);

        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> แก้ไขข้อมูลห้อง {$roomNumber} สำเร็จ!</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 1.3 ลบห้องพัก ---

if (isset($_POST['delete_room'])) {

    $roomId = intval($_POST['roomId']);

    try {

        $stmt = $conn->prepare("DELETE FROM Room WHERE roomId = ?");

        $stmt->execute([$roomId]);

        $message = "<div class='alert-box p-4 mb-6 bg-stone-50 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ลบห้องพักออกจากระบบสำเร็จ!</div>";

    } catch(PDOException $e) {

        if ($e->getCode() == 23000) {

            $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> ไม่สามารถลบห้องได้: ห้องนี้ยังมีประวัติสัญญาเช่า ข้อมูลบิล หรือประวัติแจ้งซ่อมผูกอยู่</div>";

        } else {

            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

        }

    }

}



// ==========================================

// 🌟 2. ระบบคำขอต่อสัญญา (RENEWAL ACTIONS) 🌟

// ==========================================



// --- 2.1 อนุมัติการต่อสัญญา ---

if (isset($_POST['approve_renewal'])) {

    $renewalId = intval($_POST['renewal_id']);

    $leaseId = intval($_POST['lease_id']);

    $months = intval($_POST['extension_months']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : "อนุมัติต่อสัญญาเช่าเพิ่ม {$months} เดือนเรียบร้อยแล้ว";



    // อัปโหลดเอกสารสัญญาใหม่ (ถ้ามี)

    $leaseImage = null;

    if (isset($_FILES['leaseImage']) && $_FILES['leaseImage']['error'] == 0 && !empty($_FILES['leaseImage']['tmp_name'])) {

        $uploadDir = 'uploads/';

        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        $fileExt = strtolower(pathinfo($_FILES['leaseImage']['name'], PATHINFO_EXTENSION));

        $fileName = time() . '_renew_lease_' . $leaseId . '.' . $fileExt;

        $targetFilePath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['leaseImage']['tmp_name'], $targetFilePath)) {

            $leaseImage = $targetFilePath;

        }

    }



    try {

        $conn->beginTransaction();



        // อัปเดตวันสิ้นสุดสัญญาในตาราง Lease

        $stmtUpdateLease = $conn->prepare("UPDATE Lease SET endDate = DATE_ADD(endDate, INTERVAL ? MONTH) WHERE leaseId = ?");

        $stmtUpdateLease->execute([$months, $leaseId]);



        // ถ้ามีการแนบสัญญาใหม่ ให้อัปเดตไฟล์สัญญาในตาราง Lease ด้วย

        if (!empty($leaseImage)) {

            $stmtImg = $conn->prepare("UPDATE Lease SET leaseImage = ? WHERE leaseId = ?");

            $stmtImg->execute([$leaseImage, $leaseId]);

        }



        // อัปเดตสถานะในตาราง LeaseRenewals

        $stmtUpdateStatus = $conn->prepare("UPDATE LeaseRenewals SET status = 'Approved', adminRemark = ?, leaseImage = COALESCE(?, leaseImage) WHERE renewalId = ?");

        $stmtUpdateStatus->execute([$adminRemark, $leaseImage, $renewalId]);



        // ล้างคำขอต่อสัญญา Pending อื่นๆ ของสัญญานี้

        $stmtCleanPendingRenew = $conn->prepare("DELETE FROM LeaseRenewals WHERE leaseId = ? AND status = 'Pending'");

        $stmtCleanPendingRenew->execute([$leaseId]);



        $conn->commit();



        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> อนุมัติการต่อสัญญาเช่าเรียบร้อยแล้ว! อัปเดตวันสิ้นสุดสัญญาในระบบเพิ่ม {$months} เดือน</div>";

    } catch(PDOException $e) {

        if ($conn->inTransaction()) { $conn->rollBack(); }

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 2.2 ปฏิเสธคำขอต่อสัญญา ---

if (isset($_POST['reject_renewal'])) {

    $renewalId = intval($_POST['renewal_id']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'ไม่อนุมัติการต่อสัญญาเช่า';



    try {

        $stmtReject = $conn->prepare("UPDATE LeaseRenewals SET status = 'Rejected', adminRemark = ? WHERE renewalId = ?");

        $stmtReject->execute([$adminRemark, $renewalId]);

        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='x-circle' class='w-5 h-5 text-amber-600'></i> ปฏิเสธคำขอต่อสัญญาเช่าเรียบร้อยแล้ว</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 2.3 ลบรายการคำขอต่อสัญญา ---

if (isset($_POST['delete_renewal'])) {

    $renewalId = intval($_POST['renewal_id']);

    try {

        $stmtDel = $conn->prepare("DELETE FROM LeaseRenewals WHERE renewalId = ?");

        $stmtDel->execute([$renewalId]);

        $message = "<div class='alert-box p-4 mb-6 bg-stone-50 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ลบรายการคำขอต่อสัญญาออกแล้ว</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// ==========================================

// 🌟 3. ระบบคำขอย้ายห้องพัก (ROOM CHANGE ACTIONS) 🌟

// ==========================================



// --- 3.1 อนุมัติการย้ายห้องพัก ---

if (isset($_POST['approve_room_change'])) {

    $requestId = intval($_POST['request_id']);

    $newRoomId = intval($_POST['new_room_id']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'อนุมัติการย้ายห้องพักเรียบร้อย';



    try {

        $stmtReqInfo = $conn->prepare("SELECT * FROM RoomChangeRequests WHERE requestId = ?");

        $stmtReqInfo->execute([$requestId]);

        $targetReq = $stmtReqInfo->fetch(PDO::FETCH_ASSOC);



        if ($targetReq) {

            $tenantId = $targetReq['tenantId'];

            $oldRoomId = $targetReq['currentRoomId'];



            // 1. เปลี่ยนสถานะห้องเก่าเป็น Available (ห้องว่าง)

            $stmtOldRoom = $conn->prepare("UPDATE Room SET status = 'Available' WHERE roomId = ?");

            $stmtOldRoom->execute([$oldRoomId]);



            // 2. เปลี่ยนสถานะห้องใหม่เป็น Occupied (มีผู้เช่า)

            $stmtNewRoom = $conn->prepare("UPDATE Room SET status = 'Occupied' WHERE roomId = ?");

            $stmtNewRoom->execute([$newRoomId]);



            // 3. อัปเดตสัญญาเช่าปัจจุบัน (Active Lease) ให้ชี้ไปยังห้องใหม่

            $stmtUpdateLease = $conn->prepare("UPDATE Lease SET roomId = ? WHERE tenantId = ? AND status = 'Active'");

            $stmtUpdateLease->execute([$newRoomId, $tenantId]);



            // 4. อัปเดตสถานะคำขอย้ายห้องเป็น Approved

            $stmtApproveReq = $conn->prepare("UPDATE RoomChangeRequests SET status = 'Approved', targetRoomId = ?, adminRemark = ? WHERE requestId = ?");

            $stmtApproveReq->execute([$newRoomId, $adminRemark, $requestId]);



            // ล้างคำขอ Pending อื่นๆ ของผู้เช่าคนนี้

            $stmtCleanPending = $conn->prepare("DELETE FROM RoomChangeRequests WHERE tenantId = ? AND status = 'Pending'");

            $stmtCleanPending->execute([$tenantId]);



            // ดึงเลขห้องใหม่เพื่อแสดงในข้อความสำเร็จ

            $stmtRoomNum = $conn->prepare("SELECT roomNumber FROM Room WHERE roomId = ?");

            $stmtRoomNum->execute([$newRoomId]);

            $newRoomNum = $stmtRoomNum->fetchColumn();



            $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> อนุมัติการย้ายห้องพักเรียบร้อยแล้ว! ผู้เช่าย้ายเข้าสู่ <b>ห้อง {$newRoomNum}</b> สัญญาเช่าถูกอัปเดตอัตโนมัติ</div>";

        }

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 3.2 ปฏิเสธคำขอย้ายห้องพัก ---

if (isset($_POST['reject_room_change'])) {

    $requestId = intval($_POST['request_id']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'ไม่อนุมัติการย้ายห้องพัก';



    try {

        $stmtReject = $conn->prepare("UPDATE RoomChangeRequests SET status = 'Rejected', adminRemark = ? WHERE requestId = ?");

        $stmtReject->execute([$adminRemark, $requestId]);

        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='x-circle' class='w-5 h-5 text-amber-600'></i> ปฏิเสธคำขอย้ายห้องพักเรียบร้อยแล้ว</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 3.3 ลบรายการคำขอย้ายห้องพัก ---

if (isset($_POST['delete_room_change'])) {

    $requestId = intval($_POST['request_id']);

    try {

        $stmtDel = $conn->prepare("DELETE FROM RoomChangeRequests WHERE requestId = ?");

        $stmtDel->execute([$requestId]);

        $message = "<div class='alert-box p-4 mb-6 bg-stone-50 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ลบรายการคำขอย้ายห้องออกแล้ว</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// ==========================================

// 🌟 4. ระบบแจ้งย้ายออก (MOVE-OUT ACTIONS) 🌟

// ==========================================



// --- 4.1 ยกเลิกคำขอแจ้งย้ายออก ---

if (isset($_POST['cancel_move_out'])) {

    $leaseId = intval($_POST['lease_id']);

    try {

        $stmtCancel = $conn->prepare("UPDATE Lease SET moveOutRequestDate = NULL, moveOutReason = NULL, moveOutStatus = NULL WHERE leaseId = ?");

        $stmtCancel->execute([$leaseId]);

        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> ยกเลิกคำขอแจ้งย้ายออกของสัญญาเช่านี้เรียบร้อยแล้ว</div>";

    } catch(PDOException $e) {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// ==========================================

// 🌟 3. ดึงข้อมูลและคำนวณสถิติ (DATA FETCHING) 🌟

// ==========================================



// 3.1 ซิงก์อัปเดตสถานะห้องพักในฐานข้อมูลให้ตรงกับสัญญาเช่าจริงที่เปิดใช้งานอยู่

try {

    $conn->exec("

        UPDATE Room 

        SET status = 'Available' 

        WHERE status = 'Occupied' 

          AND roomId NOT IN (SELECT roomId FROM Lease WHERE status = 'Active')

    ");

} catch(PDOException $e) {}



// 3.2 ดึงข้อมูลห้องพัก + ข้อมูลผู้เช่า & สัญญาเช่าปัจจุบัน

$rooms = $conn->query("

    SELECT r.*, 

           l.leaseId, 

           l.startDate,

           l.endDate,

           l.depositAmount,

           l.leaseImage,

           l.moveOutRequestDate,

           l.moveOutReason,

           l.moveOutStatus,

           l.bankName,

           l.bankAccount,

           l.accountName,

           l.tenantPaymentSlip,

           l.settledAt,

           l.netRefundAmount,

           u.fullName AS tenantName,

           u.phone,

           u.email,

           u.emergencyName,

           u.emergencyPhone

    FROM Room r 

    LEFT JOIN Lease l ON r.roomId = l.roomId AND l.status = 'Active' 

    LEFT JOIN User u ON l.tenantId = u.userId 

    ORDER BY r.roomNumber ASC

")->fetchAll(PDO::FETCH_ASSOC);



// 3.3 ดึงข้อมูลคำขอต่อสัญญาทั้งหมด (LeaseRenewals)

$stmtReqs = $conn->prepare("

    SELECT lr.*, u.fullName, u.phone AS tenantPhone, r.roomNumber, r.rentPrice, l.startDate, l.endDate AS currentEndDate, l.depositAmount, l.leaseImage

    FROM LeaseRenewals lr

    JOIN User u ON lr.tenantId = u.userId

    JOIN Room r ON lr.roomId = r.roomId

    JOIN Lease l ON lr.leaseId = l.leaseId

    ORDER BY FIELD(lr.status, 'Pending', 'Approved', 'Rejected'), lr.renewalId DESC

");

$stmtReqs->execute();

$renewals = $stmtReqs->fetchAll(PDO::FETCH_ASSOC);



// สรุปสถิติคำขอต่อสัญญา

$totalRenewals = count($renewals);

$countPendingRenewals = 0;

$countApprovedRenewals = 0;

$countRejectedRenewals = 0;

$pendingRenewalsByRoom = [];



foreach($renewals as $ren) {

    if ($ren['status'] === 'Pending') {

        $countPendingRenewals++;

        if (!isset($pendingRenewalsByRoom[$ren['roomId']])) {

            $pendingRenewalsByRoom[$ren['roomId']] = $ren;

        }

    } elseif ($ren['status'] === 'Approved') {

        $countApprovedRenewals++;

    } elseif ($ren['status'] === 'Rejected') {

        $countRejectedRenewals++;

    }

}



// 3.4 สรุปสถิติห้องพัก

$totalRooms = count($rooms);

$occupiedCount = 0;

$moveOutCount = 0;

foreach($rooms as $r) {

    if ($r['status'] == 'Occupied' && !empty($r['tenantName']) && !empty($r['leaseId'])) {

        $occupiedCount++;

    }

    if (!empty($r['moveOutRequestDate'])) {

        $moveOutCount++;

    }

}

$availableCount = $totalRooms - $occupiedCount;

$occupancyRate = $totalRooms > 0 ? round(($occupiedCount / $totalRooms) * 100) : 0;



// 3.5 ดึงข้อมูลใบแจ้งหนี้ที่มีสลิปรอตรวจสอบแยกตาม roomId

$pendingInvoicesByRoom = [];

try {

    $stmtPendingInv = $conn->query("

        SELECT invoiceId, roomId, billingMonth, totalAmount, slipImage 

        FROM Invoices 

        WHERE status = 'Pending Approval' AND slipImage IS NOT NULL AND slipImage != ''

        ORDER BY invoiceId DESC

    ");

    while($inv = $stmtPendingInv->fetch(PDO::FETCH_ASSOC)) {

        if (!isset($pendingInvoicesByRoom[$inv['roomId']])) {

            $pendingInvoicesByRoom[$inv['roomId']] = [];

        }

        $pendingInvoicesByRoom[$inv['roomId']][] = $inv;

    }

} catch(PDOException $e) {}



// 3.6 ดึงข้อมูลผู้ร่วมอาศัยที่อนุมัติแล้ว

$coresidentsByRoom = [];

try {

    $stmtCores = $conn->query("

        SELECT c.* 

        FROM coresidents c 

        JOIN Lease l ON c.tenantId = l.tenantId AND c.roomId = l.roomId AND l.status = 'Active' 

        WHERE c.status = 'Approved' 

        ORDER BY c.id ASC

    ");

    while($row = $stmtCores->fetch(PDO::FETCH_ASSOC)) {

        $coresidentsByRoom[$row['roomId']][] = [

            'fullName' => $row['fullName'],

            'relationship' => $row['relationship'],

            'phone' => $row['phoneNumber']

        ];

    }

} catch(PDOException $e) {}



// 3.7 ดึงสรุปจำนวนอุปกรณ์ในเช็คลิสต์แยกตาม roomId

$checklistStatsByRoom = [];

try {

    $conn->exec("CREATE TABLE IF NOT EXISTS RoomChecklist (

        checklistId INT AUTO_INCREMENT PRIMARY KEY,

        roomId INT NOT NULL,

        itemName VARCHAR(255) NOT NULL,

        category VARCHAR(100) DEFAULT 'เฟอร์นิเจอร์',

        quantity INT DEFAULT 1,

        itemStatus VARCHAR(50) DEFAULT 'Good',

        notes TEXT DEFAULT NULL,

        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        FOREIGN KEY (roomId) REFERENCES Room(roomId) ON DELETE CASCADE

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");



    $stmtCL = $conn->query("SELECT roomId, COUNT(*) as total, SUM(CASE WHEN itemStatus IN ('Damaged', 'Missing', 'Repairing') THEN 1 ELSE 0 END) as issueCount FROM RoomChecklist GROUP BY roomId");

    while($row = $stmtCL->fetch(PDO::FETCH_ASSOC)) {

        $checklistStatsByRoom[$row['roomId']] = $row;

    }

} catch(PDOException $e) {}



// 3.8 ดึงข้อมูลคำขอย้ายห้องพักทั้งหมด (RoomChangeRequests)

$roomChangeRequests = $conn->query("

    SELECT rcr.*, 

           u.fullName, u.phone AS tenantPhone,

           l.leaseId, l.startDate AS leaseStartDate, l.endDate AS leaseEndDate, l.depositAmount AS leaseDeposit, l.leaseImage,

           r_curr.roomNumber AS currRoomNumber, r_curr.roomType AS currRoomType, r_curr.rentPrice AS currRentPrice, r_curr.floor AS currFloor,

           r_target.roomNumber AS targetRoomNumber, r_target.roomType AS targetRoomTypeSpecific, r_target.rentPrice AS targetRentPrice

    FROM RoomChangeRequests rcr

    LEFT JOIN User u ON rcr.tenantId = u.userId

    LEFT JOIN Room r_curr ON rcr.currentRoomId = r_curr.roomId

    LEFT JOIN Room r_target ON rcr.targetRoomId = r_target.roomId

    LEFT JOIN Lease l ON (l.tenantId = rcr.tenantId AND l.status = 'Active')

    ORDER BY FIELD(rcr.status, 'Pending', 'Approved', 'Rejected'), rcr.requestId DESC

")->fetchAll(PDO::FETCH_ASSOC);



$totalRoomChanges = count($roomChangeRequests);

$countPendingRoomChanges = 0;

$countApprovedRoomChanges = 0;

$countRejectedRoomChanges = 0;

foreach($roomChangeRequests as $rcr) {

    if ($rcr['status'] === 'Pending') $countPendingRoomChanges++;

    elseif ($rcr['status'] === 'Approved') $countApprovedRoomChanges++;

    elseif ($rcr['status'] === 'Rejected') $countRejectedRoomChanges++;

}



// รายการห้องว่างสำหรับ Modal จัดสรรห้องใหม่

$availableRooms = $conn->query("

    SELECT roomId, roomNumber, roomType, floor, rentPrice 

    FROM Room 

    WHERE status = 'Available' 

    ORDER BY roomNumber ASC

")->fetchAll(PDO::FETCH_ASSOC);



// 3.9 ดึงข้อมูลสัญญาที่มีการแจ้งย้ายออกทั้งหมด

$stmtMoveOuts = $conn->prepare("

    SELECT l.*, r.roomNumber, r.roomType, r.floor, r.rentPrice, u.fullName AS tenantName, u.phone AS tenantPhone

    FROM Lease l

    JOIN Room r ON l.roomId = r.roomId

    JOIN User u ON l.tenantId = u.userId

    WHERE l.moveOutRequestDate IS NOT NULL

    ORDER BY 

        CASE WHEN l.status = 'Active' THEN 0 ELSE 1 END,

        l.moveOutRequestDate DESC

");

$stmtMoveOuts->execute();

$allMoveOuts = $stmtMoveOuts->fetchAll(PDO::FETCH_ASSOC);



$totalMoveOuts = count($allMoveOuts);

$countPendingMoveOuts = 0;

$countSettledMoveOuts = 0;

foreach($allMoveOuts as $mo) {

    if ($mo['status'] === 'Active') {

        $countPendingMoveOuts++;

    } else {

        $countSettledMoveOuts++;

    }

}



// กำหนดแท็บเริ่มต้นจาก URL param (?tab=renewals, ?tab=room_changes, ?tab=moveouts หรือ ?tab=rooms)

$initialTab = 'rooms';

if (isset($_GET['tab'])) {

    if ($_GET['tab'] === 'renewals') $initialTab = 'renewals';

    elseif ($_GET['tab'] === 'room_changes') $initialTab = 'room_changes';

    elseif ($_GET['tab'] === 'moveouts') $initialTab = 'moveouts';

} elseif (isset($_GET['filter']) && $_GET['filter'] === 'moveout') {

    $initialTab = 'moveouts';

}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$initialFilter = isset($_GET['filter']) ? trim($_GET['filter']) : 'all';

?>



<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>jasmin</title>

  <script src="https://cdn.tailwindcss.com">
    function selectRoomType(prefix, type) {
        const input = document.getElementById(prefix + 'RoomType');
        if (input) input.value = type;
        const btnAir = document.getElementById('btnAir_' + prefix);
        const btnFan = document.getElementById('btnFan_' + prefix);
        const checkAir = document.getElementById('checkAir_' + prefix);
        const checkFan = document.getElementById('checkFan_' + prefix);

        if (type === 'Air') {
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkAir) checkAir.classList.remove('hidden');
            if (checkFan) checkFan.classList.add('hidden');
        } else {
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkFan) checkFan.classList.remove('hidden');
            if (checkAir) checkAir.classList.add('hidden');
        }
        updateRoomPrice(prefix);
    }

</script>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest">
    function selectRoomType(prefix, type) {
        const input = document.getElementById(prefix + 'RoomType');
        if (input) input.value = type;
        const btnAir = document.getElementById('btnAir_' + prefix);
        const btnFan = document.getElementById('btnFan_' + prefix);
        const checkAir = document.getElementById('checkAir_' + prefix);
        const checkFan = document.getElementById('checkFan_' + prefix);

        if (type === 'Air') {
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkAir) checkAir.classList.remove('hidden');
            if (checkFan) checkFan.classList.add('hidden');
        } else {
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkFan) checkFan.classList.remove('hidden');
            if (checkAir) checkAir.classList.add('hidden');
        }
        updateRoomPrice(prefix);
    }

</script>

  <script>

    tailwind.config = {

      theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } }

    }

  
    function selectRoomType(prefix, type) {
        const input = document.getElementById(prefix + 'RoomType');
        if (input) input.value = type;
        const btnAir = document.getElementById('btnAir_' + prefix);
        const btnFan = document.getElementById('btnFan_' + prefix);
        const checkAir = document.getElementById('checkAir_' + prefix);
        const checkFan = document.getElementById('checkFan_' + prefix);

        if (type === 'Air') {
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkAir) checkAir.classList.remove('hidden');
            if (checkFan) checkFan.classList.add('hidden');
        } else {
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkFan) checkFan.classList.remove('hidden');
            if (checkAir) checkAir.classList.add('hidden');
        }
        updateRoomPrice(prefix);
    }

</script>

  <style>
    body {
      background: radial-gradient(circle at 15% 15%, #0f172a 0%, #080d1a 50%, #030712 100%);
      color: #f1f5f9;
      font-family: 'Prompt', sans-serif;
      min-height: 100vh;
      overflow-x: hidden;
    }
    .glass-card, .boutique-card {
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
      border-radius: 1.5rem;
    }
    .modal-enter { animation: modalFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
  </style>

  <?php include_once 'includes/swal.php'; ?>

  <?php include_once 'includes/theme_head.php'; ?>
</head>

<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white">



  <?php include_once 'includes/admin_sidebar.php'; ?>



  <!-- Top Navigation (Admin) - Warm & Cozy Boutique Header -->
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/70 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-sm ring-1 ring-rose-500/20 shrink-0">
            <i data-lucide="door-open" class="w-5 h-5 text-amber-300"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200 uppercase tracking-wider">ROOMS</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ระบบจัดการห้องพักและสัญญาเช่า</p>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>
           <a href="admin_dashboard.php" class="text-xs sm:text-sm font-bold text-slate-600 hover:text-rose-600 flex items-center gap-1.5 transition px-3 py-1.5 rounded-xl hover:bg-rose-50/70 border border-stone-200/60">
              <i data-lucide="arrow-left" class="w-4 h-4"></i> แดชบอร์ด
           </a>
           <div class="w-px h-5 bg-stone-200"></div>
           <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="text-rose-500 hover:bg-rose-50 hover:text-rose-600 transition p-2 rounded-xl border border-stone-200/80" title="ออกจากระบบ">
              <i data-lucide="log-out" class="w-4 h-4"></i>
           </a>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">

    

    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="door-closed" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ผังห้องพักและสัญญาเช่า</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="key" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ห้องทั้งหมด <?php echo count($rooms ?? []); ?> ห้อง</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ระบบจัดการห้องพักและสัญญาเช่า
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            บริหารผังห้องพัก ตรวจสอบคำขอต่ออายุสัญญาเช่า และดำเนินการย้ายเข้า/ย้ายออกได้ครบในที่เดียว
          </p>
        </div>

        <!-- Quick Top Action Buttons -->
        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <button type="button" onclick="document.getElementById('addRoomModal').classList.remove('hidden')" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="plus-circle" class="w-4 h-4 text-emerald-600"></i>
            <span>เพิ่มห้องใหม่</span>
          </button>
          <a href="check_in_form.php" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 shadow-sm shadow-rose-600/25 active:scale-95 cursor-pointer">
            <i data-lucide="file-signature" class="w-4 h-4"></i>
            <span>ทำสัญญาเช่า (ย้ายเข้า)</span>
          </a>
        </div>
      </div>
    </div>

    <!-- 🌟 MAIN TAB NAVIGATION BAR (4 แท็บหลัก: ผังห้องพัก / คำขอต่อสัญญา / คำขอย้ายห้อง / แจ้งย้ายออก) 🌟 -->
    <div class="mb-6 sm:mb-8 flex items-center justify-between flex-wrap gap-3 border-b border-rose-100/80 pb-4">
      <div class="inline-flex p-1.5 bg-stone-100/90 backdrop-blur-md rounded-2xl border border-stone-200/80 gap-1.5 flex-wrap">
        
        <!-- Tab 1: ผังห้องพัก -->
        <button type="button" id="mainTabBtnRooms" onclick="switchMainTab('rooms')" 
                class="px-4 sm:px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer <?php echo $initialTab === 'rooms' ? 'bg-white text-slate-900 shadow-2xs font-bold border border-slate-200' : 'text-slate-500 hover:text-slate-800 hover:bg-white/60'; ?>">
          <i data-lucide="door-closed" class="w-4 h-4 text-rose-500"></i>
          <span>ผังห้องพัก</span>
          <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold"><?php echo $totalRooms; ?></span>
        </button>

        <!-- Tab 2: คำขอต่อสัญญา -->
        <button type="button" id="mainTabBtnRenewals" onclick="switchMainTab('renewals')" 
                class="px-4 sm:px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer <?php echo $initialTab === 'renewals' ? 'bg-white text-rose-700 shadow-2xs font-bold border border-rose-200' : 'text-slate-500 hover:text-slate-800 hover:bg-white/60'; ?>">
          <i data-lucide="file-check-2" class="w-4 h-4 text-amber-500"></i>
          <span>คำขอต่อสัญญา</span>
          <?php if ($countPendingRenewals > 0): ?>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-rose-500 text-white font-bold animate-pulse"><?php echo $countPendingRenewals; ?> รอ</span>
          <?php else: ?>
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold"><?php echo $totalRenewals; ?></span>
          <?php endif; ?>
        </button>

        <!-- Tab 3: คำขอย้ายห้อง -->
        <button type="button" id="mainTabBtnRoomChanges" onclick="switchMainTab('room_changes')" 
                class="px-4 sm:px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer <?php echo $initialTab === 'room_changes' ? 'bg-white text-rose-700 shadow-2xs font-bold border border-rose-200' : 'text-slate-500 hover:text-slate-800 hover:bg-white/60'; ?>">
          <i data-lucide="arrow-left-right" class="w-4 h-4 text-blue-500"></i>
          <span>คำขอย้ายห้อง</span>
          <?php if ($countPendingRoomChanges > 0): ?>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-amber-500 text-white font-bold animate-pulse"><?php echo $countPendingRoomChanges; ?> รอ</span>
          <?php else: ?>
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold"><?php echo $totalRoomChanges; ?></span>
          <?php endif; ?>
        </button>

        <!-- Tab 4: แจ้งย้ายออก -->
        <button type="button" id="mainTabBtnMoveOuts" onclick="switchMainTab('moveouts')" 
                class="px-4 sm:px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer <?php echo $initialTab === 'moveouts' ? 'bg-white text-rose-700 shadow-2xs font-bold border border-rose-200' : 'text-slate-500 hover:text-slate-800 hover:bg-white/60'; ?>">
          <i data-lucide="log-out" class="w-4 h-4 text-rose-500"></i>
          <span>แจ้งย้ายออก</span>
          <?php if ($countPendingMoveOuts > 0): ?>
            <span class="text-[10px] px-2 py-0.5 rounded-full bg-rose-500 text-white font-bold animate-pulse"><?php echo $countPendingMoveOuts; ?> รอ</span>
          <?php else: ?>
            <span class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-semibold"><?php echo $totalMoveOuts; ?></span>
          <?php endif; ?>
        </button>

      </div>
    </div>

    <!-- ======================================================== -->
    <!-- 🌟 SECTION 1: แท็บผังห้องพัก (ROOMS TAB) 🌟 -->
    <!-- ======================================================== -->
    <div id="tabContentRooms" class="space-y-6 <?php echo $initialTab !== 'rooms' ? 'hidden' : ''; ?>">

        <!-- Summary Stats 4 Cards for Rooms -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6 sm:mb-8">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-semibold mb-1">ห้องพักทั้งหมด</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-900"><?php echo $totalRooms; ?> <span class="text-xs font-normal text-slate-400">ห้อง</span></p>
                    <p class="text-[11px] text-slate-400 mt-0.5">ในระบบทั้งหมด</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
                    <i data-lucide="door-closed" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-emerald-800 font-semibold mb-1">มีผู้เช่าแล้ว</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-emerald-600"><?php echo $occupiedCount; ?> <span class="text-xs font-normal text-slate-400">ห้อง</span></p>
                    <p class="text-[11px] text-emerald-600 font-medium mt-0.5">สัญญา Active</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                    <i data-lucide="user-check" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-blue-200 bg-blue-50/20 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-blue-800 font-semibold mb-1">ห้องว่างพร้อมเช่า</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-blue-600"><?php echo $availableCount; ?> <span class="text-xs font-normal text-slate-400">ห้อง</span></p>
                    <p class="text-[11px] text-blue-600 font-medium mt-0.5">พร้อมทำสัญญาใหม่</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">
                    <i data-lucide="key" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-semibold mb-1">อัตราการเข้าพัก</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-800"><?php echo $occupancyRate; ?>%</p>
                    <p class="text-[11px] text-slate-400 font-medium mt-0.5">Occupancy Rate</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                    <i data-lucide="pie-chart" class="w-6 h-6"></i>
                </div>
            </div>
        </div>

        <!-- Rooms Table Card -->

        <div class="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">

            <!-- Search & Filter Toolbar -->

            <div class="p-4 sm:p-5 border-b border-slate-100 bg-white flex flex-col md:flex-row md:items-center justify-between gap-4">

                <!-- Search Bar -->

                <div class="relative flex-1 max-w-md">

                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>

                    <input 

                        type="text" 

                        id="roomSearchInput" 

                        value="<?php echo htmlspecialchars($search); ?>" 

                        placeholder="ค้นหาเลขห้อง, ชื่อผู้เช่า, เบอร์โทร..." 

                        class="w-full pl-10 pr-9 py-2.5 text-xs sm:text-sm bg-stone-50 border border-stone-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white focus:ring-2 focus:ring-rose-100 transition shadow-2xs text-slate-800 placeholder-slate-400"

                        autocomplete="off"

                    >

                    <button 

                        type="button" 

                        id="clearSearchBtn" 

                        onclick="clearSearch()" 

                        class="<?php echo empty($search) ? 'hidden' : ''; ?> absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 rounded-full hover:bg-slate-100 transition"

                        title="ล้างคำค้นหา"

                    >

                        <i data-lucide="x" class="w-3.5 h-3.5"></i>

                    </button>

                </div>



                <!-- Filter Status Tabs -->

                <div class="flex items-center gap-2 justify-between md:justify-end flex-wrap">

                    <div class="inline-flex p-1 bg-white/80 backdrop-blur-md p-1.5 rounded-2xl border border-rose-200/60 shadow-xs rounded-xl text-xs font-semibold text-slate-600 flex-wrap">

                        <button type="button" onclick="filterByStatus('all')" id="btnFilterAll" class="filter-tab px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-2xs font-bold border border-slate-200">

                            ทั้งหมด (<?php echo $totalRooms; ?>)

                        </button>

                        <button type="button" onclick="filterByStatus('Occupied')" id="btnFilterOccupied" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                            มีผู้เช่า (<?php echo $occupiedCount; ?>)

                        </button>

                        <button type="button" onclick="filterByStatus('Available')" id="btnFilterAvailable" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                            ห้องว่าง (<?php echo $availableCount; ?>)

                        </button>

                        <button type="button" onclick="filterByStatus('MoveOut')" id="btnFilterMoveOut" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 flex items-center gap-1">

                            คำขอย้ายออก <?php if($moveOutCount > 0): ?><span class="bg-rose-500 text-white text-[10px] px-1.5 py-0.2 rounded-full font-bold"><?php echo $moveOutCount; ?></span><?php endif; ?>

                        </button>

                        <button type="button" onclick="filterByStatus('Renewing')" id="btnFilterRenewing" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 flex items-center gap-1">

                            ขอยื่นต่อสัญญา <?php if($countPendingRenewals > 0): ?><span class="bg-rose-500 text-white text-[10px] px-1.5 py-0.2 rounded-full font-bold"><?php echo $countPendingRenewals; ?></span><?php endif; ?>

                        </button>

                    </div>

                    <div class="text-xs text-slate-500 hidden sm:block whitespace-nowrap">

                        แสดง <span id="visibleCount" class="font-bold text-blue-600"><?php echo count($rooms); ?></span> / <?php echo $totalRooms; ?> ห้อง

                    </div>

                </div>

            </div>



            <!-- Table -->

            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">

                    <thead class="bg-slate-50/75 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">

                        <tr>

                            <th class="p-4">ห้อง / ชั้น</th>

                            <th class="p-4">ประเภทห้อง</th>

                            <th class="p-4">ราคา/เดือน</th>

                            <th class="p-4">สถานะ & สัญญาเช่าปัจจุบัน</th>

                            <th class="p-4 text-center">การจัดการ</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100 text-xs text-slate-700" id="roomTableBody">

                        <?php if (empty($rooms)): ?>

                        <tr>

                            <td colspan="5" class="p-12 text-center text-slate-400">

                                <i data-lucide="door-closed" class="w-10 h-10 mx-auto mb-2 text-slate-300"></i>

                                ยังไม่มีข้อมูลห้องพักในระบบ

                            </td>

                        </tr>

                        <?php else: ?>

                        <?php foreach($rooms as $r): 

                            $hasMoveOut = !empty($r['moveOutRequestDate']);

                            $hasRenewal = isset($pendingRenewalsByRoom[$r['roomId']]);

                            $renInfo = $hasRenewal ? $pendingRenewalsByRoom[$r['roomId']] : null;

                            $searchKeywords = mb_strtolower($r['roomNumber'] . ' ' . ($r['tenantName'] ?? '') . ' ' . ($r['phone'] ?? '') . ' ' . ($r['roomType'] == 'Air' ? 'ห้องแอร์ air' : 'ห้องพัดลม fan') . ' ชั้น ' . $r['floor'] . ($hasMoveOut ? ' ย้ายออก moveout' : '') . ($hasRenewal ? ' ต่อสัญญา renew' : ''), 'UTF-8');

                        ?>

                        <tr class="room-row hover:bg-rose-50/30/70 transition <?php echo ($r['status'] == 'Occupied' && !empty($r['tenantName'])) ? 'cursor-pointer' : ''; ?> <?php echo $hasRenewal ? 'bg-rose-50/30' : ($hasMoveOut ? 'bg-rose-50/30' : ''); ?>" 
                            <?php if($r['status'] == 'Occupied' && !empty($r['tenantName'])): ?>
                            onclick="openTenantModal('<?php echo $r['roomNumber']; ?>', '<?php echo htmlspecialchars($r['tenantName'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($r['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($r['emergencyName'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($r['emergencyPhone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', '<?php echo date('d/m/Y', strtotime($r['startDate'])); ?>', '<?php echo date('d/m/Y', strtotime($r['endDate'])); ?>', '<?php echo number_format($r['rentPrice']); ?>', '<?php echo number_format($r['depositAmount'] ?? 0); ?>', '<?php echo htmlspecialchars($r['leaseImage'] ?? '', ENT_QUOTES, 'UTF-8'); ?>', <?php echo htmlspecialchars(json_encode($coresidentsByRoom[$r['roomId']] ?? []), ENT_QUOTES, 'UTF-8'); ?>, '<?php echo $r['roomId']; ?>')"
                            <?php endif; ?>
                            data-search="<?php echo htmlspecialchars($searchKeywords, ENT_QUOTES, 'UTF-8'); ?>"
                            data-status="<?php echo htmlspecialchars($r['status']); ?>"
                            data-has-moveout="<?php echo $hasMoveOut ? '1' : '0'; ?>"
                            data-has-renewal="<?php echo $hasRenewal ? '1' : '0'; ?>">

                            

                            <!-- 1. ห้อง / ชั้น -->

                            <td class="p-4">

                                <div class="font-bold text-base sm:text-lg text-slate-900 flex items-center gap-2">

                                    <span class="px-2.5 py-1 rounded-xl bg-slate-50 text-slate-900 font-mono text-sm shadow-2xs border border-slate-200">

                                        ห้อง <?php echo $r['roomNumber']; ?>

                                    </span>

                                    <?php if($hasRenewal): ?>

                                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping" title="มีคำขอต่อสัญญาเช่า"></span>

                                    <?php elseif($hasMoveOut): ?>

                                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500 animate-ping" title="มีคำขอแจ้งย้ายออก"></span>

                                    <?php endif; ?>

                                </div>

                                <div class="text-[11px] text-slate-400 mt-1">ชั้น <?php echo $r['floor']; ?></div>

                            </td>



                            <!-- 2. ประเภทห้อง -->

                            <td class="p-4">

                                <div class="font-semibold text-slate-900"><?php echo $r['roomType'] == 'Air' ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?></div>

                                <div class="text-[11px] text-slate-400">ขนาดมาตรฐาน</div>

                            </td>



                            <!-- 3. ราคา/เดือน -->

                            <td class="p-4">

                                <span class="font-bold text-slate-900 text-sm">฿<?php echo number_format($r['rentPrice']); ?></span>

                                <span class="text-[11px] text-slate-400">/ด.</span>

                            </td>



                            <!-- 4. สถานะ & สัญญาเช่าปัจจุบัน -->

                            <td class="p-4">

                                <?php if($r['status'] == 'Occupied' && !empty($r['tenantName'])): ?>

                                    <div class="flex items-start gap-3">

                                        <div class="w-8 h-8 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 font-bold text-xs mt-0.5">

                                            <?php echo strtoupper(mb_substr($r['tenantName'], 0, 1, 'UTF-8')); ?>

                                        </div>

                                        <div>

                                            <div class="font-bold text-slate-900 text-xs sm:text-sm flex items-center gap-1.5">

                                                <span><?php echo htmlspecialchars($r['tenantName']); ?></span>

                                            </div>

                                            <div class="text-[11px] text-slate-500 mt-0.5">📞 <?php echo htmlspecialchars($r['phone'] ?? '-'); ?></div>



                                            <!-- 🌟 Badge แสดงคำขอต่อสัญญาเช่า 🌟 -->

                                            <?php if($hasRenewal): 

                                                $curEndObj = new DateTime($renInfo['currentEndDate']);

                                                $newEndObj = clone $curEndObj;

                                                $newEndObj->modify("+" . $renInfo['extensionMonths'] . " months");

                                            ?>

                                                <div class="mt-2 p-2.5 bg-rose-50 border border-rose-200 rounded-xl text-xs space-y-1.5 shadow-2xs">

                                                    <div class="flex items-center justify-between gap-2">

                                                        <span class="font-bold text-indigo-900 flex items-center gap-1">

                                                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-rose-600 animate-spin" style="animation-duration: 5s;"></i>

                                                            ขอยื่นต่อสัญญา: <span class="text-rose-700 font-extrabold">+<?php echo $renInfo['extensionMonths']; ?> เดือน</span>

                                                        </span>

                                                        <button type="button" 

                                                                onclick="openApproveModal('<?php echo $renInfo['renewalId']; ?>', '<?php echo $renInfo['leaseId']; ?>', '<?php echo $renInfo['extensionMonths']; ?>', '<?php echo htmlspecialchars($r['tenantName'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($r['roomNumber'], ENT_QUOTES); ?>', '<?php echo $newEndObj->format('d/m/Y'); ?>')"

                                                                class="px-2.5 py-1 bg-rose-500 hover:bg-rose-600 text-white rounded-lg text-[10px] font-bold shadow-2xs transition flex items-center gap-1 cursor-pointer">

                                                            <i data-lucide="check" class="w-3 h-3"></i> อนุมัติต่อสัญญา

                                                        </button>

                                                    </div>

                                                    <div class="text-[10px] text-rose-700 flex items-center justify-between gap-2">

                                                        <span>วันสิ้นสุดสัญญาใหม่: <strong class="font-mono"><?php echo $newEndObj->format('d/m/Y'); ?></strong></span>

                                                        <button type="button" onclick="switchMainTab('renewals')" class="text-rose-600 underline hover:text-indigo-800">ดูทั้งหมด</button>

                                                    </div>

                                                </div>

                                            <?php endif; ?>



                                            <!-- 🌟 Badge แสดงคำขอย้ายออก 🌟 -->

                                            <?php if($hasMoveOut): ?>

                                                <div class="mt-2 p-2.5 bg-rose-50/90 border border-rose-300 rounded-xl text-xs space-y-1.5 shadow-2xs">

                                                    <div class="font-bold text-rose-900 flex items-center justify-between gap-1">

                                                        <span class="flex items-center gap-1">

                                                            <i data-lucide="bell-ring" class="w-3.5 h-3.5 text-rose-600 animate-bounce"></i> 

                                                            แจ้งย้ายออก: <span class="font-mono text-rose-700"><?php echo date('d/m/Y', strtotime($r['moveOutRequestDate'])); ?></span>

                                                        </span>

                                                    </div>

                                                    <?php if(!empty($r['moveOutReason'])): ?>

                                                        <div class="text-[11px] text-rose-800 font-medium">เหตุผล: <?php echo htmlspecialchars($r['moveOutReason']); ?></div>

                                                    <?php endif; ?>

                                                </div>

                                            <?php else: ?>

                                                <?php 

                                                    $daysLeft = (strtotime($r['endDate']) - time()) / (60 * 60 * 24);

                                                    if($daysLeft <= 45 && $daysLeft >= 0):

                                                ?>

                                                    <span class="inline-block mt-1 px-2 py-0.5 bg-rose-100 text-rose-700 text-[10px] font-bold rounded">⚠️ หมดสัญญาใน <?php echo floor($daysLeft); ?> วัน</span>

                                                <?php else: ?>

                                                    <span class="inline-block mt-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-semibold border border-emerald-200 rounded">สัญญาถึง: <?php echo date("d/m/Y", strtotime($r['endDate'])); ?></span>

                                                <?php endif; ?>

                                            <?php endif; ?>



                                            <!-- แสดงสลิปใบแจ้งหนี้รอบิลรอตรวจสอบ -->

                                            <?php if(!empty($pendingInvoicesByRoom[$r['roomId']])): ?>

                                                <?php foreach($pendingInvoicesByRoom[$r['roomId']] as $pInv): ?>

                                                    <div class="mt-1.5 p-2 bg-blue-50 border border-blue-200 rounded-xl text-xs flex items-center justify-between gap-1 shadow-2xs">

                                                        <span class="font-bold text-blue-900 text-[11px]">

                                                            สลิปค่าเช่า (<?php echo htmlspecialchars($pInv['billingMonth']); ?>): ฿<?php echo number_format($pInv['totalAmount']); ?>

                                                        </span>

                                                        <button type="button" 

                                                            onclick="openDocModal('<?php echo htmlspecialchars($pInv['slipImage'], ENT_QUOTES); ?>')" 

                                                            class="px-2 py-0.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-[10px] font-bold transition">

                                                            ดูสลิป

                                                        </button>

                                                    </div>

                                                <?php endforeach; ?>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                <?php else: ?>

                                    <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold">

                                        <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> ห้องว่าง พร้อมให้เช่า

                                    </div>

                                <?php endif; ?>

                            </td>



                            <!-- 5. การจัดการ -->

                            <td class="p-4 text-center">

                                <div class="flex flex-col gap-1.5 w-36 mx-auto" onclick="event.stopPropagation()">

                                    <?php if($r['status'] == 'Occupied' && !empty($r['tenantName'])): ?>

                                        <!-- ดูข้อมูลผู้เช่า -->

                                        <button type="button" 

                                            onclick="openTenantModal(

                                                '<?php echo $r['roomNumber']; ?>', 

                                                '<?php echo htmlspecialchars($r['tenantName']); ?>', 

                                                '<?php echo htmlspecialchars($r['phone']); ?>', 

                                                '<?php echo htmlspecialchars($r['email'] ?? ''); ?>',

                                                '<?php echo htmlspecialchars($r['emergencyName'] ?? ''); ?>',

                                                '<?php echo htmlspecialchars($r['emergencyPhone'] ?? ''); ?>',

                                                '<?php echo date('d/m/Y', strtotime($r['startDate'])); ?>', 

                                                '<?php echo date('d/m/Y', strtotime($r['endDate'])); ?>', 

                                                '<?php echo number_format($r['rentPrice']); ?>', 

                                                '<?php echo number_format($r['depositAmount'] ?? 0); ?>',

                                                '<?php echo htmlspecialchars($r['leaseImage'] ?? ''); ?>',

                                                <?php echo htmlspecialchars(json_encode($coresidentsByRoom[$r['roomId']] ?? []), ENT_QUOTES, 'UTF-8'); ?>,

                                                '<?php echo $r['roomId']; ?>'

                                            )" 

                                            class="w-full px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold rounded-lg flex items-center justify-center gap-1.5 shadow-2xs transition">

                                            <i data-lucide="info" class="w-3.5 h-3.5"></i> ข้อมูลสัญญา & ผู้เช่า

                                        </button>



                                        <!-- ถ้ามีคำขอต่อสัญญาค้างอยู่ ให้มีปุ่มกดอนุมัติได้ทันที -->

                                        <?php if($hasRenewal): 

                                            $curEndObj = new DateTime($renInfo['currentEndDate']);

                                            $newEndObj = clone $curEndObj;

                                            $newEndObj->modify("+" . $renInfo['extensionMonths'] . " months");

                                        ?>

                                            <button type="button" 

                                                    onclick="openApproveModal('<?php echo $renInfo['renewalId']; ?>', '<?php echo $renInfo['leaseId']; ?>', '<?php echo $renInfo['extensionMonths']; ?>', '<?php echo htmlspecialchars($r['tenantName'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($r['roomNumber'], ENT_QUOTES); ?>', '<?php echo $newEndObj->format('d/m/Y'); ?>')"

                                                    class="w-full px-3 py-1.5 bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold rounded-lg flex items-center justify-center gap-1.5 shadow-2xs transition">

                                                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> อนุมัติต่อสัญญา

                                            </button>

                                        <?php endif; ?>



                                        <!-- ปุ่มเช็คลิสต์อุปกรณ์ -->

                                        <a href="admin_room_checklist.php?roomId=<?php echo $r['roomId']; ?>" class="w-full px-3 py-1 bg-stone-50 hover:bg-slate-100 text-slate-700 border border-slate-200 text-[11px] font-semibold rounded-lg flex items-center justify-center gap-1 transition">

                                            <i data-lucide="clipboard-check" class="w-3.5 h-3.5 text-slate-500"></i> เช็คลิสต์อุปกรณ์

                                        </a>



                                        <!-- ถ้ามีแจ้งย้ายออก ให้ปุ่มทำเรื่องย้ายออก -->

                                        <?php if($hasMoveOut): ?>

                                            <a href="move_out_form.php?roomId=<?php echo $r['roomId']; ?>&leaseId=<?php echo $r['leaseId']; ?>" class="w-full px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-lg flex items-center justify-center gap-1 shadow-2xs transition">

                                                <i data-lucide="door-open" class="w-3.5 h-3.5"></i> ดำเนินการย้ายออก

                                            </a>

                                        <?php endif; ?>



                                    <?php else: ?>

                                        <!-- ห้องว่าง: ทำสัญญาเข้าอยู่ใหม่ -->

                                        <a href="check_in_form.php?room=<?php echo urlencode($r['roomNumber']); ?>&price=<?php echo $r['rentPrice']; ?>" class="w-full px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg flex items-center justify-center gap-1 shadow-2xs transition">

                                            <i data-lucide="key" class="w-3.5 h-3.5"></i> ทำสัญญาเข้าอยู่

                                        </a>



                                        <div class="flex gap-1">

                                            <button type="button" onclick="openEditRoomModal(<?php echo $r['roomId']; ?>, '<?php echo $r['roomNumber']; ?>', <?php echo $r['floor']; ?>, '<?php echo $r['roomType']; ?>', <?php echo $r['rentPrice']; ?>)" class="w-1/2 py-1 bg-stone-50 hover:bg-slate-100 text-slate-600 border border-slate-200 rounded-lg text-[11px] font-semibold transition flex items-center justify-center gap-1">

                                                <i data-lucide="edit-2" class="w-3 h-3"></i> แก้ไข

                                            </button>

                                            

                                            <form action="" method="POST" class="w-1/2 m-0" onsubmit="return confirm('ยืนยันลบห้อง <?php echo $r['roomNumber']; ?> ออกจากระบบ?');">

                                                <input type="hidden" name="roomId" value="<?php echo $r['roomId']; ?>">

                                                <button type="submit" name="delete_room" class="w-full py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-lg text-[11px] font-semibold transition flex items-center justify-center gap-1">

                                                    <i data-lucide="trash-2" class="w-3 h-3"></i> ลบ

                                                </button>

                                            </form>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>



    <!-- ======================================================== -->

    <!-- 🌟 SECTION 2: แท็บคำขอต่ออายุสัญญาเช่า (RENEWALS TAB) 🌟 -->

    <!-- ======================================================== -->

    <div id="tabContentRenewals" class="space-y-6 <?php echo $initialTab !== 'renewals' ? 'hidden' : ''; ?>">

        <!-- Summary Stats 4 Cards for Renewals -->

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">

                <div>

                    <p class="text-xs text-slate-500 font-semibold mb-1">คำขอต่อสัญญาทั้งหมด</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-900"><?php echo $totalRenewals; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>

                    <p class="text-[11px] text-slate-400 mt-0.5">รวมทุกสถานะ</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">

                    <i data-lucide="layers" class="w-6 h-6"></i>

                </div>

            </div>



            <div class="bg-white p-5 rounded-2xl border border-amber-200 bg-amber-50/20 shadow-sm flex items-center justify-between">

                <div>

                    <p class="text-xs text-amber-800 font-semibold mb-1">รอการอนุมัติ</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-amber-600"><?php echo $countPendingRenewals; ?> <span class="text-xs font-normal text-slate-400">คำขอ</span></p>

                    <p class="text-[11px] text-amber-600 font-medium mt-0.5">รอแอดมินพิจารณา</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">

                    <i data-lucide="clock" class="w-6 h-6"></i>

                </div>

            </div>



            <div class="bg-white p-5 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-sm flex items-center justify-between">

                <div>

                    <p class="text-xs text-emerald-800 font-semibold mb-1">อนุมัติแล้ว</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-emerald-600"><?php echo $countApprovedRenewals; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>

                    <p class="text-[11px] text-emerald-600 font-medium mt-0.5">สัญญาขยายเวลาเรียบร้อย</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">

                    <i data-lucide="check-circle-2" class="w-6 h-6"></i>

                </div>

            </div>



            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">

                <div>

                    <p class="text-xs text-slate-500 font-semibold mb-1">ปฏิเสธคำขอ</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-600"><?php echo $countRejectedRenewals; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>

                    <p class="text-[11px] text-slate-400 font-medium mt-0.5">ไม่อนุมัติการต่อสัญญา</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">

                    <i data-lucide="x-circle" class="w-6 h-6"></i>

                </div>

            </div>

        </div>



        <!-- Renewals Table Card -->

        <div class="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">

            <!-- Toolbar -->

            <div class="p-4 sm:p-5 border-b border-slate-100 bg-white flex flex-col md:flex-row md:items-center justify-between gap-4">

                <!-- Search Input -->

                <div class="relative flex-1 max-w-md">

                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>

                    <input 

                        type="text" 

                        id="renewalSearchInput" 

                        onkeyup="applyRenewalFilters()"

                        placeholder="ค้นหาเลขห้อง, ชื่อผู้เช่า, เบอร์โทร..." 

                        class="w-full pl-10 pr-4 py-2.5 text-xs sm:text-sm bg-white border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:ring-2 focus:ring-indigo-100 transition shadow-2xs text-slate-800 placeholder-slate-400"

                    >

                </div>



                <!-- Status Filter Tabs -->

                <div class="flex items-center gap-2 justify-between md:justify-end flex-wrap">

                    <div class="inline-flex p-1 bg-white/80 backdrop-blur-md p-1.5 rounded-2xl border border-rose-200/60 shadow-xs rounded-xl text-xs font-semibold text-slate-600 flex-wrap">

                        <button type="button" onclick="filterRenewalsByStatus('all')" id="btnRenFilterAll" class="filter-ren-tab px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-2xs font-bold border border-slate-200">

                            ทั้งหมด (<?php echo $totalRenewals; ?>)

                        </button>

                        <button type="button" onclick="filterRenewalsByStatus('Pending')" id="btnRenFilterPending" class="filter-ren-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                            รออนุมัติ (<?php echo $countPendingRenewals; ?>)

                        </button>

                        <button type="button" onclick="filterRenewalsByStatus('Approved')" id="btnRenFilterApproved" class="filter-ren-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                            อนุมัติแล้ว (<?php echo $countApprovedRenewals; ?>)

                        </button>

                        <button type="button" onclick="filterRenewalsByStatus('Rejected')" id="btnRenFilterRejected" class="filter-ren-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                            ปฏิเสธ (<?php echo $countRejectedRenewals; ?>)

                        </button>

                    </div>

                </div>

            </div>



            <!-- Table -->

            <div class="overflow-x-auto">

                <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">

                    <thead class="bg-slate-50/75 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">

                        <tr>

                            <th class="p-4">ห้องพัก & ผู้เช่า</th>

                            <th class="p-4">รายละเอียดสัญญาเดิม</th>

                            <th class="p-4">คำขอต่อสัญญาเพิ่ม</th>

                            <th class="p-4">สถานะ & เอกสาร</th>

                            <th class="p-4 text-center">การจัดการ</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100 text-xs text-slate-700" id="renewalsTableBody">

                        <?php if(empty($renewals)): ?>

                        <tr>

                            <td colspan="5" class="p-12 text-center text-slate-400">

                                <i data-lucide="refresh-cw" class="w-10 h-10 mx-auto mb-2 text-slate-300"></i>

                                ยังไม่มีคำขอต่ออายุสัญญาเช่าในระบบ

                            </td>

                        </tr>

                        <?php else: ?>

                        <?php foreach($renewals as $req): 

                            $searchKeywords = mb_strtolower($req['roomNumber'] . ' ' . $req['fullName'] . ' ' . ($req['tenantPhone'] ?? ''), 'UTF-8');

                            $isPending = ($req['status'] === 'Pending');

                            $isApproved = ($req['status'] === 'Approved');

                            $isRejected = ($req['status'] === 'Rejected');



                            // คำนวณวันสิ้นสุดสัญญาใหม่

                            $currentEndDateObj = new DateTime($req['currentEndDate']);

                            $newEndDateObj = clone $currentEndDateObj;

                            $newEndDateObj->modify("+" . $req['extensionMonths'] . " months");

                            $newEndDateFormatted = $newEndDateObj->format("d/m/Y");

                        ?>

                        <tr class="renewal-row cursor-pointer hover:bg-rose-50/30/70 transition <?php echo $isPending ? 'bg-rose-50/20' : ''; ?>"
                            onclick="openRenewalDetailModal(<?php echo htmlspecialchars(json_encode($req), ENT_QUOTES, 'UTF-8'); ?>)"
                            data-search="<?php echo htmlspecialchars($searchKeywords, ENT_QUOTES, 'UTF-8'); ?>"
                            data-status="<?php echo htmlspecialchars($req['status']); ?>">

                            

                            <!-- 1. ห้อง & ผู้เช่า -->

                            <td class="p-4">

                                <div class="flex items-center gap-2 mb-1">

                                    <span class="px-2.5 py-1 bg-slate-50 text-slate-900 rounded-xl font-bold text-xs shadow-2xs font-mono border border-slate-200">

                                        ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?>

                                    </span>

                                </div>

                                <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($req['fullName']); ?></div>

                                <div class="text-[11px] text-slate-400 mt-0.5">📞 <?php echo htmlspecialchars($req['tenantPhone'] ?? '-'); ?></div>

                            </td>



                            <!-- 2. รายละเอียดสัญญาเดิม -->

                            <td class="p-4">

                                <div class="text-xs text-slate-600">

                                    <div>ค่าเช่า: <strong class="text-slate-900">฿<?php echo number_format($req['rentPrice']); ?>/ด.</strong></div>

                                    <div class="mt-1 text-slate-500">

                                        สิ้นสุดสัญญาปัจจุบัน: 

                                        <span class="font-bold font-mono text-rose-600 bg-rose-50 px-2 py-0.5 rounded-lg border border-rose-200/60 inline-block mt-0.5">

                                            <?php echo date("d/m/Y", strtotime($req['currentEndDate'])); ?>

                                        </span>

                                    </div>

                                </div>

                            </td>



                            <!-- 3. คำขอต่อสัญญาเพิ่ม -->

                            <td class="p-4">

                                <div class="bg-rose-50/80 border border-rose-200/80 p-3 rounded-2xl max-w-xs space-y-1">

                                    <div class="text-xs font-bold text-indigo-950 flex items-center gap-1.5">

                                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-rose-600"></i>

                                        <span>ขอยื่นต่อสัญญา: <span class="text-sm font-extrabold text-rose-700">+<?php echo $req['extensionMonths']; ?> เดือน</span></span>

                                    </div>

                                    <div class="text-[11px] text-slate-600">

                                        วันสิ้นสุดสัญญาใหม่: <strong class="text-emerald-700 font-mono font-bold"><?php echo $newEndDateFormatted; ?></strong>

                                    </div>

                                    <?php if(!empty($req['created_at'])): ?>

                                        <div class="text-[10px] text-slate-400 font-mono">

                                            ยื่นเมื่อ: <?php echo date("d/m/Y H:i", strtotime($req['created_at'])); ?> น.

                                        </div>

                                    <?php endif; ?>

                                </div>

                            </td>



                            <!-- 4. สถานะ & เอกสาร -->

                            <td class="p-4">

                                <?php if($isApproved): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold">

                                        <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> อนุมัติแล้ว

                                    </span>

                                <?php elseif($isPending): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold animate-pulse">

                                        <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-500"></i> รอแอดมินอนุมัติ

                                    </span>

                                <?php elseif($isRejected): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 text-slate-600 border border-slate-300 rounded-full text-xs font-bold">

                                        <i data-lucide="x-circle" class="w-3.5 h-3.5 text-slate-400"></i> ปฏิเสธคำขอ

                                    </span>

                                <?php endif; ?>



                                <?php if(!empty($req['leaseImage'])): ?>

                                    <div class="mt-1.5">

                                        <button type="button" onclick="openLeaseDoc('<?php echo htmlspecialchars($req['leaseImage']); ?>')" class="px-2 py-0.5 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 rounded-lg text-[10px] font-bold flex items-center gap-1">

                                            <i data-lucide="file-text" class="w-3 h-3"></i> เอกสารสัญญา

                                        </button>

                                    </div>

                                <?php endif; ?>



                                <?php if(!empty($req['adminRemark'])): ?>

                                    <p class="text-[11px] text-slate-500 mt-1.5 max-w-xs line-clamp-2" title="<?php echo htmlspecialchars($req['adminRemark']); ?>">

                                        💬 <?php echo htmlspecialchars($req['adminRemark']); ?>

                                    </p>

                                <?php endif; ?>

                            </td>



                            <!-- 5. การจัดการ -->

                            <td class="p-4 text-center">

                                <div class="flex flex-col gap-1.5 w-36 mx-auto" onclick="event.stopPropagation()">

                                    <button type="button" 
                                            onclick="openRenewalDetailModal(<?php echo htmlspecialchars(json_encode($req), ENT_QUOTES, 'UTF-8'); ?>)" 
                                            class="w-full py-1.5 bg-white hover:bg-rose-50 text-slate-700 hover:text-rose-700 border border-slate-200 hover:border-rose-300 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1 shadow-2xs cursor-pointer">
                                        <i data-lucide="eye" class="w-3.5 h-3.5 text-rose-600"></i> ดูรายละเอียด
                                    </button>

                                    <?php if($isPending): ?>

                                        <!-- ปุ่มอนุมัติ (เปิด Modal) -->

                                        <button type="button" onclick="openApproveModal('<?php echo $req['renewalId']; ?>', '<?php echo $req['leaseId']; ?>', '<?php echo $req['extensionMonths']; ?>', '<?php echo htmlspecialchars($req['fullName'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($req['roomNumber'], ENT_QUOTES); ?>', '<?php echo $newEndDateFormatted; ?>')" class="w-full py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-2xs transition flex items-center justify-center gap-1 cursor-pointer">

                                            <i data-lucide="check" class="w-3.5 h-3.5"></i> อนุมัติต่อสัญญา

                                        </button>



                                        <!-- ปุ่มปฏิเสธ -->

                                        <button type="button" onclick="openRejectModal('<?php echo $req['renewalId']; ?>', '<?php echo htmlspecialchars($req['fullName']); ?>', '<?php echo htmlspecialchars($req['roomNumber']); ?>')" class="w-full py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1 cursor-pointer">

                                            <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธ

                                        </button>

                                    <?php else: ?>

                                        <form action="" method="POST" onsubmit="return confirm('แน่ใจหรือไม่ที่จะลบรายการคำขอนี้ออกจากระบบ?');">

                                            <input type="hidden" name="renewal_id" value="<?php echo $req['renewalId']; ?>">

                                            <button type="submit" name="delete_renewal" class="w-full py-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg text-xs font-medium transition flex items-center justify-center gap-1">

                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ลบรายการ

                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>



    <!-- ======================================================== -->

    <!-- 🌟 SECTION 3: แท็บคำขอย้ายห้องพัก (ROOM CHANGE REQUESTS) 🌟 -->

    <!-- ======================================================== -->

    <div id="tabContentRoomChanges" class="space-y-6 <?php echo $initialTab !== 'room_changes' ? 'hidden' : ''; ?>">

        <!-- Summary Stats 4 Cards for Room Changes -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-semibold mb-1">คำขอย้ายห้องทั้งหมด</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-900"><?php echo $totalRoomChanges; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>
                    <p class="text-[11px] text-slate-400 mt-0.5">รวมทุกสถานะ</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                    <i data-lucide="arrow-left-right" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-amber-200 bg-amber-50/20 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-amber-800 font-semibold mb-1">รอการอนุมัติ</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-amber-600"><?php echo $countPendingRoomChanges; ?> <span class="text-xs font-normal text-slate-400">คำขอ</span></p>
                    <p class="text-[11px] text-amber-600 font-medium mt-0.5">รอแอดมินพิจารณา</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                    <i data-lucide="clock" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-xs text-emerald-800 font-semibold mb-1">อนุมัติการย้ายแล้ว</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-emerald-600"><?php echo $countApprovedRoomChanges; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>
                    <p class="text-[11px] text-emerald-600 font-medium mt-0.5">ย้ายเข้าห้องใหม่แล้ว</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                    <i data-lucide="check-circle-2" class="w-6 h-6"></i>
                </div>
            </div>

            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-semibold mb-1">ปฏิเสธคำขอ</p>
                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-600"><?php echo $countRejectedRoomChanges; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>
                    <p class="text-[11px] text-slate-400 font-medium mt-0.5">ไม่อนุมัติการย้าย</p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">
                    <i data-lucide="x-circle" class="w-6 h-6"></i>
                </div>
            </div>
        </div>

        <!-- Filter & Search Bar for Room Changes -->

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">

            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 text-xs font-medium">

                <span class="text-slate-400 text-xs mr-1 hidden sm:inline"><i data-lucide="filter" class="w-3.5 h-3.5 inline"></i> ตัวกรอง:</span>

                <button type="button" onclick="filterRoomChangesByStatus('all')" id="btnFilterRCAll" class="filter-tab-rc px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-xs font-bold border border-slate-200 border border-slate-200 font-bold">

                    ทั้งหมด (<?php echo $totalRoomChanges; ?>)

                </button>

                <button type="button" onclick="filterRoomChangesByStatus('Pending')" id="btnFilterRCPending" class="filter-tab-rc px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 flex items-center gap-1">

                    รออนุมัติ <?php if($countPendingRoomChanges > 0): ?><span class="bg-rose-500 text-white text-[10px] px-1.5 py-0.2 rounded-full font-bold"><?php echo $countPendingRoomChanges; ?></span><?php endif; ?>

                </button>

                <button type="button" onclick="filterRoomChangesByStatus('Approved')" id="btnFilterRCApproved" class="filter-tab-rc px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                    อนุมัติแล้ว (<?php echo $countApprovedRoomChanges; ?>)

                </button>

                <button type="button" onclick="filterRoomChangesByStatus('Rejected')" id="btnFilterRCRejected" class="filter-tab-rc px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                    ปฏิเสธ (<?php echo $countRejectedRoomChanges; ?>)

                </button>

            </div>



            <div class="relative w-full md:w-72">

                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>

                <input type="text" id="searchRoomChangesInput" onkeyup="applyRoomChangeFilters()" placeholder="ค้นหาชื่อผู้เช่า, เลขห้อง..." 

                       class="w-full pl-9 pr-4 py-2 bg-stone-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-rose-500 focus:bg-white transition">

            </div>

        </div>



        <!-- Table of Room Changes -->

        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="overflow-x-auto">

                <table class="w-full text-left border-collapse text-xs">

                    <thead>

                        <tr class="bg-slate-50/75 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">

                            <th class="p-3 whitespace-nowrap">วันที่ยื่น / รหัส</th>

                            <th class="p-3 whitespace-nowrap">ผู้เช่า</th>

                            <th class="p-3 whitespace-nowrap">ห้องปัจจุบัน</th>

                            <th class="p-3 whitespace-nowrap">ห้องที่ขอย้ายไป</th>

                            <th class="p-3 min-w-[140px]">เหตุผล / วันที่ย้าย</th>

                            <th class="p-3 text-center whitespace-nowrap">สลิปค่าทำความสะอาด</th>

                            <th class="p-3 text-center whitespace-nowrap">สถานะ</th>

                            <th class="p-3 text-center whitespace-nowrap sticky right-0 bg-slate-100/95 backdrop-blur-md z-20 shadow-[-4px_0_10px_rgba(0,0,0,0.06)] border-l border-slate-200">การจัดการ</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100 text-xs text-slate-700" id="roomChangesTableBody">

                        <?php if (empty($roomChangeRequests)): ?>

                        <tr>

                            <td colspan="8" class="p-8 text-center text-slate-400">

                                <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>

                                ไม่พบรายการคำขอย้ายห้องพักในระบบ

                            </td>

                        </tr>

                        <?php else: ?>

                        <?php foreach($roomChangeRequests as $rcr): 

                            $isPending = ($rcr['status'] === 'Pending');

                            $isApproved = ($rcr['status'] === 'Approved');

                            $isRejected = ($rcr['status'] === 'Rejected');

                            $searchKeywords = mb_strtolower($rcr['fullName'] . ' ' . $rcr['tenantPhone'] . ' ' . $rcr['currRoomNumber'] . ' ' . ($rcr['targetRoomNumber'] ?? '') . ' ' . ($rcr['targetRoomType'] ?? '') . ' ' . $rcr['requestId'], 'UTF-8');

                        ?>

                        <tr class="rc-row cursor-pointer hover:bg-rose-50/30/70 transition <?php echo $isPending ? 'bg-rose-50/20' : ''; ?>"
                            onclick="openRoomChangeDetailModal(<?php echo htmlspecialchars(json_encode($rcr), ENT_QUOTES, 'UTF-8'); ?>)"
                            data-status="<?php echo $rcr['status']; ?>"
                            data-search="<?php echo htmlspecialchars($searchKeywords); ?>">

                            

                            <!-- วันที่ยื่น / รหัส -->

                            <td class="p-4">

                                <span class="font-bold text-slate-900 font-mono">#CHG-<?php echo str_pad($rcr['requestId'], 4, '0', STR_PAD_LEFT); ?></span>

                                <div class="text-[11px] text-slate-400 mt-0.5">

                                    <i data-lucide="calendar" class="w-3 h-3 inline"></i> <?php echo date('d/m/Y H:i', strtotime($rcr['created_at'])); ?>

                                </div>

                            </td>



                            <!-- ผู้เช่า -->

                            <td class="p-4">

                                <div class="font-bold text-slate-900"><?php echo htmlspecialchars($rcr['fullName']); ?></div>

                                <div class="text-[11px] text-slate-500 flex items-center gap-1 mt-0.5">

                                    <i data-lucide="phone" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($rcr['tenantPhone'] ?? '-'); ?>

                                </div>

                            </td>



                            <!-- ห้องปัจจุบัน -->

                            <td class="p-4">

                                <div class="flex items-center gap-2">

                                    <span class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 font-bold flex items-center justify-center font-mono">

                                        <?php echo htmlspecialchars($rcr['currRoomNumber']); ?>

                                    </span>

                                    <div>

                                        <div class="font-semibold text-slate-700">

                                            <?php echo ($rcr['currRoomType'] === 'Air') ? '❄️ แอร์' : '🌀 พัดลม'; ?> (ชั้น <?php echo $rcr['currFloor']; ?>)

                                        </div>

                                        <div class="text-[11px] text-slate-400 font-mono">฿<?php echo number_format($rcr['currRentPrice']); ?>/ด.</div>

                                    </div>

                                </div>

                            </td>



                            <!-- ห้องที่ต้องการย้าย -->

                            <td class="p-4">

                                <?php if($isApproved && !empty($rcr['targetRoomNumber'])): ?>

                                    <div class="flex items-center gap-2">

                                        <span class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-800 font-bold flex items-center justify-center font-mono">

                                            <?php echo htmlspecialchars($rcr['targetRoomNumber']); ?>

                                        </span>

                                        <div>

                                            <div class="font-bold text-emerald-700">ย้ายเข้าแล้ว</div>

                                            <div class="text-[11px] text-slate-500 font-mono">฿<?php echo number_format($rcr['targetRentPrice']); ?>/ด.</div>

                                        </div>

                                    </div>

                                <?php else: ?>

                                    <div class="font-semibold text-rose-700 flex items-center gap-1.5">

                                        <i data-lucide="sparkles" class="w-3.5 h-3.5 text-indigo-500"></i>

                                        <span>ขอย้ายไป: <b><?php echo ($rcr['targetRoomType'] === 'Air') ? '❄️ ห้องแอร์' : (($rcr['targetRoomType'] === 'Fan') ? '🌀 ห้องพัดลม' : 'ตามที่แอดมินจัดสรร'); ?></b></span>

                                    </div>

                                    <div class="text-[11px] text-slate-400 mt-0.5">รอแอดมินจัดสรรห้อง</div>

                                <?php endif; ?>

                            </td>



                            <!-- เหตุผล / วันที่ต้องการ -->

                            <td class="p-4 max-w-xs">

                                <div class="font-medium text-slate-700 truncate" title="<?php echo htmlspecialchars($rcr['reason']); ?>">

                                    <?php echo htmlspecialchars($rcr['reason']); ?>

                                </div>

                                <?php if(!empty($rcr['preferredMoveDate'])): ?>

                                    <div class="text-[11px] text-rose-600 font-semibold mt-0.5">

                                        🗓️ ขอย้ายวันที่: <?php echo date('d/m/Y', strtotime($rcr['preferredMoveDate'])); ?>

                                    </div>

                                <?php endif; ?>

                            </td>



                            <!-- สลิปค่าทำความสะอาด -->

                            <td class="p-4 text-center">

                                <?php if(!empty($rcr['paymentSlip'])): ?>

                                    <button type="button" onclick="openDocViewer('<?php echo htmlspecialchars($rcr['paymentSlip']); ?>', 'สลิปค่าทำความสะอาด 500 บาท - ห้อง <?php echo htmlspecialchars($rcr['currRoomNumber']); ?>')" 

                                            class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-semibold hover:bg-emerald-100 transition cursor-pointer">

                                        <i data-lucide="receipt" class="w-3.5 h-3.5 text-emerald-600"></i> สลิป 500 บ.

                                    </button>

                                <?php else: ?>

                                    <span class="text-[11px] text-slate-400">ไม่มีสลิป</span>

                                <?php endif; ?>

                            </td>



                            <!-- สถานะ -->

                            <td class="p-4 text-center">

                                <?php if($isPending): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold animate-pulse">

                                        <i data-lucide="clock" class="w-3 h-3 text-amber-500"></i> รออนุมัติ

                                    </span>

                                <?php elseif($isApproved): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold">

                                        <i data-lucide="check-circle" class="w-3 h-3 text-emerald-500"></i> อนุมัติแล้ว

                                    </span>

                                <?php elseif($isRejected): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 text-slate-600 border border-slate-300 rounded-full text-xs font-bold">

                                        <i data-lucide="x-circle" class="w-3 h-3 text-slate-400"></i> ปฏิเสธ

                                    </span>

                                <?php endif; ?>



                                <?php if(!empty($rcr['adminRemark'])): ?>

                                    <p class="text-[11px] text-slate-500 mt-1 max-w-xs truncate mx-auto" title="<?php echo htmlspecialchars($rcr['adminRemark']); ?>">

                                        💬 <?php echo htmlspecialchars($rcr['adminRemark']); ?>

                                    </p>

                                <?php endif; ?>

                            </td>



                            <!-- การจัดการ (Sticky ขอบขวาเสมอ ไม่หลุดขอบจอ) -->

                            <td class="p-3 text-center sticky right-0 bg-white/95 backdrop-blur-md z-10 shadow-[-4px_0_10px_rgba(0,0,0,0.06)] border-l border-slate-200">

                                <div class="flex flex-col gap-1.5 w-32 mx-auto" onclick="event.stopPropagation()">

                                    <button type="button" 
                                            onclick="openRoomChangeDetailModal(<?php echo htmlspecialchars(json_encode($rcr), ENT_QUOTES, 'UTF-8'); ?>)" 
                                            class="w-full py-1.5 bg-white hover:bg-indigo-50 text-slate-700 hover:text-indigo-700 border border-slate-200 hover:border-indigo-300 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1 shadow-2xs cursor-pointer">
                                        <i data-lucide="eye" class="w-3.5 h-3.5 text-indigo-600"></i> ดูรายละเอียด
                                    </button>

                                    <?php if($isPending): ?>

                                        <button type="button" onclick="openApproveRoomChangeModal('<?php echo $rcr['requestId']; ?>', '<?php echo htmlspecialchars($rcr['fullName']); ?>', '<?php echo htmlspecialchars($rcr['currRoomNumber']); ?>', '<?php echo htmlspecialchars($rcr['targetRoomType'] ?? ''); ?>', '<?php echo htmlspecialchars($rcr['targetRoomId'] ?? ''); ?>', '<?php echo htmlspecialchars($rcr['paymentSlip'] ?? ''); ?>', '<?php echo htmlspecialchars($rcr['leaseImage'] ?? ''); ?>')" 

                                                class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">

                                            <i data-lucide="clipboard-check" class="w-4 h-4 text-white"></i> ตรวจสอบคำขอ

                                        </button>

                                        <button type="button" onclick="openRejectRoomChangeModal('<?php echo $rcr['requestId']; ?>', '<?php echo htmlspecialchars($rcr['fullName']); ?>', '<?php echo htmlspecialchars($rcr['currRoomNumber']); ?>')" 

                                                class="w-full py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-[11px] font-bold transition flex items-center justify-center gap-1 cursor-pointer">

                                            <i data-lucide="x" class="w-3 h-3"></i> ปฏิเสธ

                                        </button>

                                    <?php else: ?>

                                        <form action="" method="POST" onsubmit="return confirm('แน่ใจหรือไม่ที่จะลบรายการคำขอย้ายห้องนี้ออกจากระบบ?');">

                                            <input type="hidden" name="request_id" value="<?php echo $rcr['requestId']; ?>">

                                            <button type="submit" name="delete_room_change" class="w-full py-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg text-xs font-medium transition flex items-center justify-center gap-1 cursor-pointer">

                                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ลบรายการ

                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>



    <!-- ======================================================== -->

    <!-- 🌟 SECTION 4: แท็บแจ้งย้ายออก & เคลียร์ห้อง (MOVE-OUTS & SETTLEMENT) 🌟 -->

    <!-- ======================================================== -->

    <div id="tabContentMoveOuts" class="space-y-6 <?php echo $initialTab !== 'moveouts' ? 'hidden' : ''; ?>">

        <!-- Summary Stats 4 Cards for Move Outs -->

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

            <div class="bg-white rounded-3xl border border-slate-200 shadow-xs p-6 sm:p-8 flex items-center justify-between">

                <div>

                    <p class="text-xs text-slate-500 font-semibold mb-1">แจ้งย้ายออกทั้งหมด</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-slate-900"><?php echo $totalMoveOuts; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>

                    <p class="text-[11px] text-slate-400 mt-0.5">รวมประวัติและรายการค้าง</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">

                    <i data-lucide="package-minus" class="w-6 h-6"></i>

                </div>

            </div>



            <div class="bg-white p-5 rounded-2xl border border-rose-200 bg-rose-50/20 shadow-sm flex items-center justify-between">

                <div>

                    <p class="text-xs text-rose-800 font-semibold mb-1">รอเคลียร์ห้อง & คืนเงินประกัน</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-rose-600"><?php echo $countPendingMoveOuts; ?> <span class="text-xs font-normal text-slate-400">ห้อง</span></p>

                    <p class="text-[11px] text-rose-600 font-medium mt-0.5">สัญญา Active ที่แจ้งย้ายออก</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">

                    <i data-lucide="clock" class="w-6 h-6"></i>

                </div>

            </div>



            <div class="bg-white p-5 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-sm flex items-center justify-between">

                <div>

                    <p class="text-xs text-emerald-800 font-semibold mb-1">เคลียร์ห้องเสร็จสิ้นแล้ว</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-emerald-600"><?php echo $countSettledMoveOuts; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></p>

                    <p class="text-[11px] text-emerald-600 font-medium mt-0.5">ตรวจรับและคืนห้องแล้ว</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">

                    <i data-lucide="check-check" class="w-6 h-6"></i>

                </div>

            </div>



            <div class="bg-white p-5 rounded-2xl border border-blue-200 bg-blue-50/20 shadow-sm flex items-center justify-between">

                <div>

                    <p class="text-xs text-blue-800 font-semibold mb-1">ห้องว่างพร้อมปล่อยเช่า</p>

                    <p class="text-2xl sm:text-3xl font-display font-bold text-blue-600"><?php echo $availableCount; ?> <span class="text-xs font-normal text-slate-400">ห้อง</span></p>

                    <p class="text-[11px] text-blue-600 font-medium mt-0.5">พร้อมทำสัญญาใหม่</p>

                </div>

                <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center shrink-0">

                    <i data-lucide="door-open" class="w-6 h-6"></i>

                </div>

            </div>

        </div>



        <!-- Filter & Search Bar for Move Outs -->

        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">

            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0 text-xs font-medium">

                <span class="text-slate-400 text-xs mr-1 hidden sm:inline"><i data-lucide="filter" class="w-3.5 h-3.5 inline"></i> ตัวกรอง:</span>

                <button type="button" onclick="filterMoveOutsByStatus('all')" id="btnFilterMOAll" class="filter-tab-mo px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-xs font-bold border border-slate-200 border border-slate-200 font-bold">

                    ทั้งหมด (<?php echo $totalMoveOuts; ?>)

                </button>

                <button type="button" onclick="filterMoveOutsByStatus('Pending')" id="btnFilterMOPending" class="filter-tab-mo px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 flex items-center gap-1">

                    รอเคลียร์ห้อง <?php if($countPendingMoveOuts > 0): ?><span class="bg-rose-600 text-white text-[10px] px-1.5 py-0.2 rounded-full font-bold"><?php echo $countPendingMoveOuts; ?></span><?php endif; ?>

                </button>

                <button type="button" onclick="filterMoveOutsByStatus('Settled')" id="btnFilterMOSettled" class="filter-tab-mo px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900">

                    เคลียร์เรียบร้อย (<?php echo $countSettledMoveOuts; ?>)

                </button>

            </div>



            <div class="relative w-full md:w-72">

                <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>

                <input type="text" id="searchMoveOutsInput" onkeyup="applyMoveOutFilters()" placeholder="ค้นหาเลขห้อง, ผู้เช่า, เบอร์..." 

                       class="w-full pl-9 pr-4 py-2 bg-stone-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-rose-500 focus:bg-white transition">

            </div>

        </div>



        <!-- Table of Move Outs -->

        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="overflow-x-auto">

                <table class="w-full text-left border-collapse text-xs">

                    <thead>

                        <tr class="bg-slate-50/75 border-b border-slate-200/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">

                            <th class="p-3.5 sm:p-4 whitespace-nowrap">ห้องพัก</th>

                            <th class="p-3.5 sm:p-4 whitespace-nowrap">ผู้เช่า</th>

                            <th class="p-3.5 sm:p-4">วันที่แจ้งย้ายออก & เหตุผล</th>

                            <th class="p-3.5 sm:p-4 whitespace-nowrap">บัญชีรับเงินประกันคืน</th>

                            <th class="p-3.5 sm:p-4 whitespace-nowrap">เงินประกัน / ยอดเคลียร์</th>

                            <th class="p-3.5 sm:p-4 text-center whitespace-nowrap">สถานะ</th>

                            <th class="p-3.5 sm:p-4 text-center whitespace-nowrap">การจัดการ</th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-slate-100 text-xs text-slate-700" id="moveOutsTableBody">

                        <?php if (empty($allMoveOuts)): ?>

                        <tr>

                            <td colspan="7" class="p-8 text-center text-slate-400">

                                <i data-lucide="package-minus" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>

                                ไม่มีรายการแจ้งย้ายออกในระบบ

                            </td>

                        </tr>

                        <?php else: ?>

                        <?php foreach($allMoveOuts as $mo): 

                            $isPendingClear = ($mo['status'] === 'Active');

                            $searchKeywords = mb_strtolower($mo['roomNumber'] . ' ' . $mo['tenantName'] . ' ' . ($mo['tenantPhone'] ?? '') . ' ' . ($mo['bankName'] ?? '') . ' ' . ($mo['bankAccount'] ?? '') . ' ' . ($mo['accountName'] ?? ''), 'UTF-8');

                        ?>

                        <tr class="mo-row cursor-pointer hover:bg-rose-50/40 transition group <?php echo $isPendingClear ? 'bg-rose-50/15' : ''; ?>"

                            data-status="<?php echo $isPendingClear ? 'Pending' : 'Settled'; ?>"

                            data-search="<?php echo htmlspecialchars($searchKeywords); ?>"

                            onclick="openMoveOutModal(<?php echo htmlspecialchars(json_encode($mo), ENT_QUOTES, 'UTF-8'); ?>)"

                            title="คลิกเพื่อเปิดดูรายละเอียดคำขอแจ้งย้ายออก">

                            

                            <!-- ห้องพัก -->

                            <td class="p-4">

                                <div class="flex items-center gap-2">

                                    <span class="w-9 h-9 rounded-xl <?php echo $isPendingClear ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700'; ?> font-bold flex items-center justify-center font-mono text-sm">

                                        <?php echo htmlspecialchars($mo['roomNumber']); ?>

                                    </span>

                                    <div>

                                        <div class="font-bold text-slate-800">

                                            <?php echo ($mo['roomType'] === 'Air') ? '❄️ แอร์' : '🌀 พัดลม'; ?> (ชั้น <?php echo $mo['floor']; ?>)

                                        </div>

                                        <div class="text-[11px] text-slate-400 font-mono">฿<?php echo number_format($mo['rentPrice']); ?>/ด.</div>

                                    </div>

                                </div>

                            </td>



                            <!-- ผู้เช่า -->

                            <td class="p-4">

                                <div class="font-bold text-slate-900"><?php echo htmlspecialchars($mo['tenantName']); ?></div>

                                <div class="text-[11px] text-slate-500 flex items-center gap-1 mt-0.5">

                                    <i data-lucide="phone" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($mo['tenantPhone'] ?? '-'); ?>

                                </div>

                                <div class="text-[10px] text-slate-400 mt-0.5">

                                    สัญญา: <?php echo date('d/m/Y', strtotime($mo['startDate'])); ?> - <?php echo date('d/m/Y', strtotime($mo['endDate'])); ?>

                                </div>

                            </td>



                            <!-- วันที่แจ้งย้ายออก & เหตุผล -->

                            <td class="p-4 max-w-xs">

                                <div class="font-bold text-rose-700 flex items-center gap-1">

                                    <i data-lucide="calendar" class="w-3.5 h-3.5"></i>

                                    <span>ย้ายออก: <?php echo date('d/m/Y', strtotime($mo['moveOutRequestDate'])); ?></span>

                                </div>

                                <?php if(!empty($mo['moveOutReason'])): ?>

                                    <div class="text-[11px] text-slate-600 mt-1 truncate" title="<?php echo htmlspecialchars($mo['moveOutReason']); ?>">

                                        เหตุผล: <?php echo htmlspecialchars($mo['moveOutReason']); ?>

                                    </div>

                                <?php endif; ?>

                            </td>



                            <!-- บัญชีรับเงินประกันคืน -->

                            <td class="p-4">

                                <?php if(!empty($mo['bankName']) || !empty($mo['bankAccount'])): ?>

                                    <div class="font-semibold text-slate-900 flex items-center gap-1">

                                        <i data-lucide="credit-card" class="w-3.5 h-3.5 text-blue-600"></i>

                                        <span><?php echo htmlspecialchars($mo['bankName'] ?? 'ธนาคาร'); ?></span>

                                    </div>

                                    <div class="text-[11px] text-slate-600 font-mono font-bold mt-0.5"><?php echo htmlspecialchars($mo['bankAccount'] ?? '-'); ?></div>

                                    <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($mo['accountName'] ?? ''); ?></div>

                                <?php else: ?>

                                    <span class="text-[11px] text-slate-400 italic">ไม่ระบุบัญชี (คืนเงินสด)</span>

                                <?php endif; ?>

                            </td>



                            <!-- เงินประกัน / ยอดเคลียร์ -->

                            <td class="p-4">

                                <div class="text-slate-600">

                                    เงินประกันเดิม: <span class="font-mono font-bold text-slate-900">฿<?php echo number_format($mo['depositAmount']); ?></span>

                                </div>

                                <?php if(!$isPendingClear && isset($mo['netRefundAmount'])): ?>

                                    <?php if(floatval($mo['netRefundAmount']) < 0): ?>

                                        <div class="text-xs font-bold text-rose-700 mt-0.5">

                                            ยอดชำระเพิ่ม: ฿<?php echo number_format(abs($mo['netRefundAmount']), 2); ?>

                                        </div>

                                    <?php else: ?>

                                        <div class="text-xs font-bold text-emerald-700 mt-0.5">

                                            ยอดคืนสุทธิ: ฿<?php echo number_format($mo['netRefundAmount'], 2); ?>

                                        </div>

                                    <?php endif; ?>

                                    <div class="flex flex-col gap-0.5 mt-0.5">

                                        <?php if(!empty($mo['refundReceipt'])): ?>

                                            <button type="button" onclick="openDocViewer('<?php echo htmlspecialchars($mo['refundReceipt']); ?>', 'หลักฐานการโอนคืนเงินประกัน - ห้อง <?php echo htmlspecialchars($mo['roomNumber']); ?>')" 

                                                    class="text-[10px] text-rose-600 hover:underline flex items-center gap-1 cursor-pointer">

                                                <i data-lucide="receipt" class="w-3 h-3"></i> ดูสลิปคืนเงิน

                                            </button>

                                        <?php endif; ?>

                                        <?php if(!empty($mo['tenantPaymentSlip'])): ?>

                                            <button type="button" onclick="openDocViewer('<?php echo htmlspecialchars($mo['tenantPaymentSlip']); ?>', 'สลิปชำระเงินส่วนต่างย้ายออก - ห้อง <?php echo htmlspecialchars($mo['roomNumber']); ?>')" 

                                                    class="text-[10px] text-emerald-600 hover:underline flex items-center gap-1 cursor-pointer">

                                                <i data-lucide="receipt" class="w-3 h-3"></i> ดูสลิปชำระเงิน

                                            </button>

                                        <?php endif; ?>

                                    </div>

                                <?php else: ?>

                                    <span class="text-[10px] text-amber-700 font-medium">รอตรวจเช็คสภาพห้อง & มิเตอร์</span>

                                    <?php if(!empty($mo['tenantPaymentSlip'])): ?>

                                        <div class="mt-0.5">

                                            <button type="button" onclick="openDocViewer('<?php echo htmlspecialchars($mo['tenantPaymentSlip']); ?>', 'สลิปชำระเงินที่ลูกบ้านแนบมา - ห้อง <?php echo htmlspecialchars($mo['roomNumber']); ?>')" 

                                                    class="text-[10px] text-emerald-600 hover:underline flex items-center gap-1 cursor-pointer">

                                                <i data-lucide="receipt" class="w-3 h-3"></i> ดูสลิปชำระเงิน

                                            </button>

                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </td>



                            <!-- สถานะ -->

                            <td class="p-4 text-center">

                                <?php if($isPendingClear): ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-full text-xs font-bold animate-pulse">

                                        <i data-lucide="alert-circle" class="w-3 h-3 text-rose-500"></i> รอเคลียร์ห้อง

                                    </span>

                                <?php else: ?>

                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold">

                                        <i data-lucide="check-circle" class="w-3 h-3 text-emerald-500"></i> เคลียร์เรียบร้อย

                                    </span>

                                    <?php if(!empty($mo['settledAt'])): ?>

                                        <div class="text-[10px] text-slate-400 mt-1">

                                            <?php echo date('d/m/Y', strtotime($mo['settledAt'])); ?>

                                        </div>

                                    <?php endif; ?>

                                <?php endif; ?>

                            </td>



                            <!-- การจัดการ -->

                            <td class="p-4 text-center">

                                <div class="flex flex-col gap-1.5 w-36 mx-auto" onclick="event.stopPropagation()">

                                    <!-- ปุ่มดูรายละเอียด (เปิด Modal เด้งขึ้นมาแบบ 2 กรอบ) -->

                                    <button type="button" 

                                            onclick="openMoveOutModal(<?php echo htmlspecialchars(json_encode($mo), ENT_QUOTES, 'UTF-8'); ?>)" 

                                            class="w-full py-1.5 bg-white hover:bg-blue-50 text-slate-700 hover:text-blue-700 border border-slate-200 hover:border-blue-300 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1 shadow-2xs cursor-pointer">

                                        <i data-lucide="eye" class="w-3.5 h-3.5 text-blue-600"></i> ดูรายละเอียด

                                    </button>

                                    <?php if($isPendingClear): ?>

                                        <!-- ลิงก์ตรงสู่ move_out_form.php เพื่อสรุปค่าใช้จ่าย -->

                                        <a href="move_out_form.php?roomId=<?php echo $mo['roomId']; ?>&leaseId=<?php echo $mo['leaseId']; ?>" 

                                           class="w-full py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center justify-center gap-1">

                                            <i data-lucide="calculator" class="w-3.5 h-3.5"></i> สรุปเงิน & เคลียร์ห้อง

                                        </a>



                                        <!-- ปุ่มยกเลิกคำขอย้ายออก -->

                                        <form action="" method="POST" onsubmit="return confirm('ยืนยันยกเลิกคำขอแจ้งย้ายออกของห้องนี้? สัญญาจะกลับมาเป็นสถานะปกติ');">

                                            <input type="hidden" name="lease_id" value="<?php echo $mo['leaseId']; ?>">

                                            <button type="submit" name="cancel_move_out" class="w-full py-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg text-xs font-medium transition flex items-center justify-center gap-1 cursor-pointer">

                                                <i data-lucide="undo-2" class="w-3 h-3"></i> ยกเลิกคำขอย้ายออก

                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <a href="move_out_form.php?roomId=<?php echo $mo['roomId']; ?>&leaseId=<?php echo $mo['leaseId']; ?>" 

                                           class="w-full py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold transition flex items-center justify-center gap-1">

                                            <i data-lucide="file-text" class="w-3.5 h-3.5"></i> ดูใบสรุปย้ายออก

                                        </a>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                        <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>



  </main>



  <!-- ======================================================== -->

  <!-- 🌟 MODALS 🌟 -->

  <!-- ======================================================== -->



  <!-- MODAL 1: เพิ่มห้องพักใหม่ -->

  <div id="addRoomModal" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden">
    <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">
      
      <!-- Modal Header -->
      <div class="px-5 py-4 sm:px-8 sm:py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 shadow-2xs">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
            </div>
            <div>
                <h3 class="font-display font-extrabold text-slate-800 text-lg sm:text-xl tracking-tight">เพิ่มห้องพักใหม่</h3>
                <p class="text-xs text-slate-500 font-normal mt-0.5">ระบุรายละเอียดห้องพักเพื่อบันทึกเข้าระบบหอพัก</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" onclick="document.getElementById('addRoomModal').classList.add('hidden')" class="w-9 h-9 rounded-2xl bg-white/80 backdrop-blur-md p-1.5 rounded-2xl border border-rose-200/60 shadow-xs hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิด">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
      </div>
      <div class="p-6 sm:p-8 md:p-10 space-y-6 overflow-y-auto flex-1 min-h-0">
        <div class="max-w-4xl mx-auto w-full">



      <form action="" method="POST" class="space-y-5">

        <!-- หมายเลขห้อง -->

        <div>

            <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">

                หมายเลขห้อง <span class="text-rose-500">*</span>

            </label>

            <div class="relative">

                <div class="w-10 h-10 absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400">

                    <i data-lucide="door-closed" class="w-5 h-5"></i>

                </div>

                <input type="text" 

                       name="roomNumber" 

                       id="addRoomNumber"

                       oninput="autoDetectFloor(this, 'addFloor')"

                       required 

                       placeholder="เช่น 101, 102, 201" 

                       class="w-full pl-12 pr-4 py-3.5 text-base font-bold font-mono text-slate-900 bg-stone-50 border border-slate-200 rounded-2xl outline-none focus:bg-white focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition placeholder:font-normal placeholder:text-slate-400">

            </div>

        </div>



        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <!-- ประเภทห้อง -->

            <div>

                <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">

                    ประเภทห้อง <span class="text-rose-500">*</span>

                </label>

                <input type="hidden" name="roomType" id="addRoomType" value="Air" required>
                <div class="grid grid-cols-2 gap-2.5">
                    <button type="button" onclick="selectRoomType('add', 'Air')" id="btnAir_add"
                            class="p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">❄️</span>
                            <div class="text-left">
                                <div class="text-xs sm:text-sm font-bold">ห้องแอร์</div>
                                <div class="text-[10px] text-sky-700">฿4,000 / ด.</div>
                            </div>
                        </div>
                        <span id="checkAir_add" class="w-5 h-5 rounded-full bg-sky-500 text-white text-[10px] flex items-center justify-center font-bold">✓</span>
                    </button>
                    <button type="button" onclick="selectRoomType('add', 'Fan')" id="btnFan_add"
                            class="p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">🌀</span>
                            <div class="text-left">
                                <div class="text-xs sm:text-sm font-bold">ห้องพัดลม</div>
                                <div class="text-[10px] text-slate-400">฿3,500 / ด.</div>
                            </div>
                        </div>
                        <span id="checkFan_add" class="hidden w-5 h-5 rounded-full bg-sky-500 text-white text-[10px] flex items-center justify-center font-bold">✓</span>
                    </button>
                </div>

            </div>



            <!-- ชั้น -->

            <div>

                <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">

                    ชั้นที่ <span class="text-rose-500">*</span>

                </label>

                <div class="relative">

                    <div class="w-10 h-10 absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400">

                        <i data-lucide="layers" class="w-5 h-5"></i>

                    </div>

                    <input type="number" 

                           name="floor" 

                           id="addFloor"

                           min="1" 

                           max="15" 

                           value="1" 

                           required 

                           class="w-full pl-12 pr-4 py-3.5 text-base font-bold font-mono text-slate-900 bg-stone-50 border border-slate-200 rounded-2xl outline-none focus:bg-white focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10 transition">

                </div>

            </div>

        </div>



        <!-- ราคาค่าเช่าต่อเดือน (ล็อกราคาตายตัวตามประเภทห้อง) -->

        <div>

            <div class="flex items-center justify-between mb-2">

                <label class="block text-xs sm:text-sm font-bold text-slate-700">

                    ราคาค่าเช่าต่อเดือน (บาท) <span class="text-rose-500">*</span>

                </label>

                <span class="text-[11px] font-bold text-emerald-800 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-lg flex items-center gap-1 shadow-2xs">

                    <i data-lucide="lock" class="w-3 h-3 text-emerald-600"></i> ล็อกราคาตามประเภทห้อง

                </span>

            </div>

            

            <div class="relative">

                <div class="w-10 h-10 absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center text-emerald-600 font-extrabold font-mono text-lg">

                    ฿

                </div>

                <input type="number" 

                       name="rentPrice" 

                       id="addRentPrice" 

                       value="4000" 

                       readonly 

                       required 

                       class="w-full pl-12 pr-28 py-3.5 text-lg font-extrabold font-mono text-emerald-900 bg-emerald-50/70 border-2 border-emerald-300 rounded-2xl outline-none cursor-not-allowed select-none shadow-2xs">

                <div class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-emerald-700 bg-white px-2.5 py-1 rounded-xl border border-emerald-200 flex items-center gap-1 shadow-2xs pointer-events-none">

                    บาท / เดือน

                </div>

            </div>



            <!-- Note -->

            <div class="mt-2 flex items-center justify-between text-xs text-slate-500 px-1">

                <span id="addPriceNote" class="text-emerald-700 font-semibold flex items-center gap-1">

                    <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> อัตรามาตรฐาน: ห้องแอร์ 4,000 บ./ด.

                </span>

                <span class="text-slate-400 font-mono text-[11px]">(พัดลม ฿3,500 | แอร์ ฿4,000)</span>

            </div>

        </div>



        <!-- Buttons -->

        <div class="pt-6 border-t border-slate-100 flex gap-4">
            <button type="button" 
                    onclick="document.getElementById('addRoomModal').classList.add('hidden')" 
                    class="w-1/3 py-3.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-sm rounded-2xl transition cursor-pointer shadow-2xs">
                ยกเลิก
            </button>
            <button type="submit" 
                    name="add_room" 
                    class="w-2/3 py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded-2xl shadow-md hover:shadow-lg transition flex justify-center items-center gap-2 cursor-pointer">
                <i data-lucide="check" class="w-4 h-4"></i> บันทึกข้อมูลห้อง
            </button>
        </div>
      </form>
        </div>
      </div>
    </div>
  </div>



  <!-- MODAL 2: แก้ไขข้อมูลห้องพัก -->

  <div id="editRoomModal" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden">
    <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">
      
      <!-- Modal Header -->
      <div class="px-5 py-4 sm:px-8 sm:py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 shadow-2xs">
                <i data-lucide="edit" class="w-5 h-5"></i>
            </div>
            <div>
                <h3 class="font-display font-extrabold text-slate-800 text-lg sm:text-xl tracking-tight">แก้ไขข้อมูลห้องพัก</h3>
                <p class="text-xs text-slate-500 font-normal mt-0.5">ปรับปรุงข้อมูลห้องพัก และปรับราคาตามประเภทห้องอัตโนมัติ</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" onclick="document.getElementById('editRoomModal').classList.add('hidden')" class="w-9 h-9 rounded-2xl bg-white/80 backdrop-blur-md p-1.5 rounded-2xl border border-rose-200/60 shadow-xs hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิด">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
      </div>
      <div class="p-6 sm:p-8 md:p-10 space-y-6 overflow-y-auto flex-1 min-h-0">
        <div class="max-w-4xl mx-auto w-full">



      <form action="" method="POST" class="space-y-5">

        <input type="hidden" name="roomId" id="editRoomId">



        <!-- หมายเลขห้อง -->

        <div>

            <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">

                หมายเลขห้อง <span class="text-rose-500">*</span>

            </label>

            <div class="relative">

                <div class="w-10 h-10 absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400">

                    <i data-lucide="door-closed" class="w-5 h-5"></i>

                </div>

                <input type="text" 

                       name="roomNumber" 

                       id="editRoomNumber" 

                       oninput="autoDetectFloor(this, 'editFloor')"

                       required 

                       class="w-full pl-12 pr-4 py-3.5 text-base font-bold font-mono text-slate-900 bg-stone-50 border border-slate-200 rounded-2xl outline-none focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition">

            </div>

        </div>



        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

            <!-- ประเภทห้อง -->

            <div>

                <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">

                    ประเภทห้อง <span class="text-rose-500">*</span>

                </label>

                <input type="hidden" name="roomType" id="editRoomType" value="Air" required>
                <div class="grid grid-cols-2 gap-2.5">
                    <button type="button" onclick="selectRoomType('edit', 'Air')" id="btnAir_edit"
                            class="p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">❄️</span>
                            <div class="text-left">
                                <div class="text-xs sm:text-sm font-bold">ห้องแอร์</div>
                                <div class="text-[10px] text-sky-700">฿4,000 / ด.</div>
                            </div>
                        </div>
                        <span id="checkAir_edit" class="w-5 h-5 rounded-full bg-sky-500 text-white text-[10px] flex items-center justify-center font-bold">✓</span>
                    </button>
                    <button type="button" onclick="selectRoomType('edit', 'Fan')" id="btnFan_edit"
                            class="p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold">
                        <div class="flex items-center gap-2">
                            <span class="text-lg">🌀</span>
                            <div class="text-left">
                                <div class="text-xs sm:text-sm font-bold">ห้องพัดลม</div>
                                <div class="text-[10px] text-slate-400">฿3,500 / ด.</div>
                            </div>
                        </div>
                        <span id="checkFan_edit" class="hidden w-5 h-5 rounded-full bg-sky-500 text-white text-[10px] flex items-center justify-center font-bold">✓</span>
                    </button>
                </div>

            </div>



            <!-- ชั้น -->

            <div>

                <label class="block text-xs sm:text-sm font-bold text-slate-700 mb-2">

                    ชั้นที่ <span class="text-rose-500">*</span>

                </label>

                <div class="relative">

                    <div class="w-10 h-10 absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center text-slate-400">

                        <i data-lucide="layers" class="w-5 h-5"></i>

                    </div>

                    <input type="number" 

                           name="floor" 

                           id="editFloor" 

                           min="1" 

                           max="15" 

                           required 

                           class="w-full pl-12 pr-4 py-3.5 text-base font-bold font-mono text-slate-900 bg-stone-50 border border-slate-200 rounded-2xl outline-none focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 transition">

                </div>

            </div>

        </div>



        <!-- ราคาค่าเช่าต่อเดือน (ล็อกราคาตายตัวตามประเภทห้อง) -->

        <div>

            <div class="flex items-center justify-between mb-2">

                <label class="block text-xs sm:text-sm font-bold text-slate-700">

                    ราคาค่าเช่าต่อเดือน (บาท) <span class="text-rose-500">*</span>

                </label>

                <span class="text-[11px] font-bold text-blue-800 bg-blue-50 border border-blue-200 px-2.5 py-0.5 rounded-lg flex items-center gap-1 shadow-2xs">

                    <i data-lucide="lock" class="w-3 h-3 text-blue-600"></i> ล็อกราคาตามประเภทห้อง

                </span>

            </div>

            

            <div class="relative">

                <div class="w-10 h-10 absolute left-3 top-1/2 -translate-y-1/2 flex items-center justify-center text-blue-600 font-extrabold font-mono text-lg">

                    ฿

                </div>

                <input type="number" 

                       name="rentPrice" 

                       id="editRentPrice" 

                       readonly 

                       required 

                       class="w-full pl-12 pr-28 py-3.5 text-lg font-extrabold font-mono text-blue-900 bg-blue-50/70 border-2 border-blue-300 rounded-2xl outline-none cursor-not-allowed select-none shadow-2xs">

                <div class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-blue-700 bg-white px-2.5 py-1 rounded-xl border border-blue-200 flex items-center gap-1 shadow-2xs pointer-events-none">

                    บาท / เดือน

                </div>

            </div>



            <!-- Note -->

            <div class="mt-2 flex items-center justify-between text-xs text-slate-500 px-1">

                <span id="editPriceNote" class="text-blue-700 font-semibold flex items-center gap-1">

                    <i data-lucide="check-circle-2" class="w-4 h-4 text-blue-600"></i> อัตรามาตรฐาน: ห้องแอร์ 4,000 บ./ด.

                </span>

                <span class="text-slate-400 font-mono text-[11px]">(พัดลม ฿3,500 | แอร์ ฿4,000)</span>

            </div>

        </div>



        <!-- Buttons -->

        <div class="pt-6 border-t border-slate-100 flex gap-4">
            <button type="button" 
                    onclick="document.getElementById('editRoomModal').classList.add('hidden')" 
                    class="w-1/3 py-3.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-sm rounded-2xl transition cursor-pointer shadow-2xs">
                ยกเลิก
            </button>
            <button type="submit" 
                    name="edit_room" 
                    class="w-2/3 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-2xl shadow-md hover:shadow-lg transition flex justify-center items-center gap-2 cursor-pointer">
                <i data-lucide="check" class="w-4 h-4"></i> อัปเดตข้อมูล
            </button>
        </div>
      </form>
        </div>
      </div>
    </div>
  </div>



  <!-- MODAL 3: ดูข้อมูลผู้เช่า & สัญญาเช่า (2 Columns) -->
  <div id="tenantInfoModal" class="hidden fixed inset-0 z-[50] overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4">
    <div class="bg-white w-full max-w-4xl max-h-[92vh] rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">
      <!-- Header -->
      <div class="px-5 py-4 sm:px-6 sm:py-5 border-b border-slate-200/80 flex justify-between items-center bg-slate-50/70 shrink-0">
        <div class="flex items-center gap-3">
          <span class="px-3 py-1 bg-slate-900 text-white rounded-xl font-bold text-sm shadow-xs font-mono">ห้อง <span id="modalRoomNum">-</span></span>
          <span class="font-display font-extrabold text-slate-900 text-base sm:text-lg">ข้อมูลสัญญาเช่าและผู้เช่า</span>
          <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">กำลังพักอาศัย</span>
        </div>
        <button type="button" onclick="document.getElementById('tenantInfoModal').classList.add('hidden')" class="w-9 h-9 rounded-2xl bg-white/80 backdrop-blur-md p-1.5 border border-rose-200/60 shadow-xs hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิด">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <!-- Body 2 columns -->
      <div class="p-4 sm:p-6 overflow-y-auto flex-1 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
          <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลของผู้เช่า -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-indigo-200 bg-gradient-to-b from-indigo-50/40 to-slate-50/20 shadow-2xs space-y-4">
            <div class="flex items-center gap-2.5 pb-3.5 border-b border-indigo-200/80">
              <div class="w-9 h-9 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                <i data-lucide="user" class="w-5 h-5"></i>
              </div>
              <div>
                <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลของผู้เช่า</h4>
                <p class="text-[11px] text-indigo-700 font-medium">รายละเอียดผู้เช่าหลักและช่องทางติดต่อ</p>
              </div>
            </div>

            <!-- การ์ดผู้เช่า -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs flex items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <div id="modalTenantAvatar" class="w-11 h-11 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-700 font-extrabold flex items-center justify-center text-base">
                  ผ
                </div>
                <div>
                  <span class="text-[10px] text-slate-400 font-semibold uppercase">ผู้เช่าหลัก</span>
                  <div id="modalTenantName" class="font-bold text-slate-900 text-sm sm:text-base">-</div>
                  <div id="modalPhone" class="text-xs text-slate-500 font-mono mt-0.5">📞 -</div>
                </div>
              </div>
              <a id="modalTenantCallBtn" href="#" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                <i data-lucide="phone-call" class="w-3.5 h-3.5"></i> โทรหา
              </a>
            </div>

            <!-- ข้อมูลติดต่ออื่นๆ -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs space-y-2.5 text-xs text-slate-600">
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">อีเมล:</span>
                <strong id="modalEmail" class="text-slate-900">-</strong>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">ติดต่อฉุกเฉิน:</span>
                <strong id="modalEmergency" class="text-slate-900 text-xs">-</strong>
              </div>
            </div>

            <!-- ผู้ร่วมอาศัยในห้อง -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs">
              <div class="flex items-center justify-between mb-2">
                <span class="font-semibold text-slate-700 text-xs flex items-center gap-1.5">
                  <i data-lucide="users" class="w-4 h-4 text-indigo-500"></i> ผู้ร่วมอาศัยในห้อง:
                </span>
                <a id="modalCoresidentsLink" href="admin_coresidents.php" class="text-[11px] text-rose-600 hover:underline font-semibold flex items-center gap-0.5">
                  จัดการคำขอ <i data-lucide="arrow-right" class="w-3 h-3"></i>
                </a>
              </div>
              <div id="modalCoresidentsList" class="space-y-1.5 text-xs">
              </div>
            </div>
          </div>

          <!-- 🟩 กรอบที่ 2: ส่วนสัญญาเช่า & ห้องพัก -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">
            <div>
              <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i data-lucide="file-check" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <h4 class="font-display font-bold text-slate-900 text-base">ส่วนสัญญาเช่า & ค่าเช่า</h4>
                    <p class="text-[11px] text-emerald-700 font-medium">ค่าเช่ารายเดือน เงินประกัน และระยะเวลาสัญญา</p>
                  </div>
                </div>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">
                  สัญญาปกติ
                </span>
              </div>

              <!-- รายละเอียดการเงิน & สัญญา -->
              <div class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs space-y-2.5 text-xs text-slate-600 mb-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-400">ค่าเช่ารายเดือน:</span>
                  <strong class="text-slate-900 font-bold font-mono text-sm">฿<span id="modalRent">0</span></strong>
                </div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-400">เงินประกัน (มัดจำ):</span>
                  <strong class="text-emerald-600 font-bold font-mono text-sm">฿<span id="modalDeposit">0</span></strong>
                </div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-400">วันเริ่มต้นสัญญา:</span>
                  <strong id="modalStartDate" class="text-slate-900 font-mono">-</strong>
                </div>
                <div class="flex items-center justify-between">
                  <span class="text-slate-400">วันสิ้นสุดสัญญา:</span>
                  <strong id="modalEndDate" class="text-rose-600 font-mono font-bold">-</strong>
                </div>
              </div>

              <!-- เอกสารสัญญาเช่า (Preview) -->
              <div id="modalLeaseDocWrap" class="hidden bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs mb-4">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">
                    <i data-lucide="file-text" class="w-4 h-4 text-emerald-600"></i> เอกสารสัญญาเช่า
                  </span>
                  <span class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2 py-0.5 rounded-lg border border-emerald-200">LEASE DOC</span>
                </div>
                <div id="modalLeaseDocImgBox" class="min-h-[120px] flex items-center justify-center bg-stone-50 rounded-xl overflow-hidden">
                </div>
              </div>

              <!-- ลิงก์ด่วน -->
              <div class="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-2xs space-y-2 text-xs">
                <div class="flex items-center justify-between">
                  <a id="modalRepairRequestsLink" href="admin_requests.php" class="text-blue-600 hover:underline font-semibold flex items-center gap-1">
                    <i data-lucide="wrench" class="w-3.5 h-3.5"></i> ประวัติการแจ้งซ่อมห้องนี้ <i data-lucide="chevron-right" class="w-3 h-3"></i>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-5 py-4 border-t border-slate-200/80 bg-white flex items-center justify-between shrink-0">
        <button type="button" onclick="document.getElementById('tenantInfoModal').classList.add('hidden')" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-2xl text-xs sm:text-sm transition cursor-pointer">
          ปิดหน้าต่าง
        </button>
      </div>
    </div>
  </div>



  <!-- MODAL 4: อนุมัติคำขอต่อสัญญาเช่า -->

  <div id="approveModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">

    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 modal-enter">

      <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">

        <h3 class="font-display font-bold text-slate-900 text-base flex items-center gap-2">

          <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600"></i> อนุมัติคำขอต่อสัญญาเช่า

        </h3>

        <button type="button" onclick="closeApproveModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" enctype="multipart/form-data" class="space-y-4">

        <input type="hidden" name="renewal_id" id="approveRenewalId" value="">

        <input type="hidden" name="lease_id" id="approveLeaseId" value="">

        <input type="hidden" name="extension_months" id="approveExtensionMonths" value="">



        <div class="p-3.5 bg-rose-50/70 rounded-2xl border border-indigo-100 text-xs space-y-1.5">

          <div class="flex justify-between">

            <span class="text-slate-500">ผู้เช่า:</span>

            <strong id="approveName" class="text-slate-900"></strong>

          </div>

          <div class="flex justify-between">

            <span class="text-slate-500">ห้องพัก:</span>

            <strong id="approveRoom" class="text-blue-600 font-mono font-bold"></strong>

          </div>

          <div class="flex justify-between">

            <span class="text-slate-500">ขอยืดอายุสัญญา:</span>

            <strong id="approveMonthsText" class="text-rose-700 font-bold"></strong>

          </div>

          <div class="flex justify-between pt-1 border-t border-rose-200/60">

            <span class="text-slate-600 font-semibold">วันสิ้นสุดสัญญาใหม่:</span>

            <strong id="approveNewEndDate" class="text-emerald-700 font-mono font-bold"></strong>

          </div>

        </div>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">ข้อความตอบกลับผู้เช่า (adminRemark)</label>

          <input type="text" name="admin_remark" id="approveRemark" placeholder="เช่น อนุมัติต่อสัญญาเช่าเรียบร้อยแล้ว" class="w-full px-3 py-2 text-xs bg-stone-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white transition">

        </div>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">แนบไฟล์เอกสารสัญญาฉบับใหม่ (ถ้ามี)</label>

          <input type="file" name="leaseImage" accept="image/*,.pdf" class="w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 bg-stone-50 border border-slate-200 rounded-xl cursor-pointer">

          <span class="text-[10px] text-slate-400 mt-1 block">* หากแนบไฟล์ใหม่ ระบบจะอัปเดตไฟล์สัญญาในหน้าระบบของผู้เช่าทันที</span>

        </div>



        <div class="flex gap-2 pt-2">

          <button type="button" onclick="closeApproveModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">

            ยกเลิก

          </button>

          <button type="submit" name="approve_renewal" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-md shadow-emerald-600/20 transition flex items-center justify-center gap-1">

            <i data-lucide="check-circle" class="w-4 h-4"></i> ยืนยันอนุมัติสัญญา

          </button>

        </div>

      </form>

    </div>

  </div>



  <!-- MODAL 5: ปฏิเสธคำขอต่อสัญญาเช่า -->

  <div id="rejectModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">

    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 modal-enter">

      <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">

        <h3 class="font-display font-bold text-slate-900 text-base flex items-center gap-2">

          <i data-lucide="x-circle" class="w-5 h-5 text-rose-500"></i> ปฏิเสธคำขอต่อสัญญาเช่า

        </h3>

        <button type="button" onclick="closeRejectModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="space-y-4">

        <input type="hidden" name="renewal_id" id="rejectId" value="">

        <p class="text-xs text-slate-600">

          ปฏิเสธคำขอต่อสัญญาของ: <strong id="rejectName" class="text-slate-900"></strong> (ห้อง <span id="rejectRoom" class="text-blue-600 font-bold"></span>)

        </p>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">เหตุผลที่ไม่ต่อสัญญา (ลูกบ้านจะเห็นข้อความนี้)</label>

          <textarea name="admin_remark" rows="3" required placeholder="เช่น สัญญาใกล้หมดและมีผู้จองแล้ว, ไม่อนุมัติตามเงื่อนไข ฯลฯ" class="w-full px-3 py-2 text-xs bg-stone-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white transition"></textarea>

        </div>



        <div class="flex gap-2 pt-2">

          <button type="button" onclick="closeRejectModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">

            ยกเลิก

          </button>

          <button type="submit" name="reject_renewal" class="flex-1 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-md transition">

            ยืนยันปฏิเสธคำขอ

          </button>

        </div>

      </form>

    </div>

  </div>



  <!-- MODAL 6: ดูเอกสารสัญญาเช่า (Lease Document Viewer) -->

  <div id="leaseDocModal" class="hidden fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">

    <div class="bg-white rounded-3xl max-w-4xl w-full h-[85vh] p-4 flex flex-col shadow-2xl relative">

      <div class="flex justify-between items-center pb-3 border-b border-slate-200">

        <h4 class="font-bold text-slate-900 text-sm flex items-center gap-2">

          <i data-lucide="file-text" class="w-4 h-4 text-rose-600"></i> เอกสารสัญญาเช่า

        </h4>

        <div class="flex items-center gap-2">

          <a id="leaseDocDownloadBtn" href="" target="_blank" download class="px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold flex items-center gap-1 transition">

            <i data-lucide="download" class="w-3.5 h-3.5"></i> ดาวน์โหลด

          </a>

          <button type="button" onclick="closeLeaseDoc()" class="p-1 rounded-full text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition">

            <i data-lucide="x" class="w-5 h-5"></i>

          </button>

        </div>

      </div>

      <div class="flex-1 overflow-auto p-2 flex items-center justify-center bg-stone-50 rounded-2xl mt-2">

        <iframe id="leaseDocFrame" src="" class="w-full h-full rounded-xl border border-slate-200"></iframe>

      </div>

    </div>

  </div>





  <!-- MODAL 7: อนุมัติคำขอย้ายห้อง (เลือกห้องว่างที่จะย้ายเข้า) -->

  <div id="approveRoomChangeModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">

    <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-200 modal-enter">

      <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">

        <h3 class="font-display font-bold text-slate-900 text-base flex items-center gap-2">

          <i data-lucide="clipboard-check" class="w-5 h-5 text-indigo-600"></i> ตรวจสอบคำขอย้ายห้องพัก & จัดสรรห้อง

        </h3>

        <button type="button" onclick="closeApproveRoomChangeModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 cursor-pointer">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="space-y-4">

        <input type="hidden" name="request_id" id="approveRCReqId" value="">

        

        <div class="p-3 bg-stone-50 rounded-2xl border border-slate-200/80 text-xs text-slate-700">

          ผู้เช่า: <strong id="approveRCTenantName" class="text-slate-900"></strong> (ปัจจุบันอยู่ห้อง <span id="approveRCCurrRoom" class="font-bold text-blue-600"></span>)

        </div>



        <!-- ส่วนแสดงสลิปชำระเงินค่าทำความสะอาด 500 บาท & สัญญาเดิม -->

        <div id="approveRCSlipWrap" class="p-3.5 bg-rose-50/80 border border-rose-200/80 rounded-2xl flex flex-col gap-2 shadow-2xs">

          <div class="flex items-center justify-between">

            <div class="flex items-center gap-2 text-xs text-indigo-950 font-bold">

              <i data-lucide="receipt" class="w-4 h-4 text-rose-600 shrink-0"></i>

              <span>หลักฐานสลิปค่าทำความสะอาด (500 บาท):</span>

            </div>

            <div id="approveRCSlipAction">

              <!-- filled by JS -->

            </div>

          </div>

          <div id="approveRCLeaseDocAction" class="pt-2 border-t border-rose-200/60 flex items-center justify-between text-xs text-indigo-900">

            <!-- filled by JS -->

          </div>

        </div>



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">เลือกห้องว่างที่จะให้ผู้เช่าย้ายเข้าพัก <span class="text-rose-500">*</span></label>

          <div class="relative">
            <select name="new_room_id" id="approveRCNewRoomId" required class="w-full px-4 py-3 pr-10 text-xs sm:text-sm font-bold bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 rounded-2xl outline-none transition text-slate-800 appearance-none cursor-pointer">

            <option value="">-- เลือกห้องว่างจากรายการ --</option>

            <?php if(!empty($availableRooms)): foreach($availableRooms as $ar): ?>

              <option value="<?php echo $ar['roomId']; ?>" data-type="<?php echo $ar['roomType']; ?>">

                ห้อง <?php echo $ar['roomNumber']; ?> (<?php echo ($ar['roomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?> ชั้น <?php echo $ar['floor']; ?> - ฿<?php echo number_format($ar['rentPrice']); ?>/เดือน)

              </option>

            <?php endforeach; else: ?>

              <option value="" disabled>-- ขณะนี้ไม่มีห้องว่างในระบบ --</option>

            <?php endif; ?>

            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-rose-500">
              <i data-lucide="chevron-down" class="w-4 h-4"></i>
            </div>
          </div>

          <p class="text-[11px] text-slate-400 mt-1">เมื่องานอนุมัติ สัญญาเช่าจะถูกย้ายมายังห้องใหม่ และห้องเดิมจะถูกเปลี่ยนเป็นสถานะห้องว่างอัตโนมัติ</p>

        </div>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">ข้อความอนุมัติ/คำแนะนำถึงลูกบ้าน (Optional)</label>

          <textarea name="admin_remark" rows="2" placeholder="เช่น อนุมัติย้ายเข้าห้องเรียบร้อย กรุณาติดต่อรับกุญแจห้องใหม่ที่นิติบุคคล" class="w-full px-3 py-2 text-xs bg-stone-50 border border-slate-200 rounded-xl outline-none focus:border-emerald-500 transition"></textarea>

        </div>



        <div class="flex gap-2 pt-2">

          <button type="button" onclick="closeApproveRoomChangeModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">

            ยกเลิก

          </button>

          <button type="submit" name="approve_room_change" class="flex-1 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-md transition flex items-center justify-center gap-1.5 cursor-pointer">

            <i data-lucide="check" class="w-4 h-4"></i> ยืนยันอนุมัติย้ายห้อง

          </button>

        </div>

      </form>

    </div>

  </div>



  <!-- MODAL 8: ปฏิเสธคำขอย้ายห้อง -->

  <div id="rejectRoomChangeModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">

    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 modal-enter">

      <div class="flex items-center justify-between pb-3 border-b border-slate-200 mb-4">

        <h3 class="font-display font-bold text-slate-900 text-base flex items-center gap-2">

          <i data-lucide="x-circle" class="w-5 h-5 text-rose-500"></i> ปฏิเสธคำขอย้ายห้องพัก

        </h3>

        <button type="button" onclick="closeRejectRoomChangeModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100 cursor-pointer">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="space-y-4">

        <input type="hidden" name="request_id" id="rejectRCId" value="">

        <p class="text-xs text-slate-600">

          ปฏิเสธคำขอของ: <strong id="rejectRCName" class="text-slate-900"></strong> (ห้อง <span id="rejectRCRoom" class="font-bold text-blue-600"></span>)

        </p>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">เหตุผลที่ไม่สามารถย้ายห้องได้ (ลูกบ้านจะเห็นข้อความนี้)</label>

          <textarea name="admin_remark" rows="3" required placeholder="เช่น ขณะนี้ไม่มีห้องว่างประเภทที่ต้องการ, ไม่เข้าเกณฑ์การย้าย ฯลฯ" class="w-full px-3 py-2 text-xs bg-stone-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white transition"></textarea>

        </div>



        <div class="flex gap-2 pt-2">

          <button type="button" onclick="closeRejectRoomChangeModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">

            ยกเลิก

          </button>

          <button type="submit" name="reject_room_change" class="flex-1 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer">

            ยืนยันปฏิเสธคำขอ

          </button>

        </div>

      </form>

    </div>

  </div>



  <!-- ======================================================== -->
  <!-- 🌟 MODAL: รายละเอียดคำขอแจ้งย้ายออก (Move Out Detail Modal แบบ 2 กรอบ) 🌟 -->
  <!-- ======================================================== -->
  <div id="moveOutDetailModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-sm p-3 sm:p-5 flex items-center justify-center animate-fade-in" onclick="if(event.target === this) closeMoveOutModal()">
    <div class="bg-white border border-slate-200/90 rounded-3xl shadow-2xl max-w-5xl w-full overflow-hidden flex flex-col max-h-[92vh] my-auto animate-scale-up" onclick="event.stopPropagation()">
      
      <!-- Modal Top Header -->
      <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-stone-50 via-white to-rose-50/30 shrink-0">
        <div class="flex items-center gap-2.5 flex-wrap">
          <span id="mod_room_badge" class="font-bold font-mono text-xs px-3 py-1 bg-slate-900 text-white rounded-xl shadow-2xs">ห้อง -</span>
          <span id="mod_code_badge" class="text-xs font-mono font-bold text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-xl border border-slate-200">MOVE-OUT</span>
          <span id="mod_status_badge" class="text-xs font-bold px-3 py-1 rounded-full border">สถานะ</span>
        </div>
        <button type="button" onclick="closeMoveOutModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-rose-50 hover:text-rose-600 flex items-center justify-center text-slate-400 transition cursor-pointer" title="ปิดหน้าต่าง">
          <i data-lucide="x" class="w-4 h-4"></i>
        </button>
      </div>

      <!-- Modal Body (Two-Column Layout) -->
      <div class="p-4 sm:p-6 overflow-y-auto flex-1 space-y-4 bg-[#FAF9F6]">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

          <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลของผู้เช่า (Tenant Frame) -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-blue-200/80 bg-gradient-to-b from-blue-50/40 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">
            <div>
              <!-- Header กรอบผู้เช่า -->
              <div class="flex items-center justify-between pb-3.5 border-b border-blue-200/80 mb-4">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-2xl bg-blue-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i data-lucide="user" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลของผู้เช่า</h4>
                    <p class="text-[11px] text-blue-700 font-medium">รายละเอียดคำขอย้ายออก และวันเวลานัดหมาย</p>
                  </div>
                </div>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-100/80 text-blue-800 border border-blue-200 font-mono">
                  ผู้เช่าห้องพัก
                </span>
              </div>

              <!-- ข้อมูลผู้เช่า / ผู้แจ้งเรื่อง -->
              <div class="p-4 bg-white rounded-2xl border border-blue-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 shadow-2xs font-bold">
                    <i data-lucide="user" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">ผู้เช่า / ผู้แจ้งเรื่อง</span>
                    <span id="mod_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</span>
                    <div class="text-xs text-slate-500 font-mono mt-0.5" id="mod_tenant_phone">-</div>
                  </div>
                </div>
                <a id="mod_tenant_call_btn" href="#" class="px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 rounded-xl text-xs font-bold border border-emerald-200 transition flex items-center gap-1.5 shadow-2xs">
                  <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i>
                  <span>โทรหาผู้เช่า</span>
                </a>
              </div>

              <!-- วันที่และเหตุผลการย้ายออก -->
              <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs mb-4 space-y-3">
                <div class="flex items-center gap-2.5 p-3 rounded-xl bg-rose-50 border border-rose-200/80">
                  <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0">
                    <i data-lucide="calendar" class="w-4 h-4"></i>
                  </div>
                  <div>
                    <span class="text-[10px] text-rose-500 font-bold uppercase tracking-wider block">วันที่ประสงค์จะย้ายออก</span>
                    <span id="mod_moveout_date" class="font-bold text-rose-950 text-xs sm:text-sm">-</span>
                  </div>
                </div>

                <div>
                  <div class="text-[11px] font-bold text-slate-500 mb-1 flex items-center gap-1.5">
                    <i data-lucide="align-left" class="w-3.5 h-3.5 text-blue-600"></i> รายละเอียดเหตุผลการย้ายออก:
                  </div>
                  <p id="mod_moveout_reason" class="text-xs sm:text-sm text-slate-700 bg-stone-50 p-3 rounded-xl border border-stone-200/60 leading-relaxed font-normal whitespace-pre-line">-</p>
                </div>

                <div class="text-xs text-slate-500 pt-2 border-t border-slate-100 flex items-center justify-between">
                  <span>ช่วงเวลาสัญญาเช่าเดิม:</span>
                  <span id="mod_lease_period" class="font-bold text-slate-800 font-mono">-</span>
                </div>
              </div>

              <!-- บัญชีรับเงินประกันคืน -->
              <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs mb-4">
                <div class="text-[11px] font-bold text-slate-500 mb-2 flex items-center gap-1.5">
                  <i data-lucide="credit-card" class="w-3.5 h-3.5 text-blue-600"></i> บัญชีธนาคารสำหรับรับเงินคืน:
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                  <div class="p-2.5 bg-stone-50 rounded-xl border border-stone-200/60">
                    <span class="text-[10px] text-slate-400 block">ธนาคาร</span>
                    <span id="mod_bank_name" class="font-bold text-slate-800 text-xs">-</span>
                  </div>
                  <div class="p-2.5 bg-stone-50 rounded-xl border border-stone-200/60">
                    <span class="text-[10px] text-slate-400 block">เลขที่บัญชี</span>
                    <span id="mod_bank_account" class="font-mono font-bold text-slate-900 text-xs">-</span>
                  </div>
                  <div class="p-2.5 bg-stone-50 rounded-xl border border-stone-200/60">
                    <span class="text-[10px] text-slate-400 block">ชื่อบัญชี</span>
                    <span id="mod_account_name" class="font-bold text-slate-800 text-xs">-</span>
                  </div>
                </div>
              </div>

              <!-- สลิปชำระเงินส่วนต่างจากลูกบ้าน (TENANT SLIP) -->
              <div id="mod_tenant_slip_wrap" class="hidden bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">
                    <i data-lucide="receipt" class="w-4 h-4 text-emerald-600"></i> สลิปชำระเงินส่วนต่างจากผู้เช่า
                  </span>
                  <span class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2 py-0.5 rounded-lg border border-emerald-200">TENANT SLIP</span>
                </div>
                <div id="mod_tenant_slip_img_box" class="min-h-[140px] flex items-center justify-center bg-stone-50 rounded-xl overflow-hidden">
                </div>
              </div>

            </div>
          </div>

          <!-- 🟩 กรอบที่ 2: ส่วนการตรวจสอบ & สรุปเงินประกัน (Staff Frame) -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">
            <div>
              <!-- Header กรอบนิติบุคคล -->
              <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i data-lucide="calculator" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <h4 class="font-display font-bold text-slate-900 text-base">ส่วนการตรวจสอบ & สรุปเงินประกัน</h4>
                    <p class="text-[11px] text-emerald-700 font-medium">เจ้าหน้าที่ผู้รับผิดชอบ ไทม์ไลน์ และผลสรุปเงิน</p>
                  </div>
                </div>
                <span id="mod_staff_status_badge" class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">
                  นิติบุคคล
                </span>
              </div>

              <!-- กล่องสถานะการเคลียร์ห้อง -->
              <div id="mod_status_box" class="p-4 rounded-2xl border shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">
              </div>

              <!-- ไทม์ไลน์ 3 ขั้นตอน -->
              <div class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs mb-4">
                <div class="text-[11px] font-bold text-slate-400 mb-2.5 uppercase tracking-wider">บันทึกวันและเวลาการทำงาน</div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 text-xs">
                  <div class="p-3 bg-stone-50 rounded-xl border border-stone-200/70">
                    <div class="text-amber-600 font-bold flex items-center gap-1 mb-1">
                      <i data-lucide="send" class="w-3.5 h-3.5"></i> 1. แจ้งเมื่อ
                    </div>
                    <span id="mod_time_requested" class="font-mono text-slate-700 font-bold block">-</span>
                  </div>
                  <div class="p-3 bg-stone-50 rounded-xl border border-stone-200/70">
                    <div class="text-blue-600 font-bold flex items-center gap-1 mb-1">
                      <i data-lucide="calendar" class="w-3.5 h-3.5"></i> 2. วันที่ย้ายออก
                    </div>
                    <span id="mod_time_moveout" class="font-mono text-slate-700 font-bold block">-</span>
                  </div>
                  <div class="p-3 bg-stone-50 rounded-xl border border-stone-200/70">
                    <div class="text-emerald-600 font-bold flex items-center gap-1 mb-1">
                      <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> 3. เคลียร์เสร็จ
                    </div>
                    <span id="mod_time_settled" class="font-mono text-slate-700 font-bold block">-</span>
                  </div>
                </div>
              </div>

              <!-- สรุปตัวเลขเงินประกัน / ค่าใช้จ่าย -->
              <div class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs mb-4 space-y-2.5 text-xs">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100 font-bold text-slate-800">
                  <span>เงินประกันเดิม (มัดจำ):</span>
                  <span id="mod_deposit_amount" class="font-mono font-bold text-slate-900">฿0.00</span>
                </div>
                <div id="mod_deductions_detail" class="space-y-1.5 text-slate-600">
                </div>
                <div class="pt-2 border-t-2 border-dashed border-slate-200 flex items-center justify-between font-bold text-sm">
                  <span id="mod_net_label">ยอดเงิน:</span>
                  <span id="mod_net_amount" class="font-mono font-bold text-base sm:text-lg text-emerald-600">฿0.00</span>
                </div>
              </div>

              <!-- สลิปหลักฐานโอนคืนเงินประกัน (REFUND SLIP) -->
              <div id="mod_refund_slip_wrap" class="hidden bg-white border-2 border-dashed border-rose-300 rounded-2xl p-3.5 shadow-2xs">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-xs font-bold text-rose-900 flex items-center gap-1.5">
                    <i data-lucide="receipt" class="w-4 h-4 text-rose-600"></i> สลิปโอนคืนเงินประกันจากนิติบุคคล
                  </span>
                  <span class="text-[10px] bg-rose-100 text-rose-900 font-extrabold px-2 py-0.5 rounded-lg border border-rose-200">REFUND SLIP</span>
                </div>
                <div id="mod_refund_slip_img_box" class="min-h-[140px] flex items-center justify-center bg-stone-50 rounded-xl overflow-hidden">
                </div>
              </div>

            </div>
          </div>

        </div>
      </div>

      <!-- Modal Footer Actions -->
      <div class="px-5 py-4 border-t border-slate-200/80 bg-white flex items-center justify-between shrink-0">
        <button type="button" onclick="closeMoveOutModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-2xl text-xs sm:text-sm transition cursor-pointer">
          ปิดหน้าต่าง
        </button>
        <a id="mod_action_btn" href="#" class="px-6 py-2.5 bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white font-bold rounded-2xl text-xs sm:text-sm shadow-md transition flex items-center gap-2 cursor-pointer">
          <i data-lucide="calculator" class="w-4 h-4"></i>
          <span id="mod_action_btn_text">สรุปเงิน & เคลียร์ห้อง</span>
        </a>
      </div>

    </div>
  </div>

  <!-- MODAL: รายละเอียดคำขอต่อสัญญาเช่า (2 Columns) -->
  <div id="renewalDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4 overflow-y-auto">
    <div class="bg-white w-full max-w-4xl max-h-[92vh] rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">
      <!-- Header -->
      <div class="px-5 py-4 sm:px-6 sm:py-5 border-b border-slate-200/80 flex justify-between items-center bg-slate-50/70 shrink-0">
        <div class="flex items-center gap-3">
          <span id="ren_modal_room_badge" class="px-3 py-1 bg-slate-900 text-white rounded-xl font-bold text-sm shadow-xs font-mono">ห้อง -</span>
          <span id="ren_modal_id_badge" class="px-2.5 py-0.5 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-xs font-mono font-bold">#REN-0000</span>
          <span id="ren_modal_status_badge" class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">รออนุมัติ</span>
        </div>
        <button type="button" onclick="closeRenewalDetailModal()" class="w-9 h-9 rounded-2xl bg-white/80 backdrop-blur-md p-1.5 border border-rose-200/60 shadow-xs hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิด">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <!-- Body 2 columns -->
      <div class="p-4 sm:p-6 overflow-y-auto flex-1 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
          <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลผู้เช่า & สัญญาเดิม -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-indigo-200 bg-gradient-to-b from-indigo-50/40 to-slate-50/20 shadow-2xs space-y-4">
            <div class="flex items-center gap-2.5 pb-3.5 border-b border-indigo-200/80">
              <div class="w-9 h-9 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                <i data-lucide="user" class="w-5 h-5"></i>
              </div>
              <div>
                <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลผู้เช่า & สัญญาเดิม</h4>
                <p class="text-[11px] text-indigo-700 font-medium">รายละเอียดผู้ยื่นคำขอและเงื่อนไขสัญญาปัจจุบัน</p>
              </div>
            </div>
            <!-- ข้อมูลผู้เช่า -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs flex items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <div id="ren_modal_avatar" class="w-11 h-11 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-700 font-extrabold flex items-center justify-center text-base">
                  ผ
                </div>
                <div>
                  <span class="text-[10px] text-slate-400 font-semibold uppercase">ผู้เช่า / ผู้ยื่นคำขอ</span>
                  <div id="ren_modal_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</div>
                  <div id="ren_modal_tenant_phone" class="text-xs text-slate-500 font-mono mt-0.5">📞 -</div>
                </div>
              </div>
              <a id="ren_modal_call_btn" href="#" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                <i data-lucide="phone-call" class="w-3.5 h-3.5"></i> โทรหาผู้เช่า
              </a>
            </div>
            <!-- รายละเอียดสัญญาเดิม -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs space-y-2.5 text-xs text-slate-600">
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">ห้องพัก:</span>
                <strong id="ren_modal_room_info" class="text-slate-900 font-mono font-bold">-</strong>
              </div>
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">ค่าเช่ารายเดือน:</span>
                <strong id="ren_modal_rent_price" class="text-slate-900 font-mono font-bold">฿0.00</strong>
              </div>
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">เงินประกันสัญญา:</span>
                <strong id="ren_modal_deposit" class="text-emerald-600 font-mono font-bold">฿0.00</strong>
              </div>
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">เริ่มสัญญาเมื่อ:</span>
                <span id="ren_modal_start_date" class="font-mono text-slate-700 font-bold">-</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">สิ้นสุดสัญญาปัจจุบัน:</span>
                <span id="ren_modal_current_end_date" class="font-mono text-rose-600 font-bold">-</span>
              </div>
            </div>
            <!-- เอกสารสัญญาเช่าเดิม -->
            <div id="ren_modal_lease_doc_wrap" class="bg-white border-2 border-dashed border-indigo-300 rounded-2xl p-3.5 shadow-2xs">
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-indigo-900 flex items-center gap-1.5">
                  <i data-lucide="file-text" class="w-4 h-4 text-indigo-600"></i> เอกสารสัญญาเช่าปัจจุบัน
                </span>
                <span class="text-[10px] bg-indigo-100 text-indigo-900 font-extrabold px-2 py-0.5 rounded-lg border border-indigo-200">LEASE DOC</span>
              </div>
              <div id="ren_modal_lease_doc_box" class="min-h-[100px] flex items-center justify-center bg-stone-50 rounded-xl overflow-hidden text-xs text-slate-400">
                ไม่มีเอกสารแนบ
              </div>
            </div>
          </div>

          <!-- 🟩 กรอบที่ 2: ส่วนคำขอต่อสัญญา & นิติบุคคล -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">
            <div>
              <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i data-lucide="calendar-plus" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <h4 class="font-display font-bold text-slate-900 text-base">ส่วนคำขอต่อสัญญา & นิติบุคคล</h4>
                    <p class="text-[11px] text-emerald-700 font-medium">ระยะเวลาที่ขอต่อ ไทม์ไลน์ และผลการพิจารณา</p>
                  </div>
                </div>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">
                  นิติบุคคล
                </span>
              </div>

              <!-- กล่องสรุปการต่อสัญญา -->
              <div class="p-4 rounded-2xl border border-emerald-200 bg-white shadow-2xs space-y-3 mb-4">
                <div class="flex items-center justify-between">
                  <span class="text-xs text-slate-500">ระยะเวลาที่ขอขยายสัญญา:</span>
                  <span id="ren_modal_ext_months" class="px-3 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-xl font-bold font-mono text-sm">+0 เดือน</span>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                  <span class="text-xs text-slate-500">วันสิ้นสุดสัญญาใหม่:</span>
                  <span id="ren_modal_new_end_date" class="font-mono text-emerald-700 font-extrabold text-sm sm:text-base">-</span>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                  <span class="text-xs text-slate-500">ยื่นคำขอเมื่อ:</span>
                  <span id="ren_modal_created_at" class="font-mono text-slate-700 text-xs">-</span>
                </div>
              </div>

              <!-- บันทึกจากนิติบุคคล (Admin Remark) -->
              <div id="ren_modal_remark_wrap" class="p-4 rounded-2xl border border-slate-200 bg-white shadow-2xs mb-4">
                <div class="text-[11px] font-bold text-slate-400 mb-1 flex items-center gap-1.5 uppercase">
                  <i data-lucide="message-square" class="w-3.5 h-3.5"></i> ข้อความ / บันทึกจากนิติบุคคล
                </div>
                <p id="ren_modal_remark_text" class="text-xs text-slate-700 leading-relaxed font-normal">-</p>
              </div>
            </div>

            <!-- Actions Area -->
            <div id="ren_modal_actions" class="pt-2">
            </div>
          </div>
        </div>
      </div>
      <!-- Footer -->
      <div class="px-5 py-4 border-t border-slate-200/80 bg-white flex items-center justify-between shrink-0">
        <button type="button" onclick="closeRenewalDetailModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-2xl text-xs sm:text-sm transition cursor-pointer">
          ปิดหน้าต่าง
        </button>
        <div id="ren_modal_footer_right" class="flex gap-2">
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: รายละเอียดคำขอย้ายห้อง (2 Columns) -->
  <div id="roomChangeDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4 overflow-y-auto">
    <div class="bg-white w-full max-w-4xl max-h-[92vh] rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">
      <!-- Header -->
      <div class="px-5 py-4 sm:px-6 sm:py-5 border-b border-slate-200/80 flex justify-between items-center bg-slate-50/70 shrink-0">
        <div class="flex items-center gap-3">
          <span id="rc_modal_room_badge" class="px-3 py-1 bg-slate-900 text-white rounded-xl font-bold text-sm shadow-xs font-mono">ห้อง -</span>
          <span id="rc_modal_id_badge" class="px-2.5 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-lg text-xs font-mono font-bold">#CHG-0000</span>
          <span id="rc_modal_status_badge" class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">รออนุมัติ</span>
        </div>
        <button type="button" onclick="closeRoomChangeDetailModal()" class="w-9 h-9 rounded-2xl bg-white/80 backdrop-blur-md p-1.5 border border-rose-200/60 shadow-xs hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิด">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <!-- Body 2 columns -->
      <div class="p-4 sm:p-6 overflow-y-auto flex-1 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
          <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลผู้เช่า & ห้องปัจจุบัน -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-indigo-200 bg-gradient-to-b from-indigo-50/40 to-slate-50/20 shadow-2xs space-y-4">
            <div class="flex items-center gap-2.5 pb-3.5 border-b border-indigo-200/80">
              <div class="w-9 h-9 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                <i data-lucide="user" class="w-5 h-5"></i>
              </div>
              <div>
                <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลผู้เช่า & ห้องปัจจุบัน</h4>
                <p class="text-[11px] text-indigo-700 font-medium">รายละเอียดผู้ขอย้ายและห้องพักปัจจุบัน</p>
              </div>
            </div>
            <!-- ผู้เช่า -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs flex items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <div id="rc_modal_avatar" class="w-11 h-11 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-700 font-extrabold flex items-center justify-center text-base">
                  ผ
                </div>
                <div>
                  <span class="text-[10px] text-slate-400 font-semibold uppercase">ผู้เช่า / ผู้ยื่นคำขอ</span>
                  <div id="rc_modal_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</div>
                  <div id="rc_modal_tenant_phone" class="text-xs text-slate-500 font-mono mt-0.5">📞 -</div>
                </div>
              </div>
              <a id="rc_modal_call_btn" href="#" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                <i data-lucide="phone-call" class="w-3.5 h-3.5"></i> โทรหาผู้เช่า
              </a>
            </div>
            <!-- ห้องปัจจุบัน -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs space-y-2.5 text-xs text-slate-600">
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">ห้องพักปัจจุบัน:</span>
                <strong id="rc_modal_curr_room" class="text-slate-900 font-mono font-bold">-</strong>
              </div>
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">ประเภท / ชั้น:</span>
                <span id="rc_modal_curr_type" class="text-slate-700 font-semibold">-</span>
              </div>
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">ค่าเช่าปัจจุบัน:</span>
                <strong id="rc_modal_curr_rent" class="text-slate-900 font-mono font-bold">฿0.00</strong>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">สัญญาปัจจุบัน:</span>
                <span id="rc_modal_curr_lease_dates" class="font-mono text-slate-700">-</span>
              </div>
            </div>
            <!-- สลิปค่าทำความสะอาด -->
            <div id="rc_modal_slip_wrap" class="bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs">
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">
                  <i data-lucide="receipt" class="w-4 h-4 text-emerald-600"></i> สลิปค่าทำความสะอาด (500 บาท)
                </span>
                <span id="rc_modal_slip_badge" class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2 py-0.5 rounded-lg border border-emerald-200">SLIP 500฿</span>
              </div>
              <div id="rc_modal_slip_box" class="min-h-[120px] flex items-center justify-center bg-stone-50 rounded-xl overflow-hidden">
              </div>
            </div>
          </div>

          <!-- 🟩 กรอบที่ 2: ส่วนคำขอย้าย & การดำเนินการของนิติบุคคล -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">
            <div>
              <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i data-lucide="arrow-right-left" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <h4 class="font-display font-bold text-slate-900 text-base">ส่วนคำขอย้าย & นิติบุคคล</h4>
                    <p class="text-[11px] text-emerald-700 font-medium">ประเภทห้องที่ต้องการ เหตุผล และผลการจัดสรร</p>
                  </div>
                </div>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">
                  นิติบุคคล
                </span>
              </div>

              <!-- กล่องเป้าหมายการย้าย -->
              <div class="p-4 rounded-2xl border border-emerald-200 bg-white shadow-2xs space-y-3 mb-4 text-xs">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-500">ประเภทห้องที่ขอย้าย:</span>
                  <span id="rc_modal_target_type" class="px-2.5 py-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-xl font-bold">-</span>
                </div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-500">วันที่ต้องการย้าย:</span>
                  <span id="rc_modal_target_date" class="font-mono text-slate-800 font-bold">-</span>
                </div>
                <div class="pb-1">
                  <span class="text-slate-400 block mb-1">เหตุผลในการขอย้าย:</span>
                  <div id="rc_modal_reason" class="p-2.5 bg-stone-50 rounded-xl border border-stone-200 text-slate-700 text-xs">-</div>
                </div>
              </div>

              <!-- กล่องผลการจัดสรรห้อง / บันทึกนิติบุคคล -->
              <div class="p-4 rounded-2xl border border-slate-200 bg-white shadow-2xs space-y-2 mb-4 text-xs">
                <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                  <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> ผลการพิจารณา & ห้องที่จัดสรร
                </div>
                <div id="rc_modal_allocation_box" class="space-y-2">
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div id="rc_modal_actions" class="pt-2">
            </div>
          </div>
        </div>
      </div>
      <!-- Footer -->
      <div class="px-5 py-4 border-t border-slate-200/80 bg-white flex items-center justify-between shrink-0">
        <button type="button" onclick="closeRoomChangeDetailModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-2xl text-xs sm:text-sm transition cursor-pointer">
          ปิดหน้าต่าง
        </button>
        <div id="rc_modal_footer_right" class="flex gap-2">
        </div>
      </div>
    </div>
  </div>

  <?php include_once 'includes/doc_viewer_modal.php'; ?>



  <!-- ======================================================== -->

  <!-- 🌟 JAVASCRIPT LOGIC 🌟 -->

  <!-- ======================================================== -->

  <script>

    lucide.createIcons();



    // 1. ฟังก์ชันสลับแท็บหลัก (4 แท็บ: rooms, renewals, room_changes, moveouts)

    let activeMainTab = '<?php echo $initialTab; ?>';



    const mainTabsConfig = {

        'rooms': {

            btnId: 'mainTabBtnRooms',

            contentId: 'tabContentRooms',

            activeClass: 'bg-white text-slate-900 shadow-2xs font-bold border border-slate-200'

        },

        'renewals': {

            btnId: 'mainTabBtnRenewals',

            contentId: 'tabContentRenewals',

            activeClass: 'bg-white text-rose-700 shadow-2xs font-bold border border-rose-200'

        },

        'room_changes': {

            btnId: 'mainTabBtnRoomChanges',

            contentId: 'tabContentRoomChanges',

            activeClass: 'bg-white text-rose-700 shadow-2xs font-bold border border-rose-200'

        },

        'moveouts': {

            btnId: 'mainTabBtnMoveOuts',

            contentId: 'tabContentMoveOuts',

            activeClass: 'bg-white text-rose-700 shadow-2xs font-bold border border-rose-200'

        }

    };



    function switchMainTab(tab) {

        if (!mainTabsConfig[tab]) tab = 'rooms';

        activeMainTab = tab;



        Object.keys(mainTabsConfig).forEach(key => {

            const cfg = mainTabsConfig[key];

            const btn = document.getElementById(cfg.btnId);

            const content = document.getElementById(cfg.contentId);



            if (content) {

                if (key === tab) {

                    content.classList.remove('hidden');

                } else {

                    content.classList.add('hidden');

                }

            }



            if (btn) {

                if (key === tab) {

                    btn.className = 'px-4 sm:px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer ' + cfg.activeClass;

                } else {

                    btn.className = 'px-4 sm:px-5 py-2.5 rounded-xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer text-slate-500 hover:text-slate-800 hover:bg-white/60';

                }

            }

        });



        // อัปเดต URL เพื่อให้แชร์หรือรีเฟรชกลับมาแท็บเดิมได้ทันที

        try {

            const url = new URL(window.location.href);

            url.searchParams.set('tab', tab);

            window.history.replaceState({}, '', url.toString());

        } catch(e) {}



        lucide.createIcons();

    }



    // 2. ซ่อน Alert อัตโนมัติ

    function dismissFlashMessage() {

        const msg = document.getElementById('flashMessage');

        if (msg) {

            msg.style.opacity = '0';

            msg.style.transform = 'translateY(-8px)';

            setTimeout(() => { msg.style.display = 'none'; }, 400);

        }

    }

    setTimeout(dismissFlashMessage, 3500);



    // 3. ฟิลเตอร์ตารางห้องพัก (Tab 1: Rooms)

    let currentRoomStatusFilter = '<?php echo $initialFilter; ?>';



    function filterByStatus(status) {

        currentRoomStatusFilter = status;

        const tabs = {

            'all': document.getElementById('btnFilterAll'),

            'Occupied': document.getElementById('btnFilterOccupied'),

            'Available': document.getElementById('btnFilterAvailable'),

            'MoveOut': document.getElementById('btnFilterMoveOut'),

            'Renewing': document.getElementById('btnFilterRenewing')

        };



        Object.keys(tabs).forEach(key => {

            const btn = tabs[key];

            if (btn) {

                if (key === status) {

                    btn.className = 'filter-tab px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-2xs font-bold border border-slate-200 font-bold';

                } else {

                    btn.className = 'filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 font-semibold';

                }

            }

        });

        applyRoomFilters();

    }



    function applyRoomFilters() {

        const input = document.getElementById('roomSearchInput');

        const clearBtn = document.getElementById('clearSearchBtn');

        const query = (input ? input.value : '').trim().toLowerCase();

        if (clearBtn) clearBtn.classList.toggle('hidden', query === '');



        const rows = document.querySelectorAll('.room-row');

        let visible = 0;



        rows.forEach(row => {

            const searchData = (row.getAttribute('data-search') || '').toLowerCase();

            const status = row.getAttribute('data-status') || '';

            const hasMoveout = row.getAttribute('data-has-moveout') === '1';

            const hasRenewal = row.getAttribute('data-has-renewal') === '1';



            const matchQuery = query === '' || searchData.includes(query);

            let matchStatus = false;



            if (currentRoomStatusFilter === 'all') {

                matchStatus = true;

            } else if (currentRoomStatusFilter === 'Occupied') {

                matchStatus = (status === 'Occupied');

            } else if (currentRoomStatusFilter === 'Available') {

                matchStatus = (status === 'Available');

            } else if (currentRoomStatusFilter === 'MoveOut') {

                matchStatus = hasMoveout;

            } else if (currentRoomStatusFilter === 'Renewing') {

                matchStatus = hasRenewal;

            }



            if (matchQuery && matchStatus) {

                row.style.display = '';

                visible++;

            } else {

                row.style.display = 'none';

            }

        });



        const vCount = document.getElementById('visibleCount');

        if (vCount) vCount.innerText = visible;

    }



    function clearSearch() {

        const input = document.getElementById('roomSearchInput');

        if (input) {

            input.value = '';

            applyRoomFilters();

            input.focus();

        }

    }



    const roomSearchEl = document.getElementById('roomSearchInput');

    if (roomSearchEl) {

        roomSearchEl.addEventListener('input', applyRoomFilters);

    }



    // 4. ฟิลเตอร์ตารางคำขอต่อสัญญา (Tab 2: Renewals)

    let currentRenewalStatusFilter = 'all';



    function filterRenewalsByStatus(status) {

        currentRenewalStatusFilter = status;

        const tabs = {

            'all': document.getElementById('btnRenFilterAll'),

            'Pending': document.getElementById('btnRenFilterPending'),

            'Approved': document.getElementById('btnRenFilterApproved'),

            'Rejected': document.getElementById('btnRenFilterRejected')

        };



        Object.keys(tabs).forEach(key => {

            const btn = tabs[key];

            if (btn) {

                if (key === status) {

                    btn.className = 'filter-ren-tab px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-2xs font-bold border border-slate-200 font-bold';

                } else {

                    btn.className = 'filter-ren-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 font-semibold';

                }

            }

        });

        applyRenewalFilters();

    }



    function applyRenewalFilters() {

        const input = document.getElementById('renewalSearchInput');

        const query = (input ? input.value : '').trim().toLowerCase();

        const rows = document.querySelectorAll('.renewal-row');



        rows.forEach(row => {

            const searchData = (row.getAttribute('data-search') || '').toLowerCase();

            const status = row.getAttribute('data-status') || '';



            const matchQuery = query === '' || searchData.includes(query);

            const matchStatus = (currentRenewalStatusFilter === 'all' || status === currentRenewalStatusFilter);



            if (matchQuery && matchStatus) {

                row.style.display = '';

            } else {

                row.style.display = 'none';

            }

        });

    }



    // 5. ฟังก์ชันเปิด/ปิด Modal อนุมัติ & ปฏิเสธคำขอต่อสัญญา

    function openApproveModal(id, leaseId, months, name, room, newEndDate) {

        document.getElementById('approveRenewalId').value = id;

        document.getElementById('approveLeaseId').value = leaseId;

        document.getElementById('approveExtensionMonths').value = months;

        document.getElementById('approveName').innerText = name;

        document.getElementById('approveRoom').innerText = room;

        document.getElementById('approveMonthsText').innerText = '+' + months + ' เดือน';

        document.getElementById('approveNewEndDate').innerText = newEndDate;

        document.getElementById('approveRemark').value = 'อนุมัติต่อสัญญาเช่าเพิ่ม ' + months + ' เดือนเรียบร้อยแล้ว';

        document.getElementById('approveModal').classList.remove('hidden');

        lucide.createIcons();

    }



    function closeApproveModal() {

        document.getElementById('approveModal').classList.add('hidden');

    }



    function openRejectModal(id, name, room) {

        document.getElementById('rejectId').value = id;

        document.getElementById('rejectName').innerText = name;

        document.getElementById('rejectRoom').innerText = room;

        document.getElementById('rejectModal').classList.remove('hidden');

        lucide.createIcons();

    }



    function closeRejectModal() {

        document.getElementById('rejectModal').classList.add('hidden');

    }



    // 6. ฟังก์ชันเปิดดูเอกสารสัญญาเช่า

    function openLeaseDoc(url) {

        if (!url) return;

        document.getElementById('leaseDocFrame').src = url;

        document.getElementById('leaseDocDownloadBtn').href = url;

        document.getElementById('leaseDocModal').classList.remove('hidden');

        lucide.createIcons();

    }



    function closeLeaseDoc() {

        document.getElementById('leaseDocModal').classList.add('hidden');

        document.getElementById('leaseDocFrame').src = '';

    }



    // 7. ฟังก์ชันเปิด Modal แสดงข้อมูลผู้เช่า
    function openTenantModal(room, name, phone, email, emergencyName, emergencyPhone, start, end, rent, deposit, leaseImage, coresidents, roomId) {
        document.getElementById('modalRoomNum').innerText = room;
        document.getElementById('modalTenantName').innerText = name;
        const avatarEl = document.getElementById('modalTenantAvatar');
        if (avatarEl) avatarEl.innerText = name ? name.charAt(0) : 'ผ';
        document.getElementById('modalPhone').innerText = phone ? phone : '-';
        document.getElementById('modalEmail').innerText = email ? email : '-';

        const callBtn = document.getElementById('modalTenantCallBtn');
        if (callBtn) {
            if (phone && phone !== '-') {
                callBtn.href = 'tel:' + phone;
                callBtn.classList.remove('hidden');
            } else {
                callBtn.classList.add('hidden');
            }
        }

        let emText = '-';
        if (emergencyName || emergencyPhone) {
            emText = (emergencyName || '') + (emergencyPhone ? ' (' + emergencyPhone + ')' : '');
        }
        document.getElementById('modalEmergency').innerText = emText;

        document.getElementById('modalStartDate').innerText = start;
        document.getElementById('modalEndDate').innerText = end;
        document.getElementById('modalRent').innerText = rent;
        document.getElementById('modalDeposit').innerText = deposit;
        
        let coresLink = document.getElementById('modalCoresidentsLink');
        if (coresLink && roomId) {
            coresLink.href = 'admin_coresidents.php?roomId=' + roomId;
        }
        let repairLink = document.getElementById('modalRepairRequestsLink');
        if (repairLink && roomId) {
            repairLink.href = 'admin_requests.php?roomId=' + roomId;
        }

        let docWrap = document.getElementById('modalLeaseDocWrap');
        let docImgBox = document.getElementById('modalLeaseDocImgBox');
        if (leaseImage && leaseImage.trim() !== '') {
            if (docWrap) docWrap.classList.remove('hidden');
            if (docImgBox) {
                docImgBox.innerHTML = `
                    <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-emerald-200 shadow-sm" onclick="openDocViewer('${leaseImage}', 'สัญญาเช่า - ห้อง ${room}')">
                        <img src="${leaseImage}" class="w-full max-h-[160px] object-cover transition duration-300 group-hover:scale-105" alt="เอกสารสัญญา">
                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
                            <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                        </div>
                    </div>
                `;
            }
        } else {
            if (docWrap) docWrap.classList.add('hidden');
            if (docImgBox) docImgBox.innerHTML = '';
        }

        let coresList = document.getElementById('modalCoresidentsList');
        if (coresList) {
            coresList.innerHTML = '';
            if (coresidents && coresidents.length > 0) {
                coresidents.forEach(c => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center justify-between p-2 bg-stone-50 rounded-xl text-slate-700 text-xs border border-slate-200';
                    item.innerHTML = `<span><strong class="text-slate-900">${c.fullName}</strong> <span class="text-rose-600 text-[11px]">(${c.relationship})</span></span><span class="text-[11px] text-slate-400">📞 ${c.phone || '-'}</span>`;
                    coresList.appendChild(item);
                });
            } else {
                coresList.innerHTML = '<span class="text-slate-400 italic text-[11px] block py-1">ไม่มีผู้ร่วมอาศัยที่ลงทะเบียนในห้องนี้</span>';
            }
        }
        
        document.getElementById('tenantInfoModal').classList.remove('hidden');
        lucide.createIcons();
    }



    // ฟังก์ชันอัปเดตและล็อกราคาห้องพักตามประเภทห้อง (พัดลม 3,500 / แอร์ 4,000)

    function updateRoomPrice(prefix) {

        const typeSelect = document.getElementById(prefix + 'RoomType');

        const priceInput = document.getElementById(prefix + 'RentPrice');

        const noteEl = document.getElementById(prefix + 'PriceNote');

        if (!typeSelect || !priceInput) return;



        if (typeSelect.value === 'Fan') {

            priceInput.value = 3500;

            if (noteEl) {

                noteEl.className = 'text-emerald-700 font-semibold flex items-center gap-1';

                noteEl.innerHTML = '<i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> อัตรามาตรฐาน: ห้องพัดลม 3,500 บ./ด.';

            }

        } else {

            priceInput.value = 4000;

            if (noteEl) {

                noteEl.className = 'text-blue-700 font-semibold flex items-center gap-1';

                noteEl.innerHTML = '<i data-lucide="check-circle-2" class="w-4 h-4 text-blue-600"></i> อัตรามาตรฐาน: ห้องแอร์ 4,000 บ./ด.';

            }

        }

        if (window.lucide) lucide.createIcons();

    }



    // ฟังก์ชันตรวจจับเลขชั้นจากหมายเลขห้องอัตโนมัติ (เช่น 101 -> ชั้น 1, 205 -> ชั้น 2)

    function autoDetectFloor(input, floorId) {

        const val = input.value.trim();

        const floorInput = document.getElementById(floorId);

        if (!floorInput) return;

        if (val.length >= 3 && /^\d+$/.test(val)) {

            const floorNum = parseInt(val.charAt(0), 10);

            if (floorNum >= 1 && floorNum <= 15) {

                floorInput.value = floorNum;

            }

        }

    }



    // 8. ฟังก์ชันเปิด Modal แก้ไขข้อมูลห้องพัก

    function openEditRoomModal(id, number, floor, type, price) {

        document.getElementById('editRoomId').value = id;

        document.getElementById('editRoomNumber').value = number;

        document.getElementById('editFloor').value = floor;

        document.getElementById('editRoomType').value = type;

        updateRoomPrice('edit');

        document.getElementById('editRoomModal').classList.remove('hidden');

        lucide.createIcons();

    }



    // ==========================================

    // 🌟 ฟังก์ชันจัดการแท็บคำขอย้ายห้องพัก (Room Changes) 🌟

    // ==========================================

    let currentRCStatusFilter = 'all';



    function filterRoomChangesByStatus(status) {

        currentRCStatusFilter = status;

        const tabs = {

            'all': document.getElementById('btnFilterRCAll'),

            'Pending': document.getElementById('btnFilterRCPending'),

            'Approved': document.getElementById('btnFilterRCApproved'),

            'Rejected': document.getElementById('btnFilterRCRejected')

        };



        Object.keys(tabs).forEach(key => {

            const btn = tabs[key];

            if (btn) {

                if (key === status) {

                    btn.className = 'filter-tab-rc px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-xs font-bold border border-slate-200 border border-slate-200 font-bold';

                } else {

                    btn.className = 'filter-tab-rc px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 font-semibold';

                }

            }

        });

        applyRoomChangeFilters();

    }



    function applyRoomChangeFilters() {

        const input = document.getElementById('searchRoomChangesInput');

        const query = (input ? input.value : '').trim().toLowerCase();

        const rows = document.querySelectorAll('.rc-row');



        rows.forEach(row => {

            const searchData = (row.getAttribute('data-search') || '').toLowerCase();

            const status = row.getAttribute('data-status') || '';



            const matchQuery = query === '' || searchData.includes(query);

            const matchStatus = (currentRCStatusFilter === 'all' || status === currentRCStatusFilter);



            if (matchQuery && matchStatus) {

                row.style.display = '';

            } else {

                row.style.display = 'none';

            }

        });

    }



    function openApproveRoomChangeModal(reqId, tenantName, currRoom, targetType, targetRoomId, paymentSlip, leaseImage) {

        document.getElementById('approveRCReqId').value = reqId;

        document.getElementById('approveRCTenantName').innerText = tenantName;

        document.getElementById('approveRCCurrRoom').innerText = currRoom;



        const slipAction = document.getElementById('approveRCSlipAction');

        if (slipAction) {

            if (paymentSlip && paymentSlip.trim() !== '') {

                slipAction.innerHTML = `

                    <button type="button" onclick="openDocModal('${paymentSlip}')" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1.5 cursor-pointer">

                        <i data-lucide="image" class="w-3.5 h-3.5"></i> ตรวจสอบสลิป 500฿ 📄

                    </button>

                `;

            } else {

                slipAction.innerHTML = `<span class="px-2.5 py-1 bg-slate-200 text-slate-600 rounded-lg text-xs font-semibold">ไม่มีสลิปแนบ</span>`;

            }

        }



        const leaseDocAction = document.getElementById('approveRCLeaseDocAction');

        if (leaseDocAction) {

            if (leaseImage && leaseImage.trim() !== '') {

                leaseDocAction.innerHTML = `

                    <span class="font-bold flex items-center gap-1"><i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-600"></i> สัญญาเช่าเดิม:</span>

                    <button type="button" onclick="openDocModal('${leaseImage}')" class="px-3 py-1 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-1 cursor-pointer">

                        <i data-lucide="file-text" class="w-3.5 h-3.5"></i> ดูเอกสารสัญญาเดิม 📄

                    </button>

                `;

            } else {

                leaseDocAction.innerHTML = `

                    <span class="font-bold flex items-center gap-1"><i data-lucide="file-text" class="w-3.5 h-3.5 text-slate-400"></i> สัญญาเช่าเดิม:</span>

                    <span class="text-slate-400 font-normal">ไม่มีไฟล์แนบ</span>

                `;

            }

        }



        const select = document.getElementById('approveRCNewRoomId');

        if (select) {

            if (targetRoomId && targetRoomId !== '') {

                select.value = targetRoomId;

            } else if (targetType && targetType !== '') {

                for (let i = 0; i < select.options.length; i++) {

                    if (select.options[i].getAttribute('data-type') === targetType) {

                        select.selectedIndex = i;

                        break;

                    }

                }

            }

        }



        document.getElementById('approveRoomChangeModal').classList.remove('hidden');

        lucide.createIcons();

    }



    function closeApproveRoomChangeModal() {

        const modal = document.getElementById('approveRoomChangeModal');

        if (modal) modal.classList.add('hidden');

    }



    function openRejectRoomChangeModal(reqId, tenantName, currRoom) {

        document.getElementById('rejectRCId').value = reqId;

        document.getElementById('rejectRCName').innerText = tenantName;

        document.getElementById('rejectRCRoom').innerText = currRoom;

        document.getElementById('rejectRoomChangeModal').classList.remove('hidden');

        lucide.createIcons();

    }



    function closeRejectRoomChangeModal() {

        const modal = document.getElementById('rejectRoomChangeModal');

        if (modal) modal.classList.add('hidden');

    }



    // ==========================================

    // 🌟 ฟังก์ชันจัดการแท็บแจ้งย้ายออก & เคลียร์ห้อง (Move Outs) 🌟

    // ==========================================

    let currentMOStatusFilter = 'all';



    function filterMoveOutsByStatus(status) {

        currentMOStatusFilter = status;

        const tabs = {

            'all': document.getElementById('btnFilterMOAll'),

            'Pending': document.getElementById('btnFilterMOPending'),

            'Settled': document.getElementById('btnFilterMOSettled')

        };



        Object.keys(tabs).forEach(key => {

            const btn = tabs[key];

            if (btn) {

                if (key === status) {

                    btn.className = 'filter-tab-mo px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-xs font-bold border border-slate-200 border border-slate-200 font-bold';

                } else {

                    btn.className = 'filter-tab-mo px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-900 font-semibold';

                }

            }

        });

        applyMoveOutFilters();

    }



    function applyMoveOutFilters() {

        const input = document.getElementById('searchMoveOutsInput');

        const query = (input ? input.value : '').trim().toLowerCase();

        const rows = document.querySelectorAll('.mo-row');



        rows.forEach(row => {

            const searchData = (row.getAttribute('data-search') || '').toLowerCase();

            const status = row.getAttribute('data-status') || '';



            const matchQuery = query === '' || searchData.includes(query);

            const matchStatus = (currentMOStatusFilter === 'all' || status === currentMOStatusFilter);



            if (matchQuery && matchStatus) {

                row.style.display = '';

            } else {

                row.style.display = 'none';

            }

        });

    }



    // Helper ให้เรียก openDocModal แทน openDocViewer ได้เสมอ

    function openDocViewer(filePath, title) {

        if (typeof openDocModal === 'function') {

            openDocModal(filePath, title);

        } else {

            window.open(filePath, '_blank');

        }

    }

    // 🌟 ฟังก์ชันเปิด Modal ดูรายละเอียดคำขอแจ้งย้ายออก (แบบ 2 กรอบ) 🌟
    function openMoveOutModal(data) {
        if (!data) return;

        function fmtDate(d) {
            if (!d) return '-';
            const clean = d.split(' ')[0];
            const parts = clean.split('-');
            if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
            return d;
        }

        // Header Badges
        document.getElementById('mod_room_badge').innerText = `ห้อง ${data.roomNumber} (${data.roomType === 'Air' ? '❄️ แอร์' : '🌀 พัดลม'} ชั้น ${data.floor})`;
        document.getElementById('mod_code_badge').innerText = `MOVE-${String(data.leaseId).padStart(4, '0')}`;

        const isPending = (data.status === 'Active');
        const statusBadge = document.getElementById('mod_status_badge');
        if (isPending) {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 animate-pulse';
            statusBadge.innerHTML = '<i data-lucide="alert-circle" class="w-3.5 h-3.5 inline mr-1 text-rose-500"></i> รอเคลียร์ห้อง';
        } else {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200';
            statusBadge.innerHTML = '<i data-lucide="check-circle" class="w-3.5 h-3.5 inline mr-1 text-emerald-500"></i> เคลียร์เรียบร้อย';
        }

        // 🟦 กรอบที่ 1: ข้อมูลผู้เช่า
        document.getElementById('mod_tenant_name').innerText = data.tenantName || '-';
        document.getElementById('mod_tenant_phone').innerText = data.tenantPhone || 'ไม่ระบุเบอร์โทร';
        const callBtn = document.getElementById('mod_tenant_call_btn');
        if (data.tenantPhone && data.tenantPhone !== '-') {
            callBtn.href = `tel:${data.tenantPhone}`;
            callBtn.classList.remove('hidden');
        } else {
            callBtn.classList.add('hidden');
        }

        document.getElementById('mod_moveout_date').innerText = fmtDate(data.moveOutRequestDate);
        document.getElementById('mod_moveout_reason').innerText = data.moveOutReason || 'ครบกำหนดสัญญาเช่า / ไม่ระบุเหตุผลเพิ่มเติม';
        document.getElementById('mod_lease_period').innerText = `${fmtDate(data.startDate)} - ${fmtDate(data.endDate)}`;

        document.getElementById('mod_bank_name').innerText = data.bankName || 'ไม่ระบุ';
        document.getElementById('mod_bank_account').innerText = data.bankAccount || '-';
        document.getElementById('mod_account_name').innerText = data.accountName || '-';

        // Tenant Slip
        const tenantSlipWrap = document.getElementById('mod_tenant_slip_wrap');
        const tenantSlipImgBox = document.getElementById('mod_tenant_slip_img_box');
        if (data.tenantPaymentSlip && String(data.tenantPaymentSlip).trim() !== '') {
            tenantSlipWrap.classList.remove('hidden');
            tenantSlipImgBox.innerHTML = `
                <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-emerald-200 shadow-sm" onclick="openDocViewer('${data.tenantPaymentSlip}', 'สลิปชำระเงินส่วนต่าง - ห้อง ${data.roomNumber}')">
                    <img src="${data.tenantPaymentSlip}" class="w-full max-h-[180px] object-cover transition duration-300 group-hover:scale-105" alt="สลิปจากลูกบ้าน">
                    <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
                        <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                    </div>
                </div>
            `;
        } else {
            tenantSlipWrap.classList.add('hidden');
            tenantSlipImgBox.innerHTML = '';
        }

        // 🟩 กรอบที่ 2: ส่วนการตรวจสอบ & สรุปเงินประกัน
        const statusBox = document.getElementById('mod_status_box');
        if (isPending) {
            statusBox.className = 'p-4 rounded-2xl border border-amber-200 bg-amber-50/70 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4';
            statusBox.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center shrink-0 shadow-xs">
                        <i data-lucide="clock" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <span class="font-bold text-amber-950 text-sm block">รอนิติบุคคลตรวจเช็คสภาพห้อง & มิเตอร์</span>
                        <p class="text-[11px] text-amber-800">ส่งคำขอย้ายออกแล้ว นิติบุคคลต้องลงบันทึกมิเตอร์และความเสียหาย</p>
                    </div>
                </div>
                <a href="move_out_form.php?roomId=${data.roomId}&leaseId=${data.leaseId}" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-xs">
                    <i data-lucide="calculator" class="w-3.5 h-3.5"></i> สรุปเงิน & เคลียร์ห้อง
                </a>
            `;
        } else {
            statusBox.className = 'p-4 rounded-2xl border border-emerald-200 bg-emerald-50/70 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4';
            statusBox.innerHTML = `
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                        <i data-lucide="check-circle-2" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <span class="font-bold text-emerald-950 text-sm block">เคลียร์ห้องและสรุปยอดเงินเรียบร้อยแล้ว</span>
                        <p class="text-[11px] text-emerald-800">ตรวจสอบและปิดสัญญาเช่าสมบูรณ์ สถานะห้องปรับเป็นห้องว่างแล้ว</p>
                    </div>
                </div>
                <span class="text-xs font-bold text-emerald-800 bg-white px-3 py-1 rounded-xl border border-emerald-200 shadow-2xs font-mono">
                    ${data.settledAt ? fmtDate(data.settledAt) : 'เรียบร้อย'}
                </span>
            `;
        }

        // Timeline 3 steps
        document.getElementById('mod_time_requested').innerText = fmtDate(data.moveOutRequestDate);
        document.getElementById('mod_time_moveout').innerText = fmtDate(data.moveOutRequestDate);
        document.getElementById('mod_time_settled').innerText = data.settledAt ? fmtDate(data.settledAt) : (isPending ? 'รอตรวจห้อง' : 'เสร็จสิ้น');

        // Deposit & Deductions
        const depVal = parseFloat(data.depositAmount || 0);
        document.getElementById('mod_deposit_amount').innerText = `฿${depVal.toLocaleString(undefined, {minimumFractionDigits: 2})}`;

        const dedBox = document.getElementById('mod_deductions_detail');
        dedBox.innerHTML = '';
        const items = [];
        if (parseFloat(data.finalElectricFee || 0) > 0) items.push({ label: 'ค่าไฟฟ้าคงค้าง', val: parseFloat(data.finalElectricFee) });
        if (parseFloat(data.finalWaterFee || 0) > 0) items.push({ label: 'ค่าน้ำประปาคงค้าง', val: parseFloat(data.finalWaterFee) });
        if (parseFloat(data.cleaningFee || 0) > 0) items.push({ label: 'ค่าทำความสะอาด / แอร์', val: parseFloat(data.cleaningFee) });
        if (parseFloat(data.damageFee || 0) > 0) items.push({ label: `ค่าความเสียหาย${data.damageDetail ? ` (${data.damageDetail})` : ''}`, val: parseFloat(data.damageFee) });
        if (parseFloat(data.breachFee || 0) > 0) items.push({ label: 'ค่าปรับไม่ครบสัญญา / ริบมัดจำ', val: parseFloat(data.breachFee) });

        if (items.length > 0) {
            items.forEach(it => {
                const row = document.createElement('div');
                row.className = 'flex justify-between items-center text-rose-600 text-[11px]';
                row.innerHTML = `<span>- ${it.label}:</span><span class="font-mono font-semibold">-฿${it.val.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>`;
                dedBox.appendChild(row);
            });
        } else if (isPending) {
            dedBox.innerHTML = '<div class="text-[11px] text-amber-700 italic">รอลงบันทึกการหักค่าใช้จ่ายในหน้าสรุปเงิน</div>';
        } else {
            dedBox.innerHTML = '<div class="text-[11px] text-slate-400 italic">ไม่มีรายการหักค่าใช้จ่ายเพิ่มเติม</div>';
        }

        // Net Amount
        const netLabel = document.getElementById('mod_net_label');
        const netAmount = document.getElementById('mod_net_amount');
        if (isPending) {
            netLabel.innerText = 'สถานะเงินประกัน:';
            netLabel.className = 'font-bold text-slate-700';
            netAmount.innerText = 'รอตรวจเช็คสภาพห้อง & มิเตอร์';
            netAmount.className = 'text-xs font-bold text-amber-600';
        } else {
            const netVal = parseFloat(data.netRefundAmount || 0);
            if (netVal < 0) {
                netLabel.innerText = 'ยอดผู้เช่าชำระเงินส่วนต่างเพิ่ม:';
                netLabel.className = 'font-bold text-rose-800';
                netAmount.innerText = `฿${Math.abs(netVal).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
                netAmount.className = 'font-mono font-bold text-base sm:text-lg text-rose-600';
            } else {
                netLabel.innerText = 'ยอดโอนเงินคืนสุทธิให้ผู้เช่า:';
                netLabel.className = 'font-bold text-emerald-800';
                netAmount.innerText = `฿${netVal.toLocaleString(undefined, {minimumFractionDigits: 2})}`;
                netAmount.className = 'font-mono font-bold text-base sm:text-lg text-emerald-600';
            }
        }

        // Refund Slip
        const refundSlipWrap = document.getElementById('mod_refund_slip_wrap');
        const refundSlipImgBox = document.getElementById('mod_refund_slip_img_box');
        if (data.refundReceipt && String(data.refundReceipt).trim() !== '') {
            refundSlipWrap.classList.remove('hidden');
            refundSlipImgBox.innerHTML = `
                <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-rose-200 shadow-sm" onclick="openDocViewer('${data.refundReceipt}', 'สลิปโอนคืนเงินประกัน - ห้อง ${data.roomNumber}')">
                    <img src="${data.refundReceipt}" class="w-full max-h-[180px] object-cover transition duration-300 group-hover:scale-105" alt="สลิปโอนคืนเงินประกัน">
                    <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
                        <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                    </div>
                </div>
            `;
        } else {
            refundSlipWrap.classList.add('hidden');
            refundSlipImgBox.innerHTML = '';
        }

        // Action button
        const actionBtn = document.getElementById('mod_action_btn');
        const actionBtnText = document.getElementById('mod_action_btn_text');
        actionBtn.href = `move_out_form.php?roomId=${data.roomId}&leaseId=${data.leaseId}`;
        if (isPending) {
            actionBtnText.innerText = 'สรุปเงิน & เคลียร์ห้อง';
            actionBtn.className = 'px-6 py-2.5 bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white font-bold rounded-2xl text-xs sm:text-sm shadow-md transition flex items-center gap-2 cursor-pointer';
        } else {
            actionBtnText.innerText = 'ดูใบสรุปย้ายออกเต็ม';
            actionBtn.className = 'px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-2xl text-xs sm:text-sm shadow-md transition flex items-center gap-2 cursor-pointer';
        }

        // Open modal
        const modal = document.getElementById('moveOutDetailModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (window.lucide) lucide.createIcons();
    }

    function closeMoveOutModal() {
        const modal = document.getElementById('moveOutDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    // 🌟 ฟังก์ชันเปิด Modal ดูรายละเอียดคำขอต่อสัญญาเช่า (แบบ 2 กรอบ) 🌟
    function openRenewalDetailModal(data) {
        if (!data) return;

        function fmtDate(d) {
            if (!d) return '-';
            const clean = d.split(' ')[0];
            const parts = clean.split('-');
            if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
            return d;
        }

        // Header Badges
        document.getElementById('ren_modal_room_badge').innerText = `ห้อง ${data.roomNumber || '-'}`;
        document.getElementById('ren_modal_id_badge').innerText = `#REN-${String(data.renewalId).padStart(4, '0')}`;

        const statusBadge = document.getElementById('ren_modal_status_badge');
        const isPending = (data.status === 'Pending');
        const isApproved = (data.status === 'Approved');

        if (isPending) {
            statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 animate-pulse';
            statusBadge.innerHTML = 'รออนุมัติต่อสัญญา';
        } else if (isApproved) {
            statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200';
            statusBadge.innerHTML = 'อนุมัติเรียบร้อยแล้ว';
        } else {
            statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200';
            statusBadge.innerHTML = 'ปฏิเสธการต่อสัญญา';
        }

        // 🟦 กรอบที่ 1: ข้อมูลผู้เช่า & สัญญาเดิม
        const tenantName = data.fullName || '-';
        document.getElementById('ren_modal_tenant_name').innerText = tenantName;
        document.getElementById('ren_modal_avatar').innerText = tenantName.charAt(0);
        document.getElementById('ren_modal_tenant_phone').innerText = `📞 ${data.tenantPhone || '-'}`;

        const callBtn = document.getElementById('ren_modal_call_btn');
        if (data.tenantPhone && data.tenantPhone !== '-') {
            callBtn.href = `tel:${data.tenantPhone}`;
            callBtn.classList.remove('hidden');
        } else {
            callBtn.classList.add('hidden');
        }

        document.getElementById('ren_modal_room_info').innerText = `ห้อง ${data.roomNumber || '-'}`;
        document.getElementById('ren_modal_rent_price').innerText = `฿${parseFloat(data.rentPrice || 0).toLocaleString()} / ด.`;
        document.getElementById('ren_modal_deposit').innerText = `฿${parseFloat(data.depositAmount || 0).toLocaleString()}`;
        document.getElementById('ren_modal_start_date').innerText = fmtDate(data.startDate);
        document.getElementById('ren_modal_current_end_date').innerText = fmtDate(data.currentEndDate);

        // เอกสารสัญญาเช่า
        const leaseBox = document.getElementById('ren_modal_lease_doc_box');
        if (data.leaseImage && String(data.leaseImage).trim() !== '') {
            leaseBox.innerHTML = `
                <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-indigo-200 shadow-sm" onclick="openDocViewer('${data.leaseImage}', 'สัญญาเช่า - ห้อง ${data.roomNumber}')">
                    <img src="${data.leaseImage}" class="w-full max-h-[160px] object-cover transition duration-300 group-hover:scale-105" alt="เอกสารสัญญา">
                    <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
                        <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                    </div>
                </div>
            `;
        } else {
            leaseBox.innerHTML = '<span class="text-xs text-slate-400 italic py-4">ไม่มีเอกสารสัญญาแนบในระบบ</span>';
        }

        // 🟩 กรอบที่ 2: ส่วนคำขอต่อสัญญา & นิติบุคคล
        document.getElementById('ren_modal_ext_months').innerText = `+${data.extensionMonths} เดือน`;

        // คำนวณวันสิ้นสุดสัญญาใหม่
        let newEndFormatted = '-';
        if (data.currentEndDate) {
            const d = new Date(data.currentEndDate);
            d.setMonth(d.getMonth() + parseInt(data.extensionMonths || 0));
            newEndFormatted = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
        }
        document.getElementById('ren_modal_new_end_date').innerText = newEndFormatted;
        document.getElementById('ren_modal_created_at').innerText = fmtDate(data.created_at);

        // หมายเหตุจากนิติบุคคล
        const remarkWrap = document.getElementById('ren_modal_remark_wrap');
        const remarkText = document.getElementById('ren_modal_remark_text');
        if (data.adminRemark && String(data.adminRemark).trim() !== '') {
            remarkWrap.classList.remove('hidden');
            remarkText.innerText = data.adminRemark;
        } else {
            remarkWrap.classList.add('hidden');
            remarkText.innerText = '-';
        }

        // Actions
        const footerRight = document.getElementById('ren_modal_footer_right');
        footerRight.innerHTML = '';
        if (isPending) {
            footerRight.innerHTML = `
                <button type="button" onclick="closeRenewalDetailModal(); openRejectModal('${data.renewalId}', '${data.fullName}', '${data.roomNumber}')" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-2xl text-xs font-bold transition flex items-center gap-1 cursor-pointer">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธ
                </button>
                <button type="button" onclick="closeRenewalDetailModal(); openApproveModal('${data.renewalId}', '${data.leaseId}', '${data.extensionMonths}', '${data.fullName}', '${data.roomNumber}', '${newEndFormatted}')" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-xs font-bold transition flex items-center gap-1.5 shadow-md cursor-pointer">
                    <i data-lucide="check" class="w-4 h-4"></i> อนุมัติต่อสัญญา
                </button>
            `;
        }

        const modal = document.getElementById('renewalDetailModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (window.lucide) lucide.createIcons();
    }

    function closeRenewalDetailModal() {
        const modal = document.getElementById('renewalDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    // 🌟 ฟังก์ชันเปิด Modal ดูรายละเอียดคำขอย้ายห้องพัก (แบบ 2 กรอบ) 🌟
    function openRoomChangeDetailModal(data) {
        if (!data) return;

        function fmtDate(d) {
            if (!d) return '-';
            const clean = d.split(' ')[0];
            const parts = clean.split('-');
            if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
            return d;
        }

        // Header Badges
        document.getElementById('rc_modal_room_badge').innerText = `ห้อง ${data.currRoomNumber || '-'}`;
        document.getElementById('rc_modal_id_badge').innerText = `#CHG-${String(data.requestId).padStart(4, '0')}`;

        const statusBadge = document.getElementById('rc_modal_status_badge');
        const isPending = (data.status === 'Pending');
        const isApproved = (data.status === 'Approved');

        if (isPending) {
            statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 animate-pulse';
            statusBadge.innerHTML = 'รออนุมัติจัดสรรห้อง';
        } else if (isApproved) {
            statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200';
            statusBadge.innerHTML = 'อนุมัติย้ายห้องแล้ว';
        } else {
            statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200';
            statusBadge.innerHTML = 'ปฏิเสธคำขอย้าย';
        }

        // 🟦 กรอบที่ 1: ข้อมูลผู้เช่า & ห้องปัจจุบัน
        const tenantName = data.fullName || '-';
        document.getElementById('rc_modal_tenant_name').innerText = tenantName;
        document.getElementById('rc_modal_avatar').innerText = tenantName.charAt(0);
        document.getElementById('rc_modal_tenant_phone').innerText = `📞 ${data.tenantPhone || '-'}`;

        const callBtn = document.getElementById('rc_modal_call_btn');
        if (data.tenantPhone && data.tenantPhone !== '-') {
            callBtn.href = `tel:${data.tenantPhone}`;
            callBtn.classList.remove('hidden');
        } else {
            callBtn.classList.add('hidden');
        }

        document.getElementById('rc_modal_curr_room').innerText = `ห้อง ${data.currRoomNumber || '-'}`;
        document.getElementById('rc_modal_curr_type').innerText = `${data.currRoomType === 'Air' ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'} (ชั้น ${data.currFloor || '-'})`;
        document.getElementById('rc_modal_curr_rent').innerText = `฿${parseFloat(data.currRentPrice || 0).toLocaleString(undefined, {minimumFractionDigits: 0})}`;
        
        let leaseDatesText = '-';
        if (data.leaseStartDate && data.leaseEndDate) {
            leaseDatesText = `${fmtDate(data.leaseStartDate)} - ${fmtDate(data.leaseEndDate)}`;
        }
        document.getElementById('rc_modal_curr_lease_dates').innerText = leaseDatesText;

        // สลิปค่าทำความสะอาด
        const slipBox = document.getElementById('rc_modal_slip_box');
        const slipBadge = document.getElementById('rc_modal_slip_badge');
        if (data.paymentSlip && String(data.paymentSlip).trim() !== '') {
            slipBadge.innerText = 'SLIP 500฿';
            slipBadge.className = 'text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2 py-0.5 rounded-lg border border-emerald-200';
            slipBox.innerHTML = `
                <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-emerald-200 shadow-sm" onclick="openDocViewer('${data.paymentSlip}', 'สลิปค่าทำความสะอาด - ห้อง ${data.currRoomNumber}')">
                    <img src="${data.paymentSlip}" class="w-full max-h-[160px] object-cover transition duration-300 group-hover:scale-105" alt="สลิปค่าทำความสะอาด">
                    <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
                        <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                    </div>
                </div>
            `;
        } else {
            slipBadge.innerText = 'ไม่มีสลิป';
            slipBadge.className = 'text-[10px] bg-slate-100 text-slate-500 font-bold px-2 py-0.5 rounded-lg border border-slate-200';
            slipBox.innerHTML = '<span class="text-xs text-slate-400 italic py-4">ไม่มีการแนบสลิปค่าทำความสะอาด</span>';
        }

        // 🟩 กรอบที่ 2: ส่วนคำขอย้าย & นิติบุคคล
        let targetTypeDesc = '-';
        if (data.targetRoomType === 'Air') targetTypeDesc = '❄️ ขอเป็นห้องแอร์';
        else if (data.targetRoomType === 'Fan') targetTypeDesc = '🌀 ขอเป็นห้องพัดลม';
        else if (data.targetRoomType === 'Any') targetTypeDesc = '✨ ห้องใดก็ได้ (แอร์หรือพัดลม)';
        else targetTypeDesc = data.targetRoomType || 'ไม่ระบุ';
        document.getElementById('rc_modal_target_type').innerText = targetTypeDesc;

        document.getElementById('rc_modal_target_date').innerText = fmtDate(data.preferredMoveDate);
        document.getElementById('rc_modal_reason').innerText = data.reason || 'ไม่ได้ระบุเหตุผลเพิ่มเติม';

        // ผลการพิจารณา & ห้องที่จัดสรร
        const allocBox = document.getElementById('rc_modal_allocation_box');
        allocBox.innerHTML = '';
        if (isApproved) {
            allocBox.innerHTML = `
                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-200 text-emerald-900">
                    <div class="font-bold flex items-center gap-1.5 mb-1 text-xs">
                        <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i> ย้ายเข้าห้องพักใหม่สำเร็จ
                    </div>
                    <div class="text-xs space-y-1">
                        <div>ห้องเป้าหมายที่จัดสรร: <strong class="text-slate-900 font-mono text-sm">ห้อง ${data.targetRoomNumber || '-'}</strong> (${data.targetRoomTypeSpecific === 'Air' ? '❄️ แอร์' : '🌀 พัดลม'})</div>
                        ${data.targetRentPrice ? `<div>ค่าเช่าห้องใหม่: <strong class="text-slate-900 font-mono">฿${parseFloat(data.targetRentPrice).toLocaleString()}</strong> / เดือน</div>` : ''}
                        ${data.adminRemark ? `<div class="mt-2 pt-2 border-t border-emerald-200 text-slate-700">💬 หมายเหตุ: ${data.adminRemark}</div>` : ''}
                    </div>
                </div>
            `;
        } else if (data.status === 'Rejected') {
            allocBox.innerHTML = `
                <div class="p-3 bg-rose-50 rounded-xl border border-rose-200 text-rose-900">
                    <div class="font-bold flex items-center gap-1.5 mb-1 text-xs">
                        <i data-lucide="x-circle" class="w-4 h-4 text-rose-600"></i> คำขอย้ายห้องถูกปฏิเสธ
                    </div>
                    <div class="text-xs text-rose-800">
                        ${data.adminRemark ? `💬 เหตุผล: ${data.adminRemark}` : 'ไม่มีการระบุเหตุผล'}
                    </div>
                </div>
            `;
        } else {
            allocBox.innerHTML = `
                <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-amber-900 text-xs space-y-1">
                    <div class="font-bold flex items-center gap-1.5">
                        <i data-lucide="clock" class="w-4 h-4 text-amber-600"></i> รอเจ้าหน้าที่ตรวจคำขอและจัดสรรห้อง
                    </div>
                    <p class="text-[11px] text-amber-800">เจ้าหน้าที่ต้องตรวจสอบสลิปค่าทำความสะอาด และเลือกห้องว่างที่พร้อมให้ย้าย</p>
                </div>
            `;
        }

        // Actions
        const footerRight = document.getElementById('rc_modal_footer_right');
        footerRight.innerHTML = '';

        if (isPending) {
            footerRight.innerHTML = `
                <button type="button" onclick="closeRoomChangeDetailModal(); openRejectRoomChangeModal('${data.requestId}', '${data.fullName}', '${data.currRoomNumber}')" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-2xl text-xs font-bold transition flex items-center gap-1 cursor-pointer">
                    <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธ
                </button>
                <button type="button" onclick="closeRoomChangeDetailModal(); openApproveRoomChangeModal('${data.requestId}', '${data.fullName}', '${data.currRoomNumber}', '${data.targetRoomType || ''}', '${data.targetRoomId || ''}', '${data.paymentSlip || ''}', '${data.leaseImage || ''}')" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl text-xs font-bold transition flex items-center gap-1.5 shadow-md cursor-pointer">
                    <i data-lucide="clipboard-check" class="w-4 h-4"></i> ตรวจสอบ & จัดสรรห้อง
                </button>
            `;
        }

        const modal = document.getElementById('roomChangeDetailModal');
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (window.lucide) lucide.createIcons();
    }

    function closeRoomChangeDetailModal() {
        const modal = document.getElementById('roomChangeDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    }



    // 9. เริ่มต้นหน้าตามแท็บหรือฟิลเตอร์ที่ส่งมา

    document.addEventListener('DOMContentLoaded', function() {

        if (activeMainTab && mainTabsConfig[activeMainTab]) {

            switchMainTab(activeMainTab);

        } else {

            switchMainTab('rooms');

            if ('<?php echo $initialFilter; ?>' !== 'all') {

                filterByStatus('<?php echo $initialFilter; ?>');

            }

        }

    });



    // ปิด Modal เมื่อกด Escape

    document.addEventListener('keydown', function(e) {

        if (e.key === 'Escape') {

            closeApproveModal();

            closeRejectModal();

            closeLeaseDoc();

            closeApproveRoomChangeModal();

            closeRejectRoomChangeModal();

            closeMoveOutModal();
            closeRenewalDetailModal();
            closeRoomChangeDetailModal();

            if (typeof closeDocModal === 'function') closeDocModal();

            const addM = document.getElementById('addRoomModal');

            if (addM) addM.classList.add('hidden');

            const editM = document.getElementById('editRoomModal');

            if (editM) editM.classList.add('hidden');

            const tM = document.getElementById('tenantInfoModal');

            if (tM) tM.classList.add('hidden');

        }

    });

  
    function selectRoomType(prefix, type) {
        const input = document.getElementById(prefix + 'RoomType');
        if (input) input.value = type;
        const btnAir = document.getElementById('btnAir_' + prefix);
        const btnFan = document.getElementById('btnFan_' + prefix);
        const checkAir = document.getElementById('checkAir_' + prefix);
        const checkFan = document.getElementById('checkFan_' + prefix);

        if (type === 'Air') {
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkAir) checkAir.classList.remove('hidden');
            if (checkFan) checkFan.classList.add('hidden');
        } else {
            if (btnFan) btnFan.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-sky-500 bg-sky-50/70 text-sky-950 font-bold shadow-xs';
            if (btnAir) btnAir.className = 'p-3 rounded-2xl border-2 flex items-center justify-between transition cursor-pointer border-stone-200 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkFan) checkFan.classList.remove('hidden');
            if (checkAir) checkAir.classList.add('hidden');
        }
        updateRoomPrice(prefix);
    }


    function toggleFullscreenModal(btn) {
        const modal = btn.closest('[id$="Modal"]');
        if (!modal) return;
        const card = modal.querySelector(':scope > div');
        if (!card) return;
        const isFull = card.classList.contains('is-fullscreen-100');
        if (isFull) {
            card.classList.remove('is-fullscreen-100', '!w-full', '!h-full', '!max-w-none', '!rounded-none');
            modal.classList.remove('!p-0');
            btn.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';
            btn.title = 'ขยายเต็มหน้าจอ 100%';
        } else {
            card.classList.add('is-fullscreen-100', '!w-full', '!h-full', '!max-w-none', '!rounded-none');
            modal.classList.add('!p-0');
            btn.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';
            btn.title = 'คืนขนาดเดิม';
        }
        if (window.lucide) lucide.createIcons();
    }

</script>

</body>

</html>

