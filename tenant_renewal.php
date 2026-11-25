<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {
    header("Location: login.php");
    exit();
}

$tenantId = $_SESSION['user_id'];
$tenantName = $_SESSION['full_name'] ?? 'ผู้เช่า';
$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));
$message = "";

// สร้างตาราง LeaseRenewals อัตโนมัติ (ถ้ายังไม่มี)
$conn->exec("CREATE TABLE IF NOT EXISTS LeaseRenewals (
    renewalId INT AUTO_INCREMENT PRIMARY KEY,
    leaseId INT NOT NULL,
    tenantId INT NOT NULL,
    roomId INT NOT NULL,
    extensionMonths INT NOT NULL,
    status VARCHAR(50) DEFAULT 'Pending',
    adminRemark TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

// ดึงข้อมูลสัญญาเช่าปัจจุบัน (Active)
$stmtLease = $conn->prepare("SELECT l.*, r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? AND l.status = 'Active' LIMIT 1");
$stmtLease->execute([$tenantId]);
$activeLease = $stmtLease->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $activeLease ? ($activeLease['roomNumber'] ?? '') : '';
if (empty($myRoomNumber)) {
    $stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");
    $stmtHdrR->execute([$tenantId]);
    $myRoomNumber = $stmtHdrR->fetchColumn() ?: '';
}

$thaiMonthNames = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$formatThaiDateFull = function($dateStr, $includeTime = false) use ($thaiMonthNames) {
    if (!$dateStr) return '-';
    $t = strtotime($dateStr);
    $d = date('j', $t);
    $m = $thaiMonthNames[intval(date('n', $t))];
    $y = date('Y', $t) + 543;
    $res = "{$d} {$m} {$y}";
    if ($includeTime) {
        $res .= " เวลา " . date('H:i', $t) . " น.";
    }
    return $res;
};

// Real-time Status Check Endpoint (สำหรับ Polling)
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    if (!$activeLease) {
        echo json_encode(['status' => 'NoActiveLease']);
        exit();
    }
    $stmtCheckRealtime = $conn->prepare("SELECT status, adminRemark, created_at, extensionMonths, renewalId, leaseImage, oldLeaseImage FROM LeaseRenewals WHERE leaseId = ? ORDER BY renewalId DESC LIMIT 1");
    $stmtCheckRealtime->execute([$activeLease['leaseId']]);
    $latestReq = $stmtCheckRealtime->fetch(PDO::FETCH_ASSOC);
    
    $stmtLeaseFresh = $conn->prepare("SELECT endDate, startDate, leaseImage FROM Lease WHERE leaseId = ?");
    $stmtLeaseFresh->execute([$activeLease['leaseId']]);
    $freshLease = $stmtLeaseFresh->fetch(PDO::FETCH_ASSOC);

    $formattedEnd = '-';
    if ($freshLease && !empty($freshLease['endDate'])) {
        $formattedEnd = $formatThaiDateFull($freshLease['endDate']) . ' (' . date("d/m/Y", strtotime($freshLease['endDate'])) . ')';
    }

    echo json_encode([
        'status' => $latestReq ? $latestReq['status'] : 'None',
        'adminRemark' => $latestReq ? $latestReq['adminRemark'] : '',
        'newEndDate' => $formattedEnd,
        'rawEndDate' => $freshLease ? $freshLease['endDate'] : '',
        'extensionMonths' => $latestReq ? (int)$latestReq['extensionMonths'] : 0,
        'renewalId' => $latestReq ? $latestReq['renewalId'] : null,
        'newDoc' => $latestReq ? ($latestReq['leaseImage'] ?? '') : '',
        'oldDoc' => $latestReq ? ($latestReq['oldLeaseImage'] ?? ($freshLease['leaseImage'] ?? '')) : ''
    ]);
    exit();
}

$pendingRenewal = null;
$historyRenewals = [];
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'request';

if ($activeLease) {
    // 1. ดึงประวัติคำขอต่อสัญญาทั้งหมดของสัญญานี้
    $stmtHist = $conn->prepare("SELECT * FROM LeaseRenewals WHERE leaseId = ? ORDER BY renewalId DESC");
    $stmtHist->execute([$activeLease['leaseId']]);
    $historyRenewals = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    // 2. เช็คคำขอที่กำลังรออนุมัติ (Pending)
    $stmtCheck = $conn->prepare("SELECT * FROM LeaseRenewals WHERE leaseId = ? AND status = 'Pending' LIMIT 1");
    $stmtCheck->execute([$activeLease['leaseId']]);
    $pendingRenewal = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    // ระบบส่งคำขอต่อสัญญา
    if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_POST['submit_renewal'])) && !$pendingRenewal) {
        $extensionMonths = isset($_POST['extensionMonths']) ? (int)$_POST['extensionMonths'] : 6;
        
        $oldLeaseImg = $activeLease['leaseImage'] ?? null;
        $stmtInsert = $conn->prepare("INSERT INTO LeaseRenewals (leaseId, tenantId, roomId, extensionMonths, oldLeaseImage) VALUES (?, ?, ?, ?, ?)");
        if ($stmtInsert->execute([$activeLease['leaseId'], $tenantId, $activeLease['roomId'], $extensionMonths, $oldLeaseImg])) {
            $message = "<div id='flashAlert' class='p-4 mb-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>
                <div class='flex items-center gap-2'>
                    <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i>
                    <span>ส่งคำขอต่อสัญญาเรียบร้อยแล้ว อยู่ระหว่างกำลังรอดำเนินการ</span>
                </div>
                <button onclick='this.parentElement.remove()' class='text-emerald-500 hover:text-emerald-700 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>
            </div>";
            
            $stmtCheck->execute([$activeLease['leaseId']]);
            $pendingRenewal = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// คำนวณวันสิ้นสุดสัญญาฉบับใหม่จาก MySQL DATE_ADD
$calcCurEndDate = $activeLease['endDate'] ?? date('Y-m-d');
$calcExtMonths = intval($pendingRenewal['extensionMonths'] ?? 3);
$stmtExactEnd = $conn->prepare("SELECT DATE_ADD(?, INTERVAL ? MONTH) AS exactEnd");
$stmtExactEnd->execute([$calcCurEndDate, $calcExtMonths]);
$exactNewEndDate = $stmtExactEnd->fetchColumn() ?: date('Y-m-d', strtotime("+{$calcExtMonths} month", strtotime($calcCurEndDate)));

$displayNewEndFull = $formatThaiDateFull($exactNewEndDate);
$displayCurEndFull = $formatThaiDateFull($calcCurEndDate);
$displayReqCreatedFull = !empty($pendingRenewal['created_at']) ? $formatThaiDateFull($pendingRenewal['created_at'], true) : '-';
$extMonthsLabel = ($calcExtMonths == 12) ? '1 ปี (12 เดือน)' : $calcExtMonths . ' เดือน';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>ต่ออายุสัญญาเช่า - Jasmin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <style>
    body { font-family: 'Prompt', sans-serif; background-color: #FAF9F6; color: #1e293b; }
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>

<body class="pb-24 bg-[#FAF9F6] text-slate-800">

  <!-- 🌟 แท็บด้านซ้ายมือ สำหรับผู้เช่าพักอาศัย (Tenant Sidebar) 🌟 -->
  <?php include_once 'includes/tenant_sidebar.php'; ?>

  <!-- Header -->
  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100/80 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer mr-1" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          
          <a href="tenant_dashboard.php" class="w-10 h-10 rounded-xl bg-rose-50/30 hover:bg-slate-100 border border-rose-100/80 text-slate-600 hover:text-slate-900 transition flex items-center justify-center shrink-0 group" title="กลับแดชบอร์ด">
            <i data-lucide="arrow-left" class="w-5 h-5 group-hover:-translate-x-0.5 transition-transform"></i>
          </a>

          <div>
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ขอต่ออายุสัญญาเช่า</p>
          </div>
        </div>

        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

          <a href="profile.php" class="flex items-center gap-2 p-1 sm:p-1.5 sm:px-2.5 hover:bg-rose-50/70 rounded-full transition border border-transparent hover:border-rose-200 group" title="แก้ไขโปรไฟล์">
            <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs border border-rose-200 shadow-2xs shrink-0 group-hover:scale-105 transition">
              <?php echo $initials; ?>
            </div>
            <div class="hidden sm:block text-left pr-1">
              <div class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition truncate max-w-[140px] sm:max-w-[180px]"><?php echo htmlspecialchars($tenantName); ?></div>
              <div class="text-[10px] text-rose-600 font-bold"><?php echo !empty($myRoomNumber) ? "ห้อง " . htmlspecialchars($myRoomNumber) : 'ลูกบ้าน'; ?></div>
            </div>
          </a>

          <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 hover:text-rose-700 border border-rose-200 text-xs font-bold transition shadow-2xs cursor-pointer shrink-0" title="ออกจากระบบ">
            <i data-lucide="log-out" class="w-4 h-4"></i>
            <span class="hidden sm:inline">ออกจากระบบ</span>
          </a>
        </div>
      </div>
    </div>
  </header>

  <main class="max-w-md mx-auto p-4 mt-2">
      <!-- 🌟 Top Hero Banner 🌟 -->
      <div class="p-5 sm:p-6 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-5">
        <div class="flex items-center gap-2 flex-wrap mb-2">
          <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
            <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-rose-500"></i>
            <span>ต่ออายุสัญญาเช่า</span>
          </span>
          <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
            <i data-lucide="key" class="w-3.5 h-3.5 text-amber-500"></i>
            <span>ห้อง <?php echo htmlspecialchars($activeLease['roomNumber'] ?? '-'); ?></span>
          </span>
        </div>
        <h1 class="text-xl sm:text-2xl font-display font-extrabold text-slate-900 tracking-tight">
          ยื่นคำขอต่ออายุสัญญาเช่า
        </h1>
        <p class="text-xs text-slate-500 mt-1">
          เลือกระยะเวลาที่ต้องการต่อสัญญา พร้อมส่งคำขอให้นิติบุคคลอนุมัติ
        </p>
      </div>

      <!-- ข้อความแจ้งเตือน -->
      <?php echo $message; ?>

      <?php if(!$activeLease): ?>
          <div class="bg-rose-50 text-rose-600 p-5 rounded-2xl text-sm font-medium border border-rose-200 shadow-xs">
            ⚠️ ไม่พบสัญญาเช่าที่ใช้งานอยู่ หากต้องการต่อสัญญาเช่า กรุณาติดต่อผู้ดูแลหอพัก
          </div>
      <?php else: ?>

          <!-- สรุปสัญญาปัจจุบัน -->
          <div class="bg-white p-5 rounded-3xl shadow-sm border border-rose-100/80 mb-5">
              <div class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-2">ข้อมูลสัญญาปัจจุบัน</div>
              <div class="flex justify-between items-center">
                  <div>
                      <div class="text-2xl font-bold text-slate-800">ห้อง <?php echo htmlspecialchars($activeLease['roomNumber']); ?></div>
                      <div class="text-xs text-slate-500 mt-1">
                        สิ้นสุดสัญญา: <span class="font-bold text-rose-600 font-mono"><?php echo date("d/m/Y", strtotime($activeLease['endDate'])); ?></span>
                      </div>
                      <?php if(!empty($activeLease['leaseImage'])): ?>
                        <div class="mt-2.5">
                          <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($activeLease['leaseImage'], ENT_QUOTES); ?>')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 rounded-xl text-xs font-bold transition shadow-2xs cursor-pointer">
                            <i data-lucide="file-text" class="w-3.5 h-3.5 text-emerald-600"></i> ดูเอกสารสัญญาเช่าปัจจุบัน 📄
                          </button>
                        </div>
                      <?php endif; ?>
                  </div>
                  <div class="w-12 h-12 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center shadow-xs">
                    <i data-lucide="file-text" class="w-6 h-6"></i>
                  </div>
              </div>
          </div>

          <!-- 🌟 แท็บสลับหน้า: คำขอต่อสัญญา vs ประวัติคำขอ 🌟 -->
          <div class="mb-5 flex gap-2 border-b border-rose-100/80 pb-px overflow-x-auto hide-scrollbar">
              <!-- แท็บ 1: คำขอต่อสัญญา -->
              <button type="button" onclick="switchRenewalTab('request')" id="tab-renewal-request" class="<?php echo ($activeTab === 'request') ? 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
                  <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                  <span>คำขอต่อสัญญา</span>
                  <?php if($pendingRenewal): ?>
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold bg-amber-100 text-amber-800 rounded-full border border-amber-300">
                          <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                          รออนุมัติ
                      </span>
                  <?php endif; ?>
              </button>

              <!-- แท็บ 2: ประวัติคำขอ -->
              <button type="button" onclick="switchRenewalTab('history')" id="tab-renewal-history" class="<?php echo ($activeTab === 'history') ? 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
                  <i data-lucide="history" class="w-4 h-4"></i>
                  <span>ประวัติคำขอ</span>
                  <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-slate-100 text-slate-600 rounded-full">
                      <?php echo count($historyRenewals); ?>
                  </span>
              </button>
          </div>

          <!-- ================= TAB 1: คำขอต่อสัญญา ================= -->
          <div id="content-renewal-request" class="<?php echo ($activeTab === 'request') ? '' : 'hidden'; ?>">
              <?php if($pendingRenewal): ?>
                  <!-- 🌟 การ์ดแสดงคำขอที่รอดำเนินการ (แสดงแค่พอคราวๆ ให้เห็น พอกดเข้าไปถึงเป็นรายละเอียดเต็ม) 🌟 -->
                  <div onclick="openPendingRenewalModal()" 
                       class="cursor-pointer transition-all duration-200 hover:shadow-lg hover:border-amber-400 select-none bg-gradient-to-br from-amber-50/70 via-white to-orange-50/40 text-slate-800 p-5 rounded-3xl border border-amber-200 shadow-xs mb-6 space-y-3.5 group">
                      
                      <!-- Header แถบสถานะแบบย่อ -->
                      <div class="flex items-center justify-between border-b border-amber-100/80 pb-2.5">
                          <div class="flex items-center gap-2">
                              <span class="relative flex h-2.5 w-2.5">
                                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                              </span>
                              <span class="font-bold text-sm text-slate-800">คำขอต่อสัญญาเช่า (ห้อง <?php echo htmlspecialchars($activeLease['roomNumber']); ?>)</span>
                          </div>
                          <span id="pendingRenewalCardStatus" class="px-2.5 py-0.5 bg-amber-100 text-amber-900 border border-amber-300 rounded-full font-bold text-[11px] shadow-2xs">
                              ⏳ รอแอดมินอนุมัติ
                          </span>
                      </div>

                      <!-- ข้อมูลสรุปพอคราวๆ (2 ช่อง) -->
                      <div class="grid grid-cols-2 gap-2.5 bg-amber-50/60 p-3 rounded-2xl border border-amber-100 text-xs">
                          <div>
                              <span class="text-slate-500 text-[11px] flex items-center gap-1 mb-0.5">
                                  <i data-lucide="clock" class="w-3 h-3 text-amber-600"></i> ขอต่อสัญญาเพิ่ม:
                              </span>
                              <strong class="text-amber-900 font-bold text-sm"><?php echo $extMonthsLabel; ?></strong>
                          </div>
                          <div>
                              <span class="text-slate-500 text-[11px] flex items-center gap-1 mb-0.5">
                                  <i data-lucide="calendar" class="w-3 h-3 text-amber-600"></i> วันที่ยื่นคำขอ:
                              </span>
                              <span class="font-mono text-slate-800 font-bold"><?php echo date("d/m/Y H:i", strtotime($pendingRenewal['created_at'])); ?> น.</span>
                          </div>
                      </div>

                      <!-- แถบล่างชวนคลิกดูรายละเอียด -->
                      <div class="flex items-center justify-between pt-1 text-xs">
                          <span class="text-slate-500 text-[11px] flex items-center gap-1">
                              <i data-lucide="info" class="w-3.5 h-3.5 text-amber-600"></i>
                              <span>คลิกที่นี่เพื่อดูรายละเอียดสัญญา & ขั้นตอน</span>
                          </span>
                          <span class="inline-flex items-center gap-1 px-3 py-1 bg-amber-100/80 group-hover:bg-amber-500 group-hover:text-white text-amber-900 font-bold rounded-xl text-xs transition-colors">
                              <span>ดูรายละเอียด</span>
                              <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                          </span>
                      </div>
                  </div>

              <?php else: ?>
                  <!-- 🌟 ฟอร์มยื่นคำขอต่อสัญญาเช่า 🌟 -->
                  <div class="bg-white p-6 rounded-3xl shadow-[0_4px_24px_-4px_rgba(244,63,94,0.08)] border border-rose-200/80 mb-6">
                      <div class="flex items-center gap-2.5 mb-4 pb-3.5 border-b border-rose-100/80">
                        <div class="w-9 h-9 rounded-2xl bg-gradient-to-br from-rose-500 to-pink-500 text-white flex items-center justify-center shadow-xs">
                          <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        </div>
                        <div>
                          <h3 class="text-base font-bold text-slate-800 font-display">ยื่นคำขอต่ออายุสัญญาเช่า</h3>
                          <p class="text-xs text-slate-400">เลือกระยะเวลาที่ต้องการต่อสัญญาเพิ่ม ระบบจะคำนวณวันสิ้นสุดสัญญาให้อัตโนมัติ</p>
                        </div>
                      </div>

                      <form action="" method="POST" id="renewalForm" class="space-y-4">
                          <input type="hidden" name="submit_renewal" value="1">
                          <input type="hidden" name="extensionMonths" id="extensionMonthsInput" value="6">
                          
                          <div>
                              <label class="block text-xs font-bold text-slate-700 mb-2">เลือกระยะเวลาต่อสัญญา:</label>
                              <div class="relative" id="customRenewalDropdown">
                                  <button type="button" onclick="toggleRenewalDropdown(event)" id="renewalDropdownTrigger"
                                          class="w-full px-4 py-3.5 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 rounded-2xl flex items-center justify-between text-sm font-bold text-slate-800 shadow-xs transition-all duration-200 cursor-pointer">
                                      <div class="flex items-center gap-3">
                                          <div class="w-8 h-8 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">
                                              <i data-lucide="calendar" class="w-4 h-4"></i>
                                          </div>
                                          <span id="triggerTitle" class="text-sm font-bold text-slate-800">ต่อเพิ่ม 6 เดือน</span>
                                      </div>
                                      <i data-lucide="chevron-down" id="triggerChevron" class="w-4 h-4 text-rose-500 transition-transform duration-200"></i>
                                  </button>

                                  <div id="renewalDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-2 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-2xl shadow-xl shadow-rose-950/10 p-2 space-y-1 transition-all duration-200">
                                      <div onclick="selectRenewalOption(3, 'ต่อเพิ่ม 3 เดือน', event)"
                                           class="renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all hover:bg-rose-50 text-slate-700 hover:text-rose-700 font-semibold border border-transparent"
                                           data-value="3">
                                          <span class="text-sm">ต่อเพิ่ม 3 เดือน</span>
                                          <span class="check-icon hidden w-5 h-5 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">✓</span>
                                      </div>

                                      <div onclick="selectRenewalOption(6, 'ต่อเพิ่ม 6 เดือน', event)"
                                           class="renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all bg-rose-50 text-rose-700 font-bold border border-rose-200/70"
                                           data-value="6">
                                          <span class="text-sm">ต่อเพิ่ม 6 เดือน</span>
                                          <span class="check-icon w-5 h-5 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">✓</span>
                                      </div>

                                      <div onclick="selectRenewalOption(12, 'ต่อเพิ่ม 1 ปี (12 เดือน)', event)"
                                           class="renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all hover:bg-rose-50 text-slate-700 hover:text-rose-700 font-semibold border border-transparent"
                                           data-value="12">
                                          <span class="text-sm">ต่อเพิ่ม 1 ปี (12 เดือน)</span>
                                          <span class="check-icon hidden w-5 h-5 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">✓</span>
                                      </div>
                                  </div>
                              </div>
                          </div>

                          <div id="newEndDatePreview" class="p-3.5 bg-gradient-to-r from-rose-50 via-pink-50/70 to-rose-50 border border-rose-200/90 rounded-2xl flex items-center justify-between text-xs text-rose-950 shadow-2xs">
                              <span class="flex items-center gap-1.5 font-medium">
                                  <i data-lucide="calendar-check" class="w-4 h-4 text-rose-600"></i>
                                  <span>วันสิ้นสุดสัญญาใหม่โดยประมาณ:</span>
                              </span>
                              <strong id="previewNewDateText" class="font-mono text-rose-700 font-extrabold text-xs sm:text-sm bg-white/80 px-2.5 py-1 rounded-xl border border-rose-200">
                                  กำลังคำนวณ...
                              </strong>
                          </div>

                          <button type="submit" name="submit_renewal" class="w-full py-3.5 bg-gradient-to-r from-rose-500 via-rose-600 to-pink-600 hover:from-rose-600 hover:to-pink-700 text-white font-bold rounded-2xl shadow-lg shadow-rose-500/25 flex items-center justify-center gap-2 text-sm transition-all duration-200 cursor-pointer active:scale-[0.98]">
                              <i data-lucide="send" class="w-4 h-4"></i> ส่งคำขอให้นิติบุคคลอนุมัติ
                          </button>
                      </form>
                  </div>
              <?php endif; ?>
          </div>

          <!-- ================= TAB 2: ประวัติคำขอ ================= -->
          <div id="content-renewal-history" class="<?php echo ($activeTab === 'history') ? '' : 'hidden'; ?>">
              <?php if(empty($historyRenewals)): ?>
                  <div class="p-8 text-center bg-white rounded-3xl border border-rose-100 text-slate-400 text-xs shadow-xs">
                      <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-2">
                          <i data-lucide="clock" class="w-6 h-6"></i>
                      </div>
                      <p class="font-bold text-slate-600">ยังไม่มีประวัติคำขอต่อสัญญาเช่า</p>
                      <p class="text-[11px] text-slate-400 mt-1">เมื่อคุณส่งคำขอต่อสัญญา ประวัติจะแสดงที่นี่</p>
                  </div>
              <?php else: ?>
                  <div class="space-y-3">
                      <?php foreach($historyRenewals as $h): 
                          $hExt = (int)$h['extensionMonths'];
                          $hExtLabel = ($hExt == 12) ? '1 ปี (12 เดือน)' : $hExt . ' เดือน';
                          $hCreated = date("d/m/Y H:i", strtotime($h['created_at'])) . " น.";
                          $hStatus = $h['status'];
                          $newDocFile = !empty($h['leaseImage']) ? $h['leaseImage'] : (($hStatus === 'Approved' && !empty($activeLease['leaseImage'])) ? $activeLease['leaseImage'] : '');
                          $oldDocFile = !empty($h['oldLeaseImage']) ? $h['oldLeaseImage'] : ($activeLease['leaseImage'] ?? '');
                      ?>
                      <!-- การ์ดประวัติแบบพอคราวๆ (คลิกแล้วเปิดดูรายละเอียดเต็ม) -->
                      <div onclick='openRenewalModal({
                          roomNumber: "<?php echo htmlspecialchars($activeLease["roomNumber"]); ?>",
                          tenantName: "<?php echo htmlspecialchars($tenantName); ?>",
                          extensionMonths: <?php echo $hExt; ?>,
                          createdAt: "<?php echo $hCreated; ?>",
                          curEndDate: "<?php echo !empty($activeLease["startDate"]) ? date("d/m/Y", strtotime($activeLease["startDate"])) : "-"; ?>",
                          newEndDate: "<?php echo !empty($activeLease["endDate"]) ? date("d/m/Y", strtotime($activeLease["endDate"])) : "-"; ?>",
                          status: "<?php echo $hStatus; ?>",
                          adminRemark: "<?php echo addslashes($h["adminRemark"] ?? ""); ?>",
                          oldDoc: "<?php echo htmlspecialchars($oldDocFile, ENT_QUOTES); ?>",
                          newDoc: "<?php echo htmlspecialchars($newDocFile, ENT_QUOTES); ?>"
                      })' class="p-4 rounded-2xl border border-stone-200/80 bg-white hover:border-amber-300 hover:shadow-xs transition-all cursor-pointer flex items-center justify-between gap-3 text-xs group">
                          <div>
                              <div class="font-bold text-slate-800 text-sm flex items-center gap-1.5">
                                  <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-rose-500"></i>
                                  <span>ขอต่อสัญญาเพิ่ม <?php echo $hExtLabel; ?></span>
                              </div>
                              <div class="text-[11px] text-slate-400 mt-1 font-mono">ยื่นคำขอ: <?php echo $hCreated; ?></div>
                              <div class="mt-2 flex flex-wrap items-center gap-1.5" onclick="event.stopPropagation()">
                                  <?php if(!empty($oldDocFile)): ?>
                                      <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($oldDocFile, ENT_QUOTES); ?>', 'สัญญาเดิม - ห้อง <?php echo htmlspecialchars($activeLease['roomNumber']); ?>')" class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 rounded-lg text-[10px] font-bold transition shadow-2xs">
                                          <i data-lucide="file-text" class="w-3 h-3 text-slate-500"></i> ดูสัญญาเดิม 📄
                                      </button>
                                  <?php endif; ?>
                                  <?php if(!empty($newDocFile)): ?>
                                      <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($newDocFile, ENT_QUOTES); ?>', 'สัญญาใหม่ - ห้อง <?php echo htmlspecialchars($activeLease['roomNumber']); ?>')" class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 rounded-lg text-[10px] font-bold transition shadow-2xs">
                                          <i data-lucide="file-check-2" class="w-3 h-3 text-emerald-600"></i> ดูสัญญาใหม่ที่แนบมา 📄
                                      </button>
                                  <?php endif; ?>
                              </div>
                          </div>
                          <div class="flex items-center gap-2 shrink-0">
                              <?php if($hStatus === 'Approved'): ?>
                                  <span class="px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-full font-bold text-[10px] inline-flex items-center gap-1">
                                      <i data-lucide="check" class="w-3 h-3"></i> อนุมัติแล้ว
                                  </span>
                              <?php elseif($hStatus === 'Pending'): ?>
                                  <span class="px-2.5 py-1 bg-amber-100 text-amber-800 rounded-full font-bold text-[10px] inline-flex items-center gap-1">
                                      <i data-lucide="clock" class="w-3 h-3"></i> รอพิจารณา
                                  </span>
                              <?php else: ?>
                                  <span class="px-2.5 py-1 bg-rose-100 text-rose-800 rounded-full font-bold text-[10px] inline-flex items-center gap-1">
                                      <i data-lucide="x" class="w-3 h-3"></i> ไม่อนุมัติ
                                  </span>
                              <?php endif; ?>
                              <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 group-hover:translate-x-0.5 transition-transform"></i>
                          </div>
                      </div>
                      <?php endforeach; ?>
                  </div>
              <?php endif; ?>
          </div>

      <?php endif; ?>
  </main>

  <!-- 🌟 MODAL: แสดงรายละเอียดคำขอต่อสัญญาและขั้นตอนการดำเนินงานแบบเต็มหน้าจอ 🌟 -->
  <div id="renewalDetailModal" class="hidden fixed inset-0 z-50 bg-white flex flex-col w-full h-full min-h-screen overflow-hidden">
      <!-- Modal Header (Full Width Top Bar) -->
      <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 text-white px-5 py-3.5 sm:px-8 sm:py-4.5 flex items-center justify-between shrink-0 shadow-md">
          <div class="flex items-center gap-3">
              <button type="button" onclick="closeRenewalModal()" class="w-9 h-9 rounded-2xl bg-black/20 hover:bg-black/35 text-white flex items-center justify-center transition cursor-pointer shadow-xs shrink-0" title="ย้อนกลับ">
                  <i data-lucide="arrow-left" class="w-5 h-5"></i>
              </button>
              <div>
                  <div class="flex flex-wrap items-center gap-2 mb-1">
                      <span class="px-2.5 py-0.5 rounded-full bg-white/20 text-white text-[11px] font-bold tracking-wide backdrop-blur-xs flex items-center gap-1 shadow-xs">
                          <i data-lucide="refresh-cw" class="w-3 h-3"></i> คำขอต่ออายุสัญญา
                      </span>
                      <span id="modalStatusBadge" class="px-2.5 py-0.5 rounded-full bg-white text-amber-900 text-[11px] font-bold shadow-xs">
                          ⏳ รอแอดมินอนุมัติ
                      </span>
                      <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/25 border border-white/30 text-white text-[11px] font-semibold flex items-center gap-1.5 backdrop-blur-xs">
                          <span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse"></span> อัปเดตสดเรียลไทม์
                      </span>
                  </div>
                  <h2 id="modalTitle" class="text-lg sm:text-2xl font-extrabold font-display tracking-tight text-white leading-tight">รายละเอียดคำขอต่อสัญญาเช่า</h2>
                  <p id="modalSubtitle" class="text-xs sm:text-sm text-amber-100 mt-0.5 flex items-center gap-2">
                      <span>ห้อง <strong id="modalRoomNum" class="text-white">...</strong></span>
                      <span>•</span>
                      <span>ผู้เช่า: <strong id="modalTenantName" class="text-white">...</strong></span>
                  </p>
              </div>
          </div>
          <button type="button" onclick="closeRenewalModal()" class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-xl text-white font-bold text-xs transition backdrop-blur-xs cursor-pointer shadow-xs">
              <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
          </button>
      </div>

      <!-- Modal Body (Full Width Scrollable, Centered Content) -->
      <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-gradient-to-b from-amber-50/30 via-slate-50/50 to-white">
          <div class="max-w-4xl lg:max-w-5xl mx-auto w-full space-y-6">
              
              <!-- 🌟 Progress Stepper (ขั้นตอนการดำเนินงานแบบกว้างเต็มตา) 🌟 -->
              <div class="p-5 sm:p-6 bg-gradient-to-br from-amber-50/90 via-orange-50/40 to-amber-50/90 rounded-3xl border border-amber-200/90 shadow-xs space-y-4">
                  <div class="flex flex-wrap items-center justify-between gap-2 text-xs sm:text-sm font-bold text-amber-950">
                      <span class="flex items-center gap-2">
                          <i data-lucide="milestone" class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600"></i>
                          <span>สถานะขั้นตอนการดำเนินงาน:</span>
                      </span>
                      <span id="modalStepIndicator" class="text-amber-900 bg-amber-200/80 px-3 py-1 rounded-full text-xs font-extrabold border border-amber-300/60 shadow-2xs">
                          ขั้นตอนที่ 2 จาก 3 (กำลังดำเนินการ)
                      </span>
                  </div>

                  <!-- 3 Steps Bar (3 Columns on SM+, spacious layout) -->
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 text-center">
                      <div id="step1Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs">
                          <span id="step1Icon" class="w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs">✓</span>
                          <span class="font-bold text-xs sm:text-sm">1. ยื่นคำขอแล้ว</span>
                          <span id="step1Time" class="text-[11px] text-emerald-700/80 font-normal">บันทึกคำขอเข้าระบบ</span>
                      </div>
                      <div id="step2Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-amber-100 border-amber-400 text-amber-950 shadow-xs ring-2 ring-amber-300/60 transition-all">
                          <span id="step2Icon" class="w-9 h-9 rounded-full bg-amber-600 text-white flex items-center justify-center text-sm font-bold animate-pulse shadow-xs">⏳</span>
                          <span class="font-bold text-xs sm:text-sm">2. ตรวจสอบร่างสัญญา</span>
                          <span id="step2Time" class="text-[11px] text-amber-800 font-bold">กำลังดำเนินการ</span>
                      </div>
                      <div id="step3Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-stone-50 border-stone-200 text-stone-400 transition-all">
                          <span id="step3Icon" class="w-9 h-9 rounded-full bg-stone-200 text-stone-500 flex items-center justify-center text-sm font-bold">3</span>
                          <span class="font-bold text-xs sm:text-sm">3. อนุมัติสัญญาใหม่</span>
                          <span id="step3Time" class="text-[11px] text-stone-400 font-normal">รออนุมัติ</span>
                      </div>
                  </div>

                  <!-- Step Description Box -->
                  <div id="modalStepDesc" class="p-4 bg-white/95 rounded-2xl border border-amber-200/90 text-xs sm:text-sm text-amber-950 leading-relaxed shadow-2xs flex items-start gap-2.5">
                      <i data-lucide="info" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
                      <div id="modalStepDescText">
                          กำลังรอนิติบุคคลและแอดมินตรวจสอบรายละเอียดคำขอต่อสัญญาเช่า และจัดเตรียมร่างสัญญาฉบับใหม่
                      </div>
                  </div>
              </div>

              <!-- 🌟 ตารางข้อมูลรายละเอียดสัญญาและวันสิ้นสุด (Grid 2 คอลัมน์) 🌟 -->
              <div class="bg-white rounded-3xl border border-stone-200/90 shadow-xs overflow-hidden">
                  <div class="p-4 bg-stone-50 border-b border-stone-200/80 flex items-center gap-2">
                      <i data-lucide="file-spreadsheet" class="w-4 h-4 text-slate-500"></i>
                      <h4 class="text-xs sm:text-sm font-bold text-slate-700">ข้อมูลสรุปคำขอต่อสัญญาเช่า</h4>
                  </div>
                  
                  <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-stone-100 text-xs sm:text-sm">
                      <div class="p-4 sm:p-5 space-y-4">
                          <div class="flex justify-between items-center">
                              <span class="text-slate-500 flex items-center gap-2"><i data-lucide="door-closed" class="w-4 h-4 text-slate-400"></i> ห้องพักที่ขอต่อสัญญา</span>
                              <strong id="modalInfoRoom" class="text-slate-800 text-sm sm:text-base font-bold">ห้อง ...</strong>
                          </div>
                          <div class="flex justify-between items-center">
                              <span class="text-slate-500 flex items-center gap-2"><i data-lucide="user" class="w-4 h-4 text-slate-400"></i> ผู้ยื่นคำขอ</span>
                              <strong id="modalInfoTenant" class="text-slate-800 font-semibold">...</strong>
                          </div>
                          <div class="flex justify-between items-center">
                              <span class="text-slate-500 flex items-center gap-2"><i data-lucide="clock" class="w-4 h-4 text-amber-600"></i> ระยะเวลาที่ขอต่อสัญญา</span>
                              <strong id="modalInfoMonths" class="text-amber-800 bg-amber-50 px-3 py-1 rounded-xl font-bold text-xs sm:text-sm border border-amber-200">3 เดือน</strong>
                          </div>
                      </div>
                      
                      <div class="p-4 sm:p-5 space-y-4">
                          <div class="flex justify-between items-center">
                              <span class="text-slate-500 flex items-center gap-2"><i data-lucide="calendar" class="w-4 h-4 text-slate-400"></i> วันเวลาที่ยื่นคำขอ</span>
                              <span id="modalInfoCreatedAt" class="font-mono text-slate-800 font-bold">...</span>
                          </div>
                          <div class="flex justify-between items-center">
                              <span class="text-slate-500 flex items-center gap-2"><i data-lucide="calendar-x" class="w-4 h-4 text-slate-400"></i> วันสิ้นสุดสัญญาเดิม</span>
                              <span id="modalInfoCurEnd" class="font-mono text-slate-700 font-semibold">...</span>
                          </div>
                      </div>
                  </div>

                  <!-- Highlighted New Expiration Date (Spans Full Width) -->
                  <div class="p-4 sm:p-5 bg-gradient-to-r from-rose-50 via-pink-50/60 to-rose-50 border-t border-rose-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                      <div class="flex items-center gap-2.5 text-rose-900 font-bold text-xs sm:text-sm">
                          <div class="w-9 h-9 rounded-2xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-2xs">
                              <i data-lucide="sparkles" class="w-5 h-5"></i>
                          </div>
                          <div>
                              <div class="text-sm font-extrabold">สัญญาที่ยื่นขอจะสิ้นสุดวันที่:</div>
                              <span class="text-[11px] text-rose-700/80 font-normal">คำนวณตามระยะเวลาที่ขอต่อสัญญา</span>
                          </div>
                      </div>
                      <strong id="modalInfoNewEnd" class="text-rose-700 font-extrabold text-sm sm:text-base font-mono bg-white px-4 py-2.5 rounded-2xl border border-rose-200/90 shadow-2xs text-center">
                          ...
                      </strong>
                  </div>
              </div>

              <!-- Admin Remark (if any) -->
              <div id="modalRemarkWrap" class="hidden p-4 sm:p-5 bg-white rounded-3xl border border-slate-200/90 text-xs sm:text-sm shadow-xs space-y-2">
                  <div class="text-xs font-bold text-slate-600 flex items-center gap-2">
                      <i data-lucide="message-square" class="w-4 h-4 text-slate-500"></i> หมายเหตุจากนิติบุคคล:
                  </div>
                  <div id="modalRemarkText" class="text-slate-800 font-medium pl-6 leading-relaxed"></div>
              </div>

              <!-- 🌟 ส่วนเอกสารสัญญาเช่า (Documents: สัญญาเดิม & สัญญาฉบับใหม่ที่แนบมา) 🌟 -->
              <div class="bg-white rounded-3xl border border-stone-200/90 shadow-xs overflow-hidden p-5 sm:p-6 space-y-4">
                  <div class="flex items-center gap-2 pb-3 border-b border-stone-100 text-slate-800 font-bold text-xs sm:text-sm">
                      <i data-lucide="files" class="w-4 h-4 text-rose-500"></i>
                      <span>เอกสารสัญญาเช่า (สัญญาเดิม & สัญญาฉบับใหม่)</span>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <!-- กล่องที่ 1: สัญญาเดิม -->
                      <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-3 flex flex-col justify-between">
                          <div>
                              <div class="flex items-center justify-between mb-2">
                                  <span class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                      <i data-lucide="file-text" class="w-4 h-4 text-slate-500"></i> เอกสารสัญญาเดิม
                                  </span>
                                  <span class="text-[10px] bg-slate-200 text-slate-700 font-bold px-2 py-0.5 rounded-lg">สัญญาเดิม</span>
                              </div>
                              <div id="modalTenantOldDocBox" class="min-h-[110px] flex items-center justify-center bg-white rounded-xl border border-dashed border-slate-200 p-2 text-xs text-slate-400">
                                  ไม่มีเอกสารสัญญาเดิม
                              </div>
                          </div>
                          <div id="modalTenantOldDocBtnWrap"></div>
                      </div>

                      <!-- กล่องที่ 2: สัญญาฉบับใหม่ที่แนบมา -->
                      <div class="p-4 rounded-2xl bg-emerald-50/40 border border-emerald-200/80 space-y-3 flex flex-col justify-between">
                          <div>
                              <div class="flex items-center justify-between mb-2">
                                  <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">
                                      <i data-lucide="file-check-2" class="w-4 h-4 text-emerald-600"></i> สัญญาฉบับใหม่ (ที่นิติบุคคลแนบให้)
                                  </span>
                                  <span id="modalTenantNewDocBadge" class="text-[10px] bg-emerald-100 text-emerald-800 font-bold px-2 py-0.5 rounded-lg border border-emerald-200">สัญญาใหม่</span>
                              </div>
                              <div id="modalTenantNewDocBox" class="min-h-[110px] flex items-center justify-center bg-white rounded-xl border border-dashed border-emerald-200 p-2 text-xs text-slate-400">
                                  รออนุมัติสัญญา
                              </div>
                          </div>
                          <div id="modalTenantNewDocBtnWrap"></div>
                      </div>
                  </div>
              </div>
          </div>
      </div>

      <!-- Modal Footer (Full Width Bottom Bar) -->
      <div class="px-5 py-3 sm:px-8 sm:py-3.5 bg-white border-t border-slate-200/90 shrink-0 flex items-center justify-between shadow-lg">
          <span class="text-xs text-slate-500 flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
              <span class="font-medium">ระบบกำลังเชื่อมต่อและอัปเดตสถานะอัตโนมัติ</span>
          </span>
          <button type="button" onclick="closeRenewalModal()" class="px-6 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl text-xs sm:text-sm transition cursor-pointer shadow-sm active:scale-95 flex items-center gap-1.5">
              <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
          </button>
      </div>
  </div>

  <?php include_once 'includes/doc_viewer_modal.php'; ?>

  <script>
    lucide.createIcons();

    // 🌟 ระบบสลับแท็บ: คำขอต่อสัญญา vs ประวัติคำขอ 🌟
    function switchRenewalTab(tab) {
        const tabReq = document.getElementById('tab-renewal-request');
        const tabHist = document.getElementById('tab-renewal-history');
        const contentReq = document.getElementById('content-renewal-request');
        const contentHist = document.getElementById('content-renewal-history');

        if (tab === 'request') {
            tabReq.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            tabHist.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            contentReq.classList.remove('hidden');
            contentHist.classList.add('hidden');
        } else {
            tabHist.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            tabReq.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            contentHist.classList.remove('hidden');
            contentReq.classList.add('hidden');
        }
        if (window.lucide) lucide.createIcons();
    }

    // 🌟 ระบบ Modal รายละเอียดคำขอต่อสัญญาเช่า & ไทม์ไลน์ขั้นตอน 🌟
    let currentModalRenewalData = null;

    function openPendingRenewalModal() {
        openRenewalModal({
            roomNumber: '<?php echo htmlspecialchars($activeLease['roomNumber'] ?? ''); ?>',
            tenantName: '<?php echo htmlspecialchars($tenantName); ?>',
            extensionMonths: <?php echo intval($pendingRenewal['extensionMonths'] ?? 3); ?>,
            createdAt: '<?php echo !empty($pendingRenewal['created_at']) ? date("d/m/Y H:i", strtotime($pendingRenewal['created_at'])) . " น." : "-"; ?>',
            curEndDate: '<?php echo !empty($calcCurEndDate) ? $formatThaiDateFull($calcCurEndDate) . " (" . date("d/m/Y", strtotime($calcCurEndDate)) . ")" : "-"; ?>',
            newEndDate: '<?php echo !empty($exactNewEndDate) ? $formatThaiDateFull($exactNewEndDate) . " (" . date("d/m/Y", strtotime($exactNewEndDate)) . ")" : "-"; ?>',
            status: '<?php echo $pendingRenewal['status'] ?? "Pending"; ?>',
            adminRemark: '<?php echo addslashes($pendingRenewal['adminRemark'] ?? ""); ?>',
            oldDoc: '<?php echo htmlspecialchars($pendingRenewal['oldLeaseImage'] ?? ($activeLease['leaseImage'] ?? ''), ENT_QUOTES); ?>',
            newDoc: '<?php echo htmlspecialchars($pendingRenewal['leaseImage'] ?? '', ENT_QUOTES); ?>'
        });
    }

    function openRenewalModal(data) {
        if (!data) return;
        currentModalRenewalData = data;
        renderRenewalModalData(data);

        const modal = document.getElementById('renewalDetailModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        if (window.lucide) lucide.createIcons();
    }

    function renderRenewalModalData(data) {
        if (!data) return;
        document.getElementById('modalRoomNum').innerText = data.roomNumber || '-';
        document.getElementById('modalTenantName').innerText = data.tenantName || '-';
        document.getElementById('modalInfoRoom').innerText = 'ห้อง ' + (data.roomNumber || '-');
        document.getElementById('modalInfoTenant').innerText = data.tenantName || '-';
        document.getElementById('modalInfoMonths').innerText = (data.extensionMonths == 12) ? '1 ปี (12 เดือน)' : (data.extensionMonths || 3) + ' เดือน';
        document.getElementById('modalInfoCreatedAt').innerText = data.createdAt || '-';
        document.getElementById('modalInfoCurEnd').innerText = data.curEndDate || '-';
        document.getElementById('modalInfoNewEnd').innerText = data.newEndDate || '-';

        const status = data.status || 'Pending';
        const badge = document.getElementById('modalStatusBadge');
        const indicator = document.getElementById('modalStepIndicator');
        const step1Box = document.getElementById('step1Box');
        const step2Box = document.getElementById('step2Box');
        const step3Box = document.getElementById('step3Box');
        const descText = document.getElementById('modalStepDescText');

        if (status === 'Approved') {
            badge.className = 'px-3 py-1 rounded-full bg-emerald-500 text-white text-xs font-bold shadow-xs';
            badge.innerHTML = '✓ อนุมัติแล้ว';
            indicator.innerText = 'ขั้นตอนที่ 3 จาก 3 (เสร็จสมบูรณ์)';
            indicator.className = 'text-emerald-900 bg-emerald-200/90 px-3 py-1 rounded-full text-xs font-extrabold border border-emerald-300 shadow-2xs';

            step1Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs';
            step2Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs';
            step2Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs';
            step2Box.querySelector('span:first-child').innerHTML = '✓';
            document.getElementById('step2Time').innerText = 'ตรวจสอบแล้ว';
            document.getElementById('step2Time').className = 'text-[11px] text-emerald-700/80 font-normal';

            step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-400 text-emerald-950 ring-2 ring-emerald-300/70 transition-all shadow-xs';
            step3Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center text-sm font-bold shadow-xs';
            step3Box.querySelector('span:first-child').innerHTML = '✓';
            document.getElementById('step3Time').innerText = 'อนุมัติเรียบร้อย';
            document.getElementById('step3Time').className = 'text-[11px] text-emerald-800 font-bold';

            if (descText) {
                descText.innerHTML = '<span class="text-emerald-800 font-bold">🎉 คำขอต่อสัญญาเช่าได้รับการอนุมัติเรียบร้อยแล้ว</span> สัญญาฉบับใหม่มีผลบังคับใช้แล้วจนถึงวันที่ <strong>' + (data.newEndDate || '-') + '</strong>';
            }
        } else if (status === 'Rejected') {
            badge.className = 'px-3 py-1 rounded-full bg-rose-500 text-white text-xs font-bold shadow-xs';
            badge.innerHTML = '❌ ไม่อนุมัติ';
            indicator.innerText = 'คำขอถูกปฏิเสธ';
            indicator.className = 'text-rose-900 bg-rose-200/90 px-3 py-1 rounded-full text-xs font-extrabold border border-rose-300 shadow-2xs';

            if (descText) {
                descText.innerHTML = '<span class="text-rose-800 font-bold">❌ คำขอต่อสัญญาเช่าไม่ได้รับการอนุมัติ</span>' + (data.adminRemark ? '<br>เหตุผล: ' + data.adminRemark : '');
            }
        } else {
            // Pending
            badge.className = 'px-3 py-1 rounded-full bg-white text-amber-900 text-xs font-bold shadow-xs';
            badge.innerHTML = '⏳ รอแอดมินอนุมัติ';
            indicator.innerText = 'ขั้นตอนที่ 2 จาก 3 (กำลังดำเนินการ)';
            indicator.className = 'text-amber-900 bg-amber-200/80 px-3 py-1 rounded-full text-xs font-extrabold border border-amber-300/60 shadow-2xs';

            step1Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs';
            step2Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-amber-100 border-amber-400 text-amber-950 shadow-xs ring-2 ring-amber-300/60 transition-all';
            step2Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-amber-600 text-white flex items-center justify-center text-sm font-bold animate-pulse shadow-xs';
            step2Box.querySelector('span:first-child').innerHTML = '⏳';
            document.getElementById('step2Time').innerText = 'กำลังดำเนินการ';
            document.getElementById('step2Time').className = 'text-[11px] text-amber-800 font-bold';

            step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-stone-50 border-stone-200 text-stone-400 transition-all';
            step3Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-stone-200 text-stone-500 flex items-center justify-center text-sm font-bold';
            step3Box.querySelector('span:first-child').innerHTML = '3';
            document.getElementById('step3Time').innerText = 'รออนุมัติ';

            if (descText) {
                descText.innerHTML = 'กำลังรอนิติบุคคลและแอดมินตรวจสอบรายละเอียดคำขอต่อสัญญาเช่า และจัดเตรียมร่างสัญญาฉบับใหม่ กำหนดสิ้นสุดสัญญาฉบับใหม่: <strong>' + (data.newEndDate || '-') + '</strong>';
            }
        }

        if (data.adminRemark) {
            document.getElementById('modalRemarkWrap').classList.remove('hidden');
            document.getElementById('modalRemarkText').innerText = data.adminRemark;
        } else {
            document.getElementById('modalRemarkWrap').classList.add('hidden');
        }

        // จัดการแสดงผลเอกสารสัญญาเดิม (ฝั่งผู้เช่า)
        const oldDocBox = document.getElementById('modalTenantOldDocBox');
        const oldDocBtnWrap = document.getElementById('modalTenantOldDocBtnWrap');
        if (oldDocBox) {
            if (data.oldDoc && String(data.oldDoc).trim() !== '') {
                oldDocBox.innerHTML = `
                    <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-slate-200 shadow-2xs" onclick="openDocModal('${data.oldDoc}', 'เอกสารสัญญาเดิม')">
                        <img src="${data.oldDoc}" class="w-full max-h-[140px] object-cover transition duration-300 group-hover:scale-105" alt="สัญญาเดิม">
                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-1.5 text-white font-bold text-xs backdrop-blur-2xs">
                            <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                        </div>
                    </div>
                `;
                if (oldDocBtnWrap) {
                    oldDocBtnWrap.innerHTML = `
                        <button type="button" onclick="openDocModal('${data.oldDoc}', 'เอกสารสัญญาเดิม')" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs">
                            <i data-lucide="file-text" class="w-3.5 h-3.5 text-slate-500"></i> เปิดดูเอกสารสัญญาเดิม
                        </button>
                    `;
                }
            } else {
                oldDocBox.innerHTML = '<span class="text-xs text-slate-400 italic py-4">ไม่มีเอกสารสัญญาเดิมในระบบ</span>';
                if (oldDocBtnWrap) oldDocBtnWrap.innerHTML = '';
            }
        }

        // จัดการแสดงผลเอกสารสัญญาฉบับใหม่ที่แนบมา (ฝั่งผู้เช่า)
        const newDocBox = document.getElementById('modalTenantNewDocBox');
        const newDocBtnWrap = document.getElementById('modalTenantNewDocBtnWrap');
        const newDocBadge = document.getElementById('modalTenantNewDocBadge');
        if (newDocBox) {
            if (data.newDoc && String(data.newDoc).trim() !== '') {
                if (newDocBadge) {
                    newDocBadge.innerText = 'แนบแล้ว';
                    newDocBadge.className = 'text-[10px] bg-emerald-100 text-emerald-800 font-bold px-2 py-0.5 rounded-lg border border-emerald-200';
                }
                newDocBox.innerHTML = `
                    <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-emerald-300 shadow-2xs" onclick="openDocModal('${data.newDoc}', 'เอกสารสัญญาเช่าฉบับใหม่')">
                        <img src="${data.newDoc}" class="w-full max-h-[140px] object-cover transition duration-300 group-hover:scale-105" alt="สัญญาใหม่">
                        <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-1.5 text-white font-bold text-xs backdrop-blur-2xs">
                            <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
                        </div>
                    </div>
                `;
                if (newDocBtnWrap) {
                    newDocBtnWrap.innerHTML = `
                        <div class="flex gap-2">
                            <button type="button" onclick="openDocModal('${data.newDoc}', 'เอกสารสัญญาเช่าฉบับใหม่')" class="flex-1 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer shadow-2xs">
                                <i data-lucide="file-check-2" class="w-3.5 h-3.5"></i> เปิดดูสัญญาใหม่ 📄
                            </button>
                            <a href="${data.newDoc}" download target="_blank" class="px-3 py-2 bg-emerald-100 hover:bg-emerald-200 text-emerald-800 font-bold rounded-xl text-xs transition flex items-center justify-center gap-1 cursor-pointer shadow-2xs" title="ดาวน์โหลด">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                            </a>
                        </div>
                    `;
                }
            } else if (status === 'Pending') {
                if (newDocBadge) {
                    newDocBadge.innerText = 'รอดำเนินการ';
                    newDocBadge.className = 'text-[10px] bg-amber-100 text-amber-800 font-bold px-2 py-0.5 rounded-lg border border-amber-200';
                }
                newDocBox.innerHTML = `
                    <div class="text-center p-3 text-amber-800 space-y-1">
                        <i data-lucide="clock" class="w-5 h-5 text-amber-500 mx-auto"></i>
                        <div class="font-bold text-xs">อยู่ระหว่างรอนิติบุคคลแนบสัญญาใหม่</div>
                        <p class="text-[11px] text-amber-700/80">ระบบจะแสดงเอกสารสัญญาฉบับใหม่ให้ดาวน์โหลดทันทีที่แอดมินอนุมัติ</p>
                    </div>
                `;
                if (newDocBtnWrap) newDocBtnWrap.innerHTML = '';
            } else {
                if (newDocBadge) {
                    newDocBadge.innerText = 'ไม่มีเอกสาร';
                    newDocBadge.className = 'text-[10px] bg-slate-100 text-slate-500 font-bold px-2 py-0.5 rounded-lg border border-slate-200';
                }
                newDocBox.innerHTML = '<span class="text-xs text-slate-400 italic py-4">ไม่มีเอกสารสัญญาใหม่แนบในคำขอนี้</span>';
                if (newDocBtnWrap) newDocBtnWrap.innerHTML = '';
            }
        }
    }

    function closeRenewalModal() {
        const modal = document.getElementById('renewalDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRenewalModal();
        }
    });

    // 🌟 ระบบ Custom Dropdown ต่อสัญญาเช่า 🌟
    function toggleRenewalDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('renewalDropdownMenu');
        const chevron = document.getElementById('triggerChevron');
        if (menu) {
            const isHidden = menu.classList.contains('hidden');
            menu.classList.toggle('hidden');
            if (chevron) {
                if (isHidden) {
                    chevron.classList.add('rotate-180');
                } else {
                    chevron.classList.remove('rotate-180');
                }
            }
        }
        if (window.lucide) lucide.createIcons();
    }

    function selectRenewalOption(value, title, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('extensionMonthsInput');
        if (input) input.value = value;

        const titleEl = document.getElementById('triggerTitle');
        if (titleEl) titleEl.innerText = title;

        document.querySelectorAll('.renewal-option-item').forEach(item => {
            const itemVal = parseInt(item.getAttribute('data-value'));
            const check = item.querySelector('.check-icon');
            if (itemVal === parseInt(value)) {
                item.className = 'renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all bg-rose-50 text-rose-700 font-bold border border-rose-200/70';
                if (check) check.classList.remove('hidden');
            } else {
                item.className = 'renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all hover:bg-rose-50 text-slate-700 hover:text-rose-700 font-semibold border border-transparent';
                if (check) check.classList.add('hidden');
            }
        });

        calculateNewEnd(value);

        const menu = document.getElementById('renewalDropdownMenu');
        const chevron = document.getElementById('triggerChevron');
        if (menu) menu.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');

        if (window.lucide) lucide.createIcons();
    }

    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('customRenewalDropdown');
        const menu = document.getElementById('renewalDropdownMenu');
        const chevron = document.getElementById('triggerChevron');
        if (dropdown && menu && !dropdown.contains(e.target)) {
            menu.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    });

    const currentEndDateStr = '<?php echo $activeLease['endDate'] ?? date('Y-m-d'); ?>';

    function calculateNewEnd(months) {
        if (!currentEndDateStr) return;
        try {
            const parts = currentEndDateStr.split('-');
            const curDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
            curDate.setMonth(curDate.getMonth() + parseInt(months));
            const day = String(curDate.getDate()).padStart(2, '0');
            const month = String(curDate.getMonth() + 1).padStart(2, '0');
            const year = curDate.getFullYear() + 543;
            const previewEl = document.getElementById('previewNewDateText');
            if (previewEl) {
                previewEl.innerText = `${day}/${month}/${year}`;
            }
        } catch(e) {
            console.error(e);
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        calculateNewEnd(6);
    });

    // แจ้งเตือนขึ้นแปปเดียว
    document.addEventListener('DOMContentLoaded', function() {
      const flash = document.getElementById('flashAlert');
      if (flash) {
        setTimeout(() => {
          flash.style.opacity = '0';
          flash.style.transform = 'translateY(-8px)';
          setTimeout(() => flash.remove(), 400);
        }, 2500);
      }
    });

    // Real-time Polling: ตรวจสอบสถานะและอัปเดตสดทุก 3 วินาที
    <?php if($pendingRenewal): ?>
    let knownRenewalStatus = '<?php echo $pendingRenewal['status'] ?? 'Pending'; ?>';
    let pollInterval = setInterval(function() {
      fetch('tenant_renewal.php?check_status=1')
        .then(res => res.json())
        .then(data => {
          if (!data || !data.status) return;

          // ถ้าสถานะเปลี่ยน หรือมีข้อมูลใหม่
          if (data.status !== knownRenewalStatus) {
            knownRenewalStatus = data.status;

            // 1. อัปเดตป้ายสถานะบนการ์ดหน้าหลักแบบเรียลไทม์
            const pageBadge = document.getElementById('pendingRenewalCardStatus');
            if (pageBadge) {
              if (data.status === 'Approved') {
                pageBadge.className = 'px-2.5 py-0.5 bg-emerald-100 text-emerald-900 border border-emerald-300 rounded-full font-bold text-[11px] shadow-2xs';
                pageBadge.innerHTML = '✓ อนุมัติแล้ว';
              } else if (data.status === 'Rejected') {
                pageBadge.className = 'px-2.5 py-0.5 bg-rose-100 text-rose-900 border border-rose-300 rounded-full font-bold text-[11px] shadow-2xs';
                pageBadge.innerHTML = '❌ ไม่อนุมัติ';
              }
            }

            // 2. ถ้าเปิด Modal ค้างอยู่ ให้อัปเดตสถานะและขั้นตอนใน Modal สดๆ ทันที
            const modal = document.getElementById('renewalDetailModal');
            const isModalOpen = modal && !modal.classList.contains('hidden') && modal.style.display !== 'none';
            if (isModalOpen && currentModalRenewalData) {
              currentModalRenewalData.status = data.status;
              if (data.adminRemark) currentModalRenewalData.adminRemark = data.adminRemark;
              if (data.newEndDate) currentModalRenewalData.newEndDate = data.newEndDate;
              if (data.newDoc) currentModalRenewalData.newDoc = data.newDoc;
              if (data.oldDoc) currentModalRenewalData.oldDoc = data.oldDoc;
              renderRenewalModalData(currentModalRenewalData);
              if (window.lucide) lucide.createIcons();
            }

            // 3. แจ้งเตือน SweetAlert2
            if (data.status === 'Approved') {
              clearInterval(pollInterval);
              Swal.fire({
                icon: 'success',
                title: '🎉 คำขอต่อสัญญาได้รับการอนุมัติแล้ว!',
                html: `<div class="space-y-2 text-left">
                         <p class="text-sm text-slate-600">แอดมินได้อนุมัติขยายระยะเวลาสัญญาเช่าของคุณเรียบร้อยแล้ว</p>
                         <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-200 text-xs text-emerald-900">
                             <strong>วันสิ้นสุดสัญญาใหม่:</strong> ${data.newEndDate}
                         </div>
                         ${data.adminRemark ? `<div class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg">💬 หมายเหตุ: ${data.adminRemark}</div>` : ''}
                         ${data.newDoc ? `<div class="pt-2"><button type="button" onclick="openDocModal('${data.newDoc}', 'เอกสารสัญญาเช่าฉบับใหม่')" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 shadow-xs cursor-pointer"><i data-lucide='file-check-2' class='w-4 h-4'></i> เปิดดูเอกสารสัญญาใหม่ที่แนบมา 📄</button></div>` : ''}
                       </div>`,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#059669'
              }).then(() => {
                location.reload();
              });
            } else if (data.status === 'Rejected') {
              clearInterval(pollInterval);
              Swal.fire({
                icon: 'error',
                title: '❌ คำขอต่อสัญญาถูกปฏิเสธ',
                html: `<p class="text-sm text-slate-600">${data.adminRemark || 'แอดมินได้ปฏิเสธคำขอต่อสัญญาเช่า'}</p>`,
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#ef4444'
              }).then(() => {
                location.reload();
              });
            }
          }
        })
        .catch(err => console.error('Realtime check error:', err));
    }, 3000);
    <?php endif; ?>
  </script>
</body>
</html>