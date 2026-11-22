<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$initials = strtoupper(mb_substr($adminName, 0, 2, 'UTF-8'));

// 🌟 ระบบ Export เป็นไฟล์ Excel (CSV)
if (isset($_GET['export']) && $_GET['export'] === 'income') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Income_Report_' . date('Ymd_His') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // ตั้งค่าให้ Excel อ่านภาษาไทยได้ (BOM)
    
    // หัวตาราง
    fputcsv($output, array('รหัสบิล', 'รอบเดือน', 'เลขห้อง', 'ชื่อผู้เช่า', 'ค่าเช่าห้อง (บาท)', 'ค่าน้ำ (บาท)', 'ค่าไฟ (บาท)', 'ค่าอื่นๆ (บาท)', 'ยอดรวมสุทธิ (บาท)', 'สถานะ'));

    // ดึงข้อมูลเฉพาะห้องและผู้เช่าที่มีอยู่จริง
    $stmtExport = $conn->query("
        SELECT i.invoiceId, i.billingMonth, r.roomNumber, u.fullName, 
               i.rentPrice, 
               GREATEST(0, (i.waterCurr - i.waterPrev)) * i.waterUnitRate as waterTotal, 
               GREATEST(0, (i.electricCurr - i.electricPrev)) * i.electricUnitRate as elecTotal, 
               i.otherFee, i.totalAmount, i.status 
        FROM Invoices i
        JOIN Room r ON i.roomId = r.roomId
        JOIN User u ON i.tenantId = u.userId
        ORDER BY i.invoiceId DESC
    ");
    
    while ($row = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
        $statusTh = 'รอชำระเงิน';
        if($row['status'] == 'Paid') $statusTh = 'ชำระแล้ว';
        elseif($row['status'] == 'Pending Approval') $statusTh = 'รอตรวจสอบสลิป';
        
        fputcsv($output, array(
            'INV-'.str_pad($row['invoiceId'], 5, '0', STR_PAD_LEFT),
            $row['billingMonth'],
            $row['roomNumber'],
            $row['fullName'],
            number_format($row['rentPrice'], 2),
            number_format($row['waterTotal'], 2),
            number_format($row['elecTotal'], 2),
            number_format($row['otherFee'], 2),
            number_format($row['totalAmount'], 2),
            $statusTh
        ));
    }
    fclose($output);
    exit();
}

// ================= 📊 เตรียมข้อมูลสำหรับระบบรายงานและกราฟ =================

// ตัวแปรเดือนปัจจุบัน
$thaiMonths = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$currMonthNum = intval(date('n'));
$currYear = date('Y');
$currMonthNameTh = $thaiMonths[$currMonthNum];
$currBillingPattern = $currMonthNameTh . ' ' . $currYear;

// 1. ยอดรายรับเดือนปัจจุบัน
$stmtCurrRev = $conn->prepare("
    SELECT COALESCE(SUM(i.totalAmount), 0) 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    WHERE i.status = 'Paid' 
      AND (i.billingMonth LIKE ? OR i.billingMonth LIKE ? OR (MONTH(i.createdAt) = ? AND YEAR(i.createdAt) = ?))
");
$stmtCurrRev->execute(['%' . $currBillingPattern . '%', '%' . $currMonthNameTh . '%', $currMonthNum, $currYear]);
$thisMonthRev = (float)$stmtCurrRev->fetchColumn();

// 2. ข้อมูลรายรับแยกตามเดือน (12 เดือนล่าสุด)
$stmtMonthly = $conn->query("
    SELECT i.billingMonth, SUM(i.totalAmount) as total, MAX(i.invoiceId) as maxId
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    WHERE i.status = 'Paid' 
    GROUP BY i.billingMonth 
    ORDER BY maxId DESC 
    LIMIT 12
");
$incomeLabelsRaw = [];
$incomeDataRaw = [];
while($row = $stmtMonthly->fetch(PDO::FETCH_ASSOC)) {
    $incomeLabelsRaw[] = $row['billingMonth'];
    $incomeDataRaw[] = (float)$row['total'];
}
$incomeLabels = array_reverse($incomeLabelsRaw);
$incomeData = array_reverse($incomeDataRaw);

// 3. ข้อมูลรายรับสะสมรายปี
$stmtYearly = $conn->query("
    SELECT i.billingMonth, i.totalAmount 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    WHERE i.status = 'Paid'
");
$yearlyDataRaw = [];
$totalRevenueAllTime = 0;
while($row = $stmtYearly->fetch(PDO::FETCH_ASSOC)) {
    preg_match('/\d{4}/', $row['billingMonth'], $matches);
    $year = !empty($matches[0]) ? $matches[0] : '2026';
    if(!isset($yearlyDataRaw[$year])) {
        $yearlyDataRaw[$year] = 0;
    }
    $yearlyDataRaw[$year] += (float)$row['totalAmount'];
    $totalRevenueAllTime += (float)$row['totalAmount'];
}
ksort($yearlyDataRaw);
$yearlyLabels = array_keys($yearlyDataRaw);
$yearlyData = array_values($yearlyDataRaw);

// 4. สัดส่วนแหล่งที่มาของรายรับ (ค่าเช่า, ค่าน้ำ, ค่าไฟ, ค่าอื่นๆ)
$stmtBreakdown = $conn->query("
    SELECT 
        COALESCE(SUM(i.rentPrice), 0) as totalRent,
        COALESCE(SUM(GREATEST(0, i.waterCurr - i.waterPrev) * i.waterUnitRate), 0) as totalWater,
        COALESCE(SUM(GREATEST(0, i.electricCurr - i.electricPrev) * i.electricUnitRate), 0) as totalElectric,
        COALESCE(SUM(i.otherFee), 0) as totalOther,
        COALESCE(SUM(i.totalAmount), 0) as grandTotal
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    WHERE i.status = 'Paid'
")->fetch(PDO::FETCH_ASSOC);

$totalRent = (float)($stmtBreakdown['totalRent'] ?? 0);
$totalWater = (float)($stmtBreakdown['totalWater'] ?? 0);
$totalElectric = (float)($stmtBreakdown['totalElectric'] ?? 0);
$totalOther = (float)($stmtBreakdown['totalOther'] ?? 0);
$grandRevenue = (float)($stmtBreakdown['grandTotal'] ?? 1);
if ($grandRevenue <= 0) $grandRevenue = 1;

$rentPct = round(($totalRent / $grandRevenue) * 100, 1);
$waterPct = round(($totalWater / $grandRevenue) * 100, 1);
$electricPct = round(($totalElectric / $grandRevenue) * 100, 1);
$otherPct = round(($totalOther / $grandRevenue) * 100, 1);

// 5. สถิติห้องพัก (Occupancy Rate)
$roomStatsQuery = $conn->query("
    SELECT 
        COUNT(*) as total, 
        SUM(CASE WHEN status='Occupied' THEN 1 ELSE 0 END) as occupied,
        SUM(CASE WHEN status='Available' THEN 1 ELSE 0 END) as available
    FROM Room
")->fetch(PDO::FETCH_ASSOC);
$totalRooms = (int)($roomStatsQuery['total'] ?? 0);
$occupiedRooms = (int)($roomStatsQuery['occupied'] ?? 0);
$availableRooms = (int)($roomStatsQuery['available'] ?? 0);
$occupancyPercent = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

// 6. สถิติงานบริการและแจ้งซ่อม
$reqStatsQuery = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN tenantComment IS NOT NULL AND tenantComment != '' THEN 1 ELSE 0 END) as with_comment
    FROM ServiceRequest
")->fetch(PDO::FETCH_ASSOC);
$totalReqs = (int)($reqStatsQuery['total'] ?? 0);
$pendingReqs = (int)($reqStatsQuery['pending'] ?? 0);
$inProgressReqs = (int)($reqStatsQuery['in_progress'] ?? 0);
$completedReqs = (int)($reqStatsQuery['completed'] ?? 0);
$commentCount = (int)($reqStatsQuery['with_comment'] ?? 0);
$resolutionRate = $totalReqs > 0 ? round(($completedReqs / $totalReqs) * 100, 1) : 0;

// 7. ตารางสรุปภาพรวมรายรับแยกตามรอบบิล
$monthlySummary = $conn->query("
    SELECT 
        i.billingMonth,
        COUNT(i.invoiceId) as invoiceCount,
        SUM(CASE WHEN i.status = 'Paid' THEN i.totalAmount ELSE 0 END) as paidTotal,
        SUM(CASE WHEN i.status = 'Unpaid' THEN i.totalAmount ELSE 0 END) as unpaidTotal,
        SUM(CASE WHEN i.status = 'Pending Approval' THEN i.totalAmount ELSE 0 END) as pendingTotal,
        SUM(i.totalAmount) as monthTotal,
        MAX(i.invoiceId) as sortId
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    GROUP BY i.billingMonth
    ORDER BY sortId DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 8. รายการบิลล่าสุด (Recent Transactions)
$recentInvoices = $conn->query("
    SELECT i.*, r.roomNumber, u.fullName 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    ORDER BY i.invoiceId DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ยอดค้างชำระทั้งหมด
$totalUnpaidAmount = (float)$conn->query("
    SELECT COALESCE(SUM(i.totalAmount), 0) 
    FROM Invoices i
    JOIN Room r ON i.roomId = r.roomId
    JOIN User u ON i.tenantId = u.userId
    WHERE i.status = 'Unpaid'
")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>jasmin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    tailwind.config = { 
      theme: { 
        extend: { 
          fontFamily: { 
            sans: ['Prompt', 'sans-serif'], 
            display: ['Kanit', 'sans-serif'] 
          } 
        } 
      } 
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
    .glass-card {
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
      border-radius: 1.5rem;
    }
    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">

  <div class="no-print">
    <?php include_once 'includes/admin_sidebar.php'; ?>
  </div>

  <!-- 🌟 Top Navigation (Admin Style) 🌟 -->
    <!-- Top Navigation (Admin) - Warm & Cozy Boutique Header -->
    <!-- Top Navigation (Admin) - Sleek Dark Glassmorphism Header -->
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/70 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-sm ring-1 ring-rose-500/20 shrink-0">
            <i data-lucide="bar-chart-3" class="w-5 h-5 text-amber-300"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-blue-500/20 text-blue-300 font-bold px-2 py-0.5 rounded-full border border-blue-500/30 uppercase tracking-wider">REPORTS</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">รายงานและสถิติภาพรวมหอพัก</p>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>
          <a href="admin_dashboard.php" class="text-xs sm:text-sm font-bold text-slate-600 hover:text-rose-600 flex items-center gap-1.5 transition px-3 py-1.5 rounded-xl hover:bg-rose-50/70 border border-stone-200/60">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> แดชบอร์ด
          </a>
          <div class="w-px h-5 bg-stone-200"></div>
          <a href="logout.php" onclick="return confirm('ออกจากระบบ?');" class="text-rose-500 hover:bg-rose-50 hover:text-rose-600 transition p-2 rounded-xl border border-stone-200/80" title="ออกจากระบบ">
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
              <i data-lucide="sparkles" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>รายงานสถิติอัจฉริยะ</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>อัปเดต: <?php echo date("d/m/Y H:i"); ?> น.</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ระบบรายงานและสถิติภาพรวม
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            วิเคราะห์รายรับทางการเงิน อัตราการเช่าห้องพัก ประสิทธิภาพการบริการ และส่งออกรายงานบัญชี
          </p>
        </div>

        <!-- Quick Top Action Buttons -->
        <div class="flex flex-wrap items-center gap-3 shrink-0 no-print">
          <button type="button" onclick="window.print()" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="printer" class="w-4 h-4 text-slate-600"></i>
            <span>สั่งพิมพ์ / PDF</span>
          </button>
          <a href="?export=income" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 shadow-sm shadow-rose-600/25 active:scale-95 cursor-pointer">
            <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
            <span>ส่งออก Excel</span>
          </a>
        </div>
      </div>
    </div>

    <!-- 🌟 KPI Metric Cards (แนวนอนเรียงกัน 3 ช่อง) 🌟 -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">

      <!-- KPI 2: รายรับเดือนนี้ -->
      <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs metric-card relative overflow-hidden">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-500">รายรับ (เดือน<?php echo $currMonthNameTh; ?>)</span>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center shadow-xs">
            <i data-lucide="trending-up" class="w-5 h-5"></i>
          </div>
        </div>
        <div class="mt-3">
          <div class="text-2xl sm:text-3xl font-bold font-display text-blue-600">
            ฿<?php echo number_format($thisMonthRev, 2); ?>
          </div>
          <div class="flex items-center justify-between text-xs mt-1">
            <span class="text-slate-500 font-medium">รอบบิลปัจจุบัน</span>
            <?php if($totalUnpaidAmount > 0): ?>
              <span class="text-rose-500 font-bold">ค้าง ฿<?php echo number_format($totalUnpaidAmount, 2); ?></span>
            <?php else: ?>
              <span class="text-emerald-600 font-bold">ครบถ้วน 100%</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- KPI 3: อัตราการเข้าพัก (Occupancy Rate) -->
      <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs metric-card relative overflow-hidden">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-500">อัตราการเช่าห้องพัก</span>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 text-white flex items-center justify-center shadow-xs">
            <i data-lucide="door-open" class="w-5 h-5"></i>
          </div>
        </div>
        <div class="mt-3">
          <div class="flex items-baseline gap-2">
            <span class="text-2xl sm:text-3xl font-bold font-display text-white"><?php echo $occupancyPercent; ?>%</span>
            <span class="text-xs text-slate-400 font-medium">(มีผู้เช่า <?php echo $occupiedRooms; ?>/<?php echo $totalRooms; ?>)</span>
          </div>
          <div class="w-full bg-slate-100 rounded-full h-1.5 mt-2 overflow-hidden">
            <div class="bg-gradient-to-r from-amber-500 to-orange-500 h-1.5 rounded-full" style="width: <?php echo min(100, $occupancyPercent); ?>%"></div>
          </div>
        </div>
      </div>

      <!-- KPI 4: อัตราปิดงานซ่อมและบริการ -->
      <div class="bg-white p-5 rounded-3xl border border-slate-200/80 shadow-xs metric-card relative overflow-hidden">
        <div class="flex items-center justify-between">
          <span class="text-xs font-semibold text-slate-500">อัตราแก้ปัญหาสำเร็จ</span>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-purple-500 to-pink-600 text-white flex items-center justify-center shadow-xs">
            <i data-lucide="check-check" class="w-5 h-5"></i>
          </div>
        </div>
        <div class="mt-3">
          <div class="flex items-baseline gap-2">
            <span class="text-2xl sm:text-3xl font-bold font-display text-purple-600"><?php echo $resolutionRate; ?>%</span>
            <span class="text-xs text-slate-400 font-medium">(เสร็จ <?php echo $completedReqs; ?>/<?php echo $totalReqs; ?>)</span>
          </div>
          <div class="flex items-center justify-between text-xs text-slate-500 mt-1">
            <span>รอดำเนินการ: <b class="text-amber-600"><?php echo $pendingReqs; ?></b></span>
            <?php if($commentCount > 0): ?>
              <span class="text-purple-600 font-bold flex items-center gap-0.5">
                <i data-lucide="message-square" class="w-3 h-3"></i> ติชม <?php echo $commentCount; ?>
              </span>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>

    <!-- 🌟 SECTION กราฟวิเคราะห์ 🌟 -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
      
      <!-- ฝั่งซ้าย (2 คอลัมน์): แนวโน้มรายรับ + สัดส่วนโครงสร้างรายได้ -->
      <div class="lg:col-span-2 space-y-6">
        
        <!-- 1. กราฟแท่ง/เส้น แนวโน้มรายรับรายเดือน -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6 pb-4 border-b border-slate-100">
            <div class="flex items-center gap-2.5">
              <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <i data-lucide="trending-up" class="w-5 h-5"></i>
              </div>
              <div>
                <h3 class="font-bold text-slate-900 text-base">แนวโน้มรายรับรายเดือน (12 เดือนล่าสุด)</h3>
                <p class="text-xs text-slate-500">เปรียบเทียบยอดการชำระเงินที่เกิดขึ้นจริงในแต่ละรอบบิล</p>
              </div>
            </div>
            
            <!-- ปุ่มสลับโหมด แท่ง / เส้น -->
            <div class="inline-flex rounded-xl bg-slate-100 p-1 text-xs font-bold no-print">
              <button type="button" id="btnChartBar" onclick="setChartType('bar')" class="px-3 py-1 rounded-lg bg-white text-slate-800 shadow-xs transition">แท่ง (Bar)</button>
              <button type="button" id="btnChartLine" onclick="setChartType('line')" class="px-3 py-1 rounded-lg text-slate-500 hover:text-slate-900 transition">เส้น (Line)</button>
            </div>
          </div>

          <div class="relative h-[280px] w-full">
            <canvas id="incomeChart"></canvas>
          </div>
        </div>

        <!-- 2. สัดส่วนโครงสร้างรายได้ (Revenue Sources Breakdown) -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs">
          <div class="flex items-center gap-2.5 mb-5 pb-3 border-b border-slate-100">
            <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
              <i data-lucide="pie-chart" class="w-5 h-5"></i>
            </div>
            <div>
              <h3 class="font-bold text-slate-900 text-base">สัดส่วนโครงสร้างรายรับ (Revenue Breakdown)</h3>
              <p class="text-xs text-slate-500">วิเคราะห์แหล่งที่มาของรายรับจากค่าเช่า ค่าน้ำ ค่าไฟ และค่าบริการ</p>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-5 gap-6 items-center">
            <div class="md:col-span-2 relative h-[210px] w-full flex items-center justify-center">
              <canvas id="breakdownChart"></canvas>
            </div>

            <div class="md:col-span-3 space-y-3">
              <div class="p-3 bg-blue-50/70 border border-blue-100 rounded-2xl flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <div class="w-3 h-3 rounded-full bg-blue-600 shrink-0"></div>
                  <div>
                    <span class="text-xs font-bold text-white block">ค่าเช่าห้องพัก (Room Rent)</span>
                    <span class="text-[11px] text-slate-500">สัดส่วน <?php echo $rentPct; ?>%</span>
                  </div>
                </div>
                <span class="text-sm font-bold text-blue-700">฿<?php echo number_format($totalRent, 2); ?></span>
              </div>

              <div class="p-3 bg-cyan-50/70 border border-cyan-100 rounded-2xl flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <div class="w-3 h-3 rounded-full bg-cyan-500 shrink-0"></div>
                  <div>
                    <span class="text-xs font-bold text-white block">ค่าน้ำประปา (Water Usage)</span>
                    <span class="text-[11px] text-slate-500">สัดส่วน <?php echo $waterPct; ?>%</span>
                  </div>
                </div>
                <span class="text-sm font-bold text-cyan-700">฿<?php echo number_format($totalWater, 2); ?></span>
              </div>

              <div class="p-3 bg-amber-50/70 border border-amber-100 rounded-2xl flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <div class="w-3 h-3 rounded-full bg-amber-500 shrink-0"></div>
                  <div>
                    <span class="text-xs font-bold text-white block">ค่าไฟฟ้า (Electricity)</span>
                    <span class="text-[11px] text-slate-500">สัดส่วน <?php echo $electricPct; ?>%</span>
                  </div>
                </div>
                <span class="text-sm font-bold text-amber-700">฿<?php echo number_format($totalElectric, 2); ?></span>
              </div>

              <div class="p-3 bg-purple-50/70 border border-purple-100 rounded-2xl flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <div class="w-3 h-3 rounded-full bg-purple-500 shrink-0"></div>
                  <div>
                    <span class="text-xs font-bold text-white block">ค่าบริการอื่นๆ / ค่าปรับ (Other Fees)</span>
                    <span class="text-[11px] text-slate-500">สัดส่วน <?php echo $otherPct; ?>%</span>
                  </div>
                </div>
                <span class="text-sm font-bold text-purple-700">฿<?php echo number_format($totalOther, 2); ?></span>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- ฝั่งขวา (1 คอลัมน์): สถานะห้องพัก + สถิติงานบริการ + รายรับรายปี -->
      <div class="space-y-6">
        
        <!-- 1. สัดส่วนห้องพัก -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
              <i data-lucide="home" class="w-4 h-4 text-amber-500"></i> สัดส่วนการเช่าห้องพัก
            </h3>
            <span class="text-xs font-bold text-slate-500">รวม <?php echo $totalRooms; ?> ห้อง</span>
          </div>

          <div class="relative h-[180px] w-full flex items-center justify-center">
            <canvas id="roomChart"></canvas>
          </div>

          <div class="mt-4 pt-3 border-t border-slate-100 grid grid-cols-2 gap-2 text-center text-xs">
            <div class="bg-emerald-50 text-emerald-700 p-2 rounded-xl font-bold">
              มีผู้เช่า <?php echo $occupiedRooms; ?> ห้อง
            </div>
            <div class="bg-amber-50 text-amber-700 p-2 rounded-xl font-bold">
              ห้องว่าง <?php echo $availableRooms; ?> ห้อง
            </div>
          </div>
        </div>

        <!-- 2. สัดส่วนงานบริการและแจ้งซ่อม -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
              <i data-lucide="wrench" class="w-4 h-4 text-purple-600"></i> สถานะงานบริการแจ้งซ่อม
            </h3>
            <span class="text-xs font-bold text-purple-600">รวม <?php echo $totalReqs; ?> งาน</span>
          </div>

          <div class="relative h-[180px] w-full flex items-center justify-center">
            <canvas id="reqChart"></canvas>
          </div>

          <div class="mt-4 pt-3 border-t border-slate-100 grid grid-cols-3 gap-1.5 text-center text-[11px] font-bold">
            <div class="bg-amber-50 text-amber-700 p-1.5 rounded-lg">
              รอทำ <?php echo $pendingReqs; ?>
            </div>
            <div class="bg-blue-50 text-blue-700 p-1.5 rounded-lg">
              กำลังทำ <?php echo $inProgressReqs; ?>
            </div>
            <div class="bg-emerald-50 text-emerald-700 p-1.5 rounded-lg">
              เสร็จ <?php echo $completedReqs; ?>
            </div>
          </div>
        </div>

        <!-- 3. รายรับสะสมรายปี -->
        <div class="bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-slate-900 text-sm flex items-center gap-2">
              <i data-lucide="calendar" class="w-4 h-4 text-teal-600"></i> สรุปรายรับรายปี
            </h3>
            <span class="text-xs text-slate-400">เปรียบเทียบปี</span>
          </div>

          <div class="relative h-[170px] w-full">
            <canvas id="yearlyChart"></canvas>
          </div>
        </div>

      </div>

    </div>

    <!-- 🌟 ตารางสรุปผลประกอบการรายเดือน (Monthly Financial Breakdown Table) 🌟 -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
      <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h3 class="font-display font-bold text-slate-900 text-lg flex items-center gap-2">
            <i data-lucide="table-2" class="w-5 h-5 text-rose-600"></i> สรุปผลประกอบการแยกตามรอบเดือน
          </h3>
          <p class="text-xs text-slate-400 mt-0.5">ยอดเรียกเก็บ ยอดชำระ และอัตราการจัดเก็บเงิน (Collection Rate)</p>
        </div>
        <span class="text-xs font-semibold text-slate-500 bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-200">
          ทั้งหมด <?php echo count($monthlySummary); ?> รอบบิล
        </span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-600">
          <thead class="bg-slate-50 text-slate-500 text-xs font-bold uppercase tracking-wider">
            <tr>
              <th class="px-6 py-4">รอบบิล</th>
              <th class="px-6 py-4 text-center">จำนวนบิล</th>
              <th class="px-6 py-4 text-right">ยอดเรียกเก็บรวม</th>
              <th class="px-6 py-4 text-right">ยอดชำระแล้ว</th>
              <th class="px-6 py-4 text-right">ค้างชำระ</th>
              <th class="px-6 py-4 text-center">อัตราจัดเก็บ</th>
              <th class="px-6 py-4 text-center">สถานะ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if(count($monthlySummary) > 0): foreach($monthlySummary as $m): 
                $rate = $m['monthTotal'] > 0 ? round(($m['paidTotal'] / $m['monthTotal']) * 100) : 0;
                $isFull = ($rate >= 100);
            ?>
            <tr class="hover:bg-slate-50/70 transition">
              <td class="px-6 py-4 font-bold text-slate-900">
                <?php echo htmlspecialchars($m['billingMonth']); ?>
              </td>
              <td class="px-6 py-4 text-center font-medium">
                <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md text-xs font-bold">
                  <?php echo $m['invoiceCount']; ?> บิล
                </span>
              </td>
              <td class="px-6 py-4 text-right font-semibold text-slate-700">
                ฿<?php echo number_format($m['monthTotal'], 2); ?>
              </td>
              <td class="px-6 py-4 text-right font-bold text-emerald-600">
                ฿<?php echo number_format($m['paidTotal'], 2); ?>
              </td>
              <td class="px-6 py-4 text-right font-semibold <?php echo ($m['unpaidTotal'] > 0) ? 'text-rose-600' : 'text-slate-400'; ?>">
                ฿<?php echo number_format($m['unpaidTotal'], 2); ?>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center justify-center gap-2">
                  <div class="w-16 bg-slate-100 rounded-full h-2 overflow-hidden">
                    <div class="<?php echo $isFull ? 'bg-emerald-500' : 'bg-blue-500'; ?> h-2 rounded-full" style="width: <?php echo min(100, $rate); ?>%"></div>
                  </div>
                  <span class="text-xs font-bold <?php echo $isFull ? 'text-emerald-600' : 'text-slate-700'; ?>"><?php echo $rate; ?>%</span>
                </div>
              </td>
              <td class="px-6 py-4 text-center">
                <?php if($isFull): ?>
                  <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full border border-emerald-200">
                    <i data-lucide="check-circle" class="w-3 h-3"></i> ครบถ้วน
                  </span>
                <?php else: ?>
                  <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full border border-amber-200">
                    <i data-lucide="clock" class="w-3 h-3"></i> มีค้างชำระ
                  </span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr>
              <td colspan="7" class="px-6 py-12 text-center text-slate-400">ยังไม่มีข้อมูลบิลในระบบ</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- 🌟 ตารางรายการบิลล่าสุด (Recent Invoices) 🌟 -->
    <div class="bg-white rounded-3xl border border-slate-200/80 shadow-xs overflow-hidden mb-8">
      <div class="p-6 border-b border-slate-100 flex items-center justify-between">
        <div>
          <h3 class="font-display font-bold text-slate-900 text-lg flex items-center gap-2">
            <i data-lucide="receipt" class="w-5 h-5 text-blue-600"></i> ประวัติรายการบิลล่าสุด
          </h3>
          <p class="text-xs text-slate-400 mt-0.5">รายการใบแจ้งหนี้และการชำระเงินที่บันทึกเข้าระบบล่าสุด</p>
        </div>
        <a href="manage_invoice.php" class="text-xs font-bold text-blue-600 hover:text-blue-800 transition flex items-center gap-1">
          ดูบิลทั้งหมด <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-600">
          <thead class="bg-slate-50 text-slate-500 text-xs font-bold uppercase tracking-wider">
            <tr>
              <th class="px-6 py-4">รหัสบิล</th>
              <th class="px-6 py-4">ห้องพัก</th>
              <th class="px-6 py-4">ผู้เช่า</th>
              <th class="px-6 py-4">รอบเดือน</th>
              <th class="px-6 py-4 text-right">ยอดสุทธิ</th>
              <th class="px-6 py-4 text-center">สถานะ</th>
              <th class="px-6 py-4 text-center no-print">เอกสาร</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if(count($recentInvoices) > 0): foreach($recentInvoices as $inv): 
                $statusColor = "bg-amber-50 text-amber-700 border-amber-200";
                $statusText = "รอชำระ";
                if ($inv['status'] === 'Paid') {
                    $statusColor = "bg-emerald-50 text-emerald-700 border-emerald-200";
                    $statusText = "ชำระแล้ว";
                } elseif ($inv['status'] === 'Pending Approval') {
                    $statusColor = "bg-blue-50 text-blue-700 border-blue-200";
                    $statusText = "รอตรวจสลิป";
                }
            ?>
            <tr class="hover:bg-slate-50/70 transition">
              <td class="px-6 py-4 font-mono font-bold text-xs text-slate-500">
                #INV-<?php echo str_pad($inv['invoiceId'], 4, '0', STR_PAD_LEFT); ?>
              </td>
              <td class="px-6 py-4 font-bold text-slate-900">
                <span class="px-2 py-0.5 rounded bg-slate-800 text-white text-xs">
                  ห้อง <?php echo htmlspecialchars($inv['roomNumber']); ?>
                </span>
              </td>
              <td class="px-6 py-4 font-medium text-slate-700">
                <?php echo htmlspecialchars($inv['fullName']); ?>
              </td>
              <td class="px-6 py-4 text-slate-500 text-xs font-medium">
                <?php echo htmlspecialchars($inv['billingMonth']); ?>
              </td>
              <td class="px-6 py-4 text-right font-bold text-slate-900">
                ฿<?php echo number_format($inv['totalAmount'], 2); ?>
              </td>
              <td class="px-6 py-4 text-center">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border <?php echo $statusColor; ?>">
                  <?php echo $statusText; ?>
                </span>
              </td>
              <td class="px-6 py-4 text-center no-print">
                <?php if($inv['status'] === 'Paid'): ?>
                  <a href="print_receipt.php?id=<?php echo $inv['invoiceId']; ?>" target="_blank" class="text-xs text-emerald-700 hover:text-emerald-900 font-bold bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-2.5 py-1 rounded-lg transition inline-flex items-center gap-1">
                    <i data-lucide="file-check" class="w-3.5 h-3.5"></i> ใบเสร็จ
                  </a>
                <?php else: ?>
                  <span class="text-slate-300">-</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; else: ?>
            <tr>
              <td colspan="7" class="px-6 py-12 text-center text-slate-400">ยังไม่มีรายการบิลในระบบ</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>

  <!-- 🌟 Chart.js Scripts 🌟 -->
  <script>
    lucide.createIcons();

    // Data from PHP
    const incomeLabels = <?php echo json_encode($incomeLabels); ?>;
    const incomeData = <?php echo json_encode($incomeData); ?>;
    const yearlyLabels = <?php echo json_encode($yearlyLabels); ?>;
    const yearlyData = <?php echo json_encode($yearlyData); ?>;
    const roomStats = [<?php echo $occupiedRooms; ?>, <?php echo $availableRooms; ?>];
    const reqStats = [<?php echo $pendingReqs; ?>, <?php echo $inProgressReqs; ?>, <?php echo $completedReqs; ?>];
    const breakdownData = [<?php echo $totalRent; ?>, <?php echo $totalWater; ?>, <?php echo $totalElectric; ?>, <?php echo $totalOther; ?>];

    // 1. กราฟแนวโน้มรายรับรายเดือน (Bar / Line)
    const ctxIncome = document.getElementById('incomeChart').getContext('2d');
    
    // Gradient fill สำหรับ Line Chart
    const gradientLine = ctxIncome.createLinearGradient(0, 0, 0, 250);
    gradientLine.addColorStop(0, 'rgba(59, 130, 246, 0.45)');
    gradientLine.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

    let currentChartType = 'bar';
    let incomeChart = new Chart(ctxIncome, {
      type: currentChartType,
      data: {
        labels: incomeLabels.length > 0 ? incomeLabels : ['ไม่มีข้อมูล'],
        datasets: [{
          label: 'รายรับ (บาท)',
          data: incomeData.length > 0 ? incomeData : [0],
          backgroundColor: currentChartType === 'bar' ? 'rgba(59, 130, 246, 0.85)' : gradientLine,
          borderColor: '#2563eb',
          borderWidth: 2,
          borderRadius: 8,
          fill: true,
          tension: 0.35,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: '#2563eb'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(context) {
                return ' รายรับ: ฿' + Number(context.raw).toLocaleString('th-TH', {minimumFractionDigits: 2});
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function(value) { return '฿' + value.toLocaleString(); },
              font: { family: 'Prompt', size: 11 }
            },
            grid: { color: 'rgba(226, 232, 240, 0.6)' }
          },
          x: {
            ticks: { font: { family: 'Prompt', size: 11 } },
            grid: { display: false }
          }
        }
      }
    });

    function setChartType(type) {
      if (type === currentChartType) return;
      currentChartType = type;
      
      const btnBar = document.getElementById('btnChartBar');
      const btnLine = document.getElementById('btnChartLine');

      if (type === 'bar') {
        btnBar.className = 'px-3 py-1 rounded-lg bg-white text-white shadow-xs transition';
        btnLine.className = 'px-3 py-1 rounded-lg text-slate-500 hover:text-white transition';
        incomeChart.config.type = 'bar';
        incomeChart.data.datasets[0].backgroundColor = 'rgba(59, 130, 246, 0.85)';
      } else {
        btnLine.className = 'px-3 py-1 rounded-lg bg-white text-white shadow-xs transition';
        btnBar.className = 'px-3 py-1 rounded-lg text-slate-500 hover:text-white transition';
        incomeChart.config.type = 'line';
        incomeChart.data.datasets[0].backgroundColor = gradientLine;
      }
      incomeChart.update();
    }

    // 2. กราฟโดนัท สัดส่วนโครงสร้างรายได้
    const ctxBreakdown = document.getElementById('breakdownChart').getContext('2d');
    new Chart(ctxBreakdown, {
      type: 'doughnut',
      data: {
        labels: ['ค่าเช่าห้อง', 'ค่าน้ำประปา', 'ค่าไฟฟ้า', 'ค่าอื่นๆ'],
        datasets: [{
          data: breakdownData,
          backgroundColor: ['#2563eb', '#06b6d4', '#f59e0b', '#a855f7'],
          borderWidth: 2,
          borderColor: '#ffffff',
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(context) {
                return ' ' + context.label + ': ฿' + Number(context.raw).toLocaleString('th-TH', {minimumFractionDigits: 2});
              }
            }
          }
        }
      }
    });

    // 3. กราฟโดนัท สถานะห้องพัก
    const ctxRoom = document.getElementById('roomChart').getContext('2d');
    new Chart(ctxRoom, {
      type: 'doughnut',
      data: {
        labels: ['มีผู้เช่า', 'ห้องว่าง'],
        datasets: [{
          data: roomStats,
          backgroundColor: ['#10b981', '#f59e0b'],
          borderWidth: 2,
          borderColor: '#ffffff',
          hoverOffset: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
          legend: { 
            position: 'bottom', 
            labels: { boxWidth: 10, font: { family: 'Prompt', size: 11 }, padding: 12 } 
          }
        }
      }
    });

    // 4. กราฟโดนัท งานบริการ
    const ctxReq = document.getElementById('reqChart').getContext('2d');
    new Chart(ctxReq, {
      type: 'doughnut',
      data: {
        labels: ['รอดำเนินการ', 'กำลังทำ', 'เสร็จสิ้น'],
        datasets: [{
          data: reqStats,
          backgroundColor: ['#f59e0b', '#3b82f6', '#10b981'],
          borderWidth: 2,
          borderColor: '#ffffff',
          hoverOffset: 4
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
          legend: { 
            position: 'bottom', 
            labels: { boxWidth: 10, font: { family: 'Prompt', size: 11 }, padding: 12 } 
          }
        }
      }
    });

    // 5. กราฟแท่ง รายรับรายปี
    const ctxYearly = document.getElementById('yearlyChart').getContext('2d');
    new Chart(ctxYearly, {
      type: 'bar',
      data: {
        labels: yearlyLabels.length > 0 ? yearlyLabels : ['2026'],
        datasets: [{
          label: 'รายรับรายปี (บาท)',
          data: yearlyData.length > 0 ? yearlyData : [0],
          backgroundColor: 'rgba(13, 148, 136, 0.85)',
          borderRadius: 6,
          barThickness: 32
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(context) {
                return ' รายรับ: ฿' + Number(context.raw).toLocaleString('th-TH', {minimumFractionDigits: 2});
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function(value) { return '฿' + value.toLocaleString(); },
              font: { family: 'Prompt', size: 10 }
            },
            grid: { color: 'rgba(226, 232, 240, 0.6)' }
          },
          x: {
            ticks: { font: { family: 'Prompt', size: 10 } },
            grid: { display: false }
          }
        }
      }
    });
  </script>
</body>
</html>