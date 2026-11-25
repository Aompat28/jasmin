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

// 🌟 สร้างตาราง LeaseRenewals อัตโนมัติ (ถ้ายังไม่มี)

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

// 🌟 Real-time Status Check Endpoint (สำหรับ Polling ฝั่งผู้เช่า)

if (isset($_GET['check_status'])) {

    header('Content-Type: application/json');

    if (!$activeLease) {

        echo json_encode(['status' => 'NoActiveLease']);

        exit();

    }

    $stmtCheckRealtime = $conn->prepare("SELECT status, adminRemark, created_at FROM LeaseRenewals WHERE leaseId = ? ORDER BY renewalId DESC LIMIT 1");

    $stmtCheckRealtime->execute([$activeLease['leaseId']]);

    $latestReq = $stmtCheckRealtime->fetch(PDO::FETCH_ASSOC);

    

    // ดึงวันสิ้นสุดสัญญาฉบับล่าสุด

    $stmtLeaseFresh = $conn->prepare("SELECT endDate FROM Lease WHERE leaseId = ?");

    $stmtLeaseFresh->execute([$activeLease['leaseId']]);

    $freshLease = $stmtLeaseFresh->fetch(PDO::FETCH_ASSOC);

    echo json_encode([

        'status' => $latestReq ? $latestReq['status'] : 'None',

        'adminRemark' => $latestReq ? $latestReq['adminRemark'] : '',

        'newEndDate' => $freshLease ? date("d/m/Y", strtotime($freshLease['endDate'])) : ''

    ]);

    exit();

}

$pendingRenewal = null;

$historyRenewals = [];

if ($activeLease) {

    // 1. ดึงประวัติคำขอต่อสัญญาทั้งหมด (เฉพาะคำขอที่ผ่านการพิจารณาแล้ว)

    $stmtHist = $conn->prepare("SELECT * FROM LeaseRenewals WHERE leaseId = ? AND status != 'Pending' ORDER BY renewalId DESC");

    $stmtHist->execute([$activeLease['leaseId']]);

    $historyRenewals = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    // 2. เช็คว่ามีคำขอที่อนุมัติแล้วหรือไม่ (หากอนุมัติแล้ว จะไม่แสดงส่วนตรงกลางเลย)

    $hasApprovedRenewal = false;

    foreach ($historyRenewals as $h) {

        if ($h['status'] === 'Approved') {

            $hasApprovedRenewal = true;

            break;

        }

    }

    if (!$hasApprovedRenewal) {

        // เช็คว่ามีคำขอต่อสัญญาที่กำลังรออนุมัติอยู่หรือไม่

        $stmtCheck = $conn->prepare("SELECT * FROM LeaseRenewals WHERE leaseId = ? AND status = 'Pending' LIMIT 1");

        $stmtCheck->execute([$activeLease['leaseId']]);

        $pendingRenewal = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        // ระบบส่งคำขอต่อสัญญา

        if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_POST['submit_renewal'])) && !$pendingRenewal) {

            $extensionMonths = isset($_POST['extensionMonths']) ? (int)$_POST['extensionMonths'] : 6;

            

            $stmtInsert = $conn->prepare("INSERT INTO LeaseRenewals (leaseId, tenantId, roomId, extensionMonths) VALUES (?, ?, ?, ?)");

            if ($stmtInsert->execute([$activeLease['leaseId'], $tenantId, $activeLease['roomId'], $extensionMonths])) {

                $message = "<div id='flashAlert' class='p-4 mb-4 bg-emerald-50 text-emerald-700 rounded-2xl border border-emerald-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>

                    <div class='flex items-center gap-2'>

                        <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i>

                        <span>ส่งคำขอต่อสัญญาเรียบร้อยแล้ว อยู่ระหว่างกำลังรอดำเนินการ</span>

                    </div>

                    <button onclick='this.parentElement.remove()' class='text-emerald-500 hover:text-emerald-700 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>

                </div>";

                

                // ดึงคำขอใหม่หลังบันทึก

                $stmtCheck->execute([$activeLease['leaseId']]);

                $pendingRenewal = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            }

        }

    } else {

        $pendingRenewal = null;

    }

}

?>

<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

  <title>jasmin</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest"></script>

  <style> body { font-family: 'Prompt', sans-serif; background-color: #FAF9F6; } </style>

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

  <main class="max-w-md mx-auto p-4 mt-2">
<!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
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

      <!-- แจ้งเตือนขึ้นแปปเดียว -->

      <?php echo $message; ?>

      <?php if(!$activeLease): ?>

          <div class="bg-rose-50 text-rose-600 p-5 rounded-2xl text-sm font-medium border border-rose-200 shadow-xs">

            ⚠️ ไม่พบสัญญาเช่าที่ใช้งานอยู่ หากต้องการต่อสัญญาเช่า กรุณาติดต่อผู้ดูแลหอพัก

          </div>

      <?php else: ?>

          

          <!-- สรุปสัญญาปัจจุบัน -->

          <div class="bg-white p-5 rounded-3xl shadow-sm border border-rose-100/80 mb-6">

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

          <?php if(!$hasApprovedRenewal): ?>

              <?php if($pendingRenewal): ?>

                  <!-- 🌟 กรณีมีคำขอค้างอยู่ (กำลังรอดำเนินการ) -->

                  <div class="bg-gradient-to-r from-amber-50 to-orange-50 text-amber-900 p-5 rounded-3xl text-xs border border-amber-200/80 space-y-3 shadow-xs mb-6">

                      <div class="flex items-center justify-between border-b border-amber-200/60 pb-3">

                          <div class="flex items-center gap-2">

                              <span class="relative flex h-3 w-3">

                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>

                                <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>

                              </span>

                              <span class="font-bold text-sm text-amber-900">⏳ อยู่ระหว่างกำลังรอดำเนินการ</span>

                          </div>

                          <span class="px-2.5 py-1 bg-amber-200/80 text-amber-900 rounded-full font-bold text-[10px]">รอแอดมินอนุมัติ</span>

                      </div>

                      <div class="space-y-1.5">

                          <div class="flex justify-between">

                              <span class="text-slate-600">ระยะเวลาที่ขอต่อเพิ่ม:</span>

                              <strong class="text-amber-900 text-sm"><?php echo $pendingRenewal['extensionMonths'] == 12 ? '1 ปี (12 เดือน)' : $pendingRenewal['extensionMonths'] . ' เดือน'; ?></strong>

                          </div>

                          <div class="flex justify-between text-[11px] text-slate-500">

                              <span>วันที่ยื่นคำขอ:</span>

                              <span class="font-mono"><?php echo date("d/m/Y H:i", strtotime($pendingRenewal['created_at'])); ?> น.</span>

                          </div>

                      </div>

                      <div class="pt-2 border-t border-amber-200/60 text-[11px] text-amber-800 leading-relaxed flex items-start gap-1.5">

                          <i data-lucide="info" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>

                          <span>คำขอของคุณถูกส่งไปยังผู้ดูแลระบบเรียบร้อยแล้ว ระบบจะอัปเดตแบบเรียลไทม์ทันทีเมื่อแอดมินอนุมัติสัญญาใหม่ครับ</span>

                      </div>

                  </div>

              <?php else: ?>

                  <!-- 🌟 ฟอร์มขอต่อสัญญา สไตล์โมเดิร์นหรูหรา (Interactive Luxury Dropdown) 🌟 -->

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
                              
                              <!-- 🌟 Custom Rounded Dropdown (โมเดิร์น โค้งมน ไม่มีกรอบสี่เหลี่ยมแบบ Native OS) 🌟 -->
                              <div class="relative" id="customRenewalDropdown">
                                  <!-- Trigger Button -->
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

                                  <!-- Floating Menu Panel (Modern Rounded Floating Card) -->
                                  <div id="renewalDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-2 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-2xl shadow-xl shadow-rose-950/10 p-2 space-y-1 transition-all duration-200">
                                      <!-- 3 เดือน -->
                                      <div onclick="selectRenewalOption(3, 'ต่อเพิ่ม 3 เดือน', event)"
                                           class="renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all hover:bg-rose-50 text-slate-700 hover:text-rose-700 font-semibold border border-transparent"
                                           data-value="3">
                                          <span class="text-sm">ต่อเพิ่ม 3 เดือน</span>
                                          <span class="check-icon hidden w-5 h-5 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">✓</span>
                                      </div>

                                      <!-- 6 เดือน (Default) -->
                                      <div onclick="selectRenewalOption(6, 'ต่อเพิ่ม 6 เดือน', event)"
                                           class="renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all bg-rose-50 text-rose-700 font-bold border border-rose-200/70"
                                           data-value="6">
                                          <span class="text-sm">ต่อเพิ่ม 6 เดือน</span>
                                          <span class="check-icon w-5 h-5 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">✓</span>
                                      </div>

                                      <!-- 12 เดือน -->
                                      <div onclick="selectRenewalOption(12, 'ต่อเพิ่ม 1 ปี (12 เดือน)', event)"
                                           class="renewal-option-item rounded-xl px-4 py-3 flex items-center justify-between cursor-pointer transition-all hover:bg-rose-50 text-slate-700 hover:text-rose-700 font-semibold border border-transparent"
                                           data-value="12">
                                          <span class="text-sm">ต่อเพิ่ม 1 ปี (12 เดือน)</span>
                                          <span class="check-icon hidden w-5 h-5 rounded-full bg-rose-500 text-white text-xs flex items-center justify-center font-bold">✓</span>
                                      </div>
                                  </div>
                              </div>
                          </div>

                          <!-- 🌟 ป้ายแสดงวันสิ้นสุดสัญญาใหม่โดยประมาณ (Live Preview) 🌟 -->
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

          <?php endif; ?>

          <!-- ประวัติคำขอต่อสัญญา -->

          <?php if(!empty($historyRenewals)): ?>

          <div class="bg-white p-5 rounded-3xl shadow-sm border border-rose-100/80">

              <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">

                <i data-lucide="history" class="w-4 h-4 text-slate-500"></i> ประวัติคำขอต่อสัญญาเช่า

              </h3>

              <div class="space-y-3">

                <?php foreach($historyRenewals as $h): 

                  $docFile = !empty($h['leaseImage']) ? $h['leaseImage'] : (($h['status'] === 'Approved' && !empty($activeLease['leaseImage'])) ? $activeLease['leaseImage'] : '');

                ?>

                  <div class="p-3.5 rounded-2xl border border-rose-100/60 bg-rose-50/30/60 flex items-start justify-between gap-3 text-xs">

                    <div class="flex-1">

                      <div class="font-bold text-slate-800">ขอต่อสัญญาเพิ่ม <?php echo $h['extensionMonths']; ?> เดือน</div>

                      <div class="text-[11px] text-slate-400 mt-0.5 font-mono">ยื่นคำขอ: <?php echo date("d/m/Y H:i", strtotime($h['created_at'])); ?> น.</div>

                      <?php if(!empty($h['adminRemark'])): ?>

                        <div class="text-[11px] text-slate-600 mt-1.5 bg-white p-2.5 rounded-xl border border-rose-100/80 shadow-2xs">

                          💬 นิติบุคคล: <?php echo htmlspecialchars($h['adminRemark']); ?>

                        </div>

                      <?php endif; ?>

                      <?php if(!empty($docFile)): ?>

                        <div class="mt-2">

                          <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($docFile, ENT_QUOTES); ?>')" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-rose-50 text-rose-700 hover:bg-blue-100 border border-rose-200 rounded-lg text-[11px] font-bold transition cursor-pointer">

                            <i data-lucide="file-text" class="w-3.5 h-3.5 text-rose-600"></i> ดูเอกสารสัญญาฉบับใหม่ 📄

                          </button>

                        </div>

                      <?php endif; ?>

                    </div>

                    <div class="shrink-0">

                      <?php if($h['status'] === 'Approved'): ?>

                        <span class="px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-full font-bold text-[10px] inline-flex items-center gap-1">

                          <i data-lucide="check" class="w-3 h-3"></i> อนุมัติแล้ว

                        </span>

                      <?php elseif($h['status'] === 'Pending'): ?>

                        <span class="px-2.5 py-1 bg-amber-100 text-amber-800 rounded-full font-bold text-[10px] inline-flex items-center gap-1">

                          <i data-lucide="clock" class="w-3 h-3"></i> รอพิจารณา

                        </span>

                      <?php elseif($h['status'] === 'Rejected'): ?>

                        <span class="px-2.5 py-1 bg-rose-100 text-rose-800 rounded-full font-bold text-[10px] inline-flex items-center gap-1">

                          <i data-lucide="x" class="w-3 h-3"></i> ไม่อนุมัติ

                        </span>

                      <?php endif; ?>

                    </div>

                  </div>

                <?php endforeach; ?>

              </div>

          </div>

          <?php endif; ?>

      <?php endif; ?>

  </main>

  <?php include_once 'includes/doc_viewer_modal.php'; ?>

  <script>

    lucide.createIcons();

    // 🌟 ระบบ Custom Dropdown ต่อสัญญาเช่า (ไม่มีกรอบสี่เหลี่ยม OS) 🌟
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
        
        // อัปเดต Hidden input
        const input = document.getElementById('extensionMonthsInput');
        if (input) input.value = value;

        // อัปเดต Trigger View
        const titleEl = document.getElementById('triggerTitle');
        if (titleEl) titleEl.innerText = title;

        // อัปเดตไฮไลท์ในตัวเลือก
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

        // คำนวณวันสิ้นสุดสัญญาใหม่
        calculateNewEnd(value);

        // ปิดเมนู
        const menu = document.getElementById('renewalDropdownMenu');
        const chevron = document.getElementById('triggerChevron');
        if (menu) menu.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');

        if (window.lucide) lucide.createIcons();
    }

    // ปิด Dropdown เมื่อคลิกข้างนอก
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('customRenewalDropdown');
        const menu = document.getElementById('renewalDropdownMenu');
        const chevron = document.getElementById('triggerChevron');
        if (dropdown && menu && !dropdown.contains(e.target)) {
            menu.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    });

    // ปิดด้วยปุ่ม ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const menu = document.getElementById('renewalDropdownMenu');
            const chevron = document.getElementById('triggerChevron');
            if (menu) menu.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    });


    // 🌟 ระบบคำนวณวันสิ้นสุดสัญญาใหม่แบบ Real-time และการ์ดเลือก 🌟

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

    // เรียกคำนวณครั้งแรกเมื่อโหลดหน้า

    document.addEventListener("DOMContentLoaded", function() {
        calculateNewEnd(6);
    });

    // ⚡ 1. การแจ้งเตือนขึ้นแปปเดียว (2.5 วินาทีแล้วจางหายอัตโนมัติ)

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

    // ⚡ 2. ตรวจสอบสถานะการอนุมัติแบบเรียลไทม์ (Real-time Polling)

    <?php if($pendingRenewal): ?>

    let pollInterval = setInterval(function() {

      fetch('tenant_renewal.php?check_status=1')

        .then(res => res.json())

        .then(data => {

          if (data.status === 'Approved') {

            clearInterval(pollInterval);

            Swal.fire({

              icon: 'success',

              title: '🎉 คำขอต่อสัญญาได้รับการอนุมัติแล้ว!',

              html: `<p class="text-sm">แอดมินได้อนุมัติขยายระยะเวลาสัญญาเช่าของคุณเรียบร้อยแล้ว</p>

                     <p class="text-sm font-bold text-emerald-600 mt-2">วันสิ้นสุดสัญญาใหม่: ${data.newEndDate}</p>

                     ${data.adminRemark ? `<p class="text-xs text-slate-500 mt-2 bg-rose-50/30 p-2 rounded-lg">💬 ${data.adminRemark}</p>` : ''}`,

              confirmButtonText: 'ตกลง',

              confirmButtonColor: '#2563eb'

            }).then(() => {

              location.reload();

            });

          } else if (data.status === 'Rejected') {

            clearInterval(pollInterval);

            Swal.fire({

              icon: 'error',

              title: '❌ คำขอต่อสัญญาถูกปฏิเสธ',

              html: `<p class="text-sm">${data.adminRemark || 'แอดมินได้ปฏิเสธคำขอต่อสัญญาเช่า'}</p>`,

              confirmButtonText: 'ตกลง',

              confirmButtonColor: '#ef4444'

            }).then(() => {

              location.reload();

            });

          }

        })

        .catch(err => console.error('Realtime check error:', err));

    }, 4000);

    <?php endif; ?>

  </script>

</body>

</html>