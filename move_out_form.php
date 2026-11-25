<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";
$roomId = isset($_GET['roomId']) ? $_GET['roomId'] : '';
$leaseId = isset($_GET['leaseId']) ? $_GET['leaseId'] : '';

if (empty($roomId) || empty($leaseId)) {
    header("Location: manage_room.php");
    exit();
}

// 🌟 สร้างคอลัมน์เพิ่มเติมในตาราง Lease อัตโนมัติ (ถ้ายังไม่มี)
try { $conn->exec("ALTER TABLE Lease ADD COLUMN finalElectricFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN finalWaterFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN cleaningFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN damageFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN breachFee DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN damageDetail TEXT DEFAULT NULL;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN netRefundAmount DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN refundReceipt VARCHAR(255) DEFAULT NULL;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN tenantPaymentSlip VARCHAR(255) DEFAULT NULL;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE Lease ADD COLUMN settledAt DATETIME DEFAULT NULL;"); } catch(PDOException $e) {}
try { $conn->exec("ALTER TABLE RoomChecklist ADD COLUMN estimatedPrice DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}
try {
    $conn->exec("
        UPDATE RoomChecklist SET estimatedPrice = 500 WHERE (itemName LIKE '%เตียงนอน%' OR itemName LIKE '%ที่นอน%');
        UPDATE RoomChecklist SET estimatedPrice = 450 WHERE itemName LIKE '%ตู้เสื้อผ้า%';
        UPDATE RoomChecklist SET estimatedPrice = 350 WHERE itemName LIKE '%โต๊ะ%';
        UPDATE RoomChecklist SET estimatedPrice = 250 WHERE itemName LIKE '%เก้าอี้%';
        UPDATE RoomChecklist SET estimatedPrice = 200 WHERE itemName LIKE '%ผ้าม่าน%';
        UPDATE RoomChecklist SET estimatedPrice = 500 WHERE (itemName LIKE '%เครื่องปรับอากาศ%' OR itemName LIKE '%แอร์%');
        UPDATE RoomChecklist SET estimatedPrice = 400 WHERE itemName LIKE '%เครื่องทำน้ำอุ่น%';
        UPDATE RoomChecklist SET estimatedPrice = 300 WHERE itemName LIKE '%สุขภัณฑ์%';
        UPDATE RoomChecklist SET estimatedPrice = 150 WHERE (itemName LIKE '%คีย์การ์ด%' OR itemName LIKE '%กุญแจ%');
        UPDATE RoomChecklist SET estimatedPrice = 100 WHERE itemName LIKE '%หลอดไฟ%';
        UPDATE RoomChecklist SET estimatedPrice = 500 WHERE estimatedPrice > 500 OR estimatedPrice IS NULL OR estimatedPrice = 0;
    ");
} catch(PDOException $e) {}

// --- 1. ดึงข้อมูลสัญญาเช่า ห้อง ผู้เช่า และข้อมูลบัญชีธนาคารที่ลูกบ้านกรอกไว้ ---
$stmt = $conn->prepare("
    SELECT l.*, r.roomNumber, r.roomType, r.rentPrice, u.fullName, u.phone 
    FROM Lease l
    JOIN Room r ON l.roomId = r.roomId
    JOIN User u ON l.tenantId = u.userId
    WHERE l.leaseId = ?
");
$stmt->execute([$leaseId]);
$leaseData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($leaseData) {
    $leaseData['deposit'] = floatval($leaseData['depositAmount'] ?? 0);
}

$isCompleted = !empty($leaseData) && ((($leaseData['moveOutStatus'] ?? '') === 'Completed') || (($leaseData['status'] ?? '') === 'Inactive'));

if (!$leaseData) {
    echo "<!DOCTYPE html>
    <html lang='th'>
    <head>
        <meta charset='UTF-8'>
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <link href='https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap' rel='stylesheet'>
        <style>
    body {
      background-color: #FAF9F6;
      color: #1e293b;
      font-family: 'Prompt', sans-serif;
      min-height: 100vh;
    }
  </style>
      <?php include_once 'includes/theme_head.php'; ?>
</head>
    <body>
        <script>
            Swal.fire({
                title: 'ไม่พบข้อมูล',
                text: 'ไม่พบข้อมูลสัญญาเช่าที่ใช้งานอยู่',
                icon: 'warning',
                confirmButtonColor: '#3b82f6',
                confirmButtonText: 'ตกลง, กลับหน้าห้องพัก',
                customClass: { popup: 'rounded-3xl shadow-2xl font-sans p-6', confirmButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm' }
            }).then(() => {
                window.location.href = 'manage_room.php';
            });
        </script>
    </body>
    </html>";
    exit();
}

// ดึงเช็คลิสต์ของในห้องพักสำหรับตรวจสอบสภาพ
$stmtChecklist = $conn->prepare("SELECT * FROM RoomChecklist WHERE roomId = ? ORDER BY FIELD(itemStatus, 'Damaged', 'Missing', 'Repairing', 'Good')");
$stmtChecklist->execute([$roomId]);
$roomChecklist = $stmtChecklist->fetchAll(PDO::FETCH_ASSOC);

$damagedItems = [];
foreach ($roomChecklist as $item) {
    if ($item['itemStatus'] === 'Damaged' || $item['itemStatus'] === 'Missing') {
        $damagedItems[] = $item;
    }
}

// ดึงเลขมิเตอร์ล่าสุดจากใบแจ้งหนี้เพื่อเป็นค่าเริ่มต้น
$stmtInv = $conn->prepare("SELECT electricCurr, waterCurr FROM Invoices WHERE roomId = ? ORDER BY invoiceId DESC LIMIT 1");
$stmtInv->execute([$roomId]);
$lastInvoice = $stmtInv->fetch(PDO::FETCH_ASSOC);
$prevElectricVal = $lastInvoice ? floatval($lastInvoice['electricCurr']) : 0;
$prevWaterVal = $lastInvoice ? floatval($lastInvoice['waterCurr']) : 0;

// --- 2. ระบบส่งสรุปยอดย้ายออกให้ลูกบ้าน (ยังไม่เปลี่ยนสถานะห้องเป็นว่าง จนกว่าลูกบ้านจะแนบสลิปและแอดมินยืนยัน) ---
if (isset($_POST['process_move_out'])) {
    try {
        $conn->beginTransaction();

        $finalElectric = min(9999, max(0, floatval($_POST['finalElectric'] ?? 0)));
        $finalWater = min(9999, max(0, floatval($_POST['finalWater'] ?? 0)));
        $prevElectric = min(9999, max(0, floatval($_POST['prevElectric'] ?? 0)));
        $prevWater = min(9999, max(0, floatval($_POST['prevWater'] ?? 0)));

        $elecCost = max(0, $finalElectric - $prevElectric) * 8;
        $waterCost = max(0, $finalWater - $prevWater) * 18;
        $cleaningFee = floatval($_POST['cleaningFee'] ?? 0);
        $damageFee = floatval($_POST['damageFee'] ?? 0);
        $breachFee = floatval($_POST['breachFee'] ?? 0);
        $damageDetail = trim($_POST['damageDetail'] ?? '');

        $depositAmount = floatval($leaseData['depositAmount']);
        $totalDeductions = $elecCost + $waterCost + $cleaningFee + $damageFee + $breachFee;
        $netRefundAmount = $depositAmount - $totalDeductions;

        // จัดการอัปโหลดใบเสร็จ/หลักฐานการโอนเงินประกันคืน (Refund Receipt)
        $refundReceiptPath = null;
        if (isset($_FILES['refundReceipt']) && $_FILES['refundReceipt']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['refundReceipt']['tmp_name'];
            $fileName = $_FILES['refundReceipt']['name'];
            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            
            if (in_array($fileExt, $allowedExts)) {
                $newFileName = 'refund_' . $leaseId . '_' . time() . '.' . $fileExt;
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $targetFile = $uploadDir . $newFileName;
                if (move_uploaded_file($fileTmp, $targetFile)) {
                    $refundReceiptPath = $targetFile;
                }
            }
        }

        // 🌟 อัปเดตเช็คลิสต์สิ่งของในห้องพัก (สถานะ + คอมเม้นสาเหตุความเสียหาย)
        if (isset($_POST['checklist_status']) && is_array($_POST['checklist_status'])) {
            $stmtUpdateChecklist = $conn->prepare("UPDATE RoomChecklist SET itemStatus = ?, notes = ? WHERE checklistId = ? AND roomId = ?");
            foreach ($_POST['checklist_status'] as $cId => $cStatus) {
                $cNote = trim($_POST['checklist_notes'][$cId] ?? '');
                $stmtUpdateChecklist->execute([$cStatus, $cNote, intval($cId), $roomId]);
            }
        }

        // บันทึกรายละเอียดการหักมัดจำ โดยตั้ง moveOutStatus = 'Pending_Payment' (ยังคง status = Active และห้อง = Occupied)
        $stmtUpdateLease = $conn->prepare("
            UPDATE Lease SET 
                finalElectricFee = ?,
                finalWaterFee = ?,
                cleaningFee = ?,
                damageFee = ?,
                breachFee = ?,
                damageDetail = ?,
                netRefundAmount = ?,
                refundReceipt = COALESCE(?, refundReceipt),
                moveOutStatus = 'Pending_Payment',
                settledAt = NOW()
            WHERE leaseId = ?
        ");
        $stmtUpdateLease->execute([$elecCost, $waterCost, $cleaningFee, $damageFee, $breachFee, $damageDetail, $netRefundAmount, $refundReceiptPath, $leaseId]);

        // แจ้งเตือนไปยังลูกบ้าน
        try {
            $msgNoti = "นิติบุคคลได้ส่งสรุปยอดย้ายออกและการคืนเงินประกันห้อง {$leaseData['roomNumber']} เรียบร้อยแล้ว กรุณาเข้าแนบสลิป/ยืนยันในระบบ";
            $stmtNoti = $conn->prepare("INSERT INTO notification (userId, title, message, notificationType) VALUES (?, 'สรุปการย้ายออกและคืนเงินประกัน', ?, 'move_out_settled')");
            $stmtNoti->execute([$leaseData['tenantId'], $msgNoti]);
        } catch(PDOException $e) {}

        $conn->commit();
        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='clock' class='w-5 h-5 text-amber-600'></i> บันทึกสรุปยอดย้ายออกห้อง {$leaseData['roomNumber']} เรียบร้อยแล้ว! สถานะห้องยังคงเป็น Occupied รอลูกบ้านแนบสลิป/หลักฐานการชำระส่วนต่างกลับมาเพื่อให้แอดมินยืนยันให้เป็นห้องว่าง</div>";
        
        // Refresh leaseData
        $stmt->execute([$leaseId]);
        $leaseData = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch(PDOException $e) {
        $conn->rollBack();
        $message = "<div class='alert-box bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-2xl shadow-xs text-sm font-bold'><p class='font-bold'>❌ เกิดข้อผิดพลาด</p><p class='text-xs font-normal'>" . htmlspecialchars($e->getMessage()) . "</p></div>";
    }
}

// --- 3. ระบบยืนยันสลิป & ยืนยันการย้ายออกสมบูรณ์ (เปลี่ยนเป็นห้องว่าง) ---
if (isset($_POST['finalize_move_out'])) {
    try {
        $conn->beginTransaction();

        // 1. เปลี่ยนสถานะสัญญาเป็น Inactive (ย้ายออกเสร็จสมบูรณ์)
        $stmtUpdateLease = $conn->prepare("UPDATE Lease SET status = 'Inactive', moveOutStatus = 'Completed' WHERE leaseId = ?");
        $stmtUpdateLease->execute([$leaseId]);

        // 2. เปลี่ยนสถานะห้องเป็น Available (ห้องว่าง)
        $stmtUpdateRoom = $conn->prepare("UPDATE Room SET status = 'Available' WHERE roomId = ?");
        $stmtUpdateRoom->execute([$roomId]);

        // แจ้งเตือนไปยังลูกบ้าน
        try {
            $msgNoti = "นิติบุคคลได้ตรวจสอบสลิปและยืนยันการย้ายออกห้อง {$leaseData['roomNumber']} สมบูรณ์เรียบร้อยแล้ว ขอบคุณที่ใช้บริการครับ";
            $stmtNoti = $conn->prepare("INSERT INTO notification (userId, title, message, notificationType) VALUES (?, 'การย้ายออกเสร็จสมบูรณ์', ?, 'move_out_completed')");
            $stmtNoti->execute([$leaseData['tenantId'], $msgNoti]);
        } catch(PDOException $e) {}

        $conn->commit();
        $roomNum = htmlspecialchars($leaseData['roomNumber']);
        echo "<!DOCTYPE html>
        <html lang='th'>
        <head>
            <meta charset='UTF-8'>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
            <link href='https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap' rel='stylesheet'>
            <style>
    body {
      background-color: #FAF9F6;
      color: #1e293b;
      font-family: 'Prompt', sans-serif;
      min-height: 100vh;
    }
  </style>
        </head>
        <body>
            <script>
                Swal.fire({
                    title: 'ย้ายออกเสร็จสมบูรณ์! 🎉',
                    html: 'อนุมัติตรวจสอบสลิปและยืนยันการย้ายออกห้อง <b>{$roomNum}</b> สมบูรณ์!<br><span style=\"color:#059669; font-size: 0.9em;\">เปลี่ยนสถานะเป็นห้องว่าง (Available) เรียบร้อยแล้ว</span>',
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                    confirmButtonText: 'ตกลง, ไปหน้าจัดการห้องพัก',
                    timer: 5000,
                    timerProgressBar: true,
                    customClass: {
                        popup: 'rounded-3xl shadow-2xl font-sans p-6',
                        confirmButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm shadow-md'
                    }
                }).then(() => {
                    window.location.href = 'manage_room.php';
                });
            </script>
        </body>
        </html>";
        exit();
    } catch(PDOException $e) {
        $conn->rollBack();
        $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>❌ เกิดข้อผิดพลาด</p><p class='text-sm'>" . $e->getMessage() . "</p></div>";
    }
}

// --- 4. ระบบยกเลิกคำขอแจ้งย้ายออกโดยแอดมิน (คืนสถานะสัญญาเช่าปกติ) ---
if (isset($_POST['cancel_move_out_admin'])) {
    try {
        if (!empty($leaseData['tenantPaymentSlip']) && file_exists($leaseData['tenantPaymentSlip'])) {
            @unlink($leaseData['tenantPaymentSlip']);
        }

        $stmtCancel = $conn->prepare("
            UPDATE Lease 
            SET moveOutRequestDate = NULL, 
                bankName = NULL, 
                bankAccount = NULL, 
                accountName = NULL, 
                moveOutReason = NULL, 
                moveOutStatus = NULL, 
                tenantPaymentSlip = NULL,
                settledAt = NULL,
                refundReceipt = NULL,
                damageDetail = NULL,
                finalElectricFee = 0,
                finalWaterFee = 0,
                cleaningFee = 0,
                damageFee = 0,
                breachFee = 0,
                netRefundAmount = 0
            WHERE leaseId = ?
        ");
        $stmtCancel->execute([$leaseId]);

        // ลบ notification ค้าง
        try {
            $stmtDelNoti = $conn->prepare("
                DELETE FROM notification 
                WHERE notificationType IN ('move_out', 'move_out_slip', 'move_out_settled') 
                AND (message LIKE ? OR message LIKE ?)
            ");
            $searchRoom = "%ห้อง " . $leaseData['roomNumber'] . "%";
            $searchName = "%" . $leaseData['fullName'] . "%";
            $stmtDelNoti->execute([$searchRoom, $searchName]);

            // ส่ง notification แจ้งลูกบ้าน
            $notifyMsg = "นิติบุคคลได้ยกเลิกคำขอแจ้งย้ายออกห้อง {$leaseData['roomNumber']} เรียบร้อยแล้ว สถานะห้องพักกลับสู่สัญญาเช่าปกติ";
            $stmtNoti = $conn->prepare("INSERT INTO notification (userId, title, message, notificationType) VALUES (?, 'คำขอแจ้งย้ายออกถูกยกเลิกแล้ว', ?, 'move_out_cancelled')");
            $stmtNoti->execute([$leaseData['tenantId'], $notifyMsg]);
        } catch(PDOException $e) {}

        header("Location: manage_room.php?cancelled_move_out=" . urlencode($leaseData['roomNumber']));
        exit();
    } catch(PDOException $e) {
        $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>❌ เกิดข้อผิดพลาดในการยกเลิก</p><p class='text-sm'>" . htmlspecialchars($e->getMessage()) . "</p></div>";
    }
}
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
      font-family: 'Prompt', sans-serif;
      min-height: 100vh;
      overflow-x: hidden;
      background-color: #FAF9F6;
      color: #1e293b;
    }
    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
  </style>
  <?php include_once 'includes/swal.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

  <!-- Top Navigation (Admin) - Warm & Cozy Boutique Header -->
  <header class="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-rose-100/80 shadow-[0_1px_4px_0_rgba(244,63,94,0.03)]">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-2xl text-slate-600 hover:text-slate-900 hover:bg-rose-50/70 border border-stone-200/70 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-sm ring-1 ring-rose-500/20 shrink-0">
            <i data-lucide="package-minus" class="w-5 h-5 text-amber-300"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-2.5 py-0.5 rounded-full border border-rose-200 uppercase tracking-wider">MOVE-OUT</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ตรวจสอบห้องและดำเนินการย้ายออก</p>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

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
              <i data-lucide="log-out" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>บันทึกย้ายออก</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="key" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?></span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ตรวจสอบและบันทึกย้ายออก
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?> • ตรวจสอบมิเตอร์ ค่าเสียหาย และคำนวณเงินประกันคืน
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="manage_room.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="arrow-left" class="w-4 h-4 text-slate-500"></i>
            <span>ย้อนกลับไปจัดการห้อง</span>
          </a>
        </div>
      </div>
    </div>

    <?php if(!empty($message)) echo $message; ?>

    <!-- Main Settlement Form -->
    <form action="" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <input type="hidden" name="contractEndDate" id="contractEndDate" value="<?php echo htmlspecialchars($leaseData['endDate']); ?>">
        <input type="hidden" name="moveOutDate" id="moveOutDate" value="<?php echo htmlspecialchars($leaseData['moveOutRequestDate'] ?? date('Y-m-d')); ?>">
        <input type="hidden" name="depositAmount" id="depositAmount" value="<?php echo floatval($leaseData['deposit']); ?>">

        <!-- ฝั่งซ้าย (8 Cols): รายละเอียดการตรวจสอบห้องและค่าใช้จ่าย -->
        <div class="lg:col-span-8 space-y-6">

            <!-- 1. ข้อมูลสัญญาและผู้เช่า -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-6 space-y-5">
                <div class="flex items-center justify-between pb-3 border-b border-stone-100">
                    <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">1</span>
                        <h3 class="font-display font-bold text-slate-900 text-base">ข้อมูลสัญญาและผู้เช่า (Lease & Tenant Overview)</h3>
                    </div>
                    <span class="text-xs font-bold px-2.5 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">
                        ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?>
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                    <div class="p-3.5 bg-stone-50/70 border border-stone-200/70 rounded-2xl">
                        <span class="text-slate-400 block mb-1">ผู้เช่า / ผู้ทำสัญญา</span>
                        <span class="font-bold text-slate-800 text-sm block"><?php echo htmlspecialchars($leaseData['fullName']); ?></span>
                        <span class="text-slate-500 font-mono mt-0.5 block">📞 <?php echo htmlspecialchars($leaseData['phone'] ?? '-'); ?></span>
                    </div>
                    <div class="p-3.5 bg-stone-50/70 border border-stone-200/70 rounded-2xl">
                        <span class="text-slate-400 block mb-1">ประเภทห้อง / ค่าเช่า</span>
                        <span class="font-bold text-slate-800 text-sm block"><?php echo ($leaseData['roomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?></span>
                        <span class="text-rose-600 font-bold font-mono mt-0.5 block">฿<?php echo number_format($leaseData['rentPrice']); ?> / เดือน</span>
                    </div>
                    <div class="p-3.5 bg-emerald-50/50 border border-emerald-200/70 rounded-2xl">
                        <span class="text-emerald-700 block mb-1 font-semibold">เงินประกันมัดจำที่วางไว้</span>
                        <span class="font-bold text-emerald-800 text-base font-mono block">฿<?php echo number_format($leaseData['deposit'], 2); ?></span>
                        <span class="text-[10px] text-emerald-600">ยอดเงินประกันตั้งต้น</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs pt-1">
                    <div>
                        <span class="text-slate-400 block mb-1">วันที่เริ่มสัญญา</span>
                        <span class="font-semibold text-slate-700 font-mono bg-stone-50 px-3 py-1.5 rounded-xl border border-stone-200 block">
                            📅 <?php echo date('d/m/Y', strtotime($leaseData['startDate'])); ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block mb-1">วันที่สิ้นสุดสัญญาตามระบุ</span>
                        <span class="font-semibold text-slate-700 font-mono bg-stone-50 px-3 py-1.5 rounded-xl border border-stone-200 block">
                            📅 <?php echo date('d/m/Y', strtotime($leaseData['endDate'])); ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block mb-1">วันที่แจ้งย้ายออก</span>
                        <span class="font-bold text-rose-700 font-mono bg-rose-50 px-3 py-1.5 rounded-xl border border-rose-200 block">
                            🗓️ <?php echo !empty($leaseData['moveOutRequestDate']) ? date('d/m/Y', strtotime($leaseData['moveOutRequestDate'])) : '-'; ?>
                        </span>
                    </div>
                </div>

                <div id="breachWarning" class="hidden p-4 bg-amber-50 border border-amber-200/90 rounded-2xl flex items-start gap-3 text-amber-900 text-xs shadow-2xs">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
                    <div>
                        <span class="font-bold text-sm block mb-0.5">⚠️ ผู้เช่าแจ้งย้ายออกก่อนครบกำหนดสัญญาเช่า</span>
                        <span class="leading-relaxed">สัญญาเช่าสิ้นสุดวันที่ <?php echo date('d/m/Y', strtotime($leaseData['endDate'])); ?> แต่วันที่ย้ายออกคือ <?php echo !empty($leaseData['moveOutRequestDate']) ? date('d/m/Y', strtotime($leaseData['moveOutRequestDate'])) : date('d/m/Y'); ?> คุณสามารถเลือกว่าจะคืนมัดจำตามจริง หรือเลือกริบมัดจำ/หักค่าปรับอยู่ไม่ครบสัญญาได้ในข้อที่ 4</span>
                    </div>
                </div>
            </div>

            <!-- 2. ตรวจสอบสภาพห้องพักและทรัพย์สิน -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-stone-100 flex-wrap gap-2">
                    <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">2</span>
                        <h3 class="font-display font-bold text-slate-900 text-base">ตรวจสอบสภาพห้องพักและทรัพย์สิน (Room Asset Inspection)</h3>
                    </div>
                    <button type="button" onclick="syncChecklistToDamageDetail(true)" class="px-3.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs cursor-pointer">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-rose-600"></i>
                        <span>ซิงค์ความเสียหาย</span>
                    </button>
                </div>

                <?php if(!empty($roomChecklist)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                        <?php foreach($roomChecklist as $item): 
                            $isDamaged = ($item['itemStatus'] === 'Damaged' || $item['itemStatus'] === 'Missing');
                            $isRepairing = ($item['itemStatus'] === 'Repairing');
                            $cardBg = $isDamaged ? 'bg-rose-50/70 border-rose-300 shadow-2xs' : ($isRepairing ? 'bg-amber-50/70 border-amber-300 shadow-2xs' : 'bg-slate-50/70 border-slate-200');
                        ?>
                            <div id="checklist_card_<?php echo $item['checklistId']; ?>" 
                                 data-price="<?php echo floatval($item['estimatedPrice'] ?? 0); ?>" 
                                 data-qty="<?php echo intval($item['quantity'] ?? 1); ?>" 
                                 class="p-4 rounded-2xl border transition-all space-y-2.5 <?php echo $cardBg; ?>">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-800 text-xs sm:text-sm"><?php echo htmlspecialchars($item['itemName']); ?></span>
                                    <div class="flex items-center gap-2">
                                        <span class="text-[11px] text-slate-500 font-mono">x<?php echo intval($item['quantity'] ?? 1); ?></span>
                                        <span class="text-[11px] text-rose-600 font-bold font-mono">฿<?php echo number_format($item['estimatedPrice'] ?? 0, 2); ?></span>
                                    </div>
                                </div>
                                <div>
                                    <select name="checklist_status[<?php echo $item['checklistId']; ?>]" 
                                            id="checklist_status_<?php echo $item['checklistId']; ?>" 
                                            onchange="onChecklistStatusChange(<?php echo $item['checklistId']; ?>)" 
                                            class="checklist-status-select w-full px-3 py-2 bg-white border border-stone-200 rounded-xl text-xs font-bold text-slate-800 outline-none focus:border-rose-400 shadow-2xs">
                                        <option value="Good" <?php echo ($item['itemStatus'] === 'Good') ? 'selected' : ''; ?>>✅ ปกติ (Good)</option>
                                        <option value="Damaged" <?php echo ($item['itemStatus'] === 'Damaged') ? 'selected' : ''; ?>>⚠️ ชำรุด (Damaged)</option>
                                        <option value="Missing" <?php echo ($item['itemStatus'] === 'Missing') ? 'selected' : ''; ?>>❌ สูญหาย (Missing)</option>
                                        <option value="Repairing" <?php echo ($item['itemStatus'] === 'Repairing') ? 'selected' : ''; ?>>🔧 ส่งซ่อม (Repairing)</option>
                                    </select>
                                </div>
                                <div>
                                    <input type="text" 
                                           name="checklist_notes[<?php echo $item['checklistId']; ?>]" 
                                           id="checklist_notes_<?php echo $item['checklistId']; ?>" 
                                           data-name="<?php echo htmlspecialchars($item['itemName']); ?>" 
                                           value="<?php echo htmlspecialchars($item['notes'] ?? ''); ?>" 
                                           oninput="syncChecklistToDamageDetail()" 
                                           placeholder="ระบุจุดชำรุด หรือสาเหตุ..." 
                                           class="w-full px-3 py-1.5 bg-white border border-stone-200 rounded-xl text-xs text-slate-700 outline-none focus:border-rose-400 shadow-2xs">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-slate-400 py-4 text-center">ไม่มีรายการเช็คลิสต์สิ่งของสำหรับห้องพักนี้</p>
                <?php endif; ?>
            </div>

            <!-- 3. ตรวจสอบเลขมิเตอร์น้ำและมิเตอร์ไฟ -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-6 space-y-5">
                <div class="flex items-center justify-between pb-3 border-b border-stone-100">
                    <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">3</span>
                        <h3 class="font-display font-bold text-slate-900 text-base">ตรวจสอบเลขมิเตอร์น้ำและมิเตอร์ไฟ (Utility Meters)</h3>
                    </div>
                </div>

                <div class="space-y-4">
                    <!-- มิเตอร์ไฟฟ้า -->
                    <div class="p-4 bg-amber-50/50 border border-amber-200/80 rounded-2xl space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="font-bold text-amber-950 text-xs sm:text-sm flex items-center gap-1.5">
                                <i data-lucide="zap" class="w-4 h-4 text-amber-500"></i> มิเตอร์ไฟฟ้า (8 บาท/หน่วย)
                            </label>
                            <span class="text-[11px] text-amber-800 bg-amber-100/80 border border-amber-200/80 px-2.5 py-0.5 rounded-full font-bold font-mono" id="elecUnitsBadge">
                                ใช้ไป: 0 หน่วย
                            </span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[11px] text-slate-600 font-semibold mb-1 block">มิเตอร์ครั้งก่อน (4 หลัก)</label>
                                <input type="number" name="prevElectric" id="prevElectric" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" value="<?php echo intval($prevElectricVal); ?>" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateRefund()" class="w-full px-4 py-2.5 text-sm font-semibold font-mono text-slate-800 bg-white border border-stone-200 rounded-xl outline-none focus:border-amber-400 shadow-2xs">
                            </div>
                            <div>
                                <label class="text-[11px] text-amber-900 font-bold mb-1 block">เลข ณ วันย้ายออก <span class="text-rose-500">*</span> <span class="text-[10px] text-amber-600">(4 หลัก)</span></label>
                                <input type="number" name="finalElectric" id="finalElectric" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" required placeholder="เลข 4 หลัก (0-9999)" value="<?php echo $prevElectricVal > 0 ? intval($prevElectricVal) : ''; ?>" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateRefund()" class="w-full px-4 py-2.5 text-sm font-bold font-mono text-amber-900 bg-white border-2 border-amber-300 rounded-xl outline-none focus:border-amber-500 shadow-2xs">
                            </div>
                        </div>
                    </div>

                    <!-- มิเตอร์น้ำประปา -->
                    <div class="p-4 bg-blue-50/50 border border-blue-200/80 rounded-2xl space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="font-bold text-blue-950 text-xs sm:text-sm flex items-center gap-1.5">
                                <i data-lucide="droplet" class="w-4 h-4 text-blue-500"></i> มิเตอร์น้ำประปา (18 บาท/หน่วย)
                            </label>
                            <span class="text-[11px] text-blue-800 bg-blue-100/80 border border-blue-200/80 px-2.5 py-0.5 rounded-full font-bold font-mono" id="waterUnitsBadge">
                                ใช้ไป: 0 หน่วย
                            </span>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[11px] text-slate-600 font-semibold mb-1 block">มิเตอร์ครั้งก่อน (4 หลัก)</label>
                                <input type="number" name="prevWater" id="prevWater" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" value="<?php echo intval($prevWaterVal); ?>" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateRefund()" class="w-full px-4 py-2.5 text-sm font-semibold font-mono text-slate-800 bg-white border border-stone-200 rounded-xl outline-none focus:border-blue-400 shadow-2xs">
                            </div>
                            <div>
                                <label class="text-[11px] text-blue-900 font-bold mb-1 block">เลข ณ วันย้ายออก <span class="text-rose-500">*</span> <span class="text-[10px] text-blue-600">(4 หลัก)</span></label>
                                <input type="number" name="finalWater" id="finalWater" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" required placeholder="เลข 4 หลัก (0-9999)" value="<?php echo $prevWaterVal > 0 ? intval($prevWaterVal) : ''; ?>" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4); calculateRefund()" class="w-full px-4 py-2.5 text-sm font-bold font-mono text-blue-900 bg-white border-2 border-blue-300 rounded-xl outline-none focus:border-blue-500 shadow-2xs">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. หักค่าใช้จ่าย & ความเสียหาย -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-6 space-y-5">
                <div class="flex items-center justify-between pb-3 border-b border-stone-100">
                    <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">4</span>
                        <h3 class="font-display font-bold text-slate-900 text-base">รายการหักค่าใช้จ่าย & เงื่อนไขคืนเงินประกัน</h3>
                    </div>
                </div>

                <div class="space-y-5">
                    <!-- สวิตช์เลือกระหว่าง คืนเงินมัดจำ vs ริบเงินมัดจำ -->
                    <div class="p-4 bg-stone-50/70 border border-stone-200/80 rounded-2xl">
                        <label class="block text-xs font-bold text-slate-700 mb-2.5">เงื่อนไขการคืนเงินประกันมัดจำ:</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label id="lbl_mode_normal" class="flex items-center gap-3 p-3.5 bg-white rounded-xl border-2 border-emerald-500 cursor-pointer shadow-2xs transition">
                                <input type="radio" name="refundMode" value="normal" checked onchange="toggleRefundMode()" class="w-4 h-4 text-emerald-600 focus:ring-emerald-500">
                                <div>
                                    <span class="font-bold text-xs text-emerald-800 block">🟢 คืนเงินมัดจำ (หักตามจริง)</span>
                                    <span class="text-[11px] text-slate-500">คำนวณหักค่าน้ำ/ไฟ/ความเสียหาย แล้วโอนเงินส่วนต่างคืนผู้เช่า</span>
                                </div>
                            </label>
                            <label id="lbl_mode_forfeit" class="flex items-center gap-3 p-3.5 bg-white rounded-xl border border-stone-200 cursor-pointer shadow-2xs hover:border-rose-400 transition">
                                <input type="radio" name="refundMode" value="forfeit" onchange="toggleRefundMode()" class="w-4 h-4 text-rose-600 focus:ring-rose-500">
                                <div>
                                    <span class="font-bold text-xs text-rose-700 block">🔴 ริบเงินมัดจำทั้งหมด (ไม่คืนมัดจำ)</span>
                                    <span class="text-[11px] text-slate-500">กรณีอยู่ไม่ครบสัญญา หรือค่าเสียหายเกินมัดจำ (ตั้งค่าปรับ = มัดจำ)</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-700 mb-1.5 block">ค่าทำความสะอาด / ล้างแอร์ (บาท)</label>
                            <input type="number" name="cleaningFee" id="cleaningFee" value="<?php echo isset($leaseData['cleaningFee']) ? floatval($leaseData['cleaningFee']) : 500; ?>" step="50" oninput="calculateRefund()" class="w-full px-4 py-2.5 text-sm font-semibold font-mono text-slate-800 bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:bg-white focus:border-rose-400 shadow-2xs">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-700 mb-1.5 block">ค่าปรับไม่ครบสัญญา / ริบมัดจำ (บาท)</label>
                            <input type="number" name="breachFee" id="breachFee" value="<?php echo isset($leaseData['breachFee']) ? floatval($leaseData['breachFee']) : 0; ?>" step="100" oninput="calculateRefund()" class="w-full px-4 py-2.5 text-sm font-semibold font-mono text-slate-800 bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:bg-white focus:border-rose-400 shadow-2xs">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-700 mb-1.5 block">ค่าปรับความเสียหายห้องพัก (บาท)</label>
                            <input type="number" name="damageFee" id="damageFee" value="<?php echo isset($leaseData['damageFee']) ? floatval($leaseData['damageFee']) : 0; ?>" step="50" oninput="calculateRefund()" class="w-full px-4 py-2.5 text-sm font-semibold font-mono text-slate-800 bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:bg-white focus:border-rose-400 shadow-2xs">
                        </div>
                        <div class="sm:col-span-3">
                            <label class="text-xs font-bold text-slate-700 mb-1.5 block">รายละเอียดความเสียหาย (ถ้ามี)</label>
                            <textarea name="damageDetail" id="damageDetail" rows="3" placeholder="เช่น กุญแจหาย, ผนังมีรอยเปื้อน, เตียงหัก..." class="w-full px-4 py-2.5 text-sm text-slate-800 bg-[#FAF9F6] border border-stone-200 rounded-xl outline-none focus:bg-white focus:border-rose-400 shadow-2xs transition"><?php echo htmlspecialchars($leaseData['damageDetail'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. บัญชีรับเงินคืน & แนบหลักฐานใบเสร็จโอนเงินคืน -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-stone-100">
                    <div class="flex items-center gap-2.5">
                        <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">5</span>
                        <h3 class="font-display font-bold text-slate-900 text-base">บัญชีธนาคารผู้เช่า & แนบหลักฐานใบเสร็จโอนเงินคืน</h3>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-600 mb-1.5 block">ธนาคาร</label>
                            <input type="text" readonly value="<?php echo htmlspecialchars($leaseData['bankName'] ?? 'ลูกบ้านยังไม่ได้ระบุ'); ?>" class="w-full px-4 py-2.5 text-sm font-semibold text-slate-700 bg-stone-100/80 border border-stone-200 rounded-xl cursor-not-allowed">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600 mb-1.5 block">เลขที่บัญชี</label>
                            <input type="text" readonly value="<?php echo htmlspecialchars($leaseData['bankAccount'] ?? '-'); ?>" class="w-full px-4 py-2.5 text-sm font-semibold font-mono text-slate-700 bg-stone-100/80 border border-stone-200 rounded-xl cursor-not-allowed">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-600 mb-1.5 block">ชื่อบัญชี</label>
                            <input type="text" readonly value="<?php echo htmlspecialchars($leaseData['accountName'] ?? $leaseData['fullName']); ?>" class="w-full px-4 py-2.5 text-sm font-semibold text-slate-700 bg-stone-100/80 border border-stone-200 rounded-xl cursor-not-allowed">
                        </div>
                    </div>

                    <div id="refundReceiptBox" class="border-t border-stone-100 pt-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">แนบรูปสลิป / ใบเสร็จหลักฐานการโอนเงินประกันคืนให้ผู้เช่า (Refund Receipt)</label>
                        <?php if(!$isCompleted): ?>
                            <input type="file" name="refundReceipt" accept="image/*,.pdf" class="w-full text-xs p-2.5 bg-stone-50 border border-stone-200 rounded-xl outline-none focus:border-rose-400">
                            <span class="text-[11px] text-slate-400 mt-1 block">* รองรับไฟล์รูปภาพ (JPG, PNG) หรือ PDF (ผู้เช่าจะสามารถกดเปิดดูและดาวน์โหลดใบเสร็จนี้ได้ในระบบ)</span>
                        <?php endif; ?>

                        <?php if(!empty($leaseData['refundReceipt'])): ?>
                            <div class="mt-2.5 flex items-center justify-between p-3 bg-rose-50 border border-rose-200 rounded-xl">
                                <div class="flex items-center gap-2">
                                    <i data-lucide="receipt" class="w-4 h-4 text-rose-600"></i>
                                    <span class="text-xs font-bold text-rose-900">มีสลิปหลักฐานโอนคืนเงินประกันในระบบ</span>
                                </div>
                                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($leaseData['refundReceipt'], ENT_QUOTES); ?>', 'หลักฐานการโอนคืนเงินประกัน - ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?>')" class="px-3 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-xs">
                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> ดูสลิปคืนเงิน
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if(!empty($leaseData['tenantPaymentSlip'])): ?>
                            <div class="mt-2.5 flex items-center justify-between p-3 bg-emerald-50 border border-emerald-200 rounded-xl">
                                <div class="flex items-center gap-2">
                                    <i data-lucide="receipt" class="w-4 h-4 text-emerald-600"></i>
                                    <span class="text-xs font-bold text-emerald-900">มีสลิปชำระเงินส่วนต่างจากลูกบ้าน</span>
                                </div>
                                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($leaseData['tenantPaymentSlip'], ENT_QUOTES); ?>', 'สลิปชำระเงินส่วนต่าง - ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?>')" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition flex items-center gap-1 cursor-pointer shadow-xs">
                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> ดูสลิปชำระเงิน
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="noRefundNotice" class="hidden p-4 bg-rose-50 border border-rose-200 rounded-2xl flex items-center gap-3 text-rose-900 text-xs font-bold shadow-2xs">
                        <i data-lucide="shield-alert" class="w-5 h-5 text-rose-600 shrink-0"></i>
                        <div>
                            <span class="font-bold text-sm block mb-0.5">🚫 ริบเงินประกันมัดจำเต็มจำนวน / ไม่มียอดต้องโอนเงินคืนให้ผู้เช่า</span>
                            <span class="font-normal text-slate-600">เนื่องจากยึดมัดจำ หรือมีค่าใช้จ่าย/ความเสียหายหักลบเต็มจำนวนมัดจำแล้ว ไม่จำเป็นต้องแนบสลิปโอนคืน (ผู้เช่าจะเห็นสรุปในระบบทันที)</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ฝั่งขวา (4 Cols): สรุปยอดเงินคืนและดำเนินการ -->
        <div class="lg:col-span-4 sticky top-24 space-y-6">
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-md p-6 space-y-5">
                <div class="flex items-center gap-2 pb-3 border-b border-stone-100">
                    <div class="w-8 h-8 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0">
                        <i data-lucide="calculator" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <h2 class="font-display font-bold text-slate-900 text-base">สรุปยอดเงินคืนประกัน</h2>
                        <p class="text-[11px] text-slate-400">คำนวณหักลบตามจริงอัตโนมัติ</p>
                    </div>
                </div>

                <div class="space-y-3 text-xs">
                    <div id="baseDeposit" data-val="<?php echo floatval($leaseData['deposit']); ?>" class="flex items-center justify-between font-bold text-slate-900 pb-2 border-b border-dashed border-stone-200">
                        <span>เงินประกันมัดจำ (ตั้งต้น):</span>
                        <span class="font-mono text-emerald-600" id="sumDeposit">+฿<?php echo number_format($leaseData['deposit'], 2); ?></span>
                    </div>

                    <div class="flex items-center justify-between text-slate-600">
                        <span>ค่าไฟฟ้าคงค้าง:</span>
                        <span class="font-mono text-rose-600 font-semibold" id="sumElec">-฿0.00</span>
                    </div>

                    <div class="flex items-center justify-between text-slate-600">
                        <span>ค่าน้ำประปาคงค้าง:</span>
                        <span class="font-mono text-rose-600 font-semibold" id="sumWater">-฿0.00</span>
                    </div>

                    <div class="flex items-center justify-between text-slate-600">
                        <span>ค่าทำความสะอาด:</span>
                        <span class="font-mono text-rose-600 font-semibold" id="sumClean">-฿500.00</span>
                    </div>

                    <div class="flex items-center justify-between text-slate-600">
                        <span>ค่าปรับไม่ครบสัญญา:</span>
                        <span class="font-mono text-rose-600 font-semibold" id="sumBreach">-฿0.00</span>
                    </div>

                    <div class="flex items-center justify-between text-slate-600 pb-2 border-b border-dashed border-stone-200">
                        <span>ค่าความเสียหายห้องพัก:</span>
                        <span class="font-mono text-rose-600 font-semibold" id="sumDamage">-฿0.00</span>
                    </div>

                    <div class="flex items-center justify-between font-bold text-slate-800">
                        <span>รวมรายการหักทั้งหมด:</span>
                        <span class="font-mono text-rose-600" id="sumDeductions">-฿500.00</span>
                    </div>
                </div>

                <!-- กล่องยอดสุทธิ -->
                <div class="p-4 rounded-2xl bg-stone-50 border border-stone-200 text-center space-y-1">
                    <div id="netLabel" class="text-xs font-bold text-slate-600">ยอดเงินที่ต้องโอนคืน:</div>
                    <div id="netTotal" class="font-display font-bold text-2xl text-emerald-600">฿0.00</div>
                </div>

                <!-- ส่วนปุ่มกดส่งสรุปยอด / ยืนยันสลิป -->
                <div class="pt-2 space-y-2.5">
                    <?php if($isCompleted): ?>
                        <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs space-y-3">
                            <div class="flex items-center gap-1.5 font-bold text-emerald-900 text-sm">
                                <i data-lucide="check-circle-2" class="w-5 h-5 text-emerald-600"></i>
                                <span>ย้ายออกเสร็จสมบูรณ์เรียบร้อยแล้ว</span>
                            </div>
                            <?php if(!empty($leaseData['settledAt'])): ?>
                                <p class="text-slate-500 text-[11px]">วันที่เคลียร์ห้อง: <?php echo date("d/m/Y H:i น.", strtotime($leaseData['settledAt'])); ?></p>
                            <?php endif; ?>
                            <?php if(!empty($leaseData['refundReceipt'])): ?>
                                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($leaseData['refundReceipt'], ENT_QUOTES); ?>', 'หลักฐานการโอนคืนเงินประกัน - ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?>')" class="w-full py-2 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl text-xs shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                                    <i data-lucide="receipt" class="w-3.5 h-3.5"></i> ดูสลิปคืนเงินประกัน
                                </button>
                            <?php endif; ?>
                            <?php if(!empty($leaseData['tenantPaymentSlip'])): ?>
                                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($leaseData['tenantPaymentSlip'], ENT_QUOTES); ?>', 'สลิปชำระเงินส่วนต่าง - ห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?>')" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                                    <i data-lucide="receipt" class="w-3.5 h-3.5"></i> ดูสลิปชำระเงินจากลูกบ้าน
                                </button>
                            <?php endif; ?>
                        </div>
                        <a href="manage_room.php?tab=moveouts" class="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 block text-center">
                            <i data-lucide="arrow-left" class="w-3.5 h-3.5 inline"></i> กลับหน้ารายการแจ้งย้ายออก
                        </a>
                    <?php elseif(($leaseData['moveOutStatus'] ?? '') === 'Pending_Payment'): ?>
                        <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-2xl text-xs space-y-2">
                            <div class="flex items-center gap-1.5 font-bold text-amber-900">
                                <i data-lucide="clock" class="w-4 h-4 text-amber-600"></i>
                                <span><?php echo !empty($leaseData['tenantPaymentSlip']) ? '📩 ลูกบ้านแนบสลิปแล้ว' : '⏳ รอลูกบ้านแนบสลิป'; ?></span>
                            </div>
                            <?php if(!empty($leaseData['tenantPaymentSlip'])): ?>
                                <p class="text-emerald-800 text-[11px]">ลูกบ้านได้แนบไฟล์สลิปหลักฐานโอนเงินคืนห้องเข้ามาเรียบร้อยแล้ว:</p>
                                <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($leaseData['tenantPaymentSlip'], ENT_QUOTES); ?>')" class="w-full py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-xs shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> ตรวจสอบสลิปจากลูกบ้าน 📄
                                </button>
                                <button type="submit" name="finalize_move_out" onclick="return confirm('ยืนยันอนุมัติตรวจสอบสลิปและเปลี่ยนสถานะห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?> เป็นห้องว่าง (Available) ทันที?');" class="w-full py-3 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-bold rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer text-xs sm:text-sm">
                                    <i data-lucide="check-circle-2" class="w-4 h-4"></i> ยืนยันสลิป & ยืนยันย้ายออกสมบูรณ์
                                </button>
                            <?php else: ?>
                                <p class="text-[11px] text-amber-800">ส่งสรุปยอดเมื่อ <?php echo date("d/m/Y H:i น.", strtotime($leaseData['settledAt'])); ?> (ยังไม่มีสลิปจากลูกบ้าน)</p>
                            <?php endif; ?>
                        </div>

                        <button type="submit" name="process_move_out" onclick="return confirm('ปรับเปลี่ยนสรุปยอดย้ายออกใหม่ใช่หรือไม่?');" class="w-full py-2.5 bg-stone-100 hover:bg-stone-200 text-slate-700 font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer">
                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> อัปเดตยอดย้ายออก / ส่งสรุปยอดใหม่
                        </button>
                    <?php else: ?>
                        <button type="submit" name="process_move_out" onclick="return confirm('ส่งสรุปยอดย้ายออกของห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?> ให้ลูกบ้าน?\n\n(ห้องจะยังคงเป็นสถานะ Occupied เพื่อรอลูกบ้านแนบสลิปกลับมา)');" class="w-full py-3.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white font-bold rounded-2xl shadow-sm transition flex items-center justify-center gap-2 cursor-pointer text-sm active:scale-95">
                            <i data-lucide="send" class="w-4 h-4"></i> ส่งสรุปยอดย้ายออกให้ลูกบ้าน (รอสลิป)
                        </button>
                    <?php endif; ?>

                    <?php if(!empty($leaseData['moveOutRequestDate'])): ?>
                        <button type="submit" name="cancel_move_out_admin" onclick="return confirm('ต้องการยกเลิกคำขอแจ้งย้ายออกของห้อง <?php echo htmlspecialchars($leaseData['roomNumber']); ?> หรือไม่?\n\n(ข้อมูลการขอย้ายออกจะถูกล้างออกจากระบบ และสถานะห้องจะกลับสู่สัญญาเช่าปกติ)');" class="w-full py-2.5 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold rounded-xl text-xs transition flex items-center justify-center gap-1.5 cursor-pointer border border-rose-200 mt-2">
                            <i data-lucide="rotate-ccw" class="w-3.5 h-3.5 text-rose-600"></i> ยกเลิกคำขอแจ้งย้ายออก (คืนสถานะห้องปกติ)
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </form>
  </main>

  <?php include_once 'includes/doc_viewer_modal.php'; ?>

  <script>
    lucide.createIcons();

    function onChecklistStatusChange(cId) {
        const select = document.getElementById('checklist_status_' + cId);
        const card = document.getElementById('checklist_card_' + cId);
        if (!select || !card) return;

        const val = select.value;
        if (val === 'Damaged' || val === 'Missing') {
            card.className = "p-4 rounded-2xl border transition-all space-y-2.5 bg-rose-50/70 border-rose-300 shadow-2xs";
        } else if (val === 'Repairing') {
            card.className = "p-4 rounded-2xl border transition-all space-y-2.5 bg-amber-50/70 border-amber-300 shadow-2xs";
        } else {
            card.className = "p-4 rounded-2xl border transition-all space-y-2.5 bg-slate-50/70 border-slate-200";
        }

        syncChecklistToDamageDetail();
    }

    function syncChecklistToDamageDetail(showAlert = false) {
        const selects = document.querySelectorAll('.checklist-status-select');
        const damagedSummary = [];
        let autoDamageFee = 0;

        selects.forEach(select => {
            const val = select.value;
            const cId = select.id.replace('checklist_status_', '');
            const card = document.getElementById('checklist_card_' + cId);
            const price = parseFloat(card ? card.getAttribute('data-price') : 0) || 0;
            const qty = parseInt(card ? card.getAttribute('data-qty') : 1) || 1;

            if (val === 'Damaged' || val === 'Missing') {
                const noteInput = document.getElementById('checklist_notes_' + cId);
                const itemName = noteInput ? noteInput.getAttribute('data-name') : 'อุปกรณ์';
                const noteVal = noteInput ? noteInput.value.trim() : '';

                const itemTotalCost = price * qty;
                autoDamageFee += itemTotalCost;

                const statusText = (val === 'Damaged') ? 'ชำรุด' : 'สูญหาย';
                const priceBadge = price > 0 ? ` (ราคาอ้างอิง: ฿${price.toLocaleString(undefined, {minimumFractionDigits: 2})})` : '';
                
                if (noteVal !== '') {
                    damagedSummary.push(`• ${itemName} [${statusText}]${priceBadge}: ${noteVal}`);
                } else {
                    damagedSummary.push(`• ${itemName} [${statusText}]${priceBadge}`);
                }
            }
        });

        const detailTextarea = document.getElementById('damageDetail');
        if (detailTextarea) {
            if (damagedSummary.length > 0) {
                detailTextarea.value = "รายการสิ่งของชำรุด/สูญหายจากการตรวจเช็คลิสต์ห้องพัก:\n" + damagedSummary.join('\n');
            } else if (detailTextarea.value.startsWith("รายการสิ่งของชำรุด/สูญหายจากการตรวจเช็คลิสต์ห้องพัก:")) {
                detailTextarea.value = "";
            }
        }

        const damageInput = document.getElementById('damageFee');
        if (damageInput) {
            damageInput.value = autoDamageFee;
            if (typeof calculateRefund === 'function') {
                calculateRefund();
            }
        }

        if (showAlert) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'ซิงค์ข้อมูลสำเร็จ',
                    text: 'ซิงค์รายการอุปกรณ์ชำรุดและคำนวณค่าปรับความเสียหายจากราคาอ้างอิงเรียบร้อยแล้ว!',
                    icon: 'success',
                    confirmButtonColor: '#e11d48'
                });
            } else {
                alert('ซิงค์รายการสิ่งของชำรุดและคำนวณค่าปรับความเสียหายเรียบร้อยแล้ว!');
            }
        }
    }

    function toggleRefundMode() {
        const modes = document.getElementsByName('refundMode');
        let mode = 'normal';
        modes.forEach(r => { if(r.checked) mode = r.value; });

        const breachInput = document.getElementById('breachFee');
        const deposit = parseFloat(document.getElementById('baseDeposit').getAttribute('data-val')) || 0;

        const lblNormal = document.getElementById('lbl_mode_normal');
        const lblForfeit = document.getElementById('lbl_mode_forfeit');

        if (mode === 'forfeit') {
            if (breachInput) breachInput.value = deposit;
            if (lblNormal) lblNormal.className = "flex items-center gap-3 p-3.5 bg-white rounded-xl border border-stone-200 cursor-pointer shadow-2xs transition hover:border-emerald-400";
            if (lblForfeit) lblForfeit.className = "flex items-center gap-3 p-3.5 bg-rose-50/50 rounded-xl border-2 border-rose-500 cursor-pointer shadow-2xs transition";
        } else {
            if (breachInput && parseFloat(breachInput.value) === deposit) {
                breachInput.value = 0;
            }
            if (lblNormal) lblNormal.className = "flex items-center gap-3 p-3.5 bg-white rounded-xl border-2 border-emerald-500 cursor-pointer shadow-2xs transition";
            if (lblForfeit) lblForfeit.className = "flex items-center gap-3 p-3.5 bg-white rounded-xl border border-stone-200 cursor-pointer shadow-2xs transition hover:border-rose-400";
        }
        calculateRefund();
    }

    function checkContractBreach() {
        const endDateStr = document.getElementById('contractEndDate').value;
        const moveOutStr = document.getElementById('moveOutDate').value;
        const warningBox = document.getElementById('breachWarning');

        if (!endDateStr || !moveOutStr) return;

        const endDate = new Date(endDateStr);
        const moveOutDate = new Date(moveOutStr);

        if (moveOutDate < endDate) {
            if (warningBox) warningBox.classList.remove('hidden');
        } else {
            if (warningBox) warningBox.classList.add('hidden');
        }
    }

    function calculateRefund() {
        const deposit = parseFloat(document.getElementById('baseDeposit').getAttribute('data-val')) || 0;
        
        // มิเตอร์น้ำ 18 บาท/หน่วย
        const fWater = parseFloat(document.getElementById('finalWater').value) || 0;
        const pWater = parseFloat(document.getElementById('prevWater').value) || 0;
        const waterUnits = Math.max(0, fWater - pWater);
        const waterCost = waterUnits * 18;

        // มิเตอร์ไฟ 8 บาท/หน่วย
        const fElec = parseFloat(document.getElementById('finalElectric').value) || 0;
        const pElec = parseFloat(document.getElementById('prevElectric').value) || 0;
        const elecUnits = Math.max(0, fElec - pElec);
        const elecCost = elecUnits * 8;

        const wBadge = document.getElementById('waterUnitsBadge');
        if (wBadge) wBadge.innerText = 'ใช้ไป: ' + waterUnits.toLocaleString(undefined, {maximumFractionDigits: 1}) + ' หน่วย (฿' + waterCost.toLocaleString(undefined, {minimumFractionDigits: 2}) + ')';

        const eBadge = document.getElementById('elecUnitsBadge');
        if (eBadge) eBadge.innerText = 'ใช้ไป: ' + elecUnits.toLocaleString(undefined, {maximumFractionDigits: 1}) + ' หน่วย (฿' + elecCost.toLocaleString(undefined, {minimumFractionDigits: 2}) + ')';

        const cleaning = parseFloat(document.getElementById('cleaningFee').value) || 0;
        const breach = parseFloat(document.getElementById('breachFee').value) || 0;
        const damages = parseFloat(document.getElementById('damageFee').value) || 0;

        const totalDeductions = waterCost + elecCost + cleaning + breach + damages;
        const netRefund = deposit - totalDeductions;

        // ซ่อน/แสดง กล่องแนบสลิปคืนเงิน
        const receiptBox = document.getElementById('refundReceiptBox');
        const noticeBox = document.getElementById('noRefundNotice');
        const modes = document.getElementsByName('refundMode');
        let mode = 'normal';
        modes.forEach(r => { if(r.checked) mode = r.value; });

        if (netRefund <= 0 || mode === 'forfeit') {
            if (receiptBox) receiptBox.classList.add('hidden');
            if (noticeBox) noticeBox.classList.remove('hidden');
        } else {
            if (receiptBox) receiptBox.classList.remove('hidden');
            if (noticeBox) noticeBox.classList.add('hidden');
        }

        // อัปเดต UI รายการหัก
        document.getElementById('sumWater').innerText = '-฿' + waterCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sumElec').innerText = '-฿' + elecCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sumClean').innerText = '-฿' + cleaning.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sumBreach').innerText = '-฿' + breach.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sumDamage').innerText = '-฿' + damages.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('sumDeductions').innerText = '-฿' + totalDeductions.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const netElement = document.getElementById('netTotal');
        const netLabel = document.getElementById('netLabel');

        if (netRefund >= 0) {
            netLabel.innerText = (mode === 'forfeit') ? "ยอดเงินคืนหลังริบมัดจำ:" : "ยอดเงินประกันสุทธิที่ต้องโอนคืน:";
            netElement.className = "font-display font-bold text-2xl text-emerald-600";
            netElement.innerText = '฿' + netRefund.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        } else {
            netLabel.innerText = "ผู้เช่าต้องชำระเงินเพิ่ม:";
            netElement.className = "font-display font-bold text-2xl text-rose-600";
            netElement.innerText = '฿' + Math.abs(netRefund).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }

    // บังคับจำกัดเลขมิเตอร์น้ำและไฟให้กรอกได้ไม่เกิน 4 หลัก
    ['prevWater', 'finalWater', 'prevElectric', 'finalElectric'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function() {
                if (this.value.length > 4) {
                    this.value = this.value.slice(0, 4);
                }
            });
        }
    });

    window.onload = () => {
        checkContractBreach();
        syncChecklistToDamageDetail();
        calculateRefund();
    };
  </script>
</body>
</html>
