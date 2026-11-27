<?php
ob_start();
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/time_helper.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ตรวจสอบสิทธิ์ ต้องเป็น ลูกบ้าน (Tenant) เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {
    header("Location: login.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];
$tenantName = $_SESSION['full_name'] ?? 'ผู้เช่า';
$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));
$message = "";

if (isset($_SESSION['retro_flash_msg'])) {
    $message = "<div class='p-4 mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-between shadow-xs'>
        <div class='flex items-center gap-2'>
            <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>
            <span>" . htmlspecialchars($_SESSION['retro_flash_msg']) . "</span>
        </div>
        <button onclick='this.parentElement.remove()' class='text-emerald-500 hover:text-emerald-700 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>
    </div>";
    unset($_SESSION['retro_flash_msg']);
}

// Ensure RetroactiveInvoiceRequests table exists
try {
    $conn->exec("
    CREATE TABLE IF NOT EXISTS RetroactiveInvoiceRequests (
        requestId INT AUTO_INCREMENT PRIMARY KEY,
        tenantId INT NOT NULL,
        roomId INT NOT NULL,
        invoiceId INT NULL,
        requestedMonth VARCHAR(50) NOT NULL,
        requestType VARCHAR(50) DEFAULT 'PaidInvoice',
        reason TEXT NOT NULL,
        slipImage VARCHAR(255) NULL,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        adminRemark TEXT NULL,
        createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        updatedAt DATETIME NULL,
        INDEX (tenantId),
        INDEX (roomId),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
} catch(Throwable $e) {}

// ดึงข้อมูลห้องพักปัจจุบันของผู้เช่า
$stmtRoom = $conn->prepare("
    SELECT r.roomId, r.roomNumber, r.roomType, r.floor, r.rentPrice, l.leaseId 
    FROM Lease l 
    JOIN Room r ON l.roomId = r.roomId 
    WHERE l.tenantId = ? 
    ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC 
    LIMIT 1
");
$stmtRoom->execute([$tenantId]);
$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);
$myRoomId = (int)($myRoom['roomId'] ?? 0);
$myRoomNumber = $myRoom['roomNumber'] ?? '';

// Fallback 1: เช็คจากตาราง Room โดยตรง (หากผู้เช่าผูกกับห้อง)
if ($myRoomId === 0) {
    try {
        $stmtR = $conn->prepare("SELECT roomId, roomNumber, roomType, floor, rentPrice FROM Room WHERE tenantId = ? LIMIT 1");
        $stmtR->execute([$tenantId]);
        $rRow = $stmtR->fetch(PDO::FETCH_ASSOC);
        if ($rRow) {
            $myRoom = $rRow;
            $myRoomId = (int)$rRow['roomId'];
            $myRoomNumber = $rRow['roomNumber'];
        }
    } catch(Throwable $e) {}
}
// Fallback 2: เช็คจากประวัติบิลใน Invoices
if ($myRoomId === 0) {
    try {
        $stmtInvR = $conn->prepare("SELECT r.roomId, r.roomNumber, r.roomType, r.floor, r.rentPrice FROM Invoices i JOIN Room r ON i.roomId = r.roomId WHERE i.tenantId = ? ORDER BY i.invoiceId DESC LIMIT 1");
        $stmtInvR->execute([$tenantId]);
        $rRow = $stmtInvR->fetch(PDO::FETCH_ASSOC);
        if ($rRow) {
            $myRoom = $rRow;
            $myRoomId = (int)$rRow['roomId'];
            $myRoomNumber = $rRow['roomNumber'];
        }
    } catch(Throwable $e) {}
}
// Fallback 3: เช็คจาก CoResidents (ผู้ร่วมอาศัย)
if ($myRoomId === 0) {
    try {
        $stmtCoreR = $conn->prepare("SELECT r.roomId, r.roomNumber, r.roomType, r.floor, r.rentPrice FROM CoResidents c JOIN Room r ON c.roomId = r.roomId WHERE c.tenantId = ? OR c.phone = (SELECT phone FROM User WHERE userId = ?) LIMIT 1");
        $stmtCoreR->execute([$tenantId, $tenantId]);
        $rRow = $stmtCoreR->fetch(PDO::FETCH_ASSOC);
        if ($rRow) {
            $myRoom = $rRow;
            $myRoomId = (int)$rRow['roomId'];
            $myRoomNumber = $rRow['roomNumber'];
        }
    } catch(Throwable $e) {}
}

// 🌟 Real-time Status Check Endpoint (AJAX Polling สำหรับผู้เช่า) 🌟
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $stmtLive = $conn->prepare("
        SELECT r.requestId, r.requestedMonth, r.requestType, r.status, r.adminRemark, 
               r.createdAt, r.updatedAt,
               COALESCE(r.invoiceId, inv.invoiceId) AS matchedInvoiceId,
               COALESCE(inv.status, '') AS matchedInvoiceStatus,
               COALESCE(inv.totalAmount, 0) AS matchedTotalAmount
        FROM RetroactiveInvoiceRequests r
        LEFT JOIN Invoices inv ON (inv.invoiceId = r.invoiceId OR (
            inv.roomId = r.roomId AND (
                inv.billingMonth = r.requestedMonth COLLATE utf8mb4_general_ci 
                OR inv.billingMonth LIKE CONCAT('%', r.requestedMonth COLLATE utf8mb4_general_ci, '%')
            )
        ))
        WHERE r.tenantId = ?
        ORDER BY r.requestId DESC
    ");
    $stmtLive->execute([$tenantId]);
    $allReqs = $stmtLive->fetchAll(PDO::FETCH_ASSOC);
    
    $pendingCount = 0;
    foreach ($allReqs as $item) {
        if ($item['status'] === 'Pending') $pendingCount++;
    }
    
    echo json_encode([
        'success' => true,
        'pendingCount' => $pendingCount,
        'totalCount' => count($allReqs),
        'latest' => !empty($allReqs) ? $allReqs[0] : null,
        'requests' => $allReqs
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// --- เมื่อผู้เช่ายื่นคำขอบิลย้อนหลังใหม่ ---
if (isset($_POST['submit_retro_request'])) {
    $requestedRoomId = $myRoomId;
    if ($requestedRoomId <= 0) {
        try {
            $stmtFallbackRoom = $conn->prepare("SELECT roomId FROM Lease WHERE tenantId = ? ORDER BY leaseId DESC LIMIT 1");
            $stmtFallbackRoom->execute([$tenantId]);
            $requestedRoomId = (int)$stmtFallbackRoom->fetchColumn();
        } catch(Throwable $e) {}
    }
    if ($requestedRoomId <= 0) {
        try {
            $stmtFallbackRoom = $conn->prepare("SELECT roomId FROM Invoices WHERE tenantId = ? ORDER BY invoiceId DESC LIMIT 1");
            $stmtFallbackRoom->execute([$tenantId]);
            $requestedRoomId = (int)$stmtFallbackRoom->fetchColumn();
        } catch(Throwable $e) {}
    }
    $rawReqMonth = trim($_POST['requested_month'] ?? '');
    $requestType = 'PaidInvoice';
    $reason = trim($_POST['reason'] ?? '');

    $currentYm = date('Y-m');

    if (empty($rawReqMonth)) {
        $message = "<div class='p-4 mb-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-between shadow-xs'>
            <div class='flex items-center gap-2'>
                <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>
                <span>กรุณาเลือกรอบเดือนที่ต้องการขอบิลย้อนหลัง</span>
            </div>
            <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>
        </div>";
    } elseif ($rawReqMonth > $currentYm) {
        $message = "<div class='p-4 mb-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-between shadow-xs'>
            <div class='flex items-center gap-2'>
                <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>
                <span>ไม่สามารถเลือกรอบเดือนในอนาคตได้ (เลือกได้สูงสุดถึงเดือนปัจจุบัน {$currentYm})</span>
            </div>
            <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>
        </div>";
    } elseif (empty($reason)) {
        $message = "<div class='p-4 mb-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-between shadow-xs'>
            <div class='flex items-center gap-2'>
                <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>
                <span>กรุณาระบุเหตุผลหรือรายละเอียดในการขอบิลย้อนหลัง</span>
            </div>
            <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>
        </div>";
    } else {
        $thReqMonth = toThaiBillingMonth($rawReqMonth);
        $foundInvoiceId = null;
        try {
            $stmtChkInv = $conn->prepare("
                SELECT invoiceId FROM Invoices 
                WHERE roomId = ? AND (
                    billingMonth = ? 
                    OR billingMonth = ? 
                    OR billingMonth LIKE ?
                ) 
                ORDER BY invoiceId DESC LIMIT 1
            ");
            $stmtChkInv->execute([$requestedRoomId, $thReqMonth, $rawReqMonth, '%' . $rawReqMonth . '%']);
            $foundInvoiceId = $stmtChkInv->fetchColumn() ?: null;
        } catch(Throwable $e) {}

        try {
            $stmtIns = $conn->prepare("
                INSERT INTO RetroactiveInvoiceRequests 
                (tenantId, roomId, invoiceId, requestedMonth, requestType, reason, slipImage, status, createdAt) 
                VALUES (?, ?, ?, ?, ?, ?, NULL, 'Pending', NOW())
            ");
            $stmtIns->execute([$tenantId, $requestedRoomId, $foundInvoiceId, $rawReqMonth, $requestType, $reason]);
            
            $_SESSION['retro_flash_msg'] = "ส่งคำขอบิลย้อนหลังรอบเดือน " . ($thReqMonth ?: $rawReqMonth) . " เรียบร้อยแล้ว! คำขอถูกย้ายมาที่หน้าประวัติเพื่อรอเจ้าหน้าที่ตรวจสอบครับ";
            header("Location: tenant_retro_invoices.php?tab=history");
            echo "<script>window.location.replace('tenant_retro_invoices.php?tab=history');</script>";
            exit();
        } catch(PDOException $e) {
            $message = "<div class='p-4 mb-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-2xl text-xs sm:text-sm font-bold flex items-center justify-between shadow-xs'>
                <div class='flex items-center gap-2'>
                    <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>
                    <span>เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง</span>
                </div>
                <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>
            </div>";
        }
    }
}

// --- เมื่อผู้เช่ายกเลิกคำขอบิลย้อนหลังด้วยตนเอง ---
if (isset($_POST['cancel_retro_request'])) {
    $cancelReqId = intval($_POST['requestId'] ?? 0);
    if ($cancelReqId > 0) {
        try {
            $stmtCancel = $conn->prepare("
                UPDATE RetroactiveInvoiceRequests 
                SET status = 'Rejected', adminRemark = 'ผู้เช่ายกเลิกคำขอด้วยตนเอง', updatedAt = NOW() 
                WHERE requestId = ? AND tenantId = ? AND status = 'Pending'
            ");
            $stmtCancel->execute([$cancelReqId, $tenantId]);
            $_SESSION['retro_flash_msg'] = "ยกเลิกคำขอบิลย้อนหลังเรียบร้อยแล้ว";
            header("Location: tenant_retro_invoices.php?tab=history");
            echo "<script>window.location.replace('tenant_retro_invoices.php?tab=history');</script>";
            exit();
        } catch(PDOException $e) {}
    }
}

// Auto-link any requests with null invoiceId if matching invoice exists in Invoices
try {
    $unlinked = $conn->prepare("SELECT requestId, roomId, requestedMonth FROM RetroactiveInvoiceRequests WHERE tenantId = ? AND invoiceId IS NULL");
    $unlinked->execute([$tenantId]);
    foreach ($unlinked->fetchAll(PDO::FETCH_ASSOC) as $un) {
        $thM = toThaiBillingMonth($un['requestedMonth']);
        $chk = $conn->prepare("SELECT invoiceId FROM Invoices WHERE roomId = ? AND (billingMonth = ? OR billingMonth = ? OR billingMonth LIKE ?) ORDER BY invoiceId DESC LIMIT 1");
        $chk->execute([$un['roomId'], $thM, $un['requestedMonth'], '%' . $un['requestedMonth'] . '%']);
        $fId = $chk->fetchColumn();
        if ($fId) {
            $conn->prepare("UPDATE RetroactiveInvoiceRequests SET invoiceId = ? WHERE requestId = ?")->execute([$fId, $un['requestId']]);
        }
    }
} catch(Throwable $e) {}

// ดึงรายการคำขอบิลย้อนหลังทั้งหมดของผู้เช่าคนนี้
$myRetroRequests = [];
try {
    $stmtMyRetro = $conn->prepare("
        SELECT r.*, rm.roomNumber,
               COALESCE(r.invoiceId, inv_direct.invoiceId, inv.invoiceId) AS matchedInvoiceId,
               COALESCE(inv_direct.status, inv.status) AS matchedInvoiceStatus, 
               COALESCE(inv_direct.totalAmount, inv.totalAmount) AS matchedTotalAmount,
               COALESCE(inv_direct.billingMonth, inv.billingMonth) AS matchedBillingMonth
        FROM RetroactiveInvoiceRequests r
        LEFT JOIN Room rm ON r.roomId = rm.roomId
        LEFT JOIN Invoices inv_direct ON inv_direct.invoiceId = r.invoiceId
        LEFT JOIN Invoices inv ON (inv.roomId = r.roomId AND (
            inv.billingMonth = r.requestedMonth COLLATE utf8mb4_general_ci 
            OR inv.billingMonth LIKE CONCAT('%', r.requestedMonth COLLATE utf8mb4_general_ci, '%')
        ))
        WHERE r.tenantId = ?
        ORDER BY r.requestId DESC
    ");
    $stmtMyRetro->execute([$tenantId]);
    $myRetroRequests = $stmtMyRetro->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) {}

// นับจำนวนสถานะ
$countTotal = count($myRetroRequests);
$countPending = 0;
$countApproved = 0;
$countRejected = 0;
$latestPendingRequest = null;

foreach ($myRetroRequests as $req) {
    if ($req['status'] === 'Pending') {
        $countPending++;
        if ($latestPendingRequest === null) {
            $latestPendingRequest = $req;
        }
    } elseif ($req['status'] === 'Approved') {
        $countApproved++;
    } elseif ($req['status'] === 'Rejected') {
        $countRejected++;
    }
}

// กำหนดแท็บที่แสดงผล
if (isset($_GET['tab'])) {
    $activeTab = ($_GET['tab'] === 'history') ? 'history' : 'request';
} else {
    // หากมีคำขอที่ค้างรอตรวจสอบ หรือมีประวัติคำขอ ให้แสดงหน้าประวัติเป็นค่าเริ่มต้นทันที เพื่อให้เห็นสถานะชัดเจน
    $activeTab = ($countPending > 0 || $countTotal > 0) ? 'history' : 'request';
}

// ดึงข้อมูลใบแจ้งหนี้เพื่อใช้ใน Modal ดูรายละเอียดบิล
$allTenantInvoicesData = [];
$matchedInvIds = [];
foreach ($myRetroRequests as $req) {
    if (!empty($req['matchedInvoiceId'])) {
        $matchedInvIds[] = (int)$req['matchedInvoiceId'];
    }
}
if (!empty($matchedInvIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($matchedInvIds), '?'));
    $stmtInvModal = $conn->prepare("
        SELECT i.*, r.roomNumber, r.rentPrice AS roomBaseRent, r.roomType
        FROM Invoices i
        JOIN Room r ON i.roomId = r.roomId
        WHERE i.invoiceId IN ($inPlaceholders)
    ");
    $stmtInvModal->execute($matchedInvIds);
    $invoicesList = $stmtInvModal->fetchAll(PDO::FETCH_ASSOC);
    foreach ($invoicesList as $inv) {
        $wUnits = max(0, (int)($inv['waterCurr'] ?? 0) - (int)($inv['waterPrev'] ?? 0));
        $eUnits = max(0, (int)($inv['electricCurr'] ?? 0) - (int)($inv['electricPrev'] ?? 0));
        $wRate = (float)($inv['waterUnitRate'] ?? 18);
        $eRate = (float)($inv['electricUnitRate'] ?? 7);
        $rentPrice = (float)($inv['rentPrice'] ?? ($inv['roomBaseRent'] ?? 0));
        $wTotal = (float)($inv['waterTotal'] ?? ($wUnits * $wRate));
        $eTotal = (float)($inv['electricTotal'] ?? ($eUnits * $eRate));
        $otherFee = (float)($inv['otherFee'] ?? 0);
        $totalAmount = (float)($inv['totalAmount'] ?? ($rentPrice + $wTotal + $eTotal + $otherFee));

        $allTenantInvoicesData[$inv['invoiceId']] = [
            'invoiceId' => (int)$inv['invoiceId'],
            'invCode' => 'INV-' . str_pad($inv['invoiceId'], 4, '0', STR_PAD_LEFT),
            'billingMonth' => $inv['billingMonth'] ?? '-',
            'roomNumber' => $inv['roomNumber'] ?? ($myRoomNumber ?: '-'),
            'tenantName' => $tenantName,
            'status' => $inv['status'] ?? 'Unpaid',
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
            'otherFeeRemark' => $inv['otherFeeRemark'] ?? '',
            'otherFeesDetail' => $inv['otherFeesDetail'] ?? '',
            'totalAmount' => $totalAmount,
            'dueDate' => !empty($inv['dueDate']) ? date('d/m/Y', strtotime($inv['dueDate'])) : '-',
            'createdAt' => !empty($inv['createdAt']) ? date('d/m/Y H:i', strtotime($inv['createdAt'])) : '-',
            'slipImage' => $inv['slipImage'] ?? '',
            'paidAt' => !empty($inv['paidAt']) ? date('d/m/Y H:i', strtotime($inv['paidAt'])) : '-'
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>คำขอบิลย้อนหลัง - หอพักจัสมิน</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #FAF9F6; color: #1e293b; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .tab-indicator { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    </style>
    <?php include_once 'includes/swal.php'; ?>
    <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="pb-24 bg-[#FAF9F6] text-slate-800">

    <!-- แถบเมนูด้านข้างสำหรับผู้เช่า (Tenant Sidebar) -->
    <?php include_once 'includes/tenant_sidebar.php'; ?>

    <!-- Header ด้านบน -->
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100/80 shadow-sm">
        <div class="max-w-7xl 2xl:max-w-[90rem] mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center space-x-3">
                    <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer" title="เปิด/ปิด เมนู">
                        <i data-lucide="menu" class="w-5 h-5"></i>
                    </button>
                    <a href="tenant_dashboard.php" class="w-10 h-10 rounded-xl bg-rose-50/40 hover:bg-slate-100 border border-rose-100/80 text-slate-600 hover:text-slate-900 transition flex items-center justify-center shrink-0 group" title="กลับหน้าแดชบอร์ด">
                        <i data-lucide="arrow-left" class="w-5 h-5 group-hover:-translate-x-0.5 transition-transform"></i>
                    </a>
                    <div>
                        <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
                        <p class="text-[11px] text-slate-400 font-medium hidden sm:block">บริการลูกบ้าน &gt; คำขอบิลย้อนหลัง</p>
                    </div>
                </div>

                <div class="flex items-center space-x-2 sm:space-x-3">
                    <a href="view_invoices.php" class="px-3.5 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold transition flex items-center gap-1.5 shadow-2xs" title="ไปหน้ารายการใบแจ้งหนี้">
                        <i data-lucide="receipt" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">หน้ารายการบิล</span>
                    </a>
                    <?php include 'includes/theme_toggle.php'; ?>

                    <a href="profile.php" class="flex items-center gap-2 p-1 sm:p-1.5 sm:px-2.5 hover:bg-rose-50/70 rounded-full transition border border-transparent hover:border-rose-200 group" title="โปรไฟล์ผู้เช่า">
                        <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs border border-rose-200 shadow-2xs shrink-0 group-hover:scale-105 transition">
                            <?php echo $initials; ?>
                        </div>
                        <div class="hidden sm:block text-left pr-1">
                            <div class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition truncate max-w-[140px]"><?php echo htmlspecialchars($tenantName); ?></div>
                            <div class="text-[10px] text-rose-600 font-bold"><?php echo !empty($myRoomNumber) ? "ห้อง " . htmlspecialchars($myRoomNumber) : 'ลูกบ้าน'; ?></div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-7xl 2xl:max-w-[90rem] mx-auto px-4 sm:px-6 lg:px-8 pt-6 sm:pt-8 space-y-6">

        <!-- Flash Messages -->
        <?php if(!empty($message)) echo $message; ?>

        <!-- Real-time Status Alert Banner (แสดงเมื่อสถานะมีการเปลี่ยนแปลงสดผ่าน polling) -->
        <div id="realtimeNoticeBanner" class="hidden p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-emerald-800 text-xs sm:text-sm font-bold flex items-center justify-between shadow-xs transition-all animate-bounce">
            <div class="flex items-center gap-2">
                <i data-lucide="bell-ring" class="w-5 h-5 text-emerald-600 shrink-0"></i>
                <span id="realtimeNoticeText">มีการอัปเดตสถานะคำขอบิลย้อนหลังของคุณล่าสุด!</span>
            </div>
            <button type="button" onclick="window.location.reload()" class="px-3 py-1 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700 transition">
                รีเฟรชข้อมูล
            </button>
        </div>

        <!-- 🌟 Banner หัวข้อ & ข้อมูลห้องพักปัจจุบัน 🌟 -->
        <div class="bg-gradient-to-br from-rose-500/10 via-rose-50/30 to-pink-50/20 p-5 sm:p-6 rounded-3xl border border-rose-100/90 shadow-xs relative overflow-hidden">
            <div class="relative z-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-rose-600 to-pink-600 text-white flex items-center justify-center font-bold shadow-md shadow-rose-600/20 shrink-0">
                        <i data-lucide="file-clock" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-lg sm:text-xl font-bold font-display text-slate-800">คำขอบิลย้อนหลัง</h1>
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-white text-rose-700 border border-rose-200 shadow-2xs">
                                ห้อง <?php echo htmlspecialchars($myRoomNumber ?: '-'); ?>
                            </span>
                        </div>
                        <p class="text-xs sm:text-sm text-slate-500 mt-1">
                            ยื่นขอสำเนาใบเสร็จหรือบิลที่เคยชำระแล้วย้อนหลัง พร้อมติดตามสถานะแบบเรียลไทม์
                        </p>
                    </div>
                </div>
            </div>

            <!-- แท็บสลับหน้า: ยื่นคำขอบิลย้อนหลัง VS ประวัติคำขอ -->
            <div class="mt-6 pt-4 border-t border-rose-100 flex gap-2 overflow-x-auto hide-scrollbar">
                <!-- แท็บ 1: ยื่นคำขอบิลย้อนหลัง -->
                <button type="button" onclick="switchRetroTab('request')" id="tab-btn-request" class="<?php echo ($activeTab === 'request') ? 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
                    <i data-lucide="file-plus" class="w-4 h-4"></i>
                    <span>ยื่นคำขอบิลย้อนหลัง</span>
                    <?php if($countPending > 0): ?>
                        <span id="tabPendingBadge" class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold bg-amber-100 text-amber-800 rounded-full border border-amber-300">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            รอตรวจ (<?php echo $countPending; ?>)
                        </span>
                    <?php endif; ?>
                </button>

                <!-- แท็บ 2: ประวัติคำขอ -->
                <button type="button" onclick="switchRetroTab('history')" id="tab-btn-history" class="<?php echo ($activeTab === 'history') ? 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
                    <i data-lucide="history" class="w-4 h-4"></i>
                    <span>ประวัติคำขอ</span>
                    <span id="tabHistoryCount" class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-slate-100 text-slate-600 rounded-full">
                        <?php echo $countTotal; ?>
                    </span>
                </button>
            </div>
        </div>

        <!-- ================= TAB 1: ยื่นคำขอบิลย้อนหลัง ================= -->
        <div id="content-tab-request" class="<?php echo ($activeTab === 'request') ? 'space-y-6' : 'space-y-6 hidden'; ?>">

            <?php if($countPending > 0): ?>
            <!-- กล่องแจ้งเตือนคำขอที่ค้างอยู่ พร้อมปุ่มลัดไปหน้าประวัติ -->
            <div class="bg-amber-50/90 border border-amber-200/90 p-4 sm:p-5 rounded-3xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs sm:text-sm shadow-2xs">
                <div class="flex items-center gap-3 text-amber-900 font-medium">
                    <div class="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                        <i data-lucide="clock" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="font-bold text-slate-800">คุณมีคำขอบิลย้อนหลังรอเจ้าหน้าที่ตรวจสอบ (<?php echo $countPending; ?> รายการ)</div>
                        <div class="text-xs text-amber-800/80">ระบบได้ย้ายคำขอของคุณไปไว้ที่หน้าประวัติคำขอแล้ว สามารถติดตามผลได้ทันที</div>
                    </div>
                </div>
                <button type="button" onclick="switchRetroTab('history')" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shrink-0 cursor-pointer flex items-center justify-center gap-1.5 shadow-2xs">
                    <i data-lucide="history" class="w-4 h-4"></i>
                    <span>ไปยังหน้าประวัติคำขอ</span>
                </button>
            </div>
            <?php endif; ?>

            <!-- 🌟 ฟอร์มยื่นคำขอบิลย้อนหลัง 🌟 -->
            <div class="bg-white rounded-3xl border border-rose-100/90 shadow-sm p-6 sm:p-8">
                <div class="flex items-center gap-3 pb-5 border-b border-rose-100">
                    <div class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center font-bold shrink-0">
                        <i data-lucide="edit-3" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-800 font-display">กรอกข้อมูลคำขอบิลย้อนหลัง</h2>
                        <p class="text-xs text-slate-500">เลือกเดือนที่ต้องการ และระบุเหตุผลเพื่อให้นิติบุคคลจัดส่งสำเนาบิลหรือใบเสร็จรับเงินให้</p>
                    </div>
                </div>

                <form action="tenant_retro_invoices.php?tab=request" method="POST" id="retroForm" class="mt-6 space-y-6">
                    <input type="hidden" name="request_type" value="PaidInvoice">

                    <!-- ช่องที่ 1: เลือกรอบเดือนที่ต้องการขอบิล -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs sm:text-sm font-bold text-slate-700">
                                รอบเดือนที่ต้องการขอบิล <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[11px] text-slate-400">เลือกได้ไม่เกินเดือนปัจจุบัน (<?php echo date('Y-m'); ?>)</span>
                        </div>

                        <div class="relative max-w-sm">
                            <input type="month" id="requestedMonthInput" name="requested_month" required
                                   max="<?php echo date('Y-m'); ?>"
                                   value="<?php echo date('Y-m', strtotime('-1 month')); ?>"
                                   class="w-full p-3.5 bg-slate-50 hover:bg-white focus:bg-white border-2 border-slate-200 focus:border-rose-400 rounded-2xl text-xs sm:text-sm font-mono font-bold focus:outline-none focus:ring-4 focus:ring-rose-500/10 transition">
                        </div>
                    </div>

                    <!-- ช่องที่ 2: เหตุผลในการขอ -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs sm:text-sm font-bold text-slate-700">
                                เหตุผลในการขอ <span class="text-rose-500">*</span>
                            </label>
                            <span class="text-[11px] text-rose-500 font-semibold">กดปุ่มด่วนได้</span>
                        </div>

                        <!-- Preset เหตุผล -->
                        <div class="flex flex-wrap gap-2 mb-3">
                            <button type="button" onclick="setRetroReason('ต้องการนำใบเสร็จไปเบิกค่าเช่ากับบริษัท/หน่วยงาน')" class="px-3 py-1.5 bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-rose-700 rounded-xl text-xs font-semibold border border-slate-200 hover:border-rose-300 transition cursor-pointer">
                                🏢 เบิกบริษัท/หน่วยงาน
                            </button>
                            <button type="button" onclick="setRetroReason('ขอสำเนาใบเสร็จรับเงินรอบเดือนเดิมย้อนหลัง')" class="px-3 py-1.5 bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-rose-700 rounded-xl text-xs font-semibold border border-slate-200 hover:border-rose-300 transition cursor-pointer">
                                📑 ขอสำเนาใบเสร็จเดิม
                            </button>
                            <button type="button" onclick="setRetroReason('ต้องการบิลรอบเดือนที่ผ่านมาเพื่อทำบัญชีรายรับ-รายจ่าย')" class="px-3 py-1.5 bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-rose-700 rounded-xl text-xs font-semibold border border-slate-200 hover:border-rose-300 transition cursor-pointer">
                                📊 ทำบัญชีส่วนตัว
                            </button>
                        </div>

                        <textarea id="retroReasonInput" name="reason" rows="3" required
                                  placeholder="ระบุเหตุผลหรือรายละเอียดเพิ่มเติม เช่น ขอสำเนาใบเสร็จเพื่อนำไปเบิกค่าเช่ากับหน่วยงานต้นสังกัด..."
                                  class="w-full p-4 bg-slate-50 hover:bg-white focus:bg-white border-2 border-slate-200 focus:border-rose-400 rounded-2xl text-xs sm:text-sm focus:outline-none focus:ring-4 focus:ring-rose-500/10 transition placeholder:text-slate-400 leading-relaxed"></textarea>
                    </div>

                    <!-- กล่องข้อมูลแนะนำ -->
                    <div class="p-4 bg-blue-50/70 border border-blue-200/80 rounded-2xl flex items-start gap-3 text-xs text-blue-900">
                        <i data-lucide="info" class="w-5 h-5 text-blue-600 shrink-0 mt-0.5"></i>
                        <div class="space-y-1">
                            <strong class="font-bold">ข้อมูลประกอบการยื่นคำขอ</strong>
                            <p class="text-blue-800 leading-relaxed">
                                ลูกบ้านไม่ต้องแนบสลิปการโอนเงินใหม่ เจ้าหน้าที่จะตรวจสอบข้อมูลประวัติการชำระเงินของห้องคุณในระบบให้โดยอัตโนมัติ และจะทำการอนุมัติพร้อมเปิดให้ดาวน์โหลดหรือพิมพ์ใบเสร็จในแท็บ "ประวัติคำขอ" ได้ทันทีครับ
                            </p>
                        </div>
                    </div>

                    <!-- ปุ่มส่งคำขอ -->
                    <div class="pt-4 border-t border-rose-100 flex items-center justify-end gap-3">
                        <a href="view_invoices.php" class="px-5 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition">
                            กลับหน้ารายการบิล
                        </a>
                        <button type="submit" name="submit_retro_request" class="px-6 py-3 bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-lg shadow-rose-600/25 transition flex items-center gap-2 cursor-pointer active:scale-95">
                            <i data-lucide="send" class="w-4 h-4"></i>
                            <span>ส่งคำขอบิลย้อนหลัง</span>
                        </button>
                    </div>
                </form>
            </div>

        </div> <!-- End Tab 1 -->

        <!-- ================= TAB 2: ประวัติคำขอ ================= -->
        <div id="content-tab-history" class="<?php echo ($activeTab === 'history') ? 'space-y-4' : 'space-y-4 hidden'; ?>">

            <!-- Filter Bar & Summary Header -->
            <div class="bg-white rounded-3xl border border-rose-100/90 shadow-sm p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-slate-500">กรองสถานะ:</span>
                    <div class="flex items-center gap-1.5 overflow-x-auto hide-scrollbar" id="statusFilterTabs">
                        <button type="button" onclick="filterRequests('all')" class="filter-btn active px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white cursor-pointer" data-filter="all">
                            ทั้งหมด (<?php echo $countTotal; ?>)
                        </button>
                        <button type="button" onclick="filterRequests('Pending')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer" data-filter="Pending">
                            ⏳ รอตรวจสอบ (<?php echo $countPending; ?>)
                        </button>
                        <button type="button" onclick="filterRequests('Approved')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer" data-filter="Approved">
                            ✅ อนุมัติแล้ว (<?php echo $countApproved; ?>)
                        </button>
                        <?php if($countRejected > 0): ?>
                        <button type="button" onclick="filterRequests('Rejected')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer" data-filter="Rejected">
                            ❌ ยกเลิก/ไม่อนุมัติ (<?php echo $countRejected; ?>)
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- List of Request History Cards -->
            <?php if(!empty($myRetroRequests)): ?>
                <div class="space-y-3" id="requestsListContainer">
                    <?php foreach($myRetroRequests as $req): 
                        $status = $req['status'] ?? 'Pending';
                        $monthText = $req['requestedMonth'];
                        if (preg_match('/^(\d{4})-(\d{2})$/', trim($req['requestedMonth']), $mMatches)) {
                            $monthText = toThaiBillingMonth($req['requestedMonth']);
                        }
                    ?>
                    <div class="request-item-card bg-white hover:bg-rose-50/20 rounded-2xl border border-slate-200/90 hover:border-rose-300 shadow-2xs hover:shadow-md p-4 sm:p-5 transition-all cursor-pointer flex flex-col sm:flex-row sm:items-center justify-between gap-3 group"
                         data-status="<?php echo htmlspecialchars($status); ?>"
                         data-request-id="<?php echo (int)$req['requestId']; ?>"
                         onclick='openRetroDetailModal(<?php echo htmlspecialchars(json_encode($req), ENT_QUOTES, "UTF-8"); ?>)'>
                        
                        <!-- Left: Icon + Month + Reason snippet + Date -->
                        <div class="flex items-center gap-3.5 min-w-0 flex-1">
                            <div class="w-11 h-11 rounded-2xl <?php 
                                if ($status === 'Approved') echo 'bg-emerald-50 text-emerald-600 border border-emerald-200';
                                elseif ($status === 'Pending') echo 'bg-amber-50 text-amber-600 border border-amber-200';
                                else echo 'bg-slate-100 text-slate-500 border border-slate-200';
                            ?> flex items-center justify-center font-bold shrink-0 shadow-2xs group-hover:scale-105 transition">
                                <i data-lucide="<?php 
                                    if ($status === 'Approved') echo 'check-circle-2';
                                    elseif ($status === 'Pending') echo 'clock';
                                    else echo 'x-circle';
                                ?>" class="w-5 h-5"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="font-display font-bold text-slate-800 text-sm sm:text-base group-hover:text-rose-600 transition">
                                        รอบเดือน: <?php echo htmlspecialchars($monthText); ?>
                                    </h4>
                                    <span class="text-[11px] font-mono px-2 py-0.5 rounded-md bg-slate-100 text-slate-600 border border-slate-200 font-semibold">
                                        <?php echo htmlspecialchars($req['requestedMonth']); ?>
                                    </span>
                                    <?php if($status === 'Approved' && !empty($req['matchedInvoiceId'])): ?>
                                        <span class="text-[11px] font-mono font-bold px-2 py-0.5 rounded-md bg-emerald-100 text-emerald-800 border border-emerald-300">
                                            #INV-<?php echo str_pad($req['matchedInvoiceId'], 5, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-slate-500 truncate mt-1">
                                    <span class="font-semibold text-slate-600">เหตุผล:</span> <?php echo htmlspecialchars(mb_strimwidth($req['reason'] ?: 'ขอบิลย้อนหลัง', 0, 80, '...')); ?>
                                </p>
                                <div class="flex items-center gap-3 text-[11px] text-slate-400 mt-1">
                                    <span>ยื่นเมื่อ: <?php echo !empty($req['createdAt']) ? date('d/m/Y H:i', strtotime($req['createdAt'])) : '-'; ?> น.</span>
                                    <?php if(!empty($req['updatedAt'])): ?>
                                        <span>• อัปเดต: <?php echo date('d/m/Y H:i', strtotime($req['updatedAt'])); ?> น.</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Status Badge & Open Detail Pill -->
                        <div class="flex items-center gap-2.5 sm:self-center shrink-0 justify-between sm:justify-end border-t sm:border-t-0 pt-2 sm:pt-0 border-slate-100">
                            <?php if($status === 'Pending'): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200">
                                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                    <span>รอตรวจสอบ</span>
                                </span>
                            <?php elseif($status === 'Approved'): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                                    <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i>
                                    <span>อนุมัติส่งบิลแล้ว</span>
                                </span>
                            <?php elseif($status === 'Rejected'): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                    <i data-lucide="x-circle" class="w-3.5 h-3.5 text-slate-500"></i>
                                    <span>ยกเลิก</span>
                                </span>
                            <?php endif; ?>

                            <span class="inline-flex items-center gap-1 text-xs font-bold text-rose-600 group-hover:text-rose-700 bg-rose-50 group-hover:bg-rose-100 px-3 py-1.5 rounded-xl border border-rose-200 transition">
                                <span>ดูรายละเอียด</span>
                                <i data-lucide="chevron-right" class="w-4 h-4 group-hover:translate-x-0.5 transition-transform"></i>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- กรณีไม่มีประวัติคำขอ -->
                <div class="text-center py-14 px-6 bg-white rounded-3xl border border-rose-100 shadow-sm">
                    <div class="w-16 h-16 bg-rose-50 text-rose-500 rounded-2xl flex items-center justify-center mx-auto mb-3 shadow-2xs border border-rose-200/60">
                        <i data-lucide="file-clock" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800 font-display">ยังไม่มีประวัติคำขอบิลย้อนหลัง</h3>
                    <p class="text-xs sm:text-sm text-slate-500 max-w-md mx-auto mt-1 leading-relaxed">
                        หากคุณต้องการขอสำเนาบิลหรือใบเสร็จรับเงินรอบเดือนที่ผ่านมา สามารถยื่นคำขอได้ทันทีในแท็บ "ยื่นคำขอบิลย้อนหลัง" ครับ
                    </p>
                    <div class="mt-5">
                        <button type="button" onclick="switchRetroTab('request')" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-md shadow-rose-600/25 transition inline-flex items-center gap-2 cursor-pointer">
                            <i data-lucide="file-plus" class="w-4 h-4"></i>
                            <span>ยื่นคำขอบิลย้อนหลังตอนนี้</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

        </div> <!-- End Tab 2 -->

    </main>

    <!-- 🌟 MODAL: รายละเอียดคำขอบิลย้อนหลังฉบับเต็ม 🌟 -->
    <div id="retroDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-3 sm:p-4 overflow-y-auto" onclick="closeRetroDetailModal()">
        <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-100 flex flex-col my-auto" onclick="event.stopPropagation()">
            
            <!-- Modal Header -->
            <div class="px-6 py-5 border-b border-rose-100 flex items-center justify-between bg-gradient-to-r from-rose-50/90 via-pink-50/60 to-rose-50/90 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold shadow-2xs shrink-0">
                        <i data-lucide="file-clock" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h3 class="font-display font-bold text-slate-800 text-base sm:text-lg">รายละเอียดคำขอบิลย้อนหลัง</h3>
                            <span id="rdm_status_badge" class="px-3 py-1 rounded-full text-xs font-bold border shadow-2xs">
                                -
                            </span>
                        </div>
                        <p class="text-xs text-rose-700 font-medium mt-0.5" id="rdm_month_header">
                            รอบบิลเดือน: -
                        </p>
                    </div>
                </div>
                <button type="button" onclick="closeRetroDetailModal()" class="w-9 h-9 rounded-xl bg-white/80 hover:bg-white text-slate-400 hover:text-slate-600 border border-slate-200 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิดหน้าต่าง">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-6 space-y-4 max-h-[75vh] overflow-y-auto">
                
                <!-- ข้อมูลสรุปคำขอ -->
                <div class="p-4 bg-slate-50/90 rounded-2xl border border-slate-200/70 space-y-2.5 text-xs">
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200/60">
                        <span class="text-slate-500 font-semibold">ห้องพัก:</span>
                        <span class="font-bold text-slate-800" id="rdm_room">-</span>
                    </div>
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200/60">
                        <span class="text-slate-500 font-semibold">รอบเดือนที่ขอ:</span>
                        <span class="font-bold text-slate-900 font-mono" id="rdm_month">-</span>
                    </div>
                    <div class="flex items-center justify-between pb-2 border-b border-slate-200/60">
                        <span class="text-slate-500 font-semibold">วันที่ยื่นคำขอ:</span>
                        <span class="font-bold text-slate-700" id="rdm_created_at">-</span>
                    </div>
                    <div class="flex items-center justify-between" id="rdm_updated_row">
                        <span class="text-slate-500 font-semibold">อัปเดตล่าสุด:</span>
                        <span class="font-bold text-slate-700" id="rdm_updated_at">-</span>
                    </div>
                </div>

                <!-- เหตุผลในการขอ -->
                <div class="p-4 bg-white rounded-2xl border border-slate-200/80 shadow-2xs space-y-1">
                    <span class="text-xs font-bold text-slate-700 block">เหตุผลหรือรายละเอียดที่ระบุ:</span>
                    <p class="text-xs text-slate-600 leading-relaxed whitespace-pre-wrap" id="rdm_reason">-</p>
                </div>

                <!-- ข้อความตอบกลับจากผู้ดูแล/แอดมิน (ถ้ามี) -->
                <div id="rdm_remark_box" class="p-4 rounded-2xl border text-xs space-y-1 hidden">
                    <div class="flex items-center gap-1.5 font-bold mb-1" id="rdm_remark_header">
                        <i data-lucide="message-square" class="w-4 h-4"></i>
                        <span>ข้อความตอบกลับจากเจ้าหน้าที่:</span>
                    </div>
                    <p class="leading-relaxed" id="rdm_remark_text">-</p>
                </div>

                <!-- การ์ดบิลที่ได้รับกลับมา (เมื่ออนุมัติและพบบิล) -->
                <div id="rdm_invoice_box" class="p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-emerald-50/90 via-teal-50/60 to-white border border-emerald-200 space-y-3 hidden">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold shrink-0">
                                <i data-lucide="receipt" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-display font-bold text-slate-800 text-sm" id="rdm_inv_code">#INV-00000</span>
                                    <span id="rdm_inv_status_badge" class="px-2 py-0.5 text-[10px] font-bold rounded-md">
                                        -
                                    </span>
                                </div>
                                <div class="text-xs text-slate-500 font-mono mt-0.5">
                                    ยอดชำระ: <b class="text-slate-800" id="rdm_inv_amount">฿0.00</b>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-emerald-200/60 flex-wrap">
                        <button type="button" id="rdm_view_inv_btn" class="px-4 py-2 bg-white hover:bg-slate-50 text-slate-800 border border-slate-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs cursor-pointer">
                            <i data-lucide="eye" class="w-4 h-4 text-emerald-600"></i> ดูรายละเอียดบิลฉบับเต็ม
                        </button>
                        <a href="#" target="_blank" id="rdm_print_rcpt_btn" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs cursor-pointer hidden">
                            <i data-lucide="printer" class="w-4 h-4"></i> พิมพ์ใบเสร็จ (PDF)
                        </a>
                    </div>
                </div>

                <!-- กล่องข้อความเมื่อรอตรวจสอบ (Pending) -->
                <div id="rdm_pending_notice" class="p-4 rounded-2xl bg-amber-50/80 border border-amber-200 text-xs text-amber-800 space-y-2 hidden">
                    <div class="flex items-start gap-2">
                        <i data-lucide="clock" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>
                        <p class="leading-relaxed">
                            เจ้าหน้าที่นิติบุคคลกำลังตรวจสอบข้อมูลการชำระเงินของรอบเดือนนี้ เมื่ออนุมัติแล้ว คุณสามารถเปิดดูบิลและพิมพ์ใบเสร็จรับเงินได้จากหน้านี้ทันทีครับ
                        </p>
                    </div>
                    <!-- ฟอร์มยกเลิกคำขอ -->
                    <div class="pt-2 border-t border-amber-200/60 flex justify-end">
                        <form action="tenant_retro_invoices.php?tab=history" method="POST" class="m-0" onsubmit="return confirm('คุณต้องการยกเลิกคำขอบิลย้อนหลังรอบเดือนนี้ใช่หรือไม่?');">
                            <input type="hidden" name="requestId" id="rdm_cancel_req_id" value="">
                            <button type="submit" name="cancel_retro_request" class="px-3.5 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-2xs">
                                <i data-lucide="x" class="w-3.5 h-3.5"></i> ยกเลิกคำขอนี้
                            </button>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
                <button type="button" onclick="closeRetroDetailModal()" class="px-5 py-2 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-xl text-xs sm:text-sm font-bold transition cursor-pointer shadow-2xs">
                    ปิดหน้าต่าง
                </button>
            </div>

        </div>
    </div>

    <!-- 🌟 MODAL จอใหญ่: รายละเอียดใบแจ้งหนี้/บิลแบบเต็ม 🌟 -->
    <div id="tenantInvoiceDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeTenantInvoiceModal()">
        <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200 flex flex-col" onclick="event.stopPropagation()">
            
            <!-- Modal Header -->
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
                    <span id="dash_inv_detail_status_badge" class="px-3 py-1 rounded-xl text-xs font-bold shadow-2xs">
                        -
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="closeTenantInvoiceModal()" class="w-9 h-9 rounded-xl bg-white hover:bg-rose-50 text-slate-400 hover:text-rose-600 transition flex items-center justify-center border border-slate-200 cursor-pointer" title="ปิดหน้าต่าง">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>

            <!-- Modal Content (2-Column Grid) -->
            <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-slate-50/50">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 max-w-7xl mx-auto">
                    
                    <!-- ฝั่งซ้าย: รายการค่าใช้จ่าย -->
                    <div class="lg:col-span-7 bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-5">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div class="flex items-center gap-2.5">
                                <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                                    <i data-lucide="file-text" class="w-5 h-5"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-slate-800 text-sm">รายละเอียดค่าใช้จ่าย</h4>
                                    <p class="text-[11px] text-slate-400">รายการแจกแจงค่าเช่าและค่าสาธารณูปโภค</p>
                                </div>
                            </div>
                            <span class="text-xs font-mono text-slate-500">ห้อง <b id="dash_inv_detail_room_badge">-</b></span>
                        </div>

                        <!-- รายการ 1: ค่าเช่าห้อง -->
                        <div class="flex items-center justify-between p-3.5 bg-slate-50/80 rounded-2xl border border-slate-100">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                    <i data-lucide="home" class="w-4 h-4"></i>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-slate-800">ค่าเช่าห้องพัก</div>
                                    <div class="text-[10px] text-slate-400">อัตราตามสัญญาเช่า</div>
                                </div>
                            </div>
                            <div class="text-sm font-bold font-mono text-slate-800">฿<span id="dash_inv_detail_rent">0.00</span></div>
                        </div>

                        <!-- รายการ 2: ค่าน้ำประปา -->
                        <div class="p-3.5 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center">
                                        <i data-lucide="droplet" class="w-4 h-4"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-800">ค่าน้ำประปา</div>
                                        <div class="text-[10px] text-slate-400">มิเตอร์: <span id="dash_inv_detail_water_prev">0</span> ➔ <span id="dash_inv_detail_water_curr">0</span> (<span id="dash_inv_detail_water_units">0</span> หน่วย @ ฿<span id="dash_inv_detail_water_rate">18</span>)</div>
                                    </div>
                                </div>
                                <div class="text-sm font-bold font-mono text-sky-700">฿<span id="dash_inv_detail_water_total">0.00</span></div>
                            </div>
                        </div>

                        <!-- รายการ 3: ค่าไฟฟ้า -->
                        <div class="p-3.5 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-2">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                                        <i data-lucide="zap" class="w-4 h-4"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-800">ค่ากระแสไฟฟ้า</div>
                                        <div class="text-[10px] text-slate-400">มิเตอร์: <span id="dash_inv_detail_elec_prev">0</span> ➔ <span id="dash_inv_detail_elec_curr">0</span> (<span id="dash_inv_detail_elec_units">0</span> หน่วย @ ฿<span id="dash_inv_detail_elec_rate">7</span>)</div>
                                    </div>
                                </div>
                                <div class="text-sm font-bold font-mono text-amber-700">฿<span id="dash_inv_detail_elec_total">0.00</span></div>
                            </div>
                        </div>

                        <!-- รายการ 4: ค่าบริการอื่นๆ -->
                        <div id="dash_inv_detail_other_box" class="p-3.5 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-1">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center">
                                        <i data-lucide="plus-circle" class="w-4 h-4"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-800">ค่าบริการอื่นๆ / ค่าปรับ</div>
                                        <div class="text-[10px] text-slate-500" id="dash_inv_detail_other_remark">-</div>
                                    </div>
                                </div>
                                <div class="text-sm font-bold font-mono text-purple-700">฿<span id="dash_inv_detail_other_total">0.00</span></div>
                            </div>
                        </div>

                        <!-- ยอดรวมทั้งสิ้น -->
                        <div class="p-4 bg-gradient-to-r from-rose-50 via-pink-50 to-rose-50 rounded-2xl border border-rose-200 flex items-center justify-between">
                            <div>
                                <div class="text-xs font-bold text-slate-700">ยอดรวมทั้งสิ้นที่ต้องชำระ</div>
                                <div class="text-[10px] text-slate-500">ครบกำหนด: <span id="dash_inv_detail_due_date" class="font-bold text-rose-600">-</span></div>
                            </div>
                            <div class="text-xl sm:text-2xl font-bold font-mono text-rose-600">฿<span id="dash_inv_detail_grand_total">0.00</span></div>
                        </div>
                    </div>

                    <!-- ฝั่งขวา: สถานะและใบเสร็จ -->
                    <div class="lg:col-span-5 bg-white rounded-3xl p-5 sm:p-6 border border-slate-200 shadow-sm space-y-5 flex flex-col justify-between">
                        <div class="space-y-4">
                            <div class="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-slate-800 text-sm">การชำระเงินและใบเสร็จ</h4>
                                    <p class="text-[11px] text-slate-400">สถานะการชำระเงินและเอกสารหลักฐาน</p>
                                </div>
                            </div>

                            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 space-y-2 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">สถานะบิล:</span>
                                    <span id="dash_inv_detail_pay_status" class="font-bold">-</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">วันที่สร้างบิล:</span>
                                    <span id="dash_inv_detail_created_at" class="font-mono text-slate-700">-</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">วันที่ชำระเงิน:</span>
                                    <span id="dash_inv_detail_paid_at" class="font-mono text-emerald-700 font-bold">-</span>
                                </div>
                            </div>

                            <!-- รูปสลิป (ถ้ามี) -->
                            <div id="dash_inv_detail_slip_box" class="hidden space-y-2">
                                <div class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                    <i data-lucide="image" class="w-4 h-4 text-rose-500"></i> สลิปการโอนเงินที่แนบไว้:
                                </div>
                                <div class="border border-slate-200 rounded-2xl p-2 bg-slate-50 text-center">
                                    <img id="dash_inv_detail_slip_img" src="" alt="สลิปโอนเงิน" class="max-h-48 mx-auto rounded-xl object-contain cursor-pointer shadow-xs hover:opacity-95 transition" onclick="window.open(this.src, '_blank')">
                                    <p class="text-[10px] text-slate-400 mt-1">คลิกที่ภาพเพื่อดูขนาดเต็ม</p>
                                </div>
                            </div>
                        </div>

                        <!-- ปุ่มพิมพ์ใบเสร็จ -->
                        <div class="pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
                            <button type="button" onclick="closeTenantInvoiceModal()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
                                ปิดหน้าต่าง
                            </button>
                            <a id="dash_inv_detail_print_btn" href="#" target="_blank" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-2 shadow-sm">
                                <i data-lucide="printer" class="w-4 h-4"></i>
                                <span>พิมพ์ใบเสร็จ (PDF)</span>
                            </a>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <!-- JavaScript logic -->
    <script>
        // ข้อมูลใบแจ้งหนี้สำหรับ Modal
        window.tenantInvoices = <?php echo json_encode($allTenantInvoicesData, JSON_UNESCAPED_UNICODE); ?>;

        // สลับแท็บ
        function switchRetroTab(tab) {
            const reqContent = document.getElementById('content-tab-request');
            const histContent = document.getElementById('content-tab-history');
            const reqBtn = document.getElementById('tab-btn-request');
            const histBtn = document.getElementById('tab-btn-history');

            if (tab === 'request') {
                reqContent.classList.remove('hidden');
                histContent.classList.add('hidden');
                reqBtn.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
                histBtn.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            } else {
                reqContent.classList.add('hidden');
                histContent.classList.remove('hidden');
                histBtn.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
                reqBtn.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            }
            if (window.lucide) lucide.createIcons();
        }

        // ตั้งค่าเหตุผลจากปุ่มด่วน
        function setRetroReason(text) {
            const textarea = document.getElementById('retroReasonInput');
            if (textarea) {
                textarea.value = text;
                textarea.focus();
            }
        }

        // กรองสถานะในแท็บประวัติ
        function filterRequests(status) {
            document.querySelectorAll('#statusFilterTabs .filter-btn').forEach(btn => {
                if (btn.getAttribute('data-filter') === status) {
                    btn.className = 'filter-btn active px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white cursor-pointer';
                } else {
                    btn.className = 'filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer';
                }
            });

            document.querySelectorAll('.request-item-card').forEach(card => {
                const cardStatus = card.getAttribute('data-status');
                if (status === 'all' || cardStatus === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // เปิด Modal รายละเอียดบิล
        function openTenantInvoiceModal(invId) {
            const inv = window.tenantInvoices ? (window.tenantInvoices[invId] || window.tenantInvoices[String(invId)]) : null;
            if (!inv) return;

            document.getElementById('dash_inv_detail_room').textContent = inv.roomNumber || '-';
            document.getElementById('dash_inv_detail_room_badge').textContent = inv.roomNumber || '-';
            document.getElementById('dash_inv_detail_code').textContent = inv.invCode || ('INV-' + invId);
            document.getElementById('dash_inv_detail_month').textContent = inv.billingMonth || '-';
            
            const badge = document.getElementById('dash_inv_detail_status_badge');
            const payStatus = document.getElementById('dash_inv_detail_pay_status');
            const printBtn = document.getElementById('dash_inv_detail_print_btn');

            if (inv.status === 'Paid') {
                badge.className = 'px-3 py-1 rounded-xl text-xs font-bold shadow-2xs bg-emerald-100 text-emerald-800 border border-emerald-300';
                badge.textContent = 'ชำระเงินเรียบร้อยแล้ว';
                payStatus.className = 'font-bold text-emerald-600';
                payStatus.textContent = 'ชำระเงินแล้ว';
                printBtn.classList.remove('hidden');
                printBtn.href = 'print_receipt.php?id=' + invId;
            } else if (inv.status === 'Pending Approval') {
                badge.className = 'px-3 py-1 rounded-xl text-xs font-bold shadow-2xs bg-amber-100 text-amber-800 border border-amber-300';
                badge.textContent = 'รอเจ้าหน้าที่ตรวจสอบสลิป';
                payStatus.className = 'font-bold text-amber-600';
                payStatus.textContent = 'รอตรวจสอบสลิป';
                printBtn.classList.add('hidden');
            } else {
                badge.className = 'px-3 py-1 rounded-xl text-xs font-bold shadow-2xs bg-rose-100 text-rose-800 border border-rose-300';
                badge.textContent = 'ยังไม่ชำระเงิน';
                payStatus.className = 'font-bold text-rose-600';
                payStatus.textContent = 'ค้างชำระ';
                printBtn.classList.add('hidden');
            }

            document.getElementById('dash_inv_detail_rent').textContent = Number(inv.rentPrice || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});
            document.getElementById('dash_inv_detail_water_prev').textContent = inv.waterPrev || 0;
            document.getElementById('dash_inv_detail_water_curr').textContent = inv.waterCurr || 0;
            document.getElementById('dash_inv_detail_water_units').textContent = inv.waterUnits || 0;
            document.getElementById('dash_inv_detail_water_rate').textContent = inv.waterRate || 18;
            document.getElementById('dash_inv_detail_water_total').textContent = Number(inv.waterTotal || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});

            document.getElementById('dash_inv_detail_elec_prev').textContent = inv.electricPrev || 0;
            document.getElementById('dash_inv_detail_elec_curr').textContent = inv.electricCurr || 0;
            document.getElementById('dash_inv_detail_elec_units').textContent = inv.electricUnits || 0;
            document.getElementById('dash_inv_detail_elec_rate').textContent = inv.electricRate || 7;
            document.getElementById('dash_inv_detail_elec_total').textContent = Number(inv.electricTotal || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});

            const otherBox = document.getElementById('dash_inv_detail_other_box');
            if (Number(inv.otherFee || 0) > 0) {
                otherBox.classList.remove('hidden');
                document.getElementById('dash_inv_detail_other_remark').textContent = inv.otherFeeRemark || 'ค่าบริการอื่นๆ ตามสัญญา';
                document.getElementById('dash_inv_detail_other_total').textContent = Number(inv.otherFee || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});
            } else {
                otherBox.classList.add('hidden');
            }

            document.getElementById('dash_inv_detail_due_date').textContent = inv.dueDate || '-';
            document.getElementById('dash_inv_detail_grand_total').textContent = Number(inv.totalAmount || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});
            document.getElementById('dash_inv_detail_created_at').textContent = inv.createdAt || '-';
            document.getElementById('dash_inv_detail_paid_at').textContent = inv.paidAt || '-';

            const slipBox = document.getElementById('dash_inv_detail_slip_box');
            const slipImg = document.getElementById('dash_inv_detail_slip_img');
            if (inv.slipImage && inv.slipImage.trim() !== '') {
                slipBox.classList.remove('hidden');
                slipImg.src = inv.slipImage;
            } else {
                slipBox.classList.add('hidden');
            }

            document.getElementById('tenantInvoiceDetailModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            if (window.lucide) lucide.createIcons();
        }

        function closeTenantInvoiceModal() {
            document.getElementById('tenantInvoiceDetailModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        function openRetroDetailModal(req) {
            if (!req) return;
            
            const status = req.status || 'Pending';
            const badge = document.getElementById('rdm_status_badge');
            if (status === 'Approved') {
                badge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-2xs flex items-center gap-1';
                badge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> อนุมัติส่งบิลแล้ว';
            } else if (status === 'Pending') {
                badge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-800 border border-amber-200 shadow-2xs flex items-center gap-1';
                badge.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> รอเจ้าหน้าที่ตรวจสอบ';
            } else {
                badge.className = 'px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200 shadow-2xs flex items-center gap-1';
                badge.innerHTML = '<i data-lucide="x-circle" class="w-3.5 h-3.5 text-slate-500"></i> ยกเลิกคำขอ';
            }

            let monthText = req.requestedMonth || '-';
            document.getElementById('rdm_month_header').textContent = 'รอบบิลเดือน: ' + monthText + ' (' + (req.requestedMonth || '-') + ')';
            document.getElementById('rdm_room').textContent = 'ห้อง ' + (req.roomNumber || '<?php echo htmlspecialchars($myRoomNumber ?: "-"); ?>');
            document.getElementById('rdm_month').textContent = req.requestedMonth || '-';
            document.getElementById('rdm_created_at').textContent = req.createdAt ? (req.createdAt + ' น.') : '-';
            
            const updRow = document.getElementById('rdm_updated_row');
            if (req.updatedAt) {
                updRow.classList.remove('hidden');
                document.getElementById('rdm_updated_at').textContent = req.updatedAt + ' น.';
            } else {
                updRow.classList.add('hidden');
            }

            document.getElementById('rdm_reason').textContent = req.reason || 'ไม่ได้ระบุเหตุผล';

            const remarkBox = document.getElementById('rdm_remark_box');
            const remarkText = document.getElementById('rdm_remark_text');
            if (req.adminRemark && req.adminRemark.trim() !== '') {
                remarkBox.classList.remove('hidden');
                if (status === 'Approved') {
                    remarkBox.className = 'p-4 rounded-2xl border text-xs space-y-1 bg-emerald-50 border-emerald-200 text-emerald-800';
                } else if (req.adminRemark === 'ผู้เช่ายกเลิกคำขอด้วยตนเอง') {
                    remarkBox.className = 'p-4 rounded-2xl border text-xs space-y-1 bg-slate-50 border-slate-200 text-slate-700';
                } else {
                    remarkBox.className = 'p-4 rounded-2xl border text-xs space-y-1 bg-rose-50 border-rose-200 text-rose-800';
                }
                remarkText.textContent = req.adminRemark;
            } else {
                remarkBox.classList.add('hidden');
            }

            const invBox = document.getElementById('rdm_invoice_box');
            const viewBtn = document.getElementById('rdm_view_inv_btn');
            const printBtn = document.getElementById('rdm_print_rcpt_btn');
            if (req.matchedInvoiceId) {
                invBox.classList.remove('hidden');
                document.getElementById('rdm_inv_code').textContent = '#INV-' + String(req.matchedInvoiceId).padStart(5, '0');
                const stBadge = document.getElementById('rdm_inv_status_badge');
                if (req.matchedInvoiceStatus === 'Paid') {
                    stBadge.className = 'px-2 py-0.5 text-[10px] font-bold rounded-md bg-emerald-100 text-emerald-700';
                    stBadge.textContent = 'ชำระแล้ว (มีใบเสร็จ)';
                    printBtn.classList.remove('hidden');
                    printBtn.href = 'print_receipt.php?id=' + req.matchedInvoiceId;
                } else {
                    stBadge.className = 'px-2 py-0.5 text-[10px] font-bold rounded-md bg-amber-100 text-amber-700';
                    stBadge.textContent = 'รอชำระ';
                    printBtn.classList.add('hidden');
                }
                document.getElementById('rdm_inv_amount').textContent = '฿' + Number(req.matchedTotalAmount || 0).toLocaleString('th-TH', {minimumFractionDigits: 2});
                viewBtn.setAttribute('onclick', 'closeRetroDetailModal(); openTenantInvoiceModal(' + req.matchedInvoiceId + ')');
            } else {
                invBox.classList.add('hidden');
            }

            const pendingNotice = document.getElementById('rdm_pending_notice');
            if (status === 'Pending') {
                pendingNotice.classList.remove('hidden');
                document.getElementById('rdm_cancel_req_id').value = req.requestId || '';
            } else {
                pendingNotice.classList.add('hidden');
            }

            const modal = document.getElementById('retroDetailModal');
            if (modal) modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            if (window.lucide) lucide.createIcons();
        }

        function closeRetroDetailModal() {
            const modal = document.getElementById('retroDetailModal');
            if (modal) modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeTenantInvoiceModal();
                closeRetroDetailModal();
            }
        });

        // 🌟 Real-time Status Polling (ตรวจสอบสถานะคำขอย้อนหลังทุก 8 วินาที) 🌟
        let lastKnownPendingCount = <?php echo (int)$countPending; ?>;
        let lastKnownLatestStatus = '<?php echo $latestPendingRequest ? $latestPendingRequest['status'] : 'None'; ?>';

        function pollRetroStatus() {
            fetch('tenant_retro_invoices.php?check_status=1')
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) return;
                    
                    const newPendingCount = data.pendingCount || 0;
                    const latest = data.latest;
                    const newStatus = latest ? latest.status : 'None';

                    // หากจำนวนรอตรวจเปลี่ยน หรือสถานะของคำขอล่าสุดเปลี่ยน
                    if (newPendingCount !== lastKnownPendingCount || (latest && newStatus !== lastKnownLatestStatus)) {
                        const banner = document.getElementById('realtimeNoticeBanner');
                        const text = document.getElementById('realtimeNoticeText');
                        if (banner) {
                            if (newStatus === 'Approved') {
                                text.textContent = '🎉 เยี่ยมมาก! แอดมินได้อนุมัติคำขอบิลย้อนหลังของคุณแล้ว กดรีเฟรชเพื่อดูบิลหรือพิมพ์ใบเสร็จ';
                            } else if (newStatus === 'Rejected') {
                                text.textContent = 'ℹ️ แอดมินได้อัปเดตสถานะคำขอบิลย้อนหลังของคุณแล้ว กดรีเฟรชเพื่อดูรายละเอียด';
                            } else {
                                text.textContent = '🔔 มีการอัปเดตสถานะคำขอบิลย้อนหลังของคุณในระบบเรียลไทม์';
                            }
                            banner.classList.remove('hidden');
                        }
                    }
                })
                .catch(err => console.log('Polling check skipped', err));
        }

        // เริ่ม Polling ทุก 8 วินาที
        setInterval(pollRetroStatus, 8000);

        // Auto close alerts
        setTimeout(() => {
            const flash = document.getElementById('flashAlert');
            if (flash) flash.remove();
        }, 6000);
    </script>
</body>
</html>
