<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น แอดมิน (Admin) เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$message = "";

// --- 1. ระบบอนุมัติคำขอเพิ่มรถ & สิทธิ์ที่จอดรถ ---
if (isset($_POST['approve_parking'])) {
    $reqId = intval($_POST['request_id']);
    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'อนุมัติสิทธิ์เข้าจอดรถเรียบร้อยแล้ว';

    try {
        $stmt = $conn->prepare("
            UPDATE parking_requests 
            SET status = 'Approved', approvedAt = NOW(), adminRemark = ?, cancelReason = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$adminRemark, $reqId]);

        // ซิงค์ข้อมูลสิทธิ์ที่จอดรถเข้าสัญญาเช่า (Lease) ของลูกบ้านอัตโนมัติ
        try {
            $stmtSync = $conn->prepare("
                UPDATE Lease l
                JOIN parking_requests pr ON l.tenantId = pr.tenantId
                SET l.parkingPlan = 'Monthly',
                    l.parkingFee = 100.00,
                    l.vehicleType = IFNULL(pr.vehicleType, 'Car'),
                    l.vehiclePlate = pr.additionalPlate
                WHERE pr.id = ? AND l.status = 'Active' AND (l.parkingPlan = 'None' OR l.parkingPlan IS NULL OR l.parkingFee = 0)
            ");
            $stmtSync->execute([$reqId]);
        } catch(Throwable $syncErr) {}

        $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 shrink-0 text-emerald-600'></i> อนุมัติคำขอเพิ่มรถและสิทธิ์ที่จอดรถเรียบร้อยแล้ว! ข้อมูลได้รับการอัปเดตเข้าระบบ</div>";
    } catch(PDOException $e) {
        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// --- 2. ระบบยกเลิกคำขอ / ไม่อนุมัติ / เพิกถอนสิทธิ์ที่จอดรถ ---
// แอดมินมีสิทธิ์ที่จะยกเลิกคำขอได้ ทั้งรายการที่รออนุมัติ หรือรายการที่อนุมัติไปแล้ว
if (isset($_POST['cancel_parking'])) {
    $reqId = intval($_POST['request_id']);
    $cancelReason = !empty($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : 'ที่จอดรถเต็ม หรือไม่ผ่านเกณฑ์การจัดสรร';
    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : $cancelReason;

    try {
        $stmt = $conn->prepare("
            UPDATE parking_requests 
            SET status = 'Cancelled', cancelledAt = NOW(), cancelReason = ?, adminRemark = ? 
            WHERE id = ?
        ");
        $stmt->execute([$cancelReason, $adminRemark, $reqId]);
        $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='info' class='w-5 h-5 shrink-0 text-amber-600'></i> ยกเลิกคำขอ / เพิกถอนสิทธิ์ที่จอดรถเรียบร้อยแล้ว สถานะเปลี่ยนเป็น 'ยกเลิกแล้ว'</div>";
    } catch(PDOException $e) {
        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// --- 3. ระบบลบรายการประวัติคำขอ ---
if (isset($_POST['delete_parking'])) {
    $reqId = intval($_POST['request_id']);
    try {
        $stmtInfo = $conn->prepare("SELECT vehicleImage FROM parking_requests WHERE id = ?");
        $stmtInfo->execute([$reqId]);
        $targetCar = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($targetCar && !empty($targetCar['vehicleImage']) && file_exists($targetCar['vehicleImage'])) {
            @unlink($targetCar['vehicleImage']);
        }

        $stmtDel = $conn->prepare("DELETE FROM parking_requests WHERE id = ?");
        $stmtDel->execute([$reqId]);
        $message = "<div class='p-4 mb-6 bg-slate-100 border-l-4 border-slate-500 text-slate-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5 shrink-0 text-slate-600'></i> ลบรายการคำขอออกจากระบบเรียบร้อยแล้ว</div>";
    } catch(PDOException $e) {
        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 4. ดึงรายการคำขอทั้งหมดพร้อมข้อมูลห้องพักและผู้เช่า
$requests = [];
try {
    $sql = "
        SELECT 
            p.*, 
            r.roomNumber, 
            r.roomType, 
            r.floor, 
            u.fullName AS tenantName, 
            u.phone AS tenantPhone, 
            u.email AS tenantEmail,
            l.leaseId,
            l.startDate AS leaseStartDate,
            l.endDate AS leaseEndDate
        FROM parking_requests p
        LEFT JOIN Room r ON p.roomId = r.roomId
        LEFT JOIN User u ON p.tenantId = u.userId
        LEFT JOIN Lease l ON p.tenantId = l.tenantId AND l.status = 'Active'
        ORDER BY FIELD(p.status, 'Pending', 'Approved', 'Cancelled', 'Rejected'), p.id DESC
    ";
    $stmt = $conn->query($sql);
    if ($stmt) {
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {}

// สรุปสถิติสำหรับแท็บและ Dashboard Cards
$countPending = 0;
$countApproved = 0;
$countCancelled = 0;

foreach ($requests as $r) {
    if ($r['status'] === 'Pending') $countPending++;
    elseif ($r['status'] === 'Approved') $countApproved++;
    elseif (in_array($r['status'], ['Cancelled', 'Rejected'])) $countCancelled++;
}
$countTotal = count($requests);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>จัดการคำขอที่จอดรถ & ทะเบียนรถ - หอพักจัสมิน</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    tailwind.config = {
      theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } }
    }
  </script>
  <style>
    body {
      background-color: #FAF9F6;
      color: #1e293b;
      font-family: 'Prompt', sans-serif;
    }
    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96) translateY(6px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

  <!-- Top Navigation (Admin) -->
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/70 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-cyan-600 via-cyan-500 to-teal-600 flex items-center justify-center text-white shadow-sm ring-1 ring-cyan-500/20 shrink-0">
            <i data-lucide="car" class="w-5 h-5 text-white"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-cyan-50 text-cyan-700 font-bold px-2 py-0.5 rounded-full border border-cyan-200/60 uppercase tracking-wider">PARKING MANAGEMENT</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">จัดการคำขอและจัดสรรสิทธิ์ที่จอดรถมากกว่า 1 คัน</p>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <div class="flex items-center space-x-2.5 bg-rose-50/50 hover:bg-rose-50/80 border border-rose-100/80 rounded-2xl px-3 py-1.5 transition">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-rose-500 to-pink-600 text-white flex items-center justify-center font-bold text-xs shadow-sm">
              AD
            </div>
            <div class="text-left hidden sm:block">
              <div class="text-xs font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($adminName); ?></div>
              <div class="text-[10px] text-rose-500 font-medium">ผู้ดูแลระบบ</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-grow max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

    <!-- Flash message -->
    <?php if (!empty($message)) echo $message; ?>

    <!-- Banner Header -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-cyan-950 rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-slate-900/10 mb-8 relative overflow-hidden">
      <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-cyan-500/10 rounded-full blur-2xl pointer-events-none"></div>
      <div class="absolute right-8 top-1/2 -translate-y-1/2 hidden md:block text-white/10 pointer-events-none">
        <i data-lucide="car" class="w-44 h-44"></i>
      </div>
      <div class="relative z-10 max-w-2xl">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-cyan-500/20 backdrop-blur-md border border-cyan-400/30 text-cyan-300 text-xs font-semibold mb-3">
          <i data-lucide="shield" class="w-3.5 h-3.5"></i> ศูนย์ควบคุมคำขอที่จอดรถ (Admin Console)
        </div>
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-white mb-2">
          คำขอที่จอดรถ & ทะเบียนรถ (กรณีมีรถมากกว่า 1 คัน)
        </h1>
        <p class="text-slate-300 text-sm leading-relaxed">
          ตรวจสอบคำขอลงทะเบียนรถคันที่ 2 ขึ้นไปของผู้เช่า เปรียบเทียบป้ายทะเบียนคันปัจจุบันและคันที่ขอเพิ่ม พร้อมอำนาจในการอนุมัติสิทธิ์เข้าจอด หรือยกเลิกคำขอตามความเหมาะสมของพื้นที่จอดรถ
        </p>
      </div>
    </div>

    <!-- 4 Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <!-- 1. รอตรวจสอบ (Pending) -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 border border-amber-200/60 flex items-center justify-center shrink-0">
          <i data-lucide="clock" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">รอการตรวจสอบ</div>
          <div class="text-xl sm:text-2xl font-bold text-amber-600"><?php echo $countPending; ?> <span class="text-xs font-normal text-slate-500">คำขอ</span></div>
        </div>
      </div>

      <!-- 2. อนุมัติแล้ว (Approved) -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-200/60 flex items-center justify-center shrink-0">
          <i data-lucide="check-circle" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">อนุมัติสิทธิ์แล้ว</div>
          <div class="text-xl sm:text-2xl font-bold text-emerald-600"><?php echo $countApproved; ?> <span class="text-xs font-normal text-slate-500">คัน</span></div>
        </div>
      </div>

      <!-- 3. ยกเลิกแล้ว / ไม่อนุมัติ -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 border border-rose-200/60 flex items-center justify-center shrink-0">
          <i data-lucide="x-circle" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">ยกเลิก / ไม่อนุมัติ</div>
          <div class="text-xl sm:text-2xl font-bold text-rose-600"><?php echo $countCancelled; ?> <span class="text-xs font-normal text-slate-500">รายการ</span></div>
        </div>
      </div>

      <!-- 4. คำขอทั้งหมด -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-cyan-50 text-cyan-600 border border-cyan-200/60 flex items-center justify-center shrink-0">
          <i data-lucide="car" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">คำขอทั้งหมดในระบบ</div>
          <div class="text-xl sm:text-2xl font-bold text-cyan-800"><?php echo $countTotal; ?> <span class="text-xs font-normal text-slate-500">รายการ</span></div>
        </div>
      </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden mb-6">
      <div class="p-4 sm:p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        
        <!-- Search Input -->
        <div class="relative flex-1 max-w-md">
          <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
            <i data-lucide="search" class="w-4 h-4"></i>
          </div>
          <input type="text" id="adminSearchInput" onkeyup="applyAdminFilters()"
            placeholder="ค้นหาเลขห้อง, ชื่อผู้เช่า, เบอร์โทร, ป้ายทะเบียน..."
            class="w-full pl-10 pr-4 py-2.5 text-xs sm:text-sm bg-slate-50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition">
        </div>

        <!-- Filter Status Tabs (แบบหน้าแจ้งงาน) -->
        <div class="flex items-center gap-2 overflow-x-auto pb-1" id="adminFilterTabs">
          <button type="button" onclick="setAdminTab('all')" class="filter-btn active px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white cursor-pointer" data-tab="all">
            ทั้งหมด (<?php echo $countTotal; ?>)
          </button>
          <button type="button" onclick="setAdminTab('Pending')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer" data-tab="Pending">
            ⏳ รออนุมัติ (<?php echo $countPending; ?>)
          </button>
          <button type="button" onclick="setAdminTab('Approved')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer" data-tab="Approved">
            ✅ อนุมัติแล้ว (<?php echo $countApproved; ?>)
          </button>
          <?php if($countCancelled > 0): ?>
          <button type="button" onclick="setAdminTab('Cancelled')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-slate-200 cursor-pointer" data-tab="Cancelled">
            ❌ ยกเลิกแล้ว (<?php echo $countCancelled; ?>)
          </button>
          <?php endif; ?>
        </div>

      </div>

      <!-- Table Section -->
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse" id="adminTable">
          <thead>
            <tr class="border-y border-slate-200/70 bg-slate-50/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider">
              <th class="py-3 px-4 sm:px-6">ห้องพัก</th>
              <th class="py-3 px-4">ผู้เช่า / ผู้ยื่นเรื่อง</th>
              <th class="py-3 px-4">ทะเบียนเดิม & ทะเบียนที่ขอเพิ่ม</th>
              <th class="py-3 px-4">รายละเอียดยานพาหนะ</th>
              <th class="py-3 px-4">วันที่ยื่นคำขอ</th>
              <th class="py-3 px-4">สถานะ</th>
              <th class="py-3 px-4 sm:px-6 text-right">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 text-xs sm:text-sm" id="adminTableBody">
            <?php if (empty($requests)): ?>
              <tr>
                <td colspan="7" class="py-12 text-center text-slate-400">
                  <div class="w-14 h-14 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-2">
                    <i data-lucide="car" class="w-7 h-7"></i>
                  </div>
                  <p class="font-medium">ยังไม่มีคำขอที่จอดรถในระบบ</p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($requests as $r): 
                $status = $r['status'];
                $statusBadge = '';
                if ($status === 'Pending') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/60"><span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> รอตรวจสอบ</span>';
                } elseif ($status === 'Approved') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60"><i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-600"></i> อนุมัติแล้ว</span>';
                } elseif ($status === 'Cancelled') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200"><i data-lucide="slash" class="w-3.5 h-3.5"></i> ยกเลิกแล้ว</span>';
                } elseif ($status === 'Rejected') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200/60"><i data-lucide="x-circle" class="w-3.5 h-3.5"></i> ปฏิเสธ</span>';
                }

                $vIcon = 'car';
                if ($r['vehicleType'] === 'Motorcycle') $vIcon = 'bike';
                elseif ($r['vehicleType'] === 'EV') $vIcon = 'zap';
                
                $searchContent = strtolower(($r['roomNumber'] ?? '') . ' ' . ($r['tenantName'] ?? '') . ' ' . ($r['tenantPhone'] ?? '') . ' ' . ($r['currentPlate'] ?? '') . ' ' . ($r['additionalPlate'] ?? '') . ' ' . ($r['province'] ?? '') . ' ' . ($r['brandModel'] ?? ''));
              ?>
                <tr class="hover:bg-slate-50/70 transition admin-row cursor-pointer" 
                    data-status="<?php echo htmlspecialchars($status); ?>" 
                    data-search="<?php echo htmlspecialchars($searchContent); ?>"
                    onclick="if(!event.target.closest('button') && !event.target.closest('a') && !event.target.closest('form')) openAdminModal(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>)">
                  
                  <!-- ห้องพัก -->
                  <td class="py-4 px-4 sm:px-6">
                    <div class="inline-flex items-center justify-center px-3 py-1.5 rounded-xl bg-rose-50 border border-rose-100 text-rose-700 font-extrabold text-sm shadow-sm">
                      <?php echo htmlspecialchars($r['roomNumber'] ?? '-'); ?>
                    </div>
                    <?php if(!empty($r['floor'])): ?>
                      <div class="text-[10px] text-slate-400 mt-0.5">ชั้น <?php echo htmlspecialchars($r['floor']); ?></div>
                    <?php endif; ?>
                  </td>

                  <!-- ผู้เช่า -->
                  <td class="py-4 px-4">
                    <div class="font-bold text-slate-900"><?php echo htmlspecialchars($r['tenantName'] ?? 'ไม่พบชื่อ'); ?></div>
                    <div class="text-xs text-slate-500 flex items-center gap-1 mt-0.5">
                      <i data-lucide="phone" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($r['tenantPhone'] ?? '-'); ?>
                    </div>
                  </td>

                  <!-- ทะเบียนเดิม & ทะเบียนที่ขอเพิ่ม -->
                  <td class="py-4 px-4">
                    <div class="space-y-1">
                      <div class="flex items-center gap-1.5">
                        <span class="text-[10px] font-semibold text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded">คันหลัก:</span>
                        <span class="font-bold text-slate-700"><?php echo htmlspecialchars($r['currentPlate']); ?></span>
                      </div>
                      <div class="flex items-center gap-1.5">
                        <span class="text-[10px] font-bold text-cyan-700 bg-cyan-100/70 px-1.5 py-0.5 rounded">ขอเพิ่ม:</span>
                        <span class="font-extrabold text-cyan-900 bg-cyan-50 border border-cyan-200/80 px-2 py-0.5 rounded-lg"><?php echo htmlspecialchars($r['additionalPlate']); ?></span>
                      </div>
                    </div>
                  </td>

                  <!-- รายละเอียดยานพาหนะ -->
                  <td class="py-4 px-4">
                    <div class="flex items-center gap-2">
                      <div class="w-7 h-7 rounded-lg bg-cyan-50 text-cyan-600 flex items-center justify-center shrink-0">
                        <i data-lucide="<?php echo $vIcon; ?>" class="w-4 h-4"></i>
                      </div>
                      <div>
                        <div class="font-medium text-slate-800 text-xs"><?php echo htmlspecialchars($r['brandModel'] ?: $r['vehicleType']); ?></div>
                        <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($r['province'] ?? '-'); ?></div>
                      </div>
                    </div>
                  </td>

                  <!-- วันที่ยื่นคำขอ -->
                  <td class="py-4 px-4 text-xs text-slate-500 whitespace-nowrap">
                    <div><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></div>
                    <div class="text-[10px] text-slate-400"><?php echo date('H:i น.', strtotime($r['created_at'])); ?></div>
                  </td>

                  <!-- สถานะ -->
                  <td class="py-4 px-4 whitespace-nowrap">
                    <?php echo $statusBadge; ?>
                  </td>

                  <!-- การจัดการ (Buttons) -->
                  <td class="py-4 px-4 sm:px-6 text-right whitespace-nowrap">
                    <div class="flex items-center justify-end gap-1.5">
                      <!-- ดูรายละเอียด / ตรวจสอบคำขอ -->
                      <button type="button" onclick="openAdminModal(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>)"
                        class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-slate-100 hover:bg-cyan-50 hover:text-cyan-700 text-slate-700 font-bold text-xs transition flex items-center gap-1 cursor-pointer" title="ตรวจสอบคำขอ">
                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                        <span class="hidden sm:inline">ตรวจสอบ</span>
                      </button>

                      <!-- Quick Approve if Pending -->
                      <?php if ($status === 'Pending'): ?>
                        <button type="button" onclick="quickApprove(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['additionalPlate'])); ?>')"
                          class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold text-xs transition border border-emerald-200/60 flex items-center gap-1 cursor-pointer" title="อนุมัติคำขอ">
                          <i data-lucide="check" class="w-3.5 h-3.5"></i>
                          <span class="hidden sm:inline">อนุมัติ</span>
                        </button>
                      <?php endif; ?>

                      <!-- Quick Cancel if Pending or Approved (แอดมินมีสิทธิ์ที่จะยกเลิกคำขอได้) -->
                      <?php if ($status === 'Pending' || $status === 'Approved'): ?>
                        <button type="button" onclick="quickCancel(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['additionalPlate'])); ?>', '<?php echo $status; ?>')"
                          class="p-2 sm:px-3 sm:py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs transition border border-rose-200/60 flex items-center gap-1 cursor-pointer" title="ยกเลิกคำขอนี้">
                          <i data-lucide="x" class="w-3.5 h-3.5"></i>
                          <span class="hidden sm:inline">ยกเลิกคำขอ</span>
                        </button>
                      <?php endif; ?>

                      <!-- Delete if Cancelled/Rejected -->
                      <?php if (in_array($status, ['Cancelled', 'Rejected'])): ?>
                        <button type="button" onclick="quickDelete(<?php echo $r['id']; ?>)"
                          class="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition cursor-pointer" title="ลบรายการนี้">
                          <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
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

  </main>

  <!-- ==================== Large Admin Interactive Modal (Full Screen) ==================== -->
  <div id="adminModal" class="fixed inset-0 z-50 hidden bg-white flex flex-col w-full h-full min-h-screen overflow-hidden">
    
    <!-- Modal Header (Full Width Top Bar) -->
    <div class="p-4 sm:px-8 sm:py-4.5 bg-gradient-to-r from-slate-900 via-cyan-950 to-slate-900 text-white flex items-center justify-between shrink-0 shadow-md">
      <div class="flex items-center gap-3">
        <button type="button" onclick="closeAdminModal()" class="w-9 h-9 rounded-2xl bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition cursor-pointer shadow-xs shrink-0" title="ย้อนกลับ">
          <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div>
          <div class="flex items-center gap-2 mb-1">
            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-cyan-500/20 text-cyan-300 border border-cyan-400/30" id="modalRoomBadge">ห้อง -</span>
            <span class="text-xs text-slate-400 font-mono" id="modalReqCode">#REQ-CAR-000</span>
          </div>
          <h3 class="font-display font-bold text-lg sm:text-2xl text-white">ตรวจสอบคำขอเพิ่มรถ & จัดการสิทธิ์ที่จอดรถ</h3>
        </div>
      </div>
      <button type="button" onclick="closeAdminModal()" class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 bg-white/10 hover:bg-white/20 rounded-xl text-white font-bold text-xs transition cursor-pointer shadow-xs">
        <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
      </button>
    </div>

    <!-- Modal Body (Full Width Scrollable, Centered 2 Columns layout) -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-gradient-to-b from-cyan-50/20 via-slate-50/50 to-white">
      <div class="max-w-5xl mx-auto w-full grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Left Column: ส่วนข้อมูลของผู้เช่า & รูปถ่าย -->
        <div class="space-y-4">
          <div class="p-5 rounded-3xl bg-white border border-slate-200 shadow-sm">
            <div class="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider mb-3">
              <i data-lucide="user" class="w-4 h-4 text-cyan-600"></i> ข้อมูลผู้เช่า & ห้องพัก
            </div>
            <div class="space-y-2.5 text-xs sm:text-sm">
              <div class="flex justify-between">
                <span class="text-slate-400">ชื่อ-นามสกุล:</span>
                <span class="font-bold text-slate-800" id="mTenantName">-</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">เบอร์โทรศัพท์:</span>
                <span class="font-bold text-slate-800" id="mTenantPhone">-</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">ห้องพัก:</span>
                <span class="font-bold text-cyan-700" id="mRoomNumber">-</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-400">วันที่ยื่นคำขอ:</span>
                <span class="font-medium text-slate-700 font-mono" id="mCreatedAt">-</span>
              </div>
            </div>
          </div>

          <!-- รูปภาพรถยนต์ / เอกสารแนบ -->
          <div class="p-5 rounded-3xl bg-white border border-slate-200 shadow-sm">
            <div class="flex items-center gap-2 text-xs font-bold text-slate-700 uppercase tracking-wider mb-3">
              <i data-lucide="image" class="w-4 h-4 text-cyan-600"></i> รูปถ่ายยานพาหนะ / เอกสารแนบ
            </div>
            <div id="mImageWrapper" class="text-center">
              <a id="mImageLink" href="#" target="_blank" class="block group relative overflow-hidden rounded-2xl bg-slate-100 border border-slate-200">
                <img id="mVehicleImage" src="#" alt="Vehicle" class="max-h-64 mx-auto object-contain group-hover:scale-102 transition duration-300">
                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold gap-1.5">
                  <i data-lucide="maximize-2" class="w-4 h-4"></i> คลิกดูภาพขยาย
                </div>
              </a>
              <p id="mNoImageText" class="text-xs text-slate-400 italic py-6 hidden">ไม่มีรูปถ่ายหรือเอกสารแนบ</p>
            </div>
          </div>
        </div>

        <!-- Right Column: รายละเอียดยานพาหนะ & ส่วนการพิจารณาของแอดมิน -->
        <div class="space-y-4">
          
          <!-- Plates Comparison Card -->
          <div class="p-5 rounded-3xl bg-cyan-50/70 border border-cyan-200 shadow-sm space-y-3">
            <div class="text-xs font-bold text-cyan-900 flex items-center gap-1.5">
              <i data-lucide="tag" class="w-4 h-4 text-cyan-600"></i> ป้ายทะเบียนรถ
            </div>
            
            <div class="grid grid-cols-2 gap-3 text-xs">
              <div class="bg-white p-3.5 rounded-2xl border border-slate-200 shadow-xs">
                <span class="text-slate-400 block text-[10px]">คันปัจจุบัน (คันหลัก)</span>
                <span class="font-extrabold text-slate-800 text-sm sm:text-base" id="mCurrentPlate">-</span>
              </div>
              <div class="bg-white p-3.5 rounded-2xl border-2 border-cyan-300 shadow-xs">
                <span class="text-cyan-600 block text-[10px] font-bold">คันที่ขอเพิ่ม (คันที่ 2+)</span>
                <span class="font-extrabold text-cyan-900 text-sm sm:text-base" id="mAdditionalPlate">-</span>
              </div>
            </div>

            <div class="text-xs sm:text-sm text-slate-600 space-y-1.5 pt-1">
              <div><strong>จังหวัด:</strong> <span id="mProvince">-</span></div>
              <div><strong>ประเภท:</strong> <span id="mVehicleType">-</span></div>
              <div><strong>ยี่ห้อ / รุ่น / สี:</strong> <span id="mBrandModel">-</span></div>
            </div>
          </div>

          <!-- เหตุผลที่ลูกบ้านระบุ -->
          <div class="p-5 rounded-3xl bg-white border border-slate-200 shadow-sm text-xs sm:text-sm">
            <div class="font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
              <i data-lucide="help-circle" class="w-4 h-4 text-slate-400"></i> เหตุผลความจำเป็นในการขอเพิ่มที่จอดรถ
            </div>
            <p class="text-slate-800 bg-slate-50/70 p-3 rounded-2xl border border-slate-200/80 leading-relaxed font-medium" id="mReason">
              -
            </p>
          </div>

          <!-- สถานะปัจจุบัน -->
          <div id="mStatusBadgeContainer" class="p-4 rounded-2xl border text-xs sm:text-sm font-bold flex items-center justify-between">
            <span id="mStatusText">สถานะ: -</span>
            <span class="text-xs font-normal text-slate-500 font-mono" id="mActionDate"></span>
          </div>

          <!-- บันทึกแอดมินหรือเหตุผลยกเลิกถ้ามี -->
          <div id="mRemarksContainer" class="hidden text-xs sm:text-sm p-4 rounded-2xl border bg-slate-50 space-y-1">
            <div class="font-bold text-slate-700">บันทึกของแอดมิน:</div>
            <p class="text-slate-800" id="mRemarksText">-</p>
          </div>

          <!-- Action Forms Container in Modal -->
          <div class="border-t border-slate-200 pt-3 space-y-3" id="mActionArea">
            <div class="text-xs font-bold text-slate-700">การพิจารณาโดยแอดมิน:</div>

            <!-- Approve Form -->
            <form action="admin_parking.php" method="POST" class="space-y-2" id="mApproveForm">
              <input type="hidden" name="request_id" id="mApproveId" value="">
              <div>
                <input type="text" name="admin_remark" id="mApproveRemark"
                  placeholder="หมายเหตุการอนุมัติ (เช่น อนุญาตให้จอดช่อง P-10)"
                  class="w-full px-3.5 py-2.5 text-xs sm:text-sm bg-slate-50 rounded-xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition">
              </div>
              <button type="submit" name="approve_parking"
                class="w-full py-3 px-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs sm:text-sm shadow-md shadow-emerald-600/20 transition flex items-center justify-center gap-1.5 cursor-pointer">
                <i data-lucide="check" class="w-4 h-4"></i> อนุมัติคำขอ & บันทึกสิทธิ์เข้าจอด
              </button>
            </form>

            <!-- Cancel / Reject Form (แอดมินมีสิทธิ์ที่จะยกเลิกคำขอได้) -->
            <form action="admin_parking.php" method="POST" class="space-y-2" id="mCancelForm" onsubmit="return confirmCancelForm(event)">
              <input type="hidden" name="request_id" id="mCancelId" value="">
              <div>
                <input type="text" name="cancel_reason" id="mCancelReason"
                  placeholder="ระบุเหตุผลการยกเลิก / ปฏิเสธ (เช่น ที่จอดรถเต็ม, ข้อมูลไม่ถูกต้อง)"
                  class="w-full px-3.5 py-2.5 text-xs sm:text-sm bg-slate-50 rounded-xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition">
              </div>
              <button type="submit" name="cancel_parking"
                class="w-full py-2.5 px-4 rounded-2xl bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs sm:text-sm border border-rose-200/80 transition flex items-center justify-center gap-1.5 cursor-pointer">
                <i data-lucide="x" class="w-4 h-4"></i> ยกเลิกคำขอ / เพิกถอนสิทธิ์ที่จอดรถ
              </button>
            </form>

          </div>

        </div>

      </div>
    </div>

    <!-- Modal Footer -->
    <div class="p-4 sm:p-5 bg-white border-t border-slate-200 shrink-0 flex items-center justify-end shadow-lg">
      <button type="button" onclick="closeAdminModal()" class="px-6 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs sm:text-sm transition cursor-pointer">
        ปิดหน้าต่าง
      </button>
    </div>

  </div>

  <!-- Hidden Forms for Quick Action triggers -->
  <form id="quickApproveForm" action="admin_parking.php" method="POST" class="hidden">
    <input type="hidden" name="request_id" id="qa_id">
    <input type="hidden" name="admin_remark" id="qa_remark" value="อนุมัติสิทธิ์เข้าจอดเรียบร้อยแล้ว">
    <input type="hidden" name="approve_parking" value="1">
  </form>

  <form id="quickCancelForm" action="admin_parking.php" method="POST" class="hidden">
    <input type="hidden" name="request_id" id="qc_id">
    <input type="hidden" name="cancel_reason" id="qc_reason" value="">
    <input type="hidden" name="cancel_parking" value="1">
  </form>

  <form id="quickDeleteForm" action="admin_parking.php" method="POST" class="hidden">
    <input type="hidden" name="request_id" id="qd_id">
    <input type="hidden" name="delete_parking" value="1">
  </form>

  <script>
    let currentAdminTab = 'all';

    function setAdminTab(tab) {
      currentAdminTab = tab;
      document.querySelectorAll('#adminFilterTabs .filter-btn').forEach(btn => {
        if (btn.getAttribute('data-tab') === tab) {
          btn.classList.add('bg-slate-800', 'text-white');
          btn.classList.remove('bg-white', 'text-slate-600');
        } else {
          btn.classList.remove('bg-slate-800', 'text-white');
          btn.classList.add('bg-white', 'text-slate-600');
        }
      });
      applyAdminFilters();
    }

    function applyAdminFilters() {
      const query = document.getElementById('adminSearchInput').value.toLowerCase().trim();
      const rows = document.querySelectorAll('.admin-row');

      rows.forEach(row => {
        const status = row.getAttribute('data-status');
        const search = row.getAttribute('data-search') || '';

        let matchTab = (currentAdminTab === 'all');
        if (currentAdminTab === 'Cancelled') {
          matchTab = (status === 'Cancelled' || status === 'Rejected');
        } else if (currentAdminTab !== 'all') {
          matchTab = (status === currentAdminTab);
        }

        const matchSearch = (!query || search.includes(query));

        if (matchTab && matchSearch) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    // Modal logic
    function openAdminModal(data) {
      document.getElementById('modalRoomBadge').textContent = 'ห้อง ' + (data.roomNumber || '-');
      document.getElementById('modalReqCode').textContent = '#REQ-CAR-' + String(data.id).padStart(4, '0');

      document.getElementById('mTenantName').textContent = data.tenantName || 'ไม่ระบุ';
      document.getElementById('mTenantPhone').textContent = data.tenantPhone || '-';
      document.getElementById('mRoomNumber').textContent = (data.roomNumber || '-') + (data.floor ? ' (ชั้น ' + data.floor + ')' : '');
      document.getElementById('mCreatedAt').textContent = data.created_at || '-';

      document.getElementById('mCurrentPlate').textContent = data.currentPlate || '-';
      document.getElementById('mAdditionalPlate').textContent = data.additionalPlate || '-';
      document.getElementById('mProvince').textContent = data.province || '-';
      
      const typeMap = { 'Car': '🚗 รถยนต์ (Car)', 'Motorcycle': '🛵 รถมอเตอร์ไซค์ (Motorcycle)', 'EV': '⚡ รถยนต์ไฟฟ้า (EV)', 'Other': '🚐 อื่นๆ' };
      document.getElementById('mVehicleType').textContent = typeMap[data.vehicleType] || data.vehicleType || '-';
      document.getElementById('mBrandModel').textContent = data.brandModel || '-';
      document.getElementById('mReason').textContent = data.reason || 'ไม่ได้ระบุเหตุผล';

      // Image
      const imgLink = document.getElementById('mImageLink');
      const imgTag = document.getElementById('mVehicleImage');
      const noImg = document.getElementById('mNoImageText');
      if (data.vehicleImage) {
        imgTag.src = data.vehicleImage;
        imgLink.href = data.vehicleImage;
        imgLink.classList.remove('hidden');
        noImg.classList.add('hidden');
      } else {
        imgLink.classList.add('hidden');
        noImg.classList.remove('hidden');
      }

      // Status Badge & remarks
      const badgeBox = document.getElementById('mStatusBadgeContainer');
      const statusText = document.getElementById('mStatusText');
      const actionDate = document.getElementById('mActionDate');
      const remarksBox = document.getElementById('mRemarksContainer');
      const remarksText = document.getElementById('mRemarksText');

      if (data.status === 'Pending') {
        badgeBox.className = 'p-3.5 rounded-2xl border border-amber-200 bg-amber-50 text-amber-800 text-xs font-bold flex items-center justify-between';
        statusText.innerHTML = '<span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span> สถานะ: รอการตรวจสอบ / อนุมัติ</span>';
        actionDate.textContent = '';
      } else if (data.status === 'Approved') {
        badgeBox.className = 'p-3.5 rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 text-xs font-bold flex items-center justify-between';
        statusText.innerHTML = '<span class="flex items-center gap-1.5"><i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i> สถานะ: อนุมัติสิทธิ์เข้าจอดแล้ว</span>';
        actionDate.textContent = data.approvedAt ? 'อนุมัติเมื่อ: ' + data.approvedAt : '';
      } else {
        badgeBox.className = 'p-3.5 rounded-2xl border border-slate-200 bg-slate-100 text-slate-700 text-xs font-bold flex items-center justify-between';
        statusText.innerHTML = '<span class="flex items-center gap-1.5"><i data-lucide="slash" class="w-4 h-4 text-slate-500"></i> สถานะ: ยกเลิกแล้ว / ไม่อนุมัติ</span>';
        actionDate.textContent = data.cancelledAt ? 'ยกเลิกเมื่อ: ' + data.cancelledAt : '';
      }

      // Remarks display
      let remarkContent = '';
      if (data.adminRemark) remarkContent += '<strong>บันทึกแอดมิน:</strong> ' + data.adminRemark + '<br>';
      if (data.cancelReason) remarkContent += '<strong>เหตุผลยกเลิก:</strong> ' + data.cancelReason;
      if (remarkContent) {
        remarksText.innerHTML = remarkContent;
        remarksBox.classList.remove('hidden');
      } else {
        remarksBox.classList.add('hidden');
      }

      // Set IDs for forms
      document.getElementById('mApproveId').value = data.id;
      document.getElementById('mCancelId').value = data.id;

      document.getElementById('adminModal').classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      lucide.createIcons();
    }

    function closeAdminModal() {
      document.getElementById('adminModal').classList.add('hidden');
      document.body.style.overflow = '';
    }

    // Quick Approve
    function quickApprove(id, plate) {
      Swal.fire({
        title: 'ยืนยันอนุมัติคำขอ?',
        text: 'ต้องการอนุมัติสิทธิ์จอดรถสำหรับทะเบียน ' + plate + ' ใช่หรือไม่',
        icon: 'question',
        input: 'text',
        inputPlaceholder: 'หมายเหตุเพิ่มเติม เช่น ช่องจอด P-08 (เว้นว่างได้)',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'ใช่, อนุมัติคำขอ',
        cancelButtonText: 'ยกเลิก',
        customClass: { popup: 'rounded-3xl font-sans' }
      }).then((result) => {
        if (result.isConfirmed) {
          document.getElementById('qa_id').value = id;
          if (result.value && result.value.trim() !== '') {
            document.getElementById('qa_remark').value = result.value.trim();
          }
          document.getElementById('quickApproveForm').submit();
        }
      });
    }

    // Quick Cancel (แอดมินมีสิทธิ์ที่จะยกเลิกคำขอได้)
    function quickCancel(id, plate, currentStatus) {
      const isApproved = (currentStatus === 'Approved');
      Swal.fire({
        title: isApproved ? 'เพิกถอนสิทธิ์ที่จอดรถ?' : 'ยกเลิกคำขอที่จอดรถ?',
        text: 'ต้องการยกเลิกคำขอสำหรับทะเบียน ' + plate + ' ใช่หรือไม่',
        icon: 'warning',
        input: 'text',
        inputPlaceholder: 'ระบุเหตุผลการยกเลิก เช่น ที่จอดรถเต็ม, ข้อมูลไม่ถูกต้อง',
        inputValidator: (value) => {
          if (!value || !value.trim()) {
            return 'กรุณาระบุเหตุผลการยกเลิกคำขอ';
          }
        },
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'ใช่, ยกเลิกคำขอ',
        cancelButtonText: 'ปิด',
        customClass: { popup: 'rounded-3xl font-sans' }
      }).then((result) => {
        if (result.isConfirmed) {
          document.getElementById('qc_id').value = id;
          document.getElementById('qc_reason').value = result.value.trim();
          document.getElementById('quickCancelForm').submit();
        }
      });
    }

    // Quick Delete
    function quickDelete(id) {
      Swal.fire({
        title: 'ยืนยันลบรายการ?',
        text: 'รายการที่ถูกลบจะไม่สามารถกู้คืนได้ ต้องการดำเนินการต่อหรือไม่',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'ใช่, ลบรายการ',
        cancelButtonText: 'ยกเลิก',
        customClass: { popup: 'rounded-3xl font-sans' }
      }).then((result) => {
        if (result.isConfirmed) {
          document.getElementById('qd_id').value = id;
          document.getElementById('quickDeleteForm').submit();
        }
      });
    }

    function confirmCancelForm(e) {
      const reason = document.getElementById('mCancelReason').value.trim();
      if (!reason) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'กรุณาระบุเหตุผล',
          text: 'กรุณาระบุเหตุผลการยกเลิกคำขอในช่องข้อความ',
          confirmButtonColor: '#e11d48'
        });
        return false;
      }
      return true;
    }

    document.addEventListener('DOMContentLoaded', () => {
      lucide.createIcons();
    });
  </script>
</body>
</html>
