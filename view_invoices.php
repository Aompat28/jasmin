<?php

session_start();

require_once 'includes/db_connect.php';



// ตรวจสอบสิทธิ์ ต้องเป็น ลูกบ้าน (Tenant) เท่านั้น

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {

    header("Location: login.php");

    exit();

}



$tenantId = $_SESSION['user_id'];

$tenantName = $_SESSION['full_name'];

$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));



// ดึงข้อมูลห้องพักปัจจุบันสำหรับแสดงใน Header
$stmtHdrRoom = $conn->prepare("
    SELECT r.roomId, r.roomNumber 
    FROM Lease l 
    JOIN Room r ON l.roomId = r.roomId 
    WHERE l.tenantId = ? 
    ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC 
    LIMIT 1
");
$stmtHdrRoom->execute([$tenantId]);
$hdrRoomData = $stmtHdrRoom->fetch(PDO::FETCH_ASSOC);
$myRoomId = (int)($hdrRoomData['roomId'] ?? 0);
$myRoomNumber = $hdrRoomData['roomNumber'] ?? '';
$message = "";



// --- ระบบอัปโหลดสลิปโอนเงิน ---

if (isset($_POST['upload_slip'])) {

    $invoiceId = intval($_POST['invoice_id'] ?? 0);

    

    // หากไม่มี invoice_id หรือเป็น 0 ให้ค้นหาบิลที่ยังไม่ชำระของผู้เช่าคนนี้หรือห้องของผู้เช่า

    if ($invoiceId <= 0) {

        $stmtFind = $conn->prepare("

            SELECT i.invoiceId 

            FROM Invoices i

            JOIN Room r ON i.roomId = r.roomId

            LEFT JOIN Lease l ON r.roomId = l.roomId

            WHERE (i.tenantId = ? OR l.tenantId = ?) AND i.status IN ('Unpaid', 'Pending Approval')

            ORDER BY i.invoiceId DESC LIMIT 1

        ");

        $stmtFind->execute([$tenantId, $tenantId]);

        $invoiceId = (int)$stmtFind->fetchColumn();

    }



    if ($invoiceId > 0 && isset($_FILES['slip_image']) && $_FILES['slip_image']['error'] == 0) {

        $uploadDir = 'uploads/';

        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

        

        // ตั้งชื่อไฟล์ใหม่เพื่อป้องกันชื่อซ้ำ

        $fileName = time() . '_slip_' . basename($_FILES['slip_image']['name']);

        $targetFilePath = $uploadDir . $fileName;

        

        if (move_uploaded_file($_FILES['slip_image']['tmp_name'], $targetFilePath)) {

            // อัปเดตสถานะเป็น "รอตรวจสอบสลิป" และบันทึกที่อยู่ไฟล์รูป

            $stmt = $conn->prepare("UPDATE Invoices SET status = 'Pending Approval', slipImage = ? WHERE invoiceId = ?");

            if ($stmt->execute([$targetFilePath, $invoiceId])) {

                $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle' class='w-5 h-5'></i> อัปโหลดสลิปเรียบร้อยแล้ว! กรุณารอแอดมินตรวจสอบครับ</div>";

            }

        } else {

            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ</div>";

        }

    }

}



// --- ดึงข้อมูลบิลทั้งหมดของลูกบ้านคนนี้ ---

$stmt = $conn->prepare("

    SELECT i.*, r.roomNumber 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    WHERE i.tenantId = ? OR (i.roomId = ? AND ? > 0)
    ORDER BY i.invoiceId DESC
");
$stmt->execute([$tenantId, $myRoomId, $myRoomId]);
$rawInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🌟 1. ระบบตัดบิลซ้ำรอบเดือนเดียวกัน (Deduplication by billingMonth) 🌟
// ป้องกันการแสดงผลบิลซ้ำซ้อนในเดือนเดียวกัน โดยเลือกรายการที่มีสลิปหรือสถานะที่ต้องดำเนินการก่อน
$invoicesByMonth = [];
foreach ($rawInvoices as $inv) {
    $m = trim($inv['billingMonth']);
    if (!isset($invoicesByMonth[$m])) {
        $invoicesByMonth[$m] = $inv;
    } else {
        $existing = $invoicesByMonth[$m];
        // หากบิลเดิมมีสถานะ Paid แต่เจอบิลที่ยังต้องดำเนินการ (Unpaid / Pending Approval) ให้แสดงบิลที่ต้องทำรายการ
        if ($existing['status'] === 'Paid' && in_array($inv['status'], ['Unpaid', 'Pending Approval'])) {
            $invoicesByMonth[$m] = $inv;
        } elseif ($inv['status'] === $existing['status']) {
            if (!empty($inv['slipImage']) && empty($existing['slipImage'])) {
                $invoicesByMonth[$m] = $inv;
            } elseif ($inv['invoiceId'] > $existing['invoiceId']) {
                $invoicesByMonth[$m] = $inv;
            }
        }
    }
}
$dedupedInvoices = array_values($invoicesByMonth);

// 🌟 2. คัดแยก 3 หมวดหมู่ชัดเจน: รอชำระ / รอตรวจสอบสลิป / ประวัติการชำระเงิน 🌟
$unpaidInvoices = [];
$pendingApprovalInvoices = [];
$paymentHistory = [];

foreach ($dedupedInvoices as $inv) {
    if ($inv['status'] === 'Unpaid') {
        $unpaidInvoices[] = $inv;
    } elseif ($inv['status'] === 'Pending Approval') {
        $pendingApprovalInvoices[] = $inv;
    } elseif ($inv['status'] === 'Paid') {
        $paymentHistory[] = $inv;
    }
}
$pendingInvoices = array_merge($unpaidInvoices, $pendingApprovalInvoices);

// รับค่าแท็บเริ่มต้นจาก URL (?tab=current, ?tab=pending หรือ ?tab=history)
// หากไม่ได้ระบุมา: ตรวจสอบตามลำดับความสำคัญ (มีบิลรอชำระ -> current, มีบิลรอตรวจ -> pending, ไม่มีเลย -> history)
if (isset($_GET['tab']) && in_array($_GET['tab'], ['current', 'pending', 'history'], true)) {
    $activeTab = $_GET['tab'];
} else {
    if (!empty($unpaidInvoices)) {
        $activeTab = 'current';
    } elseif (!empty($pendingApprovalInvoices)) {
        $activeTab = 'pending';
    } else {
        $activeTab = 'history';
    }
}

// 🌟 รวบรวมข้อมูลใบแจ้งหนี้ทั้งหมดสำหรับ Tenant Invoice Detail Modal จอใหญ่ 🌟
$allTenantInvoicesData = [];
foreach ($rawInvoices as $inv) {
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



?>



<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>jasmin</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Kanit:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest"></script>

  <script>

    tailwind.config = {
      darkMode: ['selector', '[data-theme="dark"]'],
      theme: {
        extend: {
          fontFamily: {
            sans: ['Prompt', 'sans-serif'],
            display: ['Kanit', 'sans-serif'],
            mono: ['Plus Jakarta Sans', 'monospace']
          }
        }
      }
    }

  </script>

  <style>

    body { background-color: #FAF9F6; color: #1e293b; }

    .modal-enter { animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }

    .hide-scrollbar::-webkit-scrollbar { display: none; }

    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

  </style>

  <?php include_once 'includes/swal.php'; ?>

  <?php include_once 'includes/theme_head.php'; ?>
</head>

<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">



  <!-- 🌟 แท็บด้านซ้ายมือ สำหรับผู้เช่าพักอาศัย (Tenant Sidebar) 🌟 -->

  <?php include_once 'includes/tenant_sidebar.php'; ?>



  <!-- Top Navigation -->

  <!-- Top Navigation -->

  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100/80 shadow-sm">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between h-16">

        <div class="flex items-center space-x-3">

          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer mr-1" title="เปิด/ปิด แถบเมนูด้านซ้าย">

            <i data-lucide="menu" class="w-5 h-5"></i>

          </button>

          

          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-md">

            <i data-lucide="receipt" class="w-5 h-5"></i>

          </div>

          <div>
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ระบบจัดการค่าเช่าและใบเสร็จ</p>
          </div>

        </div>

        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

          <!-- ลิงก์โปรไฟล์ของผู้เช่า (ตรงรูปวงกลม + ชื่อและเลขห้อง) -->
          <a href="profile.php" class="flex items-center gap-2 p-1 sm:p-1.5 sm:px-2.5 hover:bg-rose-50/70 rounded-full transition border border-transparent hover:border-rose-200 group" title="แก้ไขโปรไฟล์">
            <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs border border-rose-200 shadow-2xs shrink-0 group-hover:scale-105 transition">
              <?php echo $initials; ?>
            </div>
            <div class="hidden sm:block text-left pr-1">
              <div class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition truncate max-w-[140px] sm:max-w-[180px]"><?php echo htmlspecialchars($headerTenantName ?? $sidebarTenantName ?? ($tenantName ?? 'ผู้เช่า')); ?></div>
              <div class="text-[10px] text-rose-600 font-bold"><?php echo !empty($headerRoomNumber ?? $sidebarRoomNumber ?? ($myRoomNumber ?? '')) ? "ห้อง " . htmlspecialchars($headerRoomNumber ?? $sidebarRoomNumber ?? ($myRoomNumber ?? '')) : 'ลูกบ้าน'; ?></div>
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



  <main class="flex-1 max-w-3xl w-full mx-auto px-4 sm:px-6 py-8">

    

    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="receipt" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ใบแจ้งหนี้ & ชำระเงิน</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="wallet" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ระบบบิลค่าเช่า</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ใบแจ้งหนี้และใบเสร็จรับเงิน
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            ตรวจสอบค่าใช้จ่ายรอบปัจจุบัน แนบสลิปชำระเงิน และเรียกดูประวัติใบเสร็จย้อนหลัง
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="tenant_dashboard.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="arrow-left" class="w-4 h-4 text-slate-500"></i>
            <span>กลับแดชบอร์ด</span>
          </a>
        </div>
      </div>
    </div>



    <div id="alert-container">

        <?php echo $message; ?>

    </div>



    <!-- 🌟 แท็บสลับหน้า: รอบบิลปัจจุบัน vs รอตรวจสอบสลิป vs ประวัติการชำระเงิน 🌟 -->
    <div class="mb-6 flex gap-2 border-b border-rose-100/80 pb-px overflow-x-auto hide-scrollbar">
        <!-- แท็บ 1: รอบบิลปัจจุบัน (เฉพาะที่ยังไม่ชำระ) -->
        <button onclick="switchTab('current')" id="tab-current" class="<?php echo ($activeTab === 'current') ? 'px-5 py-2.5 text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
            <i data-lucide="receipt" class="w-4 h-4"></i>
            <span>รอบบิลปัจจุบัน</span>
            <?php if(!empty($unpaidInvoices)): ?>
                <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-rose-500 text-white rounded-full">รอชำระ (<?php echo count($unpaidInvoices); ?>)</span>
            <?php else: ?>
                <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-emerald-100 text-emerald-700 rounded-full">ไม่มีค้างชำระ</span>
            <?php endif; ?>
        </button>

        <!-- แท็บ 2: รอตรวจสอบสลิป (เหมือนแถบตรวจสอบของแอดมิน) -->
        <button onclick="switchTab('pending')" id="tab-pending" class="<?php echo ($activeTab === 'pending') ? 'px-5 py-2.5 text-sm font-bold border-b-2 border-amber-500 text-amber-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
            <i data-lucide="clock" class="w-4 h-4 text-amber-500"></i>
            <span>รอตรวจสอบสลิป</span>
            <?php if(!empty($pendingApprovalInvoices)): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300 shadow-2xs">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <?php echo count($pendingApprovalInvoices); ?> รอตรวจ
                </span>
            <?php else: ?>
                <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-slate-100 text-slate-500 rounded-full">0</span>
            <?php endif; ?>
        </button>

        <!-- แท็บ 3: ประวัติการชำระเงิน (ที่ชำระเสร็จสิ้นแล้วทั้งหมด) -->
        <button onclick="switchTab('history')" id="tab-history" class="<?php echo ($activeTab === 'history') ? 'px-5 py-2.5 text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
            <i data-lucide="history" class="w-4 h-4"></i>
            <span>ประวัติการชำระเงิน</span>
            <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-slate-100 text-slate-600 rounded-full"><?php echo count($paymentHistory); ?></span>
        </button>
    </div>



    <!-- ================= แท็บ 1: รอบบิลปัจจุบัน (Current Bill - Unpaid) ================= -->
    <div id="content-current" class="<?php echo ($activeTab === 'current') ? 'space-y-4' : 'space-y-4 hidden'; ?>">
        <?php if(!empty($unpaidInvoices)): ?>
            <?php foreach($unpaidInvoices as $currentInvoice): 
                $waterTotal = ($currentInvoice['waterCurr'] - $currentInvoice['waterPrev']) * $currentInvoice['waterUnitRate'];
                $elecTotal = ($currentInvoice['electricCurr'] - $currentInvoice['electricPrev']) * $currentInvoice['electricUnitRate'];
            ?>
        <!-- การ์ดรอบบิลปัจจุบัน (รอชำระ) -->
        <div class="bg-white rounded-3xl border border-rose-100/80 shadow-sm overflow-hidden transition hover:shadow-md">
            <!-- Header การ์ด -->
            <div class="px-6 py-5 border-b border-rose-100/60 flex flex-wrap justify-between items-center gap-3 bg-rose-50/30">
                <div class="flex items-center gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center font-bold shadow-2xs">
                        <i data-lucide="calendar-check" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-display font-bold text-slate-800 text-lg">รอบบิล: <?php echo htmlspecialchars($currentInvoice['billingMonth']); ?></h3>
                            <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-lg bg-slate-100 text-slate-600 border border-rose-100/80">
                                ห้อง <?php echo htmlspecialchars($currentInvoice['roomNumber']); ?>
                            </span>
                        </div>
                        <p class="text-xs font-mono text-slate-400 mt-0.5">รหัสอ้างอิง: INV-<?php echo str_pad($currentInvoice['invoiceId'], 5, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
                <div class="px-3.5 py-1.5 rounded-full border text-xs font-bold flex items-center gap-1.5 bg-rose-100 text-rose-700 border-rose-200">
                    <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i> รอชำระเงิน
                </div>
            </div>

            <div class="p-6">
                <!-- แบนเนอร์แจ้งเตือนสถานะ -->
                <div class="mb-5 p-4 bg-rose-50 border border-rose-200 rounded-2xl flex items-center justify-between text-xs text-rose-800 font-medium">
                    <span class="flex items-center gap-2">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 shrink-0"></i>
                        <span>มีรายการรอชำระเงิน กรุณาโอนเงินและแนบสลิปเพื่อยืนยัน</span>
                    </span>
                    <span class="font-bold font-mono text-rose-900 bg-white px-2 py-0.5 rounded border border-rose-200">Unpaid</span>
                </div>

                <!-- แจกแจงค่าใช้จ่ายแบบการ์ด 4 ช่อง -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 py-3 text-xs text-slate-600">
                    <div class="bg-rose-50/40 p-3 rounded-2xl border border-rose-100/70">
                        <span class="text-slate-400 block text-[11px]">ค่าเช่าห้องพัก</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($currentInvoice['rentPrice'], 2); ?></span>
                    </div>
                    <div class="bg-rose-50/40 p-3 rounded-2xl border border-rose-100/70">
                        <span class="text-slate-400 block text-[11px]">ค่าน้ำ (<?php echo max(0, $currentInvoice['waterCurr'] - $currentInvoice['waterPrev']); ?> หน่วย)</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($waterTotal, 2); ?></span>
                    </div>
                    <div class="bg-rose-50/40 p-3 rounded-2xl border border-rose-100/70">
                        <span class="text-slate-400 block text-[11px]">ค่าไฟ (<?php echo max(0, $currentInvoice['electricCurr'] - $currentInvoice['electricPrev']); ?> หน่วย)</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($elecTotal, 2); ?></span>
                    </div>
                    <div class="bg-rose-50/40 p-3 rounded-2xl border border-rose-100/70">
                        <span class="text-slate-400 block text-[11px]">ค่าใช้จ่ายอื่นๆ</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($currentInvoice['otherFee'], 2); ?></span>
                    </div>
                    <?php if(!empty($currentInvoice['otherFeeNote'])): ?>
                    <div class="col-span-2 sm:col-span-4 bg-slate-50 p-2.5 rounded-xl border border-slate-200/60 text-slate-600 text-[11px] flex items-center gap-1.5">
                        <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>
                        <span>หมายเหตุค่าใช้จ่ายอื่น: <strong><?php echo htmlspecialchars($currentInvoice['otherFeeNote']); ?></strong></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ยอดรวมทั้งสิ้น -->
                <div class="mt-5 p-4 rounded-2xl bg-rose-50/30 flex items-center justify-between border border-rose-100/80">
                    <div>
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">ยอดสุทธิที่ต้องชำระ</span>
                        <span class="text-xs text-slate-500">รวมค่าเช่าและสาธารณูปโภค</span>
                    </div>
                    <span class="text-2xl sm:text-3xl font-display font-bold text-rose-600">฿<?php echo number_format($currentInvoice['totalAmount'], 2); ?></span>
                </div>

                <!-- กล่องเลขบัญชี & QR Code ถ้ายังไม่ชำระ -->
                <div class="mt-5 p-4 bg-rose-50/60 rounded-2xl border border-blue-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="w-full sm:w-auto">
                        <div class="text-xs font-semibold text-slate-500 mb-1">ช่องทางโอนเงิน:</div>
                        <div class="font-bold text-base text-slate-800 font-mono tracking-wide">109-3-21573-3</div>
                        <div class="text-xs text-slate-500">บจก. หอพักจัสมิน (ธ.กสิกรไทย)</div>
                        <button type="button" onclick="copyAccountNumber('1093215733', this)" class="mt-2 inline-flex items-center gap-1.5 px-3 py-1 bg-white hover:bg-amber-50 text-amber-700 border border-amber-200 rounded-xl text-xs font-bold transition shadow-2xs cursor-pointer">
                            <i data-lucide="copy" class="w-3.5 h-3.5"></i> <span>คัดลอกเลขบัญชี</span>
                        </button>
                    </div>
                    <div class="shrink-0 text-center flex flex-col items-center">
                        <img src="pay_me.png" alt="QR Code" onclick="openSlipPreview('pay_me.png')" class="w-20 h-20 object-contain border border-rose-100/80 rounded-xl p-1 bg-white shadow-xs cursor-pointer hover:scale-105 transition" title="คลิกดู QR Code ขนาดใหญ่">
                        <span class="text-[10px] text-rose-600 font-bold mt-1 cursor-pointer" onclick="openSlipPreview('pay_me.png')">แตะขยาย QR Code</span>
                    </div>
                </div>

                <!-- ปุ่ม Action สำหรับรอบบิลปัจจุบัน -->
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" onclick="openTenantInvoiceModal(<?php echo $currentInvoice['invoiceId']; ?>)" class="px-4 py-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-xl text-sm font-bold transition flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs">
                        <i data-lucide="eye" class="w-4 h-4 text-emerald-600"></i> ดูรายละเอียดบิล
                    </button>
                    <button onclick="openUploadModal(<?php echo $currentInvoice['invoiceId']; ?>, '<?php echo htmlspecialchars($currentInvoice['billingMonth']); ?>', <?php echo $currentInvoice['totalAmount']; ?>)" class="flex-1 py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 cursor-pointer">
                        <i data-lucide="upload-cloud" class="w-4 h-4"></i> แจ้งโอนเงิน / อัปโหลดสลิป
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <!-- กรณีไม่มีรอบบิลค้างชำระ -->
        <div class="text-center py-12 sm:py-16 px-6 bg-white rounded-3xl border border-emerald-100 shadow-sm relative overflow-hidden">
            <div class="w-20 h-20 bg-emerald-50 text-emerald-600 rounded-3xl flex items-center justify-center mx-auto mb-4 shadow-sm border border-emerald-200/60">
                <i data-lucide="shield-check" class="w-10 h-10 text-emerald-600"></i>
            </div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 mb-2">
                <i data-lucide="check" class="w-3.5 h-3.5"></i> ไม่มีรายการค้างชำระ
            </div>
            <h3 class="text-xl sm:text-2xl font-bold text-slate-800 font-display">ไม่มีรอบบิลค้างชำระในปัจจุบัน</h3>
            <p class="text-xs sm:text-sm text-slate-500 max-w-md mx-auto mt-2 leading-relaxed">
                ยอดเยี่ยม! คุณไม่มีบิลค้างชำระในขณะนี้ เมื่อมีบิลรอบใหม่หรือต้องการดูประวัติย้อนหลังสามารถเลือกแถบด้านบนได้
            </p>
            <?php if(!empty($pendingApprovalInvoices)): ?>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <button type="button" onclick="switchTab('pending')" class="px-6 py-3 bg-amber-500 hover:bg-amber-600 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-md hover:shadow-lg transition flex items-center gap-2 cursor-pointer">
                    <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                    <i data-lucide="clock" class="w-4 h-4"></i>
                    <span>มีบิลรอแอดมินตรวจสอบสลิป <?php echo count($pendingApprovalInvoices); ?> รายการ</span>
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </div>
            <?php elseif(count($paymentHistory) > 0): ?>
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                <button type="button" onclick="switchTab('history')" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-md hover:shadow-lg transition flex items-center gap-2 cursor-pointer">
                    <i data-lucide="history" class="w-4 h-4"></i>
                    <span>ดูประวัติและใบเสร็จรับเงินทั้งหมด (<?php echo count($paymentHistory); ?> รอบบิล)</span>
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ================= แท็บ 2: รอตรวจสอบสลิป (Pending Approval - แถบตรวจเหมือนแอดมิน) ================= -->
    <div id="content-pending" class="<?php echo ($activeTab === 'pending') ? 'space-y-4' : 'space-y-4 hidden'; ?>">
        <?php if(!empty($pendingApprovalInvoices)): ?>
            <?php foreach($pendingApprovalInvoices as $currentInvoice): 
                $waterTotal = ($currentInvoice['waterCurr'] - $currentInvoice['waterPrev']) * $currentInvoice['waterUnitRate'];
                $elecTotal = ($currentInvoice['electricCurr'] - $currentInvoice['electricPrev']) * $currentInvoice['electricUnitRate'];
            ?>
        <div class="bg-white rounded-3xl border border-amber-200/90 shadow-sm overflow-hidden transition hover:shadow-md">
            <div class="px-6 py-5 border-b border-amber-100 flex flex-wrap justify-between items-center gap-3 bg-amber-50/40">
                <div class="flex items-center gap-3.5">
                    <div class="w-11 h-11 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center font-bold shadow-2xs">
                        <i data-lucide="clock" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="font-display font-bold text-slate-800 text-lg">รอบบิล: <?php echo htmlspecialchars($currentInvoice['billingMonth']); ?></h3>
                            <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-lg bg-amber-100 text-amber-800 border border-amber-200">
                                ห้อง <?php echo htmlspecialchars($currentInvoice['roomNumber']); ?>
                            </span>
                        </div>
                        <p class="text-xs font-mono text-slate-400 mt-0.5">รหัสอ้างอิง: INV-<?php echo str_pad($currentInvoice['invoiceId'], 5, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
                <div class="px-3.5 py-1.5 rounded-full border text-xs font-bold flex items-center gap-1.5 bg-amber-100 text-amber-800 border-amber-300 shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    <i data-lucide="clock" class="w-3.5 h-3.5"></i> รอแอดมินตรวจสลิป
                </div>
            </div>

            <div class="p-6">
                <!-- แบนเนอร์สถานะกำลังตรวจสอบสลิป -->
                <div class="mb-5 p-4 bg-amber-50 border border-amber-200 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs text-amber-900 font-medium">
                    <div class="flex items-center gap-2.5">
                        <div class="w-6 h-6 rounded-full bg-amber-200 text-amber-800 flex items-center justify-center shrink-0">
                            <i data-lucide="clock" class="w-3.5 h-3.5"></i>
                        </div>
                        <span>แนบสลิปเรียบร้อยแล้ว อยู่ระหว่างรอนิติบุคคลตรวจสอบความถูกต้อง เมื่ออนุมัติแล้วบิลจะย้ายไปที่ส่วนประวัติโดยอัตโนมัติ</span>
                    </div>
                    <span class="self-start sm:self-center font-bold font-mono text-amber-800 bg-white px-2.5 py-1 rounded-lg border border-amber-200 shrink-0">
                        Pending Approval
                    </span>
                </div>

                <!-- ค่าใช้จ่ายแจกแจง -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 py-3 text-xs text-slate-600">
                    <div class="bg-amber-50/30 p-3 rounded-2xl border border-amber-100">
                        <span class="text-slate-400 block text-[11px]">ค่าเช่าห้องพัก</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($currentInvoice['rentPrice'], 2); ?></span>
                    </div>
                    <div class="bg-amber-50/30 p-3 rounded-2xl border border-amber-100">
                        <span class="text-slate-400 block text-[11px]">ค่าน้ำ (<?php echo max(0, $currentInvoice['waterCurr'] - $currentInvoice['waterPrev']); ?> หน่วย)</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($waterTotal, 2); ?></span>
                    </div>
                    <div class="bg-amber-50/30 p-3 rounded-2xl border border-amber-100">
                        <span class="text-slate-400 block text-[11px]">ค่าไฟ (<?php echo max(0, $currentInvoice['electricCurr'] - $currentInvoice['electricPrev']); ?> หน่วย)</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($elecTotal, 2); ?></span>
                    </div>
                    <div class="bg-amber-50/30 p-3 rounded-2xl border border-amber-100">
                        <span class="text-slate-400 block text-[11px]">ค่าใช้จ่ายอื่นๆ</span>
                        <span class="font-bold text-slate-800 text-sm">฿<?php echo number_format($currentInvoice['otherFee'], 2); ?></span>
                    </div>
                    <?php if(!empty($currentInvoice['otherFeeNote'])): ?>
                    <div class="col-span-2 sm:col-span-4 bg-slate-50 p-2.5 rounded-xl border border-slate-200/60 text-slate-600 text-[11px] flex items-center gap-1.5">
                        <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>
                        <span>หมายเหตุค่าใช้จ่ายอื่น: <strong><?php echo htmlspecialchars($currentInvoice['otherFeeNote']); ?></strong></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ยอดรวมทั้งสิ้น -->
                <div class="mt-4 p-4 rounded-2xl bg-amber-50/60 flex items-center justify-between border border-amber-200">
                    <div>
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block">ยอดที่แนบสลิปชำระ</span>
                        <span class="text-xs text-slate-500">รวมค่าเช่าและสาธารณูปโภค</span>
                    </div>
                    <span class="text-2xl sm:text-3xl font-display font-bold text-amber-700">฿<?php echo number_format($currentInvoice['totalAmount'], 2); ?></span>
                </div>

                <!-- ปุ่ม Action สำหรับแท็บรอตรวจสอบ -->
                <div class="mt-6 flex flex-wrap gap-3">
                    <button type="button" onclick="openTenantInvoiceModal(<?php echo $currentInvoice['invoiceId']; ?>)" class="px-4 py-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-xl text-sm font-bold transition flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs">
                        <i data-lucide="eye" class="w-4 h-4 text-emerald-600"></i> ดูรายละเอียดบิล
                    </button>
                    <?php if(!empty($currentInvoice['slipImage'])): ?>
                        <button type="button" onclick="openSlipPreview('<?php echo htmlspecialchars($currentInvoice['slipImage']); ?>')" class="flex-1 py-3 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-sm font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 cursor-pointer">
                            <i data-lucide="image" class="w-4 h-4"></i> ดูสลิปที่แนบ
                        </button>
                    <?php endif; ?>
                    <button type="button" onclick="openUploadModal(<?php echo $currentInvoice['invoiceId']; ?>, '<?php echo htmlspecialchars($currentInvoice['billingMonth']); ?>', <?php echo $currentInvoice['totalAmount']; ?>)" class="py-3 px-4 bg-white hover:bg-amber-50 text-amber-700 border border-amber-300 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 cursor-pointer" title="แก้ไข / แนบสลิปใหม่">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> แก้ไข/ส่งสลิปใหม่
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <!-- เมื่อไม่มีบิลที่รอตรวจสอบสลิป -->
        <div class="text-center py-12 sm:py-16 px-6 bg-white rounded-3xl border border-amber-100 shadow-sm relative overflow-hidden">
            <div class="w-20 h-20 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-amber-200/70 shadow-sm">
                <i data-lucide="check-check" class="w-10 h-10 text-amber-600"></i>
            </div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 mb-2">
                <i data-lucide="clock" class="w-3.5 h-3.5"></i> ไม่มีรายการรอตรวจ
            </div>
            <h3 class="text-xl sm:text-2xl font-bold text-slate-800 font-display">ไม่มีรายการที่รอตรวจสอบสลิป</h3>
            <p class="text-xs sm:text-sm text-slate-500 max-w-md mx-auto mt-2 leading-relaxed">
                เมื่อคุณโอนเงินและแนบสลิปในรอบบิลปัจจุบันแล้ว รายการจะเข้ามาแสดงที่แถบนี้เพื่อรอนิติบุคคลตรวจสอบความถูกต้อง
            </p>
        </div>
        <?php endif; ?>
    </div>



    <!-- ================= แท็บ 2: ประวัติการชำระเงิน (Payment History) ================= -->
    <div id="content-history" class="<?php echo ($activeTab === 'history') ? 'space-y-4' : 'space-y-4 hidden'; ?>">
        <?php if(count($paymentHistory) > 0): ?>
            <!-- Filter & Search Bar สำหรับดูบิลของหลายๆ เดือน -->
            <div class="bg-white rounded-2xl border border-rose-100/80 p-3 sm:p-4 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="relative flex-1 max-w-md">
                    <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                    <input type="text" id="historySearchInput" oninput="filterHistoryBills()" placeholder="ค้นหารอบเดือน, ปี หรือเลขที่ใบเสร็จ..." class="w-full pl-10 pr-4 py-2 bg-slate-50 hover:bg-white focus:bg-white rounded-xl border border-slate-200 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-rose-400 focus:border-rose-400 transition placeholder:text-slate-400">
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="text-xs text-emerald-700 font-bold bg-emerald-50 px-3 py-1.5 rounded-full border border-emerald-200 flex items-center gap-1.5 shadow-2xs">
                        <i data-lucide="receipt" class="w-3.5 h-3.5 text-emerald-600"></i>
                        <span id="historyCountText">ทั้งหมด <?php echo count($paymentHistory); ?> รายการ</span>
                    </span>
                </div>
            </div>

            <!-- กล่องแจ้งเมื่อค้นหาไม่พบ -->
            <div id="historySearchEmpty" class="hidden text-center py-10 bg-white rounded-3xl border border-rose-100/80 shadow-sm">
                <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-2 text-slate-400">
                    <i data-lucide="search-x" class="w-6 h-6"></i>
                </div>
                <p class="text-sm font-bold text-slate-700">ไม่พบรายการบิลตามคำค้นหา</p>
                <p class="text-xs text-slate-400 mt-0.5">ลองค้นหาด้วยชื่อเดือน เช่น "พฤศจิกายน" หรือปี พ.ศ./ค.ศ.</p>
            </div>

            <div id="historyBillsList" class="space-y-4">
            <?php foreach($paymentHistory as $hist): 
                $hWater = ($hist['waterCurr'] - $hist['waterPrev']) * $hist['waterUnitRate'];
                $hElec = ($hist['electricCurr'] - $hist['electricPrev']) * $hist['electricUnitRate'];
            ?>
            <div onclick="openTenantInvoiceModal(<?php echo $hist['invoiceId']; ?>)" class="history-bill-card bg-white rounded-3xl border border-rose-100/80 shadow-sm p-5 sm:p-6 hover:shadow-md hover:border-emerald-300 transition cursor-pointer group" data-search="<?php echo htmlspecialchars($hist['billingMonth'] . ' ' . $hist['invoiceId'] . ' ' . ($hist['roomNumber'] ?? '') . ' REC-' . str_pad($hist['invoiceId'], 5, '0', STR_PAD_LEFT)); ?>" title="คลิกเพื่อดูรายละเอียดบิลแบบเต็ม">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-rose-100/60">
                    <div class="flex items-center gap-3.5">
                        <div class="w-11 h-11 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold shadow-2xs shrink-0 group-hover:scale-105 transition">
                            <i data-lucide="file-check-2" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-display font-bold text-slate-800 text-base sm:text-lg group-hover:text-emerald-700 transition">รอบบิล: <?php echo htmlspecialchars($hist['billingMonth']); ?></h3>
                                <span class="text-[10px] font-bold px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200 flex items-center gap-1">
                                    <i data-lucide="check" class="w-3 h-3"></i> ชำระเงินแล้ว
                                </span>
                            </div>
                            <div class="flex items-center gap-2 flex-wrap text-xs text-slate-400 mt-0.5 font-mono">
                                <span>ใบเสร็จเลขที่: REC-<?php echo str_pad($hist['invoiceId'], 5, '0', STR_PAD_LEFT); ?></span>
                                <span>•</span>
                                <span>ห้อง <?php echo htmlspecialchars($hist['roomNumber'] ?? ($myRoomNumber ?: '-')); ?></span>
                                <?php if(!empty($hist['paidAt'])): ?>
                                    <span>•</span>
                                    <span class="text-emerald-600">ชำระเมื่อ: <?php echo date('d/m/Y H:i', strtotime($hist['paidAt'])); ?> น.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="text-left sm:text-right">
                        <div class="text-[11px] text-slate-400 font-medium uppercase">ยอดชำระสุทธิ</div>
                        <div class="text-xl sm:text-2xl font-display font-bold text-emerald-700 font-mono">฿<?php echo number_format($hist['totalAmount'], 2); ?></div>
                    </div>
                </div>

                <!-- รายละเอียดค่าใช้จ่ายย่อส่วน -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 py-3 text-xs text-slate-600">
                    <div class="bg-rose-50/30 p-2.5 rounded-xl border border-rose-100/60">
                        <span class="text-slate-400 block text-[11px]">ค่าเช่าห้องพัก</span>
                        <span class="font-bold text-slate-700">฿<?php echo number_format($hist['rentPrice'], 2); ?></span>
                    </div>
                    <div class="bg-rose-50/30 p-2.5 rounded-xl border border-rose-100/60">
                        <span class="text-slate-400 block text-[11px]">ค่าน้ำ (<?php echo max(0, $hist['waterCurr'] - $hist['waterPrev']); ?> หน่วย)</span>
                        <span class="font-bold text-slate-700">฿<?php echo number_format($hWater, 2); ?></span>
                    </div>
                    <div class="bg-rose-50/30 p-2.5 rounded-xl border border-rose-100/60">
                        <span class="text-slate-400 block text-[11px]">ค่าไฟ (<?php echo max(0, $hist['electricCurr'] - $hist['electricPrev']); ?> หน่วย)</span>
                        <span class="font-bold text-slate-700">฿<?php echo number_format($hElec, 2); ?></span>
                    </div>
                    <div class="bg-rose-50/30 p-2.5 rounded-xl border border-rose-100/60">
                        <span class="text-slate-400 block text-[11px]">ค่าใช้จ่ายอื่นๆ</span>
                        <span class="font-bold text-slate-700">฿<?php echo number_format($hist['otherFee'], 2); ?></span>
                    </div>
                </div>

                <!-- ปุ่มดาวน์โหลดใบเสร็จ & ดูสลิป -->
                <div class="pt-3 border-t border-rose-100/60 flex flex-wrap gap-2.5 items-center justify-between">
                    <div class="text-[11px] text-slate-400 flex items-center gap-1">
                        <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400"></i>
                        <span>คลิกที่การ์ดเพื่อดูรายละเอียดบิลแบบเต็ม</span>
                    </div>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button type="button" onclick="event.stopPropagation(); openTenantInvoiceModal(<?php echo $hist['invoiceId']; ?>)" class="px-3.5 py-2 bg-white hover:bg-emerald-50 text-slate-700 hover:text-emerald-700 border border-slate-200 hover:border-emerald-300 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs">
                            <i data-lucide="eye" class="w-3.5 h-3.5 text-emerald-600"></i> ดูรายละเอียดบิล
                        </button>
                        <?php if(!empty($hist['slipImage'])): ?>
                            <button type="button" onclick="event.stopPropagation(); openSlipPreview('<?php echo htmlspecialchars($hist['slipImage']); ?>')" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer">
                                <i data-lucide="image" class="w-3.5 h-3.5"></i> ดูหลักฐานสลิป
                            </button>
                        <?php endif; ?>
                        <a href="print_receipt.php?id=<?php echo $hist['invoiceId']; ?>" target="_blank" onclick="event.stopPropagation()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs">
                            <i data-lucide="file-check" class="w-3.5 h-3.5"></i> ดู / ดาวน์โหลดใบเสร็จ (PDF)
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-16 bg-white rounded-3xl border border-rose-100/80 shadow-sm">
                <div class="w-16 h-16 bg-rose-50/30 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300">
                    <i data-lucide="history" class="w-8 h-8"></i>
                </div>
                <h3 class="text-lg font-bold text-slate-700 font-display">ยังไม่มีประวัติการชำระเงิน</h3>
                <p class="text-sm text-slate-500 mt-1">เมื่อท่านชำระเงินและนิติบุคคลอนุมัติแล้ว ประวัติการชำระเงินและใบเสร็จจะแสดงที่นี่ครับ</p>
            </div>
        <?php endif; ?>
    </div>



  </main>



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

    <!-- 🌟 MODAL: อัปโหลดสลิปโอนเงิน 🌟 -->

  <div id="uploadModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">

      <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden modal-enter">

          <div class="p-5 border-b border-rose-100/60 flex justify-between items-center bg-rose-50/30/50">

              <h3 class="font-display font-bold text-slate-800 text-lg flex items-center gap-2">

                  <i data-lucide="upload" class="w-5 h-5 text-rose-600"></i> แจ้งชำระเงิน

              </h3>

              <button type="button" onclick="closeUploadModal()" class="text-slate-400 hover:text-rose-500 transition"><i data-lucide="x" class="w-5 h-5"></i></button>

          </div>

          

          <form action="" method="POST" enctype="multipart/form-data" class="p-5">

              <input type="hidden" name="invoice_id" id="modal_invoice_id">

              

              <div class="mb-4 text-center p-4 bg-rose-50 rounded-xl border border-blue-100">

                  <div class="text-xs text-rose-600 font-semibold mb-1">ยอดทั้งหมด (รอบ <span id="modal_month"></span>)</div>

                  <div class="text-3xl font-display font-bold text-rose-700">฿<span id="modal_amount"></span></div>

              </div>



              <!-- แสดงเลขที่บัญชี และรูป QR Code -->

              <div class="mb-5 p-4 bg-rose-50/30 rounded-xl border border-rose-100/80 flex items-center justify-between gap-3">

                  <div>

                      <div class="text-xs font-semibold text-slate-500 mb-1">บัญชีรับโอนเงิน:</div>

                      <div>

                          <div class="font-bold text-base text-slate-800 tracking-wide font-mono">109-3-21573-3</div>

                          <div class="text-[11px] text-slate-500 mt-0.5">บจก. หอพักจัสมิน (ธ.กสิกรไทย)</div>

                          <button type="button" onclick="copyAccountNumber('1093215733', this)" class="mt-2 inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 rounded-lg text-xs font-bold transition">

                              <i data-lucide="copy" class="w-3.5 h-3.5"></i> <span>คัดลอกเลขบัญชี</span>

                          </button>

                      </div>

                  </div>

                  

                  <div class="shrink-0 text-center border-l border-rose-100/80 pl-4 ml-1">

                      <img src="pay_me.png" alt="QR Code" onclick="openSlipPreview('pay_me.png')" class="w-24 h-24 object-contain border border-rose-100/80 rounded-lg p-1 bg-white mx-auto shadow-sm cursor-pointer hover:shadow-md transition transform hover:-translate-y-0.5" title="คลิกเพื่อขยาย QR Code">

                      <div class="text-[10px] text-rose-600 mt-1.5 font-bold flex items-center justify-center gap-1 cursor-pointer" onclick="openSlipPreview('pay_me.png')">

                          <i data-lucide="zoom-in" class="w-3 h-3"></i> แตะเพื่อขยาย

                      </div>

                  </div>

              </div>



              <div class="mb-6">

                  <label class="block text-sm font-semibold text-slate-700 mb-2">แนบสลิปโอนเงิน <span class="text-rose-500">*</span></label>

                  <input type="file" name="slip_image" accept="image/*" required class="w-full text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-rose-50 file:text-rose-700 hover:file:bg-blue-100 bg-rose-50/30 border border-rose-100/80 rounded-xl">

              </div>



              <button type="submit" name="upload_slip" class="w-full py-3 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-bold shadow-md transition">

                  ยืนยันการแจ้งโอนเงิน

              </button>

          </form>

      </div>

  </div>



  <!-- 🌟 MODAL: ดูรูปภาพขยาย (ใช้ได้ทั้งสลิป และ QR Code) 🌟 -->

  <div id="previewModal" class="hidden fixed inset-0 z-[60] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4" onclick="closeSlipPreview()">

      <div class="relative max-w-md w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">

          <button type="button" onclick="closeSlipPreview()" class="absolute -top-12 right-0 text-white/70 hover:text-rose-400 p-2 transition flex items-center gap-2 font-bold text-sm">

              ปิดรูปภาพ <i data-lucide="x-circle" class="w-6 h-6"></i>

          </button>

          <img id="previewImage" src="" alt="Preview" class="max-h-[80vh] max-w-full rounded-2xl shadow-2xl object-contain bg-white p-2">

      </div>

  </div>



  <script>

    lucide.createIcons();



    // ซ่อน Alert อัตโนมัติ

    document.addEventListener("DOMContentLoaded", function() {

        const alerts = document.querySelectorAll(".alert-box");

        alerts.forEach(function(alert) {

            setTimeout(function() {

                alert.style.opacity = "0";

                setTimeout(function() { alert.remove(); }, 500);

            }, 3500);

        });

    });



    // ระบบสลับแท็บ (รอบบิลปัจจุบัน / รอตรวจสอบสลิป / ประวัติการชำระเงิน)
    function switchTab(tabId) {
        ['current', 'pending', 'history'].forEach(function(t) {
            var el = document.getElementById('content-' + t);
            if (el) el.classList.add('hidden');
            var btn = document.getElementById('tab-' + t);
            if (btn) {
                btn.className = "px-5 py-2.5 text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer";
            }
        });

        var targetContent = document.getElementById('content-' + tabId);
        if (targetContent) targetContent.classList.remove('hidden');

        var targetBtn = document.getElementById('tab-' + tabId);
        if (targetBtn) {
            if (tabId === 'pending') {
                targetBtn.className = "px-5 py-2.5 text-sm font-bold border-b-2 border-amber-500 text-amber-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer";
            } else {
                targetBtn.className = "px-5 py-2.5 text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer";
            }
        }
        if (window.lucide) lucide.createIcons();
    }

    // ฟังก์ชันค้นหาและกรองบิลในประวัติหลายๆ เดือน
    function filterHistoryBills() {
        const input = document.getElementById('historySearchInput');
        if (!input) return;
        const query = (input.value || '').toLowerCase().trim();
        const cards = document.querySelectorAll('.history-bill-card');
        let visibleCount = 0;
        cards.forEach(card => {
            const text = (card.getAttribute('data-search') || card.textContent).toLowerCase();
            if (!query || text.includes(query)) {
                card.classList.remove('hidden');
                visibleCount++;
            } else {
                card.classList.add('hidden');
            }
        });
        const emptyEl = document.getElementById('historySearchEmpty');
        if (emptyEl) {
            if (visibleCount === 0 && cards.length > 0) {
                emptyEl.classList.remove('hidden');
            } else {
                emptyEl.classList.add('hidden');
            }
        }
        const countText = document.getElementById('historyCountText');
        if (countText) {
            countText.textContent = query ? `พบ ${visibleCount} จาก ${cards.length} รายการ` : `ทั้งหมด ${cards.length} รายการ`;
        }
    }



    // เปิด Modal อัปโหลดสลิป

    function openUploadModal(id, month, amount) {

        document.getElementById('modal_invoice_id').value = id;

        document.getElementById('modal_month').textContent = month;

        

        let formattedAmount = parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        document.getElementById('modal_amount').textContent = formattedAmount;

        

        document.getElementById('uploadModal').classList.remove('hidden');

    }



    function closeUploadModal() {

        document.getElementById('uploadModal').classList.add('hidden');

    }



    // เปิด Modal ขยายรูปภาพ (ดู QR Code หรือ สลิปเก่า)

    function openSlipPreview(url) {

        document.getElementById('previewImage').src = url;

        document.getElementById('previewModal').classList.remove('hidden');

    }

    

    function closeSlipPreview() {

        document.getElementById('previewModal').classList.add('hidden');

        document.getElementById('previewImage').src = ''; 

    }



    // ฟังก์ชันคัดลอกเลขบัญชี

    function copyAccountNumber(accountNumber, btnElement) {

        navigator.clipboard.writeText(accountNumber).then(() => {

            const span = btnElement.querySelector('span');

            const originalText = span.textContent;

            span.textContent = "คัดลอกสำเร็จ!";

            btnElement.classList.remove('bg-amber-50', 'text-amber-700', 'border-amber-200');

            btnElement.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');

            setTimeout(() => {

                span.textContent = originalText;

                btnElement.classList.remove('bg-emerald-50', 'text-emerald-700', 'border-emerald-200');

                btnElement.classList.add('bg-amber-50', 'text-amber-700', 'border-amber-200');

            }, 2000);

        });

    }



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
                <a href="print_receipt.php?id=${inv.invoiceId}" target="_blank" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
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
                <button type="button" onclick="closeTenantInvoiceModal(); openUploadModal(${inv.invoiceId}, '${inv.billingMonth}', ${inv.totalAmount})" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i> ส่งสลิปใหม่
                </button>
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
                <button type="button" onclick="closeTenantInvoiceModal(); openUploadModal(${inv.invoiceId}, '${inv.billingMonth}', ${inv.totalAmount})" class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-sm transition flex items-center gap-1.5 cursor-pointer">
                    <i data-lucide="upload" class="w-4 h-4"></i> ชำระเงิน / แนบสลิป
                </button>
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
                <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-emerald-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center max-h-56" onclick="openSlipPreview('${inv.slipImage}')">
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

    document.addEventListener("DOMContentLoaded", function() {
        const params = new URLSearchParams(window.location.search);
        const invId = params.get('inv_id') || params.get('id');
        if (invId) {
            setTimeout(() => {
                openTenantInvoiceModal(invId);
            }, 300);
        }
    });

    // ปิด Modal เมื่อกด Escape

    document.addEventListener('keydown', function(e) {

        if (e.key === 'Escape') {

            closeUploadModal();

            closeSlipPreview();

            closeTenantInvoiceModal();

        }

    });

  </script>

</body>

</html>

