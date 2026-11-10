<?php

session_start();

require_once 'includes/db_connect.php';

require_once 'includes/time_helper.php';



// ตรวจสอบสิทธิ์ ต้องเป็น ลูกบ้าน (Tenant) เท่านั้น

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {

    header("Location: login.php");

    exit();

}



$tenantId = $_SESSION['user_id'];

$tenantName = $_SESSION['full_name'];

$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));



// 1. ดึงข้อมูลห้องพักปัจจุบันและข้อมูลสัญญาทั้งหมดของผู้เช่า

$stmtRoom = $conn->prepare("

    SELECT r.roomId, r.roomNumber, r.roomType, r.rentPrice, r.floor, l.* 

    FROM Lease l 

    JOIN Room r ON l.roomId = r.roomId 

    WHERE l.tenantId = ? AND l.status = 'Active' 

    LIMIT 1

");

$stmtRoom->execute([$tenantId]);

$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $myRoom ? ($myRoom['roomNumber'] ?? '') : '';



// --- ระบบบันทึกข้อคิดเห็น/ติชมงานจากผู้เช่า (AJAX Endpoint) ---

if (isset($_POST['action']) && $_POST['action'] === 'save_tenant_comment') {

    header('Content-Type: application/json; charset=utf-8');

    $reqId = intval($_POST['request_id'] ?? 0);

    $comment = trim($_POST['tenant_comment'] ?? '');



    $stmtCheck = $conn->prepare("SELECT requestId, status, completionImage FROM ServiceRequest WHERE requestId = ? AND tenantId = ?");

    $stmtCheck->execute([$reqId, $tenantId]);

    $checkReq = $stmtCheck->fetch(PDO::FETCH_ASSOC);



    if ($checkReq) {

        if ($checkReq['status'] !== 'Completed') {
        echo json_encode(['success' => false, 'message' => 'งานยังไม่ได้รับการอนุมัติเสร็จสิ้นจากนิติบุคคล ไม่สามารถส่งข้อคิดเห็นได้']);
        exit;
    }
    if (!empty($comment)) {

            $stmtComment = $conn->prepare("UPDATE ServiceRequest SET tenantComment = ?, commentedAt = NOW() WHERE requestId = ? AND tenantId = ?");

            if ($stmtComment->execute([$comment, $reqId, $tenantId])) {

                echo json_encode([

                    'success' => true,

                    'message' => 'บันทึกความคิดเห็นเรียบร้อยแล้ว!',

                    'tenantComment' => $comment,

                    'commentedAt' => date('d/m/Y H:i')

                ]);

            } else {

                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกความคิดเห็น']);

            }

        } else {

            echo json_encode(['success' => false, 'message' => 'กรุณากรอกความคิดเห็นก่อนกดบันทึกครับ']);

        }

    } else {

        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลคำร้องนี้']);

    }

    exit;

}







// 🌟 ดึงข้อมูลรายการเช็คลิสต์อุปกรณ์และทรัพย์สินภายในห้องพักของลูกบ้าน

$tenantChecklist = [];

$isDefaultChecklist = false;

if ($myRoom && !empty($myRoom['roomId'])) {

    try {

        $stmtCl = $conn->prepare("SELECT * FROM RoomChecklist WHERE roomId = ? ORDER BY FIELD(category, 'เฟอร์นิเจอร์', 'เครื่องใช้ไฟฟ้า', 'ระบบไฟฟ้า', 'ห้องน้ำ', 'ระบบความปลอดภัย'), checklistId ASC");

        $stmtCl->execute([$myRoom['roomId']]);

        $tenantChecklist = $stmtCl->fetchAll(PDO::FETCH_ASSOC);

    } catch(PDOException $e) {}



    // กรณีที่ห้องนี้ยังไม่มีรายการในตาราง ให้แสดงชุดมาตรฐาน 10 รายการเป็นข้อมูลอ้างอิง

    if (empty($tenantChecklist)) {

        $isDefaultChecklist = true;

        $tenantChecklist = [

            ['itemName' => 'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 500, 'itemStatus' => 'Good', 'notes' => 'สภาพใหม่ ไม่เลอะ'],

            ['itemName' => 'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 450, 'itemStatus' => 'Good', 'notes' => 'มีไม้แขวนเสื้อ 5 อัน'],

            ['itemName' => 'เครื่องปรับอากาศ (รีโมท 1 อัน)', 'category' => 'เครื่องใช้ไฟฟ้า', 'quantity' => 1, 'estimatedPrice' => 500, 'itemStatus' => 'Good', 'notes' => 'ล้างแอร์เรียบร้อย'],

            ['itemName' => 'เครื่องทำน้ำอุ่น', 'category' => 'เครื่องใช้ไฟฟ้า', 'quantity' => 1, 'estimatedPrice' => 400, 'itemStatus' => 'Good', 'notes' => 'มีสายดินและตัดไฟ ELCB'],

            ['itemName' => 'โต๊ะทำงาน / โต๊ะเขียนหนังสือ', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 350, 'itemStatus' => 'Good', 'notes' => 'มีลิ้นชักพร้อมกุญแจ'],

            ['itemName' => 'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ', 'category' => 'ห้องน้ำ', 'quantity' => 1, 'estimatedPrice' => 300, 'itemStatus' => 'Good', 'notes' => 'ชักโครก สายฉีดชำระปกติ'],

            ['itemName' => 'เก้าอี้ทำงาน', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 250, 'itemStatus' => 'Good', 'notes' => 'ขาเก้าอี้แข็งแรง'],

            ['itemName' => 'ผ้าม่านกันแสงหน้าต่าง/ระเบียง', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 200, 'itemStatus' => 'Good', 'notes' => 'ซักทำความสะอาดแล้ว'],

            ['itemName' => 'คีย์การ์ด / กุญแจเข้าห้องพัก', 'category' => 'ระบบความปลอดภัย', 'quantity' => 2, 'estimatedPrice' => 150, 'itemStatus' => 'Good', 'notes' => 'กุญแจ 2 ดอก คีย์การ์ด 1 ใบ'],

            ['itemName' => 'หลอดไฟส่องสว่าง LED', 'category' => 'ระบบไฟฟ้า', 'quantity' => 2, 'estimatedPrice' => 100, 'itemStatus' => 'Good', 'notes' => 'เปิดติดครบทุกจุด']

        ];

    }

}



// 2. ดึงประกาศข่าวสารล่าสุดจากนิติบุคคล (Announcements)

$announcements = [];

try {

    $announcements = $conn->query("SELECT *, COALESCE(content, message) as content, COALESCE(message, content) as message, COALESCE(created_at, createdAt) as created_at FROM Announcements ORDER BY announcementId DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {}



// 3. ดึงยอดค้างชำระ (Unpaid Invoices)

$stmtUnpaid = $conn->prepare("SELECT SUM(totalAmount) as total, COUNT(invoiceId) as count FROM Invoices WHERE tenantId = ? AND status = 'Unpaid'");

$stmtUnpaid->execute([$tenantId]);

$unpaidData = $stmtUnpaid->fetch(PDO::FETCH_ASSOC);

$unpaidTotal = (float)($unpaidData['total'] ?? 0);

$unpaidCount = (int)($unpaidData['count'] ?? 0);



// 4. ดึงข้อมูลประวัติและการทำรายการทั้งหมดของผู้เช่า (Invoices, Requests, Renewals, RoomChanges, MoveOut, Coresidents)

$myRoomId = $myRoom ? (int)$myRoom['roomId'] : 0;



// 4.1 ประวัติใบแจ้งหนี้ทั้งหมด

$stmtAllInv = $conn->prepare("

    SELECT i.*, r.roomNumber 

    FROM Invoices i

    LEFT JOIN Room r ON i.roomId = r.roomId

    WHERE i.tenantId = ? OR (i.roomId = ? AND ? > 0)

    ORDER BY i.invoiceId DESC

");

$stmtAllInv->execute([$tenantId, $myRoomId, $myRoomId]);

$myInvoices = $stmtAllInv->fetchAll(PDO::FETCH_ASSOC);



// 4.2 ประวัติการแจ้งซ่อมทั้งหมด

$stmtAllReq = $conn->prepare("

    SELECT sr.*, r.roomNumber, staff.fullName AS staffName, staff.phone AS staffPhone, staff.role AS staffRole

    FROM ServiceRequest sr

    LEFT JOIN Room r ON sr.roomId = r.roomId

    LEFT JOIN User staff ON sr.assignedTo = staff.userId

    WHERE sr.tenantId = ?

    ORDER BY sr.requestId DESC

");

$stmtAllReq->execute([$tenantId]);

$myRequests = $stmtAllReq->fetchAll(PDO::FETCH_ASSOC);



// รวบรวมข้อมูลคำขอทั้งหมดสำหรับ Request Detail Modal จอใหญ่

$allRequestsData = [];

foreach ($myRequests as $r) {

    $dur = (!empty($r['assignedAt']) && !empty($r['completedAt'])) 

           ? duration_th($r['assignedAt'], $r['completedAt']) 

           : ((!empty($r['requestDate']) && !empty($r['completedAt'])) ? duration_th($r['requestDate'], $r['completedAt']) : '');



    $isCleanerRole = in_array($r['staffRole'] ?? '', ['Cleaner', 'Housekeeper']);

    $roleLabel = $isCleanerRole ? "แม่บ้าน" : "ช่างซ่อมบำรุง";



    $allRequestsData[$r['requestId']] = [

        'requestId' => (int)$r['requestId'],

        'reqCode' => 'REQ-' . str_pad($r['requestId'], 4, '0', STR_PAD_LEFT),

        'roomId' => (int)($r['roomId'] ?? 0),

        'roomNumber' => $r['roomNumber'] ?? ($myRoom['roomNumber'] ?? '-'),

        'category' => $r['category'],

        'description' => $r['description'] ?? '',

        'preferredDate' => !empty($r['preferredDate']) ? date('d/m/Y', strtotime($r['preferredDate'])) : '-',

        'preferredTime' => $r['preferredTime'] ?? '',

        'allowEntry' => (int)($r['allowEntry'] ?? 0),

        'status' => $r['status'],

        'requestDate' => !empty($r['requestDate']) ? date('d/m/Y H:i', strtotime($r['requestDate'])) : '-',

        'requestDateRaw' => $r['requestDate'] ?? '',

        'timeAgoRequest' => !empty($r['requestDate']) ? time_ago_th($r['requestDate']) : '',

        'assignedAt' => !empty($r['assignedAt']) ? date('d/m/Y H:i', strtotime($r['assignedAt'])) : '',

        'assignedAtRaw' => $r['assignedAt'] ?? '',

        'timeAgoAssigned' => !empty($r['assignedAt']) ? time_ago_th($r['assignedAt']) : '',

        'completedAt' => !empty($r['completedAt']) ? date('d/m/Y H:i', strtotime($r['completedAt'])) : '',

        'completedAtRaw' => $r['completedAt'] ?? '',

        'timeAgoCompleted' => !empty($r['completedAt']) ? time_ago_th($r['completedAt']) : '',

        'duration' => $dur,

        'staffName' => $r['staffName'] ?? '',

        'staffPhone' => $r['staffPhone'] ?? '',

        'staffRole' => $roleLabel,

        'requestImage' => $r['requestImage'] ?? '',

        'completionImage' => $r['completionImage'] ?? '',

        'tenantComment' => $r['tenantComment'] ?? '',

        'commentedAt' => !empty($r['commentedAt']) ? date('d/m/Y H:i', strtotime($r['commentedAt'])) : '',

        'estimatedHours' => (int)($r['estimatedHours'] ?? 2)

    ];

}

// 🌟 รวบรวมข้อมูลใบแจ้งหนี้ทั้งหมดสำหรับ Tenant Invoice Detail Modal จอใหญ่ 🌟
$allTenantInvoicesData = [];
foreach ($myInvoices as $inv) {
    $wUnits = max(0, (int)($inv['waterCurr'] ?? 0) - (int)($inv['waterPrev'] ?? 0));
    $eUnits = max(0, (int)($inv['electricCurr'] ?? 0) - (int)($inv['electricPrev'] ?? 0));
    $wRate = (float)($inv['waterUnitRate'] ?? 18);
    $eRate = (float)($inv['electricUnitRate'] ?? 7);
    $wTotal = $wUnits * $wRate;
    $eTotal = $eUnits * $eRate;
    $rentPrice = (float)($inv['rentPrice'] ?? 0);
    $otherFee = (float)($inv['otherFee'] ?? 0);
    $totalAmount = (float)($inv['totalAmount'] ?? ($rentPrice + $wTotal + $eTotal + $otherFee));
    
    $allTenantInvoicesData[$inv['invoiceId']] = [
        'invoiceId' => (int)$inv['invoiceId'],
        'invCode' => 'INV-' . str_pad($inv['invoiceId'], 4, '0', STR_PAD_LEFT),
        'billingMonth' => $inv['billingMonth'] ?? '-',
        'roomNumber' => $inv['roomNumber'] ?? ($myRoomNumber ?: '-'),
        'tenantName' => $tenantName,
        'rentPrice' => $rentPrice,
        'waterPrev' => (int)($inv['waterPrev'] ?? 0),
        'waterCurr' => (int)($inv['waterCurr'] ?? 0),
        'waterUnits' => $wUnits,
        'waterRate' => $wRate,
        'waterTotal' => $wTotal,
        'electricPrev' => (int)($inv['electricPrev'] ?? 0),
        'electricCurr' => (int)($inv['electricCurr'] ?? 0),
        'electricUnits' => $eUnits,
        'electricRate' => $eRate,
        'electricTotal' => $eTotal,
        'otherFee' => $otherFee,
        'otherFeeNote' => !empty($inv['otherFeeNote']) ? $inv['otherFeeNote'] : (abs($otherFee - 20) < 0.01 ? 'ค่าขยะ (20.00 บาท)' : (abs($otherFee - 100) < 0.01 ? 'ค่าบริการส่วนกลาง (100.00 บาท)' : '')),
        'totalAmount' => $totalAmount,
        'status' => $inv['status'] ?? 'Pending',
        'slipImage' => $inv['slipImage'] ?? '',
        'slipUploadedAt' => !empty($inv['slipUploadedAt']) ? date('d/m/Y H:i', strtotime($inv['slipUploadedAt'])) : '',
        'paidAt' => !empty($inv['paidAt']) ? date('d/m/Y H:i', strtotime($inv['paidAt'])) : '',
        'createdAt' => !empty($inv['createdAt']) ? date('d/m/Y H:i', strtotime($inv['createdAt'])) : '',
        'notes' => $inv['notes'] ?? ($inv['remark'] ?? '')
    ];
}



// 4.3 ประวัติคำขอต่อสัญญาเช่า

$stmtAllRenew = $conn->prepare("

    SELECT lr.*, l.startDate, l.endDate, r.roomNumber 

    FROM LeaseRenewals lr

    LEFT JOIN Lease l ON lr.leaseId = l.leaseId

    LEFT JOIN Room r ON lr.roomId = r.roomId

    WHERE lr.tenantId = ?

    ORDER BY lr.renewalId DESC

");

$stmtAllRenew->execute([$tenantId]);

$myRenewals = $stmtAllRenew->fetchAll(PDO::FETCH_ASSOC);



// 4.4 ประวัติคำขอย้ายห้องพัก

$stmtAllRc = $conn->prepare("

    SELECT rc.*, cr.roomNumber AS currentRoomNumber, tr.roomNumber AS targetRoomNumber

    FROM RoomChangeRequests rc

    LEFT JOIN Room cr ON rc.currentRoomId = cr.roomId

    LEFT JOIN Room tr ON rc.targetRoomId = tr.roomId

    WHERE rc.tenantId = ?

    ORDER BY rc.requestId DESC

");

$stmtAllRc->execute([$tenantId]);

$myRoomChanges = $stmtAllRc->fetchAll(PDO::FETCH_ASSOC);



// 4.5 ประวัติแจ้งย้ายออก & คืนห้อง

$stmtAllMo = $conn->prepare("

    SELECT l.*, r.roomNumber, r.roomType, r.floor, r.rentPrice

    FROM Lease l

    JOIN Room r ON l.roomId = r.roomId

    WHERE l.tenantId = ? AND (l.moveOutRequestDate IS NOT NULL OR l.moveOutStatus IS NOT NULL)

    ORDER BY l.leaseId DESC

");

$stmtAllMo->execute([$tenantId]);

$myMoveOuts = $stmtAllMo->fetchAll(PDO::FETCH_ASSOC);



// 4.6 ผู้ร่วมพักอาศัย

$stmtAllCo = $conn->prepare("

    SELECT cr.*, r.roomNumber

    FROM CoResidents cr

    LEFT JOIN Room r ON cr.roomId = r.roomId

    WHERE cr.tenantId = ?

    ORDER BY cr.id DESC

");

$stmtAllCo->execute([$tenantId]);

$coresidents = $stmtAllCo->fetchAll(PDO::FETCH_ASSOC);



// 5. ประวัติการทำรายการ (Unified Timeline Events: เฉพาะประวัติแจ้งซ่อม และประวัติที่เคยจ่ายเงิน)

$paidInvoices = [];

foreach ($myInvoices as $inv) {

    if (in_array($inv['status'], ['Paid', 'Pending Approval'])) {

        $paidInvoices[] = $inv;

    }

}



$timelineEvents = [];



foreach ($paidInvoices as $inv) {

    $timelineEvents[] = [

        'type' => 'invoice',

        'id' => $inv['invoiceId'],

        'title' => 'ชำระเงินบิลรอบเดือน ' . $inv['billingMonth'],

        'date' => $inv['createdAt'] ?? date('Y-m-d H:i:s'),

        'status' => $inv['status'],

        'statusText' => ($inv['status'] === 'Paid' ? 'ชำระเรียบร้อย' : 'รอตรวจสอบสลิป'),

        'statusColor' => ($inv['status'] === 'Paid' ? 'emerald' : 'amber'),

        'icon' => 'receipt',

        'iconBg' => 'bg-emerald-50 text-emerald-600',

        'details' => 'ยอดเงิน ฿' . number_format($inv['totalAmount'], 2),

        'tab' => 'invoices',

        'raw' => $inv

    ];

}



foreach ($myRequests as $req) {

    $stText = 'รอดำเนินการ';

    $stColor = 'amber';

    if ($req['status'] === 'Completed') {

        $stText = 'เสร็จสิ้น';

        $stColor = 'emerald';

    } elseif ($req['status'] === 'Pending Approval') {

        $stText = 'ช่างส่งงาน (รอนิติตรวจรับ)';

        $stColor = 'orange';

    } elseif ($req['status'] === 'In Progress') {

        $stText = 'กำลังดำเนินการ';

        $stColor = 'blue';

    } elseif ($req['status'] === 'Cancelled') {

        $stText = 'ยกเลิกแล้ว';

        $stColor = 'slate';

    }

    $timelineEvents[] = [

        'type' => 'request',

        'id' => $req['requestId'],

        'title' => 'แจ้งซ่อม: ' . $req['category'],

        'date' => $req['requestDate'],

        'status' => $req['status'],

        'statusText' => $stText,

        'statusColor' => $stColor,

        'icon' => 'wrench',

        'iconBg' => 'bg-amber-50 text-amber-600',

        'details' => $req['description'] . (!empty($req['preferredTime']) ? ' • นัด: ' . $req['preferredTime'] : '') . ' (กำหนดเวลา ' . intval($req['estimatedHours'] ?? 2) . ' ชม.)',

        'tab' => 'requests',

        'raw' => $req

    ];

}



usort($timelineEvents, function($a, $b) {

    return strtotime($b['date']) - strtotime($a['date']);

});



// 6. Live notifications

$tenantNotifications = [];



if ($unpaidCount > 0) {

    $tenantNotifications[] = [

        'type' => 'warning', 'icon' => 'alert-triangle',

        'title' => 'มียอดค้างชำระ ' . $unpaidCount . ' รายการ',

        'desc' => 'ยอดรวม ฿' . number_format($unpaidTotal, 2) . ' กรุณาชำระเงินตามกำหนดเวลา',

        'link' => 'view_invoices.php', 'onclick' => "", 'linkText' => 'ชำระเงิน'

    ];

}



// เช็คผู้ร่วมอาศัย

foreach ($coresidents as $co) {

    if ($co['status'] === 'Pending') {

        $tenantNotifications[] = [

            'type' => 'info', 'icon' => 'user-plus',

            'title' => 'คำขอผู้ร่วมพักอาศัย: ' . htmlspecialchars($co['fullName']),

            'desc' => 'อยู่ระหว่างรอแอดมินนิติบุคคลพิจารณาอนุมัติ',

            'link' => 'tenant_coresidents.php', 'onclick' => "", 'linkText' => 'ดูผู้ร่วมอาศัย'

        ];

    } elseif ($co['status'] === 'Approved') {

        $tenantNotifications[] = [

            'type' => 'success', 'icon' => 'check-circle-2',

            'title' => 'อนุมัติผู้ร่วมพักอาศัย: ' . htmlspecialchars($co['fullName']),

            'desc' => 'ได้รับการอนุมัติเข้าพักในห้องเรียบร้อยแล้ว',

            'link' => 'tenant_coresidents.php', 'onclick' => "", 'linkText' => 'ดูผู้ร่วมอาศัย'

        ];

    }

}



// เช็คสถานะขอย้ายห้อง

if (!empty($myRoomChanges)) {

    $rcLatest = $myRoomChanges[0];

    if ($rcLatest['status'] === 'Pending') {

        $tenantNotifications[] = [

            'type' => 'info', 'icon' => 'arrow-left-right',

            'title' => 'คำขอย้ายห้องพัก',

            'desc' => 'ส่งคำขอย้ายห้องแล้ว อยู่ระหว่างรอแอดมินตรวจสอบ',

            'link' => 'tenant_room_change.php', 'onclick' => "", 'linkText' => 'ติดตามสถานะ'

        ];

    }

}



// เช็คงานแจ้งซ่อมที่เสร็จสิ้นแล้ว
foreach ($myRequests as $req) {
    if ($req['status'] === 'Completed') {
        $staffRoleTh = in_array($req['staffRole'] ?? '', ['Cleaner', 'Housekeeper']) ? 'แม่บ้าน' : 'ช่าง';
        $staffNamePart = !empty($req['staffName']) ? ' โดย ' . $staffRoleTh . 'คุณ' . htmlspecialchars($req['staffName']) : '';
        $tenantNotifications[] = [
            'type' => 'success',
            'icon' => 'check-circle-2',
            'title' => 'งานแจ้งซ่อม ' . $req['reqCode'] . ' ดำเนินการเสร็จสิ้นแล้ว',
            'desc' => htmlspecialchars($req['category']) . ' แก้ไขเสร็จสิ้นเรียบร้อย' . $staffNamePart . ' คลิกเพื่อตรวจผลงานและให้คะแนนบริการ',
            'link' => '#',
            'onclick' => 'openRequestDetailModal(' . $req['requestId'] . ')',
            'linkText' => 'ตรวจผลงาน'
        ];
        break;
    }
}

// เช็คสถานะต่อสัญญา

if (!empty($myRenewals)) {

    $rnLatest = $myRenewals[0];

    if ($rnLatest['status'] === 'Pending') {

        $tenantNotifications[] = [

            'type' => 'info', 'icon' => 'refresh-cw',

            'title' => 'คำขอต่ออายุสัญญาเช่า',

            'desc' => 'ส่งคำขอต่อสัญญาเช่าแล้ว รอแอดมินพิจารณา',

            'link' => 'tenant_renewal.php', 'onclick' => "", 'linkText' => 'ติดตามสถานะ'

        ];

    }

}



// เช็คสถานะแจ้งย้ายออก

if (!empty($myMoveOuts)) {

    $moLatest = $myMoveOuts[0];

    if ($moLatest['moveOutStatus'] === 'Pending_Payment') {

        $tenantNotifications[] = [

            'type' => 'warning', 'icon' => 'file-text',

            'title' => 'แจ้งย้ายออก: สรุปยอดคืนเงินมัดจำ',

            'desc' => 'แอดมินได้ส่งรายละเอียดคืนมัดจำแล้ว กรุณาแนบสลิปเพื่อยืนยัน',

            'link' => 'tenant_move_out.php', 'onclick' => "", 'linkText' => 'ดูยอดคืนเงิน'

        ];

    } elseif ($moLatest['moveOutStatus'] === 'Slip_Uploaded') {

        $tenantNotifications[] = [

            'type' => 'info', 'icon' => 'clock',

            'title' => 'แจ้งย้ายออก: รอแอดมินยืนยันสลิป',

            'desc' => 'คุณได้แนบสลิปเรียบร้อยแล้ว รอแอดมินยืนยันปิดสัญญา',

            'link' => 'tenant_move_out.php', 'onclick' => "", 'linkText' => 'ดูสถานะ'

        ];

    }

}



$totalAlertCount = count($announcements) + count($tenantNotifications);



// ข้อมูลวันเดือนปีภาษาไทยสำหรับ Welcome Banner

$thaiMonths = [

    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',

    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',

    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'

];

$currMonthNum = (int)date('n');

$currMonthNameTh = $thaiMonths[$currMonthNum];

$currYear = (int)date('Y');

$todayThaiDate = date('d') . ' ' . $currMonthNameTh . ' ' . ($currYear + 543);



// บิลรอบล่าสุดสำหรับ Stat Card 1

$latestInvoice = !empty($myInvoices) ? $myInvoices[0] : null;



// คำนวณสรุปยอดค้างชำระ และงานแจ้งซ่อม

$unpaidTotal = (float)($unpaidData['total'] ?? 0);

$unpaidCount = (int)($unpaidData['count'] ?? 0);



$pendingServiceCount = 0;

if (!empty($myRequests)) {

    foreach ($myRequests as $req) {

        if (in_array($req['status'] ?? '', ['Pending', 'In Progress', 'Pending Approval'])) {
            $pendingServiceCount++;
        }

    }

}



// ดึงเลขมิเตอร์ล่าสุด

$latestMeterWater = 0;

$latestMeterElectric = 0;

try {

    $stmtMtr = $conn->prepare("SELECT currentWaterMeter, currentElectricMeter FROM Invoices WHERE tenantId = ? ORDER BY invoiceId DESC LIMIT 1");

    $stmtMtr->execute([$tenantId]);

    if ($mtrRow = $stmtMtr->fetch(PDO::FETCH_ASSOC)) {

        $latestMeterWater = $mtrRow['currentWaterMeter'];

        $latestMeterElectric = $mtrRow['currentElectricMeter'];

    } elseif ($myRoom) {

        $latestMeterWater = $myRoom['startWaterMeter'] ?? 0;

        $latestMeterElectric = $myRoom['startElectricMeter'] ?? 0;

    }

} catch(Throwable $e) {}



?>



<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>jasmin</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">

  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/@tailwindcss/browser@4"></script>

  <script src="https://unpkg.com/lucide@latest"></script>

  <style>

    body { font-family: 'Prompt', 'Noto Sans Thai', sans-serif; background-color: #FAF9F6; color: #1f1519; }

    .font-display { font-family: 'Kanit', 'Space Grotesk', sans-serif; }

    .boutique-card {

      background-color: #ffffff;

      border: 1px solid rgba(254, 205, 211, 0.65);

      box-shadow: 0 8px 30px -4px rgba(244, 63, 94, 0.05), 0 2px 6px -2px rgba(244, 63, 94, 0.02);

      border-radius: 1.75rem;

    }

    .boutique-card-hover {

      transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);

    }

    .boutique-card-hover:hover {

      transform: translateY(-2px);

      box-shadow: 0 16px 36px -6px rgba(244, 63, 94, 0.10);

      border-color: rgba(251, 113, 133, 0.7);

    }

    .font-display { font-family: 'Space Grotesk', 'Noto Sans Thai', sans-serif; }

    .scrollbar-thin::-webkit-scrollbar { width: 5px; height: 5px; }

    .scrollbar-thin::-webkit-scrollbar-track { background: transparent; }

    .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }

    .scrollbar-thin::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

  </style>

  <?php include_once 'includes/theme_head.php'; ?>
</head>

<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white pb-16 md:pb-8 bg-[#FAF9F6] text-slate-800">



  <!-- 🌟 แท็บด้านซ้ายมือ สำหรับผู้เช่าพักอาศัย (Tenant Sidebar) 🌟 -->

  <?php include_once 'includes/tenant_sidebar.php'; ?>



  <!-- Top Navigation -->

  <!-- Top Navigation -->

  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between h-16">

        

        <!-- Logo & Hamburger Menu Button -->

        <div class="flex items-center space-x-2">

          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">

            <i data-lucide="menu" class="w-5 h-5"></i>

          </button>

          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-md shrink-0">

            <i data-lucide="home" class="w-5 h-5"></i>

          </div>

          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
              <a href="profile.php" class="inline-flex items-center gap-1.5 text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold px-2.5 py-0.5 rounded-full border border-rose-200 transition shadow-2xs" title="โปรไฟล์ของฉัน">
                <span><?php echo htmlspecialchars($headerTenantName ?? $sidebarTenantName ?? ($tenantName ?? 'ผู้เช่า')); ?></span>
                <?php if(!empty($headerRoomNumber ?? $sidebarRoomNumber ?? ($myRoomNumber ?? ''))): ?>
                  <span class="text-rose-400">•</span>
                  <span>ห้อง <?php echo htmlspecialchars($headerRoomNumber ?? $sidebarRoomNumber ?? ($myRoomNumber ?? '')); ?></span>
                <?php endif; ?>
              </a>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ระบบจัดการลูกบ้าน</p>
          </div>

        </div>



        <!-- Right Side Nav Actions -->

        <div class="flex items-center space-x-2 sm:space-x-3">

          <!-- 🌟 สลับธีม 2 สี (มืด / สว่าง) 🌟 -->
          <?php include 'includes/theme_toggle.php'; ?>

          <!-- 🔔 ปุ่มการแจ้งเตือน (Notification Bell) -->

          <button type="button" onclick="openNotifModal()" class="relative p-2 text-slate-600 hover:text-rose-600 hover:bg-slate-100 rounded-xl transition cursor-pointer" title="การแจ้งเตือน">

              <i data-lucide="bell" class="w-5 h-5"></i>

              <?php if($totalAlertCount > 0): ?>

                <span id="headerNotifBadge" class="notif-badge absolute top-1 right-1 w-4 h-4 bg-rose-500 text-white font-bold text-[10px] rounded-full flex items-center justify-center border-2 border-white animate-pulse">

                    <?php echo $totalAlertCount; ?>

                </span>

              <?php endif; ?>

          </button>



          <div class="w-px h-6 bg-rose-200/60 hidden sm:block"></div>



          <!-- ลิงก์โปรไฟล์ของผู้เช่า (ตรงชื่อ) -->

          <a href="profile.php" class="flex items-center gap-2 p-1 sm:p-1.5 hover:bg-rose-50/70 rounded-xl transition border border-transparent hover:border-rose-200 group" title="โปรไฟล์ของฉัน">

            <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs border border-rose-200 shadow-2xs shrink-0">

              <?php echo $initials; ?>

            </div>

            <div class="hidden sm:block text-left pr-0.5">

              <div class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition truncate max-w-[130px] sm:max-w-[170px]"><?php echo htmlspecialchars($tenantName); ?></div>

              <div class="text-[10px] text-rose-600 font-semibold truncate flex items-center gap-1">

                <?php if(!empty($myRoomNumber)): ?>

                  <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> ห้อง <?php echo htmlspecialchars($myRoomNumber); ?>

                <?php else: ?>

                  <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> ผู้เช่า

                <?php endif; ?>

              </div>

            </div>

          </a>



          <!-- ปุ่มออกจากระบบ (อยู่มุมขวาบนตรงชื่อ) -->

          <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 hover:text-rose-700 border border-rose-200 text-xs font-bold transition shadow-2xs cursor-pointer shrink-0" title="ออกจากระบบ">

            <i data-lucide="log-out" class="w-4 h-4"></i>

            <span class="hidden sm:inline">ออกจากระบบ</span>

          </a>

        </div>

      </div>

    </div>

  </header>



  <!-- 🌟 Main Content Container 🌟 -->

  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

    <div class="space-y-6 sm:space-y-8">



      <!-- 🌟 1. Top Welcome & Fast Action Bar (Warm Rose & Linen Boutique Hero) 🌟 -->
      <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs">
        <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
          <div>
            <div class="flex items-center gap-2 flex-wrap mb-2">
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
                <i data-lucide="calendar" class="w-3.5 h-3.5 text-rose-500"></i>
                <span><?php echo $todayThaiDate; ?></span>
              </span>
              <?php if ($myRoom): ?>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
                  <i data-lucide="home" class="w-3.5 h-3.5 text-amber-500"></i>
                  <span>ห้อง <?php echo htmlspecialchars($myRoom['roomNumber']); ?> (<?php echo ($myRoom['roomType'] === 'Air') ? 'ห้องแอร์' : 'ห้องพัดลม'; ?> ชั้น <?php echo htmlspecialchars($myRoom['floor'] ?? '1'); ?>)</span>
                </span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 bg-slate-100 border border-slate-200 px-3 py-1 rounded-full">
                  <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400"></i>
                  <span>ยังไม่มีสัญญาเช่าที่เปิดใช้งาน</span>
                </span>
              <?php endif; ?>
            </div>
            <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
              สวัสดีคุณ <?php echo htmlspecialchars($tenantName); ?> 👋
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
              ยินดีต้อนรับสู่ระบบบริการลูกบ้านหอพักจัสมิน จัดการบิล ตรวจสอบค่าเช่า และแจ้งซ่อมได้ตลอด 24 ชั่วโมง
            </p>
          </div>

          <!-- Quick Top Action Buttons -->
          <div class="flex flex-wrap items-center gap-3 shrink-0">
            <a href="tenant_request.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
              <i data-lucide="wrench" class="w-4 h-4 text-amber-500"></i>
              <span>แจ้งซ่อมใหม่</span>
            </a>
            <a href="view_invoices.php" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 shadow-sm shadow-rose-600/25 active:scale-95 cursor-pointer">
              <i data-lucide="receipt" class="w-4 h-4"></i>
              <span>ใบแจ้งหนี้ & ชำระเงิน</span>
            </a>
          </div>
        </div>
      </div>



      <!-- 🌟 2. 4 Key Stats Cards (ตัวชี้วัดสำคัญ 4 ช่องแนวนอน เหมือนในรูปเป๊ะ) 🌟 -->

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">

        

        <!-- ช่องที่ 1: ค่าเช่ารอบปัจจุบัน -->

        <a href="view_invoices.php" class="bg-white p-5 sm:p-6 rounded-3xl border border-rose-100/90 shadow-sm hover:shadow-md hover:border-emerald-300 transition-all group block">

          <div class="flex items-center justify-between">

            <span class="text-xs font-semibold text-slate-500 group-hover:text-slate-800 transition">ค่าเช่ารอบปัจจุบัน</span>

            <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 group-hover:bg-emerald-100 transition flex items-center justify-center shrink-0">

              <i data-lucide="wallet" class="w-5 h-5"></i>

            </div>

          </div>

          <div class="mt-3">

            <div class="text-2xl sm:text-3xl font-display font-bold text-slate-900 group-hover:text-emerald-700 transition">

              ฿<?php echo number_format($latestInvoice ? $latestInvoice['totalAmount'] : ($myRoom['rentPrice'] ?? 0), 2); ?>

            </div>

            <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-500">

              <?php if ($latestInvoice && $latestInvoice['status'] === 'Unpaid'): ?>

                <span class="inline-flex items-center gap-1 text-rose-600 font-bold">

                  <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-ping"></span> รอชำระเงิน

                </span>

              <?php elseif ($latestInvoice && $latestInvoice['status'] === 'Paid'): ?>

                <span class="inline-flex items-center gap-1 text-emerald-600 font-bold">

                  <i data-lucide="check" class="w-3.5 h-3.5"></i> ชำระเรียบร้อย

                </span>

              <?php else: ?>

                <span>รอบเดือน <?php echo $currMonthNameTh; ?></span>

              <?php endif; ?>

            </div>

          </div>

        </a>



        <!-- ช่องที่ 2: ยอดค้างชำระทั้งหมด -->

        <a href="view_invoices.php" class="bg-white p-5 sm:p-6 rounded-3xl border border-rose-100/90 shadow-sm hover:shadow-md hover:border-rose-300 transition-all group block">

          <div class="flex items-center justify-between">

            <span class="text-xs font-semibold text-slate-500 group-hover:text-slate-800 transition">ยอดค้างชำระทั้งหมด</span>

            <div class="w-10 h-10 rounded-2xl <?php echo ($unpaidTotal > 0) ? 'bg-rose-50 text-rose-500 group-hover:bg-rose-100' : 'bg-rose-50/40 text-slate-400'; ?> transition flex items-center justify-center shrink-0">

              <i data-lucide="alert-circle" class="w-5 h-5"></i>

            </div>

          </div>

          <div class="mt-3">

            <div class="text-2xl sm:text-3xl font-display font-bold <?php echo ($unpaidTotal > 0) ? 'text-rose-600' : 'text-slate-900'; ?> group-hover:text-rose-700 transition">

              ฿<?php echo number_format($unpaidTotal, 2); ?>

            </div>

            <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-500">

              <?php if ($unpaidTotal > 0): ?>

                <span class="text-rose-600 font-semibold">มีบิลค้างชำระ <?php echo $unpaidCount; ?> รายการ</span>

              <?php else: ?>

                <span class="text-emerald-600 font-semibold">✓ ไม่มีบิลค้างชำระ</span>

              <?php endif; ?>

            </div>

          </div>

        </a>



        <!-- ช่องที่ 3: สถานะห้องพัก & สัญญา -->

        <a href="#contract-section" class="bg-white p-5 sm:p-6 rounded-3xl border border-rose-100/90 shadow-sm hover:shadow-md hover:border-rose-300 transition-all group block">

          <div class="flex items-center justify-between">

            <span class="text-xs font-semibold text-slate-500 group-hover:text-slate-800 transition">สถานะห้องพัก & สัญญา</span>

            <div class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 group-hover:bg-blue-100 transition flex items-center justify-center shrink-0">

              <i data-lucide="door-closed" class="w-5 h-5"></i>

            </div>

          </div>

          <div class="mt-3">

            <div class="text-2xl sm:text-3xl font-display font-bold text-slate-900 group-hover:text-rose-700 transition">

              <?php echo !empty($myRoomNumber) ? "ห้อง {$myRoomNumber}" : 'ไม่มีห้อง'; ?>

            </div>

            <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-500 truncate">

              <?php if ($myRoom): ?>

                <span>สิ้นสุด: <strong class="text-slate-700 font-mono"><?php echo date('d/m/Y', strtotime($myRoom['endDate'])); ?></strong></span>

              <?php else: ?>

                <span class="text-amber-600">ยังไม่มีสัญญา</span>

              <?php endif; ?>

            </div>

          </div>

        </a>



        <!-- ช่องที่ 4: งานซ่อม & บริการ -->

        <a href="#history-section" onclick="switchHistoryTab('requests')" class="bg-white p-5 sm:p-6 rounded-3xl border border-rose-100/90 shadow-sm hover:shadow-md hover:border-amber-300 transition-all group block">

          <div class="flex items-center justify-between">

            <span class="text-xs font-semibold text-slate-500 group-hover:text-slate-800 transition">งานซ่อม & บริการ</span>

            <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-500 group-hover:bg-amber-100 transition flex items-center justify-center shrink-0">

              <i data-lucide="wrench" class="w-5 h-5"></i>

            </div>

          </div>

          <div class="mt-3">

            <div class="text-2xl sm:text-3xl font-display font-bold text-slate-900 group-hover:text-amber-700 transition">

              <?php echo $pendingServiceCount; ?> <span class="text-base font-normal text-slate-500">งาน</span>

            </div>

            <div class="flex items-center gap-1.5 mt-1 text-xs text-rose-600 font-semibold group-hover:translate-x-1 transition-transform">

              <span>จัดการงานซ่อม →</span>

            </div>

          </div>

        </a>



      </div>



      <!-- 🌟 3. ศูนย์บริการและจัดการลูกบ้าน (Quick Action Grid 8 ช่อง เหมือนรูปเป๊ะ!) 🌟 -->

      <div class="bg-gradient-to-br from-white via-white to-rose-50/40 p-6 sm:p-8 rounded-[2rem] border border-rose-200/80 shadow-[0_8px_30px_rgb(244,63,94,0.05)] space-y-4">

        

        <div class="flex items-center justify-between pb-2 border-b border-slate-100">

          <div class="flex items-center space-x-3">

            <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">

              <i data-lucide="layout-grid" class="w-5 h-5"></i>

            </div>

            <div>

              <h2 class="font-display font-bold text-slate-800 text-base sm:text-lg">ศูนย์บริการและจัดการลูกบ้าน</h2>

              <p class="text-xs text-slate-400">เข้าถึงเมนูงานและบริการสำหรับผู้เช่าพักได้อย่างสะดวกรวดเร็ว</p>

            </div>

          </div>

        </div>



        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">

          

          <!-- 1. ห้อง & สัญญา -->

          <a href="#contract-section" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-rose-50 border border-rose-100/80 hover:border-rose-200 transition-all flex flex-col items-center text-center group">

            <div class="w-11 h-11 rounded-2xl bg-rose-500/10 text-rose-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="door-closed" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-rose-700 transition">ห้อง & สัญญา</span>

            <span class="text-[10px] text-slate-400 mt-0.5"><?php echo !empty($myRoomNumber) ? "ห้อง {$myRoomNumber}" : 'ข้อมูลห้อง'; ?></span>

          </a>



          <!-- 2. ใบแจ้งหนี้ -->

          <a href="view_invoices.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-emerald-50 border border-rose-100/80 hover:border-emerald-200 transition-all flex flex-col items-center text-center group relative cursor-pointer">

            <?php if($unpaidCount > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-amber-500 text-white text-[10px] font-bold rounded-full animate-bounce">

                <?php echo $unpaidCount; ?>

              </span>

            <?php endif; ?>

            <div class="w-11 h-11 rounded-2xl bg-emerald-600/10 text-emerald-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="receipt" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-emerald-700 transition">ใบแจ้งหนี้</span>

            <span class="text-[10px] text-slate-400 mt-0.5">ตรวจสลิปโอน</span>

          </a>



          <!-- 3. งานแจ้งซ่อม -->

          <a href="tenant_request.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-amber-50 border border-rose-100/80 hover:border-amber-200 transition-all flex flex-col items-center text-center group relative cursor-pointer">

            <?php if($pendingServiceCount > 0): ?>

              <span class="absolute top-2 right-2 px-1.5 py-0.2 bg-rose-500 text-white text-[10px] font-bold rounded-full">

                <?php echo $pendingServiceCount; ?>

              </span>

            <?php endif; ?>

            <div class="w-11 h-11 rounded-2xl bg-amber-600/10 text-amber-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="wrench" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-amber-700 transition">งานแจ้งซ่อม</span>

            <span class="text-[10px] text-slate-400 mt-0.5">แจ้งซ่อมใหม่</span>

          </a>



          <!-- 4. ต่อสัญญาเช่า -->

          <a href="tenant_renewal.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-indigo-50 border border-rose-100/80 hover:border-indigo-200 transition-all flex flex-col items-center text-center group cursor-pointer">

            <div class="w-11 h-11 rounded-2xl bg-indigo-600/10 text-indigo-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="refresh-cw" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-indigo-700 transition">ต่อสัญญาเช่า</span>

            <span class="text-[10px] text-slate-400 mt-0.5">ยื่นคำขอ</span>

          </a>



          <!-- 5. ผู้ร่วมอาศัย -->

          <a href="tenant_coresidents.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-purple-50 border border-rose-100/80 hover:border-purple-200 transition-all flex flex-col items-center text-center group cursor-pointer">

            <div class="w-11 h-11 rounded-2xl bg-purple-600/10 text-purple-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="users" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-purple-700 transition">ผู้ร่วมอาศัย</span>

            <span class="text-[10px] text-slate-400 mt-0.5">จัดการสมาชิก</span>

          </a>



          <!-- 6. ขอย้ายห้อง -->

          <a href="tenant_room_change.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-sky-50 border border-rose-100/80 hover:border-sky-200 transition-all flex flex-col items-center text-center group cursor-pointer">

            <div class="w-11 h-11 rounded-2xl bg-sky-600/10 text-sky-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="arrow-left-right" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-sky-700 transition">ขอย้ายห้อง</span>

            <span class="text-[10px] text-slate-400 mt-0.5">เปลี่ยนห้องพัก</span>

          </a>



          <!-- 7. แจ้งย้ายออก -->

          <a href="tenant_move_out.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-rose-50 border border-rose-100/80 hover:border-rose-200 transition-all flex flex-col items-center text-center group cursor-pointer">

            <div class="w-11 h-11 rounded-2xl bg-rose-600/10 text-rose-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="package-minus" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-rose-700 transition">แจ้งย้ายออก</span>

            <span class="text-[10px] text-slate-400 mt-0.5">ขอคืนห้องพัก</span>

          </a>



          <!-- 8. รายงาน & โปรไฟล์ -->

          <a href="profile.php" class="p-3.5 rounded-2xl bg-rose-50/40 hover:bg-slate-100 border border-rose-100/80 hover:border-slate-300 transition-all flex flex-col items-center text-center group">

            <div class="w-11 h-11 rounded-2xl bg-slate-600/10 text-slate-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">

              <i data-lucide="user-cog" class="w-5 h-5"></i>

            </div>

            <span class="text-xs font-bold text-slate-800 group-hover:text-slate-900 transition">โปรไฟล์ & สถิติ</span>

            <span class="text-[10px] text-slate-400 mt-0.5">ความปลอดภัย</span>

          </a>



        </div>



      </div>



      <!-- 🌟 4. พื้นที่เนื้อหารายละเอียด (ประกาศ, เช็คลิสต์, รายการแจ้งซ่อม, สัญญาเช่า) 🌟 -->

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">

        

        <!-- ฝั่งซ้าย (2 ส่วน): ประกาศ, เช็คลิสต์อุปกรณ์ประจำห้อง, รายการแจ้งซ่อมล่าสุด -->

        <div class="lg:col-span-2 space-y-6">



          <!-- ประกาศจากหอพัก -->

          <?php if(!empty($announcements)): ?>

          <div class="bg-white rounded-3xl border border-rose-100/90 shadow-sm p-6">

            <div class="flex items-center justify-between mb-4">

              <div class="flex items-center gap-2.5">

                <div class="w-8 h-8 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center">

                  <i data-lucide="megaphone" class="w-4 h-4"></i>

                </div>

                <div>

                  <h3 class="font-display font-bold text-slate-800 text-sm sm:text-base">ประกาศข่าวสารจากหอพัก</h3>

                  <p class="text-[11px] text-slate-400">ข้อมูลและข่าวประชาสัมพันธ์สำคัญสำหรับลูกบ้าน</p>

                </div>

              </div>

            </div>



            <div class="space-y-3">

              <?php foreach($announcements as $ann): 

                $type = $ann['type'] ?? 'info';

                $badgeClass = "bg-rose-50 text-rose-700 border-blue-200";

                $typeLabel = "ทั่วไป";

                if ($type === 'warning') {

                    $badgeClass = "bg-amber-50 text-amber-700 border-amber-200";

                    $typeLabel = "แจ้งเตือน";

                } elseif ($type === 'success') {

                    $badgeClass = "bg-emerald-50 text-emerald-700 border-emerald-200";

                    $typeLabel = "ข่าวดี";

                }

                $annDate = !empty($ann['created_at']) ? date('d/m/Y', strtotime($ann['created_at'])) : (!empty($ann['createdAt']) ? date('d/m/Y', strtotime($ann['createdAt'])) : date('d/m/Y'));

                $annBody = $ann['content'] ?? $ann['message'] ?? '';

              ?>

                <div class="p-4 rounded-2xl bg-rose-50/40/80 border border-rose-100/80 hover:bg-white hover:shadow-xs transition">

                  <div class="flex items-start justify-between gap-2 mb-1.5">

                    <div class="flex items-center gap-2">

                      <h4 class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($ann['title'] ?? ''); ?></h4>

                      <span class="text-[10px] px-2 py-0.5 rounded-full font-bold border <?php echo $badgeClass; ?>"><?php echo $typeLabel; ?></span>

                    </div>

                    <span class="text-[10px] font-mono text-slate-400 shrink-0"><?php echo $annDate; ?></span>

                  </div>

                  <p class="text-xs text-slate-600 line-clamp-3 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($annBody)); ?></p>

                </div>

              <?php endforeach; ?>

            </div>

          </div>

          <?php endif; ?>



<!-- 🌟 ศูนย์รวมประวัติรายการ (Unified History Hub) 🌟 -->

          <div id="history-section" class="bg-white rounded-3xl border border-rose-100/90 shadow-sm p-5 sm:p-6 space-y-5 scroll-mt-20">

            

            <!-- Section Header -->

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100">

              <div class="flex items-center gap-3">

                <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-md shrink-0">

                  <i data-lucide="history" class="w-5 h-5"></i>

                </div>

                <div>

                  <div class="flex items-center gap-2">

                    <h3 class="font-display font-bold text-slate-800 text-base sm:text-lg">ประวัติรายการ</h3>

                    <span class="text-[11px] font-bold bg-rose-50 text-rose-700 px-2.5 py-0.5 rounded-full border border-blue-200">

                      <?php echo count($timelineEvents); ?> รายการ

                    </span>

                  </div>

                  <p class="text-xs text-slate-400 mt-0.5">ประวัติการแจ้งซ่อมบำรุง และประวัติการชำระเงินค่าห้องพัก</p>

                </div>

              </div>

            </div>



            <!-- Tab Navigation Bar (3 Clean Tabs, No Scrollbar Needed) -->

            <div class="flex items-center gap-2 pb-1 flex-wrap sm:flex-nowrap">

              <button type="button" onclick="switchHistoryTab('timeline')" id="btn-tab-timeline" class="history-tab-btn px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 bg-rose-500 text-white shadow-xs cursor-pointer">

                <i data-lucide="sparkles" class="w-4 h-4"></i>

                <span>ประวัติทั้งหมด</span>

                <span class="tab-badge px-2 py-0.5 rounded-full text-[10px] bg-white/20 text-white font-mono"><?php echo count($timelineEvents); ?></span>

              </button>



              <button type="button" onclick="switchHistoryTab('requests')" id="btn-tab-requests" class="history-tab-btn px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 bg-slate-100 text-slate-600 hover:bg-slate-200 cursor-pointer">

                <i data-lucide="wrench" class="w-4 h-4"></i>

                <span>ประวัติการแจ้งซ่อม</span>

                <span class="tab-badge px-2 py-0.5 rounded-full text-[10px] bg-slate-200 text-slate-700 font-mono"><?php echo count($myRequests); ?></span>

              </button>



              <button type="button" onclick="switchHistoryTab('invoices')" id="btn-tab-invoices" class="history-tab-btn px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 bg-slate-100 text-slate-600 hover:bg-slate-200 cursor-pointer">

                <i data-lucide="receipt" class="w-4 h-4"></i>

                <span>ประวัติการชำระเงิน</span>

                <span class="tab-badge px-2 py-0.5 rounded-full text-[10px] bg-slate-200 text-slate-700 font-mono"><?php echo count($paidInvoices); ?></span>

              </button>

            </div>



            <!-- ========================================== -->

            <!-- TAB PANE 1: ประวัติทั้งหมด (Timeline)         -->

            <!-- ========================================== -->

            <div id="tab-pane-timeline" class="history-tab-pane space-y-3">

              <?php if (empty($timelineEvents)): ?>

                <div class="text-center py-10 text-slate-400">

                  <div class="w-12 h-12 mx-auto mb-2 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center">

                    <i data-lucide="calendar" class="w-6 h-6"></i>

                  </div>

                  <p class="text-xs font-medium">ยังไม่มีประวัติการทำรายการในระบบ</p>

                </div>

              <?php else: ?>

                <div class="space-y-3">

                  <?php foreach ($timelineEvents as $ev): ?>

                    <div class="p-4 rounded-2xl bg-rose-50/40/80 hover:bg-white border border-rose-100/80 hover:border-rose-300 hover:shadow-sm transition flex flex-col sm:flex-row sm:items-center justify-between gap-3 cursor-pointer group" onclick="<?php echo ($ev['type'] === 'request' ? 'openRequestDetailModal(' . $ev['id'] . ')' : 'openTenantInvoiceModal(' . $ev['id'] . ')'); ?>">

                      <div class="flex items-start gap-3">

                        <div class="w-10 h-10 rounded-2xl <?php echo $ev['iconBg']; ?> flex items-center justify-center shrink-0 mt-0.5 shadow-2xs">

                          <i data-lucide="<?php echo $ev['icon']; ?>" class="w-5 h-5"></i>

                        </div>

                        <div class="space-y-0.5">

                          <div class="flex items-center gap-2 flex-wrap">

                            <span class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($ev['title']); ?></span>

                            <?php 

                              $bColor = 'bg-slate-100 text-slate-700';

                              if ($ev['statusColor'] === 'emerald') $bColor = 'bg-emerald-100 text-emerald-700';

                              elseif ($ev['statusColor'] === 'blue') $bColor = 'bg-blue-100 text-blue-700';

                              elseif ($ev['statusColor'] === 'amber') $bColor = 'bg-amber-100 text-amber-700';

                              elseif ($ev['statusColor'] === 'rose') $bColor = 'bg-rose-100 text-rose-700';

                            ?>

                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold <?php echo $bColor; ?>">

                              <?php echo htmlspecialchars($ev['statusText']); ?>

                            </span>

                          </div>

                          <p class="text-xs text-slate-500 line-clamp-1"><?php echo htmlspecialchars($ev['details']); ?></p>

                          <span class="text-[10px] text-slate-400 flex items-center gap-1 font-mono">

                            <i data-lucide="clock" class="w-3 h-3"></i>

                            <?php echo date('d/m/Y H:i', strtotime($ev['date'])); ?> น.

                          </span>

                        </div>

                      </div>



                      <?php if ($ev['type'] === 'request'): ?>

                        <div class="flex items-center gap-2 self-start sm:self-center shrink-0">

                          <button type="button" onclick="event.stopPropagation(); openRequestDetailModal(<?php echo $ev['id']; ?>)" class="px-3.5 py-1.5 rounded-xl bg-white hover:bg-rose-600 text-rose-600 hover:text-white border border-rose-200 hover:border-rose-600 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs">

                            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>

                            <span>ดูรายละเอียด</span>

                          </button>

                        </div>

                      <?php else: ?>

                        <div class="flex items-center gap-2 self-start sm:self-center shrink-0">

                          <button type="button" onclick="event.stopPropagation(); openTenantInvoiceModal(<?php echo $ev['id']; ?>)" class="px-3.5 py-1.5 rounded-xl bg-white hover:bg-emerald-600 text-emerald-600 hover:text-white border border-emerald-200 hover:border-emerald-600 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs">

                            <i data-lucide="receipt" class="w-3.5 h-3.5"></i>

                            <span>ดูรายละเอียดบิล</span>

                          </button>

                        </div>

                      <?php endif; ?>

                    </div>

                  <?php endforeach; ?>

                </div>

              <?php endif; ?>

            </div>



            <!-- ========================================== -->

            <!-- TAB PANE 2: ประวัติการแจ้งซ่อม (Requests)   -->

            <!-- ========================================== -->

            <div id="tab-pane-requests" class="history-tab-pane space-y-4 hidden">

              <div class="flex items-center justify-between pb-2">

                <span class="text-xs text-slate-500 font-medium">รายการแจ้งซ่อมและประวัติการบำรุงรักษาทั้งหมด</span>

                <a href="tenant_request.php" class="px-3.5 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-2xs">

                  <i data-lucide="plus" class="w-3.5 h-3.5"></i>

                  <span>แจ้งซ่อมใหม่</span>

                </a>

              </div>



              <?php if (empty($myRequests)): ?>

                <div class="text-center py-10 text-slate-400">

                  <div class="w-12 h-12 mx-auto mb-2 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center">

                    <i data-lucide="wrench" class="w-6 h-6"></i>

                  </div>

                  <p class="text-xs font-medium">ไม่มีประวัติงานแจ้งซ่อมบำรุง</p>

                </div>

              <?php else: ?>

                <div class="space-y-3">

                  <?php foreach ($myRequests as $req): ?>

                    <div class="p-4 sm:p-5 rounded-2xl bg-rose-50/40/80 border border-rose-100/80/80 hover:bg-white hover:border-rose-300 hover:shadow-md transition space-y-3 cursor-pointer group" onclick="openRequestDetailModal(<?php echo $req['requestId']; ?>)">

                      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">

                        <div class="flex items-center gap-2 flex-wrap">

                          <span class="text-xs font-bold text-slate-800 font-mono">#REQ-<?php echo $req['requestId']; ?></span>

                          <span class="text-[10px] bg-amber-100 text-amber-800 font-bold px-2 py-0.5 rounded-md"><?php echo htmlspecialchars($req['category']); ?></span>

                          <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-md font-mono border border-blue-200">⏱️ <?php echo intval($req['estimatedHours'] ?? 2); ?> ชม.</span>

                          <?php if(!empty($req['roomNumber'])): ?>

                            <span class="text-[10px] bg-slate-200 text-slate-700 font-bold px-2 py-0.5 rounded-md">ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?></span>

                          <?php endif; ?>

                        </div>

                        <div>

                          <?php if ($req['status'] === 'Completed'): ?>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">✓ เสร็จสิ้น / ส่งงานแล้ว</span>

                          <?php elseif ($req['status'] === 'Pending Approval'): ?>
                    <span class="text-xs font-bold px-3 py-1 rounded-xl bg-orange-50 text-orange-700 border border-orange-200 flex items-center gap-1.5 shadow-2xs">
                        <i data-lucide="clock" class="w-3.5 h-3.5 text-orange-600"></i> ช่างส่งงาน (รอนิติตรวจรับ)
                    </span>
                  <?php elseif ($req['status'] === 'In Progress'): ?>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-100 text-rose-700">⚡ กำลังดำเนินการ</span>

                          <?php elseif ($req['status'] === 'Cancelled'): ?>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-200 text-slate-700">✕ ยกเลิกแล้ว</span>

                          <?php else: ?>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700">⏳ รอดำเนินการ</span>

                          <?php endif; ?>

                        </div>

                      </div>



                      <p class="text-xs text-slate-700 font-medium"><?php echo nl2br(htmlspecialchars($req['description'])); ?></p>



                      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-[11px] text-slate-500 pt-1 border-t border-rose-100/80/50">

                        <div>

                          <span class="text-slate-400">วันที่แจ้ง:</span>

                          <span class="font-medium text-slate-700"><?php echo date('d/m/Y H:i', strtotime($req['requestDate'])); ?> น.</span>

                        </div>

                        <div>

                          <span class="text-slate-400">เวลานัดเข้า:</span>

                          <span class="font-medium text-slate-700"><?php echo !empty($req['preferredDate']) ? date('d/m/Y', strtotime($req['preferredDate'])) . ' (' . htmlspecialchars($req['preferredTime'] ?? 'ทั้งวัน') . ')' : 'ตามความสะดวกของช่าง'; ?></span>

                        </div>

                      </div>

                      <?php if ($req['status'] === 'Completed'): 
                        $staffRoleTh = in_array($req['staffRole'] ?? '', ['Cleaner', 'Housekeeper']) ? 'แม่บ้าน' : 'ช่างซ่อมบำรุง';
                        $cardDur = (!empty($req['assignedAt']) && !empty($req['completedAt'])) 
                               ? duration_th($req['assignedAt'], $req['completedAt']) 
                               : ((!empty($req['requestDate']) && !empty($req['completedAt'])) ? duration_th($req['requestDate'], $req['completedAt']) : '');
                      ?>
                        <div class="p-3 rounded-2xl bg-emerald-50/90 border border-emerald-200 text-xs flex flex-wrap items-center justify-between gap-2 shadow-2xs">
                          <div class="flex items-center gap-2">
                            <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                            <div>
                              <span class="font-bold text-emerald-950">
                                <?php if(!empty($req['staffName'])): ?>
                                  ผู้ปฏิบัติงาน: คุณ<?php echo htmlspecialchars($req['staffName']); ?> (<?php echo $staffRoleTh; ?>)
                                <?php else: ?>
                                  นิติบุคคลดำเนินการเสร็จสิ้นเรียบร้อย
                                <?php endif; ?>
                              </span>
                              <?php if(!empty($req['completedAt'])): ?>
                                <span class="text-[11px] text-emerald-800 font-mono ml-1">เมื่อ <?php echo date('d/m/Y H:i', strtotime($req['completedAt'])); ?> น.</span>
                              <?php endif; ?>
                            </div>
                          </div>
                          <?php if(!empty($cardDur)): ?>
                            <span class="text-[10px] bg-white text-emerald-800 font-semibold px-2 py-0.5 rounded-lg border border-emerald-200">⏱️ ใช้เวลา: <?php echo $cardDur; ?></span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>



                      <div class="pt-2 flex items-center justify-between border-t border-slate-100 flex-wrap gap-2">

                        <div class="flex items-center gap-2">

                          <?php if (!empty($req['requestImage'])): ?>

                            <button type="button" onclick="event.stopPropagation(); openImagePreviewModal('<?php echo htmlspecialchars($req['requestImage'], ENT_QUOTES); ?>', 'รูปภาพปัญหาหน้างาน #REQ-<?php echo $req['requestId']; ?>')" class="px-2.5 py-1 bg-white hover:bg-slate-100 text-slate-700 border border-rose-100/80 rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-2xs">

                              <i data-lucide="image" class="w-3.5 h-3.5 text-amber-600"></i> รูปก่อนทำ

                            </button>

                          <?php endif; ?>

                          <?php if ($req['status'] === 'Completed' && !empty($req['completionImage'])): ?>

                            <button type="button" onclick="event.stopPropagation(); openImagePreviewModal('<?php echo htmlspecialchars($req['completionImage'], ENT_QUOTES); ?>', 'รูปภาพผลงานส่งมอบ #REQ-<?php echo $req['requestId']; ?>')" class="px-2.5 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-2xs">

                              <i data-lucide="sparkles" class="w-3.5 h-3.5 text-emerald-600"></i> รูปหลังทำ

                            </button>

                          <?php elseif ($req['status'] === 'Pending Approval'): ?>

                            <span class="px-2.5 py-1 bg-orange-50 text-orange-700 border border-orange-200 rounded-lg text-xs font-bold flex items-center gap-1 shadow-2xs">
                              <i data-lucide="clock" class="w-3.5 h-3.5 text-orange-600"></i> รอนิติตรวจรับ
                            </span>

                          <?php endif; ?>

                        </div>



                        <button type="button" onclick="event.stopPropagation(); openRequestDetailModal(<?php echo $req['requestId']; ?>)" class="px-3.5 py-1.5 bg-rose-50 hover:bg-blue-100 text-rose-700 border border-blue-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs ml-auto cursor-pointer group-hover:bg-rose-500 group-hover:text-white group-hover:border-blue-600">

                          <i data-lucide="eye" class="w-3.5 h-3.5"></i>

                          <span>ดูรายละเอียดงาน</span>

                        </button>

                      </div>

                    </div>

                  <?php endforeach; ?>

                </div>

              <?php endif; ?>

            </div>



            <!-- ========================================== -->

            <!-- TAB PANE 3: ประวัติการชำระเงิน (Paid Invoices) -->

            <!-- ========================================== -->

            <div id="tab-pane-invoices" class="history-tab-pane space-y-4 hidden">

              <div class="flex items-center justify-between pb-2">

                <span class="text-xs text-slate-500 font-medium">ประวัติการชำระเงินค่าห้องพักและค่าน้ำค่าไฟ</span>

                <a href="view_invoices.php" class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-2xs">

                  <i data-lucide="credit-card" class="w-3.5 h-3.5"></i>

                  <span>ดูบิลทั้งหมด / แนบสลิป</span>

                </a>

              </div>



              <?php if (empty($paidInvoices)): ?>

                <div class="text-center py-10 text-slate-400">

                  <div class="w-12 h-12 mx-auto mb-2 rounded-2xl bg-emerald-50 text-emerald-500 flex items-center justify-center">

                    <i data-lucide="receipt" class="w-6 h-6"></i>

                  </div>

                  <p class="text-xs font-medium">ยังไม่มีประวัติการชำระเงินที่เสร็จสมบูรณ์</p>

                </div>

              <?php else: ?>

                <div class="space-y-3">

                  <?php foreach ($paidInvoices as $inv): 

                    $wUnits = max(0, (int)$inv['waterCurr'] - (int)$inv['waterPrev']);

                    $eUnits = max(0, (int)$inv['electricCurr'] - (int)$inv['electricPrev']);

                    $wTotal = $wUnits * (float)$inv['waterUnitRate'];

                    $eTotal = $eUnits * (float)$inv['electricUnitRate'];

                  ?>

                    <div onclick="openTenantInvoiceModal(<?php echo $inv['invoiceId']; ?>)" class="p-4 rounded-2xl bg-rose-50/40/80 border border-rose-100/80 hover:bg-white hover:border-emerald-300 hover:shadow-md transition space-y-3 cursor-pointer group">

                      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2 border-b border-rose-100/80/50">

                        <div class="flex items-center gap-2">

                          <div class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">

                            <i data-lucide="receipt" class="w-4 h-4"></i>

                          </div>

                          <div>

                            <h4 class="text-xs font-bold text-slate-800">รอบเดือน <?php echo htmlspecialchars($inv['billingMonth']); ?></h4>

                            <span class="text-[10px] text-slate-400 font-mono">#INV-<?php echo $inv['invoiceId']; ?> • ห้อง <?php echo htmlspecialchars($inv['roomNumber'] ?? $myRoomNumber); ?></span>

                          </div>

                        </div>

                        <div>

                          <?php if ($inv['status'] === 'Paid'): ?>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-700">✓ ชำระเงินเรียบร้อย</span>

                          <?php elseif ($inv['status'] === 'Pending Approval'): ?>

                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700">⏳ รอตรวจสอบสลิป</span>

                          <?php endif; ?>

                        </div>

                      </div>



                      <!-- Breakdown -->

                      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs bg-white p-3 rounded-xl border border-slate-100">

                        <div>

                          <span class="text-slate-400 text-[10px] block">ค่าเช่าห้อง</span>

                          <span class="font-bold text-slate-700 font-mono">฿<?php echo number_format($inv['rentPrice'], 2); ?></span>

                        </div>

                        <div>

                          <span class="text-slate-400 text-[10px] block">ค่าน้ำ (<?php echo $wUnits; ?> หน่วย)</span>

                          <span class="font-bold text-rose-600 font-mono">฿<?php echo number_format($wTotal, 2); ?></span>

                        </div>

                        <div>

                          <span class="text-slate-400 text-[10px] block">ค่าไฟ (<?php echo $eUnits; ?> หน่วย)</span>

                          <span class="font-bold text-amber-600 font-mono">฿<?php echo number_format($eTotal, 2); ?></span>

                        </div>

                        <div>

                          <span class="text-slate-400 text-[10px] block">ยอดสุทธิรวม</span>

                          <span class="font-display font-bold text-slate-900 text-sm font-mono">฿<?php echo number_format($inv['totalAmount'], 2); ?></span>

                        </div>

                      </div>



                      <!-- Actions -->

                      <div class="flex items-center justify-between pt-1 flex-wrap gap-2">

                        <div class="flex items-center gap-2">

                          <button type="button" onclick="event.stopPropagation(); openTenantInvoiceModal(<?php echo $inv['invoiceId']; ?>)" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white border border-emerald-200 hover:border-emerald-600 rounded-xl text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-2xs">

                            <i data-lucide="eye" class="w-3.5 h-3.5"></i> ดูรายละเอียดบิล

                          </button>

                          <?php if (!empty($inv['slipImage'])): ?>

                            <button type="button" onclick="openImagePreviewModal('<?php echo htmlspecialchars($inv['slipImage'], ENT_QUOTES); ?>', 'สลิปโอนเงินรอบ <?php echo htmlspecialchars($inv['billingMonth']); ?>')" class="px-3 py-1.5 bg-rose-50 hover:bg-blue-100 text-rose-700 rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer">

                              <i data-lucide="file-image" class="w-3.5 h-3.5"></i> ดูสลิปโอนเงิน

                            </button>

                          <?php endif; ?>

                          <?php if ($inv['status'] === 'Paid'): ?>

                            <a href="print_receipt.php?invoice_id=<?php echo $inv['invoiceId']; ?>" target="_blank" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-bold transition flex items-center gap-1">

                              <i data-lucide="printer" class="w-3.5 h-3.5"></i> พิมพ์ใบเสร็จ

                            </a>

                          <?php endif; ?>

                        </div>

                      </div>

                    </div>

                  <?php endforeach; ?>

                </div>

              <?php endif; ?>

            </div>



          </div>



        </div><!-- ฝั่งขวา (1 ส่วน): สัญญาเช่า & ข้อมูลห้องพัก, มิเตอร์, ผู้ร่วมอาศัย -->

        <div class="space-y-6" id="contract-section">



          <!-- สรุปสัญญาเช่าและห้องพัก -->

          <?php if($myRoom): ?>

          <div class="bg-white rounded-3xl border border-rose-100/90 shadow-sm p-6 space-y-4">

            <div class="flex items-center justify-between pb-3 border-b border-slate-100">

              <div class="flex items-center gap-2.5">

                <div class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">

                  <i data-lucide="file-text" class="w-4 h-4"></i>

                </div>

                <div>

                  <h3 class="font-display font-bold text-slate-800 text-sm sm:text-base">ข้อมูลสัญญาและห้องพัก</h3>

                  <p class="text-[11px] text-slate-400">สัญญาเช่าปัจจุบัน (Active)</p>

                </div>

              </div>

            </div>



            <div class="space-y-3 text-xs">

              <div class="flex justify-between items-center py-1.5 border-b border-slate-50">

                <span class="text-slate-500">หมายเลขห้อง</span>

                <span class="font-bold text-slate-800 font-mono text-sm">ห้อง <?php echo htmlspecialchars($myRoom['roomNumber']); ?></span>

              </div>

              <div class="flex justify-between items-center py-1.5 border-b border-slate-50">

                <span class="text-slate-500">ประเภทห้อง / ชั้น</span>

                <span class="font-bold text-slate-800"><?php echo ($myRoom['roomType'] === 'Air') ? 'ห้องแอร์' : 'ห้องพัดลม'; ?> (ชั้น <?php echo htmlspecialchars($myRoom['floor'] ?? '1'); ?>)</span>

              </div>

              <div class="flex justify-between items-center py-1.5 border-b border-slate-50">

                <span class="text-slate-500">ค่าเช่ารายเดือน</span>

                <span class="font-bold text-rose-600 font-mono text-sm">฿<?php echo number_format($myRoom['rentPrice'], 2); ?>/ด.</span>

              </div>

              <div class="flex justify-between items-center py-1.5 border-b border-slate-50">

                <span class="text-slate-500">เงินประกันห้องพัก</span>

                <span class="font-bold text-slate-800 font-mono">฿<?php echo number_format($myRoom['depositAmount'], 2); ?></span>

              </div>

              <div class="flex justify-between items-center py-1.5 border-b border-slate-50">

                <span class="text-slate-500">ระยะเวลาสัญญา</span>

                <span class="font-medium text-slate-700"><?php echo date('d/m/Y', strtotime($myRoom['startDate'])); ?> - <?php echo date('d/m/Y', strtotime($myRoom['endDate'])); ?></span>

              </div>

              <div class="flex justify-between items-center py-1.5 border-b border-slate-50">

                <span class="text-slate-500">มิเตอร์น้ำล่าสุด</span>

                <span class="font-bold text-rose-600 font-mono"><?php echo htmlspecialchars($latestMeterWater); ?> หน่วย</span>

              </div>

              <div class="flex justify-between items-center py-1.5">

                <span class="text-slate-500">มิเตอร์ไฟล่าสุด</span>

                <span class="font-bold text-amber-600 font-mono"><?php echo htmlspecialchars($latestMeterElectric); ?> หน่วย</span>

              </div>

            </div>



            <?php if(!empty($myRoom['leaseImage'])): ?>

              <div class="pt-2">

                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($myRoom['leaseImage'], ENT_QUOTES); ?>')" class="w-full py-2.5 px-3 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer shadow-2xs">

                  <i data-lucide="file-check" class="w-4 h-4 text-emerald-600"></i> ดูเอกสารสัญญาเช่า 📄

                </button>

              </div>

            <?php endif; ?>



            <!-- 🌟 Dropdown รายการเช็คลิสต์อุปกรณ์ประจำห้อง 🌟 -->

            <?php if(!empty($tenantChecklist)): ?>

              <div class="pt-2 border-t border-slate-100">

                <details class="group bg-rose-50/40/80 hover:bg-slate-100/80 border border-rose-100/80/80 rounded-2xl overflow-hidden transition-all duration-200">

                  <summary class="flex items-center justify-between p-3.5 cursor-pointer select-none list-none text-xs font-bold text-slate-700 hover:text-rose-600 transition">

                    <div class="flex items-center gap-2">

                      <div class="w-6 h-6 rounded-lg bg-blue-100 text-rose-600 flex items-center justify-center shrink-0">

                        <i data-lucide="clipboard-check" class="w-3.5 h-3.5"></i>

                      </div>

                      <span>เช็คลิสต์อุปกรณ์ประจำห้อง</span>

                      <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-1.5 py-0.2 rounded-full border border-blue-200">

                        <?php echo count($tenantChecklist); ?>

                      </span>

                    </div>

                    <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 group-open:rotate-180 transition-transform duration-200"></i>

                  </summary>

                  

                  <div class="p-3 pt-1 border-t border-rose-100/80/60 bg-white space-y-2">

                    <div class="max-h-56 overflow-y-auto divide-y divide-slate-100 text-xs scrollbar-thin">

                      <?php foreach($tenantChecklist as $idx => $cl): ?>

                        <div class="py-2 flex items-center justify-between gap-2">

                          <div class="min-w-0 pr-2">

                            <div class="font-medium text-slate-800 truncate"><?php echo htmlspecialchars($cl['itemName']); ?></div>

                            <div class="text-[10px] text-slate-400"><?php echo htmlspecialchars($cl['category']); ?> • จำนวน <?php echo htmlspecialchars($cl['quantity']); ?> ชิ้น</div>

                          </div>

                          <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 shrink-0">✓ ปกติ</span>

                        </div>

                      <?php endforeach; ?>

                    </div>



                    <div class="pt-2 border-t border-slate-100 text-center">

                      <button type="button" onclick="openChecklistModal()" class="w-full py-2 bg-rose-50 hover:bg-blue-100 text-rose-700 font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer">

                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i> ดูตารางเช็คลิสต์และราคาเต็ม

                      </button>

                    </div>

                  </div>

                </details>

              </div>

            <?php endif; ?>

          </div>

          <?php endif; ?>



          <!-- ผู้ร่วมพักอาศัย -->

          <div class="bg-white rounded-3xl border border-rose-100/90 shadow-sm p-6 space-y-3">

            <div class="flex items-center justify-between pb-2 border-b border-slate-100">

              <div class="flex items-center gap-2.5">

                <div class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center">

                  <i data-lucide="users" class="w-4 h-4"></i>

                </div>

                <div>

                  <h3 class="font-display font-bold text-slate-800 text-sm">ผู้ร่วมพักอาศัย</h3>

                  <p class="text-[11px] text-slate-400">สมาชิกในห้องพัก</p>

                </div>

              </div>

              <a href="tenant_coresidents.php" class="text-[11px] font-bold text-rose-600 hover:text-blue-800">

                + เพิ่มสมาชิก

              </a>

            </div>



            <?php if(empty($coresidents)): ?>

              <p class="text-xs text-slate-400 py-2">ยังไม่มีผู้ร่วมพักอาศัยที่ลงทะเบียน</p>

            <?php else: ?>

              <div class="space-y-2">

                <?php foreach($coresidents as $co): ?>

                  <div class="flex items-center justify-between p-2.5 rounded-xl bg-rose-50/40 text-xs">

                    <span class="font-medium text-slate-700"><?php echo htmlspecialchars($co['fullName']); ?></span>

                    <?php if($co['status'] === 'Approved'): ?>

                      <span class="text-[10px] text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded-full">อนุมัติแล้ว</span>

                    <?php else: ?>

                      <span class="text-[10px] text-amber-600 font-bold bg-amber-50 px-2 py-0.5 rounded-full">รอตรวจสอบ</span>

                    <?php endif; ?>

                  </div>

                <?php endforeach; ?>

              </div>

            <?php endif; ?>

          </div>



        </div>



      </div>



    </div>

  

  <!-- 🌟 MODAL จอใหญ่: รายละเอียดงานแบบเต็ม แบ่งกรอบชัดเจนระหว่างผู้เช่า และช่าง/แม่บ้าน 🌟 -->

  <div id="requestDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeRequestDetailModal()">

      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-rose-100/80/80 flex flex-col" onclick="event.stopPropagation()">

          

          <!-- Modal Top Header -->

          <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-rose-50/40/90 shrink-0">

              <div class="flex items-center gap-2.5 flex-wrap">

                  <span class="text-xs font-bold px-3 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">

                      ห้อง <span id="dash_req_detail_room">-</span>

                  </span>

                  <span id="dash_req_detail_code" class="text-xs font-mono font-bold text-slate-600 bg-white px-2.5 py-1 rounded-xl border border-rose-100/80 shadow-2xs">

                      REQ-0000

                  </span>

                  <div id="dash_req_detail_status_badge" class="text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5 shadow-2xs"></div>

              </div>

              <div class="flex items-center gap-2">

                  <button type="button" onclick="closeRequestDetailModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">

                      <i data-lucide="x" class="w-5 h-5"></i>

                  </button>

              </div>

          </div>



          <!-- Modal Scrollable Content: 2 Clear Side-by-Side Frames (จอใหญ่) -->

          <div class="p-5 sm:p-6 md:p-8 space-y-6 overflow-y-auto flex-1 min-h-0">

              

              <!-- 🌟 แบ่ง 2 กรอบหลัก: ฝั่งผู้เช่า (ซ้าย) VS ฝั่งช่าง/แม่บ้าน (ขวา) 🌟 -->

              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">

                  

                  <!-- ======================================================== -->

                  <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลการแจ้งของผู้เช่า (Tenant Frame)   -->

                  <!-- ======================================================== -->

                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-blue-200 bg-gradient-to-b from-blue-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">

                      <div>

                          <!-- Header กรอบผู้เช่า -->

                          <div class="flex items-center justify-between pb-3.5 border-b border-blue-200/80 mb-4">

                              <div class="flex items-center gap-2.5">

                                  <div class="w-9 h-9 rounded-2xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-xs">

                                      <i data-lucide="user" class="w-5 h-5"></i>

                                  </div>

                                  <div>

                                      <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลของผู้เช่า</h4>

                                      <p class="text-[11px] text-rose-700 font-medium">รายละเอียดปัญหาที่แจ้ง และวันเวลาที่นัดหมาย</p>

                                  </div>

                              </div>

                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-100/80 text-blue-800 border border-blue-200 font-mono">

                                  ผู้เช่าห้องพัก

                              </span>

                          </div>



                          <!-- ประเภทงาน & กำหนดเวลา -->

                          <div class="space-y-1 mb-3.5">

                              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">ประเภทงาน / ปัญหาที่แจ้ง</span>

                              <div class="flex items-center justify-between gap-2 flex-wrap">

                                  <h3 class="text-base sm:text-lg font-bold text-slate-900" id="dash_req_detail_category">-</h3>

                                  <div class="flex items-center gap-1.5 flex-wrap">

                                      <span id="dash_req_detail_hours_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 font-mono shadow-2xs">⏱️ 2 ชม.</span>

                                      <span id="dash_req_detail_entry_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl border shadow-2xs"></span>

                                  </div>

                              </div>

                          </div>



                          <!-- รายละเอียดปัญหา -->

                          <div class="bg-white p-4 rounded-2xl border border-blue-200/80 shadow-2xs mb-4">

                              <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider mb-1.5">รายละเอียดปัญหาที่พบ</span>

                              <p class="text-xs sm:text-sm text-slate-700 whitespace-pre-line leading-relaxed font-normal" id="dash_req_detail_desc">-</p>

                          </div>



                          <!-- นัดหมายวันเวลาเข้าห้อง -->

                          <div id="dash_req_detail_appointment" class="p-3.5 bg-indigo-50/80 border border-indigo-200/90 rounded-2xl flex items-center gap-3 mb-4 text-xs shadow-2xs">

                              <div class="w-8 h-8 rounded-xl bg-indigo-600 text-white flex items-center justify-center shrink-0">

                                  <i data-lucide="calendar-clock" class="w-4 h-4"></i>

                              </div>

                              <div>

                                  <span class="text-[10px] text-indigo-500 font-bold uppercase tracking-wider block">วันและช่วงเวลาที่นัดหมายเข้าห้อง</span>

                                  <span id="dash_req_detail_pref_text" class="font-bold text-indigo-950 text-xs sm:text-sm"></span>

                              </div>

                          </div>

                      </div>



                      <!-- 📷 รูปก่อนทำ (ปัญหาที่คุณแจ้ง) BEFORE -->

                      <div class="bg-white border-2 border-dashed border-amber-300 rounded-2xl p-3.5 shadow-2xs">

                          <div class="flex items-center justify-between mb-2.5">

                              <span class="text-xs font-bold text-amber-900 flex items-center gap-1.5">

                                  <i data-lucide="camera" class="w-4 h-4 text-amber-600"></i> รูปถ่ายปัญหาหน้างาน (ก่อนทำ)

                              </span>

                              <span class="text-[10px] bg-amber-100 text-amber-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-amber-200">BEFORE</span>

                          </div>

                          <div id="dash_req_detail_before_img_container" class="min-h-[160px] flex items-center justify-center">

                              <!-- Dynamic Before Image -->

                          </div>

                      </div>

                  </div>



                  <!-- ======================================================== -->

                  <!-- 🟩 กรอบที่ 2: ส่วนการปฏิบัติงานของช่าง/แม่บ้าน (Staff Frame) -->

                  <!-- ======================================================== -->

                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">

                      <div>

                          <!-- Header กรอบช่าง/แม่บ้าน -->

                          <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">

                              <div class="flex items-center gap-2.5">

                                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">

                                      <i data-lucide="wrench" class="w-5 h-5"></i>

                                  </div>

                                  <div>

                                      <h4 class="font-display font-bold text-slate-900 text-base">ส่วนการปฏิบัติงานของช่าง / แม่บ้าน</h4>

                                      <p class="text-[11px] text-emerald-700 font-medium">เจ้าหน้าที่ผู้รับผิดชอบ ไทม์ไลน์ และผลงานที่ส่งมอบ</p>

                                  </div>

                              </div>

                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">

                                  ทีมช่าง & แม่บ้าน

                              </span>

                          </div>



                          <!-- เจ้าหน้าที่ผู้รับผิดชอบงาน -->

                          <div id="dash_req_detail_staff_box" class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">

                              <div class="flex items-center gap-3 text-slate-700 font-medium">

                                  <div class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 shadow-2xs">

                                      <i data-lucide="user-check" class="w-5 h-5"></i>

                                  </div>

                                  <div>

                                      <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">เจ้าหน้าที่ผู้รับผิดชอบ</span>

                                      <span class="font-bold text-slate-900 text-sm sm:text-base" id="dash_req_detail_staff_name">-</span>

                                  </div>

                              </div>

                              <a id="dash_req_detail_staff_phone_btn" href="#" class="hidden inline-flex items-center gap-2 text-xs font-bold text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3.5 py-2 rounded-xl transition shadow-2xs">

                                  <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i>

                                  <span class="staff-phone-text">โทรติดต่อเจ้าหน้าที่</span>

                              </a>

                          </div>



                          <!-- บันทึกวันและเวลาการทำงาน (ไทม์ไลน์ 3 สเต็ป) -->

                          <div class="space-y-2 mb-4">

                              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">บันทึกวันและเวลาการทำงาน</span>

                              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">

                                  

                                  <!-- 1. แจ้งเมื่อ -->

                                  <div class="bg-white border border-amber-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">

                                      <span class="text-[11px] font-bold text-amber-700 flex items-center gap-1.5">

                                          <i data-lucide="send" class="w-3.5 h-3.5 text-amber-600"></i> 1. แจ้งเมื่อ

                                      </span>

                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="dash_req_detail_req_date">-</div>

                                      <div class="text-[10px] text-amber-800 font-semibold mt-0.5" id="dash_req_detail_req_ago"></div>

                                  </div>



                                  <!-- 2. รับงานเมื่อ -->

                                  <div class="bg-white border border-blue-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">

                                      <span class="text-[11px] font-bold text-rose-700 flex items-center gap-1.5">

                                          <i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-600"></i> 2. รับงานเมื่อ

                                      </span>

                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="dash_req_detail_assigned_date">-</div>

                                      <div class="text-[10px] text-blue-800 font-semibold mt-0.5" id="dash_req_detail_assigned_ago"></div>

                                  </div>



                                  <!-- 3. จบงานเมื่อ -->

                                  <div id="dash_req_detail_completed_box" class="bg-white border border-rose-100/80 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">

                                      <span class="text-[11px] font-bold text-slate-700 flex items-center gap-1.5">

                                          <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> 3. ส่งงาน/จบงาน

                                      </span>

                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="dash_req_detail_completed_date">-</div>

                                      <div class="text-[10px] text-emerald-800 font-semibold mt-0.5" id="dash_req_detail_duration"></div>

                                  </div>

                              </div>

                          </div>

                      </div>



                      <!-- ✨ รูปหลังทำ (ผลงานที่ช่างส่งมอบ) AFTER -->

                      <div class="bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs">

                          <div class="flex items-center justify-between mb-2.5">

                              <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">

                                  <i data-lucide="sparkles" class="w-4 h-4 text-emerald-600"></i> รูปผลงานการซ่อม (หลังทำ)

                              </span>

                              <span class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-emerald-200">AFTER</span>

                          </div>

                          <div id="dash_req_detail_after_img_container" class="min-h-[160px] flex items-center justify-center">

                              <!-- Dynamic After Image -->

                          </div>

                      </div>

                  </div>



              </div>



              <!-- ======================================================== -->

              <!-- 🟪 กรอบที่ 3: ส่วนการประเมินและข้อความติชม (Feedback)      -->

              <!-- ======================================================== -->

              <div id="dash_req_detail_comment_section" class="bg-purple-50/70 border-2 border-purple-200 rounded-3xl p-5 shadow-2xs">

                  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">

                      <span class="text-xs sm:text-sm font-bold text-purple-950 flex items-center gap-2">

                          <i data-lucide="message-square-quote" class="w-4 h-4 text-purple-600"></i>

                          ความคิดเห็นและการติชมผลงานจากผู้เช่า

                      </span>

                      <span class="text-[11px] bg-white text-purple-700 font-bold px-3 py-1 rounded-full border border-purple-200 flex items-center gap-1.5 shadow-2xs">

                          <i data-lucide="lock" class="w-3.5 h-3.5 text-purple-500"></i> ลับเฉพาะคุณและนิติบุคคล

                      </span>

                  </div>

                  <div id="dash_req_detail_comment_display">

                      <!-- Dynamic comment content -->

                  </div>

              </div>



          </div>



          <!-- Modal Footer -->

          <div class="px-6 py-4 border-t border-slate-100 bg-rose-50/40 flex items-center justify-between gap-3 shrink-0">

              <button type="button" onclick="closeRequestDetailModal()" class="px-5 py-2.5 bg-white hover:bg-slate-100 border border-rose-100/80 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer shadow-2xs">

                  ปิดหน้าต่าง

              </button>



              <div id="dash_req_detail_footer_action">

                  <!-- Dynamic Action button -->

              </div>

          </div>

      </div>

  </div>



  <!-- 🌟 MODAL จอใหญ่: รายละเอียดใบแจ้งหนี้/บิลแบบเต็ม แบ่ง 2 กรอบชัดเจน (ค่าใช้จ่าย VS สลิปและการชำระเงิน) 🌟 -->
  <div id="tenantInvoiceDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeTenantInvoiceModal()">
      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200 flex flex-col" onclick="event.stopPropagation()">
          
          <!-- Modal Top Header -->
          <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/90 shrink-0">
              <div class="flex items-center gap-2.5 flex-wrap">
                  <span class="text-xs font-bold px-3 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">
                      ห้อง <span id="dash_inv_detail_room">-</span>
                  </span>
                  <span id="dash_inv_detail_code" class="text-xs font-mono font-bold text-slate-600 bg-white px-2.5 py-1 rounded-xl border border-slate-200 shadow-2xs">
                      INV-0000
                  </span>
                  <span class="text-xs font-bold text-slate-700 bg-white px-2.5 py-1 rounded-xl border border-slate-200 shadow-2xs">
                      รอบเดือน <span id="dash_inv_detail_month" class="text-emerald-700 font-bold">-</span>
                  </span>
                  <div id="dash_inv_detail_status_badge" class="text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5 shadow-2xs"></div>
              </div>
              <div class="flex items-center gap-2">
                  <button type="button" onclick="toggleFullscreenModal(this)" class="w-9 h-9 rounded-2xl bg-white hover:bg-slate-100 text-slate-600 transition flex items-center justify-center border border-slate-200 cursor-pointer shrink-0 shadow-2xs" title="ขยายเต็มหน้าจอ 100%">
                      <i data-lucide="eye" class="w-4 h-4"></i>
                  </button>
                  <button type="button" onclick="closeTenantInvoiceModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">
                      <i data-lucide="x" class="w-5 h-5"></i>
                  </button>
              </div>
          </div>

          <!-- Modal Scrollable Content: 2 Clear Side-by-Side Frames (จอใหญ่) -->
          <div class="p-5 sm:p-6 md:p-8 space-y-6 overflow-y-auto flex-1 min-h-0">
              
              <!-- 🌟 แบ่ง 2 กรอบหลัก: ฝั่งค่าใช้จ่าย (ซ้าย) VS ฝั่งชำระเงินและสลิป (ขวา) 🌟 -->
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">
                  
                  <!-- ======================================================== -->
                  <!-- 🟩 กรอบที่ 1: รายละเอียดค่าใช้จ่ายและมิเตอร์ (Invoice Breakdown) -->
                  <!-- ======================================================== -->
                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/40 to-slate-50/20 flex flex-col justify-between shadow-2xs space-y-5">
                      <div>
                          <!-- Header กรอบค่าใช้จ่าย -->
                          <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">
                              <div class="flex items-center gap-2.5">
                                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                                      <i data-lucide="receipt" class="w-5 h-5"></i>
                                  </div>
                                  <div>
                                      <h4 class="font-display font-bold text-slate-900 text-base">รายละเอียดค่าใช้จ่ายประจำเดือน</h4>
                                      <p class="text-[11px] text-emerald-700 font-medium">แจกแจงรายการค่าห้อง ค่าน้ำ และค่าไฟฟ้าตามมิเตอร์จริง</p>
                                  </div>
                              </div>
                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">
                                  ใบแจ้งหนี้
                              </span>
                          </div>

                          <!-- Quick Summary Grid -->
                          <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mb-4 text-xs">
                              <div class="bg-white p-3 rounded-2xl border border-emerald-100/80 shadow-2xs">
                                  <span class="text-slate-400 block text-[10px]">ผู้เช่า / ผู้พักอาศัย</span>
                                  <span id="dash_inv_detail_tenant" class="font-bold text-slate-800 text-xs truncate block">-</span>
                              </div>
                              <div class="bg-white p-3 rounded-2xl border border-emerald-100/80 shadow-2xs">
                                  <span class="text-slate-400 block text-[10px]">ห้องพัก</span>
                                  <span class="font-bold text-slate-800 text-xs font-mono">ห้อง <span id="dash_inv_detail_room_badge">-</span></span>
                              </div>
                              <div class="bg-white p-3 rounded-2xl border border-emerald-100/80 shadow-2xs col-span-2 sm:col-span-1">
                                  <span class="text-slate-400 block text-[10px]">วันที่ออกบิล</span>
                                  <span id="dash_inv_detail_date" class="font-bold text-slate-800 text-xs font-mono">-</span>
                              </div>
                          </div>

                          <!-- Itemized Table Breakdown -->
                          <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-2xs space-y-0">
                              <div class="p-3 bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-600 flex items-center justify-between">
                                  <span>รายการค่าใช้จ่าย</span>
                                  <span>จำนวนเงิน (บาท)</span>
                              </div>

                              <!-- ค่าเช่าห้อง -->
                              <div class="p-3.5 border-b border-slate-100 flex items-center justify-between text-xs hover:bg-slate-50/50 transition">
                                  <div class="flex items-center gap-2">
                                      <div class="w-7 h-7 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                                          <i data-lucide="home" class="w-3.5 h-3.5"></i>
                                      </div>
                                      <div>
                                          <div class="font-bold text-slate-800">ค่าเช่าห้องพักประจำเดือน</div>
                                          <div class="text-[10px] text-slate-400">อัตราค่าเช่าคงที่ตามสัญญาเช่า</div>
                                      </div>
                                  </div>
                                  <div class="font-bold text-slate-800 font-mono text-sm">฿<span id="dash_inv_detail_rent">0.00</span></div>
                              </div>

                              <!-- ค่าน้ำประปา -->
                              <div class="p-3.5 border-b border-slate-100 flex items-center justify-between text-xs hover:bg-slate-50/50 transition">
                                  <div class="flex items-center gap-2">
                                      <div class="w-7 h-7 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
                                          <i data-lucide="droplet" class="w-3.5 h-3.5"></i>
                                      </div>
                                      <div>
                                          <div class="font-bold text-slate-800">ค่าน้ำประปา</div>
                                          <div class="text-[10px] text-slate-500 font-mono">
                                              มิเตอร์: <span id="dash_inv_detail_w_prev">0</span> → <span id="dash_inv_detail_w_curr">0</span> 
                                              (<span id="dash_inv_detail_w_units">0</span> หน่วย @ ฿<span id="dash_inv_detail_w_rate">0</span>)
                                          </div>
                                      </div>
                                  </div>
                                  <div class="font-bold text-rose-600 font-mono text-sm">฿<span id="dash_inv_detail_w_total">0.00</span></div>
                              </div>

                              <!-- ค่าไฟฟ้า -->
                              <div class="p-3.5 border-b border-slate-100 flex items-center justify-between text-xs hover:bg-slate-50/50 transition">
                                  <div class="flex items-center gap-2">
                                      <div class="w-7 h-7 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center shrink-0">
                                          <i data-lucide="zap" class="w-3.5 h-3.5"></i>
                                      </div>
                                      <div>
                                          <div class="font-bold text-slate-800">ค่าไฟฟ้า</div>
                                          <div class="text-[10px] text-slate-500 font-mono">
                                              มิเตอร์: <span id="dash_inv_detail_e_prev">0</span> → <span id="dash_inv_detail_e_curr">0</span> 
                                              (<span id="dash_inv_detail_e_units">0</span> หน่วย @ ฿<span id="dash_inv_detail_e_rate">0</span>)
                                          </div>
                                      </div>
                                  </div>
                                  <div class="font-bold text-amber-600 font-mono text-sm">฿<span id="dash_inv_detail_e_total">0.00</span></div>
                              </div>

                              <!-- ค่าใช้จ่ายอื่นๆ -->
                              <div class="p-3.5 flex items-center justify-between text-xs hover:bg-slate-50/50 transition" id="dash_inv_detail_other_row">
                                  <div class="flex items-center gap-2">
                                      <div class="w-7 h-7 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                                          <i data-lucide="plus-circle" class="w-3.5 h-3.5"></i>
                                      </div>
                                      <div>
                                          <div class="font-bold text-slate-800">ค่าบริการส่วนกลาง / ค่าใช้จ่ายอื่น</div>
                                          <div class="text-[10px] text-slate-400" id="dash_inv_detail_other_note">ค่าบริการเพิ่มเติม (ถ้ามี)</div>
                                      </div>
                                  </div>
                                  <div class="font-bold text-slate-800 font-mono text-sm">฿<span id="dash_inv_detail_other">0.00</span></div>
                              </div>
                          </div>
                      </div>

                      <!-- Grand Total Card -->
                      <div class="p-4 rounded-2xl bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 text-white shadow-md flex items-center justify-between">
                          <div>
                              <span class="text-[11px] text-emerald-100 uppercase tracking-wider block font-medium">ยอดรวมสุทธิทั้งสิ้น</span>
                              <span class="text-xs text-emerald-200">รวมภาษีและค่าบริการเรียบร้อย</span>
                          </div>
                          <div class="text-right">
                              <span class="text-2xl sm:text-3xl font-black font-mono tracking-tight text-white">฿<span id="dash_inv_detail_grand_total">0.00</span></span>
                          </div>
                      </div>
                  </div>

                  <!-- ======================================================== -->
                  <!-- 💳 กรอบที่ 2: ช่องทางการชำระเงิน และหลักฐานสลิป (Payment & Slip) -->
                  <!-- ======================================================== -->
                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-slate-200 bg-gradient-to-b from-slate-50/60 to-white flex flex-col justify-between shadow-2xs space-y-5">
                      <div class="space-y-4">
                          <!-- Header กรอบชำระเงิน -->
                          <div class="flex items-center justify-between pb-3.5 border-b border-slate-200 mb-2">
                              <div class="flex items-center gap-2.5">
                                  <div class="w-9 h-9 rounded-2xl bg-blue-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                                      <i data-lucide="credit-card" class="w-5 h-5"></i>
                                  </div>
                                  <div>
                                      <h4 class="font-display font-bold text-slate-900 text-base">ช่องทางชำระเงิน & หลักฐานสลิป</h4>
                                      <p class="text-[11px] text-slate-500 font-medium">ข้อมูลบัญชีธนาคารและการตรวจสอบสลิปโอนเงิน</p>
                                  </div>
                              </div>
                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 font-mono">
                                  การเงิน
                              </span>
                          </div>

                          <!-- สถานะการตรวจสอบสลิป -->
                          <div id="dash_inv_slip_status_card" class="p-3.5 rounded-2xl border text-xs flex items-center justify-between"></div>

                          <!-- รูปภาพสลิปที่แนบไว้ -->
                          <div class="space-y-2">
                              <label class="block text-xs font-bold text-slate-700 flex items-center justify-between">
                                  <span class="flex items-center gap-1.5">
                                      <i data-lucide="image" class="w-3.5 h-3.5 text-blue-600"></i>
                                      <span>หลักฐานสลิปโอนเงินที่แนบในระบบ:</span>
                                  </span>
                                  <span id="dash_inv_detail_slip_time" class="text-[11px] font-mono text-slate-400 font-normal"></span>
                              </label>

                              <div id="dash_inv_slip_preview_box" class="w-full">
                                  <!-- Dynamic slip image or empty state -->
                              </div>
                          </div>

                          <!-- กล่องข้อมูลบัญชีธนาคารสำหรับโอนเงิน -->
                          <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs space-y-2.5">
                              <div class="flex items-center justify-between border-b border-slate-200/80 pb-2">
                                  <span class="font-bold text-slate-700 flex items-center gap-1.5">
                                      <i data-lucide="building-2" class="w-3.5 h-3.5 text-slate-500"></i>
                                      <span>บัญชีรับโอนเงินของทางหอพัก</span>
                                  </span>
                                  <span class="text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">พร้อมเพย์ / โอนเงิน</span>
                              </div>
                              <div class="grid grid-cols-2 gap-2 pt-1 text-[11px]">
                                  <div>
                                      <span class="text-slate-400 block text-[10px]">ธนาคาร:</span>
                                      <span class="font-bold text-slate-800">กสิกรไทย (KBANK)</span>
                                  </div>
                                  <div>
                                      <span class="text-slate-400 block text-[10px]">เลขที่บัญชี:</span>
                                      <span class="font-bold text-slate-900 font-mono">123-4-56789-0</span>
                                  </div>
                                  <div class="col-span-2">
                                      <span class="text-slate-400 block text-[10px]">ชื่อบัญชี:</span>
                                      <span class="font-bold text-slate-800">อพาร์ทเมนท์ แมนเนจเม้นท์ บจก.</span>
                                  </div>
                              </div>
                          </div>
                      </div>

                      <!-- Footer Notice in Frame 2 -->
                      <div class="pt-2 text-[11px] text-slate-500 flex items-center gap-2">
                          <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                          <span>ตรวจสอบและออกใบเสร็จรับเงินโดยนิติบุคคลอย่างเป็นทางการ</span>
                      </div>
                  </div>
              </div>
          </div>

          <!-- Modal Footer -->
          <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex items-center justify-between gap-3 shrink-0">
              <button type="button" onclick="closeTenantInvoiceModal()" class="px-5 py-2.5 bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer shadow-2xs">
                  ปิดหน้าต่าง
              </button>
              <div id="dash_inv_detail_footer_action">
                  <!-- Dynamic Action button -->
              </div>
          </div>
      </div>
  </div>

  </main>



<!-- 🌟 แถบเมนูด้านล่างสำหรับมือถือ (Bottom Navigation Bar) 🌟 -->

  <nav class="md:hidden fixed bottom-0 left-0 w-full bg-white border-t border-rose-100/80 z-50 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">

      <div class="flex justify-around items-center h-16">

          <a href="tenant_dashboard.php" class="flex flex-col items-center justify-center w-full h-full text-rose-600">

              <i data-lucide="home" class="w-5 h-5 mb-1"></i>

              <span class="text-[10px] font-bold">หน้าหลัก</span>

          </a>

          <a href="javascript:void(0)" onclick="openNotifModal()" class="flex flex-col items-center justify-center w-full h-full text-slate-400 hover:text-rose-600 transition relative">

              <i data-lucide="bell" class="w-5 h-5 mb-1"></i>

              <span class="text-[10px] font-medium">แจ้งเตือน</span>

              <?php if($totalAlertCount > 0): ?>

                <span class="absolute top-2 right-4 w-2 h-2 bg-rose-500 rounded-full"></span>

              <?php endif; ?>

          </a>

          <a href="view_invoices.php" class="flex flex-col items-center justify-center w-full h-full text-slate-400 hover:text-rose-600 transition">

              <i data-lucide="receipt" class="w-5 h-5 mb-1"></i>

              <span class="text-[10px] font-medium">จ่ายบิล</span>

          </a>

          <a href="tenant_request.php" class="flex flex-col items-center justify-center w-full h-full text-slate-400 hover:text-rose-600 transition">

              <i data-lucide="wrench" class="w-5 h-5 mb-1"></i>

              <span class="text-[10px] font-medium">แจ้งซ่อม</span>

          </a>

          <a href="profile.php" class="flex flex-col items-center justify-center w-full h-full text-slate-400 hover:text-rose-600 transition">

              <i data-lucide="user" class="w-5 h-5 mb-1"></i>

              <span class="text-[10px] font-medium">โปรไฟล์</span>

          </a>

      </div>

  </nav>



  <!-- 🔔 Modal แสดงการแจ้งเตือนทั้งหมด (Notification Modal) -->

  <div id="notifModal" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 opacity-0 pointer-events-none transition-opacity duration-200">

    <div class="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-4" id="notifModalContent">

      <div class="flex items-center justify-between border-b border-slate-100 pb-3">

        <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">

          <i data-lucide="bell" class="w-5 h-5 text-rose-600"></i> การแจ้งเตือน & ประกาศทั้งหมด

        </h3>

        <button type="button" onclick="closeNotifModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition cursor-pointer">

          <i data-lucide="x" class="w-4 h-4"></i>

        </button>

      </div>



      <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-1">

        <?php if($totalAlertCount > 0): ?>

            <?php foreach($tenantNotifications as $n): ?>

              <div class="p-3.5 rounded-2xl bg-rose-50/70 border border-blue-200/80 flex items-start gap-3">

                <i data-lucide="<?php echo $n['icon']; ?>" class="w-5 h-5 text-rose-600 shrink-0 mt-0.5"></i>

                <div class="flex-1">

                  <h4 class="font-bold text-xs text-slate-800"><?php echo $n['title']; ?></h4>

                  <p class="text-[11px] text-slate-600 mt-0.5"><?php echo $n['desc']; ?></p>

                  <?php if(!empty($n['link'])): ?>

                    <a href="<?php echo $n['link']; ?>" class="inline-flex items-center gap-1 text-[11px] font-bold text-rose-600 hover:underline mt-1.5">

                      <?php echo $n['linkText']; ?> <i data-lucide="arrow-right" class="w-3 h-3"></i>

                    </a>

                  <?php endif; ?>

                </div>

              </div>

            <?php endforeach; ?>



            <?php foreach($announcements as $rawAnn): 

                $ann = array_change_key_case($rawAnn, CASE_LOWER);

            ?>

              <div class="p-3.5 rounded-2xl bg-rose-50/40 border border-rose-100/80 flex items-start gap-3">

                <i data-lucide="megaphone" class="w-5 h-5 text-rose-500 shrink-0 mt-0.5"></i>

                <div>

                  <h4 class="font-bold text-xs text-slate-800"><?php echo htmlspecialchars($ann['title'] ?? 'ประกาศนิติบุคคล'); ?></h4>

                  <p class="text-[11px] text-slate-600 mt-0.5 whitespace-pre-line"><?php echo htmlspecialchars($ann['message'] ?? $ann['content'] ?? ''); ?></p>

                </div>

              </div>

            <?php endforeach; ?>

        <?php else: ?>

          <div class="p-6 text-center text-slate-400 text-xs">

            <i data-lucide="check-circle" class="w-8 h-8 mx-auto text-emerald-400 mb-2"></i>

            ไม่มีการแจ้งเตือนในขณะนี้

          </div>

        <?php endif; ?>

      </div>



      <div class="pt-2 border-t border-slate-100 flex justify-end">

        <button type="button" onclick="closeNotifModal()" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">

          ปิดหน้าต่าง

        </button>

      </div>

    </div>

  </div>



  <?php include_once 'includes/doc_viewer_modal.php'; ?>



  <!-- 📋 Modal แสดงรายการเช็คลิสต์อุปกรณ์ห้องพัก (Room Checklist Modal) -->

    <!-- 🖼️ Universal Image / Document Preview Modal -->

  <div id="imagePreviewModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center p-3 sm:p-4">

    <div class="bg-white rounded-3xl max-w-2xl w-full p-5 sm:p-6 shadow-2xl flex flex-col max-h-[90vh] animate-in fade-in zoom-in duration-200">

      <div class="flex items-center justify-between border-b border-slate-100 pb-3 shrink-0">

        <div class="flex items-center gap-2.5">

          <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">

            <i data-lucide="image" class="w-4 h-4"></i>

          </div>

          <h3 id="imagePreviewTitle" class="font-display font-bold text-slate-800 text-sm sm:text-base">รูปภาพ / เอกสาร</h3>

        </div>

        <button type="button" onclick="closeImagePreviewModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition cursor-pointer">

          <i data-lucide="x" class="w-4 h-4"></i>

        </button>

      </div>

      <div class="py-4 my-2 overflow-y-auto flex-1 flex items-center justify-center bg-rose-50/40 rounded-2xl border border-slate-100">

        <img id="imagePreviewImg" src="" alt="Preview" class="max-h-[60vh] max-w-full object-contain rounded-xl shadow-xs">

      </div>

      <div class="pt-3 border-t border-slate-100 flex justify-end shrink-0">

        <button type="button" onclick="closeImagePreviewModal()" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl text-xs shadow-xs transition cursor-pointer">

          ปิดหน้าต่าง

        </button>

      </div>

    </div>

  </div>



  <div id="checklistModal" class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4 opacity-0 pointer-events-none transition-opacity duration-200">

    <div class="bg-white rounded-3xl max-w-2xl w-full p-5 sm:p-6 shadow-2xl flex flex-col max-h-[90vh] transition-transform duration-200 transform scale-95" id="checklistModalContent">

      

      <!-- Modal Header -->

      <div class="flex items-center justify-between border-b border-slate-100 pb-4 shrink-0">

        <div class="flex items-center gap-3">

          <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center shadow-xs">

            <i data-lucide="clipboard-check" class="w-5 h-5"></i>

          </div>

          <div>

            <div class="flex items-center gap-2">

              <h3 class="font-display font-bold text-slate-800 text-base sm:text-lg">เช็คลิสต์อุปกรณ์ห้องพัก</h3>

              <span class="text-xs bg-indigo-100 text-indigo-700 font-bold px-2.5 py-0.5 rounded-full">

                ห้อง <?php echo htmlspecialchars($myRoom['roomNumber'] ?? '-'); ?>

              </span>

            </div>

            <p class="text-xs text-slate-500 mt-0.5">รายการเฟอร์นิเจอร์และอุปกรณ์ที่ส่งมอบให้ผู้เช่าดูแล (<?php echo count($tenantChecklist); ?> รายการ)</p>

          </div>

        </div>

        <button type="button" onclick="closeChecklistModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition cursor-pointer">

          <i data-lucide="x" class="w-4 h-4"></i>

        </button>

      </div>



      <!-- Info Banner -->

      <div class="my-3 p-3 bg-rose-50/70 border border-blue-200/80 rounded-2xl flex items-start gap-2.5 shrink-0">

        <i data-lucide="info" class="w-4 h-4 text-rose-600 shrink-0 mt-0.5"></i>

        <div class="text-xs text-blue-900 leading-relaxed">

          <span class="font-bold">คำแนะนำสำหรับลูกบ้าน:</span> กรุณาตรวจสอบสภาพอุปกรณ์ภายในห้องพัก หากพบรายการใดชำรุดเสียหายหรือไม่ตรงตามรายการ สามารถแจ้งซ่อมผ่านระบบเพื่อให้นิติบุคคลเข้าตรวจสอบแก้ไขได้ทันทีครับ

        </div>

      </div>



      <!-- Checklist Items (Scrollable Body) -->

      <div class="flex-1 overflow-y-auto pr-1 my-2 space-y-2.5">

        <?php if (!empty($tenantChecklist)): ?>

          <div class="space-y-2.5">

            <?php foreach ($tenantChecklist as $idx => $item): 

              $cat = $item['category'] ?? 'เฟอร์นิเจอร์';

              $catClass = 'bg-slate-100 text-slate-700';

              if ($cat === 'เฟอร์นิเจอร์') $catClass = 'bg-amber-50 text-amber-800 border-amber-200/60';

              elseif ($cat === 'เครื่องใช้ไฟฟ้า') $catClass = 'bg-rose-50 text-blue-800 border-blue-200/60';

              elseif ($cat === 'ระบบไฟฟ้า') $catClass = 'bg-yellow-50 text-yellow-800 border-yellow-200/60';

              elseif ($cat === 'ห้องน้ำ') $catClass = 'bg-cyan-50 text-cyan-800 border-cyan-200/60';

              elseif ($cat === 'ระบบความปลอดภัย') $catClass = 'bg-purple-50 text-purple-800 border-purple-200/60';



              $status = $item['itemStatus'] ?? 'Good';

            ?>

              <div class="p-3.5 rounded-2xl bg-rose-50/40/80 hover:bg-slate-100/60 border border-rose-100/80/80 transition flex flex-col sm:flex-row sm:items-center justify-between gap-3">

                <div class="flex items-start gap-3">

                  <span class="w-6 h-6 rounded-lg bg-white border border-rose-100/80 text-slate-500 font-mono text-xs flex items-center justify-center font-bold shrink-0 mt-0.5">

                    <?php echo $idx + 1; ?>

                  </span>

                  <div>

                    <div class="flex flex-wrap items-center gap-2">

                      <span class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($item['itemName']); ?></span>

                      <span class="text-[11px] font-semibold px-2 py-0.5 rounded-md border <?php echo $catClass; ?>">

                        <?php echo htmlspecialchars($cat); ?>

                      </span>

                    </div>

                    <?php if (!empty($item['notes'])): ?>

                      <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">

                        <i data-lucide="message-square" class="w-3.5 h-3.5 text-slate-400"></i>

                        <span><?php echo htmlspecialchars($item['notes']); ?></span>

                      </p>

                    <?php endif; ?>

                  </div>

                </div>



                <div class="flex items-center justify-between sm:justify-end gap-3 shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-rose-100/80/60">

                  <div class="text-xs text-slate-500">

                    จำนวน: <strong class="text-slate-800"><?php echo intval($item['quantity'] ?? 1); ?></strong> ชิ้น

                  </div>

                  <div>

                    <?php if ($status === 'Good'): ?>

                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold">

                        <i data-lucide="check" class="w-3 h-3"></i> ปกติ / สมบูรณ์

                      </span>

                    <?php elseif ($status === 'Damaged'): ?>

                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-rose-100 text-rose-700 rounded-full text-xs font-bold">

                        <i data-lucide="alert-circle" class="w-3 h-3"></i> ชำรุด

                      </span>

                    <?php elseif ($status === 'Missing'): ?>

                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-200 text-slate-700 rounded-full text-xs font-bold">

                        <i data-lucide="help-circle" class="w-3 h-3"></i> สูญหาย

                      </span>

                    <?php elseif ($status === 'Repairing'): ?>

                      <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-100 text-amber-700 rounded-full text-xs font-bold">

                        <i data-lucide="wrench" class="w-3 h-3"></i> กำลังซ่อม

                      </span>

                    <?php endif; ?>

                  </div>

                </div>

              </div>

            <?php endforeach; ?>

          </div>

        <?php else: ?>

          <div class="text-center py-10 text-slate-400">

            <i data-lucide="clipboard-list" class="w-12 h-12 mx-auto mb-2 text-slate-300"></i>

            <p class="text-sm font-semibold text-slate-600">ยังไม่มีรายการเช็คลิสต์อุปกรณ์สำหรับห้องนี้</p>

            <p class="text-xs text-slate-400 mt-1">กรุณาติดต่อนิติบุคคลเพื่อบันทึกรายการอุปกรณ์ประจำห้อง</p>

          </div>

        <?php endif; ?>

      </div>



      <!-- Modal Footer -->

      <div class="pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3 shrink-0">

        <a href="tenant_request.php" class="inline-flex items-center gap-2 px-4 py-2 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 rounded-xl text-xs font-bold transition">

          <i data-lucide="wrench" class="w-4 h-4 text-amber-600"></i> พบอุปกรณ์ชำรุด? แจ้งซ่อมที่นี่

        </a>

        <button type="button" onclick="closeChecklistModal()" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer">

          ปิดหน้าต่าง

        </button>

      </div>



    </div>

  </div>



  <script>

    // 🌟 ระบบจัดการแท็บประวัติต่างๆ (Unified History Tabs)

    function switchHistoryTab(tabName) {

      // ซ่อน Pane ทั้งหมด

      document.querySelectorAll('.history-tab-pane').forEach(el => el.classList.add('hidden'));

      // แสดง Pane เป้าหมาย

      const target = document.getElementById('tab-pane-' + tabName);

      if (target) {

        target.classList.remove('hidden');

      }

      

      // ปรับสถานะปุ่มแท็บ

      document.querySelectorAll('.history-tab-btn').forEach(btn => {

        btn.classList.remove('bg-rose-500', 'text-white', 'shadow-xs');

        btn.classList.add('bg-slate-100', 'text-slate-600', 'hover:bg-slate-200');

        const badge = btn.querySelector('.tab-badge');

        if (badge) {

          badge.classList.remove('bg-white/20', 'text-white');

          badge.classList.add('bg-slate-200', 'text-slate-700');

        }

      });

      

      // ทำปุ่มที่เลือกเป็นสีน้ำเงิน

      const activeBtn = document.getElementById('btn-tab-' + tabName);

      if (activeBtn) {

        activeBtn.classList.remove('bg-slate-100', 'text-slate-600', 'hover:bg-slate-200');

        activeBtn.classList.add('bg-rose-500', 'text-white', 'shadow-xs');

        const badge = activeBtn.querySelector('.tab-badge');

        if (badge) {

          badge.classList.remove('bg-slate-200', 'text-slate-700');

          badge.classList.add('bg-white/20', 'text-white');

        }

      }



      // เลื่อนจอลงมาที่ส่วนประวัติ

      const sec = document.getElementById('history-section');

      if (sec) {

        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });

      }

      lucide.createIcons();

    }



    // 🌟 ระบบเปิด Modal ดูรูปภาพ / เอกสารสลิป

    function openImagePreviewModal(src, title) {

      const modal = document.getElementById('imagePreviewModal');

      const img = document.getElementById('imagePreviewImg');

      const t = document.getElementById('imagePreviewTitle');

      if (modal && img) {

        img.src = src;

        if (t) t.textContent = title || 'รูปภาพ / เอกสาร';

        modal.classList.remove('hidden');

        modal.classList.add('flex');

        lucide.createIcons();

      }

    }



    function closeImagePreviewModal() {

      const modal = document.getElementById('imagePreviewModal');

      if (modal) {

        modal.classList.add('hidden');

        modal.classList.remove('flex');

      }

    }



    function openDocModal(src, title) {

      openImagePreviewModal(src, title || 'เอกสารสัญญาเช่า');

    }



    lucide.createIcons();



    function markAllNotificationsAsRead() {

      try {

        localStorage.setItem('tenant_notif_read_v1', 'true');

        

        // Hide red counter badges

        document.querySelectorAll('.notif-badge').forEach(el => el.classList.add('hidden'));

        

        // Change "ใหม่" status badges to "อ่านแล้ว"

        document.querySelectorAll('.notif-status-badge').forEach(el => {

          el.textContent = 'อ่านแล้ว';

          el.className = 'notif-status-badge text-[10px] bg-slate-100 text-slate-500 font-semibold px-2 py-0.5 rounded-full shrink-0';

        });



        const dashboardCount = document.getElementById('dashboardNotifCount');

        if(dashboardCount) {

          dashboardCount.textContent = 'อ่านแล้วทั้งหมด';

          dashboardCount.className = 'text-xs bg-slate-100 text-slate-500 font-semibold px-2.5 py-1 rounded-full border border-rose-100/80';

        }

      } catch(e) {}

    }



    function openNotifModal() {

      const modal = document.getElementById('notifModal');

      modal.classList.remove('opacity-0', 'pointer-events-none');

      markAllNotificationsAsRead();

    }



    function closeNotifModal() {

      const modal = document.getElementById('notifModal');

      modal.classList.add('opacity-0', 'pointer-events-none');

    }



    function openChecklistModal() {

      const modal = document.getElementById('checklistModal');

      const content = document.getElementById('checklistModalContent');

      if (modal) {

        modal.classList.remove('opacity-0', 'pointer-events-none');

        if (content) {

          content.classList.remove('scale-95');

          content.classList.add('scale-100');

        }

      }

    }



    function closeChecklistModal() {

      const modal = document.getElementById('checklistModal');

      const content = document.getElementById('checklistModalContent');

      if (modal) {

        modal.classList.add('opacity-0', 'pointer-events-none');

        if (content) {

          content.classList.remove('scale-100');

          content.classList.add('scale-95');

        }

      }

    }



    // ปิด modal เมื่อคลิกพื้นหลัง

    

    // ปิด Modal เมื่อกด Escape

    document.addEventListener('keydown', function(e) {

      if (e.key === 'Escape') {

        closeRequestDetailModal();

        closeChecklistModal();

        closeNotifModal();

        closeImagePreviewModal();

      }

    });



    document.addEventListener('click', function(e) {

      const checklistModal = document.getElementById('checklistModal');

      if (checklistModal && e.target === checklistModal) {

        closeChecklistModal();

      }

      const notifModal = document.getElementById('notifModal');

      if (notifModal && e.target === notifModal) {

        closeNotifModal();

      }

      const imgModal = document.getElementById('imagePreviewModal');

      if (imgModal && e.target === imgModal) {

        closeImagePreviewModal();

      }

    });



    document.addEventListener("DOMContentLoaded", function() {

      if (localStorage.getItem('tenant_notif_read_v1') === 'true') {

        markAllNotificationsAsRead();

      }

    });

  </script>

  <script src="js/realtime-time.js"></script>

<script>

    // ข้อมูลรายการแจ้งซ่อมทั้งหมดสำหรับ Request Detail Modal จอใหญ่

    window.tenantRequests = <?php echo json_encode($allRequestsData, JSON_UNESCAPED_UNICODE); ?>;

    // ข้อมูลใบแจ้งหนี้ทั้งหมดสำหรับ Tenant Invoice Detail Modal จอใหญ่
    window.tenantInvoices = <?php echo json_encode($allTenantInvoicesData, JSON_UNESCAPED_UNICODE); ?>;

    function openTenantInvoiceModal(invId) {
        const inv = window.tenantInvoices ? (window.tenantInvoices[invId] || window.tenantInvoices[String(invId)]) : null;
        if (!inv) return;

        // ข้อมูลหลัก
        document.getElementById('dash_inv_detail_room').textContent = inv.roomNumber || '-';
        document.getElementById('dash_inv_detail_room_badge').textContent = inv.roomNumber || '-';
        document.getElementById('dash_inv_detail_code').textContent = inv.invCode;
        document.getElementById('dash_inv_detail_month').textContent = inv.billingMonth;
        document.getElementById('dash_inv_detail_tenant').textContent = inv.tenantName || '-';
        document.getElementById('dash_inv_detail_date').textContent = inv.createdAt || '-';

        // ป้ายสถานะ
        const statusBadge = document.getElementById('dash_inv_detail_status_badge');
        const slipStatusCard = document.getElementById('dash_inv_slip_status_card');
        const footerAction = document.getElementById('dash_inv_detail_footer_action');

        if (inv.status === 'Paid') {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> ชำระเรียบร้อยแล้ว';

            slipStatusCard.className = 'p-3.5 rounded-2xl border text-xs flex items-center justify-between bg-emerald-50 border-emerald-200 text-emerald-800';
            slipStatusCard.innerHTML = `
                <div class="flex items-center gap-2">
                    <i data-lucide="badge-check" class="w-4 h-4 text-emerald-600 shrink-0"></i>
                    <div>
                        <div class="font-bold">ตรวจสอบและรับชำระเงินเรียบร้อยแล้ว</div>
                        <div class="text-[10px] text-emerald-600 font-mono">${inv.paidAt ? 'บันทึกชำระเมื่อ: ' + inv.paidAt + ' น.' : 'ยอดเงินเข้าบัญชีเรียบร้อย'}</div>
                    </div>
                </div>
                <span class="px-2.5 py-1 bg-emerald-600 text-white font-bold rounded-lg text-[10px]">ชำระแล้ว</span>
            `;

            footerAction.innerHTML = `
                <a href="print_receipt.php?invoice_id=${inv.invoiceId}" target="_blank" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <i data-lucide="printer" class="w-4 h-4"></i> พิมพ์ / ดาวน์โหลดใบเสร็จ (PDF)
                </a>
            `;
        } else if (inv.status === 'Pending Approval') {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-amber-600"></i> รอแอดมินตรวจสลิป';

            slipStatusCard.className = 'p-3.5 rounded-2xl border text-xs flex items-center justify-between bg-amber-50 border-amber-200 text-amber-800';
            slipStatusCard.innerHTML = `
                <div class="flex items-center gap-2">
                    <i data-lucide="clock" class="w-4 h-4 text-amber-600 shrink-0"></i>
                    <div>
                        <div class="font-bold">แนบสลิปเรียบร้อยแล้ว อยู่ระหว่างรอตรวจสอบ</div>
                        <div class="text-[10px] text-amber-600 font-mono">${inv.slipUploadedAt ? 'แนบสลิปเมื่อ: ' + inv.slipUploadedAt + ' น.' : 'รอเจ้าหน้าที่ยืนยัน'}</div>
                    </div>
                </div>
                <span class="px-2.5 py-1 bg-amber-500 text-white font-bold rounded-lg text-[10px]">รอตรวจสลิป</span>
            `;

            footerAction.innerHTML = `
                <a href="view_invoices.php" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <i data-lucide="external-link" class="w-4 h-4"></i> ตรวจสอบสถานะในหน้ารายการบิล
                </a>
            `;
        } else {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="alert-circle" class="w-3.5 h-3.5 text-rose-600"></i> รอชำระเงิน';

            slipStatusCard.className = 'p-3.5 rounded-2xl border text-xs flex items-center justify-between bg-rose-50 border-rose-200 text-rose-800';
            slipStatusCard.innerHTML = `
                <div class="flex items-center gap-2">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 shrink-0"></i>
                    <div>
                        <div class="font-bold">ยังไม่ได้รับการชำระเงินสำหรับรอบนี้</div>
                        <div class="text-[10px] text-rose-600">กรุณาโอนเงินและแนบหลักฐานสลิป</div>
                    </div>
                </div>
                <span class="px-2.5 py-1 bg-rose-600 text-white font-bold rounded-lg text-[10px]">ค้างชำระ</span>
            `;

            footerAction.innerHTML = `
                <a href="view_invoices.php" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <i data-lucide="upload" class="w-4 h-4"></i> ไปหน้าชำระเงิน / แนบสลิป
                </a>
            `;
        }

        // ตัวเลขค่าใช้จ่าย
        document.getElementById('dash_inv_detail_rent').textContent = Number(inv.rentPrice).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('dash_inv_detail_w_prev').textContent = inv.waterPrev;
        document.getElementById('dash_inv_detail_w_curr').textContent = inv.waterCurr;
        document.getElementById('dash_inv_detail_w_units').textContent = inv.waterUnits;
        document.getElementById('dash_inv_detail_w_rate').textContent = inv.waterRate;
        document.getElementById('dash_inv_detail_w_total').textContent = Number(inv.waterTotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('dash_inv_detail_e_prev').textContent = inv.electricPrev;
        document.getElementById('dash_inv_detail_e_curr').textContent = inv.electricCurr;
        document.getElementById('dash_inv_detail_e_units').textContent = inv.electricUnits;
        document.getElementById('dash_inv_detail_e_rate').textContent = inv.electricRate;
        document.getElementById('dash_inv_detail_e_total').textContent = Number(inv.electricTotal).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('dash_inv_detail_other').textContent = Number(inv.otherFee).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const otherNoteEl = document.getElementById('dash_inv_detail_other_note');
        if (otherNoteEl) {
            otherNoteEl.textContent = inv.otherFeeNote || (Number(inv.otherFee) > 0 ? (Math.abs(Number(inv.otherFee) - 20) < 0.01 ? 'ค่าขยะ (20.00 บาท)' : 'ค่าบริการส่วนกลาง') : 'ไม่มี');
        }
        document.getElementById('dash_inv_detail_grand_total').textContent = Number(inv.totalAmount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        // กล่องรูปสลิป
        const slipBox = document.getElementById('dash_inv_slip_preview_box');
        const slipTimeEl = document.getElementById('dash_inv_detail_slip_time');
        slipTimeEl.textContent = inv.slipUploadedAt ? ('อัปโหลดเมื่อ: ' + inv.slipUploadedAt + ' น.') : '';

        if (inv.slipImage) {
            slipBox.innerHTML = `
                <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-emerald-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center max-h-56" onclick="openImagePreviewModal('${inv.slipImage}', 'หลักฐานสลิปโอนเงิน รอบบิล ${inv.billingMonth}')">
                    <img src="${inv.slipImage}" class="w-full h-full object-contain transition duration-300 group-hover:scale-105" alt="สลิปโอนเงิน">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">
                        <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูสลิปเต็ม</span>
                    </div>
                </div>
            `;
        } else {
            slipBox.innerHTML = `
                <div class="w-full h-32 rounded-2xl bg-slate-50 border border-dashed border-slate-300 flex flex-col items-center justify-center text-slate-400 text-xs">
                    <i data-lucide="file-question" class="w-6 h-6 mb-1 text-slate-300"></i>
                    <span>ยังไม่มีการแนบสลิปโอนเงินในระบบ</span>
                </div>
            `;
        }

        lucide.createIcons();
        document.getElementById('tenantInvoiceDetailModal').classList.remove('hidden');
    }

    function closeTenantInvoiceModal() {
        const modal = document.getElementById('tenantInvoiceDetailModal');
        if (modal) modal.classList.add('hidden');
    }



    function openRequestDetailModal(reqId) {

        const req = window.tenantRequests ? window.tenantRequests[reqId] : null;

        if (!req) return;



        // ข้อมูลหลัก

        document.getElementById('dash_req_detail_room').textContent = req.roomNumber || '-';

        document.getElementById('dash_req_detail_code').textContent = req.reqCode;

        document.getElementById('dash_req_detail_category').textContent = req.category;

        document.getElementById('dash_req_detail_desc').textContent = req.description || '-';

        const hoursBadge = document.getElementById('dash_req_detail_hours_badge');

        if (hoursBadge) {

            hoursBadge.textContent = '⏱️ กำหนดเวลา ' + (req.estimatedHours || 2) + ' ชม.';

        }



        // ป้ายสถานะ

        const statusBadge = document.getElementById('dash_req_detail_status_badge');

        if (req.status === 'Completed') {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> เสร็จสิ้นเรียบร้อย';
        } else if (req.status === 'Pending Approval') {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-orange-50 text-orange-700 border border-orange-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-orange-600"></i> ช่างปฏิบัติงานแล้ว (รอนิติตรวจรับ)';
        } else if (req.status === 'In Progress') {

            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-rose-50 text-rose-700 border border-blue-200 flex items-center gap-1.5';

            statusBadge.innerHTML = '<i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-600"></i> นิติมอบหมายงานแล้ว';

        } else {

            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-1.5';

            statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-amber-600"></i> รอนิติมอบหมายงาน';

        }



        // นัดหมายเข้าห้อง

        const prefBox = document.getElementById('dash_req_detail_appointment');

        if (req.preferredDate && req.preferredDate !== '-') {

            prefBox.classList.remove('hidden');

            document.getElementById('dash_req_detail_pref_text').textContent = 'นัดเข้า: ' + req.preferredDate + (req.preferredTime ? ' (' + req.preferredTime + ')' : '');

        } else {

            prefBox.classList.add('hidden');

        }



        // สิทธิ์การเข้าห้อง

        const entryBadge = document.getElementById('dash_req_detail_entry_badge');

        if (req.allowEntry == 1) {

            entryBadge.className = 'text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-xl border border-emerald-200 flex items-center gap-1.5';

            entryBadge.innerHTML = '<i data-lucide="key" class="w-3.5 h-3.5"></i> ยินยอมให้ใช้กุญแจสำรอง';

        } else {

            entryBadge.className = 'text-xs font-bold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-xl border border-rose-100/80 flex items-center gap-1.5';

            entryBadge.innerHTML = '<i data-lucide="lock" class="w-3.5 h-3.5"></i> ต้องมีผู้เช่าอยู่ห้อง';

        }



        // ข้อมูลพนักงานรับผิดชอบ

        const staffBox = document.getElementById('dash_req_detail_staff_box');

        const staffNameEl = document.getElementById('dash_req_detail_staff_name');

        const staffPhoneBtn = document.getElementById('dash_req_detail_staff_phone_btn');



        if (req.staffName) {

            staffBox.classList.remove('hidden');

            staffNameEl.textContent = 'คุณ' + req.staffName + (req.staffRole ? ' (' + req.staffRole + ')' : '');

            if (req.staffPhone) {

                staffPhoneBtn.href = 'tel:' + req.staffPhone;

                staffPhoneBtn.querySelector('.staff-phone-text').textContent = 'โทร ' + req.staffPhone;

                staffPhoneBtn.classList.remove('hidden');

            } else {

                staffPhoneBtn.classList.add('hidden');

            }

        } else if (req.status === 'Pending') {

            staffBox.classList.remove('hidden');

            staffNameEl.innerHTML = '<span class="text-amber-700 font-medium">รอนิติบุคคลมอบหมายงานให้เจ้าหน้าที่</span>';

            staffPhoneBtn.classList.add('hidden');

        } else {

            staffBox.classList.add('hidden');

        }



        // ไทม์ไลน์ 1: แจ้งเมื่อ

        document.getElementById('dash_req_detail_req_date').textContent = req.requestDate + ' น.';

        document.getElementById('dash_req_detail_req_ago').textContent = '';



        // ไทม์ไลน์ 2: รับงานเมื่อ

        const assignedDateEl = document.getElementById('dash_req_detail_assigned_date');

        const assignedAgoEl = document.getElementById('dash_req_detail_assigned_ago');

        if (req.assignedAt) {

            assignedDateEl.textContent = req.assignedAt + ' น.';

            assignedAgoEl.textContent = '';

        } else {

            assignedDateEl.innerHTML = req.status === 'Pending' ? '<span class="text-amber-600 font-semibold">รอนิติมอบหมาย</span>' : '-';

            assignedAgoEl.textContent = '';

        }



        // ไทม์ไลน์ 3: จบงานเมื่อ

        const completedBox = document.getElementById('dash_req_detail_completed_box');

        const completedDateEl = document.getElementById('dash_req_detail_completed_date');

        const durationEl = document.getElementById('dash_req_detail_duration');



        if (req.status === 'Completed' && req.completedAt) {
            completedDateEl.textContent = req.completedAt + ' น.';
            durationEl.textContent = req.duration ? ('⏱️ ใช้เวลา: ' + req.duration) : 'เสร็จสิ้นเรียบร้อย';
            durationEl.classList.remove('hidden');
            completedBox.classList.remove('bg-white', 'border-rose-100/80');
            completedBox.classList.add('bg-emerald-50/70', 'border-emerald-200/80');
        } else if (req.status === 'Completed') {
            completedDateEl.textContent = 'เสร็จสิ้นแล้ว';
            durationEl.classList.add('hidden');
            completedBox.classList.remove('bg-white', 'border-rose-100/80');
            completedBox.classList.add('bg-emerald-50/70', 'border-emerald-200/80');

        } else {

            completedDateEl.innerHTML = '<span class="text-rose-600 font-semibold flex items-center gap-1.5"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span></span> กำลังดำเนินการ</span>';

            durationEl.classList.add('hidden');

            completedBox.classList.remove('bg-emerald-50/70', 'border-emerald-200/80');

            completedBox.classList.add('bg-white', 'border-rose-100/80');

        }



        // รูปก่อนทำ

        const beforeContainer = document.getElementById('dash_req_detail_before_img_container');

        if (req.requestImage) {

            beforeContainer.innerHTML = `

                <div class="relative group cursor-pointer rounded-2xl overflow-hidden border border-amber-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImagePreviewModal('${req.requestImage}', 'รูปก่อนทำ (ปัญหาที่คุณแจ้ง - ห้อง ${req.roomNumber})')">

                    <img src="${req.requestImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="ก่อนทำ">

                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">

                        <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>

                    </div>

                </div>`;

        } else {

            beforeContainer.innerHTML = `

                <div class="h-32 rounded-2xl bg-white border border-dashed border-amber-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">

                    <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>

                    <span>ไม่ได้แนบรูปปัญหา</span>

                </div>`;

        }



        // รูปหลังทำ

        const afterContainer = document.getElementById('dash_req_detail_after_img_container');

        if (req.status === 'Completed' && req.completionImage) {
            afterContainer.innerHTML = `
                <div class="relative group cursor-pointer rounded-2xl overflow-hidden border border-emerald-300 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${req.completionImage}', 'รูปหลังทำ (ผลงานที่ช่างส่งมอบ - ห้อง ${req.roomNumber})')">
                    <img src="${req.completionImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="หลังทำ">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">
                        <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>
                    </div>
                </div>`;
        } else if (req.status === 'Pending Approval') {
            afterContainer.innerHTML = `
                <div class="h-32 rounded-2xl bg-orange-50/70 border border-dashed border-orange-200 flex flex-col items-center justify-center text-orange-700 text-xs text-center p-3">
                    <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center mb-1.5">
                        <i data-lucide="clock" class="w-4 h-4"></i>
                    </div>
                    <span class="font-bold text-orange-800">ช่างปฏิบัติงานเสร็จแล้ว</span>
                    <span class="text-[11px] text-orange-600 mt-0.5">อยู่ระหว่างนิติบุคคลตรวจสอบและอนุมัติงาน</span>
                </div>`;
        } else if (req.status === 'Completed') {

            afterContainer.innerHTML = `

                <div class="h-32 rounded-2xl bg-white border border-dashed border-emerald-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">

                    <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>

                    <span>ไม่มีรูปภาพผลงาน</span>

                </div>`;

        } else {

            afterContainer.innerHTML = `

                <div class="h-32 rounded-2xl bg-rose-50/40 border border-dashed border-rose-100/80 flex flex-col items-center justify-center text-slate-400 text-xs text-center p-3">

                    <div class="w-8 h-8 rounded-full bg-rose-50 text-rose-500 flex items-center justify-center mb-1.5">

                        <i data-lucide="hourglass" class="w-4 h-4"></i>

                    </div>

                    <span class="font-bold text-slate-600">กำลังดำเนินการ</span>

                    <span class="text-[11px] text-slate-400 mt-0.5">ช่างจะอัปโหลดรูปเมื่อส่งมอบงาน</span>

                </div>`;

        }



        // ความคิดเห็น / ติชม (กล่องพิมพ์ติชมและแก้ไขได้ทันทีในหน้าเดียวกัน ไม่ต้องเด้งออกไปที่ไหน)

        const commentSec = document.getElementById('dash_req_detail_comment_section');

        const commentDisplay = document.getElementById('dash_req_detail_comment_display');

        const footerAction = document.getElementById('dash_req_detail_footer_action');

        const canComment = (req.status === 'Completed' || req.tenantComment);



        if (canComment) {

            commentSec.classList.remove('hidden');

            const hasComment = !!(req.tenantComment && req.tenantComment.trim());

            const curComment = req.tenantComment ? req.tenantComment.trim() : '';



            commentDisplay.innerHTML = `

                <div class="space-y-3">

                    <div class="relative">

                        <textarea id="inline_dash_tenant_comment_input" 

                            rows="3" 

                            placeholder="แตะที่นี่เพื่อพิมพ์ติชมหรือข้อเสนอแนะต่อการทำงาน ส่งตรงถึงนิติบุคคลได้ทันที..." 

                            class="w-full px-4 py-3 text-xs sm:text-sm bg-white border border-purple-200 rounded-2xl outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-200/50 text-slate-800 placeholder-slate-400 transition resize-y shadow-2xs leading-relaxed font-sans"></textarea>

                    </div>



                    <div class="flex items-center justify-between flex-wrap gap-2 pt-0.5 text-[11px]">

                        <div id="inline_dash_comment_status" class="text-purple-700 flex items-center gap-1.5 font-sans">

                            ${req.commentedAt ? `<i data-lucide="clock" class="w-3.5 h-3.5 text-purple-600"></i> บันทึกเมื่อ: ${req.commentedAt} น.` : '<span class="text-slate-400">สามารถพิมพ์และกดบันทึกได้ทันที</span>'}

                        </div>

                        <div class="flex items-center gap-2">

                            <span id="inline_dash_comment_saved_badge" class="hidden text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200 flex items-center gap-1">

                                <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> บันทึกแล้ว!

                            </span>

                            <button type="button" onclick="saveTenantCommentInline(${req.requestId})" id="btn_dash_save_comment" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm hover:shadow cursor-pointer">

                                <i data-lucide="send" class="w-3.5 h-3.5"></i>

                                <span id="btn_dash_save_comment_text">${hasComment ? 'บันทึกการแก้ไข' : 'บันทึกความคิดเห็น'}</span>

                            </button>

                        </div>

                    </div>

                </div>

            `;

            const ta = document.getElementById('inline_dash_tenant_comment_input');

            if (ta) ta.value = curComment;



            footerAction.innerHTML = '';

        } else {

            commentSec.classList.add('hidden');

            footerAction.innerHTML = '';

        }



        lucide.createIcons();

        document.getElementById('requestDetailModal').classList.remove('hidden');

    }



    

    // --- ฟังก์ชันบันทึกความคิดเห็นแบบ Inline ในหน้าเดียวกัน ---

    function saveTenantCommentInline(reqId) {

        const textarea = document.getElementById('inline_dash_tenant_comment_input');

        if (!textarea) return;

        const comment = textarea.value.trim();

        if (!comment) {

            if (typeof Swal !== 'undefined') {

                Swal.fire({

                    icon: 'warning',

                    title: 'กรุณากรอกความคิดเห็น',

                    text: 'โปรดพิมพ์ข้อความติชมหรือข้อเสนอแนะก่อนกดบันทึกครับ',

                    confirmButtonColor: '#7c3aed'

                });

            } else {

                alert('กรุณากรอกความคิดเห็นก่อนกดส่งครับ');

            }

            textarea.focus();

            return;

        }



        const btn = document.getElementById('btn_dash_save_comment');

        const btnText = document.getElementById('btn_dash_save_comment_text');

        const savedBadge = document.getElementById('inline_dash_comment_saved_badge');

        const statusBox = document.getElementById('inline_dash_comment_status');



        if (btn) btn.disabled = true;

        if (btnText) btnText.textContent = 'กำลังบันทึก...';



        const formData = new FormData();

        formData.append('action', 'save_tenant_comment');

        formData.append('request_id', reqId);

        formData.append('tenant_comment', comment);



        fetch('tenant_dashboard.php', {

            method: 'POST',

            body: formData

        })

        .then(res => res.json())

        .then(data => {

            if (btn) btn.disabled = false;

            if (btnText) btnText.textContent = 'บันทึกการแก้ไข';



            if (data.success) {

                if (statusBox) {

                    statusBox.innerHTML = `<i data-lucide="clock" class="w-3.5 h-3.5 text-purple-600"></i> บันทึกเมื่อ: ${data.commentedAt} น.`;

                }

                if (savedBadge) {

                    savedBadge.classList.remove('hidden');

                    setTimeout(() => {

                        savedBadge.classList.add('hidden');

                    }, 3500);

                }

                if (window.tenantRequests && window.tenantRequests[reqId]) {

                    window.tenantRequests[reqId].tenantComment = comment;

                    window.tenantRequests[reqId].commentedAt = data.commentedAt;

                }

                lucide.createIcons();



                if (typeof Swal !== 'undefined') {

                    const Toast = Swal.mixin({

                        toast: true,

                        position: 'top-end',

                        showConfirmButton: false,

                        timer: 2500,

                        timerProgressBar: true

                    });

                    Toast.fire({

                        icon: 'success',

                        title: 'บันทึกความคิดเห็นเรียบร้อยแล้ว'

                    });

                }

            } else {

                if (typeof Swal !== 'undefined') {

                    Swal.fire({

                        icon: 'error',

                        title: 'เกิดข้อผิดพลาด',

                        text: data.message || 'ไม่สามารถบันทึกความคิดเห็นได้',

                        confirmButtonColor: '#7c3aed'

                    });

                } else {

                    alert(data.message || 'เกิดข้อผิดพลาด');

                }

            }

        })

        .catch(err => {

            if (btn) btn.disabled = false;

            if (btnText) btnText.textContent = 'บันทึกการแก้ไข';

            console.error(err);

            alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');

        });

    }



    function closeRequestDetailModal() {

        const modal = document.getElementById('requestDetailModal');

        if (modal) modal.classList.add('hidden');

    }



  

    // ฟังก์ชันสลับขยายเต็มหน้าจอ 100% / คืนขนาด

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

