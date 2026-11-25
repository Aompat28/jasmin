<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น ลูกบ้าน (Tenant) เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {
    header("Location: login.php");
    exit();
}

$tenantId = (int)$_SESSION['user_id'];
$tenantName = $_SESSION['full_name'] ?? 'ผู้เช่า';
$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));
$message = "";

// 1. ดึงข้อมูลห้องพักและสัญญาปัจจุบันของผู้เช่า
$stmtRoom = $conn->prepare("
    SELECT r.roomId, r.roomNumber, r.roomType, r.floor, l.leaseId, l.startDate, l.endDate 
    FROM Lease l 
    JOIN Room r ON l.roomId = r.roomId 
    WHERE l.tenantId = ? AND l.status = 'Active' 
    LIMIT 1
");
$stmtRoom->execute([$tenantId]);
$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);
$myRoomNumber = $myRoom ? ($myRoom['roomNumber'] ?? '') : '';
if (empty($myRoomNumber)) {
    $stmtHdrR = $conn->prepare("SELECT r.roomNumber, r.roomId FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");
    $stmtHdrR->execute([$tenantId]);
    $hdrRoom = $stmtHdrR->fetch(PDO::FETCH_ASSOC);
    if ($hdrRoom) {
        $myRoomNumber = $hdrRoom['roomNumber'] ?? '';
        if (!$myRoom) $myRoom = $hdrRoom;
    }
}
$roomId = $myRoom ? (int)$myRoom['roomId'] : null;

// --- 2. จัดการคำขอเพิ่มรถ & สิทธิ์ที่จอดรถ (Submit Request) ---
if (isset($_POST['submit_parking_request'])) {
    if (!$roomId) {
        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> ไม่สามารถส่งคำขอได้ เนื่องจากคุณยังไม่มีสัญญาเช่าห้องพักที่เปิดใช้งานอยู่</div>";
    } else {
        $currentPlate = trim($_POST['currentPlate'] ?? '');
        $additionalPlate = trim($_POST['additionalPlate'] ?? '');
        $vehicleType = trim($_POST['vehicleType'] ?? 'Car');
        $brandModel = trim($_POST['brandModel'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $vehicleImage = null;

        if (empty($currentPlate) || empty($additionalPlate)) {
            $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> กรุณากรอกทั้ง 'ป้ายทะเบียนรถคันปัจจุบัน' และ 'ป้ายทะเบียนรถคันที่ขอเพิ่ม' ให้ครบถ้วน</div>";
        } else {
            // ตรวจสอบว่ามีคำขอของทะเบียนคันที่ขอเพิ่มนี้ค้างอยู่หรือไม่
            $checkStmt = $conn->prepare("
                SELECT id, status FROM parking_requests 
                WHERE tenantId = ? AND roomId = ? AND additionalPlate = ? AND status IN ('Pending', 'Approved')
                LIMIT 1
            ");
            $checkStmt->execute([$tenantId, $roomId, $additionalPlate]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['status'] === 'Approved') {
                $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5 shrink-0'></i> ป้ายทะเบียน {$additionalPlate} ได้รับการอนุมัติสิทธิ์จอดรถเรียบร้อยแล้ว ไม่จำเป็นต้องส่งคำขอซ้ำ</div>";
            } elseif ($existing && $existing['status'] === 'Pending') {
                $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5 shrink-0'></i> มีคำขอสำหรับป้ายทะเบียน {$additionalPlate} อยู่ระหว่างรอแอดมินตรวจสอบแล้ว กรุณาอย่าส่งคำขอซ้ำ</div>";
            } else {
                // จัดการอัปโหลดรูปถ่ายยานพาหนะหรือเอกสาร (ถ้ามี)
                if (isset($_FILES['vehicle_image']) && $_FILES['vehicle_image']['error'] === UPLOAD_ERR_OK && !empty($_FILES['vehicle_image']['tmp_name'])) {
                    $uploadDir = 'uploads/';
                    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
                    $ext = strtolower(pathinfo($_FILES['vehicle_image']['name'], PATHINFO_EXTENSION));
                    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                    if (in_array($ext, $allowedExts)) {
                        $fileName = time() . '_car_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $targetPath = $uploadDir . $fileName;
                        if (move_uploaded_file($_FILES['vehicle_image']['tmp_name'], $targetPath)) {
                            $vehicleImage = $targetPath;
                        }
                    }
                }

                try {
                    $insStmt = $conn->prepare("
                        INSERT INTO parking_requests 
                        (tenantId, roomId, currentPlate, additionalPlate, vehicleType, brandModel, province, vehicleImage, reason, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
                    ");
                    $insStmt->execute([$tenantId, $roomId, $currentPlate, $additionalPlate, $vehicleType, $brandModel, $province, $vehicleImage, $reason]);
                    $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 shrink-0 text-emerald-600'></i> ส่งคำขอเพิ่มรถและที่จอดรถสำเร็จแล้ว! แอดมินจะทำการตรวจสอบและอนุมัติให้โดยเร็ว</div>";
                } catch(PDOException $e) {
                    $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> เกิดข้อผิดพลาดในการบันทึก: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
    }
}

// --- 3. จัดการยกเลิกคำขอโดยผู้เช่า (Cancel Pending Request) ---
if (isset($_POST['cancel_request'])) {
    $reqId = (int)$_POST['request_id'];
    try {
        $chkStmt = $conn->prepare("SELECT id, status, vehicleImage FROM parking_requests WHERE id = ? AND tenantId = ?");
        $chkStmt->execute([$reqId, $tenantId]);
        $reqItem = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($reqItem && $reqItem['status'] === 'Pending') {
            $upd = $conn->prepare("UPDATE parking_requests SET status = 'Cancelled', cancelledAt = NOW(), cancelReason = 'ลูกบ้านขอยกเลิกคำขอด้วยตนเอง' WHERE id = ?");
            $upd->execute([$reqId]);
            $message = "<div class='p-4 mb-6 bg-slate-100 border-l-4 border-slate-500 text-slate-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check' class='w-5 h-5 shrink-0 text-slate-600'></i> ยกเลิกคำขอเพิ่มรถเรียบร้อยแล้ว</div>";
        } else {
            $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5 shrink-0'></i> ไม่สามารถยกเลิกได้ เนื่องจากรายการนี้ได้รับการพิจารณาไปแล้ว</div>";
        }
    } catch(PDOException $e) {
        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// 4. ดึงรายการคำขอทั้งหมดของลูกบ้านคนนี้
$parkingRequests = [];
if ($roomId) {
    try {
        $stmtList = $conn->prepare("
            SELECT p.*, r.roomNumber 
            FROM parking_requests p 
            JOIN Room r ON p.roomId = r.roomId 
            WHERE p.tenantId = ? 
            ORDER BY p.id DESC
        ");
        $stmtList->execute([$tenantId]);
        $parkingRequests = $stmtList->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {}
}

// สรุปสถิติ
$pendingCount = 0;
$approvedCount = 0;
$cancelledCount = 0;
$latestCurrentPlate = '';

foreach ($parkingRequests as $pr) {
    if ($pr['status'] === 'Pending') $pendingCount++;
    elseif ($pr['status'] === 'Approved') $approvedCount++;
    elseif (in_array($pr['status'], ['Cancelled', 'Rejected'])) $cancelledCount++;

    if (empty($latestCurrentPlate) && !empty($pr['currentPlate'])) {
        $latestCurrentPlate = $pr['currentPlate'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ขอเพิ่มรถ & สิทธิ์ที่จอดรถ - หอพักจัสมิน</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] },
          colors: {
            brand: { 50: '#fff1f2', 100: '#ffe4e6', 500: '#f43f5e', 600: '#e11d48', 700: '#be123c' }
          }
        }
      }
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

  <?php include_once 'includes/tenant_sidebar.php'; ?>

  <!-- Top Navigation (Tenant) -->
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/70 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-cyan-600 via-cyan-500 to-teal-600 flex items-center justify-center text-white shadow-sm ring-1 ring-cyan-500/20 shrink-0">
            <i data-lucide="car" class="w-5 h-5 text-white"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-cyan-50 text-cyan-700 font-bold px-2 py-0.5 rounded-full border border-cyan-200/60 uppercase tracking-wider">PARKING PERMIT</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ระบบขอเพิ่มรถ & ลงทะเบียนที่จอดรถมากกว่า 1 คัน</p>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <div class="flex items-center space-x-2.5 bg-rose-50/50 hover:bg-rose-50/80 border border-rose-100/80 rounded-2xl px-3 py-1.5 transition">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-cyan-500 to-teal-600 text-white flex items-center justify-center font-bold text-xs shadow-sm">
              <?php echo $initials; ?>
            </div>
            <div class="text-left hidden sm:block">
              <div class="text-xs font-bold text-slate-800 leading-tight"><?php echo htmlspecialchars($tenantName); ?></div>
              <div class="text-[10px] text-cyan-600 font-medium">
                <?php echo !empty($myRoomNumber) ? "ห้อง {$myRoomNumber}" : 'ลูกบ้าน'; ?>
              </div>
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
    <div class="bg-gradient-to-r from-cyan-600 via-teal-600 to-emerald-600 rounded-3xl p-6 sm:p-8 text-white shadow-xl shadow-cyan-900/10 mb-8 relative overflow-hidden">
      <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-white/10 rounded-full blur-2xl pointer-events-none"></div>
      <div class="absolute right-8 top-1/2 -translate-y-1/2 hidden md:block text-white/15 pointer-events-none">
        <i data-lucide="car" class="w-44 h-44"></i>
      </div>
      <div class="relative z-10 max-w-2xl">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/20 backdrop-blur-md border border-white/25 text-white text-xs font-semibold mb-3">
          <i data-lucide="shield-check" class="w-3.5 h-3.5"></i> สิทธิ์ที่จอดรถห้องพัก <?php echo htmlspecialchars($myRoomNumber); ?>
        </div>
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-white mb-2">
          ลงทะเบียนขอเพิ่มรถ & สิทธิ์ที่จอดรถ
        </h1>
        <p class="text-cyan-50 text-sm leading-relaxed">
          ในกรณีที่ผู้เช่ามีรถยนต์หรือรถมอเตอร์ไซค์ <span class="font-bold underline text-white">มากกว่า 1 คัน</span> สามารถกรอกข้อมูลป้ายทะเบียนรถคันปัจจุบัน และส่งคำขอเพิ่มรถคันถัดไป เพื่อให้นิติบุคคลตรวจสอบและพิจารณาอนุมัติสิทธิ์เข้าจอด
        </p>
      </div>
    </div>

    <!-- 4 Quick Stat Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <!-- 1. คันปัจจุบัน -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-cyan-50 text-cyan-600 border border-cyan-100 flex items-center justify-center shrink-0">
          <i data-lucide="car-front" class="w-6 h-6"></i>
        </div>
        <div class="min-w-0">
          <div class="text-[11px] font-medium text-slate-500">ทะเบียนรถคันหลัก</div>
          <div class="text-sm sm:text-base font-bold text-slate-900 truncate">
            <?php echo !empty($latestCurrentPlate) ? htmlspecialchars($latestCurrentPlate) : '<span class="text-slate-400 font-normal">ยังไม่ได้ลงทะเบียน</span>'; ?>
          </div>
        </div>
      </div>

      <!-- 2. อนุมัติแล้ว -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100 flex items-center justify-center shrink-0">
          <i data-lucide="check-circle" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">รถที่ได้รับอนุมัติแล้ว</div>
          <div class="text-lg sm:text-xl font-bold text-emerald-600"><?php echo $approvedCount; ?> <span class="text-xs font-normal text-slate-500">คัน</span></div>
        </div>
      </div>

      <!-- 3. รอตรวจสอบ -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 border border-amber-100 flex items-center justify-center shrink-0">
          <i data-lucide="clock" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">คำขอรอตรวจสอบ</div>
          <div class="text-lg sm:text-xl font-bold text-amber-600"><?php echo $pendingCount; ?> <span class="text-xs font-normal text-slate-500">รายการ</span></div>
        </div>
      </div>

      <!-- 4. จำนวนคำขอทั้งหมด -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-slate-50 text-slate-600 border border-slate-200 flex items-center justify-center shrink-0">
          <i data-lucide="layers" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">ประวัติคำขอทั้งหมด</div>
          <div class="text-lg sm:text-xl font-bold text-slate-800"><?php echo count($parkingRequests); ?> <span class="text-xs font-normal text-slate-500">รายการ</span></div>
        </div>
      </div>
    </div>

    <!-- Main Content Layout: Form (Left) & Request History List (Right / Full) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

      <!-- Form Section: กรอกข้อมูลขอเพิ่มรถ (5 Cols on large screen) -->
      <div class="lg:col-span-5">
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden sticky top-24">
          <div class="p-6 border-b border-slate-100 bg-slate-50/60">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-xl bg-cyan-100 text-cyan-700 flex items-center justify-center">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
              </div>
              <div>
                <h2 class="font-display font-bold text-slate-900 text-base">แบบฟอร์มขอเพิ่มรถคันที่ 2+</h2>
                <p class="text-xs text-slate-500">กรอกทะเบียนปัจจุบันและข้อมูลรถที่ขอเพิ่ม</p>
              </div>
            </div>
          </div>

          <form action="tenant_parking.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4" onsubmit="return validateParkingForm(event)">
            
            <!-- ป้ายทะเบียนรถคันปัจจุบัน (Current Plate) -->
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                <span>ป้ายทะเบียนรถคันปัจจุบัน <span class="text-rose-500">*</span></span>
                <span class="text-[10px] text-slate-400 font-normal">รถคันแรกที่จอดอยู่</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                  <i data-lucide="car-front" class="w-4 h-4"></i>
                </div>
                <input type="text" name="currentPlate" id="currentPlate" required
                  value="<?php echo htmlspecialchars($latestCurrentPlate); ?>"
                  placeholder="เช่น 1กข 1234 กทม."
                  class="w-full pl-10 pr-3.5 py-2.5 text-sm bg-slate-50/50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition font-medium text-slate-800">
              </div>
              <p class="text-[11px] text-slate-400 mt-1">ทะเบียนรถคันหลักที่คุณใช้สิทธิ์จอดเดิมในปัจจุบัน</p>
            </div>

            <!-- เส้นคั่นแบ่ง -->
            <div class="border-t border-slate-100 pt-3">
              <div class="inline-flex items-center gap-1.5 text-xs font-bold text-cyan-700 bg-cyan-50 px-2.5 py-1 rounded-lg mb-3">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> ข้อมูลรถคันที่ต้องการขอเพิ่ม
              </div>
            </div>

            <!-- ป้ายทะเบียนรถคันที่ขอเพิ่ม (Additional Plate) -->
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                <span>ป้ายทะเบียนรถคันที่ขอเพิ่ม <span class="text-rose-500">*</span></span>
                <span class="text-[10px] text-cyan-600 font-semibold">คันใหม่ / เพิ่มเติม</span>
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                  <i data-lucide="tag" class="w-4 h-4"></i>
                </div>
                <input type="text" name="additionalPlate" id="additionalPlate" required
                  placeholder="เช่น 5กง 9999"
                  class="w-full pl-10 pr-3.5 py-2.5 text-sm bg-slate-50/50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition font-semibold text-slate-900 tracking-wide">
              </div>
            </div>

            <!-- จังหวัด และ ประเภทยานพาหนะ -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  จังหวัด <span class="text-rose-500">*</span>
                </label>
                <input type="text" name="province" id="province" required
                  placeholder="เช่น กรุงเทพฯ, เชียงใหม่"
                  class="w-full px-3.5 py-2.5 text-sm bg-slate-50/50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800">
              </div>

              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  ประเภทยานพาหนะ <span class="text-rose-500">*</span>
                </label>
                <select name="vehicleType" id="vehicleType" required
                  class="w-full px-3 py-2.5 text-sm bg-slate-50/50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800">
                  <option value="Car">🚗 รถยนต์ (Car)</option>
                  <option value="Motorcycle">🛵 รถมอเตอร์ไซค์ (Motorcycle)</option>
                  <option value="EV">⚡ รถยนต์ไฟฟ้า (EV)</option>
                  <option value="Other">🚐 อื่นๆ (Other)</option>
                </select>
              </div>
            </div>

            <!-- ยี่ห้อ / รุ่น / สี -->
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5">
                ยี่ห้อ / รุ่น / สี
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                  <i data-lucide="info" class="w-4 h-4"></i>
                </div>
                <input type="text" name="brandModel" id="brandModel"
                  placeholder="เช่น Honda City Hatchback สีขาวมุก"
                  class="w-full pl-10 pr-3.5 py-2.5 text-sm bg-slate-50/50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800">
              </div>
            </div>

            <!-- เหตุผลความจำเป็นในการขอเพิ่มที่จอดรถ -->
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5">
                เหตุผลความจำเป็นในการขอเพิ่ม
              </label>
              <textarea name="reason" id="reason" rows="2"
                placeholder="เช่น มีรถมอเตอร์ไซค์สำหรับเดินทางทำงานประจำวัน, มีผู้ร่วมอาศัยนำรถมาใช้งาน"
                class="w-full px-3.5 py-2.5 text-sm bg-slate-50/50 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800 resize-none"></textarea>
            </div>

            <!-- แนบรูปถ่ายรถ หรือ สำเนาคู่มือจดทะเบียน -->
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                <span>รูปถ่ายรถยนต์ หรือ เอกสารคู่มือจดทะเบียน</span>
                <span class="text-[10px] text-slate-400 font-normal">PNG, JPG (ไม่เกิน 5MB)</span>
              </label>
              <div class="relative border-2 border-dashed border-slate-200 hover:border-cyan-400 rounded-2xl p-4 text-center transition bg-slate-50/40 hover:bg-cyan-50/20 group cursor-pointer" onclick="document.getElementById('vehicle_image').click()">
                <input type="file" name="vehicle_image" id="vehicle_image" accept="image/*" class="hidden" onchange="previewVehicleImage(this)">
                <div id="imagePreviewContainer" class="hidden mb-2">
                  <img id="imagePreview" src="#" alt="Preview" class="max-h-32 mx-auto rounded-xl shadow-sm border border-slate-200 object-cover">
                </div>
                <div id="uploadPlaceholder">
                  <i data-lucide="upload-cloud" class="w-8 h-8 mx-auto text-slate-400 group-hover:text-cyan-600 transition mb-1"></i>
                  <p class="text-xs text-slate-600 font-medium group-hover:text-cyan-700 transition">คลิกเลือกรูปภาพ หรือ ลากไฟล์มาวางที่นี่</p>
                  <p class="text-[10px] text-slate-400 mt-0.5">ภาพถ่ายรถที่เห็นป้ายทะเบียนชัดเจน</p>
                </div>
              </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" name="submit_parking_request"
              class="w-full py-3 px-4 rounded-2xl bg-gradient-to-r from-cyan-600 via-teal-600 to-emerald-600 hover:from-cyan-700 hover:via-teal-700 hover:to-emerald-700 text-white font-bold text-sm shadow-md shadow-cyan-600/20 hover:shadow-lg transition-all flex items-center justify-center gap-2 cursor-pointer mt-4">
              <i data-lucide="send" class="w-4 h-4"></i> ส่งคำขอเพิ่มรถให้นิติบุคคลตรวจสอบ
            </button>
          </form>
        </div>
      </div>

      <!-- List Section: ประวัติและสถานะคำขอ (7 Cols on large screen) -->
      <div class="lg:col-span-7">
        <div class="bg-white rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden">
          
          <!-- Filter Tabs & Title -->
          <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
              <h2 class="font-display font-bold text-slate-900 text-lg">รายการคำขอและสิทธิ์ที่จอดรถ</h2>
              <p class="text-xs text-slate-500">ติดตามสถานะการพิจารณาจากนิติบุคคล</p>
            </div>

            <!-- Status Filter Tabs -->
            <div class="inline-flex p-1 bg-slate-100/80 rounded-2xl text-xs font-semibold" id="filterTabs">
              <button type="button" onclick="filterList('all')" class="tab-btn px-3 py-1.5 rounded-xl bg-white text-slate-800 shadow-sm transition cursor-pointer" data-tab="all">
                ทั้งหมด (<?php echo count($parkingRequests); ?>)
              </button>
              <button type="button" onclick="filterList('Pending')" class="tab-btn px-3 py-1.5 rounded-xl text-slate-500 hover:text-slate-800 transition cursor-pointer" data-tab="Pending">
                รอตรวจ (<?php echo $pendingCount; ?>)
              </button>
              <button type="button" onclick="filterList('Approved')" class="tab-btn px-3 py-1.5 rounded-xl text-slate-500 hover:text-slate-800 transition cursor-pointer" data-tab="Approved">
                อนุมัติ (<?php echo $approvedCount; ?>)
              </button>
              <button type="button" onclick="filterList('Cancelled')" class="tab-btn px-3 py-1.5 rounded-xl text-slate-500 hover:text-slate-800 transition cursor-pointer" data-tab="Cancelled">
                ยกเลิก (<?php echo $cancelledCount; ?>)
              </button>
            </div>
          </div>

          <!-- List of Items -->
          <div class="divide-y divide-slate-100" id="requestsListContainer">
            <?php if (empty($parkingRequests)): ?>
              <div class="p-12 text-center">
                <div class="w-16 h-16 rounded-full bg-cyan-50 text-cyan-500 flex items-center justify-center mx-auto mb-3">
                  <i data-lucide="car" class="w-8 h-8"></i>
                </div>
                <h3 class="font-display font-bold text-slate-800 text-base mb-1">ยังไม่มีประวัติการขอเพิ่มรถ</h3>
                <p class="text-xs text-slate-400 max-w-sm mx-auto">หากคุณมีรถมากกว่า 1 คัน สามารถกรอกแบบฟอร์มด้านข้างเพื่อขอสิทธิ์ที่จอดรถเพิ่มเติมได้ทันที</p>
              </div>
            <?php else: ?>
              <?php foreach ($parkingRequests as $pr): 
                $status = $pr['status'];
                $statusBadge = '';
                $statusRowClass = '';
                if ($status === 'Pending') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/60"><span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> รอตรวจสอบ</span>';
                    $statusRowClass = 'item-pending';
                } elseif ($status === 'Approved') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200/60"><i data-lucide="check-circle" class="w-3.5 h-3.5 text-emerald-600"></i> อนุมัติแล้ว</span>';
                    $statusRowClass = 'item-approved';
                } elseif ($status === 'Cancelled') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-600 border border-slate-200"><i data-lucide="slash" class="w-3.5 h-3.5"></i> ยกเลิกแล้ว</span>';
                    $statusRowClass = 'item-cancelled';
                } elseif ($status === 'Rejected') {
                    $statusBadge = '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200/60"><i data-lucide="x-circle" class="w-3.5 h-3.5"></i> ปฏิเสธ</span>';
                    $statusRowClass = 'item-cancelled';
                }

                $vIcon = 'car';
                if ($pr['vehicleType'] === 'Motorcycle') $vIcon = 'bike';
                elseif ($pr['vehicleType'] === 'EV') $vIcon = 'zap';
              ?>
                <div class="p-5 hover:bg-slate-50/80 transition request-card <?php echo $statusRowClass; ?>" data-status="<?php echo htmlspecialchars($status); ?>">
                  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    
                    <!-- Vehicle Info & Plates -->
                    <div class="flex items-start gap-3.5">
                      <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-cyan-50 to-teal-50 border border-cyan-100 text-cyan-600 flex items-center justify-center shrink-0 shadow-sm mt-0.5">
                        <i data-lucide="<?php echo $vIcon; ?>" class="w-6 h-6"></i>
                      </div>
                      <div>
                        <!-- Plates Comparison -->
                        <div class="flex flex-wrap items-center gap-2 mb-1.5">
                          <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold border border-slate-200" title="ทะเบียนรถคันปัจจุบัน">
                            <i data-lucide="car" class="w-3 h-3 text-slate-400"></i> คันเดิม: <?php echo htmlspecialchars($pr['currentPlate']); ?>
                          </span>
                          <i data-lucide="arrow-right" class="w-3.5 h-3.5 text-cyan-500"></i>
                          <span class="inline-flex items-center gap-1 px-3 py-0.5 rounded-lg bg-cyan-50 text-cyan-800 text-sm font-extrabold border border-cyan-200/80" title="ทะเบียนรถคันที่ขอเพิ่ม">
                            <i data-lucide="plus-circle" class="w-3.5 h-3.5 text-cyan-600"></i> คันที่ขอเพิ่ม: <?php echo htmlspecialchars($pr['additionalPlate']); ?>
                          </span>
                        </div>

                        <!-- Brand / Province / Date -->
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                          <?php if(!empty($pr['province'])): ?>
                            <span class="flex items-center gap-1">
                              <i data-lucide="map-pin" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($pr['province']); ?>
                            </span>
                          <?php endif; ?>

                          <?php if(!empty($pr['brandModel'])): ?>
                            <span class="flex items-center gap-1 font-medium text-slate-700">
                              <i data-lucide="info" class="w-3 h-3 text-slate-400"></i> <?php echo htmlspecialchars($pr['brandModel']); ?>
                            </span>
                          <?php endif; ?>

                          <span class="flex items-center gap-1 text-slate-400">
                            <i data-lucide="calendar" class="w-3 h-3"></i> ยื่นเมื่อ: <?php echo date('d/m/Y H:i', strtotime($pr['created_at'])); ?>
                          </span>
                        </div>

                        <!-- Admin Remark if available -->
                        <?php if (!empty($pr['adminRemark'])): ?>
                          <div class="mt-2 text-xs bg-slate-100/90 text-slate-700 px-3 py-1.5 rounded-xl border border-slate-200/60 inline-flex items-center gap-1.5">
                            <i data-lucide="message-square" class="w-3.5 h-3.5 text-cyan-600 shrink-0"></i>
                            <span><strong>หมายเหตุแอดมิน:</strong> <?php echo htmlspecialchars($pr['adminRemark']); ?></span>
                          </div>
                        <?php endif; ?>

                        <!-- Cancel Reason if available -->
                        <?php if (!empty($pr['cancelReason'])): ?>
                          <div class="mt-2 text-xs bg-rose-50 text-rose-700 px-3 py-1.5 rounded-xl border border-rose-100 inline-flex items-center gap-1.5">
                            <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-rose-500 shrink-0"></i>
                            <span><strong>เหตุผลการยกเลิก:</strong> <?php echo htmlspecialchars($pr['cancelReason']); ?></span>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <!-- Status & Actions -->
                    <div class="flex sm:flex-col items-end justify-between sm:justify-center gap-2 shrink-0">
                      <div><?php echo $statusBadge; ?></div>

                      <div class="flex items-center gap-2 mt-1">
                        <!-- ปุ่มดูรายละเอียด Modal -->
                        <button type="button" onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($pr), ENT_QUOTES, 'UTF-8'); ?>)"
                          class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs transition flex items-center gap-1 cursor-pointer">
                          <i data-lucide="eye" class="w-3.5 h-3.5"></i> ดูรายละเอียด
                        </button>

                        <!-- ถ้าเป็น Pending ให้มีปุ่มยกเลิกคำขอ -->
                        <?php if ($status === 'Pending'): ?>
                          <form action="tenant_parking.php" method="POST" onsubmit="return confirmCancelRequest(event)">
                            <input type="hidden" name="request_id" value="<?php echo $pr['id']; ?>">
                            <button type="submit" name="cancel_request"
                              class="px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs transition border border-rose-200/60 flex items-center gap-1 cursor-pointer" title="ยกเลิกคำขอนี้">
                              <i data-lucide="x" class="w-3.5 h-3.5"></i> ยกเลิกคำขอ
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>

                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        </div>
      </div>

    </div>

  </main>

  <!-- ==================== Large Detail Modal ==================== -->
  <div id="detailModal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/60 backdrop-blur-sm p-4 sm:p-6 flex items-center justify-center">
    <div class="relative w-full max-w-2xl bg-white rounded-3xl shadow-2xl border border-slate-200 overflow-hidden modal-enter">
      
      <!-- Modal Header -->
      <div class="p-6 bg-gradient-to-r from-cyan-600 via-teal-600 to-emerald-600 text-white flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-white">
            <i data-lucide="car" class="w-5 h-5"></i>
          </div>
          <div>
            <h3 class="font-display font-bold text-lg text-white" id="modalTitle">รายละเอียดคำขอเพิ่มรถ</h3>
            <p class="text-xs text-cyan-100" id="modalSubtitle">รหัสคำขอ #REQ-000</p>
          </div>
        </div>
        <button type="button" onclick="closeDetailModal()" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition cursor-pointer">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
        
        <!-- Status Banner -->
        <div id="modalStatusBanner" class="p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 flex items-center justify-between">
          <span class="text-xs font-bold" id="modalStatusText">สถานะ: รอการตรวจสอบ</span>
          <span class="text-[11px] text-slate-500" id="modalCreatedAt">ยื่นเมื่อ: -</span>
        </div>

        <!-- 2 Column Comparison: ป้ายทะเบียนเดิม vs ป้ายทะเบียนที่ขอเพิ่ม -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- ทะเบียนรถคันปัจจุบัน -->
          <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200">
            <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1 flex items-center gap-1.5">
              <i data-lucide="car" class="w-3.5 h-3.5 text-slate-400"></i> ป้ายทะเบียนคันปัจจุบัน
            </div>
            <div class="text-base font-extrabold text-slate-800" id="modalCurrentPlate">-</div>
            <div class="text-xs text-slate-400 mt-0.5">คันหลักที่ลงทะเบียนไว้</div>
          </div>

          <!-- ป้ายทะเบียนที่ขอเพิ่ม -->
          <div class="p-4 rounded-2xl bg-cyan-50/80 border border-cyan-200">
            <div class="text-[11px] font-bold text-cyan-700 uppercase tracking-wider mb-1 flex items-center gap-1.5">
              <i data-lucide="plus-circle" class="w-3.5 h-3.5 text-cyan-600"></i> ป้ายทะเบียนคันที่ขอเพิ่ม
            </div>
            <div class="text-base font-extrabold text-cyan-900" id="modalAdditionalPlate">-</div>
            <div class="text-xs text-cyan-600 mt-0.5 font-medium" id="modalProvince">จังหวัด: -</div>
          </div>
        </div>

        <!-- รายละเอียดตัวรถ -->
        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200 space-y-2.5">
          <div class="text-xs font-bold text-slate-700 flex items-center gap-1.5 border-b border-slate-200/80 pb-2">
            <i data-lucide="info" class="w-4 h-4 text-cyan-600"></i> รายละเอียดยานพาหนะ
          </div>
          <div class="grid grid-cols-2 gap-3 text-xs">
            <div>
              <span class="text-slate-400 block">ประเภทยานพาหนะ:</span>
              <span class="font-bold text-slate-800" id="modalVehicleType">-</span>
            </div>
            <div>
              <span class="text-slate-400 block">ยี่ห้อ / รุ่น / สี:</span>
              <span class="font-bold text-slate-800" id="modalBrandModel">-</span>
            </div>
          </div>
          <div>
            <span class="text-slate-400 block text-xs">เหตุผลความจำเป็นในการขอเพิ่ม:</span>
            <p class="text-xs font-medium text-slate-800 mt-1 bg-white p-2.5 rounded-xl border border-slate-200/60 leading-relaxed" id="modalReason">
              -
            </p>
          </div>
        </div>

        <!-- รูปถ่ายรถยนต์ / เอกสารแนบ -->
        <div id="modalImageSection" class="hidden">
          <div class="text-xs font-bold text-slate-700 mb-2 flex items-center gap-1.5">
            <i data-lucide="image" class="w-4 h-4 text-cyan-600"></i> รูปถ่ายรถยนต์ / เอกสารประกอบ
          </div>
          <div class="bg-slate-100 rounded-2xl p-2 text-center border border-slate-200">
            <a id="modalImageLink" href="#" target="_blank" class="block group relative overflow-hidden rounded-xl">
              <img id="modalVehicleImage" src="#" alt="Vehicle" class="max-h-60 mx-auto rounded-xl object-contain group-hover:scale-105 transition duration-300">
              <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold gap-1.5">
                <i data-lucide="maximize-2" class="w-4 h-4"></i> คลิกเพื่อดูภาพขยาย
              </div>
            </a>
          </div>
        </div>

        <!-- หมายเหตุจากนิติบุคคล (ถ้ามี) -->
        <div id="modalAdminRemarkSection" class="hidden bg-emerald-50 rounded-2xl p-4 border border-emerald-200">
          <div class="text-xs font-bold text-emerald-800 flex items-center gap-1.5 mb-1">
            <i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i> ข้อความจากนิติบุคคล
          </div>
          <p class="text-xs text-emerald-900 leading-relaxed font-medium" id="modalAdminRemark">-</p>
        </div>

        <!-- เหตุผลการยกเลิก (ถ้ามี) -->
        <div id="modalCancelReasonSection" class="hidden bg-rose-50 rounded-2xl p-4 border border-rose-200">
          <div class="text-xs font-bold text-rose-800 flex items-center gap-1.5 mb-1">
            <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600"></i> ข้อมูลการยกเลิก / ปฏิเสธ
          </div>
          <p class="text-xs text-rose-900 leading-relaxed font-medium" id="modalCancelReason">-</p>
        </div>

      </div>

      <!-- Modal Footer -->
      <div class="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-end">
        <button type="button" onclick="closeDetailModal()" class="px-5 py-2.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold text-xs transition cursor-pointer">
          ปิดหน้าต่าง
        </button>
      </div>

    </div>
  </div>

  <script>
    // Preview image before upload
    function previewVehicleImage(input) {
      const container = document.getElementById('imagePreviewContainer');
      const img = document.getElementById('imagePreview');
      const placeholder = document.getElementById('uploadPlaceholder');
      if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
          img.src = e.target.result;
          container.classList.remove('hidden');
          placeholder.classList.add('hidden');
        }
        reader.readAsDataURL(input.files[0]);
      } else {
        container.classList.add('hidden');
        placeholder.classList.remove('hidden');
      }
    }

    // Filter tabs
    function filterList(status) {
      document.querySelectorAll('#filterTabs .tab-btn').forEach(btn => {
        if (btn.getAttribute('data-tab') === status) {
          btn.classList.add('bg-white', 'text-slate-800', 'shadow-sm');
          btn.classList.remove('text-slate-500');
        } else {
          btn.classList.remove('bg-white', 'text-slate-800', 'shadow-sm');
          btn.classList.add('text-slate-500');
        }
      });

      const cards = document.querySelectorAll('.request-card');
      cards.forEach(card => {
        const cStatus = card.getAttribute('data-status');
        if (status === 'all') {
          card.style.display = '';
        } else if (status === 'Cancelled') {
          if (cStatus === 'Cancelled' || cStatus === 'Rejected') {
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

    // Open Detail Modal
    function openDetailModal(data) {
      document.getElementById('modalTitle').textContent = 'รายละเอียดคำขอเพิ่มรถ (ห้อง ' + (data.roomNumber || '') + ')';
      document.getElementById('modalSubtitle').textContent = 'รหัสคำขอ #REQ-CAR-' + String(data.id).padStart(4, '0');
      document.getElementById('modalCurrentPlate').textContent = data.currentPlate || '-';
      document.getElementById('modalAdditionalPlate').textContent = data.additionalPlate || '-';
      document.getElementById('modalProvince').textContent = 'จังหวัด: ' + (data.province || '-');

      const typeMap = { 'Car': '🚗 รถยนต์ (Car)', 'Motorcycle': '🛵 รถมอเตอร์ไซค์ (Motorcycle)', 'EV': '⚡ รถยนต์ไฟฟ้า (EV)', 'Other': '🚐 อื่นๆ' };
      document.getElementById('modalVehicleType').textContent = typeMap[data.vehicleType] || data.vehicleType || '-';
      document.getElementById('modalBrandModel').textContent = data.brandModel || '-';
      document.getElementById('modalReason').textContent = data.reason || 'ไม่ได้ระบุ';
      document.getElementById('modalCreatedAt').textContent = 'ยื่นเมื่อ: ' + (data.created_at || '-');

      // Status text & banner styling
      const statusBanner = document.getElementById('modalStatusBanner');
      const statusText = document.getElementById('modalStatusText');
      if (data.status === 'Pending') {
        statusBanner.className = 'p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 flex items-center justify-between';
        statusText.innerHTML = '<span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span> สถานะ: รอการตรวจสอบจากนิติบุคคล</span>';
      } else if (data.status === 'Approved') {
        statusBanner.className = 'p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 flex items-center justify-between';
        statusText.innerHTML = '<span class="flex items-center gap-1.5"><i data-lucide="check-circle" class="w-4 h-4 text-emerald-600"></i> สถานะ: อนุมัติสิทธิ์เข้าจอดเรียบร้อยแล้ว</span>';
      } else {
        statusBanner.className = 'p-4 rounded-2xl bg-slate-100 border border-slate-200 text-slate-800 flex items-center justify-between';
        statusText.innerHTML = '<span class="flex items-center gap-1.5"><i data-lucide="x-circle" class="w-4 h-4 text-slate-500"></i> สถานะ: ยกเลิกแล้ว / ไม่ได้รับอนุมัติ</span>';
      }

      // Image
      const imgSection = document.getElementById('modalImageSection');
      if (data.vehicleImage) {
        document.getElementById('modalVehicleImage').src = data.vehicleImage;
        document.getElementById('modalImageLink').href = data.vehicleImage;
        imgSection.classList.remove('hidden');
      } else {
        imgSection.classList.add('hidden');
      }

      // Admin Remark
      const remarkSection = document.getElementById('modalAdminRemarkSection');
      if (data.adminRemark) {
        document.getElementById('modalAdminRemark').textContent = data.adminRemark;
        remarkSection.classList.remove('hidden');
      } else {
        remarkSection.classList.add('hidden');
      }

      // Cancel Reason
      const cancelSection = document.getElementById('modalCancelReasonSection');
      if (data.cancelReason) {
        document.getElementById('modalCancelReason').textContent = data.cancelReason;
        cancelSection.classList.remove('hidden');
      } else {
        cancelSection.classList.add('hidden');
      }

      document.getElementById('detailModal').classList.remove('hidden');
      lucide.createIcons();
    }

    function closeDetailModal() {
      document.getElementById('detailModal').classList.add('hidden');
    }

    // Confirmation for cancel request
    function confirmCancelRequest(e) {
      e.preventDefault();
      const form = e.target;
      Swal.fire({
        title: 'ยืนยันการยกเลิกคำขอ?',
        text: "คุณต้องการยกเลิกคำขอเพิ่มรถและสิทธิ์ที่จอดรถนี้ใช่หรือไม่",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'ใช่, ยกเลิกคำขอ',
        cancelButtonText: 'ไม่ยกเลิก',
        customClass: { popup: 'rounded-3xl font-sans' }
      }).then((result) => {
        if (result.isConfirmed) {
          form.submit();
        }
      });
      return false;
    }

    function validateParkingForm(e) {
      const cur = document.getElementById('currentPlate').value.trim();
      const add = document.getElementById('additionalPlate').value.trim();
      if (!cur || !add) {
        Swal.fire({
          icon: 'error',
          title: 'กรุณากรอกข้อมูลให้ครบ',
          text: 'กรุณากรอกทั้งป้ายทะเบียนคันปัจจุบัน และป้ายทะเบียนคันที่ขอเพิ่ม',
          confirmButtonColor: '#0891b2'
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
