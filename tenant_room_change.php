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



// 🌟 สร้างตาราง RoomChangeRequests อัตโนมัติ (ถ้ายังไม่มี)

// 🌟 สร้างตาราง RoomChangeRequests อัตโนมัติ (ถ้ายังไม่มี)

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



try { $conn->exec("ALTER TABLE RoomChangeRequests ADD COLUMN paymentSlip VARCHAR(255) DEFAULT NULL;"); } catch(PDOException $e) {}



// ดึงข้อมูลห้องและสัญญาเช่าปัจจุบันของผู้เช่า

$stmtRoom = $conn->prepare("

    SELECT r.roomId, r.roomNumber, r.roomType, r.floor, r.rentPrice, l.leaseId 

    FROM Lease l 

    JOIN Room r ON l.roomId = r.roomId 

    WHERE l.tenantId = ? AND l.status = 'Active' 

    LIMIT 1

");

$stmtRoom->execute([$tenantId]);

$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $myRoom ? ($myRoom['roomNumber'] ?? '') : '';

if (empty($myRoomNumber)) {

    $stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");

    $stmtHdrR->execute([$tenantId]);

    $myRoomNumber = $stmtHdrR->fetchColumn() ?: '';

}



// 🌟 Real-time Status Check Endpoint (สำหรับ Polling ฝั่งผู้เช่า)
$thaiMonthNamesRc = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$formatThaiDateRc = function($dateStr, $includeTime = false) use ($thaiMonthNamesRc) {
    if (!$dateStr) return '-';
    $t = strtotime($dateStr);
    $d = date('j', $t);
    $m = $thaiMonthNamesRc[intval(date('n', $t))];
    $y = date('Y', $t) + 543;
    $res = "{$d} {$m} {$y}";
    if ($includeTime) {
        $res .= " เวลา " . date('H:i', $t) . " น.";
    }
    return $res;
};

if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    if (!$myRoom) {
        echo json_encode(['status' => 'NoActiveRoom']);
        exit();
    }
    $stmtCheckRealtime = $conn->prepare("SELECT status, adminRemark, created_at, preferredMoveDate, targetRoomType, targetRoomId, requestId FROM RoomChangeRequests WHERE tenantId = ? ORDER BY requestId DESC LIMIT 1");
    $stmtCheckRealtime->execute([$tenantId]);
    $latestReq = $stmtCheckRealtime->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => $latestReq ? $latestReq['status'] : 'None',
        'adminRemark' => $latestReq ? $latestReq['adminRemark'] : '',
        'preferredMoveDate' => ($latestReq && !empty($latestReq['preferredMoveDate'])) ? $formatThaiDateRc($latestReq['preferredMoveDate']) : '',
        'requestId' => $latestReq ? $latestReq['requestId'] : null
    ]);
    exit();
}
    // ดึงรายการห้องว่างในระบบที่มีอยู่ในปัจจุบัน เผื่อเลือกระบุห้องเจาะจง

$availableRooms = $conn->query("

    SELECT roomId, roomNumber, roomType, floor, rentPrice 

    FROM Room 

    WHERE status = 'Available' 

    ORDER BY roomNumber ASC

")->fetchAll(PDO::FETCH_ASSOC);



$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'request';
$pendingRequest = null;

$requestHistory = [];



if ($myRoom) {

    // ดึงประวัติคำขอย้ายห้องที่ผ่านการพิจารณาแล้ว (เฉพาะที่อนุมัติหรือไม่อนุมัติแล้ว)

    $stmtHist = $conn->prepare("

        SELECT rcr.*, 

               r_curr.roomNumber AS currRoomNumber, r_curr.roomType AS currRoomType,

               r_target.roomNumber AS targetRoomNumber, r_target.roomType AS targetRoomTypeSpecific

        FROM RoomChangeRequests rcr

        LEFT JOIN Room r_curr ON rcr.currentRoomId = r_curr.roomId

        LEFT JOIN Room r_target ON rcr.targetRoomId = r_target.roomId

        WHERE rcr.tenantId = ?

        ORDER BY rcr.requestId DESC

    ");

    $stmtHist->execute([$tenantId]);

    $requestHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);



    // เช็คว่ามีคำขอย้ายห้องที่รออนุมัติอยู่หรือไม่
    $stmtCheck = $conn->prepare("SELECT * FROM RoomChangeRequests WHERE tenantId = ? AND status = 'Pending' LIMIT 1");
    $stmtCheck->execute([$tenantId]);
    $pendingRequest = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    // เช็คคำขอล่าสุดที่อนุมัติแล้ว (ถ้ามี)
    $latestApprovedRequest = null;
    foreach ($requestHistory as $h) {
        if ($h['status'] === 'Approved') {
            $latestApprovedRequest = $h;
            break;
        }
    }

    // ส่งคำขอย้ายห้องใหม่ (กรณีไม่มีคำขอรออนุมัติอยู่)
    if ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || isset($_POST['submit_room_change'])) && !$pendingRequest) {

            $changeTypeChoice = $_POST['changeTypeChoice'] ?? 'type';

            $targetRoomType = null;

            $targetRoomId = null;



            if ($changeTypeChoice === 'type') {

                $targetRoomType = $_POST['targetRoomType'] ?? 'Air';

            } elseif ($changeTypeChoice === 'specific_room' && !empty($_POST['targetRoomId'])) {

                $targetRoomId = intval($_POST['targetRoomId']);

                $stmtTargetType = $conn->prepare("SELECT roomType FROM Room WHERE roomId = ?");

                $stmtTargetType->execute([$targetRoomId]);

                $targetRoomType = $stmtTargetType->fetchColumn() ?: null;

            }



            $reason = trim($_POST['reason'] ?? '');

            $preferredMoveDate = !empty($_POST['preferredMoveDate']) ? $_POST['preferredMoveDate'] : null;



            if (empty($reason)) {

                $message = "<div id='flashAlert' class='p-4 mb-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>

                    <div class='flex items-center gap-2'>

                        <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600'></i>

                        <span>กรุณาระบุเหตุผลในการขอย้ายห้องพัก</span>

                    </div>

                    <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>

                </div>";

            } elseif (!isset($_FILES['payment_slip']) || $_FILES['payment_slip']['error'] !== UPLOAD_ERR_OK || empty($_FILES['payment_slip']['tmp_name'])) {

                $message = "<div id='flashAlert' class='p-4 mb-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>

                    <div class='flex items-center gap-2'>

                        <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>

                        <span>กรุณาแนบสลิปหลักฐานการชำระเงินค่าทำความสะอาด 500 บาท (บังคับแนบเอกสารก่อนส่งคำขอ)</span>

                    </div>

                    <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>

                </div>";

            } else {

                $uploadDir = 'uploads/';

                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

                $ext = strtolower(pathinfo($_FILES['payment_slip']['name'], PATHINFO_EXTENSION));

                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];



                if (!in_array($ext, $allowedExts)) {

                    $message = "<div id='flashAlert' class='p-4 mb-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>

                        <div class='flex items-center gap-2'>

                            <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>

                            <span>ไฟล์สลิปต้องเป็น รูปภาพ (JPG, PNG, WEBP) เท่านั้น</span>

                        </div>

                        <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>

                    </div>";

                } else {

                    $fileName = time() . '_roomchange_slip_' . bin2hex(random_bytes(4)) . '.' . $ext;

                    $targetFilePath = $uploadDir . $fileName;



                    if (move_uploaded_file($_FILES['payment_slip']['tmp_name'], $targetFilePath)) {

                        $paymentSlip = $targetFilePath;

                        $stmtInsert = $conn->prepare("

                            INSERT INTO RoomChangeRequests (tenantId, currentRoomId, targetRoomType, targetRoomId, reason, preferredMoveDate, paymentSlip, status) 

                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')

                        ");

                        if ($stmtInsert->execute([$tenantId, $myRoom['roomId'], $targetRoomType, $targetRoomId, $reason, $preferredMoveDate, $paymentSlip])) {

                            $message = "<div id='flashAlert' class='p-4 mb-4 bg-emerald-50 text-emerald-800 rounded-2xl border border-emerald-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>

                                <div class='flex items-center gap-2'>

                                    <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>

                                    <span>ส่งคำขอย้ายห้องพักและสลิปค่าทำความสะอาด 500 บาทเรียบร้อยแล้ว อยู่ระหว่างกำลังรอดำเนินการ</span>

                                </div>

                                <button onclick='this.parentElement.remove()' class='text-emerald-500 hover:text-emerald-700 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>

                            </div>";



                            $stmtCheck->execute([$tenantId]);

                            $pendingRequest = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                        }

                    } else {

                        $message = "<div id='flashAlert' class='p-4 mb-4 bg-rose-50 text-rose-700 rounded-2xl border border-rose-200 font-bold text-sm flex items-center justify-between shadow-xs transition-all duration-300'>

                            <div class='flex items-center gap-2'>

                                <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>

                                <span>เกิดข้อผิดพลาดในการอัปโหลดไฟล์สลิป กรุณาลองใหม่อีกครั้ง</span>

                            </div>

                            <button onclick='this.parentElement.remove()' class='text-rose-400 hover:text-rose-600 p-1'><i data-lucide='x' class='w-4 h-4'></i></button>

                        </div>";

                    }

                }

            }

        }

    }

?>



<!DOCTYPE html>

<html lang="th">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>ขอย้ายห้องพัก - Jasmin</title>
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
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">คำขอย้ายห้องพัก</p>
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
            <i data-lucide="repeat" class="w-3.5 h-3.5 text-rose-500"></i>
            <span>ย้ายห้องพัก</span>
          </span>
          <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
            <i data-lucide="home" class="w-3.5 h-3.5 text-amber-500"></i>
            <span>ห้อง <?php echo htmlspecialchars($myRoom['roomNumber'] ?? '-'); ?></span>
          </span>
        </div>
        <h1 class="text-xl sm:text-2xl font-display font-extrabold text-slate-900 tracking-tight">
          ยื่นคำขอย้ายห้องพัก
        </h1>
        <p class="text-xs text-slate-500 mt-1">
          เลือกห้องพักใหม่ที่ต้องการย้ายไป พร้อมระบุเหตุผลและวันที่สะดวก
        </p>
      </div>

      <!-- แจ้งเตือนขึ้นแปปเดียว -->

      <?php echo $message; ?>



      <?php if(!$myRoom): ?>

          <div class="bg-rose-50 text-rose-600 p-5 rounded-2xl text-sm font-medium border border-rose-200 shadow-xs">

            ⚠️ ไม่พบสัญญาเช่าที่ใช้งานอยู่ กรุณาติดต่อผู้ดูแลหอพัก

          </div>

      <?php else: ?>



          <!-- การ์ดห้องพักปัจจุบัน -->
          <div class="bg-white p-5 rounded-3xl shadow-sm border border-rose-100/80 mb-6">
              <div class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-2">ห้องพักปัจจุบันของคุณ</div>
              <div class="flex items-center justify-between">
                  <div>
                      <div class="text-2xl font-bold text-slate-800">ห้อง <?php echo htmlspecialchars($myRoom['roomNumber']); ?></div>
                      <div class="text-xs text-slate-500 mt-1">
                        ประเภท: <strong class="text-slate-700"><?php echo ($myRoom['roomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?></strong> (ชั้น <?php echo $myRoom['floor']; ?>)
                      </div>
                      <div class="text-xs text-rose-600 font-bold mt-1">
                        ค่าเช่าปัจจุบัน: ฿<?php echo number_format($myRoom['rentPrice'], 2); ?>/เดือน
                      </div>
                  </div>
                  <div class="w-12 h-12 bg-indigo-50 text-indigo-600 rounded-2xl flex items-center justify-center shadow-xs">
                    <i data-lucide="door-open" class="w-6 h-6"></i>
                  </div>
              </div>

              <!-- 🌟 แท็บสลับหน้า: คำขอย้ายห้อง vs ประวัติคำขอ (อยู่ด้านล่างภายในการ์ดห้องพัก) 🌟 -->
              <div class="mt-5 pt-3 border-t border-rose-100/80 flex gap-2 overflow-x-auto hide-scrollbar">
                  <!-- แท็บ 1: คำขอย้ายห้อง -->
                  <button type="button" onclick="switchRoomChangeTab('request')" id="tab-rc-request" class="<?php echo ($activeTab === 'request') ? 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
                      <i data-lucide="repeat" class="w-4 h-4"></i>
                      <span>คำขอย้ายห้อง</span>
                      <?php if($pendingRequest): ?>
                          <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold bg-amber-100 text-amber-800 rounded-full border border-amber-300">
                              <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                              รออนุมัติ
                          </span>
                      <?php endif; ?>
                  </button>

                  <!-- แท็บ 2: ประวัติคำขอ -->
                  <button type="button" onclick="switchRoomChangeTab('history')" id="tab-rc-history" class="<?php echo ($activeTab === 'history') ? 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
                      <i data-lucide="history" class="w-4 h-4"></i>
                      <span>ประวัติคำขอ</span>
                      <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-slate-100 text-slate-600 rounded-full">
                          <?php echo count($requestHistory); ?>
                      </span>
                  </button>
              </div>
          </div>

          <!-- ================= TAB 1: คำขอย้ายห้อง ================= -->
          <div id="content-rc-request" class="<?php echo ($activeTab === 'request') ? '' : 'hidden'; ?>">

              <?php if($pendingRequest): 
                  $thaiMonthNamesRc = [
                      1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
                      5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
                      9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
                  ];
                  $formatThaiDateRc = function($dateStr, $includeTime = false) use ($thaiMonthNamesRc) {
                      if (!$dateStr) return '-';
                      $t = strtotime($dateStr);
                      $d = date('j', $t);
                      $m = $thaiMonthNamesRc[intval(date('n', $t))];
                      $y = date('Y', $t) + 543;
                      $res = "{$d} {$m} {$y}";
                      if ($includeTime) {
                          $res .= " เวลา " . date('H:i', $t) . " น.";
                      }
                      return $res;
                  };

                  $moveDateFull = !empty($pendingRequest['preferredMoveDate']) ? $formatThaiDateRc($pendingRequest['preferredMoveDate']) : '-';
                  $reqDateRcFull = $formatThaiDateRc($pendingRequest['created_at'], true);
                  $targetTypeLabel = ($pendingRequest['targetRoomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม';
              ?>

                  <!-- 🌟 การ์ดแสดงคำขอย้ายห้องที่รอดำเนินการ (แสดงแค่พอคราวๆ ให้เห็น พอกดเข้าไปถึงเป็นรายละเอียดเต็ม) 🌟 -->
                  <div onclick="openPendingRoomChangeModal()" 
                       class="cursor-pointer transition-all duration-200 hover:shadow-lg hover:border-amber-400 select-none bg-gradient-to-br from-amber-50/70 via-white to-orange-50/40 text-slate-800 p-5 rounded-3xl border border-amber-200 shadow-xs mb-6 space-y-3.5 group">
                      
                      <!-- Header แถบสถานะแบบย่อ -->
                      <div class="flex items-center justify-between border-b border-amber-100/80 pb-2.5">
                          <div class="flex items-center gap-2">
                              <span class="relative flex h-2.5 w-2.5">
                                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                              </span>
                              <span class="font-bold text-sm text-slate-800">คำขอย้ายห้องพัก (ห้อง <?php echo htmlspecialchars($myRoom['roomNumber'] ?? ''); ?>)</span>
                          </div>
                          <span id="pendingRoomChangeCardStatus" class="px-2.5 py-0.5 bg-amber-100 text-amber-900 border border-amber-300 rounded-full font-bold text-[11px] shadow-2xs">
                              ⏳ รอแอดมินอนุมัติ
                          </span>
                      </div>

                      <!-- ข้อมูลสรุปพอคราวๆ (2 ช่อง) -->
                      <div class="grid grid-cols-2 gap-2.5 bg-amber-50/60 p-3 rounded-2xl border border-amber-100 text-xs">
                          <div>
                              <span class="text-slate-500 text-[11px] flex items-center gap-1 mb-0.5">
                                  <i data-lucide="door-open" class="w-3 h-3 text-amber-600"></i> ย้ายไปประเภทห้อง:
                              </span>
                              <strong class="text-amber-900 font-bold text-sm"><?php echo $targetTypeLabel; ?></strong>
                          </div>
                          <div>
                              <span class="text-slate-500 text-[11px] flex items-center gap-1 mb-0.5">
                                  <i data-lucide="calendar" class="w-3 h-3 text-amber-600"></i> วันที่ยื่นคำขอ:
                              </span>
                              <span class="font-mono text-slate-800 font-bold"><?php echo date("d/m/Y H:i", strtotime($pendingRequest['created_at'])); ?> น.</span>
                          </div>
                      </div>

                      <!-- แถบล่างชวนคลิกดูรายละเอียด -->
                      <div class="flex items-center justify-between pt-1 text-xs">
                          <span class="text-slate-500 text-[11px] flex items-center gap-1">
                              <i data-lucide="info" class="w-3.5 h-3.5 text-amber-600"></i>
                              <span>คลิกที่นี่เพื่อดูรายละเอียด & ขั้นตอน</span>
                          </span>
                          <span class="inline-flex items-center gap-1 px-3 py-1 bg-amber-100/80 group-hover:bg-amber-500 group-hover:text-white text-amber-900 font-bold rounded-xl text-xs transition-colors">
                              <span>ดูรายละเอียด</span>
                              <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                          </span>
                      </div>
                  </div>

              <?php else: ?>

                  <!-- ฟอร์มขอย้ายห้อง -->
                  <div class="bg-white p-5 rounded-3xl shadow-sm border border-rose-100/80 mb-6">

                      <h3 class="text-sm font-bold text-slate-800 mb-3 flex items-center gap-1.5">

                        <i data-lucide="arrow-left-right" class="w-4 h-4 text-rose-600"></i> กรอกฟอร์มขอย้ายห้องพัก

                      </h3>



                      <!-- ⚠️ กล่องแจ้งเตือนค่าธรรมเนียมทำความสะอาด 500 บาท -->

                      <div class="p-4 bg-amber-50/90 border border-amber-200/90 rounded-2xl mb-4 space-y-2">

                          <div class="flex items-center gap-2 text-amber-900 font-bold text-xs">

                              <i data-lucide="alert-circle" class="w-4 h-4 text-amber-600 shrink-0"></i>

                              <span>เงื่อนไขการย้ายห้องพัก & ค่าทำความสะอาด (500 บาท)</span>

                          </div>

                          <p class="text-[11px] text-slate-700 leading-relaxed">

                              หากท่านประสงค์จะย้ายห้องพัก จะมี<b>ค่าทำความสะอาดห้องพักเดิมจำนวน 500 บาท</b> (บังคับชำระล่วงหน้าก่อนยื่นคำขอ) 

                              กรุณาโอนเงินชำระค่าธรรมเนียม และแนบสลิปหลักฐานการโอนเงินเพื่อใช้อนุมัติ

                          </p>

                          <div class="p-2.5 bg-white/90 rounded-xl border border-amber-200 text-[11px] font-mono text-slate-800 space-y-0.5 shadow-2xs">

                              <div>🏦 <b>ธนาคารกสิกรไทย</b>: 098-1-23456-7</div>

                              <div>👤 <b>ชื่อบัญชี</b>: หอพักจัสมิน</div>

                              <div>💰 <b>ยอดเงินที่ต้องโอน</b>: <span class="text-rose-600 font-bold">500.00 บาท</span></div>

                          </div>

                      </div>



                      <form action="" method="POST" enctype="multipart/form-data" id="roomChangeForm" class="space-y-4">

                          <input type="hidden" name="submit_room_change" value="1">

                          

                          <div>

                              <label class="block text-xs font-bold text-slate-700 mb-1.5">รูปแบบการย้ายห้อง <span class="text-rose-500">*</span></label>

                              <div class="grid grid-cols-2 gap-2">

                                  <label class="flex items-center gap-2 p-3 bg-rose-50/30 border border-rose-100/80 rounded-xl cursor-pointer hover:bg-rose-50/50 text-xs font-semibold">

                                      <input type="radio" name="changeTypeChoice" value="type" checked onclick="toggleChoiceDisplay('type')" class="text-rose-600">

                                      <span>ระบุประเภทห้อง</span>

                                  </label>

                                  <label class="flex items-center gap-2 p-3 bg-rose-50/30 border border-rose-100/80 rounded-xl cursor-pointer hover:bg-rose-50/50 text-xs font-semibold">

                                      <input type="radio" name="changeTypeChoice" value="specific_room" onclick="toggleChoiceDisplay('specific_room')" class="text-rose-600">

                                      <span>เลือกห้องว่างเจาะจง</span>

                                  </label>

                              </div>

                          </div>



                          <!-- ตัวเลือก 1: ระบุประเภทห้อง -->

                          <div id="wrap_type" class="block">

                              <label class="block text-xs font-bold text-slate-700 mb-1.5">ต้องการย้ายไปประเภทใด <span class="text-rose-500">*</span></label>
                              <input type="hidden" name="targetRoomType" id="targetRoomTypeInput" value="<?php echo ($myRoom['roomType'] === 'Fan') ? 'Air' : 'Fan'; ?>" required>
                              
                              <div class="grid grid-cols-2 gap-3" id="roomTypePills">
                                  <!-- ห้องแอร์ -->
                                  <button type="button" onclick="selectTargetRoomType('Air')" id="btnTargetAir"
                                          class="p-3.5 rounded-2xl border-2 flex items-center justify-between text-left transition-all cursor-pointer <?php echo ($myRoom['roomType'] === 'Fan') ? 'border-sky-500 bg-gradient-to-br from-sky-50 to-blue-50/70 text-sky-950 font-bold shadow-xs' : 'border-rose-100/80 bg-white hover:border-sky-300 text-slate-700 font-semibold'; ?>">
                                      <div class="flex items-center gap-2.5">
                                          <span class="text-xl">❄️</span>
                                          <div>
                                              <div class="text-xs sm:text-sm font-bold">ห้องแอร์</div>
                                              <div class="text-[10px] text-slate-400">฿4,000 / เดือน</div>
                                          </div>
                                      </div>
                                      <span id="checkAir" class="<?php echo ($myRoom['roomType'] === 'Fan') ? '' : 'hidden'; ?> w-6 h-6 rounded-full bg-sky-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">✓</span>
                                  </button>

                                  <!-- ห้องพัดลม -->
                                  <button type="button" onclick="selectTargetRoomType('Fan')" id="btnTargetFan"
                                          class="p-3.5 rounded-2xl border-2 flex items-center justify-between text-left transition-all cursor-pointer <?php echo ($myRoom['roomType'] === 'Air') ? 'border-sky-500 bg-gradient-to-br from-sky-50 to-blue-50/70 text-sky-950 font-bold shadow-xs' : 'border-rose-100/80 bg-white hover:border-sky-300 text-slate-700 font-semibold'; ?>">
                                      <div class="flex items-center gap-2.5">
                                          <span class="text-xl">🌀</span>
                                          <div>
                                              <div class="text-xs sm:text-sm font-bold">ห้องพัดลม</div>
                                              <div class="text-[10px] text-slate-400">฿3,500 / เดือน</div>
                                          </div>
                                      </div>
                                      <span id="checkFan" class="<?php echo ($myRoom['roomType'] === 'Air') ? '' : 'hidden'; ?> w-6 h-6 rounded-full bg-sky-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">✓</span>
                                  </button>
                              </div>

                          </div>



                          <!-- ตัวเลือก 2: เลือกห้องว่างเจาะจง -->

                          <div id="wrap_specific_room" class="hidden">

                              <label class="block text-xs font-bold text-slate-700 mb-1.5">เลือกห้องว่างที่ต้องการย้ายไป</label>

                              <div class="relative">
                                  <select name="targetRoomId" class="w-full px-4 py-3.5 pr-10 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 rounded-2xl text-xs sm:text-sm font-bold text-slate-800 shadow-xs outline-none transition appearance-none cursor-pointer">

                                  <option value="">-- เลือกห้องว่างจากรายการ --</option>

                                  <?php if(!empty($availableRooms)): foreach($availableRooms as $ar): ?>

                                      <option value="<?php echo $ar['roomId']; ?>">

                                          ห้อง <?php echo $ar['roomNumber']; ?> (<?php echo ($ar['roomType'] === 'Air') ? 'ห้องแอร์' : 'ห้องพัดลม'; ?> ชั้น <?php echo $ar['floor']; ?> - ฿<?php echo number_format($ar['rentPrice']); ?>/เดือน)

                                      </option>

                                  <?php endforeach; else: ?>

                                      <option value="" disabled>-- ขณะนี้ไม่มีห้องว่างในระบบ --</option>

                                  <?php endif; ?>

                              </select>
                                  <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-rose-500">
                                      <i data-lucide="chevron-down" class="w-4 h-4"></i>
                                  </div>
                              </div>

                              <p class="text-[11px] text-slate-400 mt-1">หากไม่มีห้องในรายการ สามารถเลือกรูปแบบ "ระบุประเภทห้อง" เพื่อให้แอดมินจัดหาให้ได้ครับ</p>

                          </div>



                          <div>

                              <label class="block text-xs font-bold text-slate-700 mb-1.5">เหตุผลในการขอย้ายห้องพัก <span class="text-rose-500">*</span></label>

                              <textarea name="reason" rows="3" required placeholder="เช่น ต้องการย้ายจากห้องพัดลมเป็นห้องแอร์เนื่องจากอากาศร้อน, ต้องการย้ายไปชั้นล่าง ฯลฯ" class="w-full p-3 text-xs bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-rose-500 transition"></textarea>

                          </div>



                          <div>

                              <label class="block text-xs font-bold text-slate-700 mb-1.5">วันที่ประสงค์ขอย้ายเข้าห้องใหม่ (ประมาณการ)</label>

                              <input type="date" name="preferredMoveDate" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>" class="w-full p-3 text-xs bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-rose-500">

                          </div>



                          <!-- 🌟 ช่องบังคับแนบสลิปค่าทำความสะอาด 500 บาท -->

                          <div class="pt-2 border-t border-rose-100/60">

                              <label class="block text-xs font-bold text-slate-700 mb-1.5">

                                  แนบสลิปการโอนเงินค่าทำความสะอาด (500 บาท) <span class="text-rose-500 font-bold">* บังคับแนบสลิป</span>

                              </label>

                              <input type="file" name="payment_slip" accept="image/*" required class="w-full text-xs text-slate-500 file:mr-3 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-rose-50 file:text-rose-700 hover:file:bg-blue-100 bg-rose-50/30 border border-rose-100/80 rounded-xl transition cursor-pointer">

                              <p class="text-[11px] text-slate-400 mt-1">รองรับไฟล์รูปภาพ (JPG, PNG, WEBP)</p>

                          </div>



                          <button type="submit" name="submit_room_change" class="w-full py-3.5 bg-rose-500 hover:bg-rose-600 text-white font-bold rounded-xl shadow-md transition flex items-center justify-center gap-2 text-sm cursor-pointer active:scale-[0.98]">

                              <i data-lucide="send" class="w-4 h-4"></i> ส่งคำขอย้ายห้องและสลิปให้นิติบุคคล

                          </button>

                      </form>

                  </div>

              <?php endif; ?>



          </div> <!-- End #content-rc-request -->

          <!-- ================= TAB 2: ประวัติคำขอ ================= -->
          <div id="content-rc-history" class="<?php echo ($activeTab === 'history') ? '' : 'hidden'; ?>">
            <div class="bg-white p-5 rounded-3xl shadow-sm border border-rose-100/80">
              <?php
              $countRcTotal = count($requestHistory);
              $countRcPending = 0;
              $countRcApproved = 0;
              $countRcRejected = 0;
              foreach($requestHistory as $rch) {
                  if ($rch['status'] === 'Pending') $countRcPending++;
                  elseif ($rch['status'] === 'Approved') $countRcApproved++;
                  elseif (in_array($rch['status'], ['Rejected', 'Cancelled'])) $countRcRejected++;
              }
              ?>

              <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                <i data-lucide="history" class="w-4 h-4 text-slate-500"></i> ประวัติคำขอย้ายห้องพัก
              </h3>

              <?php if(empty($requestHistory)): ?>
                  <div class="p-8 text-center text-slate-400 text-xs">
                      <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-2">
                          <i data-lucide="clock" class="w-6 h-6"></i>
                      </div>
                      <p class="font-bold text-slate-600">ยังไม่มีประวัติคำขอย้ายห้องพัก</p>
                      <p class="text-[11px] text-slate-400 mt-1">เมื่อคุณส่งคำขอย้ายห้องพัก ประวัติจะแสดงที่นี่</p>
                  </div>
              <?php else: ?>
                  <!-- 🌟 Status Filter Tabs (แบบหน้าแจ้งงาน) 🌟 -->
                  <div class="flex items-center gap-2 mb-4 overflow-x-auto pb-1" id="rcFilterTabs">
                    <button type="button" onclick="filterRoomChange('all')" class="rc-filter-btn active px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white cursor-pointer" data-filter="all">
                      ทั้งหมด (<?php echo $countRcTotal; ?>)
                    </button>
                    <button type="button" onclick="filterRoomChange('Pending')" class="rc-filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-rose-100/80 cursor-pointer" data-filter="Pending">
                      ⏳ รออนุมัติ (<?php echo $countRcPending; ?>)
                    </button>
                    <button type="button" onclick="filterRoomChange('Approved')" class="rc-filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-rose-100/80 cursor-pointer" data-filter="Approved">
                      ✅ อนุมัติแล้ว (<?php echo $countRcApproved; ?>)
                    </button>
                    <?php if($countRcRejected > 0): ?>
                    <button type="button" onclick="filterRoomChange('Rejected')" class="rc-filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-rose-100/80 cursor-pointer" data-filter="Rejected">
                      ❌ ไม่อนุมัติ/ยกเลิก (<?php echo $countRcRejected; ?>)
                    </button>
                    <?php endif; ?>
                  </div>

                  <div class="space-y-3">
                    <?php foreach($requestHistory as $h): 
                      $rcTargetDisp = !empty($h['targetRoomNumber']) 
                        ? ('ห้อง ' . $h['targetRoomNumber'] . ' (' . (($h['targetRoomTypeSpecific'] === 'Air') ? 'ห้องแอร์' : 'ห้องพัดลม') . ')')
                        : (($h['targetRoomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม');
                      $rcModalPayload = [
                        'roomNumber' => $myRoom['roomNumber'] ?? '',
                        'targetDisplay' => $rcTargetDisp,
                        'tenantName' => $tenantName,
                        'reason' => $h['reason'] ?? '',
                        'preferredMoveDate' => !empty($h['preferredMoveDate']) ? date('d/m/Y', strtotime($h['preferredMoveDate'])) : '-',
                        'createdAt' => date('d/m/Y H:i', strtotime($h['created_at'])) . ' น.',
                        'status' => $h['status'],
                        'slipUrl' => !empty($h['paymentSlip']) ? $h['paymentSlip'] : '',
                        'adminRemark' => $h['adminRemark'] ?? ''
                      ];
                      $rcPayloadJson = htmlspecialchars(json_encode($rcModalPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    ?>
                      <div data-status="<?php echo htmlspecialchars($h['status']); ?>" 
                           onclick='openRoomChangeModal(<?php echo $rcPayloadJson; ?>)'
                           class="rc-card p-3.5 rounded-2xl border border-rose-100/60 bg-white hover:border-amber-300 hover:shadow-xs transition-all cursor-pointer flex items-start justify-between gap-3 text-xs group">

                        <div class="flex-1">
                          <div class="font-bold text-slate-800 flex items-center gap-1.5 flex-wrap">
                            <?php if($h['status'] === 'Approved'): ?>
                                <span class="text-emerald-700">อนุมัติย้ายเข้า:</span>
                                <?php if(!empty($h['targetRoomNumber'])): ?>
                                    <strong>ห้อง <?php echo $h['targetRoomNumber']; ?></strong> (<?php echo ($h['targetRoomTypeSpecific'] === 'Air') ? 'ห้องแอร์' : 'ห้องพัดลม'; ?>)
                                <?php else: ?>
                                    <strong><?php echo ($h['targetRoomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?></strong>
                                <?php endif; ?>
                                <?php if(!empty($myRoom['roomNumber']) && !empty($h['targetRoomNumber']) && $myRoom['roomNumber'] == $h['targetRoomNumber']): ?>
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-800 rounded-md font-bold text-[10px]">ห้องปัจจุบันของคุณ</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>ขอย้ายไป:</span>
                                <?php if(!empty($h['targetRoomNumber'])): ?>
                                    <strong>ห้อง <?php echo $h['targetRoomNumber']; ?></strong> (<?php echo ($h['targetRoomTypeSpecific'] === 'Air') ? 'ห้องแอร์' : 'ห้องพัดลม'; ?>)
                                <?php else: ?>
                                    <strong><?php echo ($h['targetRoomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?></strong>
                                <?php endif; ?>
                            <?php endif; ?>
                          </div>

                          <div class="text-slate-600 mt-1">เหตุผล: <?php echo htmlspecialchars($h['reason']); ?></div>
                          <div class="text-[11px] text-slate-400 mt-1 font-mono">ยื่นเมื่อ: <?php echo date("d/m/Y H:i", strtotime($h['created_at'])); ?> น.</div>

                          <div class="mt-2 flex flex-wrap gap-2" onclick="event.stopPropagation()">
                            <button type="button" onclick='openRoomChangeModal(<?php echo $rcPayloadJson; ?>)' class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 text-amber-800 hover:bg-amber-100 border border-amber-200 rounded-lg text-[11px] font-bold transition cursor-pointer">
                              <i data-lucide="eye" class="w-3.5 h-3.5 text-amber-600"></i> ดูรายละเอียด & ขั้นตอน
                            </button>
                            <?php if(!empty($h['paymentSlip'])): ?>
                              <button type="button" onclick="openImageModal('<?php echo htmlspecialchars($h['paymentSlip']); ?>', 'หลักฐานสลิปโอนค่าทำความสะอาด 500 บาท')" class="px-2 py-0.5 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-md text-[10px] font-bold border border-rose-200 transition flex items-center gap-1 cursor-pointer">
                                <i data-lucide="image" class="w-3 h-3"></i> ดูสลิปค่าทำความสะอาด 500฿
                              </button>
                            <?php endif; ?>
                          </div>

                          <?php if(!empty($h['adminRemark'])): ?>
                            <div class="text-[11px] text-slate-600 mt-1.5 bg-white p-2.5 rounded-xl border border-rose-100/80 shadow-2xs">
                              💬 นิติบุคคล: <?php echo htmlspecialchars($h['adminRemark']); ?>
                            </div>
                          <?php endif; ?>
                        </div>

                        <div class="shrink-0 flex items-center gap-2">
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
                          <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 group-hover:translate-x-0.5 transition-transform"></i>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
              <?php endif; ?>
            </div>
          </div> <!-- End #content-rc-history -->



      <?php endif; ?>


      <!-- 🌟 MODAL: แสดงรายละเอียดคำขอย้ายห้องพักและขั้นตอนการดำเนินงานแบบเต็มหน้าจอ 🌟 -->
      <div id="roomChangeDetailModal" class="hidden fixed inset-0 z-50 bg-white flex flex-col w-full h-full min-h-screen overflow-hidden">
          <!-- Modal Header (Full Width Top Bar) -->
          <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 text-white px-5 py-3.5 sm:px-8 sm:py-4.5 flex items-center justify-between shrink-0 shadow-md">
              <div class="flex items-center gap-3">
                  <button type="button" onclick="closeRoomChangeModal()" class="w-9 h-9 rounded-2xl bg-black/20 hover:bg-black/35 text-white flex items-center justify-center transition cursor-pointer shadow-xs shrink-0" title="ย้อนกลับ">
                      <i data-lucide="arrow-left" class="w-5 h-5"></i>
                  </button>
                  <div>
                      <div class="flex flex-wrap items-center gap-2 mb-1">
                          <span class="px-2.5 py-0.5 rounded-full bg-white/20 text-white text-[11px] font-bold tracking-wide backdrop-blur-xs flex items-center gap-1 shadow-xs">
                              <i data-lucide="repeat" class="w-3.5 h-3.5"></i> คำขอย้ายห้องพัก
                          </span>
                          <span id="rcModalStatusBadge" class="px-2.5 py-0.5 rounded-full bg-white text-amber-900 text-[11px] font-bold shadow-xs">
                              ⏳ รอแอดมินอนุมัติ
                          </span>
                          <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/25 border border-white/30 text-white text-[11px] font-semibold flex items-center gap-1.5 backdrop-blur-xs">
                              <span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse"></span> อัปเดตสดเรียลไทม์
                          </span>
                      </div>
                      <h2 id="rcModalTitle" class="text-lg sm:text-2xl font-extrabold font-display tracking-tight text-white leading-tight">รายละเอียดคำขอย้ายห้องพัก</h2>
                      <p id="rcModalSubtitle" class="text-xs sm:text-sm text-amber-100 mt-0.5 flex items-center gap-2">
                          <span>ห้องปัจจุบัน <strong id="rcModalRoomNum" class="text-white">...</strong></span>
                          <span>•</span>
                          <span>ผู้เช่า: <strong id="rcModalTenantName" class="text-white">...</strong></span>
                      </p>
                  </div>
              </div>
              <button type="button" onclick="closeRoomChangeModal()" class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-xl text-white font-bold text-xs transition backdrop-blur-xs cursor-pointer shadow-xs">
                  <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
              </button>
          </div>

          <!-- Modal Body (Full Width Scrollable, Centered content) -->
          <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-gradient-to-b from-amber-50/30 via-slate-50/50 to-white">
              <div class="max-w-4xl lg:max-w-5xl mx-auto w-full space-y-6">
                  
                  <!-- 🌟 Progress Stepper (ขั้นตอนการดำเนินงานแบบกว้างเต็มตา) 🌟 -->
                  <div class="p-5 sm:p-6 bg-gradient-to-br from-amber-50/90 via-orange-50/40 to-amber-50/90 rounded-3xl border border-amber-200/90 shadow-xs space-y-4">
                      <div class="flex flex-wrap items-center justify-between gap-2 text-xs sm:text-sm font-bold text-amber-950">
                          <span class="flex items-center gap-2">
                              <i data-lucide="milestone" class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600"></i>
                              <span>สถานะขั้นตอนการดำเนินงาน:</span>
                          </span>
                          <span id="rcModalStepIndicator" class="text-amber-900 bg-amber-200/80 px-3 py-1 rounded-full text-xs font-extrabold border border-amber-300/60 shadow-2xs">
                              ขั้นตอนที่ 2 จาก 3 (กำลังดำเนินการ)
                          </span>
                      </div>

                      <!-- 3 Steps Bar (3 Columns on SM+, spacious layout) -->
                      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 text-center">
                          <div id="rcStep1Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs">
                              <span id="rcStep1Icon" class="w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs">✓</span>
                              <span class="font-bold text-xs sm:text-sm">1. ยื่นคำขอแล้ว</span>
                              <span id="rcStep1Time" class="text-[11px] text-emerald-700/80 font-normal">บันทึกคำขอเข้าระบบ</span>
                          </div>
                          <div id="rcStep2Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-amber-100 border-amber-400 text-amber-950 shadow-xs ring-2 ring-amber-300/60 transition-all">
                              <span id="rcStep2Icon" class="w-9 h-9 rounded-full bg-amber-600 text-white flex items-center justify-center text-sm font-bold animate-pulse shadow-xs">⏳</span>
                              <span class="font-bold text-xs sm:text-sm">2. ตรวจสอบห้องว่าง</span>
                              <span id="rcStep2Time" class="text-[11px] text-amber-800 font-bold">กำลังดำเนินการ</span>
                          </div>
                          <div id="rcStep3Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-stone-50 border-stone-200 text-stone-400 transition-all">
                              <span id="rcStep3Icon" class="w-9 h-9 rounded-full bg-stone-200 text-stone-500 flex items-center justify-center text-sm font-bold">3</span>
                              <span class="font-bold text-xs sm:text-sm">3. อนุมัติย้ายห้อง</span>
                              <span id="rcStep3Time" class="text-[11px] text-stone-400 font-normal">รออนุมัติ</span>
                          </div>
                      </div>

                      <!-- Step Description Box -->
                      <div id="rcModalStepDesc" class="p-4 bg-white/95 rounded-2xl border border-amber-200/90 text-xs sm:text-sm text-amber-950 leading-relaxed shadow-2xs flex items-start gap-2.5">
                          <i data-lucide="info" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
                          <div id="rcModalStepDescText">
                              กำลังรอนิติบุคคลตรวจสอบห้องว่างตามประเภทที่ระบุ พร้อมจัดเตรียมการย้ายห้อง
                          </div>
                      </div>
                  </div>

                  <!-- 🌟 ตารางข้อมูลรายละเอียดคำขอย้ายห้อง (Grid 2 คอลัมน์) 🌟 -->
                  <div class="bg-white rounded-3xl border border-stone-200/90 shadow-xs overflow-hidden">
                      <div class="p-4 bg-stone-50 border-b border-stone-200/80 flex items-center gap-2">
                          <i data-lucide="file-spreadsheet" class="w-4 h-4 text-slate-500"></i>
                          <h4 class="text-xs sm:text-sm font-bold text-slate-700">ข้อมูลสรุปคำขอย้ายห้องพัก</h4>
                      </div>

                      <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-stone-100 text-xs sm:text-sm">
                          <div class="p-4 sm:p-5 space-y-4">
                              <div class="flex justify-between items-center">
                                  <span class="text-slate-500 flex items-center gap-2"><i data-lucide="home" class="w-4 h-4 text-slate-400"></i> ห้องพักปัจจุบัน</span>
                                  <strong id="rcModalInfoRoom" class="text-slate-800 text-sm sm:text-base font-bold">ห้อง ...</strong>
                              </div>
                              <div class="flex justify-between items-center">
                                  <span class="text-slate-500 flex items-center gap-2"><i data-lucide="door-open" class="w-4 h-4 text-amber-600"></i> ย้ายไปห้องพัก</span>
                                  <strong id="rcModalInfoTarget" class="text-amber-800 bg-amber-50 px-3 py-1 rounded-xl font-bold text-xs sm:text-sm border border-amber-200">...</strong>
                              </div>
                          </div>

                          <div class="p-4 sm:p-5 space-y-4">
                              <div class="flex justify-between items-center">
                                  <span class="text-slate-500 flex items-center gap-2"><i data-lucide="calendar" class="w-4 h-4 text-slate-400"></i> วันเวลาที่ยื่นคำขอ</span>
                                  <span id="rcModalInfoCreatedAt" class="font-mono text-slate-800 font-bold">...</span>
                              </div>
                          </div>
                      </div>

                      <!-- Highlighted Preferred Move Date -->
                      <div class="p-4 sm:p-5 bg-gradient-to-r from-rose-50 via-pink-50/60 to-rose-50 border-t border-rose-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                          <div class="flex items-center gap-2 text-rose-900 font-bold text-xs sm:text-sm">
                              <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-2xs">
                                  <i data-lucide="calendar-heart" class="w-4 h-4"></i>
                              </div>
                              <div>
                                  <div class="text-sm font-extrabold">วันที่ประสงค์ขอย้าย (คำขอวันที่ขอย้าย):</div>
                                  <span class="text-[11px] text-rose-700/80 font-normal">กำหนดการที่ต้องการย้ายเข้าห้องใหม่</span>
                              </div>
                          </div>
                          <strong id="rcModalInfoMoveDate" class="text-rose-700 font-extrabold text-sm sm:text-base font-mono bg-white px-4 py-2.5 rounded-2xl border border-rose-200/90 shadow-2xs text-center">
                              ...
                          </strong>
                      </div>

                      <!-- Reason & Slip -->
                      <div class="p-4 sm:p-5 border-t border-stone-100 space-y-3.5 text-xs sm:text-sm">
                          <div>
                              <span class="text-slate-500 flex items-center gap-2 mb-1.5 font-bold"><i data-lucide="file-text" class="w-4 h-4 text-slate-400"></i> เหตุผลการขอย้าย:</span>
                              <div id="rcModalInfoReason" class="text-slate-800 font-medium pl-6 leading-relaxed">...</div>
                          </div>

                          <div id="rcModalSlipWrap" class="p-3.5 bg-emerald-50/80 rounded-2xl border border-emerald-200/80 flex items-center justify-between">
                              <span class="text-emerald-900 font-bold flex items-center gap-2"><i data-lucide="receipt" class="w-4 h-4 text-emerald-600"></i> ค่าทำความสะอาด (500 บาท):</span>
                              <button type="button" id="rcModalSlipBtn" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-2xs transition flex items-center gap-1.5 cursor-pointer">
                                  <i data-lucide="image" class="w-4 h-4"></i> ดูสลิปแนบ
                              </button>
                          </div>
                      </div>
                  </div>

                  <!-- Admin Remark (if any) -->
                  <div id="rcModalRemarkWrap" class="hidden p-4 sm:p-5 bg-white rounded-3xl border border-slate-200/90 text-xs sm:text-sm shadow-xs space-y-2">
                      <div class="text-xs font-bold text-slate-600 flex items-center gap-2">
                          <i data-lucide="message-square" class="w-4 h-4 text-slate-500"></i> หมายเหตุจากนิติบุคคล:
                      </div>
                      <div id="rcModalRemarkText" class="text-slate-800 font-medium pl-6 leading-relaxed"></div>
                  </div>
              </div>
          </div>

          <!-- Modal Footer (Full Width Bottom Bar) -->
          <div class="px-5 py-3 sm:px-8 sm:py-3.5 bg-white border-t border-slate-200/90 shrink-0 flex items-center justify-between shadow-lg">
              <span class="text-xs text-slate-500 flex items-center gap-2">
                  <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                  <span class="font-medium">ระบบกำลังเชื่อมต่อและอัปเดตสถานะอัตโนมัติ</span>
              </span>
              <button type="button" onclick="closeRoomChangeModal()" class="px-6 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl text-xs sm:text-sm transition cursor-pointer shadow-sm active:scale-95 flex items-center gap-1.5">
                  <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
              </button>
          </div>
      </div>

            </div> <!-- End #content-rc-history -->
</main>



  <!-- MODAL: ดูรูปภาพสลิป -->

  <div id="imagePreviewModal" class="hidden fixed inset-0 z-[60] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4" onclick="closeImageModal()">

    <div class="relative max-w-2xl w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">

      <button type="button" onclick="closeImageModal()" class="absolute -top-12 right-0 text-white/70 hover:text-rose-400 p-2 transition flex items-center gap-2 font-bold text-sm">

        ปิดรูปภาพ <i data-lucide="x-circle" class="w-6 h-6"></i>

      </button>

      <div id="imageTitle" class="absolute -top-10 left-0 text-white font-bold text-sm bg-black/50 px-3 py-1 rounded-full backdrop-blur-md"></div>

      <img id="previewImage" src="" alt="Slip Preview" class="max-h-[80vh] max-w-full rounded-2xl shadow-2xl object-contain border-2 border-white/10 bg-black/50">

    </div>

  </div>



  <script>
    lucide.createIcons();

    // 🌟 ระบบสลับแท็บ: คำขอย้ายห้อง vs ประวัติคำขอ 🌟
    function switchRoomChangeTab(tab) {
        const tabReq = document.getElementById('tab-rc-request');
        const tabHist = document.getElementById('tab-rc-history');
        const contentReq = document.getElementById('content-rc-request');
        const contentHist = document.getElementById('content-rc-history');

        if (tab === 'request') {
            if (tabReq) tabReq.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            if (tabHist) tabHist.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            if (contentReq) contentReq.classList.remove('hidden');
            if (contentHist) contentHist.classList.add('hidden');
        } else {
            if (tabHist) tabHist.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-rose-600 text-rose-600 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            if (tabReq) tabReq.className = 'px-4 py-2 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
            if (contentHist) contentHist.classList.remove('hidden');
            if (contentReq) contentReq.classList.add('hidden');
        }
        try {
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        } catch(e) {}
        if (window.lucide) lucide.createIcons();
    }

    function openDocModal(url, title) {
        openImageModal(url, title || 'ดูเอกสารหลักฐาน');
    }

    function openImageModal(url, titleText) {

        document.getElementById('previewImage').src = url;

        document.getElementById('imageTitle').textContent = titleText;

        document.getElementById('imagePreviewModal').classList.remove('hidden');

    }

    

    function closeImageModal() {

        document.getElementById('imagePreviewModal').classList.add('hidden');

        document.getElementById('previewImage').src = ''; 

    }



    function toggleChoiceDisplay(choice) {

      if (choice === 'type') {

        document.getElementById('wrap_type').classList.remove('hidden');

        document.getElementById('wrap_specific_room').classList.add('hidden');

      } else {

        document.getElementById('wrap_type').classList.add('hidden');

        document.getElementById('wrap_specific_room').classList.remove('hidden');

      }

    }



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

    <?php if($pendingRequest): ?>
    let knownRcStatus = '<?php echo $pendingRequest['status'] ?? 'Pending'; ?>';
    let pollInterval = setInterval(function() {
      fetch('tenant_room_change.php?check_status=1')
        .then(res => res.json())
        .then(data => {
          if (!data || !data.status) return;

          // ถ้าสถานะเปลี่ยน หรือมีข้อมูลใหม่
          if (data.status !== knownRcStatus) {
            knownRcStatus = data.status;

            // 1. อัปเดตป้ายสถานะบนการ์ดหน้าหลัก
            const pageBadge = document.getElementById('pendingRoomChangeCardStatus');
            if (pageBadge) {
              if (data.status === 'Approved') {
                pageBadge.className = 'px-2.5 py-0.5 bg-emerald-100 text-emerald-900 border border-emerald-300 rounded-full font-bold text-[11px] shadow-2xs';
                pageBadge.innerHTML = '✓ อนุมัติแล้ว';
              } else if (data.status === 'Rejected') {
                pageBadge.className = 'px-2.5 py-0.5 bg-rose-100 text-rose-900 border border-rose-300 rounded-full font-bold text-[11px] shadow-2xs';
                pageBadge.innerHTML = '❌ ไม่อนุมัติ';
              }
            }

            // 2. ถ้าเปิด Modal ค้างอยู่ ให้อัปเดตข้อมูลสดๆ ทันที
            const modal = document.getElementById('roomChangeDetailModal');
            const isModalOpen = modal && !modal.classList.contains('hidden') && modal.style.display !== 'none';
            if (isModalOpen && currentModalRcData) {
              currentModalRcData.status = data.status;
              if (data.adminRemark) currentModalRcData.adminRemark = data.adminRemark;
              if (data.preferredMoveDate) currentModalRcData.preferredMoveDate = data.preferredMoveDate;
              renderRoomChangeModalData(currentModalRcData);
              if (window.lucide) lucide.createIcons();
            }

            // 3. แสดง SweetAlert2 แจ้งเตือนผู้เช่า
            if (data.status === 'Approved') {
              clearInterval(pollInterval);
              Swal.fire({
                icon: 'success',
                title: '🎉 คำขอย้ายห้องพักได้รับการอนุมัติแล้ว!',
                html: `<div class="space-y-2 text-left">
                         <p class="text-sm text-slate-600">แอดมินได้อนุมัติคำขอย้ายห้องพักของคุณเรียบร้อยแล้ว</p>
                         ${data.adminRemark ? `<div class="text-xs text-slate-500 bg-slate-50 p-2 rounded-lg">💬 หมายเหตุ: ${data.adminRemark}</div>` : ''}
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
                title: '❌ คำขอย้ายห้องพักถูกปฏิเสธ',
                html: `<p class="text-sm text-slate-600">${data.adminRemark || 'แอดมินได้ปฏิเสธคำขอย้ายห้องพัก'}</p>`,
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

  
    function selectTargetRoomType(type) {
        document.getElementById('targetRoomTypeInput').value = type;
        const btnAir = document.getElementById('btnTargetAir');
        const btnFan = document.getElementById('btnTargetFan');
        const checkAir = document.getElementById('checkAir');
        const checkFan = document.getElementById('checkFan');

        if (type === 'Air') {
            btnAir.className = 'p-3.5 rounded-2xl border-2 flex items-center justify-between text-left transition-all cursor-pointer border-sky-500 bg-gradient-to-br from-sky-50 to-blue-50/70 text-sky-950 font-bold shadow-xs';
            btnFan.className = 'p-3.5 rounded-2xl border-2 flex items-center justify-between text-left transition-all cursor-pointer border-rose-100/80 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkAir) checkAir.classList.remove('hidden');
            if (checkFan) checkFan.classList.add('hidden');
        } else {
            btnFan.className = 'p-3.5 rounded-2xl border-2 flex items-center justify-between text-left transition-all cursor-pointer border-sky-500 bg-gradient-to-br from-sky-50 to-blue-50/70 text-sky-950 font-bold shadow-xs';
            btnAir.className = 'p-3.5 rounded-2xl border-2 flex items-center justify-between text-left transition-all cursor-pointer border-rose-100/80 bg-white hover:border-sky-300 text-slate-700 font-semibold';
            if (checkFan) checkFan.classList.remove('hidden');
            if (checkAir) checkAir.classList.add('hidden');
        }
    }


    function toggleRoomChangeDetails() {
        const el = document.getElementById('rcDetailsExpanded');
        const chevron = document.getElementById('rcChevronIcon');
        const footerChevron = document.getElementById('rcFooterChevron');
        const toggleText = document.getElementById('rcToggleText');
        if (!el) return;
        if (el.classList.contains('hidden')) {
            el.classList.remove('hidden');
            if (chevron) chevron.classList.add('rotate-180');
            if (footerChevron) footerChevron.classList.add('rotate-180');
            if (toggleText) toggleText.textContent = 'คลิกเพื่อย่อรายละเอียด';
        } else {
            el.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
            if (footerChevron) footerChevron.classList.remove('rotate-180');
            if (toggleText) toggleText.textContent = 'คลิกเพื่อดูรายละเอียดเพิ่มเติม';
        }
        if (window.lucide) lucide.createIcons();
    }

    // 🌟 ระบบ Modal รายละเอียดคำขอย้ายห้องพัก & ไทม์ไลน์ขั้นตอน 🌟
    let currentModalRcData = null;

    function openPendingRoomChangeModal() {
        <?php if($pendingRequest): ?>
        openRoomChangeModal({
            roomNumber: <?php echo json_encode($myRoom['roomNumber'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
            tenantName: <?php echo json_encode($tenantName ?? '', JSON_UNESCAPED_UNICODE); ?>,
            targetDisplay: <?php echo json_encode($targetTypeLabel ?? '', JSON_UNESCAPED_UNICODE); ?>,
            createdAt: <?php echo json_encode(!empty($pendingRequest['created_at']) ? date("d/m/Y H:i", strtotime($pendingRequest['created_at'])) . " น." : "-", JSON_UNESCAPED_UNICODE); ?>,
            preferredMoveDate: <?php echo json_encode(!empty($pendingRequest['preferredMoveDate']) ? (isset($formatThaiDateRc) ? $formatThaiDateRc($pendingRequest['preferredMoveDate']) : date("d/m/Y", strtotime($pendingRequest['preferredMoveDate']))) : "-", JSON_UNESCAPED_UNICODE); ?>,
            reason: <?php echo json_encode($pendingRequest['reason'] ?? "", JSON_UNESCAPED_UNICODE); ?>,
            slipUrl: <?php echo json_encode(!empty($pendingRequest['paymentSlip']) ? $pendingRequest['paymentSlip'] : (!empty($pendingRequest['cleaningFeeSlip']) ? "uploads/slips/" . $pendingRequest['cleaningFeeSlip'] : ""), JSON_UNESCAPED_UNICODE); ?>,
            status: <?php echo json_encode($pendingRequest['status'] ?? "Pending", JSON_UNESCAPED_UNICODE); ?>,
            adminRemark: <?php echo json_encode($pendingRequest['adminRemark'] ?? "", JSON_UNESCAPED_UNICODE); ?>
        });
        <?php endif; ?>
    }

    function openRoomChangeModal(data) {
        if (!data) return;
        currentModalRcData = data;
        renderRoomChangeModalData(data);

        const modal = document.getElementById('roomChangeDetailModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        if (window.lucide) lucide.createIcons();
    }

    function renderRoomChangeModalData(data) {
        if (!data) return;
        document.getElementById('rcModalRoomNum').innerText = data.roomNumber || '-';
        document.getElementById('rcModalTenantName').innerText = data.tenantName || '-';
        document.getElementById('rcModalInfoRoom').innerText = 'ห้อง ' + (data.roomNumber || '-');
        document.getElementById('rcModalInfoTarget').innerText = data.targetDisplay || '-';
        document.getElementById('rcModalInfoCreatedAt').innerText = data.createdAt || '-';
        document.getElementById('rcModalInfoMoveDate').innerText = data.preferredMoveDate || '-';
        document.getElementById('rcModalInfoReason').innerText = data.reason || '-';

        if (data.slipUrl) {
            document.getElementById('rcModalSlipWrap').classList.remove('hidden');
            document.getElementById('rcModalSlipBtn').onclick = function() {
                openDocModal(data.slipUrl);
            };
        } else {
            document.getElementById('rcModalSlipWrap').classList.add('hidden');
        }

        const status = data.status || 'Pending';
        const badge = document.getElementById('rcModalStatusBadge');
        const indicator = document.getElementById('rcModalStepIndicator');
        const step1Box = document.getElementById('rcStep1Box');
        const step2Box = document.getElementById('rcStep2Box');
        const step3Box = document.getElementById('rcStep3Box');
        const descText = document.getElementById('rcModalStepDescText');

        if (status === 'Approved') {
            badge.className = 'px-3 py-1 rounded-full bg-emerald-500 text-white text-xs font-bold shadow-xs';
            badge.innerHTML = '✓ อนุมัติแล้ว';
            indicator.innerText = 'ขั้นตอนที่ 3 จาก 3 (เสร็จสมบูรณ์)';
            indicator.className = 'text-emerald-900 bg-emerald-200/90 px-3 py-1 rounded-full text-xs font-extrabold border border-emerald-300 shadow-2xs';

            step1Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs';
            step2Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-2xs';
            step2Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs';
            step2Box.querySelector('span:first-child').innerHTML = '✓';
            document.getElementById('rcStep2Time').innerText = 'ตรวจสอบแล้ว';
            document.getElementById('rcStep2Time').className = 'text-[11px] text-emerald-700/80 font-normal';

            step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-400 text-emerald-950 ring-2 ring-emerald-300/70 transition-all shadow-xs';
            step3Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center text-sm font-bold shadow-xs';
            step3Box.querySelector('span:first-child').innerHTML = '✓';
            document.getElementById('rcStep3Time').innerText = 'อนุมัติเรียบร้อย';
            document.getElementById('rcStep3Time').className = 'text-[11px] text-emerald-800 font-bold';

            if (descText) {
                descText.innerHTML = '<span class="text-emerald-800 font-bold">🎉 คำขอย้ายห้องพักได้รับการอนุมัติเรียบร้อยแล้ว</span> สัญญาได้รับการปรับเปลี่ยนเป็นห้องใหม่เรียบร้อยแล้ว';
            }
        } else if (status === 'Rejected') {
            badge.className = 'px-3 py-1 rounded-full bg-rose-500 text-white text-xs font-bold shadow-xs';
            badge.innerHTML = '❌ ไม่อนุมัติ';
            indicator.innerText = 'คำขอถูกปฏิเสธ';
            indicator.className = 'text-rose-900 bg-rose-200/90 px-3 py-1 rounded-full text-xs font-extrabold border border-rose-300 shadow-2xs';

            if (descText) {
                descText.innerHTML = '<span class="text-rose-800 font-bold">❌ คำขอย้ายห้องพักไม่ได้รับการอนุมัติ</span>' + (data.adminRemark ? '<br>เหตุผล: ' + data.adminRemark : '');
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
            document.getElementById('rcStep2Time').innerText = 'กำลังดำเนินการ';
            document.getElementById('rcStep2Time').className = 'text-[11px] text-amber-800 font-bold';

            step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-stone-50 border-stone-200 text-stone-400 transition-all';
            step3Box.querySelector('span:first-child').className = 'w-9 h-9 rounded-full bg-stone-200 text-stone-500 flex items-center justify-center text-sm font-bold';
            step3Box.querySelector('span:first-child').innerHTML = '3';
            document.getElementById('rcStep3Time').innerText = 'รออนุมัติ';

            if (descText) {
                descText.innerHTML = 'กำลังรอนิติบุคคลและแอดมินตรวจสอบห้องว่างตามคำขอ และจัดเตรียมดำเนินการย้ายในวันที่ <strong>' + (data.preferredMoveDate || '-') + '</strong>';
            }
        }

        if (data.adminRemark) {
            document.getElementById('rcModalRemarkWrap').classList.remove('hidden');
            document.getElementById('rcModalRemarkText').innerText = data.adminRemark;
        } else {
            document.getElementById('rcModalRemarkWrap').classList.add('hidden');
        }
    }

    // Filter tabs in Room Change History (แบบหน้าแจ้งงาน)
    function filterRoomChange(status) {
      document.querySelectorAll('#rcFilterTabs .rc-filter-btn').forEach(btn => {
        if (btn.getAttribute('data-filter') === status) {
          btn.classList.add('bg-slate-800', 'text-white');
          btn.classList.remove('bg-white', 'text-slate-600');
        } else {
          btn.classList.remove('bg-slate-800', 'text-white');
          btn.classList.add('bg-white', 'text-slate-600');
        }
      });

      const cards = document.querySelectorAll('.rc-card');
      cards.forEach(card => {
        const cStatus = card.getAttribute('data-status');
        if (status === 'all') {
          card.style.display = '';
        } else if (status === 'Rejected') {
          if (cStatus === 'Rejected' || cStatus === 'Cancelled') {
            card.style.display = '';
          } else {
            card.style.display = 'none';
          }
        } else {
          if (cStatus === status) {
            card.style.display = '';
          } else {
            card.style.display = 'none';
          }
        }
      });
    }

    function closeRoomChangeModal() {
        const modal = document.getElementById('roomChangeDetailModal');
        if (modal) {
            modal.classList.add('hidden');
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRoomChangeModal();
        }
    });

</script>

</body>

</html>

