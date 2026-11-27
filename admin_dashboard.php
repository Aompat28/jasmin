<?php

session_start();

require_once 'includes/db_connect.php';
require_once 'includes/time_helper.php';



// ตรวจสอบสิทธิ์ ต้องเป็น Admin เท่านั้น

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {

    header("Location: login.php");

    exit();

}



$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';

$initials = strtoupper(mb_substr($adminName, 0, 2, 'UTF-8'));



// 🌟 สร้างตาราง Announcements อัตโนมัติ (ถ้ายังไม่มี)

try {

    $conn->exec("ALTER TABLE Announcements ADD COLUMN message TEXT NULL");

} catch(Throwable $e) {}

try {

    $conn->exec("ALTER TABLE Announcements ADD COLUMN content TEXT NULL");

} catch(Throwable $e) {}

try {

    $conn->exec("ALTER TABLE Announcements ADD COLUMN type VARCHAR(50) DEFAULT 'info'");

} catch(Throwable $e) {}

try {

    $conn->exec("CREATE TABLE IF NOT EXISTS Announcements (

        announcementId INT AUTO_INCREMENT PRIMARY KEY,

        title VARCHAR(255) NOT NULL,

        content TEXT NOT NULL,

        message TEXT NULL,

        type VARCHAR(50) DEFAULT 'info',

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

} catch(Throwable $e) {}



// 🌟 สร้างตาราง admin_read_notifications อัตโนมัติ (บันทึกสถานะการอ่านแจ้งเตือน)

try {

    $conn->exec("CREATE TABLE IF NOT EXISTS admin_read_notifications (

        id INT AUTO_INCREMENT PRIMARY KEY,

        admin_id INT NOT NULL,

        notif_id VARCHAR(100) NOT NULL,

        read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        UNIQUE KEY uq_admin_notif (admin_id, notif_id)

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

} catch(Throwable $e) {}



// 🌟 AJAX Handler: ทำเครื่องหมายแจ้งเตือนว่าอ่านแล้ว

if (isset($_REQUEST['action'])) {

    $action = $_REQUEST['action'];

    $adminId = (int)($_SESSION['user_id'] ?? 0);



    if ($action === 'mark_notif_read') {

        header('Content-Type: application/json; charset=utf-8');

        $notifId = trim($_REQUEST['notif_id'] ?? '');

        if (!empty($notifId) && $adminId > 0) {

            try {

                $stmt = $conn->prepare("INSERT IGNORE INTO admin_read_notifications (admin_id, notif_id) VALUES (?, ?)");

                $stmt->execute([$adminId, $notifId]);

                echo json_encode(['success' => true]);

            } catch(Throwable $e) {

                echo json_encode(['success' => false, 'error' => $e->getMessage()]);

            }

        } else {

            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);

        }

        exit();

    }



    if ($action === 'mark_all_notifs_read') {

        header('Content-Type: application/json; charset=utf-8');

        $notifIds = $_POST['notif_ids'] ?? [];

        if (is_string($notifIds)) {

            $notifIds = json_decode($notifIds, true) ?: [];

        }

        if (!empty($notifIds) && $adminId > 0) {

            try {

                $stmt = $conn->prepare("INSERT IGNORE INTO admin_read_notifications (admin_id, notif_id) VALUES (?, ?)");

                foreach ($notifIds as $nid) {

                    $nid = trim($nid);

                    if (!empty($nid)) {

                        $stmt->execute([$adminId, $nid]);

                    }

                }

                echo json_encode(['success' => true]);

            } catch(Throwable $e) {

                echo json_encode(['success' => false, 'error' => $e->getMessage()]);

            }

        } else {

            echo json_encode(['success' => true]);

        }

        exit();

    }

}



// --- จัดการระบบประกาศข่าวสาร ---

$alertMessage = "";

if (isset($_POST['add_announcement']) || isset($_POST['create_announcement']) || (isset($_POST['action']) && in_array($_POST['action'], ['create_announcement', 'add_announcement']))) {

    $title = trim($_POST['title']);

    $msg = trim($_POST['message'] ?? ($_POST['content'] ?? ''));

    $type = $_POST['type'] ?? 'info'; 

    if (!empty($title) && !empty($msg)) {

        try {

            $stmt = $conn->prepare("INSERT INTO Announcements (title, content, message, type) VALUES (?, ?, ?, ?)");

            $stmt->execute([$title, $msg, $msg, $type]);

            $alertMessage = "<div class='alert-box bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 p-4 mb-6 rounded-2xl shadow-xs flex items-center justify-between transition-all duration-300'>

                <div class='flex items-center gap-2'>

                    <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>

                    <span class='font-bold text-sm'>สร้างประกาศข่าวสารเรียบร้อยแล้ว! ข้อมูลส่งถึงลูกบ้านทุกคนทันที</span>

                </div>

                <button type='button' onclick='this.closest(\".alert-box\").remove()' class='text-emerald-600 hover:text-emerald-800 p-1 cursor-pointer'><i data-lucide='x' class='w-4 h-4'></i></button>

            </div>";

        } catch(Throwable $e) {

            $alertMessage = "<div class='alert-box bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>

                <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>

                <span>เกิดข้อผิดพลาดในการสร้างประกาศ: " . htmlspecialchars($e->getMessage()) . "</span>

            </div>";

        }

    }

}



if (isset($_POST['edit_announcement']) || (isset($_POST['action']) && in_array($_POST['action'], ['edit_announcement', 'update_announcement']))) {

    $id = intval($_POST['id'] ?? ($_POST['announcement_id'] ?? 0));

    $title = trim($_POST['title']);

    $msg = trim($_POST['message'] ?? ($_POST['content'] ?? ''));

    $type = $_POST['type'] ?? 'info';

    

    try {

        $stmt = $conn->prepare("UPDATE Announcements SET title = ?, content = ?, message = ?, type = ? WHERE announcementId = ?");

        $stmt->execute([$title, $msg, $msg, $type, $id]);

        $alertMessage = "<div class='alert-box bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 p-4 mb-6 rounded-2xl shadow-xs flex items-center justify-between transition-all duration-300'>

            <div class='flex items-center gap-2'>

                <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>

                <span class='font-bold text-sm'>แก้ไขประกาศข่าวสารเรียบร้อยแล้ว! ข้อมูลอัปเดตส่งถึงลูกบ้านทันที</span>

            </div>

            <button type='button' onclick='this.parentElement.remove()' class='text-emerald-600 hover:text-emerald-800 p-1 cursor-pointer'><i data-lucide='x' class='w-4 h-4'></i></button>

        </div>";

    } catch(Throwable $e) {

        $alertMessage = "<div class='alert-box bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>

            <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>

            <span>เกิดข้อผิดพลาดในการแก้ไขประกาศ: " . htmlspecialchars($e->getMessage()) . "</span>

        </div>";

    }

}



if (isset($_POST['delete_announcement']) || (isset($_POST['action']) && $_POST['action'] === 'delete_announcement')) {

    $id = intval($_POST['id'] ?? ($_POST['announcement_id'] ?? 0));

    try {

        $stmt = $conn->prepare("DELETE FROM Announcements WHERE announcementId = ?");

        $stmt->execute([$id]);

        $alertMessage = "<div class='alert-box bg-slate-50 border-l-4 border-slate-500 text-slate-700 p-4 mb-6 rounded-2xl shadow-xs text-sm font-bold flex items-center justify-between'>

            <div class='flex items-center gap-2'>

                <i data-lucide='trash-2' class='w-5 h-5 text-slate-500 shrink-0'></i>

                <span>ลบรายการประกาศข่าวสารออกเรียบร้อยแล้ว</span>

            </div>

            <button type='button' onclick='this.closest(\".alert-box\").remove()' class='text-slate-500 hover:text-slate-800 p-1 cursor-pointer'><i data-lucide='x' class='w-4 h-4'></i></button>

        </div>";

    } catch(Throwable $e) {

        try {

            $stmt = $conn->prepare("DELETE FROM Announcements WHERE id = ?");

            $stmt->execute([$id]);

            $alertMessage = "<div class='alert-box bg-slate-50 border-l-4 border-slate-500 text-slate-700 p-4 mb-6 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>

                <i data-lucide='trash-2' class='w-5 h-5 text-slate-500 shrink-0'></i>

                <span>ลบรายการประกาศข่าวสารออกเรียบร้อยแล้ว</span>

            </div>";

        } catch (Throwable $ex) {

            $alertMessage = "<div class='alert-box bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>

                <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>

                <span>ไม่สามารถลบประกาศได้</span>

            </div>";

        }

    }

}



// ดึงรายการประกาศล่าสุด 5 รายการ

$announcements = [];

try {

    $stmtAnn = $conn->query("SELECT * FROM Announcements ORDER BY announcementId DESC");

    if ($stmtAnn) $announcements = $stmtAnn->fetchAll(PDO::FETCH_ASSOC);

} catch(Throwable $e) {}



// --- 1. สรุปข้อมูลสถิติแบบปลอดภัย (Safe Metrics) ---

try {

    $conn->exec("

        UPDATE Room 

        SET status = 'Available' 

        WHERE status = 'Occupied' 

          AND roomId NOT IN (SELECT roomId FROM Lease WHERE status = 'Active')

    ");

} catch(Throwable $e) {}



$totalRooms = 0; $occupiedRooms = 0; $vacantRooms = 0;

try {

    $stmtRoom = $conn->query("

        SELECT 

            COUNT(r.roomId) as total, 

            SUM(CASE WHEN r.status='Occupied' AND l.leaseId IS NOT NULL THEN 1 ELSE 0 END) as occupied 

        FROM Room r

        LEFT JOIN Lease l ON r.roomId = l.roomId AND l.status = 'Active'

    ");

    if ($stmtRoom) {

        $roomStats = $stmtRoom->fetch(PDO::FETCH_ASSOC);

        $totalRooms = intval($roomStats['total'] ?: 0);

        $occupiedRooms = intval($roomStats['occupied'] ?: 0);

        $vacantRooms = $totalRooms - $occupiedRooms;

    }

} catch(Throwable $e) {}



$thaiMonths = [

    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',

    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',

    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'

];

$currMonthNum = intval(date('n'));

$currYear = date('Y');

$currMonthNameTh = $thaiMonths[$currMonthNum];



$revenue = 0;

try {

    $stmtRev = $conn->query("SELECT COALESCE(SUM(totalAmount), 0) FROM Invoices WHERE status = 'Paid'");

    if ($stmtRev) $revenue = (float)$stmtRev->fetchColumn();

} catch(Throwable $e) {}



$unpaidAmount = 0;

try {

    $stmtUnpaid = $conn->query("SELECT COALESCE(SUM(totalAmount), 0) FROM Invoices WHERE status = 'Unpaid'");

    if ($stmtUnpaid) $unpaidAmount = (float)$stmtUnpaid->fetchColumn();

} catch(Throwable $e) {}



$pendingSlipCount = 0;

try {

    $stmtSlip = $conn->query("SELECT COUNT(*) FROM Invoices WHERE status = 'Pending Approval'");

    if ($stmtSlip) $pendingSlipCount = (int)$stmtSlip->fetchColumn();

} catch(Throwable $e) {}



$pendingServices = 0;
$completedServices = 0;
try {
    $stmtServ = $conn->query("SELECT COUNT(*) FROM ServiceRequest WHERE status IN ('Pending', 'In Progress', 'Pending Approval')");
    if ($stmtServ) $pendingServices = (int)$stmtServ->fetchColumn();
    $stmtComp = $conn->query("SELECT COUNT(*) FROM ServiceRequest WHERE status = 'Completed'");
    if ($stmtComp) $completedServices = (int)$stmtComp->fetchColumn();
} catch(Throwable $e) {}



$pendingCoresidents = 0;

try {

    $stmtCore = $conn->query("SELECT COUNT(*) FROM CoResidents WHERE status IN ('Pending', 'Cancel_Pending')");

    if ($stmtCore) $pendingCoresidents = (int)$stmtCore->fetchColumn();

} catch(Throwable $e) {}



$pendingRenewals = 0;

try {

    $stmtRenCount = $conn->query("SELECT COUNT(*) FROM LeaseRenewals WHERE status = 'Pending'");

    if ($stmtRenCount) $pendingRenewals = (int)$stmtRenCount->fetchColumn();

} catch(Throwable $e) {}



$pendingRoomChanges = 0;

try {

    $stmtRCCount = $conn->query("SELECT COUNT(*) FROM RoomChangeRequests WHERE status = 'Pending'");

    if ($stmtRCCount) $pendingRoomChanges = (int)$stmtRCCount->fetchColumn();

} catch(Throwable $e) {}



$pendingMoveOuts = 0;

try {

    $stmtMoveCount = $conn->query("SELECT COUNT(*) FROM Lease WHERE status = 'Active' AND moveOutRequestDate IS NOT NULL");

    if ($stmtMoveCount) $pendingMoveOuts = (int)$stmtMoveCount->fetchColumn();

} catch(Throwable $e) {}



$totalPendingActions = $pendingSlipCount + $pendingServices + $pendingRenewals + $pendingCoresidents + $pendingRoomChanges + $pendingMoveOuts;



// --- 2. ระบบแจ้งเตือน (Notifications & Action Items) ---

$alerts = [];
$allAlertsData = [];

// 1. แจ้งย้ายออก (Move Out)
try {
    $stmtMoveAlerts = $conn->query("
        SELECT l.*, r.roomNumber, u.fullName, u.phone 
        FROM Lease l 
        JOIN Room r ON l.roomId = r.roomId 
        JOIN User u ON l.tenantId = u.userId 
        WHERE l.status = 'Active' AND l.moveOutRequestDate IS NOT NULL 
        ORDER BY l.leaseId DESC LIMIT 6
    ");
    if ($stmtMoveAlerts) {
        foreach($stmtMoveAlerts->fetchAll(PDO::FETCH_ASSOC) as $mo) {
            $alertId = 'mo_' . $mo['leaseId'];
            $reqDateStr = !empty($mo['moveOutRequestDate']) ? date('d/m/Y', strtotime($mo['moveOutRequestDate'])) : '-';
            $startDateStr = !empty($mo['startDate']) ? date('d/m/Y', strtotime($mo['startDate'])) : '-';
            $endDateStr = !empty($mo['endDate']) ? date('d/m/Y', strtotime($mo['endDate'])) : '-';
            $attachedImg = !empty($mo['tenantPaymentSlip']) ? $mo['tenantPaymentSlip'] : '';

            $alerts[] = [
                'id' => $alertId,
                'type' => 'move_out',
                'icon' => 'package-minus',
                'title' => 'คำขอแจ้งย้ายออก (ห้อง ' . $mo['roomNumber'] . ')',
                'message' => $mo['fullName'] . ' แจ้งย้ายออกวันที่ ' . $reqDateStr,
                'color' => 'text-rose-600',
                'badge' => 'แจ้งย้ายออก',
                'badgeColor' => 'bg-rose-50 text-rose-700 border-rose-200',
                'btnText' => 'สรุปเงินประกัน',
                'link' => 'move_out_form.php?roomId=' . $mo['roomId'] . '&leaseId=' . $mo['leaseId'],
                'attachedImage' => $attachedImg,
                'roomNumber' => $mo['roomNumber']
            ];

            $allAlertsData[$alertId] = [
                'id' => $alertId,
                'type' => 'move_out',
                'icon' => 'package-minus',
                'title' => 'คำขอแจ้งย้ายออก (ห้อง ' . $mo['roomNumber'] . ')',
                'subtitle' => 'คำขอย้ายออกจากผู้เช่า พร้อมข้อมูลบัญชีรับเงินประกันคืน',
                'badge' => 'แจ้งย้ายออก',
                'badgeColor' => 'bg-rose-50 text-rose-700 border-rose-200',
                'roomNumber' => $mo['roomNumber'],
                'tenantName' => $mo['fullName'],
                'tenantPhone' => !empty($mo['phone']) ? $mo['phone'] : 'ไม่ระบุเบอร์โทร',
                'requestDate' => $reqDateStr,
                'reason' => !empty($mo['moveOutReason']) ? $mo['moveOutReason'] : 'ไม่ได้ระบุเหตุผลการย้ายออก',
                'customFields' => [
                    ['label' => 'ห้องพัก', 'value' => 'ห้อง ' . $mo['roomNumber']],
                    ['label' => 'วันเริ่มสัญญา', 'value' => $startDateStr],
                    ['label' => 'วันสิ้นสุดสัญญา', 'value' => $endDateStr],
                    ['label' => 'วันที่ประสงค์จะย้ายออก', 'value' => $reqDateStr]
                ],
                'financialFields' => [
                    ['label' => 'เงินประกันความเสียหาย', 'value' => '฿' . number_format($mo['depositAmount'] ?? 0, 2)],
                    ['label' => 'ธนาคารรับเงินคืน', 'value' => !empty($mo['bankName']) ? $mo['bankName'] : '-'],
                    ['label' => 'เลขที่บัญชีรับเงินคืน', 'value' => !empty($mo['bankAccount']) ? $mo['bankAccount'] : '-'],
                    ['label' => 'ชื่อเจ้าของบัญชี', 'value' => !empty($mo['accountName']) ? $mo['accountName'] : '-']
                ],
                'attachedImage' => $attachedImg,
                'link' => 'move_out_form.php?roomId=' . $mo['roomId'] . '&leaseId=' . $mo['leaseId'],
                'btnText' => 'ไปหน้าสรุปเงินประกันและตรวจห้อง'
            ];
        }
    }
} catch(Throwable $e) {}

// 2. ขอย้ายห้อง (Room Change)
try {
    $stmtRCAlerts = $conn->query("
        SELECT rcr.*, r.roomNumber, u.fullName, u.phone, targetRoom.roomNumber AS targetRoomNumber
        FROM RoomChangeRequests rcr 
        JOIN Room r ON rcr.currentRoomId = r.roomId 
        JOIN User u ON rcr.tenantId = u.userId 
        LEFT JOIN Room targetRoom ON rcr.targetRoomId = targetRoom.roomId
        WHERE rcr.status = 'Pending' 
        ORDER BY rcr.requestId DESC LIMIT 6
    ");
    if ($stmtRCAlerts) {
        foreach($stmtRCAlerts->fetchAll(PDO::FETCH_ASSOC) as $rc) {
            $alertId = 'rc_' . $rc['requestId'];
            $reqDateStr = !empty($rc['created_at']) ? date('d/m/Y H:i', strtotime($rc['created_at'])) . ' น.' : '-';
            $prefMoveStr = !empty($rc['preferredMoveDate']) ? date('d/m/Y', strtotime($rc['preferredMoveDate'])) : 'เร็วที่สุด';
            $attachedImg = !empty($rc['paymentSlip']) ? $rc['paymentSlip'] : '';
            $targetRoomStr = !empty($rc['targetRoomNumber']) ? ('ห้อง ' . $rc['targetRoomNumber']) : 'นิติจัดสรรให้ตามว่าง';

            $alerts[] = [
                'id' => $alertId,
                'type' => 'room_change',
                'icon' => 'arrow-left-right',
                'title' => 'คำขอย้ายห้องพัก (ห้อง ' . $rc['roomNumber'] . ')',
                'message' => $rc['fullName'] . ' - ' . mb_strimwidth($rc['reason'], 0, 45, '...'),
                'time' => $rc['created_at'] ?? '',
                'color' => 'text-rose-600',
                'badge' => 'ขอย้ายห้อง',
                'badgeColor' => 'bg-rose-50 text-rose-700 border-rose-200',
                'btnText' => 'พิจารณา',
                'link' => 'manage_room.php?tab=room_changes',
                'attachedImage' => $attachedImg,
                'roomNumber' => $rc['roomNumber']
            ];

            $allAlertsData[$alertId] = [
                'id' => $alertId,
                'type' => 'room_change',
                'icon' => 'arrow-left-right',
                'title' => 'คำขอย้ายห้องพัก (ห้อง ' . $rc['roomNumber'] . ')',
                'subtitle' => 'คำขอย้ายห้องพักจากลูกบ้าน พร้อมรายละเอียดห้องที่ต้องการ',
                'badge' => 'ขอย้ายห้อง',
                'badgeColor' => 'bg-rose-50 text-rose-700 border-rose-200',
                'roomNumber' => $rc['roomNumber'],
                'tenantName' => $rc['fullName'],
                'tenantPhone' => !empty($rc['phone']) ? $rc['phone'] : 'ไม่ระบุเบอร์โทร',
                'requestDate' => $reqDateStr,
                'reason' => !empty($rc['reason']) ? $rc['reason'] : 'ไม่ได้ระบุเหตุผลการย้ายห้อง',
                'customFields' => [
                    ['label' => 'ห้องพักปัจจุบัน', 'value' => 'ห้อง ' . $rc['roomNumber']],
                    ['label' => 'ประเภทห้องที่ต้องการ', 'value' => !empty($rc['targetRoomType']) ? $rc['targetRoomType'] : 'ตามสะดวก'],
                    ['label' => 'ห้องเป้าหมายที่เลือก', 'value' => $targetRoomStr],
                    ['label' => 'วันที่ต้องการย้าย', 'value' => $prefMoveStr]
                ],
                'financialFields' => [
                    ['label' => 'สถานะคำขอ', 'value' => 'รอพิจารณาอนุมัติ'],
                    ['label' => 'สลิปค่าธรรมเนียมย้ายห้อง', 'value' => !empty($attachedImg) ? 'มีสลิปแนบมาด้วย' : 'ไม่มีสลิปแนบ']
                ],
                'attachedImage' => $attachedImg,
                'link' => 'manage_room.php?tab=room_changes',
                'btnText' => 'ไปพิจารณาคำขอย้ายห้อง'
            ];
        }
    }
} catch(Throwable $e) {}

// 3. ขอต่อสัญญาเช่า (Lease Renewal)
try {
    $stmtRenAlerts = $conn->query("
        SELECT lr.*, r.roomNumber, u.fullName, u.phone, l.endDate AS currentEndDate 
        FROM LeaseRenewals lr 
        JOIN Room r ON lr.roomId = r.roomId 
        JOIN User u ON lr.tenantId = u.userId 
        LEFT JOIN Lease l ON lr.leaseId = l.leaseId 
        WHERE lr.status = 'Pending' 
        ORDER BY lr.renewalId DESC LIMIT 6
    ");
    if ($stmtRenAlerts) {
        foreach($stmtRenAlerts->fetchAll(PDO::FETCH_ASSOC) as $ren) {
            $alertId = 'ren_' . $ren['renewalId'];
            $reqDateStr = !empty($ren['created_at']) ? date('d/m/Y H:i', strtotime($ren['created_at'])) . ' น.' : '-';
            $currEndDateStr = !empty($ren['currentEndDate']) ? date('d/m/Y', strtotime($ren['currentEndDate'])) : '-';
            $attachedImg = !empty($ren['leaseImage']) ? $ren['leaseImage'] : '';

            $alerts[] = [
                'id' => $alertId,
                'type' => 'renewal',
                'icon' => 'refresh-cw',
                'title' => 'คำขอต่อสัญญาเช่า (ห้อง ' . $ren['roomNumber'] . ')',
                'message' => $ren['fullName'] . ' ขอต่อสัญญาเพิ่ม ' . $ren['extensionMonths'] . ' เดือน',
                'time' => $ren['created_at'] ?? '',
                'color' => 'text-blue-600',
                'badge' => 'รออนุมัติ',
                'badgeColor' => 'bg-blue-50 text-blue-700 border-blue-200',
                'btnText' => 'อนุมัติสัญญา',
                'link' => 'manage_room.php?tab=renewals',
                'attachedImage' => $attachedImg,
                'roomNumber' => $ren['roomNumber']
            ];

            $allAlertsData[$alertId] = [
                'id' => $alertId,
                'type' => 'renewal',
                'icon' => 'refresh-cw',
                'title' => 'คำขอต่อสัญญาเช่า (ห้อง ' . $ren['roomNumber'] . ')',
                'subtitle' => 'คำขอขยายระยะเวลาสัญญาเช่าพักอาศัยจากลูกบ้าน',
                'badge' => 'รออนุมัติ',
                'badgeColor' => 'bg-blue-50 text-blue-700 border-blue-200',
                'roomNumber' => $ren['roomNumber'],
                'tenantName' => $ren['fullName'],
                'tenantPhone' => !empty($ren['phone']) ? $ren['phone'] : 'ไม่ระบุเบอร์โทร',
                'requestDate' => $reqDateStr,
                'reason' => 'ลูกบ้านยื่นคำขอต่อสัญญาเช่าพักอาศัย เพิ่มอีก ' . $ren['extensionMonths'] . ' เดือน',
                'customFields' => [
                    ['label' => 'ห้องพัก', 'value' => 'ห้อง ' . $ren['roomNumber']],
                    ['label' => 'สัญญาเดิมสิ้นสุดวันที่', 'value' => $currEndDateStr],
                    ['label' => 'ระยะเวลาที่ขอต่อเพิ่ม', 'value' => $ren['extensionMonths'] . ' เดือน']
                ],
                'financialFields' => [
                    ['label' => 'สถานะคำขอ', 'value' => 'รอการอนุมัติสัญญาใหม่'],
                    ['label' => 'เอกสาร/สลิปต่อสัญญา', 'value' => !empty($attachedImg) ? 'มีเอกสารแนบ' : 'ไม่มีเอกสารแนบ']
                ],
                'attachedImage' => $attachedImg,
                'link' => 'manage_room.php?tab=renewals',
                'btnText' => 'ไปอนุมัติสัญญาเช่า'
            ];
        }
    }
} catch(Throwable $e) {}

// 4. แจ้งซ่อมและทำความสะอาด (Service Requests)
$requestsData = [];
$indexedRequests = [];
try {
    $stmtReqAlerts = $conn->query("
        SELECT sr.*, r.roomNumber, u.fullName AS tenantName, u.phone AS tenantPhone,
               st.fullName AS staffName, st.role AS staffRole, st.phone AS staffPhone
        FROM ServiceRequest sr
        JOIN Room r ON sr.roomId = r.roomId
        JOIN User u ON sr.tenantId = u.userId
        LEFT JOIN User st ON sr.assignedTo = st.userId
        WHERE sr.status IN ('Pending', 'In Progress', 'Pending Approval', 'Completed')
        ORDER BY 
            CASE 
                WHEN sr.status = 'Pending Approval' THEN 1
                WHEN sr.status = 'Pending' THEN 2
                WHEN sr.status = 'In Progress' THEN 3
                WHEN sr.status = 'Completed' THEN 4
                ELSE 5
            END,
            COALESCE(sr.completedAt, sr.assignedAt, sr.requestDate) DESC,
            sr.requestId DESC
        LIMIT 25
    ");
    if ($stmtReqAlerts) {
        foreach($stmtReqAlerts->fetchAll(PDO::FETCH_ASSOC) as $req) {
            $isComp = ($req['status'] === 'Completed');
            $isInProg = ($req['status'] === 'In Progress');
            $isPendingAppr = ($req['status'] === 'Pending Approval');
            $staffLabel = !empty($req['staffName']) ? (' (โดย ' . htmlspecialchars($req['staffName']) . ')') : '';

            $indexedRequests[(int)$req['requestId']] = [
                'requestId' => (int)$req['requestId'],
                'reqCode' => 'SR-' . str_pad($req['requestId'], 4, '0', STR_PAD_LEFT),
                'roomNumber' => $req['roomNumber'] ?? '-',
                'category' => $req['category'] ?? '',
                'description' => $req['description'] ?? '',
                'requestImage' => $req['requestImage'] ?? '',
                'tenantName' => $req['tenantName'] ?? '',
                'tenantPhone' => $req['tenantPhone'] ?? '',
                'preferredDate' => !empty($req['preferredDate']) ? date('d/m/Y', strtotime($req['preferredDate'])) : '-',
                'preferredTime' => $req['preferredTime'] ?? '',
                'requestDate' => !empty($req['requestDate']) ? date('d/m/Y H:i', strtotime($req['requestDate'])) : '-',
                'timeAgoRequest' => !empty($req['requestDate']) ? time_ago_th($req['requestDate']) : '',
                'status' => $req['status'],
                'assignedTo' => $req['assignedTo'] ? (int)$req['assignedTo'] : null,
                'assignedAt' => !empty($req['assignedAt']) ? date('d/m/Y H:i', strtotime($req['assignedAt'])) : '',
                'timeAgoAssigned' => !empty($req['assignedAt']) ? time_ago_th($req['assignedAt']) : '',
                'staffName' => $req['staffName'] ?? '',
                'staffRole' => $req['staffRole'] ?? '',
                'staffPhone' => $req['staffPhone'] ?? '',
                'completionImage' => $req['completionImage'] ?? '',
                'completedAt' => !empty($req['completedAt']) ? date('d/m/Y H:i', strtotime($req['completedAt'])) : '',
                'duration' => (!empty($req['requestDate']) && !empty($req['completedAt'])) ? duration_th($req['requestDate'], $req['completedAt']) : '',
                'timeAgoCompleted' => !empty($req['completedAt']) ? time_ago_th($req['completedAt']) : '',
                'tenantComment' => $req['tenantComment'] ?? '',
                'commentedAt' => !empty($req['commentedAt']) ? date('d/m/Y H:i', strtotime($req['commentedAt'])) : '',
                'estimatedHours' => (int)($req['estimatedHours'] ?? 2),
                'isCleaning' => (mb_strpos($req['category'] ?? '', 'ความสะอาด', 0, 'UTF-8') !== false || mb_strpos($req['category'] ?? '', 'ขยะ', 0, 'UTF-8') !== false),
                'staffComment' => $req['staffComment'] ?? '',
                'staffCommentedAt' => !empty($req['staffCommentedAt']) ? date('d/m/Y H:i', strtotime($req['staffCommentedAt'])) : ''
            ];

            $alerts[] = [
                'id' => 'req_' . $req['requestId'],
                'requestId' => (int)$req['requestId'],
                'type' => 'service_request',
                'roomNumber' => $req['roomNumber'] ?? '',
                'requestImage' => $req['requestImage'] ?? '',
                'icon' => $isPendingAppr ? 'clipboard-check' : ($isComp ? 'check-circle-2' : ($isInProg ? 'wrench' : 'clock')),
                'title' => ($isPendingAppr ? 'ช่างส่งมอบงาน (รออนุมัติ)' : ($isComp ? 'งานซ่อมเสร็จสิ้น' : ($isInProg ? 'งานอยู่ระหว่างทำ' : 'แจ้งซ่อมใหม่'))) . ' (ห้อง ' . ($req['roomNumber'] ?? '-') . ')' . $staffLabel,
                'message' => $req['category'] . ': ' . mb_strimwidth($req['description'], 0, 40, '...'),
                'time' => $req['requestDate'] ?? '',
                'requestDate' => $req['requestDate'] ?? '',
                'completedAt' => $isComp ? ($req['completedAt'] ?? '') : '',
                'color' => $isPendingAppr ? 'text-orange-600' : ($isComp ? 'text-emerald-600' : ($isInProg ? 'text-blue-600' : 'text-amber-600')),
                'badge' => $isPendingAppr ? 'รอแอดมินอนุมัติ' : ($isComp ? 'เสร็จสิ้นแล้ว' : ($isInProg ? 'กำลังดำเนินการ' : 'รอมอบหมาย')),
                'badgeColor' => $isPendingAppr ? 'bg-orange-50 text-orange-700 border-orange-200' : ($isComp ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($isInProg ? 'bg-blue-50 text-blue-700 border-blue-200' : 'bg-amber-50 text-amber-700 border-amber-200')),
                'btnText' => $isPendingAppr ? 'ตรวจและอนุมัติ' : ($isComp ? 'ดูผลงาน' : ($isInProg ? 'ดูสถานะ' : 'จ่ายงานช่าง')),
                'link' => 'admin_requests.php?status=' . ($isPendingAppr ? 'Pending Approval' : ($isComp ? 'Completed' : ($isInProg ? 'In Progress' : 'Pending'))) . '&req_id=' . $req['requestId'],
                'attachedImage' => !empty($req['requestImage']) ? $req['requestImage'] : (!empty($req['completionImage']) ? $req['completionImage'] : '')
            ];
        }
    }
} catch(Throwable $e) {}

// 5. สลิปโอนเงินรอตรวจ (Pending Slips)
try {
    $stmtSl = $conn->query("
        SELECT i.*, r.roomNumber, u.fullName AS tenantName, u.phone AS tenantPhone 
        FROM Invoices i 
        JOIN Room r ON i.roomId = r.roomId 
        LEFT JOIN User u ON i.tenantId = u.userId 
        WHERE i.status = 'Pending Approval' 
        ORDER BY i.invoiceId DESC LIMIT 6
    ");
    if ($stmtSl) {
        foreach($stmtSl->fetchAll(PDO::FETCH_ASSOC) as $slip) {
            $alertId = 'slip_' . $slip['invoiceId'];
            $reqDateStr = !empty($slip['createdAt']) ? date('d/m/Y H:i', strtotime($slip['createdAt'])) . ' น.' : '-';
            $attachedImg = !empty($slip['slipImage']) ? $slip['slipImage'] : '';
            $tName = !empty($slip['tenantName']) ? $slip['tenantName'] : ('ผู้เช่าห้อง ' . $slip['roomNumber']);

            $alerts[] = [
                'id' => $alertId,
                'type' => 'slip',
                'icon' => 'credit-card',
                'title' => 'สลิปโอนเงินรอตรวจสอบ (ห้อง ' . $slip['roomNumber'] . ')',
                'message' => 'รอบบิล ' . ($slip['billingMonth'] ?? '-') . ' ยอด ฿' . number_format($slip['totalAmount'], 2),
                'color' => 'text-emerald-600',
                'badge' => 'รอตรวจสลิป',
                'badgeColor' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'btnText' => 'ตรวจสลิป',
                'link' => 'manage_invoice.php?tab=verify&inv_id=' . $slip['invoiceId'],
                'attachedImage' => $attachedImg,
                'roomNumber' => $slip['roomNumber']
            ];

            $allAlertsData[$alertId] = [
                'id' => $alertId,
                'type' => 'slip',
                'icon' => 'credit-card',
                'title' => 'สลิปโอนเงินรอตรวจสอบ (ห้อง ' . $slip['roomNumber'] . ')',
                'subtitle' => 'หลักฐานการชำระเงินค่าเช่าและค่าน้ำค่าไฟที่รอนิติตรวจสอบ',
                'badge' => 'รอตรวจสลิป',
                'badgeColor' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'roomNumber' => $slip['roomNumber'],
                'tenantName' => $tName,
                'tenantPhone' => !empty($slip['tenantPhone']) ? $slip['tenantPhone'] : 'ไม่ระบุเบอร์โทร',
                'requestDate' => $reqDateStr,
                'reason' => 'ลูกบ้านได้แนบสลิปชำระเงินค่าเช่าห้องพักและสาธารณูปโภคประจำรอบบิล ' . ($slip['billingMonth'] ?? '-'),
                'customFields' => [
                    ['label' => 'ห้องพัก', 'value' => 'ห้อง ' . $slip['roomNumber']],
                    ['label' => 'รอบบิล', 'value' => $slip['billingMonth'] ?? '-'],
                    ['label' => 'ค่าเช่าห้อง', 'value' => '฿' . number_format($slip['rentPrice'] ?? 0, 2)],
                    ['label' => 'ค่าน้ำประปา', 'value' => '฿' . number_format(($slip['waterCurr'] - $slip['waterPrev']) * ($slip['waterUnitRate'] ?? 18), 2)],
                    ['label' => 'ค่าไฟฟ้า', 'value' => '฿' . number_format(($slip['electricCurr'] - $slip['electricPrev']) * ($slip['electricUnitRate'] ?? 7), 2)],
                    ['label' => 'ค่าใช้จ่ายอื่นๆ / ส่วนกลาง', 'value' => '฿' . number_format($slip['otherFee'] ?? 0, 2) . (!empty($slip['otherFeeNote']) ? ' (' . $slip['otherFeeNote'] . ')' : '')]
                ],
                'financialFields' => [
                    ['label' => 'ยอดรวมสุทธิที่ต้องชำระ', 'value' => '฿' . number_format($slip['totalAmount'] ?? 0, 2)],
                    ['label' => 'สถานะการชำระ', 'value' => 'รอการตรวจสอบและอนุมัติสลิป']
                ],
                'attachedImage' => $attachedImg,
                'link' => 'manage_invoice.php?tab=verify&inv_id=' . $slip['invoiceId'],
                'btnText' => 'ไปหน้าตรวจสอบและอนุมัติสลิป'
            ];
        }
    }
} catch(Throwable $e) {}

// 6. ขอเพิ่ม/ยกเลิกผู้ร่วมอาศัย (CoResidents)
try {
    $stmtCoAlerts = $conn->query("
        SELECT cr.*, r.roomNumber, u.fullName AS tenantName, u.phone AS tenantPhone 
        FROM CoResidents cr 
        JOIN Room r ON cr.roomId = r.roomId 
        JOIN User u ON cr.tenantId = u.userId 
        WHERE cr.status IN ('Pending', 'Cancel_Pending') 
        ORDER BY cr.id DESC LIMIT 6
    ");
    if ($stmtCoAlerts) {
        foreach($stmtCoAlerts->fetchAll(PDO::FETCH_ASSOC) as $co) {
            $isCancel = ($co['status'] === 'Cancel_Pending');
            $alertId = 'co_' . $co['id'];
            $reqDateStr = !empty($co['created_at']) ? date('d/m/Y H:i', strtotime($co['created_at'])) . ' น.' : '-';
            $attachedImg = !empty($co['idCardImage']) ? $co['idCardImage'] : '';
            $titleStr = ($isCancel ? 'ขอยกเลิกผู้ร่วมอาศัย' : 'ขอเพิ่มผู้ร่วมอาศัย') . ' (ห้อง ' . ($co['roomNumber'] ?? '-') . ')';
            $badgeStr = $isCancel ? 'ขอยกเลิก' : 'รออนุมัติ';
            $badgeColorStr = $isCancel ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-purple-50 text-purple-700 border-purple-200';

            $alerts[] = [
                'id' => $alertId,
                'type' => 'coresident',
                'icon' => 'users',
                'title' => $titleStr,
                'message' => $co['fullName'] . ' (ผู้เช่า: ' . $co['tenantName'] . ')',
                'time' => $co['created_at'] ?? '',
                'color' => 'text-purple-600',
                'badge' => $badgeStr,
                'badgeColor' => $badgeColorStr,
                'btnText' => 'พิจารณา',
                'link' => 'admin_coresidents.php',
                'attachedImage' => $attachedImg,
                'roomNumber' => $co['roomNumber']
            ];

            $allAlertsData[$alertId] = [
                'id' => $alertId,
                'type' => 'coresident',
                'icon' => 'users',
                'title' => $titleStr,
                'subtitle' => 'คำขอจัดการข้อมูลผู้ร่วมอาศัยพักร่วมกันในห้องพัก',
                'badge' => $badgeStr,
                'badgeColor' => $badgeColorStr,
                'roomNumber' => $co['roomNumber'] ?? '-',
                'tenantName' => $co['tenantName'] ?? '-',
                'tenantPhone' => !empty($co['tenantPhone']) ? $co['tenantPhone'] : 'ไม่ระบุเบอร์โทร',
                'requestDate' => $reqDateStr,
                'reason' => $isCancel ? (!empty($co['cancelReason']) ? $co['cancelReason'] : 'ขอยกเลิกการพักอาศัยของผู้ร่วมอาศัย') : 'ขอเพิ่มผู้ร่วมอาศัยเพื่อเข้าพักร่วมกันในห้องพัก',
                'customFields' => [
                    ['label' => 'ห้องพัก', 'value' => 'ห้อง ' . ($co['roomNumber'] ?? '-')],
                    ['label' => 'ผู้เช่าหลัก', 'value' => $co['tenantName'] ?? '-'],
                    ['label' => 'ชื่อผู้ร่วมอาศัย', 'value' => $co['fullName'] ?? '-'],
                    ['label' => 'ความสัมพันธ์', 'value' => !empty($co['relationship']) ? $co['relationship'] : '-'],
                    ['label' => 'เบอร์โทรผู้ร่วมอาศัย', 'value' => !empty($co['phoneNumber']) ? $co['phoneNumber'] : '-'],
                    ['label' => 'เลขบัตรประชาชน', 'value' => !empty($co['idCardNumber']) ? $co['idCardNumber'] : '-']
                ],
                'financialFields' => [
                    ['label' => 'ประเภทคำขอ', 'value' => $isCancel ? 'ขอยกเลิกผู้ร่วมอาศัย' : 'ขอเพิ่มผู้ร่วมอาศัยใหม่'],
                    ['label' => 'สถานะการพิจารณา', 'value' => 'รอนิติบุคคลพิจารณาอนุมัติ']
                ],
                'attachedImage' => $attachedImg,
                'link' => 'admin_coresidents.php',
                'btnText' => 'ไปจัดการผู้ร่วมอาศัย'
            ];
        }
    }
} catch(Throwable $e) {}
// 🌟 กรองรายการที่แอดมินเคยกดอ่านแล้วออก ไม่ให้แสดงซ้ำ

$adminId = (int)($_SESSION['user_id'] ?? 0);

$readNotifIds = [];

try {

    $stmtRead = $conn->prepare("SELECT notif_id FROM admin_read_notifications WHERE admin_id = ?");

    $stmtRead->execute([$adminId]);

    $readNotifIds = $stmtRead->fetchAll(PDO::FETCH_COLUMN) ?: [];

} catch(Throwable $e) {}



$unreadAlerts = [];

foreach ($alerts as $item) {

    // 🌟 ดึงข้อมูลให้เป็นปัจจุบันตามสถานะจริงของระบบ:
    // รายการที่ยังค้างอยู่จริง (Active Pending Tasks เช่น Pending, In Progress, รอตรวจสลิป, ขอย้ายห้อง, ย้ายออก, ขอเพิ่มผู้ร่วมอาศัย)
    // ต้องแสดงใน "รายการที่ต้องดำเนินการ" เสมอ ไม่ถูกซ่อน
    // เฉพาะรายการที่เสร็จสิ้นสมบูรณ์แล้ว (Completed) เท่านั้น ที่ถ้าแอดมินเคยกดอ่านแล้วถึงจะซ่อน
    $isCompleted = (isset($item['badge']) && $item['badge'] === 'เสร็จสิ้นแล้ว');

    if ($isCompleted && in_array($item['id'], $readNotifIds)) {

        continue;

    }

    $unreadAlerts[] = $item;

}

$alerts = $unreadAlerts;

$totalPendingActions = count($alerts);



$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;



// 🌟 คำทักทายตามช่วงเวลาจริง (Warm & Cozy Boutique Greeting)

$currentHour = (int)date('G');

if ($currentHour >= 5 && $currentHour < 12) {

    $timeGreeting = 'สวัสดีตอนเช้า';

    $timeIcon = 'sun';

    $timeBadge = 'ยามเช้าอันสดใส';

} elseif ($currentHour >= 12 && $currentHour < 17) {

    $timeGreeting = 'สวัสดีตอนบ่าย';

    $timeIcon = 'sun-medium';

    $timeBadge = 'ช่วงเวลาบ่าย';

} elseif ($currentHour >= 17 && $currentHour < 21) {

    $timeGreeting = 'สวัสดีตอนเย็น';

    $timeIcon = 'sunset';

    $timeBadge = 'ยามเย็นแสนอบอุ่น';

} else {

    $timeGreeting = 'สวัสดีตอนค่ำ';

    $timeIcon = 'moon';

    $timeBadge = 'ราตรีสวัสดิ์';

}



?>



<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

  <title>jasmin</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Kanit:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest"></script>

  <script>

    tailwind.config = {

      theme: {

        extend: {

          fontFamily: {

            sans: ['Prompt', 'sans-serif'],

            display: ['Kanit', 'sans-serif'],

            number: ['Plus Jakarta Sans', 'Prompt', 'sans-serif']

          }

        }

      }

    }



    function selectAddAnnouncementType(type) {

        const input = document.getElementById('addAnnouncementTypeInput');

        if (input) input.value = type;

        const btnInfo = document.getElementById('btnAddAnnInfo');

        const btnWarn = document.getElementById('btnAddAnnWarn');

        const btnSucc = document.getElementById('btnAddAnnSucc');

        const inactive = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white text-stone-700 font-semibold';

        if (btnInfo) btnInfo.className = inactive;

        if (btnWarn) btnWarn.className = inactive;

        if (btnSucc) btnSucc.className = inactive;

        if (type === 'info' && btnInfo) btnInfo.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs';

        else if (type === 'warning' && btnWarn) btnWarn.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-amber-500 bg-amber-50/80 text-amber-950 font-bold shadow-xs';

        else if (type === 'success' && btnSucc) btnSucc.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-emerald-500 bg-emerald-50/80 text-emerald-950 font-bold shadow-xs';

    }



    function selectEditAnnouncementType(type) {

        const input = document.getElementById('editAnnouncementType');

        if (input) input.value = type;

        const btnInfo = document.getElementById('btnEditAnnInfo');

        const btnWarn = document.getElementById('btnEditAnnWarn');

        const btnSucc = document.getElementById('btnEditAnnSucc');

        const inactive = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white text-stone-700 font-semibold';

        if (btnInfo) btnInfo.className = inactive;

        if (btnWarn) btnWarn.className = inactive;

        if (btnSucc) btnSucc.className = inactive;

        if (type === 'info' && btnInfo) btnInfo.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs';

        else if (type === 'warning' && btnWarn) btnWarn.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-amber-500 bg-amber-50/80 text-amber-950 font-bold shadow-xs';

        else if (type === 'success' && btnSucc) btnSucc.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-emerald-500 bg-emerald-50/80 text-emerald-950 font-bold shadow-xs';

    }

  </script>

  <style>

    .glass-card, .boutique-card {

      background: rgba(255, 255, 255, 0.95);

      backdrop-filter: blur(16px);

      -webkit-backdrop-filter: blur(16px);

      border: 1px solid rgba(226, 232, 240, 0.9);

      box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.04);

      border-radius: 1.5rem;

    }

    html[data-theme="dark"] .glass-card,

    html[data-theme="dark"] .boutique-card {

      background: rgba(15, 23, 42, 0.75);

      border-color: rgba(255, 255, 255, 0.08);

      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);

    }

    .stat-card-modern {

      transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);

      position: relative;

      overflow: hidden;

    }

    .stat-card-modern:hover {

      transform: translateY(-4px);

      box-shadow: 0 16px 32px -8px rgba(0, 0, 0, 0.08), 0 4px 12px -2px rgba(0, 0, 0, 0.03);

    }

    .service-tile {

      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);

    }

    .service-tile:hover {

      transform: translateY(-3px);

      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.07);

    }

    .modal-enter {

      animation: modalFadeIn 0.24s cubic-bezier(0.16, 1, 0.3, 1) forwards;

    }

    @keyframes modalFadeIn {

      from { opacity: 0; transform: scale(0.96) translateY(8px); }

      to { opacity: 1; transform: scale(1) translateY(0); }

    }

    /* Pulse animation for alert indicators */

    @keyframes livePulse {

      0%, 100% { opacity: 1; transform: scale(1); }

      50% { opacity: 0.5; transform: scale(1.15); }

    }

    .live-pulse-dot {

      animation: livePulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

    }

  </style>

  <?php include_once 'includes/swal.php'; ?>

  <?php include_once 'includes/theme_head.php'; ?>
</head>



<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white pb-16 md:pb-8">



  <!-- Ambient Glowing Background Orbs (Soft Rose) -->

  <div class="fixed top-0 left-1/4 w-96 h-96 bg-rose-400/5 rounded-full blur-3xl pointer-events-none -z-10"></div>

  <div class="fixed top-1/3 right-10 w-96 h-96 bg-pink-400/5 rounded-full blur-3xl pointer-events-none -z-10"></div>



  <?php include_once 'includes/admin_sidebar.php'; ?>



  <!-- Top Navigation (Admin) - Warm Rose & Linen Boutique Header -->

  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between h-16">



        <!-- Logo & Sidebar Toggle -->

        <div class="flex items-center space-x-3">

          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/80 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">

            <i data-lucide="menu" class="w-5 h-5"></i>

          </button>

          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-rose-500 via-rose-600 to-pink-600 flex items-center justify-center text-white shadow-md shadow-rose-500/25 shrink-0">

            <i data-lucide="shield-check" class="w-5 h-5"></i>

          </div>

          <div>

            <div class="flex items-center space-x-2">

              <span class="font-display font-extrabold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>

            </div>

          </div>

        </div>



        <!-- Right Side Nav Actions -->

        <div class="flex items-center space-x-2 sm:space-x-3">

          <!-- 🌟 สลับธีม 2 สี (มืด / สว่าง) 🌟 -->
          <?php include 'includes/theme_toggle.php'; ?>

          <!-- 🔔 ปุ่มแจ้งเตือนงานค้าง -->

          <div class="relative notif-wrapper">

            <button onclick="toggleNotifications()" class="relative p-2.5 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/80 transition-colors focus:outline-none cursor-pointer" title="การแจ้งเตือนงานค้าง">

              <i data-lucide="bell" class="w-5 h-5"></i>

              <span id="bellBadge" class="<?php echo $totalPendingActions > 0 ? '' : 'hidden'; ?> absolute -top-1 -right-1 bg-rose-500 text-white text-[10px] font-bold px-1.5 min-w-[20px] h-[20px] rounded-full flex items-center justify-center shadow-xs">

                <?php echo $totalPendingActions; ?>

              </span>

            </button>



            <!-- Dropdown Notification (Warm Rose & Linen Style) -->

            <div id="notifDropdown" class="hidden absolute right-0 mt-2.5 w-80 sm:w-96 bg-white rounded-3xl shadow-xl border border-rose-100/90 py-3.5 px-4 z-50 modal-enter text-slate-700">

              <div class="flex items-center justify-between pb-3 border-b border-rose-100">

                <span class="font-bold text-sm text-slate-900 flex items-center gap-1.5">

                  <i data-lucide="bell-ring" class="w-4 h-4 text-rose-500"></i> รายการที่ต้องดำเนินการ

                </span>

                <div class="flex items-center gap-2">

                  <span id="notifBadgeCount" class="text-xs bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200">

                    <?php echo $totalPendingActions; ?> รายการ

                  </span>

                  <button id="btnMarkAllRead" type="button" onclick="markAllNotifsAsRead(event)" class="<?php echo $totalPendingActions > 0 ? '' : 'hidden'; ?> text-[11px] font-semibold text-rose-600 hover:text-rose-800 hover:underline flex items-center gap-0.5 cursor-pointer transition" title="ทำเครื่องหมายว่าอ่านทั้งหมด">

                    <i data-lucide="check-check" class="w-3.5 h-3.5"></i> อ่านทั้งหมด

                  </button>

                </div>

              </div>

              <div id="notifListContainer" class="divide-y divide-rose-50 max-h-80 overflow-y-auto py-1">

                <?php if(count($alerts) > 0): foreach($alerts as $alert): ?>

                  <div id="notif-item-<?php echo $alert['id']; ?>" class="flex items-start gap-2 py-3 px-2 rounded-2xl hover:bg-rose-50/50 transition border-b border-rose-50/50 last:border-0 group">

                    <a href="<?php echo $alert['link']; ?>" onclick="handleNotifClick(event, '<?php echo $alert['id']; ?>', '<?php echo $alert['link']; ?>')" class="flex items-start gap-3 flex-1 min-w-0">

                      <div class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center shrink-0 mt-0.5 group-hover:scale-105 transition">

                        <i data-lucide="<?php echo $alert['icon']; ?>" class="w-4 h-4"></i>

                      </div>

                      <div class="flex-1 min-w-0">

                        <div class="flex items-center justify-between gap-1 mb-0.5">

                          <strong class="text-xs truncate <?php echo $alert['color']; ?>"><?php echo htmlspecialchars($alert['title']); ?></strong>

                          <span class="text-[10px] font-bold px-1.5 py-0.5 rounded border <?php echo $alert['badgeColor']; ?> shrink-0"><?php echo $alert['badge']; ?></span>

                        </div>

                        <span class="text-xs text-slate-500 block line-clamp-2"><?php echo htmlspecialchars($alert['message']); ?></span>

                        <?php if (!empty($alert['time'])): ?>

                          <span class="text-[10px] text-slate-400 block mt-1">

                            <?php echo date("d/m/Y H:i", strtotime($alert['time'])); ?> น.

                          </span>

                        <?php endif; ?>

                      </div>

                    </a>

                    <button type="button" onclick="dismissNotif(event, '<?php echo $alert['id']; ?>')" class="p-1.5 rounded-xl text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 border border-transparent hover:border-emerald-200 transition shrink-0 self-center cursor-pointer" title="ทำเครื่องหมายว่าอ่านแล้ว">

                      <i data-lucide="check" class="w-4 h-4"></i>

                    </button>

                  </div>

                <?php endforeach; else: ?>

                  <div class="py-6 text-center text-xs text-slate-400">

                    <i data-lucide="check-circle" class="w-8 h-8 text-emerald-500 mx-auto mb-1"></i>

                    ไม่มีรายการค้างดำเนินการ

                  </div>

                <?php endif; ?>

              </div>

            </div>

          </div>



          <!-- ลิงก์โปรไฟล์ของผู้ดูแล -->

          <div class="flex items-center space-x-2 pl-2 border-l border-rose-100">

            <a href="profile.php" class="flex items-center space-x-2.5 p-1.5 hover:bg-rose-50/70 rounded-2xl transition border border-stone-200/80 bg-white" title="โปรไฟล์ของฉัน">

              <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-rose-500 to-pink-600 text-white flex items-center justify-center font-bold text-xs shadow-xs">

                <?php echo $initials; ?>

              </div>

              <div class="hidden sm:block text-left">

                <div class="text-xs font-semibold text-slate-800 hover:text-rose-600 transition"><?php echo htmlspecialchars($adminName); ?></div>

                <div class="text-[10px] text-emerald-600 font-semibold flex items-center gap-1">

                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> แอดมิน (Admin)

                </div>

              </div>

            </a>



            <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="p-2 text-rose-500 hover:bg-rose-50 rounded-2xl transition border border-transparent hover:border-rose-100" title="ออกจากระบบ">

              <i data-lucide="log-out" class="w-4 h-4"></i>

            </a>

          </div>



        </div>

      </div>

    </div>

  </header>



  <!-- Main Content Dashboard Container -->

  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

    <div class="space-y-6 sm:space-y-8">



      <!-- 🌟 Top Welcome & Fast Action Bar (Boutique Executive Hero) 🌟 -->
      <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-white via-rose-50/40 to-pink-50/30 border border-slate-200/80 rounded-3xl shadow-xs">
        <!-- Inner Ambient Glow Effect -->
        <div class="absolute -right-16 -top-16 w-72 h-72 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 w-72 h-72 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
          <div>
            <div class="flex items-center gap-2 flex-wrap mb-2.5">
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/90 px-3 py-1 rounded-full shadow-2xs">
                <i data-lucide="calendar" class="w-3.5 h-3.5 text-rose-500"></i>
                <span><?php echo date('d') . ' ' . $currMonthNameTh . ' ' . ($currYear + 543); ?></span>
              </span>
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
                <i data-lucide="<?php echo $timeIcon; ?>" class="w-3.5 h-3.5 text-amber-500"></i>
                <span><?php echo $timeBadge; ?></span>
              </span>
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-800 bg-emerald-50 border border-emerald-200 px-3 py-1 rounded-full">
                <span class="w-2 h-2 rounded-full bg-emerald-500 live-pulse-dot"></span>
                <span>ระบบออนไลน์ปกติ</span>
              </span>
            </div>

            <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
              <?php echo $timeGreeting; ?>, คุณ <?php echo htmlspecialchars($adminName); ?> <?php echo $timeEmoji ?? '👋'; ?>
            </h1>

            <!-- Dynamic Quick Summary Pills -->
            <div class="flex items-center gap-2 flex-wrap mt-3.5 pt-3 border-t border-slate-200/60 text-xs">
              <span class="text-slate-400 text-[11px] font-semibold uppercase tracking-wider">สถานะวันนี้:</span>
              <a href="manage_invoice.php?tab=verify" class="px-2.5 py-1 rounded-lg <?php echo $pendingSlipCount > 0 ? 'bg-amber-50 text-amber-900 border border-amber-200 font-bold hover:bg-amber-100' : 'bg-stone-100 text-slate-600'; ?> transition flex items-center gap-1">
                <i data-lucide="receipt" class="w-3.5 h-3.5 text-amber-600"></i>
                <span>สลิปรอตรวจ <?php echo $pendingSlipCount; ?></span>
              </a>
              <a href="admin_requests.php" class="px-2.5 py-1 rounded-lg <?php echo $pendingServices > 0 ? 'bg-rose-50 text-rose-900 border border-rose-200 font-bold hover:bg-rose-100' : 'bg-stone-100 text-slate-600'; ?> transition flex items-center gap-1">
                <i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-600"></i>
                <span>แจ้งซ่อมค้าง <?php echo $pendingServices; ?></span>
              </a>
              <a href="manage_room.php?tab=room_changes" class="px-2.5 py-1 rounded-lg <?php echo $pendingRoomChanges > 0 ? 'bg-blue-50 text-blue-900 border border-blue-200 font-bold hover:bg-blue-100' : 'bg-stone-100 text-slate-600'; ?> transition flex items-center gap-1">
                <i data-lucide="arrow-left-right" class="w-3.5 h-3.5 text-blue-600"></i>
                <span>ขอย้ายห้อง <?php echo $pendingRoomChanges; ?></span>
              </a>
              <a href="manage_room.php?tab=moveouts" class="px-2.5 py-1 rounded-lg <?php echo $pendingMoveOuts > 0 ? 'bg-purple-50 text-purple-900 border border-purple-200 font-bold hover:bg-purple-100' : 'bg-stone-100 text-slate-600'; ?> transition flex items-center gap-1">
                <i data-lucide="package-minus" class="w-3.5 h-3.5 text-purple-600"></i>
                <span>แจ้งย้ายออก <?php echo $pendingMoveOuts; ?></span>
              </a>
            </div>
          </div>


        </div>
      </div>



      <!-- Alert Box -->

      <?php if (!empty($alertMessage)): ?>

        <div><?php echo $alertMessage; ?></div>

      <?php endif; ?>



      <!-- 🌟 4 Key Stats Cards (Warm Rose & Linen Boutique Metrics) 🌟 -->

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">



        <!-- 1. ยอดรายรับเดือนนี้ -->
        <a href="manage_invoice.php" class="stat-card-modern bg-white p-5 sm:p-6 rounded-3xl border border-emerald-200/90 shadow-xs hover:border-emerald-400 group block">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider group-hover:text-slate-800 transition">รายรับรวม (เดือนนี้)</span>
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-50 to-emerald-100/60 text-emerald-600 border border-emerald-200 group-hover:scale-110 transition-transform flex items-center justify-center shrink-0 shadow-2xs">
              <i data-lucide="wallet" class="w-5 h-5"></i>
            </div>
          </div>
          <div class="mt-3.5">
            <div class="text-2xl sm:text-3xl font-number font-extrabold text-slate-900 group-hover:text-emerald-600 transition tracking-tight">
              ฿<?php echo number_format($revenue, 2); ?>
            </div>
            <div class="flex items-center gap-1.5 text-xs text-emerald-700 font-semibold mt-2.5">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              <span>รอบเดือน <?php echo $currMonthNameTh; ?></span>
            </div>
          </div>
        </a>



        <!-- 2. ยอดค้างชำระ & สลิปรอตรวจ -->
        <a href="manage_invoice.php?tab=verify" class="stat-card-modern bg-white p-5 sm:p-6 rounded-3xl border border-rose-200/90 shadow-xs hover:border-rose-400 group block">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider group-hover:text-slate-800 transition">ยอดค้างชำระทั้งหมด</span>
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-rose-50 to-pink-100/60 text-rose-600 border border-rose-200 group-hover:scale-110 transition-transform flex items-center justify-center shrink-0 shadow-2xs">
              <i data-lucide="alert-circle" class="w-5 h-5"></i>
            </div>
          </div>
          <div class="mt-3.5">
            <div class="text-2xl sm:text-3xl font-number font-extrabold tracking-tight <?php echo ($unpaidAmount > 0) ? 'text-rose-600' : 'text-slate-800'; ?> transition">
              ฿<?php echo number_format($unpaidAmount, 2); ?>
            </div>
            <div class="mt-2.5 flex items-center gap-1.5 text-xs">
              <?php if($pendingSlipCount > 0): ?>
                <span class="text-amber-900 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200 font-bold flex items-center gap-1">
                  <i data-lucide="clock" class="w-3 h-3 text-amber-600"></i> รอตรวจ <?php echo $pendingSlipCount; ?> สลิป
                </span>
              <?php else: ?>
                <span class="text-slate-400">ไม่มีสลิปค้างตรวจสอบ</span>
              <?php endif; ?>
            </div>
          </div>
        </a>



        <!-- 3. สถานะห้องพัก (Occupancy Rate & Progress) -->
        <a href="manage_room.php" class="stat-card-modern bg-white p-5 sm:p-6 rounded-3xl border border-sky-200/90 shadow-xs hover:border-sky-400 group block">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider group-hover:text-slate-800 transition">อัตราการเข้าพัก</span>
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-sky-50 to-blue-100/60 text-sky-600 border border-sky-200 group-hover:scale-110 transition-transform flex items-center justify-center shrink-0 shadow-2xs">
              <i data-lucide="door-open" class="w-5 h-5"></i>
            </div>
          </div>
          <div class="mt-3.5">
            <div class="text-2xl sm:text-3xl font-number font-extrabold text-slate-900 group-hover:text-sky-600 transition tracking-tight">
              <?php echo $occupiedRooms; ?> <span class="text-sm font-normal text-slate-500 font-sans">/ <?php echo $totalRooms; ?> ห้อง</span>
            </div>
            <!-- Progress Bar -->
            <div class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden mt-2.5 border border-stone-200/80">
              <div class="bg-gradient-to-r from-sky-500 via-rose-400 to-pink-500 h-full rounded-full transition-all duration-500" style="width: <?php echo $occupancyRate; ?>%;"></div>
            </div>
            <div class="flex items-center justify-between text-[11px] text-slate-500 mt-1.5 font-semibold">
              <span class="text-emerald-600">ห้องว่าง <?php echo $vacantRooms; ?> ห้อง</span>
              <span class="font-number font-bold text-sky-600"><?php echo $occupancyRate; ?>%</span>
            </div>
          </div>
        </a>



        <!-- 4. งานซ่อมบำรุงและบริการ -->
        <a href="admin_requests.php" class="stat-card-modern bg-white p-5 sm:p-6 rounded-3xl border border-amber-200/90 shadow-xs hover:border-amber-400 group block">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-500 uppercase tracking-wider group-hover:text-slate-800 transition">งานแจ้งซ่อม & บริการ</span>
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-50 to-yellow-100/60 text-amber-600 border border-amber-200 group-hover:scale-110 transition-transform flex items-center justify-center shrink-0 shadow-2xs">
              <i data-lucide="wrench" class="w-5 h-5"></i>
            </div>
          </div>
          <div class="mt-3.5">
            <div class="text-2xl sm:text-3xl font-number font-extrabold text-slate-900 group-hover:text-amber-600 transition tracking-tight">
              <?php echo ($pendingServices + $completedServices); ?> <span class="text-sm font-normal text-slate-500 font-sans">งานทั้งหมด</span>
            </div>
            <div class="mt-2.5 flex items-center gap-1.5 text-xs flex-wrap">
              <?php if($pendingServices > 0): ?>
                <span class="text-rose-700 bg-rose-50 px-2.5 py-0.5 rounded-full border border-rose-200 font-bold flex items-center gap-1">
                  <i data-lucide="clock" class="w-3 h-3 text-rose-500"></i> รอทำ <?php echo $pendingServices; ?>
                </span>
              <?php else: ?>
                <span class="text-slate-500 bg-slate-50 px-2.5 py-0.5 rounded-full border border-slate-200 font-medium">
                  ไม่มีงานค้าง
                </span>
              <?php endif; ?>
              <?php if($completedServices > 0): ?>
                <span class="text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200 font-bold flex items-center gap-1">
                  <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-500"></i> เสร็จสิ้น <?php echo $completedServices; ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </a>



      </div>



      <!-- 🌟 Service Hub Grid: เมนูจัดการหลัก (Warm Rose & Linen Boutique Grid) 🌟 -->

      <div class="bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-xs">

        <div class="flex items-center justify-between pb-4 border-b border-rose-100/80 mb-6">

          <div class="flex items-center gap-3">

            <div class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 border border-rose-200 flex items-center justify-center shadow-2xs">

              <i data-lucide="grid" class="w-5 h-5"></i>

            </div>

            <div>

              <h2 class="font-display font-extrabold text-slate-900 text-lg">ศูนย์บริการและการจัดการ</h2>

              <p class="text-xs text-slate-500">เข้าถึงเมนูการบริหารหอพักได้อย่างรวดเร็วในคลิกเดียว</p>

            </div>

          </div>

          <span class="text-[11px] font-bold tracking-wider uppercase text-rose-700 bg-rose-50 border border-rose-200 px-3 py-1 rounded-full">8 บริการหลัก</span>

        </div>



        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 sm:gap-4">



          <!-- 1. ห้องพัก & สัญญา -->

          <a href="manage_room.php" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-sky-300 transition-all duration-200 flex flex-col items-center text-center group hover:-translate-y-1 hover:shadow-sm">

            <div class="w-12 h-12 rounded-2xl bg-sky-50 text-sky-600 border border-sky-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="door-open" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-sky-600 transition">ผังห้องพัก</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $totalRooms; ?> ห้อง</span>

          </a>



          <!-- 2. ใบแจ้งหนี้ & ตรวจสลิป -->

          <a href="manage_invoice.php" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-emerald-300 transition-all duration-200 flex flex-col items-center text-center group relative hover:-translate-y-1 hover:shadow-sm">

            <?php if($pendingSlipCount > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-amber-500 text-white text-[10px] font-bold rounded-full animate-pulse shadow-xs">

                <?php echo $pendingSlipCount; ?>

              </span>

            <?php endif; ?>

            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="receipt" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-emerald-600 transition">บิล & สลิป</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $pendingSlipCount > 0 ? "รอตรวจ {$pendingSlipCount}" : "เรียบร้อย"; ?></span>

          </a>



          <!-- 3. แจ้งซ่อมบำรุง -->

          <a href="admin_requests.php" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-amber-300 transition-all duration-200 flex flex-col items-center text-center group relative hover:-translate-y-1 hover:shadow-sm">

            <?php if($pendingServices > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-rose-500 text-white text-[10px] font-bold rounded-full animate-pulse shadow-xs">

                <?php echo $pendingServices; ?>

              </span>

            <?php endif; ?>

            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="wrench" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-amber-600 transition">แจ้งซ่อม</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $pendingServices > 0 ? "ค้าง {$pendingServices} งาน" : "ไม่มีงานค้าง"; ?></span>

          </a>



          <!-- 4. ต่ออายุสัญญา -->

          <a href="manage_room.php?tab=renewals" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-rose-300 transition-all duration-200 flex flex-col items-center text-center group relative hover:-translate-y-1 hover:shadow-sm">

            <?php if($pendingRenewals > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-rose-500 text-white text-[10px] font-bold rounded-full animate-pulse shadow-xs">

                <?php echo $pendingRenewals; ?>

              </span>

            <?php endif; ?>

            <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="refresh-cw" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition">ต่อสัญญา</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $pendingRenewals > 0 ? "รอ {$pendingRenewals} คำขอ" : "ตรวจสอบ"; ?></span>

          </a>



          <!-- 5. ผู้ร่วมอาศัย -->

          <a href="admin_coresidents.php" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-purple-300 transition-all duration-200 flex flex-col items-center text-center group relative hover:-translate-y-1 hover:shadow-sm">

            <?php if($pendingCoresidents > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-purple-500 text-white text-[10px] font-bold rounded-full animate-pulse shadow-xs">

                <?php echo $pendingCoresidents; ?>

              </span>

            <?php endif; ?>

            <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-600 border border-purple-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="users" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-purple-600 transition">ผู้ร่วมอาศัย</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $pendingCoresidents > 0 ? "รอ {$pendingCoresidents} คำขอ" : "จัดการสมาชิก"; ?></span>

          </a>



          <!-- 6. คำขอย้ายห้อง -->

          <a href="manage_room.php?tab=room_changes" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-blue-300 transition-all duration-200 flex flex-col items-center text-center group relative hover:-translate-y-1 hover:shadow-sm">

            <?php if($pendingRoomChanges > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-blue-500 text-white text-[10px] font-bold rounded-full animate-pulse shadow-xs">

                <?php echo $pendingRoomChanges; ?>

              </span>

            <?php endif; ?>

            <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 border border-blue-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="arrow-left-right" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-blue-600 transition">ขอย้ายห้อง</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $pendingRoomChanges > 0 ? "รอ {$pendingRoomChanges} คำขอ" : "ตรวจสอบ"; ?></span>

          </a>



          <!-- 7. แจ้งย้ายออก -->

          <a href="manage_room.php?tab=moveouts" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-rose-300 transition-all duration-200 flex flex-col items-center text-center group relative hover:-translate-y-1 hover:shadow-sm">

            <?php if($pendingMoveOuts > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-rose-500 text-white text-[10px] font-bold rounded-full animate-pulse shadow-xs">

                <?php echo $pendingMoveOuts; ?>

              </span>

            <?php endif; ?>

            <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 border border-rose-100 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="package-minus" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition">แจ้งย้ายออก</span>

            <span class="text-[10px] text-slate-500 mt-0.5"><?php echo $pendingMoveOuts > 0 ? "รอเคลียร์ {$pendingMoveOuts}" : "ไม่มีค้าง"; ?></span>

          </a>



          <!-- 8. รายงาน & สถิติ -->

          <a href="admin_reports.php" class="p-4 rounded-2xl bg-stone-50/70 hover:bg-white border border-slate-200/90 hover:shadow-xs hover:border-stone-400 transition-all duration-200 flex flex-col items-center text-center group hover:-translate-y-1 hover:shadow-sm">

            <div class="w-12 h-12 rounded-2xl bg-stone-100 text-stone-700 border border-stone-200 flex items-center justify-center mb-2.5 group-hover:scale-110 transition-transform shadow-2xs">

              <i data-lucide="bar-chart-3" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-stone-900 transition">รายงาน & สถิติ</span>

            <span class="text-[10px] text-slate-500 mt-0.5">วิเคราะห์ข้อมูล</span>

          </a>



        </div>

      </div>



      <!-- 🌟 Operational Grid: รายการที่ต้องดำเนินการด่วน & กระดานข่าวสารประกาศ 🌟 -->

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">



        <!-- ฝั่งซ้าย (7 Cols): รายการที่ต้องดำเนินการด่วน (Pending Action Queue) -->

        <div class="lg:col-span-7 bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-xs">

          <div class="flex items-center justify-between pb-4 border-b border-rose-100/80 mb-5">

            <div class="flex items-center gap-3">

              <div class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 border border-rose-200 flex items-center justify-center shadow-2xs">

                <i data-lucide="alert-triangle" class="w-5 h-5"></i>

              </div>

              <div>

                <h3 class="font-display font-extrabold text-slate-900 text-lg">รายการที่ต้องดำเนินการ</h3>

                <p class="text-xs text-slate-500">คำขอจากลูกบ้านและงานที่รอการอนุมัติ</p>

              </div>

            </div>

            <span id="dashAlertsCount" class="px-3 py-1 bg-rose-50 text-rose-700 text-xs font-bold rounded-full border border-rose-200">
              <?php echo count($alerts); ?> รายการ
            </span>
          </div>

          <!-- 🌟 Category Filter Tabs (Instant Filter without Page Reload) 🌟 -->
          <div class="flex items-center gap-1.5 overflow-x-auto pb-2 mb-4 scrollbar-none text-xs">
            <button type="button" onclick="filterDashAlerts('all', this)" class="alert-filter-btn px-3 py-1.5 rounded-xl font-bold transition cursor-pointer bg-slate-900 text-white shadow-xs shrink-0 flex items-center gap-1.5">
              <span>ทั้งหมด</span>
              <span class="px-1.5 py-0.2 bg-white/20 rounded-full text-[10px]"><?php echo count($alerts); ?></span>
            </button>
            <button type="button" onclick="filterDashAlerts('slip', this)" class="alert-filter-btn px-3 py-1.5 rounded-xl font-semibold transition cursor-pointer bg-stone-100 text-slate-600 hover:bg-stone-200 shrink-0 flex items-center gap-1.5">
              <span>🧾 ตรวจสลิป</span>
              <?php if($pendingSlipCount > 0): ?>
                <span class="px-1.5 py-0.2 bg-emerald-100 text-emerald-800 rounded-full text-[10px] font-bold"><?php echo $pendingSlipCount; ?></span>
              <?php endif; ?>
            </button>
            <button type="button" onclick="filterDashAlerts('repair', this)" class="alert-filter-btn px-3 py-1.5 rounded-xl font-semibold transition cursor-pointer bg-stone-100 text-slate-600 hover:bg-stone-200 shrink-0 flex items-center gap-1.5">
              <span>🔧 แจ้งซ่อม</span>
              <?php if($pendingServices > 0): ?>
                <span class="px-1.5 py-0.2 bg-amber-100 text-amber-800 rounded-full text-[10px] font-bold"><?php echo $pendingServices; ?></span>
              <?php endif; ?>
            </button>
            <button type="button" onclick="filterDashAlerts('room', this)" class="alert-filter-btn px-3 py-1.5 rounded-xl font-semibold transition cursor-pointer bg-stone-100 text-slate-600 hover:bg-stone-200 shrink-0 flex items-center gap-1.5">
              <span>🔄 ย้ายห้อง/ออก</span>
              <?php $roomActions = $pendingRoomChanges + $pendingRenewals + $pendingMoveOuts; if($roomActions > 0): ?>
                <span class="px-1.5 py-0.2 bg-rose-100 text-rose-800 rounded-full text-[10px] font-bold"><?php echo $roomActions; ?></span>
              <?php endif; ?>
            </button>
            <button type="button" onclick="filterDashAlerts('coresident', this)" class="alert-filter-btn px-3 py-1.5 rounded-xl font-semibold transition cursor-pointer bg-stone-100 text-slate-600 hover:bg-stone-200 shrink-0 flex items-center gap-1.5">
              <span>👥 สมาชิก</span>
              <?php if($pendingCoresidents > 0): ?>
                <span class="px-1.5 py-0.2 bg-purple-100 text-purple-800 rounded-full text-[10px] font-bold"><?php echo $pendingCoresidents; ?></span>
              <?php endif; ?>
            </button>
          </div>

          <div id="dashAlertsList" class="space-y-3.5">
            <?php if(count($alerts) > 0): foreach($alerts as $item): 
              $isSr = (($item['type'] ?? '') === 'service_request');
              $alertCat = 'other';
              if (($item['type'] ?? '') === 'slip') $alertCat = 'slip';
              elseif (($item['type'] ?? '') === 'service_request') $alertCat = 'repair';
              elseif (in_array(($item['type'] ?? ''), ['room_change', 'move_out', 'renewal'])) $alertCat = 'room';
              elseif (($item['type'] ?? '') === 'coresident') $alertCat = 'coresident';
            ?>
              <div id="dash-item-<?php echo $item['id']; ?>" data-category="<?php echo $alertCat; ?>" class="dash-alert-card p-4 rounded-2xl border border-slate-200/90 bg-white hover:bg-rose-50/20 hover:border-rose-300 shadow-2xs transition-all flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                <!-- คลิกในกรอบเพื่อดูรายละเอียด -->
                <div class="flex items-start gap-3 flex-1 cursor-pointer group/card" onclick="openAlertDetail('<?php echo $item['id']; ?>')">
                  <div class="w-10 h-10 rounded-xl bg-white border border-stone-200 text-slate-700 flex items-center justify-center shrink-0 shadow-2xs mt-0.5 group-hover/card:scale-105 group-hover/card:border-rose-300 transition">
                    <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4 <?php echo $item['color']; ?>"></i>
                  </div>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                      <span class="font-bold text-slate-900 text-sm group-hover/card:text-rose-600 transition"><?php echo htmlspecialchars($item['title']); ?></span>
                      <span class="px-2 py-0.5 rounded-full font-bold text-[10px] border <?php echo $item['badgeColor']; ?>"><?php echo $item['badge']; ?></span>
                    </div>
                    <p class="text-slate-600 mt-1 line-clamp-1"><?php echo htmlspecialchars($item['message']); ?></p>
                    <div class="flex items-center gap-3 flex-wrap mt-1">
                      <?php if(!empty($item['time'])): ?>
                        <span class="text-[10px] text-slate-600 font-mono flex items-center gap-1 font-semibold">
                          <i data-lucide="clock" class="w-3 h-3 text-slate-400"></i> แจ้งเมื่อ: <b><?php echo date("d/m/Y H:i", strtotime($item['time'])); ?> น.</b>
                        </span>
                      <?php endif; ?>
                      <?php if(!empty($item['completedAt'])): ?>
                        <span class="text-[10px] text-emerald-800 font-mono bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200 font-bold flex items-center gap-1">
                          <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-600"></i> ส่งมอบ: <b><?php echo date("d/m/Y H:i", strtotime($item['completedAt'])); ?> น.</b>
                        </span>
                      <?php endif; ?>
                      <?php if($isSr && !empty($item['requestImage'])): ?>
                        <span onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($item['requestImage']); ?>', 'รูปปัญหาจากลูกบ้าน - ห้อง <?php echo htmlspecialchars($item['roomNumber'] ?? ''); ?>')" class="inline-flex items-center gap-1 text-[10px] text-amber-700 font-bold bg-amber-50 hover:bg-amber-100 border border-amber-200 px-2 py-0.5 rounded-md cursor-pointer transition shadow-2xs">
                          <i data-lucide="image" class="w-3 h-3 text-amber-600"></i> ดูรูปที่แนบ
                        </span>
                      <?php elseif(!empty($item['attachedImage'])): ?>
                        <span onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($item['attachedImage']); ?>', 'เอกสาร/หลักฐานแนบ - <?php echo htmlspecialchars($item['title'] ?? ''); ?>')" class="inline-flex items-center gap-1 text-[10px] text-rose-700 font-bold bg-rose-50 hover:bg-rose-100 border border-rose-200 px-2 py-0.5 rounded-md cursor-pointer transition shadow-2xs">
                          <i data-lucide="image" class="w-3 h-3 text-rose-600"></i> ดูรูปที่แนบ
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                  <button type="button" onclick="event.stopPropagation(); dismissNotif(event, '<?php echo $item['id']; ?>')" class="px-3 py-2 rounded-xl text-slate-500 hover:text-emerald-700 hover:bg-emerald-50 border border-slate-200 hover:border-emerald-300 text-xs font-semibold transition flex items-center gap-1.5 cursor-pointer" title="ทำเครื่องหมายว่าอ่านแล้ว">
                    <i data-lucide="check" class="w-3.5 h-3.5"></i>
                    <span class="hidden sm:inline">อ่านแล้ว</span>
                  </button>

                  <a href="<?php echo $item['link']; ?>" onclick="markNotifReadBeacon('<?php echo $item['id']; ?>')" class="px-4 py-2 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1 shadow-xs active:scale-95">
                    <span><?php echo $item['btnText'] ?? 'ดำเนินการ'; ?></span>
                    <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
              <div id="dashAlertsFilterEmpty" class="hidden py-10 text-center text-slate-400">
                <div class="w-12 h-12 rounded-2xl bg-stone-100 text-slate-400 flex items-center justify-center mx-auto mb-2.5">
                  <i data-lucide="inbox" class="w-6 h-6"></i>
                </div>
                <h5 class="font-bold text-slate-700 text-sm">ไม่มีรายการในหมวดหมู่นี้</h5>
                <p class="text-xs text-slate-400 mt-0.5">เลือกหมวดหมู่อื่นเพื่อดูรายการค้าง</p>
              </div>
            <?php else: ?>

              <div class="py-12 text-center text-slate-400">

                <div class="w-14 h-14 rounded-full bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center justify-center mx-auto mb-3 shadow-2xs">

                  <i data-lucide="check-circle-2" class="w-7 h-7"></i>

                </div>

                <h4 class="font-bold text-slate-800 text-base">ยอดเยี่ยม! ไม่มีรายการค้างที่ต้องดำเนินการ</h4>

                <p class="text-xs text-slate-500 mt-1">คำขอและเอกสารทั้งหมดได้รับการอนุมัติเรียบร้อยแล้ว</p>

              </div>

            <?php endif; ?>

          </div>

        </div>



        <!-- ฝั่งขวา (5 Cols): ประกาศข่าวสารปัจจุบัน & ปุ่มเขียนประกาศ -->

        <div class="lg:col-span-5 bg-white p-6 sm:p-8 rounded-3xl border border-slate-200 shadow-xs">

          <div class="flex items-center justify-between pb-4 border-b border-rose-100/80 mb-5">

            <div class="flex items-center gap-3">

              <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 border border-amber-200 flex items-center justify-center shadow-2xs">

                <i data-lucide="megaphone" class="w-5 h-5"></i>

              </div>

              <div>

                <h3 class="font-display font-extrabold text-slate-900 text-lg">ประกาศข่าวสาร</h3>

                <p class="text-xs text-slate-500">สื่อสารถึงผู้เช่าทั้งหมด</p>

              </div>

            </div>

            <button type="button" onclick="openAddAnnouncementModal()" class="px-3 py-1.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1 cursor-pointer active:scale-95">

              <i data-lucide="plus" class="w-3.5 h-3.5"></i>

              <span>เขียนประกาศ</span>

            </button>

          </div>



          <!-- Announcement List -->

          <div class="space-y-3.5 max-h-[460px] overflow-y-auto pr-1">

            <?php if(count($announcements) > 0): foreach($announcements as $ann): ?>

              <?php

                $annType = $ann['type'] ?? 'info';

                $annEmoji = ($annType === 'warning') ? '🟠' : (($annType === 'success') ? '🟢' : '🔵');

                $annId = $ann['announcementId'] ?? ($ann['id'] ?? 0);

                $annTitle = $ann['title'] ?? '';

                $annMsg = $ann['message'] ?? ($ann['content'] ?? '');

                $annDate = $ann['created_at'] ?? ($ann['createdAt'] ?? date('Y-m-d H:i:s'));

                $annAuthor = $ann['author_name'] ?? ($ann['author'] ?? 'ผู้ดูแล');

                $annPayload = [

                    'id' => $annId,

                    'announcementId' => $annId,

                    'title' => $annTitle,

                    'message' => $annMsg,

                    'type' => $annType

                ];

              ?>

              <div class="p-4 rounded-2xl border border-slate-200/90 bg-white hover:bg-amber-50/20 hover:border-amber-300 shadow-2xs transition-all space-y-2 group shadow-2xs">

                <div class="flex items-start justify-between gap-2">

                  <div class="flex items-center gap-2">

                    <span class="text-sm"><?php echo $annEmoji; ?></span>

                    <h4 class="font-bold text-slate-900 text-sm group-hover:text-rose-600 transition"><?php echo htmlspecialchars($annTitle); ?></h4>

                  </div>

                  <div class="flex items-center gap-1 shrink-0">

                    <button type="button"

                            onclick='openEditAnnouncementModal(<?php echo json_encode($annPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)'

                            class="p-1 text-slate-400 hover:text-slate-700 hover:bg-stone-100 rounded-lg transition cursor-pointer"

                            title="แก้ไขประกาศ">

                      <i data-lucide="pencil" class="w-3.5 h-3.5"></i>

                    </button>

                    <form action="" method="POST" class="inline" onsubmit="return confirm('ยืนยันลบประกาศนี้?');">

                      <input type="hidden" name="action" value="delete_announcement">

                      <input type="hidden" name="id" value="<?php echo $annId; ?>">

                      <button type="submit" class="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition cursor-pointer" title="ลบประกาศ">

                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>

                      </button>

                    </form>

                  </div>

                </div>



                <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-line"><?php echo htmlspecialchars($annMsg); ?></p>



                <div class="flex items-center justify-between pt-2 border-t border-stone-200/60 text-[10px] text-slate-400">

                  <span>ผู้ประกาศ: <?php echo htmlspecialchars($annAuthor); ?></span>

                  <span><?php echo date('d/m/Y H:i', strtotime($annDate)); ?> น.</span>

                </div>

              </div>

            <?php endforeach; else: ?>

              <div class="py-12 text-center text-slate-400">

                <i data-lucide="bell-off" class="w-10 h-10 mx-auto mb-2 text-slate-300"></i>

                <p class="text-xs">ยังไม่มีประกาศข่าวสารในขณะนี้</p>

                <button type="button" onclick="openAddAnnouncementModal()" class="mt-3 text-xs text-rose-600 font-bold hover:underline">

                  + เพิ่มประกาศแรกตอนนี้

                </button>

              </div>

            <?php endif; ?>

          </div>

        </div>



      </div>



    </div>

  </main>



  <!-- 🌟 MODAL: สร้างประกาศข่าวสารใหม่ (Warm Rose & Linen Style) 🌟 -->

  <div id="addAnnouncementModal" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4" onclick="if(event.target === this) closeAddAnnouncementModal()">

    <div class="bg-white rounded-3xl max-w-2xl w-full overflow-hidden shadow-2xl modal-enter border border-rose-100" onclick="event.stopPropagation()">

      <div class="p-5 border-b border-rose-100 flex items-center justify-between bg-rose-50/30">

        <div class="flex items-center gap-3">

          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-600 text-white flex items-center justify-center shadow-sm">

            <i data-lucide="megaphone" class="w-5 h-5"></i>

          </div>

          <div>

            <h3 class="font-display font-extrabold text-slate-900 text-base">สร้างประกาศข่าวสารใหม่</h3>

            <p class="text-xs text-slate-500">ส่งตรงถึงหน้าแดชบอร์ดของผู้เช่าทุกคน</p>

          </div>

        </div>

        <button type="button" onclick="closeAddAnnouncementModal()" class="text-slate-400 hover:text-slate-700 hover:bg-stone-100 p-1.5 rounded-xl transition cursor-pointer">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="p-6 space-y-4">
        <input type="hidden" name="action" value="create_announcement">
        <input type="hidden" name="add_announcement" value="1">

        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">หัวข้อประกาศ <span class="text-rose-500">*</span></label>

          <input type="text" name="title" required placeholder="เช่น แจ้งกำหนดการล้างแท้งค์น้ำประจำปี" class="w-full px-4 py-2.5 text-sm bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:border-rose-400 focus:bg-white transition text-slate-800 placeholder-slate-400">

        </div>



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">เนื้อหาประกาศ <span class="text-rose-500">*</span></label>

          <textarea name="message" required rows="4" placeholder="ระบุรายละเอียด วันเวลา หรือข้อควรปฏิบัติสำหรับลูกบ้าน..." class="w-full px-4 py-2.5 text-sm bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:border-rose-400 focus:bg-white transition text-slate-800 placeholder-slate-400 resize-none leading-relaxed"></textarea>

        </div>



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">ระดับความสำคัญ</label>

          <input type="hidden" name="type" id="addAnnouncementTypeInput" value="info">

          <div class="grid grid-cols-3 gap-2" id="addAnnouncementTypePills">

            <button type="button" onclick="selectAddAnnouncementType('info')" id="btnAddAnnInfo"

                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs">

              <span class="text-base">🔵</span>

              <span class="text-xs">ทั่วไป</span>

            </button>

            <button type="button" onclick="selectAddAnnouncementType('warning')" id="btnAddAnnWarn"

                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white hover:border-amber-400 text-stone-700 font-semibold">

              <span class="text-base">🟠</span>

              <span class="text-xs">แจ้งเตือน</span>

            </button>

            <button type="button" onclick="selectAddAnnouncementType('success')" id="btnAddAnnSucc"

                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white hover:border-emerald-400 text-stone-700 font-semibold">

              <span class="text-base">🟢</span>

              <span class="text-xs">ข่าวดี</span>

            </button>

          </div>

        </div>



        <div class="pt-3 border-t border-rose-100 flex items-center justify-end gap-2.5">

          <button type="button" onclick="closeAddAnnouncementModal()" class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-stone-100 rounded-xl transition cursor-pointer">

            ยกเลิก

          </button>

          <button type="submit" name="create_announcement" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-xl text-xs font-bold shadow-sm shadow-rose-600/25 transition flex items-center gap-1.5 cursor-pointer active:scale-95">

            <i data-lucide="send" class="w-3.5 h-3.5"></i>

            <span>เผยแพร่ประกาศ</span>

          </button>

        </div>

      </form>

    </div>

  </div>



  <!-- 🌟 MODAL: แก้ไขประกาศข่าวสาร (Warm Rose & Linen Style) 🌟 -->

  <div id="editAnnouncementModal" class="hidden fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4" onclick="if(event.target === this) closeEditAnnouncementModal()">

    <div class="bg-white rounded-3xl max-w-2xl w-full overflow-hidden shadow-2xl modal-enter border border-rose-100" onclick="event.stopPropagation()">

      <div class="p-5 border-b border-rose-100 flex items-center justify-between bg-rose-50/30">

        <div class="flex items-center gap-3">

          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-amber-500 to-rose-500 text-white flex items-center justify-center shadow-sm">

            <i data-lucide="pencil" class="w-5 h-5"></i>

          </div>

          <div>

            <h3 class="font-display font-extrabold text-slate-900 text-base">แก้ไขประกาศข่าวสาร</h3>

            <p class="text-xs text-slate-500">ปรับปรุงรายละเอียดเนื้อหาประกาศ</p>

          </div>

        </div>

        <button type="button" onclick="closeEditAnnouncementModal()" class="text-slate-400 hover:text-slate-700 hover:bg-stone-100 p-1.5 rounded-xl transition cursor-pointer">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="p-6 space-y-4">
        <input type="hidden" name="action" value="update_announcement">
        <input type="hidden" name="edit_announcement" value="1">
        <input type="hidden" name="id" id="editAnnouncementId">
        <input type="hidden" name="announcement_id" id="editAnnouncementId2">



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">หัวข้อประกาศ <span class="text-rose-500">*</span></label>

          <input type="text" name="title" id="editAnnouncementTitle" required class="w-full px-4 py-2.5 text-sm bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:border-rose-400 focus:bg-white transition text-slate-800">

        </div>



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">เนื้อหาประกาศ <span class="text-rose-500">*</span></label>

          <textarea name="message" id="editAnnouncementMessage" required rows="4" class="w-full px-4 py-2.5 text-sm bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:border-rose-400 focus:bg-white transition text-slate-800 resize-none leading-relaxed"></textarea>

        </div>



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1.5">ระดับความสำคัญ</label>

          <input type="hidden" name="type" id="editAnnouncementType" value="info">

          <div class="grid grid-cols-3 gap-2" id="editAnnouncementTypePills">

            <button type="button" onclick="selectEditAnnouncementType('info')" id="btnEditAnnInfo"

                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs">

              <span class="text-base">🔵</span>

              <span class="text-xs">ทั่วไป</span>

            </button>

            <button type="button" onclick="selectEditAnnouncementType('warning')" id="btnEditAnnWarn"

                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white hover:border-amber-400 text-stone-700 font-semibold">

              <span class="text-base">🟠</span>

              <span class="text-xs">แจ้งเตือน</span>

            </button>

            <button type="button" onclick="selectEditAnnouncementType('success')" id="btnEditAnnSucc"

                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white hover:border-emerald-400 text-stone-700 font-semibold">

              <span class="text-base">🟢</span>

              <span class="text-xs">ข่าวดี</span>

            </button>

          </div>

        </div>



        <div class="pt-3 border-t border-rose-100 flex items-center justify-end gap-2.5">

          <button type="button" onclick="closeEditAnnouncementModal()" class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-stone-100 rounded-xl transition cursor-pointer">

            ยกเลิก

          </button>

          <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-xl text-xs font-bold shadow-sm shadow-rose-600/25 transition flex items-center gap-1.5 cursor-pointer active:scale-95">

            <i data-lucide="check" class="w-3.5 h-3.5"></i>

            <span>บันทึกการแก้ไข</span>

          </button>

        </div>

      </form>

    </div>

  </div>



    <!-- 🌟 MODAL ขยายดูรูปภาพเต็มจอ (Image Preview Modal) 🌟 -->
  <div id="imagePreviewModal" class="hidden fixed inset-0 z-[60] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4 modal-enter" onclick="closeImageModal()">
      <div class="relative max-w-2xl w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">
          <button type="button" onclick="closeImageModal()" class="absolute -top-12 right-0 text-white/80 hover:text-rose-400 p-2 transition flex items-center gap-2 font-bold text-sm cursor-pointer">
              ปิดรูปภาพ <i data-lucide="x-circle" class="w-6 h-6"></i>
          </button>
          <div id="imageTitle" class="absolute -top-10 left-0 text-white font-bold text-sm bg-black/60 px-3 py-1 rounded-full backdrop-blur-md"></div>
          <img id="previewImage" src="" alt="Image Preview" class="max-h-[80vh] max-w-full rounded-2xl shadow-2xl object-contain border-2 border-white/20 bg-black/60">
      </div>
  </div>

  <!-- 🌟 MODAL จอใหญ่: รายละเอียดคำร้องแจ้งซ่อมแบบเต็ม แบ่ง 2 กรอบชัดเจน (ผู้เช่า VS ช่าง/แม่บ้าน) 🌟 -->
  <div id="requestDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeRequestDetailModal()">
      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col" onclick="event.stopPropagation()">
          
          <!-- Modal Top Header -->
          <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/90 shrink-0">
              <div class="flex items-center gap-2.5 flex-wrap">
                  <span class="text-xs font-bold px-3 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">
                      ห้อง <span id="dash_detail_room_number">-</span>
                  </span>
                  <span id="dash_detail_req_code" class="text-xs font-mono font-bold text-slate-600 bg-white px-2.5 py-1 rounded-xl border border-slate-200 shadow-2xs">
                      REQ-0000
                  </span>
                  <div id="dash_detail_status_badge" class="text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5 shadow-2xs"></div>
              </div>
              <div class="flex items-center gap-2">
                  <button type="button" onclick="closeRequestDetailModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">
                      <i data-lucide="x" class="w-5 h-5"></i>
                  </button>
              </div>
          </div>

          <!-- Body: 2 Side-by-Side Large Frames -->
          <div class="p-5 sm:p-6 md:p-8 space-y-6 overflow-y-auto flex-1 min-h-0">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
                  
                  <!-- ======================================================== -->
                  <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลของผู้เช่า (Tenant Frame)         -->
                  <!-- ======================================================== -->
                  <div class="p-6 rounded-3xl bg-amber-50/40 border-2 border-amber-200/80 flex flex-col justify-between space-y-6 shadow-sm">
                      <div class="space-y-5">
                          <!-- Header กรอบข้อมูลผู้เช่า -->
                          <div class="flex items-center justify-between pb-3 border-b border-amber-200/70">
                              <span class="text-xs font-bold text-amber-900 flex items-center gap-2">
                                  <div class="w-7 h-7 rounded-lg bg-amber-500 text-white flex items-center justify-center shadow-2xs">
                                      <i data-lucide="file-text" class="w-4 h-4"></i>
                                  </div>
                                  <span>ข้อมูลคำขอจากผู้เช่า (Tenant Request)</span>
                              </span>
                              <span class="text-[11px] font-bold text-amber-700 bg-amber-100/80 px-2.5 py-0.5 rounded-full border border-amber-200">
                                  ฝั่งผู้เช่า
                              </span>
                          </div>

                          <!-- หมวดหมู่และประเภทงาน -->
                          <div>
                              <div class="flex items-center justify-between gap-2 flex-wrap mb-2">
                                  <h3 class="text-base sm:text-lg font-bold text-slate-900" id="dash_detail_category">-</h3>
                                  <div class="flex items-center gap-1.5 flex-wrap">
                                      <span id="dash_detail_est_hours_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 shadow-2xs font-mono">⏱️ 2 ชม.</span>
                                      <span id="dash_detail_job_type_badge" class="px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs">งานช่าง</span>
                                      <span id="dash_detail_key_badge" class="px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs">กุญแจ</span>
                                  </div>
                              </div>
                          </div>

                          <!-- ผู้เช่าผู้แจ้ง -->
                          <div class="bg-white p-4 rounded-2xl border border-amber-200/80 shadow-2xs flex items-center justify-between gap-3">
                              <div class="flex items-center gap-3">
                                  <div class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center font-bold text-base shadow-2xs">
                                      <i data-lucide="user" class="w-5 h-5"></i>
                                  </div>
                                  <div>
                                      <span class="text-[11px] text-slate-400 font-semibold block">ผู้เช่า / ผู้แจ้งปัญหา:</span>
                                      <span id="dash_detail_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</span>
                                      <div class="text-xs text-slate-500 font-mono mt-0.5" id="dash_detail_tenant_phone">-</div>
                                  </div>
                              </div>
                              <a id="dash_detail_tenant_call_btn" href="#" class="px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs border border-emerald-200">
                                  <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i> โทรหาผู้เช่า
                              </a>
                          </div>

                          <!-- รายละเอียดปัญหา / อาการที่พบ -->
                          <div>
                              <span class="text-xs font-bold text-amber-900 block mb-1.5">รายละเอียดปัญหา / อาการที่พบ:</span>
                              <div class="p-4 bg-white rounded-2xl border border-amber-200/80 shadow-2xs min-h-[90px]">
                                  <p class="text-xs sm:text-sm text-slate-700 whitespace-pre-line leading-relaxed font-normal" id="dash_detail_description">-</p>
                              </div>
                          </div>

                          <!-- วัน-เวลานัดหมายเข้าห้อง -->
                          <div id="dash_detail_appointment_box" class="p-3.5 bg-rose-50/80 border border-rose-200/90 rounded-2xl flex items-center justify-between gap-3 shadow-2xs">
                              <div class="flex items-center gap-2.5">
                                  <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-2xs">
                                      <i data-lucide="calendar-clock" class="w-4 h-4"></i>
                                  </div>
                                  <div>
                                      <span class="text-[10px] text-rose-700 font-semibold block">วัน-เวลาที่ผู้เช่าสะดวกให้นัดเข้าซ่อม:</span>
                                      <span id="dash_detail_preferred_text" class="font-bold text-indigo-950 text-xs sm:text-sm"></span>
                                  </div>
                              </div>
                          </div>

                          <!-- รูปภาพก่อนทำ (ปัญหาจากลูกบ้าน) -->
                          <div>
                              <span class="text-xs font-bold text-amber-900 block mb-1.5 flex items-center gap-1.5">
                                  <i data-lucide="image" class="w-3.5 h-3.5 text-amber-600"></i> รูปภาพปัญหาที่ลูกบ้านแนบมา:
                              </span>
                              <div id="dash_detail_before_img_container" class="min-h-[160px] flex items-center justify-center"></div>
                          </div>
                      </div>
                  </div>

                  <!-- ======================================================== -->
                  <!-- 🟩 กรอบที่ 2: ส่วนการปฏิบัติงานของช่าง/แม่บ้าน (Staff Frame) -->
                  <!-- ======================================================== -->
                  <div class="p-6 rounded-3xl bg-emerald-50/40 border-2 border-emerald-200/80 flex flex-col justify-between space-y-6 shadow-sm">
                      <div class="space-y-5">
                          <!-- Header กรอบช่าง/แม่บ้าน -->
                          <div class="flex items-center justify-between pb-3 border-b border-emerald-200/70">
                              <span class="text-xs font-bold text-emerald-900 flex items-center gap-2">
                                  <div class="w-7 h-7 rounded-lg bg-emerald-600 text-white flex items-center justify-center shadow-2xs">
                                      <i data-lucide="wrench" class="w-4 h-4"></i>
                                  </div>
                                  <span>ส่วนการจัดการ & บันทึกการทำงาน (Staff & Operations)</span>
                              </span>
                              <span id="dash_detail_staff_status_badge" class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100 text-emerald-800 border border-emerald-200 font-mono">
                                  มอบหมายแล้ว
                              </span>
                          </div>

                          <!-- กล่องข้อมูลพนักงานผู้รับผิดชอบ -->
                          <div id="dash_detail_staff_box" class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs flex items-center justify-between gap-3">
                              <div class="flex items-center gap-3">
                                  <div id="dash_detail_staff_avatar" class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-base shadow-2xs font-mono">
                                      S
                                  </div>
                                  <div>
                                      <span class="text-[11px] text-slate-400 font-semibold block">เจ้าหน้าที่ผู้รับผิดชอบ:</span>
                                      <div class="flex items-center gap-2 flex-wrap">
                                          <span id="dash_detail_staff_name" class="font-bold text-slate-900 text-sm sm:text-base">-</span>
                                          <span id="dash_detail_staff_role" class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200 font-mono">ช่างซ่อม</span>
                                      </div>
                                      <span id="dash_detail_staff_phone" class="font-mono text-xs text-slate-500 mt-0.5 block"></span>
                                  </div>
                              </div>
                              <div id="dash_detail_staff_call_box" class="flex items-center gap-2">
                                  <a id="dash_detail_staff_call_btn" href="#" class="px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs border border-emerald-200">
                                      <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i> โทรหาช่าง
                                  </a>
                              </div>
                          </div>

                          <!-- กล่องรอมอบหมายงาน (แสดงเมื่อยังไม่มีช่างรับงาน) -->
                          <div id="dash_detail_unassigned_box" class="hidden p-4 bg-amber-50 border-2 border-dashed border-amber-300 rounded-2xl text-center space-y-2">
                              <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mx-auto shadow-2xs">
                                  <i data-lucide="user-x" class="w-5 h-5"></i>
                              </div>
                              <div class="text-xs font-bold text-amber-900">ยังไม่ได้มอบหมายงานให้พนักงาน</div>
                              <p class="text-[11px] text-amber-700">สามารถคลิกปุ่มด้านล่างเพื่อไปหน้ามอบหมายงานให้ช่างหรือแม่บ้าน</p>
                              <a id="dash_detail_assign_link_inline" href="admin_requests.php" class="inline-flex items-center gap-1 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shadow-xs">
                                  <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> ไปมอบหมายงาน
                              </a>
                          </div>

                          <!-- ไทม์ไลน์และประวัติเวลาการทำงาน -->
                          <div class="space-y-2">
                              <span class="text-xs font-bold text-emerald-900 block flex items-center gap-1.5">
                                  <i data-lucide="history" class="w-3.5 h-3.5 text-emerald-600"></i> ลำดับเวลาการดำเนินงาน (Timeline):
                              </span>
                              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                  <div class="bg-white border border-amber-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">
                                      <span class="text-[10px] text-slate-400 font-semibold flex items-center gap-1"><i data-lucide="bell" class="w-3 h-3 text-amber-500"></i> 1. แจ้งเมื่อ</span>
                                      <div class="text-xs font-bold text-slate-900 mt-1 font-mono" id="dash_detail_created_date">-</div>
                                      <div class="text-[10px] text-amber-800 font-semibold mt-0.5" id="dash_detail_request_time"></div>
                                  </div>
                                  <div class="bg-white border border-blue-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">
                                      <span class="text-[10px] text-slate-400 font-semibold flex items-center gap-1"><i data-lucide="user-check" class="w-3 h-3 text-blue-500"></i> 2. รับงานเมื่อ / นัดหมาย</span>
                                      <div class="text-xs font-bold text-slate-900 mt-1 font-mono" id="dash_detail_timeline_pref_date">-</div>
                                      <div class="text-[10px] text-blue-800 font-semibold mt-0.5" id="dash_detail_timeline_pref_time"></div>
                                  </div>
                                  <div id="dash_detail_completed_box" class="bg-white border border-slate-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">
                                      <span class="text-[10px] text-slate-400 font-semibold flex items-center gap-1"><i data-lucide="check-circle" class="w-3 h-3 text-emerald-500"></i> 3. ส่งงาน/จบงาน</span>
                                      <div class="text-xs font-bold text-slate-900 mt-1 font-mono" id="dash_detail_completed_at">-</div>
                                      <div class="text-[10px] text-emerald-800 font-semibold mt-0.5" id="dash_detail_completed_label">ยังไม่เสร็จสิ้น</div>
                                  </div>
                              </div>
                          </div>

                          <!-- บันทึกหมายเหตุจากช่าง -->
                          <div id="dash_detail_staff_comment_box" class="hidden bg-amber-50/70 border-2 border-dashed border-amber-300 rounded-2xl p-4 shadow-2xs space-y-2">
                              <span class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                                  <i data-lucide="message-square-quote" class="w-3.5 h-3.5 text-amber-600"></i> หมายเหตุ/บันทึกผลงานจากช่าง/ผู้ปฏิบัติงาน:
                              </span>
                              <div id="dash_detail_staff_comment_text" class="text-xs sm:text-sm text-slate-800 bg-white p-3.5 rounded-xl border border-amber-200 leading-relaxed font-mono"></div>
                              <div class="flex items-center justify-between text-[10px] text-amber-800 font-medium">
                                  <span id="dash_detail_staff_comment_author"></span>
                                  <span id="dash_detail_staff_comment_time" class="font-mono"></span>
                              </div>
                          </div>

                          <!-- รูปภาพหลังทำ (ผลงานที่ส่งมอบ) -->
                          <div>
                              <span class="text-xs font-bold text-emerald-900 block mb-1.5 flex items-center gap-1.5">
                                  <i data-lucide="camera" class="w-3.5 h-3.5 text-emerald-600"></i> รูปถ่ายผลงานหลังทำ (ภาพส่งมอบงาน):
                              </span>
                              <div id="dash_detail_after_img_container" class="min-h-[160px] flex items-center justify-center"></div>
                          </div>

                          <!-- ส่วนข้อคิดเห็นจากผู้เช่า (Feedback) -->
                          <div id="dash_detail_comment_container" class="hidden bg-purple-50/70 border-2 border-purple-200 rounded-2xl p-4 shadow-2xs space-y-2">
                              <span class="text-xs font-bold text-purple-900 flex items-center gap-1.5">
                                  <i data-lucide="sparkles" class="w-3.5 h-3.5 text-purple-600"></i> ความคิดเห็นจากผู้เช่า (Tenant Review):
                              </span>
                              <div class="bg-white/90 p-3.5 rounded-xl border border-purple-100 text-xs sm:text-sm text-slate-700 leading-relaxed italic" id="dash_detail_comment_text">-</div>
                              <div id="dash_detail_comment_time" class="text-[10px] text-purple-700 mt-1 font-medium text-right"></div>
                          </div>
                      </div>
                  </div>

              </div>
          </div>

          <!-- Footer Actions -->
          <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between shrink-0">
              <button type="button" onclick="closeRequestDetailModal()" class="px-5 py-2.5 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs">
                  <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
              </button>
              <div id="dash_detail_modal_actions" class="flex items-center gap-2 flex-wrap"></div>
          </div>
      </div>
  </div>
  <!-- 🌟 MODAL: รายละเอียดคำขอทั่วไป (แจ้งย้ายออก, ขอย้ายห้อง, ต่อสัญญา, ตรวจสลิป, ผู้ร่วมอาศัย) 🌟 -->
  <div id="generalAlertDetailModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 backdrop-blur-sm p-4 flex items-center justify-center animate-fade-in" onclick="if(event.target === this) closeGeneralAlertDetailModal()">
      <div class="bg-white border border-stone-200/80 rounded-3xl shadow-2xl max-w-4xl w-full overflow-hidden flex flex-col max-h-[90vh] my-auto animate-scale-up" onclick="event.stopPropagation()">
          
          <!-- Header -->
          <div class="px-6 py-4.5 border-b border-stone-200/80 bg-gradient-to-r from-stone-50 via-white to-rose-50/30 flex items-center justify-between shrink-0">
              <div class="flex items-center gap-3">
                  <div id="gad_icon_container" class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 border border-rose-200 flex items-center justify-center shrink-0 shadow-2xs">
                      <i id="gad_icon" data-lucide="file-text" class="w-5 h-5"></i>
                  </div>
                  <div>
                      <div class="flex items-center gap-2 flex-wrap">
                          <h3 id="gad_title" class="font-bold text-slate-900 text-base sm:text-lg">รายละเอียดคำขอ</h3>
                          <span id="gad_badge" class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border bg-rose-50 text-rose-700 border-rose-200">สถานะ</span>
                      </div>
                      <p id="gad_subtitle" class="text-xs text-slate-500 mt-0.5">ข้อมูลคำขอและเอกสารแนบ</p>
                  </div>
              </div>
              <button type="button" onclick="closeGeneralAlertDetailModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-rose-50 hover:text-rose-600 flex items-center justify-center text-slate-400 transition cursor-pointer" title="ปิดหน้าต่าง">
                  <i data-lucide="x" class="w-4 h-4"></i>
              </button>
          </div>

          <!-- Body Content: 2-column Layout (แยกตีกรอบเป็นฝั่งๆ) -->
          <div class="p-5 sm:p-6 overflow-y-auto flex-1 space-y-5 bg-[#FAF9F6]">
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                  
                  <!-- 🟦 ฝั่งที่ 1: ข้อมูลผู้เช่า & รายละเอียดคำขอ -->
                  <div class="p-5 sm:p-6 rounded-3xl bg-white border border-stone-200/90 shadow-sm flex flex-col justify-between space-y-4">
                      <div>
                          <div class="flex items-center justify-between pb-3 border-b border-stone-100 mb-4">
                              <span class="text-xs font-bold text-slate-900 flex items-center gap-2">
                                  <div class="w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 border border-indigo-200 flex items-center justify-center shadow-2xs">
                                      <i data-lucide="user" class="w-4 h-4"></i>
                                  </div>
                                  <span>ข้อมูลผู้เช่า & เนื้อหาคำขอ</span>
                              </span>
                              <span id="gad_room_badge" class="px-2.5 py-0.5 rounded-xl text-xs font-bold bg-slate-100 text-slate-700 font-mono">ห้อง -</span>
                          </div>

                          <div class="space-y-3 text-xs text-slate-700">
                              <div class="p-3 bg-slate-50/80 rounded-2xl border border-slate-200/60 flex items-center justify-between">
                                  <span class="text-slate-500">ชื่อ-นามสกุล ผู้เช่า:</span>
                                  <span id="gad_tenant_name" class="font-bold text-slate-900 text-sm">-</span>
                              </div>
                              <div class="p-3 bg-slate-50/80 rounded-2xl border border-slate-200/60 flex items-center justify-between">
                                  <span class="text-slate-500">เบอร์โทรศัพท์:</span>
                                  <span id="gad_tenant_phone" class="font-bold font-mono text-slate-900">-</span>
                              </div>
                              <div class="p-3 bg-slate-50/80 rounded-2xl border border-slate-200/60 flex items-center justify-between">
                                  <span class="text-slate-500">วันเวลาที่ยื่นคำขอ:</span>
                                  <span id="gad_request_date" class="font-bold font-mono text-slate-900">-</span>
                              </div>

                              <!-- Custom Info Section 1 (เช่น วันที่ย้ายออก / ขอย้ายไปห้อง / ระยะเวลาต่อสัญญา) -->
                              <div id="gad_custom_info_box" class="space-y-2 pt-1"></div>

                              <!-- Description / Reason box -->
                              <div class="pt-2">
                                  <span class="text-[11px] font-bold text-slate-500 block mb-1">เหตุผลหรือข้อความประกอบคำขอ:</span>
                                  <div id="gad_reason_box" class="p-3.5 rounded-2xl bg-amber-50/40 border border-amber-200/70 text-slate-700 leading-relaxed text-xs whitespace-pre-line min-h-[60px]">
                                      -
                                  </div>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- 🟩 ฝั่งที่ 2: ข้อมูลการเงิน/สถานะ & เอกสารรูปภาพแนบ -->
                  <div class="p-5 sm:p-6 rounded-3xl bg-white border border-stone-200/90 shadow-sm flex flex-col justify-between space-y-4">
                      <div>
                          <div class="flex items-center justify-between pb-3 border-b border-stone-100 mb-4">
                              <span class="text-xs font-bold text-slate-900 flex items-center gap-2">
                                  <div class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center justify-center shadow-2xs">
                                      <i data-lucide="receipt" class="w-4 h-4"></i>
                                  </div>
                                  <span>ข้อมูลการเงิน & เอกสารแนบ</span>
                              </span>
                              <span id="gad_right_tag" class="text-[11px] font-bold text-emerald-700">หลักฐาน</span>
                          </div>

                          <!-- Custom Info Section 2 (เช่น ยอดเงินมัดจำ, บัญชีธนาคาร, ยอดบิล) -->
                          <div id="gad_financial_box" class="space-y-2 mb-4 text-xs"></div>

                          <!-- Attached Image / Slip Preview -->
                          <div>
                              <span class="text-xs font-bold text-slate-700 block mb-2 flex items-center gap-1.5">
                                  <i data-lucide="image" class="w-3.5 h-3.5 text-rose-500"></i> เอกสาร/รูปภาพที่แนบมา (คลิกดูภาพขยาย):
                              </span>
                              <div id="gad_image_container" class="min-h-[160px] flex items-center justify-center bg-stone-50 rounded-2xl border border-stone-200/80 p-2 overflow-hidden">
                                  <!-- Image rendered dynamically -->
                              </div>
                          </div>
                      </div>
                  </div>

              </div>
          </div>

          <!-- Footer Actions -->
          <div class="px-6 py-4 border-t border-stone-200/80 bg-white flex items-center justify-between shrink-0">
              <button type="button" onclick="closeGeneralAlertDetailModal()" class="px-5 py-2.5 bg-stone-100 hover:bg-stone-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs">
                  <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
              </button>
              <a id="gad_action_btn" href="#" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-md active:scale-95">
                  <span id="gad_action_btn_text">ดำเนินการ</span>
                  <i data-lucide="arrow-right" class="w-4 h-4"></i>
              </a>
          </div>

      </div>
  </div>

<!-- Mobile Bottom Navigation Bar (Warm Rose & Linen Style) -->

  <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-white/95 backdrop-blur-md border-t border-rose-100/80 py-2 px-3 z-40 flex items-center justify-around shadow-lg">

    <a href="admin_dashboard.php" class="flex flex-col items-center text-rose-600 text-[10px] font-bold">

      <i data-lucide="layout-dashboard" class="w-5 h-5 mb-0.5"></i>

      <span>หน้าหลัก</span>

    </a>

    <a href="manage_room.php" class="flex flex-col items-center text-slate-500 hover:text-slate-800 text-[10px] font-medium">

      <i data-lucide="door-open" class="w-5 h-5 mb-0.5"></i>

      <span>ห้องพัก</span>

    </a>

    <a href="manage_invoice.php" class="flex flex-col items-center text-slate-500 hover:text-slate-800 text-[10px] font-medium">

      <i data-lucide="receipt" class="w-5 h-5 mb-0.5"></i>

      <span>บิล & สลิป</span>

    </a>

    <a href="admin_requests.php" class="flex flex-col items-center text-slate-500 hover:text-slate-800 text-[10px] font-medium">

      <i data-lucide="wrench" class="w-5 h-5 mb-0.5"></i>

      <span>แจ้งซ่อม</span>

    </a>

    <a href="profile.php" class="flex flex-col items-center text-slate-500 hover:text-slate-800 text-[10px] font-medium">

      <i data-lucide="user" class="w-5 h-5 mb-0.5"></i>

      <span>โปรไฟล์</span>

    </a>

  </nav>



  <!-- Realtime Script and Modal Functions -->

  <script src="js/realtime-time.js"></script>

  <script>

    // Initialize Lucide Icons

    lucide.createIcons();



    // Active unread notification IDs

    let activeNotifIds = <?php echo json_encode(array_values(array_column($alerts, 'id'))); ?> || [];



    function updateBadgeCount() {

      const count = Math.max(0, activeNotifIds.length);



      // Bell badge in navbar

      const bellBadge = document.getElementById('bellBadge');

      if (bellBadge) {

        if (count > 0) {

          bellBadge.textContent = count;

          bellBadge.classList.remove('hidden');

        } else {

          bellBadge.classList.add('hidden');

        }

      }



      // Dropdown count badge

      const notifBadgeCount = document.getElementById('notifBadgeCount');

      if (notifBadgeCount) {

        notifBadgeCount.textContent = count + ' รายการ';

      }



      // "อ่านทั้งหมด" button

      const btnMarkAll = document.getElementById('btnMarkAllRead');

      if (btnMarkAll) {

        if (count > 0) {

          btnMarkAll.classList.remove('hidden');

        } else {

          btnMarkAll.classList.add('hidden');

        }

      }



      // Dashboard card count badge

      const dashAlertsCount = document.getElementById('dashAlertsCount');

      if (dashAlertsCount) {

        dashAlertsCount.textContent = count + ' รายการ';

      }



      // If count is 0, show empty state in dropdown

      const notifContainer = document.getElementById('notifListContainer');

      if (notifContainer && count === 0) {

        notifContainer.innerHTML = `

          <div class="py-6 text-center text-xs text-slate-400">

            <i data-lucide="check-circle" class="w-8 h-8 text-emerald-500 mx-auto mb-1"></i>

            ไม่มีรายการค้างดำเนินการ

          </div>

        `;

        lucide.createIcons();

      }



      // If count is 0, show empty state in dashboard card

      const dashList = document.getElementById('dashAlertsList');

      if (dashList && count === 0) {

        dashList.innerHTML = `

          <div id="dashEmptyState" class="py-12 text-center text-slate-400">

            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center mx-auto mb-3 shadow-2xs">

              <i data-lucide="check-circle" class="w-6 h-6"></i>

            </div>

            <p class="font-bold text-slate-700 text-sm">ยอดเยี่ยม! ไม่มีรายการค้างดำเนินการ</p>

            <p class="text-xs text-slate-400 mt-1">ระบบได้รับการอัปเดตและดำเนินการครบถ้วนแล้ว</p>

          </div>

        `;

        lucide.createIcons();

      }

    }



    function dismissNotif(event, notifId) {

      if (event) {

        event.preventDefault();

        event.stopPropagation();

      }



      // Remove from active array

      activeNotifIds = activeNotifIds.filter(id => id !== notifId);



      // Animate and remove from dropdown

      const itemEl = document.getElementById('notif-item-' + notifId);

      if (itemEl) {

        itemEl.style.transition = 'all 0.25s ease';

        itemEl.style.opacity = '0';

        itemEl.style.transform = 'translateX(20px)';

        setTimeout(() => {

          itemEl.remove();

          updateBadgeCount();

        }, 250);

      } else {

        updateBadgeCount();

      }



      // Animate and remove from dashboard card

      const dashEl = document.getElementById('dash-item-' + notifId);

      if (dashEl) {

        dashEl.style.transition = 'all 0.25s ease';

        dashEl.style.opacity = '0';

        dashEl.style.transform = 'translateX(20px)';

        setTimeout(() => {

          dashEl.remove();

          updateBadgeCount();

        }, 250);

      }



      // Send AJAX to server

      sendNotifRead(notifId);

    }



    function markNotifReadBeacon(notifId) {

      sendNotifRead(notifId);

    }



    function handleNotifClick(event, notifId, link) {

      if (event) {

        event.preventDefault();

      }

      sendNotifRead(notifId);

      setTimeout(() => {

        window.location.href = link;

      }, 50);

    }



    function sendNotifRead(notifId) {

      const url = 'admin_dashboard.php?action=mark_notif_read';

      const data = new URLSearchParams();

      data.append('notif_id', notifId);



      if (navigator.sendBeacon) {

        navigator.sendBeacon(url, data);

      } else {

        fetch(url, {

          method: 'POST',

          body: data,

          keepalive: true

        }).catch(err => console.error(err));

      }

    }



    function markAllNotifsAsRead(event) {

      if (event) {

        event.preventDefault();

        event.stopPropagation();

      }



      const idsToMark = [...activeNotifIds];

      if (idsToMark.length === 0) return;



      activeNotifIds = [];

      updateBadgeCount();



      const url = 'admin_dashboard.php?action=mark_all_notifs_read';

      const data = new URLSearchParams();

      data.append('notif_ids', JSON.stringify(idsToMark));



      if (navigator.sendBeacon) {

        navigator.sendBeacon(url, data);

      } else {

        fetch(url, {

          method: 'POST',

          body: data,

          keepalive: true

        }).catch(err => console.error(err));

      }

    }



    // Toggle Notifications Dropdown

    function toggleNotifications() {

      const dd = document.getElementById('notifDropdown');

      if (dd) {

        dd.classList.toggle('hidden');

      }

    }



    // Close notifications when clicked outside

    document.addEventListener('click', function(e) {

      const notifWrapper = document.querySelector('.notif-wrapper');

      const dd = document.getElementById('notifDropdown');

      if (notifWrapper && dd && !notifWrapper.contains(e.target)) {

        dd.classList.add('hidden');

      }

    });



    // Add Announcement Modal Functions

    function openAddAnnouncementModal() {

      const modal = document.getElementById('addAnnouncementModal');

      if (modal) {

        modal.classList.remove('hidden');

        lucide.createIcons();

      }

    }



    function closeAddAnnouncementModal() {

      const modal = document.getElementById('addAnnouncementModal');

      if (modal) {

        modal.classList.add('hidden');

      }

    }



    // Edit Announcement Modal Functions

    function openEditAnnouncementModal(ann) {

      const modal = document.getElementById('editAnnouncementModal');

      if (!modal) return;

      const annId = ann.id || ann.announcementId || '';
      document.getElementById('editAnnouncementId').value = annId;
      const elId2 = document.getElementById('editAnnouncementId2');
      if (elId2) elId2.value = annId;

      document.getElementById('editAnnouncementTitle').value = ann.title || '';

      document.getElementById('editAnnouncementMessage').value = ann.message || '';

      const type = ann.type || 'info';

      selectEditAnnouncementType(type);

      modal.classList.remove('hidden');

      lucide.createIcons();

    }



    function closeEditAnnouncementModal() {

      const modal = document.getElementById('editAnnouncementModal');

      if (modal) {

        modal.classList.add('hidden');

      }

    }


    // ข้อมูลงานแจ้งซ่อมสำหรับเปิด Modal จอใหญ่ทันที
    window.requestsData = <?php echo json_encode($indexedRequests ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.allAlertsData = <?php echo json_encode($allAlertsData ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // 🌟 ระบบกรองรายการที่ต้องดำเนินการทันที (Category Filter) 🌟
    function filterDashAlerts(cat, btn) {
        document.querySelectorAll('.alert-filter-btn').forEach(b => {
            b.className = 'alert-filter-btn px-3 py-1.5 rounded-xl font-semibold transition cursor-pointer bg-stone-100 text-slate-600 hover:bg-stone-200 shrink-0 flex items-center gap-1.5';
        });
        if (btn) {
            btn.className = 'alert-filter-btn px-3 py-1.5 rounded-xl font-bold transition cursor-pointer bg-slate-900 text-white shadow-xs shrink-0 flex items-center gap-1.5';
        }
        const cards = document.querySelectorAll('.dash-alert-card');
        let visibleCount = 0;
        cards.forEach(card => {
            const cardCat = card.getAttribute('data-category');
            if (cat === 'all' || cardCat === cat) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        const emptyNotice = document.getElementById('dashAlertsFilterEmpty');
        if (emptyNotice) {
            if (visibleCount === 0 && cards.length > 0) {
                emptyNotice.classList.remove('hidden');
            } else {
                emptyNotice.classList.add('hidden');
            }
        }
    }

    // 🌟 ฟังก์ชันเปิดดูรายละเอียดคำขอแบบรวมทุกรายการ (คลิกในกรอบหรือกดปุ่มดูรายละเอียด) 🌟
    function openAlertDetail(alertId) {
        if (!alertId) return;

        // หากเป็นงานแจ้งซ่อม/ทำความสะอาด ให้เรียกเปิด modal ของงานแจ้งซ่อมเดิม
        if (String(alertId).startsWith('req_')) {
            const reqId = parseInt(String(alertId).replace('req_', ''), 10);
            openRequestDetailModal(reqId);
            return;
        }

        const alertData = window.allAlertsData ? (window.allAlertsData[alertId] || window.allAlertsData[String(alertId)]) : null;
        if (!alertData) {
            console.warn("No alert data found for ID:", alertId);
            return;
        }

        const modal = document.getElementById('generalAlertDetailModal');
        if (!modal) return;

        // ข้อมูลส่วนหัว
        document.getElementById('gad_title').innerText = alertData.title || 'รายละเอียดคำขอ';
        const badgeEl = document.getElementById('gad_badge');
        badgeEl.innerText = alertData.badge || 'รอดำเนินการ';
        badgeEl.className = 'px-2.5 py-0.5 rounded-full text-[11px] font-bold border ' + (alertData.badgeColor || 'bg-rose-50 text-rose-700 border-rose-200');
        document.getElementById('gad_subtitle').innerText = alertData.subtitle || 'ข้อมูลคำขอและเอกสารแนบ';

        // ไอคอน
        const iconEl = document.getElementById('gad_icon');
        if (iconEl && alertData.icon) {
            iconEl.setAttribute('data-lucide', alertData.icon);
        }

        // ข้อมูลผู้เช่า & ห้องพัก
        document.getElementById('gad_room_badge').innerText = 'ห้อง ' + (alertData.roomNumber || '-');
        document.getElementById('gad_tenant_name').innerText = alertData.tenantName || '-';
        document.getElementById('gad_tenant_phone').innerText = alertData.tenantPhone || 'ไม่ระบุเบอร์โทร';
        document.getElementById('gad_request_date').innerText = alertData.requestDate || '-';
        document.getElementById('gad_reason_box').innerText = alertData.reason || 'ไม่มีข้อความเพิ่มเติม';

        // กรอบข้อมูลฝั่งซ้าย (Custom Fields)
        const customBox = document.getElementById('gad_custom_info_box');
        customBox.innerHTML = '';
        if (alertData.customFields && alertData.customFields.length > 0) {
            alertData.customFields.forEach(f => {
                const row = document.createElement('div');
                row.className = 'p-3 bg-slate-50/80 rounded-2xl border border-slate-200/60 flex items-center justify-between text-xs';
                row.innerHTML = `<span class="text-slate-500">${f.label}:</span><span class="font-bold text-slate-900">${f.value}</span>`;
                customBox.appendChild(row);
            });
        }

        // กรอบข้อมูลฝั่งขวา (Financial & Status Fields)
        const finBox = document.getElementById('gad_financial_box');
        finBox.innerHTML = '';
        if (alertData.financialFields && alertData.financialFields.length > 0) {
            alertData.financialFields.forEach(f => {
                const row = document.createElement('div');
                row.className = 'p-3 bg-rose-50/30 rounded-2xl border border-rose-100/80 flex items-center justify-between text-xs';
                row.innerHTML = `<span class="text-slate-500">${f.label}:</span><span class="font-bold text-slate-900 font-mono">${f.value}</span>`;
                finBox.appendChild(row);
            });
        }

        // กล่องรูปภาพแนบ / สลิป
        const imgContainer = document.getElementById('gad_image_container');
        if (alertData.attachedImage && String(alertData.attachedImage).trim() !== '') {
            imgContainer.innerHTML = `
                <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-stone-200 shadow-sm" onclick="openImageModal('${alertData.attachedImage}', '${alertData.title || 'เอกสารแนบ'}')">
                    <img src="${alertData.attachedImage}" class="w-full max-h-[220px] object-cover transition duration-300 group-hover:scale-105" alt="Attached Document">
                    <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
                        <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูรูปขนาดใหญ่
                    </div>
                </div>
            `;
        } else {
            imgContainer.innerHTML = `
                <div class="p-6 text-center text-slate-400">
                    <i data-lucide="image-off" class="w-8 h-8 mx-auto mb-1 text-slate-300"></i>
                    <span class="text-xs">ไม่มีรูปภาพหรือเอกสารแนบในคำขอนี้</span>
                </div>
            `;
        }

        // ปุ่มลิงก์ดำเนินการ
        const actionBtn = document.getElementById('gad_action_btn');
        const actionBtnText = document.getElementById('gad_action_btn_text');
        if (actionBtn && alertData.link) {
            actionBtn.href = alertData.link;
            actionBtnText.innerText = alertData.btnText || 'ไปหน้าดำเนินการ';
        }

        // แสดง Modal
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        if (window.lucide) {
            lucide.createIcons();
        }
    }

    function closeGeneralAlertDetailModal() {
        const modal = document.getElementById('generalAlertDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    function openRequestDetailModal(reqId) {
        const modal = document.getElementById('requestDetailModal');
        if (!modal) return;
        const data = window.requestsData ? (window.requestsData[reqId] || window.requestsData[String(reqId)]) : null;
        if (!data) {
            console.warn("No data for request ID:", reqId);
            return;
        }

        try {
            document.getElementById('dash_detail_room_number').innerText = (data.roomNumber || '-');
            document.getElementById('dash_detail_req_code').innerText = data.reqCode || '-';
            document.getElementById('dash_detail_category').innerText = data.category || '-';
            document.getElementById('dash_detail_description').innerText = data.description || '-';
            document.getElementById('dash_detail_tenant_name').innerText = data.tenantName || '-';
            document.getElementById('dash_detail_tenant_phone').innerText = data.tenantPhone || 'ไม่ระบุเบอร์โทร';

            const estHoursBadge = document.getElementById('dash_detail_est_hours_badge');
            if (estHoursBadge) {
                estHoursBadge.innerText = '⏱️ ' + (data.estimatedHours || 2) + ' ชม.';
            }

            // นัดหมายเข้าห้อง
            const apptBox = document.getElementById('dash_detail_appointment_box');
            const prefText = document.getElementById('dash_detail_preferred_text');
            if ((data.preferredDate && data.preferredDate !== '-') || data.preferredTime) {
                apptBox.classList.remove('hidden');
                let prefStr = 'นัดเข้า: ';
                if (data.preferredDate && data.preferredDate !== '-') {
                    prefStr += data.preferredDate + (data.preferredTime ? ' (' + data.preferredTime + ')' : '');
                } else {
                    prefStr += data.preferredTime;
                }
                prefText.innerText = prefStr;
            } else {
                apptBox.classList.add('hidden');
            }

            // ไทม์ไลน์
            document.getElementById('dash_detail_created_date').innerText = (data.requestDate || '-') + ' น.';
            document.getElementById('dash_detail_request_time').innerText = data.timeAgoRequest ? '(' + data.timeAgoRequest + ')' : '';

            const timePrefDate = document.getElementById('dash_detail_timeline_pref_date');
            const timePrefTime = document.getElementById('dash_detail_timeline_pref_time');
            if (data.assignedAt) {
                timePrefDate.innerText = data.assignedAt + ' น.';
                timePrefTime.innerText = data.timeAgoAssigned ? '(' + data.timeAgoAssigned + ')' : '';
            } else if (data.preferredDate && data.preferredDate !== '-') {
                timePrefDate.innerText = data.preferredDate;
                timePrefTime.innerText = data.preferredTime ? ('⏰ ' + data.preferredTime) : '';
            } else {
                timePrefDate.innerText = 'ตามคิวงาน';
                timePrefTime.innerText = '';
            }

            const completedAtEl = document.getElementById('dash_detail_completed_at');
            const completedLabelEl = document.getElementById('dash_detail_completed_label');
            const completedBox = document.getElementById('dash_detail_completed_box');
            if (data.status === 'Completed' && data.completedAt) {
                completedAtEl.innerText = data.completedAt + ' น.';
                let compLabel = data.duration ? ('⏱️ ใช้เวลา: ' + data.duration) : 'เสร็จสิ้นเรียบร้อย';
                completedLabelEl.innerText = compLabel;
                completedBox.className = 'bg-white border border-emerald-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs';
            } else if (data.status === 'Completed') {
                completedAtEl.innerText = '-';
                completedLabelEl.innerText = 'เสร็จสิ้นแล้ว (ไม่มีบันทึกเวลา)';
                completedBox.className = 'bg-white border border-emerald-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs';
            } else {
                completedAtEl.innerText = '-';
                completedLabelEl.innerText = 'ยังไม่เสร็จสิ้น';
                completedBox.className = 'bg-white border border-slate-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs';
            }

            // Tenant Call Link
            const callBtn = document.getElementById('dash_detail_tenant_call_btn');
            if (data.tenantPhone) {
                callBtn.href = 'tel:' + data.tenantPhone;
                callBtn.classList.remove('hidden');
            } else {
                callBtn.classList.add('hidden');
            }

            // Status Badge
            const statusBadge = document.getElementById('dash_detail_status_badge');
            if (data.status === 'Pending') {
                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-1.5 shadow-2xs';
                statusBadge.innerHTML = '<i data-lucide="inbox" class="w-3.5 h-3.5 text-amber-600"></i> รอมอบหมายงาน';
            } else if (data.status === 'In Progress') {
                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 flex items-center gap-1.5 shadow-2xs';
                statusBadge.innerHTML = '<i data-lucide="wrench" class="w-3.5 h-3.5 text-blue-600"></i> นิติบุคคลมอบหมายงานแล้ว';
            } else if (data.status === 'Pending Approval') {
                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-orange-50 text-orange-700 border border-orange-200 flex items-center gap-1.5 shadow-2xs';
                statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-orange-600"></i> รอแอดมินอนุมัติ';
            } else if (data.status === 'Completed') {
                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5 shadow-2xs';
                statusBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> เสร็จสิ้นแล้ว';
            }

            // Job Type Badge
            const jobBadge = document.getElementById('dash_detail_job_type_badge');
            if (data.isCleaning) {
                jobBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-teal-50 text-teal-700 border border-teal-200 shadow-2xs';
                jobBadge.innerHTML = '<i data-lucide="sparkles" class="w-3.5 h-3.5"></i> งานแม่บ้าน';
            } else {
                jobBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs';
                jobBadge.innerHTML = '<i data-lucide="wrench" class="w-3.5 h-3.5"></i> งานช่าง';
            }

            // Key Permission Badge
            const keyBadge = document.getElementById('dash_detail_key_badge');
            if (data.allowEntry == 1) {
                keyBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs';
                keyBadge.innerHTML = '<i data-lucide="key" class="w-3.5 h-3.5"></i> ใช้กุญแจสำรองได้';
            } else {
                keyBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-slate-100 text-slate-600 border border-slate-200 shadow-2xs';
                keyBadge.innerHTML = '<i data-lucide="lock" class="w-3.5 h-3.5"></i> ต้องมีผู้เช่าอยู่ห้อง';
            }

            // Before Image Container
            const beforeContainer = document.getElementById('dash_detail_before_img_container');
            if (data.requestImage) {
                beforeContainer.innerHTML = `
                    <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-amber-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${data.requestImage}', 'รูปก่อนทำ (ปัญหาจากลูกบ้าน - ห้อง ${data.roomNumber})')">
                        <img src="${data.requestImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="ก่อนทำ">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">
                            <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>
                        </div>
                    </div>`;
            } else {
                beforeContainer.innerHTML = `
                    <div class="w-full h-32 rounded-2xl bg-white border border-dashed border-amber-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">
                        <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>
                        <span>ลูกบ้านไม่ได้แนบรูป</span>
                    </div>`;
            }

            // Staff Box
            const staffBox = document.getElementById('dash_detail_staff_box');
            const unassignedBox = document.getElementById('dash_detail_unassigned_box');
            const staffStatusBadge = document.getElementById('dash_detail_staff_status_badge');
            if (data.assignedTo && data.staffName) {
                staffBox.classList.remove('hidden');
                unassignedBox.classList.add('hidden');
                document.getElementById('dash_detail_staff_name').innerText = data.staffName;
                document.getElementById('dash_detail_staff_avatar').innerText = (data.staffName || 'S').substring(0, 1).toUpperCase();
                
                const isCleaner = ['Cleaner', 'Housekeeper'].includes(data.staffRole);
                document.getElementById('dash_detail_staff_role').innerText = isCleaner ? 'แม่บ้าน' : 'ช่างซ่อมบำรุง';
                document.getElementById('dash_detail_staff_phone').innerText = data.staffPhone ? '• 📞 ' + data.staffPhone : '';
                
                const staffCallBtn = document.getElementById('dash_detail_staff_call_btn');
                if (data.staffPhone) {
                    staffCallBtn.href = 'tel:' + data.staffPhone;
                    document.getElementById('dash_detail_staff_call_box').classList.remove('hidden');
                } else {
                    document.getElementById('dash_detail_staff_call_box').classList.add('hidden');
                }
                staffStatusBadge.innerText = 'มอบหมายแล้ว';
                staffStatusBadge.className = 'text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-100/80 text-blue-800 border border-blue-200 font-mono';
            } else {
                staffBox.classList.add('hidden');
                unassignedBox.classList.remove('hidden');
                staffStatusBadge.innerText = 'รอมอบหมาย';
                staffStatusBadge.className = 'text-[11px] font-bold px-2.5 py-1 rounded-xl bg-amber-100/80 text-amber-800 border border-amber-200 font-mono';
                document.getElementById('dash_detail_assign_link_inline').href = 'admin_requests.php?status=Pending&req_id=' + data.requestId;
            }

            // After Image Container
            const afterContainer = document.getElementById('dash_detail_after_img_container');
            if (data.completionImage) {
                afterContainer.innerHTML = `
                    <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-emerald-300 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${data.completionImage}', 'รูปหลังทำ (ผลงานที่ส่งมอบ - ห้อง ${data.roomNumber})')">
                        <img src="${data.completionImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="หลังทำ">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">
                            <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>
                        </div>
                    </div>`;
            } else {
                afterContainer.innerHTML = `
                    <div class="w-full h-32 rounded-2xl bg-white border border-dashed border-emerald-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">
                        <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>
                        <span>${data.status === 'Completed' ? 'ไม่มีรูปภาพผลงาน' : 'ยังไม่ได้ส่งมอบงาน'}</span>
                    </div>`;
            }

            // Staff Comment
            const staffCommBox = document.getElementById('dash_detail_staff_comment_box');
            if (staffCommBox) {
                if (data.staffComment) {
                    staffCommBox.classList.remove('hidden');
                    document.getElementById('dash_detail_staff_comment_text').innerText = data.staffComment;
                    document.getElementById('dash_detail_staff_comment_author').innerText = 'บันทึกโดย: ' + (data.staffName || 'เจ้าหน้าที่') + (data.staffRole ? ' (' + data.staffRole + ')' : '');
                    document.getElementById('dash_detail_staff_comment_time').innerText = data.staffCommentedAt ? ('บันทึกเมื่อ: ' + data.staffCommentedAt + ' น.') : '';
                } else {
                    staffCommBox.classList.add('hidden');
                }
            }

            // Tenant Comment
            const commentContainer = document.getElementById('dash_detail_comment_container');
            if (data.tenantComment) {
                document.getElementById('dash_detail_comment_text').innerText = '"' + data.tenantComment + '"';
                document.getElementById('dash_detail_comment_time').innerText = data.commentedAt ? 'ส่งข้อคิดเห็นเมื่อ: ' + data.commentedAt + ' น.' : '';
                commentContainer.classList.remove('hidden');
            } else {
                commentContainer.classList.add('hidden');
            }

            // Footer Actions
            const actionsBox = document.getElementById('dash_detail_modal_actions');
            actionsBox.innerHTML = '';
            if (data.status === 'Pending') {
                const assignBtn = document.createElement('a');
                assignBtn.href = 'admin_requests.php?status=Pending&req_id=' + data.requestId;
                assignBtn.className = 'px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm';
                assignBtn.innerHTML = '<i data-lucide="user-plus" class="w-4 h-4"></i> จ่ายงานช่าง / มอบหมายงาน';
                actionsBox.appendChild(assignBtn);
            } else if (data.status === 'In Progress') {
                const manageBtn = document.createElement('a');
                manageBtn.href = 'admin_requests.php?status=In Progress&req_id=' + data.requestId;
                manageBtn.className = 'px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm';
                manageBtn.innerHTML = '<i data-lucide="wrench" class="w-4 h-4"></i> จัดการงานในหน้าระบบแจ้งซ่อม';
                actionsBox.appendChild(manageBtn);
            } else if (data.status === 'Pending Approval') {
                const apprBtn = document.createElement('a');
                apprBtn.href = 'admin_requests.php?status=Pending Approval&req_id=' + data.requestId;
                apprBtn.className = 'px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm';
                apprBtn.innerHTML = '<i data-lucide="check-circle-2" class="w-4 h-4"></i> ตรวจสอบและอนุมัติงาน';
                actionsBox.appendChild(apprBtn);
            } else if (data.status === 'Completed') {
                const completeBtn = document.createElement('a');
                completeBtn.href = 'admin_requests.php?status=Completed&req_id=' + data.requestId;
                completeBtn.className = 'px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm';
                completeBtn.innerHTML = '<i data-lucide="check-circle" class="w-4 h-4"></i> ตรวจงานในหน้าระบบแจ้งซ่อม';
                actionsBox.appendChild(completeBtn);
            }

            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            if (window.lucide) {
                lucide.createIcons();
            }
        } catch(err) {
            console.error("Error opening request detail modal:", err);
        }
    }

    function closeRequestDetailModal() {
        const modal = document.getElementById('requestDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
    }

    function openImageModal(url, titleText) {
        if (!url) return;
        const preview = document.getElementById('previewImage');
        const title = document.getElementById('imageTitle');
        const modal = document.getElementById('imagePreviewModal');
        if (preview) preview.src = url;
        if (title) title.textContent = titleText || 'ดูรูปภาพ';
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            if (window.lucide) lucide.createIcons();
        }
    }

    function closeImageModal() {
        const modal = document.getElementById('imagePreviewModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            const preview = document.getElementById('previewImage');
            if (preview) preview.src = '';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeImageModal();
            closeRequestDetailModal();
            closeGeneralAlertDetailModal();
        }
    });

    </script>

</body>

</html>

