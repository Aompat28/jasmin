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
    SELECT r.roomId, r.roomNumber, r.roomType, r.floor, l.leaseId, l.startDate, l.endDate, l.vehiclePlate, l.vehicleProvince, l.vehicleType 
    FROM Lease l 
    JOIN Room r ON l.roomId = r.roomId 
    WHERE l.tenantId = ? AND l.status = 'Active' 
    LIMIT 1
");
$stmtRoom->execute([$tenantId]);
$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);
$myRoomNumber = $myRoom ? ($myRoom['roomNumber'] ?? '') : '';
if (empty($myRoomNumber)) {
    $stmtHdrR = $conn->prepare("SELECT r.roomNumber, r.roomId, l.vehiclePlate, l.vehicleProvince, l.vehicleType FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");
    $stmtHdrR->execute([$tenantId]);
    $hdrRoom = $stmtHdrR->fetch(PDO::FETCH_ASSOC);
    if ($hdrRoom) {
        $myRoomNumber = $hdrRoom['roomNumber'] ?? '';
        if (!$myRoom) $myRoom = $hdrRoom;
    }
}
$roomId = $myRoom ? (int)$myRoom['roomId'] : null;

// --- API ตรวจสอบสถานะคำขอแบบ Real-time ---
if (isset($_GET['check_realtime'])) {
    header('Content-Type: application/json; charset=utf-8');
    $stmtCheckRealtime = $conn->prepare("
        SELECT id, status, adminRemark, approvedAt, cancelledAt, updated_at 
        FROM parking_requests 
        WHERE tenantId = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtCheckRealtime->execute([$tenantId]);
    $latestReq = $stmtCheckRealtime->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'status' => $latestReq ? $latestReq['status'] : 'None',
        'id' => $latestReq ? (int)$latestReq['id'] : null,
        'adminRemark' => $latestReq ? ($latestReq['adminRemark'] ?? '') : '',
        'approvedAt' => ($latestReq && !empty($latestReq['approvedAt'])) ? date('d/m/Y H:i', strtotime($latestReq['approvedAt'])) : '',
        'cancelledAt' => ($latestReq && !empty($latestReq['cancelledAt'])) ? date('d/m/Y H:i', strtotime($latestReq['cancelledAt'])) : ''
    ]);
    exit();
}

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

// สรุปสถิติ & แยกรายการคำขอเป็น "คำขอที่รออนุมัติ (Pending)" กับ "ประวัติคำขอ (History)"
$pendingCount = 0;
$approvedCount = 0;
$cancelledCount = 0;
$latestCurrentPlate = '';
$latestCurrentProvince = '';
$pendingRequest = null;
$historyRequests = [];

foreach ($parkingRequests as $pr) {
    if ($pr['status'] === 'Pending') {
        $pendingCount++;
        if (!$pendingRequest) {
            $pendingRequest = $pr;
        }
    } elseif ($pr['status'] === 'Approved') {
        $approvedCount++;
        $historyRequests[] = $pr;
    } elseif (in_array($pr['status'], ['Cancelled', 'Rejected'])) {
        $cancelledCount++;
        $historyRequests[] = $pr;
    }

    if (empty($latestCurrentPlate) && !empty($pr['currentPlate'])) {
        $latestCurrentPlate = $pr['currentPlate'];
        $latestCurrentProvince = $pr['province'] ?? '';
    }
}

// หากยังไม่มีทะเบียนในรายการคำขอ ให้ดึงทะเบียนรถคันแรกจากสัญญาเช่า (Lease) หรือข้อมูลผู้เช่า (User)
if (empty($latestCurrentPlate)) {
    if (!empty($myRoom['vehiclePlate'])) {
        $latestCurrentPlate = $myRoom['vehiclePlate'];
        $latestCurrentProvince = $myRoom['vehicleProvince'] ?? '';
    } else {
        try {
            $stmtUP = $conn->prepare("SELECT vehiclePlate, vehicleProvince, vehicleType FROM `User` WHERE userId = ? LIMIT 1");
            $stmtUP->execute([$tenantId]);
            $uData = $stmtUP->fetch(PDO::FETCH_ASSOC);
            if (!empty($uData['vehiclePlate'])) {
                $latestCurrentPlate = $uData['vehiclePlate'];
                $latestCurrentProvince = $uData['vehicleProvince'] ?? '';
            }
        } catch(PDOException $e) {}
    }
}

// แท็บที่เปิดอยู่ปัจจุบัน (คำขอ vs ประวัติคำขอ)
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'history' ? 'history' : 'request';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
    .hide-scrollbar::-webkit-scrollbar { display: none; }
    .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.98) translateY(6px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800 pb-16">

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
          <?php include_once 'includes/theme_toggle.php'; ?>
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
            <?php 
              if (!empty($latestCurrentPlate)) {
                echo htmlspecialchars($latestCurrentPlate);
                if (!empty($latestCurrentProvince)) {
                  echo ' <span class="text-xs font-normal text-slate-500">(' . htmlspecialchars($latestCurrentProvince) . ')</span>';
                }
              } else {
                echo '<span class="text-slate-400 font-normal">ยังไม่ได้ลงทะเบียน</span>';
              }
            ?>
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

      <!-- 4. จำนวนคำขอประวัติทั้งหมด -->
      <div class="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/80 shadow-sm flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-2xl bg-slate-50 text-slate-600 border border-slate-200 flex items-center justify-center shrink-0">
          <i data-lucide="layers" class="w-6 h-6"></i>
        </div>
        <div>
          <div class="text-[11px] font-medium text-slate-500">ประวัติคำขอทั้งหมด</div>
          <div class="text-lg sm:text-xl font-bold text-slate-800"><?php echo count($historyRequests); ?> <span class="text-xs font-normal text-slate-500">รายการ</span></div>
        </div>
      </div>
    </div>

    <!-- 🌟 แท็บสลับหน้า: คำขอเพิ่มรถ vs ประวัติคำขอ 🌟 -->
    <div class="mb-6 flex gap-2 border-b border-cyan-100/80 pb-px overflow-x-auto hide-scrollbar">
      <!-- แท็บ 1: คำขอ -->
      <button type="button" onclick="switchParkingTab('request')" id="tab-parking-request"
        class="<?php echo ($activeTab === 'request') ? 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-cyan-600 text-cyan-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
        <i data-lucide="plus-circle" class="w-4 h-4"></i>
        <span>คำขอเพิ่มรถ</span>
        <?php if ($pendingRequest): ?>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold bg-amber-100 text-amber-800 rounded-full border border-amber-300">
            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
            รออนุมัติ
          </span>
        <?php endif; ?>
      </button>

      <!-- แท็บ 2: ประวัติคำขอ -->
      <button type="button" onclick="switchParkingTab('history')" id="tab-parking-history"
        class="<?php echo ($activeTab === 'history') ? 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-cyan-600 text-cyan-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer' : 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer'; ?>">
        <i data-lucide="history" class="w-4 h-4"></i>
        <span>ประวัติคำขอ</span>
        <span class="inline-flex items-center justify-center px-2 py-0.5 text-[10px] font-bold bg-slate-100 text-slate-600 rounded-full">
          <?php echo count($historyRequests); ?>
        </span>
      </button>
    </div>

    <!-- ==================== TAB 1: คำขอขอเพิ่มรถ ==================== -->
    <div id="content-parking-request" class="<?php echo ($activeTab === 'request') ? '' : 'hidden'; ?>">
      
      <?php if ($pendingRequest): ?>
        <!-- 🌟 การ์ดแสดงคำขอที่รอดำเนินการ (แสดงแค่พอคร่าวๆ ให้เห็น พอกดเข้าไปถึงเป็นรายละเอียดเต็มจอ) 🌟 -->
        <div class="max-w-3xl mx-auto mb-8">
          
          <div onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($pendingRequest), ENT_QUOTES, 'UTF-8'); ?>)"
            class="cursor-pointer transition-all duration-200 hover:shadow-xl hover:border-amber-400 select-none bg-gradient-to-br from-amber-50/70 via-white to-orange-50/40 text-slate-800 p-6 sm:p-7 rounded-3xl border-2 border-amber-200 shadow-md mb-6 space-y-4 group">
            
            <!-- Header แถบสถานะแบบย่อ -->
            <div class="flex items-center justify-between border-b border-amber-100/90 pb-3">
              <div class="flex items-center gap-2.5">
                <span class="relative flex h-3 w-3">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500"></span>
                </span>
                <span class="font-bold text-sm sm:text-base text-slate-800">
                  คำขอเพิ่มรถและสิทธิ์ที่จอดรถ (ห้อง <?php echo htmlspecialchars($myRoomNumber); ?>)
                </span>
              </div>
              <span class="px-3 py-1 bg-amber-100 text-amber-900 border border-amber-300 rounded-full font-bold text-xs shadow-xs flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> รอแอดมินอนุมัติ
              </span>
            </div>

            <!-- ข้อมูลสรุปพอคร่าวๆ (ป้ายทะเบียนเดิม vs ป้ายทะเบียนที่ขอเพิ่ม) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-white/90 p-4 rounded-2xl border border-amber-100 shadow-xs">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">
                  <i data-lucide="car" class="w-5 h-5"></i>
                </div>
                <div>
                  <div class="text-[11px] font-bold text-slate-400">ทะเบียนรถคันหลัก</div>
                  <div class="text-sm font-extrabold text-slate-800"><?php echo htmlspecialchars($pendingRequest['currentPlate']); ?></div>
                </div>
              </div>

              <div class="flex items-center gap-3 border-t sm:border-t-0 sm:border-l border-amber-100 pt-3 sm:pt-0 sm:pl-3">
                <div class="w-10 h-10 rounded-xl bg-cyan-100 text-cyan-700 flex items-center justify-center shrink-0">
                  <i data-lucide="plus-circle" class="w-5 h-5"></i>
                </div>
                <div>
                  <div class="text-[11px] font-bold text-cyan-700">ทะเบียนรถคันที่ขอเพิ่ม</div>
                  <div class="text-sm font-extrabold text-cyan-900">
                    <?php echo htmlspecialchars($pendingRequest['additionalPlate']); ?> 
                    <span class="text-xs font-normal text-slate-500">(<?php echo htmlspecialchars($pendingRequest['province'] ?: 'ไม่ระบุจังหวัด'); ?>)</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- แถบระบุเวลา & รถ -->
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600 px-1">
              <div class="flex items-center gap-1.5">
                <i data-lucide="info" class="w-3.5 h-3.5 text-amber-600"></i>
                <span>ประเภท: <strong><?php echo htmlspecialchars($pendingRequest['vehicleType']); ?></strong></span>
                <?php if (!empty($pendingRequest['brandModel'])): ?>
                  <span>• รุ่น: <strong><?php echo htmlspecialchars($pendingRequest['brandModel']); ?></strong></span>
                <?php endif; ?>
              </div>
              <div class="text-slate-400 flex items-center gap-1">
                <i data-lucide="calendar" class="w-3.5 h-3.5"></i> ยื่นเมื่อ: <?php echo date("d/m/Y H:i", strtotime($pendingRequest['created_at'])); ?> น.
              </div>
            </div>

            <!-- 🌟 Stepper ย่อ 3 ขั้นตอน 🌟 -->
            <div class="pt-2">
              <div class="grid grid-cols-3 gap-2 text-center text-[11px]">
                <div class="p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 font-bold flex items-center justify-center gap-1">
                  <span>✓</span> 1. ยื่นคำขอแล้ว
                </div>
                <div class="p-2.5 rounded-xl bg-amber-100 border border-amber-300 text-amber-900 font-bold flex items-center justify-center gap-1 shadow-xs ring-1 ring-amber-300">
                  <span class="animate-pulse">⏳</span> 2. ตรวจสอบข้อมูลรถ
                </div>
                <div class="p-2.5 rounded-xl bg-stone-100 border border-stone-200 text-stone-400 font-medium">
                  3. อนุมัติสิทธิ์เข้าจอด
                </div>
              </div>
            </div>

            <!-- Action footer ของการ์ด -->
            <div class="flex items-center justify-between pt-2 border-t border-amber-100/80">
              <span class="text-xs font-bold text-amber-700 flex items-center gap-1 group-hover:translate-x-1 transition-transform">
                <span>คลิกเพื่อดูรายละเอียดเพิ่มเติมและรูปถ่ายแบบเต็มจอ</span>
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
              </span>

              <!-- ปุ่มยกเลิกคำขอ -->
              <form action="tenant_parking.php" method="POST" onsubmit="return confirmCancelRequest(event)" onclick="event.stopPropagation();">
                <input type="hidden" name="request_id" value="<?php echo $pendingRequest['id']; ?>">
                <button type="submit" name="cancel_request"
                  class="px-3.5 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs transition border border-rose-200 flex items-center gap-1.5 cursor-pointer" title="ยกเลิกคำขอนี้">
                  <i data-lucide="x" class="w-3.5 h-3.5"></i> ขอยกเลิกคำขอ
                </button>
              </form>
            </div>

          </div>

          <!-- Note Information -->
          <div class="p-4 bg-cyan-50/60 rounded-2xl border border-cyan-200/80 text-xs text-cyan-900 flex items-start gap-2.5">
            <i data-lucide="info" class="w-4 h-4 text-cyan-600 shrink-0 mt-0.5"></i>
            <div>
              <strong>คำขอของคุณอยู่ระหว่างการพิจารณาจากนิติบุคคล</strong> 
              เมื่อแอดมินตรวจสอบและทำการอนุมัติเรียบร้อยแล้ว รายการนี้จะย้ายไปแสดงที่แท็บ <strong>"ประวัติคำขอ"</strong> โดยอัตโนมัติ และคุณจะสามารถส่งคำขอเพิ่มรถคันถัดไปได้ทันที
            </div>
          </div>

        </div>

      <?php else: ?>
        <!-- เมื่อไม่มีคำขอค้างอยู่ หรือคำขอเดิมอนุมัติไปแล้ว จะแสดงแบบฟอร์มขอเพิ่มรถใหม่ (ไม่อนุมัติซ้ำหน้าคำขอ) -->
        <div class="max-w-2xl mx-auto">
          <div class="bg-white rounded-3xl border border-slate-200/80 shadow-md overflow-hidden">
            <div class="p-6 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-cyan-50/30">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-cyan-100 text-cyan-700 flex items-center justify-center shrink-0">
                  <i data-lucide="plus-circle" class="w-6 h-6"></i>
                </div>
                <div>
                  <h2 class="font-display font-bold text-slate-900 text-lg">แบบฟอร์มขอเพิ่มรถคันที่ 2+</h2>
                  <p class="text-xs text-slate-500">กรอกทะเบียนปัจจุบันและข้อมูลรถที่ขอเพิ่มสิทธิ์ที่จอด</p>
                </div>
              </div>
            </div>

            <form action="tenant_parking.php" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-5" onsubmit="return validateParkingForm(event)">
              
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
                    class="w-full pl-10 pr-3.5 py-3 text-sm bg-slate-50/60 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition font-medium text-slate-800">
                </div>
                <p class="text-[11px] text-slate-400 mt-1">ทะเบียนรถคันหลักที่คุณใช้สิทธิ์จอดเดิมในปัจจุบัน</p>
              </div>

              <!-- เส้นคั่นแบ่ง -->
              <div class="border-t border-slate-100 pt-2">
                <div class="inline-flex items-center gap-1.5 text-xs font-bold text-cyan-700 bg-cyan-50 px-3 py-1 rounded-xl mb-1">
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
                    class="w-full pl-10 pr-3.5 py-3 text-sm bg-slate-50/60 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition font-bold text-slate-900 tracking-wide">
                </div>
              </div>

              <!-- จังหวัด และ ประเภทยานพาหนะ -->
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5">
                    จังหวัด <span class="text-rose-500">*</span>
                  </label>
                  <input type="text" name="province" id="province" required
                    placeholder="เช่น กรุงเทพฯ, เชียงใหม่"
                    class="w-full px-3.5 py-3 text-sm bg-slate-50/60 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800">
                </div>

                <div>
                  <label class="block text-xs font-bold text-slate-700 mb-1.5">
                    ประเภทยานพาหนะ <span class="text-rose-500">*</span>
                  </label>
                  <select name="vehicleType" id="vehicleType" required
                    class="w-full px-3.5 py-3 text-sm bg-slate-50/60 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800 cursor-pointer">
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
                    class="w-full pl-10 pr-3.5 py-3 text-sm bg-slate-50/60 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800">
                </div>
              </div>

              <!-- เหตุผลความจำเป็นในการขอเพิ่มที่จอดรถ -->
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  เหตุผลความจำเป็นในการขอเพิ่ม
                </label>
                <textarea name="reason" id="reason" rows="3"
                  placeholder="เช่น มีรถมอเตอร์ไซค์สำหรับเดินทางทำงานประจำวัน, มีผู้ร่วมอาศัยนำรถมาใช้งาน"
                  class="w-full px-3.5 py-2.5 text-sm bg-slate-50/60 rounded-2xl border border-slate-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-500 transition text-slate-800 resize-none"></textarea>
              </div>

              <!-- แนบรูปถ่ายรถ หรือ สำเนาคู่มือจดทะเบียน -->
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center justify-between">
                  <span>รูปถ่ายรถยนต์ หรือ เอกสารคู่มือจดทะเบียน</span>
                  <span class="text-[10px] text-slate-400 font-normal">PNG, JPG (ไม่เกิน 5MB)</span>
                </label>
                <div class="relative border-2 border-dashed border-slate-200 hover:border-cyan-400 rounded-2xl p-5 text-center transition bg-slate-50/50 hover:bg-cyan-50/20 group cursor-pointer" onclick="document.getElementById('vehicle_image').click()">
                  <input type="file" name="vehicle_image" id="vehicle_image" accept="image/*" class="hidden" onchange="previewVehicleImage(this)">
                  <div id="imagePreviewContainer" class="hidden mb-2">
                    <img id="imagePreview" src="#" alt="Preview" class="max-h-36 mx-auto rounded-xl shadow-sm border border-slate-200 object-cover">
                  </div>
                  <div id="uploadPlaceholder">
                    <i data-lucide="upload-cloud" class="w-9 h-9 mx-auto text-slate-400 group-hover:text-cyan-600 transition mb-1.5"></i>
                    <p class="text-xs text-slate-600 font-medium group-hover:text-cyan-700 transition">คลิกเลือกรูปภาพ หรือ ลากไฟล์มาวางที่นี่</p>
                    <p class="text-[10px] text-slate-400 mt-0.5">ภาพถ่ายรถที่เห็นป้ายทะเบียนชัดเจน</p>
                  </div>
                </div>
              </div>

              <!-- Submit Button -->
              <button type="submit" name="submit_parking_request"
                class="w-full py-3.5 px-4 rounded-2xl bg-gradient-to-r from-cyan-600 via-teal-600 to-emerald-600 hover:from-cyan-700 hover:via-teal-700 hover:to-emerald-700 text-white font-bold text-sm shadow-md shadow-cyan-600/20 hover:shadow-lg transition-all flex items-center justify-center gap-2 cursor-pointer mt-4">
                <i data-lucide="send" class="w-4 h-4"></i> ส่งคำขอเพิ่มรถให้นิติบุคคลตรวจสอบ
              </button>
            </form>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- ==================== TAB 2: ประวัติคำขอ ==================== -->
    <div id="content-parking-history" class="<?php echo ($activeTab === 'history') ? '' : 'hidden'; ?>">
      <div class="bg-white rounded-3xl border border-slate-200/80 shadow-md overflow-hidden">
        
        <!-- Header & Status Filter Pills -->
        <div class="p-5 sm:p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-gradient-to-r from-slate-50 to-white">
          <div>
            <h2 class="font-display font-bold text-slate-900 text-lg">ประวัติคำขอและสิทธิ์ที่จอดรถ</h2>
            <p class="text-xs text-slate-500">ตรวจสอบรายละเอียดผลการพิจารณาและข้อมูลรถที่ขอเพิ่ม</p>
          </div>

          <!-- Status Filter Tabs -->
          <div class="inline-flex p-1 bg-slate-100/90 rounded-2xl text-xs font-semibold" id="filterTabs">
            <button type="button" onclick="filterList('all')" class="tab-btn px-3 py-1.5 rounded-xl bg-white text-slate-800 shadow-sm transition cursor-pointer" data-tab="all">
              ทั้งหมด (<?php echo count($historyRequests); ?>)
            </button>
            <button type="button" onclick="filterList('Approved')" class="tab-btn px-3 py-1.5 rounded-xl text-slate-500 hover:text-slate-800 transition cursor-pointer" data-tab="Approved">
              อนุมัติแล้ว (<?php echo $approvedCount; ?>)
            </button>
            <button type="button" onclick="filterList('Cancelled')" class="tab-btn px-3 py-1.5 rounded-xl text-slate-500 hover:text-slate-800 transition cursor-pointer" data-tab="Cancelled">
              ยกเลิก / ไม่ผ่าน (<?php echo $cancelledCount; ?>)
            </button>
          </div>
        </div>

        <!-- รายการประวัติคำขอแบบการ์ดคร่าวๆ (คลิกดูรายละเอียดเต็มจอ) -->
        <div class="divide-y divide-slate-100" id="requestsListContainer">
          <?php if (empty($historyRequests)): ?>
            <div class="p-12 text-center">
              <div class="w-16 h-16 rounded-full bg-cyan-50 text-cyan-500 flex items-center justify-center mx-auto mb-3">
                <i data-lucide="folder-clock" class="w-8 h-8"></i>
              </div>
              <h3 class="font-display font-bold text-slate-800 text-base mb-1">ยังไม่มีประวัติคำขอที่เสร็จสิ้น</h3>
              <p class="text-xs text-slate-400 max-w-sm mx-auto mb-4">เมื่อนิติบุคคลตรวจสอบและอนุมัติคำขอเพิ่มรถของคุณแล้ว รายการจะแสดงในหน้านี้</p>
              <button type="button" onclick="switchParkingTab('request')" class="inline-flex items-center gap-1.5 px-4 py-2 bg-cyan-50 hover:bg-cyan-100 text-cyan-700 font-bold rounded-xl text-xs transition cursor-pointer">
                <i data-lucide="plus-circle" class="w-4 h-4"></i> ไปที่หน้าคำขอเพิ่มรถ
              </button>
            </div>
          <?php else: ?>
            <?php foreach ($historyRequests as $pr): 
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
              <!-- การ์ดสรุปพอคร่าวๆ (คลิกได้ทั้งการ์ดเพื่อเปิดดูรายละเอียดแบบเต็มจอ) -->
              <div onclick="openDetailModal(<?php echo htmlspecialchars(json_encode($pr), ENT_QUOTES, 'UTF-8'); ?>)"
                   class="p-5 hover:bg-cyan-50/20 transition cursor-pointer select-none group request-card <?php echo $statusRowClass; ?>" 
                   data-status="<?php echo htmlspecialchars($status); ?>">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                  
                  <!-- Vehicle Info & Plates -->
                  <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-cyan-50 to-teal-50 border border-cyan-100 text-cyan-600 flex items-center justify-center shrink-0 shadow-sm mt-0.5 group-hover:scale-105 transition-transform">
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

                        <?php if($status === 'Approved' && !empty($pr['approvedAt'])): ?>
                          <span class="flex items-center gap-1 text-emerald-600 font-semibold">
                            <i data-lucide="check" class="w-3 h-3"></i> อนุมัติเมื่อ: <?php echo date('d/m/Y H:i', strtotime($pr['approvedAt'])); ?>
                          </span>
                        <?php endif; ?>
                      </div>

                      <!-- Admin Remark if available -->
                      <?php if (!empty($pr['adminRemark'])): ?>
                        <div class="mt-2 text-xs bg-emerald-50 text-emerald-800 px-3 py-1 rounded-xl border border-emerald-200/60 inline-flex items-center gap-1.5">
                          <i data-lucide="message-square" class="w-3.5 h-3.5 text-emerald-600 shrink-0"></i>
                          <span><strong>หมายเหตุแอดมิน:</strong> <?php echo htmlspecialchars($pr['adminRemark']); ?></span>
                        </div>
                      <?php endif; ?>

                      <!-- Cancel Reason if available -->
                      <?php if (!empty($pr['cancelReason'])): ?>
                        <div class="mt-2 text-xs bg-rose-50 text-rose-700 px-3 py-1 rounded-xl border border-rose-100 inline-flex items-center gap-1.5">
                          <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-rose-500 shrink-0"></i>
                          <span><strong>เหตุผลการยกเลิก:</strong> <?php echo htmlspecialchars($pr['cancelReason']); ?></span>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- Status & Action Button -->
                  <div class="flex sm:flex-col items-end justify-between sm:justify-center gap-2 shrink-0">
                    <div><?php echo $statusBadge; ?></div>

                    <div class="flex items-center gap-1.5 text-xs font-bold text-cyan-700 group-hover:text-cyan-800 group-hover:translate-x-1 transition-transform">
                      <span class="hidden sm:inline">ดูรายละเอียดเต็มจอ</span>
                      <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </div>
                  </div>

                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>

  </main>

  <!-- ==================== 🌟 FULL-SCREEN DETAIL MODAL (ทำให้เป็นจอใหญ่) 🌟 ==================== -->
  <div id="detailModal" class="hidden fixed inset-0 z-50 bg-white flex flex-col w-full h-full min-h-screen overflow-hidden">
    
    <!-- Modal Header (Full Width Top Bar) -->
    <div class="bg-gradient-to-r from-cyan-600 via-teal-600 to-emerald-600 text-white px-5 py-3.5 sm:px-8 sm:py-4.5 flex items-center justify-between shrink-0 shadow-md">
      <div class="flex items-center gap-3">
        <button type="button" onclick="closeDetailModal()" class="w-9 h-9 rounded-2xl bg-black/20 hover:bg-black/35 text-white flex items-center justify-center transition cursor-pointer shadow-xs shrink-0" title="ย้อนกลับ">
          <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div>
          <div class="flex flex-wrap items-center gap-2 mb-1">
            <span class="px-2.5 py-0.5 rounded-full bg-white/20 text-white text-[11px] font-bold tracking-wide backdrop-blur-xs flex items-center gap-1 shadow-xs">
              <i data-lucide="car" class="w-3 h-3"></i> สิทธิ์ที่จอดรถ
            </span>
            <span id="modalStatusBadge" class="px-2.5 py-0.5 rounded-full bg-white text-cyan-900 text-[11px] font-bold shadow-xs">
              ⏳ รอแอดมินอนุมัติ
            </span>
            <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/25 border border-white/30 text-white text-[11px] font-semibold flex items-center gap-1.5 backdrop-blur-xs">
              <span class="w-2 h-2 rounded-full bg-emerald-300 animate-pulse"></span> อัปเดตสดเรียลไทม์
            </span>
          </div>
          <h2 id="modalTitle" class="text-lg sm:text-2xl font-extrabold font-display tracking-tight text-white leading-tight">
            รายละเอียดคำขอเพิ่มรถและสิทธิ์ที่จอดรถ
          </h2>
          <p id="modalSubtitle" class="text-xs sm:text-sm text-cyan-100 mt-0.5 flex items-center gap-2">
            <span>ห้อง <strong id="modalRoomNum" class="text-white"><?php echo htmlspecialchars($myRoomNumber); ?></strong></span>
            <span>•</span>
            <span>ผู้เช่า: <strong id="modalTenantName" class="text-white"><?php echo htmlspecialchars($tenantName); ?></strong></span>
          </p>
        </div>
      </div>
      <button type="button" onclick="closeDetailModal()" class="hidden sm:inline-flex items-center gap-1.5 px-4 py-2 bg-white/20 hover:bg-white/30 rounded-xl text-white font-bold text-xs transition backdrop-blur-xs cursor-pointer shadow-xs">
        <i data-lucide="x" class="w-4 h-4"></i> ปิดหน้าต่าง
      </button>
    </div>

    <!-- Modal Body (Full Width Scrollable, Centered Content) -->
    <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-gradient-to-b from-cyan-50/25 via-slate-50/50 to-white">
      <div class="max-w-4xl lg:max-w-5xl mx-auto w-full space-y-6">

        <!-- 🌟 Progress Stepper (ขั้นตอนการดำเนินงานแบบเต็มตา) 🌟 -->
        <div class="p-5 sm:p-6 bg-gradient-to-br from-cyan-50/90 via-teal-50/40 to-emerald-50/40 rounded-3xl border border-cyan-200/90 shadow-sm space-y-4">
          <div class="flex flex-wrap items-center justify-between gap-2 text-xs sm:text-sm font-bold text-cyan-950">
            <span class="flex items-center gap-2">
              <i data-lucide="milestone" class="w-4 h-4 sm:w-5 sm:h-5 text-cyan-600"></i>
              <span>สถานะขั้นตอนการดำเนินงาน:</span>
            </span>
            <span id="modalStepIndicator" class="text-cyan-900 bg-cyan-100/90 px-3 py-1 rounded-full text-xs font-extrabold border border-cyan-200 shadow-xs">
              ขั้นตอนที่ 2 จาก 3 (กำลังดำเนินการ)
            </span>
          </div>

          <!-- 3 Steps Bar -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 text-center">
            <div id="step1Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 transition-all shadow-xs">
              <span id="step1Icon" class="w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs">✓</span>
              <span class="font-bold text-xs sm:text-sm">1. ยื่นคำขอแล้ว</span>
              <span id="step1Time" class="text-[11px] text-emerald-700/80 font-normal">บันทึกข้อมูลรถเข้าระบบ</span>
            </div>
            <div id="step2Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-amber-100 border-amber-400 text-amber-950 shadow-xs ring-2 ring-amber-300/60 transition-all">
              <span id="step2Icon" class="w-9 h-9 rounded-full bg-amber-600 text-white flex items-center justify-center text-sm font-bold animate-pulse shadow-xs">⏳</span>
              <span class="font-bold text-xs sm:text-sm">2. ตรวจสอบข้อมูลรถ</span>
              <span id="step2Time" class="text-[11px] text-amber-800 font-bold">นิติบุคคลกำลังตรวจสอบ</span>
            </div>
            <div id="step3Box" class="p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-stone-50 border-stone-200 text-stone-400 transition-all">
              <span id="step3Icon" class="w-9 h-9 rounded-full bg-stone-200 text-stone-500 flex items-center justify-center text-sm font-bold">3</span>
              <span id="step3Title" class="font-bold text-xs sm:text-sm">3. ผลการพิจารณา</span>
              <span id="step3Time" class="text-[11px] text-stone-400 font-normal">รออนุมัติสิทธิ์เข้าจอด</span>
            </div>
          </div>

          <!-- Step Description Box -->
          <div id="modalStepDesc" class="p-4 bg-white/95 rounded-2xl border border-cyan-200/90 text-xs sm:text-sm text-cyan-950 leading-relaxed shadow-xs flex items-start gap-2.5">
            <i data-lucide="info" class="w-5 h-5 text-cyan-600 shrink-0 mt-0.5"></i>
            <div id="modalStepDescText">
              คำขอของคุณถูกบันทึกเรียบร้อยแล้ว อยู่ระหว่างเจ้าหน้าที่นิติบุคคลตรวจสอบความถูกต้องของข้อมูลรถและโควต้าที่จอด
            </div>
          </div>
        </div>

        <!-- 🌟 เปรียบเทียบ 2 คอลัมน์: ทะเบียนรถคันเดิม vs ทะเบียนรถที่ขอเพิ่ม 🌟 -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <!-- คันปัจจุบัน -->
          <div class="p-5 rounded-3xl bg-white border border-slate-200 shadow-sm">
            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-2">
              <div class="w-7 h-7 rounded-lg bg-slate-100 text-slate-600 flex items-center justify-center">
                <i data-lucide="car" class="w-4 h-4"></i>
              </div>
              <span>ป้ายทะเบียนรถคันหลัก (คันแรก)</span>
            </div>
            <div class="text-2xl font-extrabold text-slate-800 tracking-wide" id="modalCurrentPlate">-</div>
            <div class="text-xs text-slate-400 mt-1">สิทธิ์ที่จอดรถหลักของห้องพัก</div>
          </div>

          <!-- คันที่ขอเพิ่ม -->
          <div class="p-5 rounded-3xl bg-cyan-50/70 border-2 border-cyan-300 shadow-sm">
            <div class="text-xs font-bold text-cyan-800 uppercase tracking-wider mb-2 flex items-center gap-2">
              <div class="w-7 h-7 rounded-lg bg-cyan-200/80 text-cyan-800 flex items-center justify-center">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
              </div>
              <span>ป้ายทะเบียนรถคันที่ขอเพิ่ม (คันที่ 2+)</span>
            </div>
            <div class="text-2xl font-extrabold text-cyan-950 tracking-wide" id="modalAdditionalPlate">-</div>
            <div class="text-xs text-cyan-700 font-semibold mt-1" id="modalProvince">จังหวัด: -</div>
          </div>
        </div>

        <!-- 🌟 รายละเอียดยานพาหนะ (Grid 4 ช่อง) 🌟 -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="p-4 bg-slate-50/80 border-b border-slate-200/80 flex items-center gap-2">
            <i data-lucide="info" class="w-4 h-4 text-cyan-600"></i>
            <h4 class="text-xs sm:text-sm font-bold text-slate-800">ข้อมูลรายละเอียดตัวรถและวันเวลา</h4>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-slate-100 text-xs sm:text-sm">
            <div class="p-4 sm:p-5 space-y-4">
              <div class="flex justify-between items-center">
                <span class="text-slate-500 flex items-center gap-2"><i data-lucide="help-circle" class="w-4 h-4 text-slate-400"></i> ประเภทยานพาหนะ</span>
                <strong id="modalVehicleType" class="text-slate-800 font-bold bg-slate-100 px-3 py-1 rounded-xl">-</strong>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-slate-500 flex items-center gap-2"><i data-lucide="tag" class="w-4 h-4 text-slate-400"></i> ยี่ห้อ / รุ่น / สี</span>
                <strong id="modalBrandModel" class="text-slate-800 font-semibold text-right">-</strong>
              </div>
            </div>

            <div class="p-4 sm:p-5 space-y-4">
              <div class="flex justify-between items-center">
                <span class="text-slate-500 flex items-center gap-2"><i data-lucide="calendar" class="w-4 h-4 text-slate-400"></i> วันที่ยื่นคำขอ</span>
                <strong id="modalCreatedAt" class="font-mono text-slate-800">-</strong>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-slate-500 flex items-center gap-2"><i data-lucide="clock" class="w-4 h-4 text-slate-400"></i> ผลการดำเนินการ</span>
                <strong id="modalActionDate" class="font-mono text-slate-800">-</strong>
              </div>
            </div>
          </div>

          <!-- เหตุผลความจำเป็น -->
          <div class="p-4 sm:p-5 border-t border-slate-100 bg-slate-50/30">
            <span class="text-slate-500 block text-xs font-bold mb-1.5 flex items-center gap-1.5">
              <i data-lucide="message-square" class="w-3.5 h-3.5 text-slate-400"></i> เหตุผลความจำเป็นในการขอเพิ่มที่จอดรถ:
            </span>
            <p class="text-xs sm:text-sm text-slate-800 bg-white p-3.5 rounded-2xl border border-slate-200/80 leading-relaxed font-medium" id="modalReason">
              -
            </p>
          </div>
        </div>

        <!-- 🌟 รูปถ่ายรถยนต์ / เอกสารประกอบ (สามารถคลิกดูรูปขยายได้) 🌟 -->
        <div id="modalImageSection" class="hidden bg-white rounded-3xl border border-slate-200 shadow-sm p-5 space-y-3">
          <div class="flex items-center justify-between">
            <h4 class="text-xs sm:text-sm font-bold text-slate-800 flex items-center gap-2">
              <i data-lucide="image" class="w-4 h-4 text-cyan-600"></i> รูปถ่ายรถยนต์ / เอกสารคู่มือจดทะเบียน
            </h4>
            <span class="text-xs text-slate-400 font-medium">คลิกที่รูปเพื่อเปิดดูภาพขนาดเต็ม</span>
          </div>
          <div class="bg-slate-100/80 rounded-2xl p-3 text-center border border-slate-200">
            <div id="modalImageClickable" class="cursor-pointer group relative overflow-hidden rounded-xl inline-block max-w-full">
              <img id="modalVehicleImage" src="#" alt="Vehicle" class="max-h-80 mx-auto rounded-xl object-contain group-hover:scale-102 transition duration-300 shadow-sm">
              <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white text-xs font-bold gap-2">
                <i data-lucide="maximize-2" class="w-5 h-5"></i> คลิกเพื่อดูภาพขยาย
              </div>
            </div>
          </div>
        </div>

        <!-- 🌟 หมายเหตุจากนิติบุคคล (ถ้ามี) 🌟 -->
        <div id="modalAdminRemarkSection" class="hidden bg-emerald-50 rounded-3xl p-5 border border-emerald-200 shadow-sm space-y-2">
          <div class="text-xs sm:text-sm font-bold text-emerald-900 flex items-center gap-2">
            <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> ข้อความตอบรับจากนิติบุคคล
          </div>
          <p class="text-xs sm:text-sm text-emerald-950 leading-relaxed bg-white/80 p-3.5 rounded-2xl border border-emerald-200/80 font-medium" id="modalAdminRemark">
            -
          </p>
        </div>

        <!-- 🌟 เหตุผลการยกเลิก / ปฏิเสธ (ถ้ามี) 🌟 -->
        <div id="modalCancelReasonSection" class="hidden bg-rose-50 rounded-3xl p-5 border border-rose-200 shadow-sm space-y-2">
          <div class="text-xs sm:text-sm font-bold text-rose-900 flex items-center gap-2">
            <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600"></i> บันทึกเหตุผลการยกเลิก / ปฏิเสธ
          </div>
          <p class="text-xs sm:text-sm text-rose-950 leading-relaxed bg-white/80 p-3.5 rounded-2xl border border-rose-200/80 font-medium" id="modalCancelReason">
            -
          </p>
        </div>

      </div>
    </div>

    <!-- Modal Footer (Sticky Bottom Action Bar) -->
    <div class="p-4 sm:p-5 bg-white border-t border-slate-200 shrink-0 flex items-center justify-between shadow-lg">
      <div id="modalPendingCancelArea">
        <form id="modalCancelForm" action="tenant_parking.php" method="POST" onsubmit="return confirmCancelRequest(event)">
          <input type="hidden" name="request_id" id="modalCancelRequestId" value="">
          <button type="submit" name="cancel_request" class="px-4 py-2.5 rounded-2xl bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs sm:text-sm border border-rose-200 flex items-center gap-2 transition cursor-pointer">
            <i data-lucide="x" class="w-4 h-4"></i> ขอยกเลิกคำขอนี้
          </button>
        </form>
      </div>
      <button type="button" onclick="closeDetailModal()" class="px-6 py-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs sm:text-sm transition cursor-pointer ml-auto">
        ปิดหน้าต่าง
      </button>
    </div>

  </div>

  <!-- Include Document / Image Viewer Modal -->
  <?php include_once 'includes/doc_viewer_modal.php'; ?>

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

    // Tab Switching: คำขอ vs ประวัติคำขอ
    function switchParkingTab(tab) {
      const btnRequest = document.getElementById('tab-parking-request');
      const btnHistory = document.getElementById('tab-parking-history');
      const contentRequest = document.getElementById('content-parking-request');
      const contentHistory = document.getElementById('content-parking-history');

      if (tab === 'request') {
        btnRequest.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-cyan-600 text-cyan-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
        btnHistory.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
        contentRequest.classList.remove('hidden');
        contentHistory.classList.add('hidden');
      } else {
        btnHistory.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-cyan-600 text-cyan-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
        btnRequest.className = 'px-5 py-2.5 text-xs sm:text-sm font-bold border-b-2 border-transparent text-slate-500 hover:text-slate-700 flex items-center gap-2 whitespace-nowrap transition cursor-pointer';
        contentHistory.classList.remove('hidden');
        contentRequest.classList.add('hidden');
      }

      if (window.history && window.history.replaceState) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url.toString());
      }
      lucide.createIcons();
    }

    // Filter tabs in History
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

    // Open Full-Screen Detail Modal
    function openDetailModal(data) {
      if (!data) return;

      document.getElementById('modalTitle').textContent = 'รายละเอียดคำขอเพิ่มรถและสิทธิ์ที่จอด (ห้อง ' + (data.roomNumber || '<?php echo htmlspecialchars($myRoomNumber); ?>') + ')';
      document.getElementById('modalRoomNum').textContent = data.roomNumber || '<?php echo htmlspecialchars($myRoomNumber); ?>';
      document.getElementById('modalTenantName').textContent = '<?php echo htmlspecialchars($tenantName); ?>';

      document.getElementById('modalCurrentPlate').textContent = data.currentPlate || '-';
      document.getElementById('modalAdditionalPlate').textContent = data.additionalPlate || '-';
      document.getElementById('modalProvince').textContent = 'จังหวัด: ' + (data.province || '-');

      const typeMap = { 'Car': '🚗 รถยนต์ (Car)', 'Motorcycle': '🛵 รถมอเตอร์ไซค์ (Motorcycle)', 'EV': '⚡ รถยนต์ไฟฟ้า (EV)', 'Other': '🚐 อื่นๆ' };
      document.getElementById('modalVehicleType').textContent = typeMap[data.vehicleType] || data.vehicleType || '-';
      document.getElementById('modalBrandModel').textContent = data.brandModel || 'ไม่ได้ระบุ';
      document.getElementById('modalReason').textContent = data.reason || 'ไม่ได้ระบุเหตุผล';
      document.getElementById('modalCreatedAt').textContent = (data.created_at || '-') + ' น.';

      // Stepper elements
      const step1Box = document.getElementById('step1Box');
      const step2Box = document.getElementById('step2Box');
      const step3Box = document.getElementById('step3Box');
      const step1Icon = document.getElementById('step1Icon');
      const step2Icon = document.getElementById('step2Icon');
      const step3Icon = document.getElementById('step3Icon');
      const step3Title = document.getElementById('step3Title');
      const step2Time = document.getElementById('step2Time');
      const step3Time = document.getElementById('step3Time');
      const stepIndicator = document.getElementById('modalStepIndicator');
      const stepDescText = document.getElementById('modalStepDescText');
      const statusBadge = document.getElementById('modalStatusBadge');
      const actionDateEl = document.getElementById('modalActionDate');
      const cancelFormArea = document.getElementById('modalPendingCancelArea');
      const cancelInputId = document.getElementById('modalCancelRequestId');

      if (data.status === 'Pending') {
        statusBadge.className = 'px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-900 border border-amber-300 text-[11px] font-bold shadow-xs';
        statusBadge.innerHTML = '⏳ รอแอดมินอนุมัติ';

        stepIndicator.textContent = 'ขั้นตอนที่ 2 จาก 3 (กำลังดำเนินการ)';
        stepIndicator.className = 'text-amber-900 bg-amber-100 px-3 py-1 rounded-full text-xs font-extrabold border border-amber-300 shadow-xs';

        step1Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 shadow-xs';
        step1Icon.className = 'w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs';
        step1Icon.textContent = '✓';

        step2Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-amber-100 border-amber-400 text-amber-950 shadow-xs ring-2 ring-amber-300/60';
        step2Icon.className = 'w-9 h-9 rounded-full bg-amber-600 text-white flex items-center justify-center text-sm font-bold animate-pulse shadow-xs';
        step2Icon.textContent = '⏳';
        step2Time.textContent = 'นิติบุคคลกำลังตรวจสอบ';

        step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-stone-50 border-stone-200 text-stone-400';
        step3Icon.className = 'w-9 h-9 rounded-full bg-stone-200 text-stone-500 flex items-center justify-center text-sm font-bold';
        step3Icon.textContent = '3';
        step3Title.textContent = '3. อนุมัติสิทธิ์เข้าจอด';
        step3Time.textContent = 'รอผลการพิจารณา';

        stepDescText.textContent = 'คำขอของคุณถูกบันทึกเข้าระบบเรียบร้อยแล้ว อยู่ระหว่างเจ้าหน้าที่นิติบุคคลตรวจสอบความถูกต้องของข้อมูลรถและโควต้าที่จอดรถ';
        actionDateEl.textContent = 'อยู่ระหว่างรอการอนุมัติ';

        cancelFormArea.classList.remove('hidden');
        cancelInputId.value = data.id;

      } else if (data.status === 'Approved') {
        statusBadge.className = 'px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-900 border border-emerald-300 text-[11px] font-bold shadow-xs';
        statusBadge.innerHTML = '✓ อนุมัติสิทธิ์เข้าจอดแล้ว';

        stepIndicator.textContent = 'ขั้นตอนที่ 3 จาก 3 (อนุมัติเสร็จสิ้น)';
        stepIndicator.className = 'text-emerald-900 bg-emerald-100 px-3 py-1 rounded-full text-xs font-extrabold border border-emerald-300 shadow-xs';

        step1Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 shadow-xs';
        step1Icon.className = 'w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs';
        step1Icon.textContent = '✓';

        step2Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-50 border-emerald-300 text-emerald-800 shadow-xs';
        step2Icon.className = 'w-9 h-9 rounded-full bg-emerald-500 text-white flex items-center justify-center text-sm font-bold shadow-xs';
        step2Icon.textContent = '✓';
        step2Time.textContent = 'ตรวจสอบผ่านเรียบร้อย';

        step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-emerald-100 border-emerald-400 text-emerald-950 shadow-xs ring-2 ring-emerald-300/60';
        step3Icon.className = 'w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center text-sm font-bold shadow-xs';
        step3Icon.textContent = '✓';
        step3Title.textContent = '3. ได้รับอนุมัติสิทธิ์แล้ว';
        step3Time.textContent = data.approvedAt ? ('อนุมัติเมื่อ: ' + data.approvedAt) : 'อนุมัติเรียบร้อย';

        stepDescText.textContent = 'ยินดีด้วยครับ! นิติบุคคลได้ทำการอนุมัติสิทธิ์ที่จอดรถสำหรับป้ายทะเบียนนี้เรียบร้อยแล้ว คุณสามารถนำรถเข้าจอดในพื้นที่ของหอพักได้ตามระเบียบ';
        actionDateEl.textContent = data.approvedAt ? ('อนุมัติเมื่อ: ' + data.approvedAt + ' น.') : 'อนุมัติแล้ว';

        cancelFormArea.classList.add('hidden');

      } else {
        // Cancelled / Rejected
        statusBadge.className = 'px-2.5 py-0.5 rounded-full bg-rose-100 text-rose-900 border border-rose-300 text-[11px] font-bold shadow-xs';
        statusBadge.innerHTML = '✕ ' + (data.status === 'Cancelled' ? 'ยกเลิกคำขอแล้ว' : 'ไม่อนุมัติคำขอ');

        stepIndicator.textContent = 'ขั้นตอนเสร็จสิ้น (ยกเลิก / ไม่ผ่าน)';
        stepIndicator.className = 'text-rose-900 bg-rose-100 px-3 py-1 rounded-full text-xs font-extrabold border border-rose-300 shadow-xs';

        step1Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-slate-50 border-slate-200 text-slate-700 shadow-xs';
        step1Icon.textContent = '✓';

        step2Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-slate-50 border-slate-200 text-slate-700 shadow-xs';
        step2Icon.textContent = '✕';
        step2Time.textContent = 'สิ้นสุดการตรวจสอบ';

        step3Box.className = 'p-4 rounded-2xl border flex flex-col items-center justify-center gap-1.5 bg-rose-100 border-rose-300 text-rose-900 shadow-xs ring-2 ring-rose-200';
        step3Icon.className = 'w-9 h-9 rounded-full bg-rose-500 text-white flex items-center justify-center text-sm font-bold shadow-xs';
        step3Icon.textContent = '✕';
        step3Title.textContent = '3. สิ้นสุดคำขอ';
        step3Time.textContent = data.cancelledAt ? ('ยกเลิกเมื่อ: ' + data.cancelledAt) : 'ยกเลิกแล้ว';

        stepDescText.textContent = data.cancelReason ? ('คำขอนี้ถูกยกเลิกแล้ว: ' + data.cancelReason) : 'คำขอนี้ถูกยกเลิกหรือไม่ผ่านการอนุมัติ หากมีข้อสงสัยกรุณาติดต่อนิติบุคคล';
        actionDateEl.textContent = data.cancelledAt ? ('ยกเลิกเมื่อ: ' + data.cancelledAt + ' น.') : 'ยกเลิกแล้ว';

        cancelFormArea.classList.add('hidden');
      }

      // Attached vehicle image
      const imgSection = document.getElementById('modalImageSection');
      const modalImg = document.getElementById('modalVehicleImage');
      const clickEl = document.getElementById('modalImageClickable');
      if (data.vehicleImage) {
        modalImg.src = data.vehicleImage;
        clickEl.onclick = function() {
          openDocModal(data.vehicleImage, 'รูปถ่ายรถยนต์ / เอกสารแนบ (' + (data.additionalPlate || '') + ')');
        };
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
      document.body.style.overflow = 'hidden';
      lucide.createIcons();
    }

    function closeDetailModal() {
      document.getElementById('detailModal').classList.add('hidden');
      document.body.style.overflow = '';
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
      const prov = document.getElementById('province').value.trim();
      if (!cur || !add || !prov) {
        Swal.fire({
          icon: 'error',
          title: 'กรุณากรอกข้อมูลให้ครบ',
          text: 'กรุณากรอกทั้งป้ายทะเบียนคันปัจจุบัน ป้ายทะเบียนคันที่ขอเพิ่ม และจังหวัด',
          confirmButtonColor: '#0891b2',
          customClass: { popup: 'rounded-3xl font-sans' }
        });
        return false;
      }
      return true;
    }

    // Real-time status polling for parking requests
    let lastKnownParkingStatus = '<?php echo $pendingRequest ? "Pending" : ($historyRequests[0]["status"] ?? "None"); ?>';
    setInterval(() => {
      fetch('tenant_parking.php?check_realtime=1')
        .then(res => res.json())
        .then(data => {
          if (data && data.status && data.status !== 'None') {
            if (lastKnownParkingStatus === 'Pending' && data.status === 'Approved') {
              lastKnownParkingStatus = data.status;
              Swal.fire({
                icon: 'success',
                title: 'คำขอของคุณได้รับการอนุมัติแล้ว! 🎉',
                text: 'นิติบุคคลได้ทำการอนุมัติสิทธิ์ที่จอดรถสำหรับรถของคุณเรียบร้อยแล้ว',
                confirmButtonColor: '#059669',
                confirmButtonText: 'ดูประวัติคำขอ',
                customClass: { popup: 'rounded-3xl font-sans' }
              }).then(() => {
                window.location.href = 'tenant_parking.php?tab=history';
              });
            } else if (lastKnownParkingStatus === 'Pending' && (data.status === 'Cancelled' || data.status === 'Rejected')) {
              lastKnownParkingStatus = data.status;
              Swal.fire({
                icon: 'info',
                title: 'คำขอของคุณมีการเปลี่ยนแปลงสถานะ',
                text: 'สถานะคำขอ: ' + (data.status === 'Cancelled' ? 'ยกเลิกแล้ว' : 'ไม่ผ่านการอนุมัติ'),
                confirmButtonColor: '#475569',
                confirmButtonText: 'ตกลง',
                customClass: { popup: 'rounded-3xl font-sans' }
              }).then(() => {
                window.location.href = 'tenant_parking.php?tab=history';
              });
            }
          }
        })
        .catch(err => console.log('Polling err:', err));
    }, 7000);

    document.addEventListener('DOMContentLoaded', () => {
      lucide.createIcons();
    });
  </script>
</body>
</html>