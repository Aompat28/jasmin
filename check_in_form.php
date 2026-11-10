<?php
session_start();
require_once 'includes/db_connect.php';
require_once 'includes/mailer.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin เท่านั้น
if (!isset($_SESSION['user_id']) or $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";
$preSelectedRoom = isset($_GET['room']) ? $_GET['room'] : '';
$preSelectedPrice = isset($_GET['price']) ? $_GET['price'] : '';

// 🌟 สร้างตาราง RoomChecklist อัตโนมัติ (ถ้ายังไม่มี)
$conn->exec("CREATE TABLE IF NOT EXISTS RoomChecklist (
    checklistId INT AUTO_INCREMENT PRIMARY KEY,
    roomId INT NOT NULL,
    itemName VARCHAR(255) NOT NULL,
    category VARCHAR(100) DEFAULT 'เฟอร์นิเจอร์',
    quantity INT DEFAULT 1,
    estimatedPrice DECIMAL(10,2) DEFAULT 0,
    itemStatus VARCHAR(50) DEFAULT 'Good',
    notes TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (roomId) REFERENCES Room(roomId) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

try { $conn->exec("ALTER TABLE RoomChecklist ADD COLUMN estimatedPrice DECIMAL(10,2) DEFAULT 0;"); } catch(PDOException $e) {}

// รายการอุปกรณ์มาตรฐานประจำห้อง (Default Preset 10 รายการ)
$defaultItems = [
    ['itemName' => 'เตียงนอนพร้อมฟูกที่นอน (5 ฟุต)', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 500, 'itemStatus' => 'Good', 'notes' => 'สภาพใหม่ ไม่เลอะ'],
    ['itemName' => 'ตู้เสื้อผ้าไม้ / ตู้เสื้อผ้าบิวต์อิน', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 450, 'itemStatus' => 'Good', 'notes' => 'มีไม้แขวนเสื้อ 5 อัน'],
    ['itemName' => 'เครื่องปรับอากาศ (รีโมท 1 อัน)', 'category' => 'เครื่องใช้ไฟฟ้า', 'quantity' => 1, 'estimatedPrice' => 500, 'itemStatus' => 'Good', 'notes' => 'ล้างแอร์เรียบร้อย'],
    ['itemName' => 'เครื่องทำน้ำอุ่น', 'category' => 'เครื่องใช้ไฟฟ้า', 'quantity' => 1, 'estimatedPrice' => 400, 'itemStatus' => 'Good', 'notes' => 'มีสายดินและตัดไฟ ELCB'],
    ['itemName' => 'โต๊ะทำงาน / โต๊ะเขียนหนังสือ', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 350, 'itemStatus' => 'Good', 'notes' => 'มีลิ้นชักพร้อมกุญแจ'],
    ['itemName' => 'ชุดสุขภัณฑ์ & ฝักบัวอาบน้ำ', 'category' => 'ห้องน้ำ', 'quantity' => 1, 'estimatedPrice' => 300, 'itemStatus' => 'Good', 'notes' => 'ชักโครก สายฉีดชำระปกติ'],
    ['itemName' => 'เก้าอี้ทำงาน', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 250, 'itemStatus' => 'Good', 'notes' => 'ขาเก้าอี้แข็งแรง'],
    ['itemName' => 'ผ้าม่านกันแสงหน้าต่าง/ระเบียง', 'category' => 'เฟอร์นิเจอร์', 'quantity' => 1, 'estimatedPrice' => 200, 'itemStatus' => 'Good', 'notes' => 'ซักทำความสะอาดแล้ว'],
    ['itemName' => 'คีย์การ์ด / กุญแจเข้าห้องพัก', 'category' => 'ระบบความปลอดภัย', 'quantity' => 2, 'estimatedPrice' => 150, 'itemStatus' => 'Good', 'notes' => 'กุญแจ 2 ดอก คีย์การ์ด 1 ใบ'],
    ['itemName' => 'หลอดไฟส่องสว่าง LED', 'category' => 'ระบบไฟฟ้า', 'quantity' => 2, 'estimatedPrice' => 100, 'itemStatus' => 'Good', 'notes' => 'เปิดติดครบทุกจุด']
];

// --- ระบบบันทึกข้อมูลเมื่อกดตกลงทำสัญญา ---
if (isset($_POST['create_lease'])) {
    // รับค่าจากฟอร์ม
    $roomId = $_POST['roomId'];
    $username = trim($_POST['username']); 
    $plainPassword = trim($_POST['phone']); 
    $fullName = trim($_POST['fullName']);
    $idCard = trim($_POST['idCard']);
    $phone = $plainPassword;
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);

    // 🌟 1. ดักเช็ค Username ซ้ำก่อน
    $stmtCheckUser = $conn->prepare("SELECT COUNT(*) FROM User WHERE username = ?");
    $stmtCheckUser->execute([$username]);
    
    if ($stmtCheckUser->fetchColumn() > 0) {
        $message = "<div class='bg-amber-50 border-l-4 border-amber-500 text-amber-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ไม่สามารถทำสัญญาได้</p><p class='text-sm'>ชื่อผู้ใช้งาน (Username) <b>'{$username}'</b> มีในระบบแล้ว กรุณาตั้งชื่ออื่นครับ</p></div>";
    } elseif (!preg_match('/^[a-zA-Z\x{0E00}-\x{0E7F}\s]+$/u', $fullName)) {
        $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ข้อมูลไม่ถูกต้อง</p><p class='text-sm'>ชื่อ-นามสกุล ต้องเป็นตัวอักษรภาษาไทยหรือภาษาอังกฤษเท่านั้น (ห้ามใส่ตัวเลขหรือสัญลักษณ์)</p></div>";
    } elseif (!preg_match('/^[0-9]{13}$/', $idCard)) {
        $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ข้อมูลไม่ถูกต้อง</p><p class='text-sm'>เลขประจำตัวประชาชน ต้องเป็นตัวเลข 13 หลักเท่านั้น (ปัจจุบันกรอก: " . strlen($idCard) . " หลัก)</p></div>";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ข้อมูลไม่ถูกต้อง</p><p class='text-sm'>เบอร์โทรศัพท์ ต้องเป็นตัวเลข 10 หลักเท่านั้น (ปัจจุบันกรอก: " . strlen($phone) . " หลัก)</p></div>";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ข้อมูลไม่ถูกต้อง</p><p class='text-sm'>รูปแบบอีเมลไม่ถูกต้อง กรุณากรอกอีเมลให้สมบูรณ์ (เช่น tenant@example.com)</p></div>";
    } else {
        $password = password_hash($plainPassword, PASSWORD_DEFAULT);
        
        $emergencyName = trim($_POST['emergencyName']);
        $emergencyPhone = trim($_POST['emergencyPhone']);
        $emergencyContact = $emergencyName . " - " . $emergencyPhone;
        
        $startDate = $_POST['startDate'];
        $endDate = $_POST['endDate'];
        $depositAmount = floatval($_POST['depositAmount']);
        
        $waterMeter = min(9999, max(0, floatval($_POST['waterMeter'])));
        $electricMeter = min(9999, max(0, floatval($_POST['electricMeter'])));

        // ระบบอัปโหลดไฟล์สัญญา (จำเป็นต้องแนบ)
        $leaseImage = null;
        if (!isset($_FILES['leaseImage']) || $_FILES['leaseImage']['error'] != 0 || empty($_FILES['leaseImage']['tmp_name'])) {
            $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ข้อมูลไม่ครบถ้วน</p><p class='text-sm'>กรุณาแนบไฟล์เอกสารสัญญาเช่า (รูปภาพหรือ PDF) เพื่อประกอบการเปิดสัญญาเช่า</p></div>";
        } else {
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
            
            $fileExt = strtolower(pathinfo($_FILES['leaseImage']['name'], PATHINFO_EXTENSION));
            $fileName = time() . '_lease_room' . $roomId . '.' . $fileExt;
            $targetFilePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['leaseImage']['tmp_name'], $targetFilePath)) {
                $leaseImage = $targetFilePath;
            }
        }

        if (!empty($leaseImage)) {

        try {
            $conn->beginTransaction();

            $stmtUser = $conn->prepare("INSERT INTO `User` (username, password, fullName, phone, email, emergencyName, emergencyPhone, role) VALUES (?, ?, ?, ?, ?, ?, ?, 'Tenant')");
            $stmtUser->execute([$username, $password, $fullName, $phone, $email, $emergencyName, $emergencyPhone]);
            $tenantId =$conn->lastInsertId();

            $stmtLease = $conn->prepare("INSERT INTO Lease (roomId, tenantId, startDate, endDate, depositAmount, leaseImage, initialWaterMeter, initialElectricMeter, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
            $stmtLease->execute([$roomId, $tenantId, $startDate, $endDate, $depositAmount, $leaseImage, $waterMeter, $electricMeter]);
            
            $stmtRoom =$conn->prepare("UPDATE Room SET status = 'Occupied' WHERE roomId = ?");
            $stmtRoom->execute([$roomId]);

            // 🌟 ตรวจสอบและบันทึกรายการเช็คลิสต์อุปกรณ์ตามที่แอดมินติ๊กเลือกจริง
            if (isset($_POST['checklist_items']) && is_array($_POST['checklist_items'])) {
                // ล้างรายการเก่าของห้องนี้ออกก่อน เพื่อบันทึกเฉพาะสิ่งของที่มีอยู่จริงในห้องตามที่แอดมินเลือก
                $stmtDelCL = $conn->prepare("DELETE FROM RoomChecklist WHERE roomId = ?");
                $stmtDelCL->execute([$roomId]);

                $stmtAddCL = $conn->prepare("INSERT INTO RoomChecklist (roomId, itemName, category, quantity, estimatedPrice, itemStatus, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($_POST['checklist_items'] as $item) {
                    if (isset($item['is_present']) && $item['is_present'] == '1') {
                        $stmtAddCL->execute([
                            $roomId,
                            $item['itemName'],
                            $item['category'] ?? 'เฟอร์นิเจอร์',
                            intval($item['quantity'] ?? 1),
                            floatval($item['estimatedPrice'] ?? 0),
                            $item['itemStatus'] ?? 'Good',
                            $item['notes'] ?? ''
                        ]);
                    }
                }
            } else {
                $stmtCheckCL = $conn->prepare("SELECT COUNT(*) FROM RoomChecklist WHERE roomId = ?");
                $stmtCheckCL->execute([$roomId]);
                if ($stmtCheckCL->fetchColumn() == 0) {
                    $stmtAddCL = $conn->prepare("INSERT INTO RoomChecklist (roomId, itemName, category, quantity, estimatedPrice, itemStatus, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    foreach ($defaultItems as $item) {
                        $stmtAddCL->execute([$roomId, $item['itemName'], $item['category'], $item['quantity'], $item['estimatedPrice'], $item['itemStatus'], $item['notes']]);
                    }
                }
            }

            $conn->commit();
            
            // 🌟 2. แสดงข้อความสำเร็จ และ เด้งกลับหน้า manage_room.php อัตโนมัติใน 3 วินาที
            $message = "
            <div class='bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 mb-6 rounded-md shadow-sm'>
                <p class='font-bold'>✅ เปิดสัญญาเช่าสำเร็จ!</p>
                <p class='text-sm'>บันทึกข้อมูลผู้เช่า สัญญาเช่า ลงทะเบียนเช็คลิสต์อุปกรณ์ และอัปเดตสถานะห้องพักเรียบร้อยแล้ว</p>
                <p class='text-xs text-emerald-600 mt-2 font-bold'>🔄 กำลังพากลับไปยังหน้าจัดการห้องพักใน 3 วินาที...</p>
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = 'manage_room.php';
                }, 3000);
            
    function selectDuration(dur) {
        const input = document.getElementById('duration');
        if (input) input.value = dur;
        const btn3 = document.getElementById('btnDur3');
        const btn6 = document.getElementById('btnDur6');
        const btn12 = document.getElementById('btnDur12');
        const inactive = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-0.5 text-center transition cursor-pointer border-stone-200 bg-white hover:border-rose-300 text-slate-700 font-semibold';
        if (btn3) btn3.className = inactive;
        if (btn6) btn6.className = inactive;
        if (btn12) btn12.className = inactive;
        const active = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-0.5 text-center transition cursor-pointer border-rose-500 bg-rose-50/80 text-rose-900 font-bold shadow-xs';
        if (dur === 3 && btn3) btn3.className = active;
        if (dur === 6 && btn6) btn6.className = active;
        if (dur === 12 && btn12) btn12.className = active;
        calculateEndDate();
    }

</script>";

            // Best-effort: ส่งอีเมลต้อนรับ
            $safeName = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
            $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
            $safePass = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
            sendMail(
                $email,
                '[หอพักจัสมิน] ยินดีต้อนรับ — ข้อมูลเข้าสู่ระบบของคุณ',
                "<h2>สวัสดีคุณ {$safeName}</h2>
                <p>บัญชีลูกบ้านของคุณถูกสร้างในระบบหอพักจัสมิน เรียบร้อยแล้ว</p>
                <p><b>Username:</b> {$safeUser}<br>
                <b>รหัสผ่านเริ่มต้น:</b> {$safePass} (เบอร์โทรศัพท์ของคุณ)</p>
                <p>กรุณาเข้าสู่ระบบและเปลี่ยนรหัสผ่านหลังเข้าใช้งานครั้งแรก</p>"
            );
        } catch(PDOException $e) {
            $conn->rollBack();$message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>❌ เกิดข้อผิดพลาด</p><p class='text-sm'>" . $e->getMessage() . "</p></div>";
        }
        }
    }
}

// ดึงรายการห้องว่างมาโชว์ในตัวเลือก
$availableRooms =$conn->query("SELECT * FROM Room WHERE status = 'Available' ORDER BY roomNumber ASC")->fetchAll(PDO::FETCH_ASSOC);

// ดึงเช็คลิสต์อุปกรณ์ทั้งหมดที่มีในระบบ จัดกลุ่มตาม roomId
$checklistsByRoom = [];
try {
    $stmtAllCL = $conn->query("SELECT * FROM RoomChecklist ORDER BY FIELD(category, 'เฟอร์นิเจอร์', 'เครื่องใช้ไฟฟ้า', 'ระบบไฟฟ้า', 'ห้องน้ำ', 'ระบบความปลอดภัย'), checklistId ASC");
    $rawChecklists = $stmtAllCL->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rawChecklists as $cl) {
        $checklistsByRoom[$cl['roomId']][] = $cl;
    }
} catch(PDOException $e) {}
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
      theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } }
    }
    function selectDuration(dur) {
        const input = document.getElementById('duration');
        if (input) input.value = dur;
        const btn3 = document.getElementById('btnDur3');
        const btn6 = document.getElementById('btnDur6');
        const btn12 = document.getElementById('btnDur12');
        const inactive = 'py-3 px-2 rounded-2xl border-2 flex items-center justify-center text-center transition cursor-pointer border-stone-200 bg-white hover:border-rose-300 text-slate-700 font-semibold text-xs sm:text-sm';
        if (btn3) btn3.className = inactive;
        if (btn6) btn6.className = inactive;
        if (btn12) btn12.className = inactive;
        const active = 'py-3 px-2 rounded-2xl border-2 flex items-center justify-center text-center transition cursor-pointer border-rose-500 bg-rose-50/80 text-rose-900 font-bold text-xs sm:text-sm shadow-xs';
        if (dur === 3 && btn3) btn3.className = active;
        if (dur === 6 && btn6) btn6.className = active;
        if (dur === 12 && btn12) btn12.className = active;
        calculateEndDate();
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
    .boutique-card {
      background-color: #ffffff;
      border: 1px solid rgba(231, 229, 228, 0.85);
      box-shadow: 0 4px 20px -4px rgba(28, 25, 23, 0.04), 0 2px 6px -2px rgba(28, 25, 23, 0.02);
      border-radius: 1.5rem;
    }
    input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="file"]), select, textarea {
        background-color: #FAF9F6; border: 1px solid #e2e8f0; border-radius: 0.875rem;
        padding: 0.625rem 1rem; font-size: 0.875rem; width: 100%; transition: all 0.2s; color: #1e293b;
    }
    input:focus, select:focus, textarea:focus { background-color: #ffffff; border-color: #f43f5e; outline: none; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.12); }
    label { display: block; font-size: 0.75rem; font-weight: 600; color: #475569; margin-bottom: 0.375rem; }
    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

  <!-- Top Navigation (Admin) -->
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
            <i data-lucide="file-signature" class="w-5 h-5 text-amber-300"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-blue-500/20 text-blue-300 font-bold px-2 py-0.5 rounded-full border border-blue-500/30 uppercase tracking-wider">CHECK-IN</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ทำสัญญาเช่าใหม่ (ย้ายเข้า)</p>
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
              <i data-lucide="file-signature" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>สัญญาเช่าใหม่</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="door-open" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ขั้นตอนการย้ายเข้า (Check-In)</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ทำสัญญาเช่าใหม่ (ย้ายเข้า)
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            บันทึกข้อมูลผู้เช่า กำหนดระยะเวลาสัญญา จดเลขมิเตอร์เริ่มต้น และแนบเอกสารสัญญา
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

    <?php echo $message; ?>

    <form action="" method="POST" enctype="multipart/form-data" id="leaseForm" onsubmit="return validateLeaseForm(event);" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        
        <div class="lg:col-span-8 space-y-6">

            <!-- 1. ข้อมูลห้องพัก & มิเตอร์เริ่มต้น -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm overflow-hidden">
                <div class="px-5 py-4 bg-slate-50/80 border-b border-stone-100 flex items-center gap-3">
                    <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">1</span>
                    <h3 class="font-display font-bold text-slate-900 text-sm">ข้อมูลห้องพักและมิเตอร์ (Room & Meters)</h3>
                </div>
                <div class="p-5 sm:p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div class="sm:col-span-2">
                        <label>เลือกห้องพักที่ต้องการทำสัญญา <span class="text-rose-500">*</span></label>
                        <div class="relative">
                            <select name="roomId" id="roomSelect" required onchange="updateRentSummary(this); updateRoomChecklist(this.value)" class="w-full px-4 py-3 pr-10 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 rounded-2xl text-xs sm:text-sm font-bold text-slate-800 shadow-xs outline-none transition appearance-none cursor-pointer">
                                <option value="">-- เลือกห้องว่าง --</option>
                                <?php foreach($availableRooms as$r): ?>
                                    <option value="<?php echo $r['roomId']; ?>" data-price="<?php echo $r['rentPrice']; ?>" data-type="<?php echo $r['roomType']; ?>" <?php echo ($preSelectedRoom ==$r['roomNumber']) ? 'selected' : ''; ?>>
                                        ห้อง <?php echo $r['roomNumber']; ?> (<?php echo$r['roomType'] == 'Air' ? '❄️ ห้องแอร์' : '🌀 พัดลม'; ?>) - ฿<?php echo number_format($r['rentPrice']); ?>/เดือน
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-rose-500">
                                <i data-lucide="chevron-down" class="w-4 h-4"></i>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label>เลขมิเตอร์น้ำประปา <span class="text-rose-500">*</span> <span class="text-[10px] text-slate-400 font-normal">(สูงสุด 4 หลัก 0-9999)</span></label>
                        <div class="relative">
                            <i data-lucide="droplet" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-blue-500 pointer-events-none"></i>
                            <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="waterMeter" id="waterMeter" required placeholder="เช่น 0000 (4 หลัก)" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4);" class="!pl-10">
                        </div>
                    </div>
                    <div>
                        <label>เลขมิเตอร์ไฟฟ้า เริ่มต้น <span class="text-rose-500">*</span> <span class="text-[10px] text-slate-400 font-normal">(สูงสุด 4 หลัก 0-9999)</span></label>
                        <div class="relative">
                            <i data-lucide="zap" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-amber-500 pointer-events-none"></i>
                            <input type="number" step="1" min="0" max="9999" maxlength="4" inputmode="numeric" name="electricMeter" id="electricMeter" required placeholder="เช่น 0000 (4 หลัก)" oninput="if(this.value.length > 4) this.value = this.value.slice(0, 4);" class="!pl-10">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. เช็คลิสต์อุปกรณ์และทรัพย์สินประจำห้อง (Room Checklist) -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm overflow-hidden" id="checklistCard">
                <div class="px-5 py-4 bg-slate-50/80 border-b border-stone-100 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">2</span>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-display font-bold text-slate-900 text-sm">รายการเช็คลิสต์อุปกรณ์และทรัพย์สินประจำห้อง (Room Checklist)</h3>
                                <span id="checklistCountBadge" class="text-xs bg-rose-50 text-rose-700 font-bold px-2.5 py-0.5 rounded-full border border-rose-200">0 รายการ</span>
                            </div>
                            <p class="text-[11px] text-slate-500 mt-0.5">แสดงรายละเอียดอุปกรณ์ สภาพปัจจุบัน และราคาอ้างอิงก่อนส่งมอบห้องให้ลูกบ้าน</p>
                        </div>
                    </div>
                    <div id="checklistHeaderAction" class="hidden">
                        <a id="editChecklistLink" href="#" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100 text-xs font-bold border border-rose-200 transition shadow-2xs">
                            <i data-lucide="external-link" class="w-3.5 h-3.5"></i> จัดการ/แก้ไขเช็คลิสต์ห้องนี้
                        </a>
                    </div>
                </div>
                <div class="p-5 sm:p-6" id="checklistContainer">
                    <div class="text-center py-8 text-slate-400">
                        <i data-lucide="clipboard-list" class="w-10 h-10 mx-auto mb-2 text-slate-300"></i>
                        <p class="text-sm font-medium">กรุณาเลือกห้องพักด้านบนเพื่อแสดงรายละเอียดเช็คลิสต์อุปกรณ์</p>
                    </div>
                </div>
            </div>

            <!-- 3. ข้อมูลผู้เช่า -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm overflow-hidden">
                <div class="px-5 py-4 bg-slate-50/80 border-b border-stone-100 flex items-center gap-3">
                    <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">3</span>
                    <h3 class="font-display font-bold text-slate-900 text-sm">ข้อมูลผู้เช่า (Tenant Profile)</h3>
                </div>
                <div class="p-5 sm:p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label>ชื่อผู้ใช้งานระบบ (Username) <span class="text-rose-500">*</span></label>
                        <input type="text" name="username" required placeholder="ตั้งชื่อสำหรับ Login แอป">
                    </div>
                    <div>
                        <label>ชื่อ-นามสกุล (ผู้เช่าหลัก) <span class="text-rose-500">*</span></label>
                        <input type="text" name="fullName" id="fullNameInput" required placeholder="ตามบัตรประชาชน" oninput="validateFullName(this)">
                        <span id="nameWarn" class="text-[11px] text-rose-500 font-semibold mt-1 hidden block"></span>
                    </div>
                    <div>
                        <label>เลขประจำตัวประชาชน <span class="text-rose-500">*</span></label>
                        <input type="text" name="idCard" id="idCardInput" required maxlength="13" placeholder="13 หลัก (เฉพาะตัวเลข)" oninput="validateIdCard(this)">
                        <span id="idCardWarn" class="text-[11px] text-rose-500 font-semibold mt-1 hidden block"></span>
                    </div>
                    <div>
                        <label>เบอร์โทรศัพท์ <span class="text-rose-500">*</span></label>
                        <input type="tel" name="phone" id="phoneInput" required maxlength="10" placeholder="10 หลัก (เฉพาะตัวเลข)" oninput="validatePhone(this)">
                        <span id="phoneWarn" class="text-[11px] text-rose-500 font-semibold mt-1 hidden block"></span>
                        <span class="text-[10px] text-slate-400 mt-1 block">*ระบบจะใช้เบอร์โทรเป็นรหัสผ่านเริ่มต้น</span>
                    </div>
                    <div>
                        <label>อีเมล <span class="text-rose-500">*</span></label>
                        <input type="email" name="email" id="emailInput" required placeholder="tenant@example.com" oninput="validateEmail(this)" onblur="validateEmail(this)">
                        <span id="emailWarn" class="text-[11px] text-rose-500 font-semibold mt-1 hidden block"></span>
                        <span class="text-[10px] text-slate-400 mt-1 block">*ใช้สำหรับส่งข้อมูลเข้าสู่ระบบและแจ้งเตือนบิล</span>
                    </div>
                    <div class="sm:col-span-2">
                        <label>ที่อยู่ตามทะเบียนบ้าน</label>
                        <textarea name="address" rows="2" placeholder="เลขที่, หมู่, ถนน, ตำบล, อำเภอ, จังหวัด"></textarea>
                    </div>
                    
                    <div class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-5 pt-4 border-t border-slate-100 mt-2">
                        <div>
                            <label>ชื่อบุคคลติดต่อฉุกเฉิน</label>
                            <input type="text" name="emergencyName" placeholder="เช่น มารดา, บิดา, ญาติ">
                        </div>
                        <div>
                            <label>เบอร์โทรฉุกเฉิน</label>
                            <input type="tel" name="emergencyPhone" placeholder="08xxxxxxxx">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. ระยะเวลาสัญญา & การเงิน & แนบเอกสาร -->
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm overflow-hidden">
                <div class="px-5 py-4 bg-slate-50/80 border-b border-stone-100 flex items-center gap-3">
                    <span class="w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center text-xs font-bold shadow-2xs">4</span>
                    <h3 class="font-display font-bold text-slate-900 text-sm">ระยะเวลาสัญญา และ เงินประกัน (Contract & Documents)</h3>
                </div>
                <div class="p-5 sm:p-6 space-y-5">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label>วันเริ่มต้นสัญญา (Start Date) <span class="text-rose-500">*</span></label>
                            <input type="date" name="startDate" id="startDate" required value="<?php echo date('Y-m-d'); ?>" onchange="calculateEndDate()">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">ระยะเวลาสัญญา</label>
                            <input type="hidden" id="duration" value="12">
                            <div class="grid grid-cols-3 gap-2">
                                <button type="button" onclick="selectDuration(3)" id="btnDur3"
                                        class="py-3 px-2 rounded-2xl border-2 flex items-center justify-center text-center transition cursor-pointer border-stone-200 bg-white hover:border-rose-300 text-slate-700 font-semibold text-xs sm:text-sm">
                                    <span>3 เดือน</span>
                                </button>
                                <button type="button" onclick="selectDuration(6)" id="btnDur6"
                                        class="py-3 px-2 rounded-2xl border-2 flex items-center justify-center text-center transition cursor-pointer border-stone-200 bg-white hover:border-rose-300 text-slate-700 font-semibold text-xs sm:text-sm">
                                    <span>6 เดือน</span>
                                </button>
                                <button type="button" onclick="selectDuration(12)" id="btnDur12"
                                        class="py-3 px-2 rounded-2xl border-2 flex items-center justify-center text-center transition cursor-pointer border-rose-500 bg-rose-50/80 text-rose-900 font-bold text-xs sm:text-sm shadow-xs">
                                    <span>1 ปี (12 เดือน)</span>
                                </button>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label>วันสิ้นสุดสัญญา (End Date) <span class="text-rose-500">*</span></label>
                            <input type="date" name="endDate" id="endDate" required>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label>เงินประกันความเสียหาย (มัดจำ) <span class="text-rose-500">*</span></label>
                            <div class="relative">
                                <input type="number" name="depositAmount" id="depositInput" required step="100" placeholder="0" class="font-bold text-slate-800 !pr-10" oninput="calculateTotalMoveIn()">
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">฿</span>
                            </div>
                        </div>
                        <div>
                            <label>ค่าเช่าล่วงหน้างวดแรก</label>
                            <div class="relative">
                                <input type="number" id="advanceRentInput" readonly value="<?php echo $preSelectedPrice ?: '0'; ?>" class="font-bold text-slate-500 bg-slate-100 cursor-not-allowed !pr-10">
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-slate-400 pointer-events-none">฿</span>
                            </div>
                            <span class="text-[10px] text-slate-400 mt-1 block">ดึงราคาตามห้องพักที่เลือก</span>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100">
                        <label>แนบไฟล์เอกสารสัญญาเช่า (รูปภาพหรือ PDF) <span class="text-rose-500 font-bold">* (จำเป็นต้องแนบ)</span></label>
                        <input type="file" name="leaseImage" id="leaseFileInput" accept="image/*,.pdf" required class="w-full text-sm file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 bg-slate-50 border border-slate-200 rounded-xl mt-1 cursor-pointer">
                        <span class="text-[10px] text-slate-400 mt-1 block">* ไฟล์นี้จะถูกส่งไปแสดงในหน้าระบบของลูกบ้าน เพื่อให้ลูกบ้านสามารถเปิดดูหรือดาวน์โหลดได้ตลอดเวลา</span>
                    </div>

                </div>
            </div>

            <!-- ข้อกำหนดกฎหมาย -->
            <div class="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex items-start gap-3">
                <i data-lucide="scale" class="w-5 h-5 text-amber-600 mt-0.5"></i>
                <div>
                    <h4 class="text-sm font-bold text-amber-900">แจ้งเพื่อทราบตามกฎหมายหอพัก</h4>
                    <p class="text-xs text-amber-800 mt-1">ตามประกาศคณะกรรมการว่าด้วยสัญญา: ห้ามเรียกเก็บเงินประกันและค่าเช่าล่วงหน้ารวมกันเกิน 2 เดือนของอัตราค่าเช่า และผู้เช่ามีสิทธิ์บอกเลิกสัญญาได้โดยต้องแจ้งล่วงหน้าไม่น้อยกว่า 30 วัน</p>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN: Summary Card (4 cols) -->
        <div class="lg:col-span-4 sticky top-24">
            <div class="bg-white rounded-3xl border border-slate-200 shadow-lg shadow-slate-200/50 p-6">
                <h2 class="font-display font-bold text-slate-900 text-lg border-b border-slate-100 pb-4 mb-4 flex items-center gap-2">
                    <i data-lucide="calculator" class="w-5 h-5 text-blue-600"></i> สรุปยอดเงินแรกเข้า
                </h2>
                
                <div class="space-y-4 text-sm mb-6">
                    <div class="flex justify-between items-center text-slate-500">
                        <span>ผู้เช่า:</span>
                        <strong class="text-slate-900 truncate max-w-[150px]" id="sumName">-</strong>
                    </div>
                    <div class="flex justify-between items-center text-slate-500">
                        <span>ห้องพัก:</span>
                        <strong class="text-blue-600 bg-blue-50 px-2 py-0.5 rounded-md" id="sumRoom"><?php echo $preSelectedRoom ? 'ห้อง '.$preSelectedRoom : '-'; ?></strong>
                    </div>
                    <div class="flex justify-between items-center text-slate-500">
                        <span>ระยะเวลา:</span>
                        <strong class="text-slate-900" id="sumDuration">1 ปี</strong>
                    </div>
                </div>

                <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-3 mb-6">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-600">ค่าเช่าล่วงหน้า (1 เดือน)</span>
                        <span class="font-semibold text-slate-900" id="sumAdvance">฿<?php echo number_format((float)$preSelectedPrice); ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm border-b border-slate-200 pb-3">
                        <span class="text-slate-600">เงินประกันความเสียหาย</span>
                        <span class="font-semibold text-slate-900" id="sumDeposit">฿0</span>
                    </div>
                    <div class="flex justify-between items-center pt-1">
                        <span class="font-bold text-slate-900">ยอดรวมที่ต้องชำระ</span>
                        <span class="font-display font-bold text-xl text-blue-600" id="sumTotal">฿<?php echo number_format((float)$preSelectedPrice); ?></span>
                    </div>
                </div>

                <button type="submit" name="create_lease" class="w-full py-3.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white font-bold rounded-2xl shadow-sm shadow-rose-600/25 flex items-center justify-center gap-2 transition-all active:scale-95 cursor-pointer">
                    <i data-lucide="check-circle" class="w-5 h-5"></i> บันทึกข้อมูลและเปิดสัญญา
                </button>
            </div>
        </div>

    </form>

  </main>

  <script>
    lucide.createIcons();

    calculateEndDate();

    function calculateEndDate() {
        let startDate = document.getElementById('startDate').value;
        let duration = document.getElementById('duration').value;
        
        if (startDate && duration !== 'custom') {
            let start = new Date(startDate);
            start.setMonth(start.getMonth() + parseInt(duration));
            start.setDate(start.getDate() - 1);
            
            let yyyy = start.getFullYear();
            let mm = String(start.getMonth() + 1).padStart(2, '0');
            let dd = String(start.getDate()).padStart(2, '0');
            
            document.getElementById('endDate').value = `${yyyy}-${mm}-${dd}`;
            document.getElementById('sumDuration').innerText = duration == '12' ? '1 ปี' : duration + ' เดือน';
        }
    }

    function updateRentSummary(selectElement) {
        if(selectElement.selectedIndex > 0) {
            let selectedOption = selectElement.options[selectElement.selectedIndex];
            let price = selectedOption.getAttribute('data-price');
            let roomText = selectedOption.text.split(' ')[1]; 
            
            document.getElementById('advanceRentInput').value = price;
            document.getElementById('sumRoom').innerText = 'ห้อง ' + roomText;
            
            document.getElementById('depositInput').value = price * 1.5; 
        } else {
            document.getElementById('advanceRentInput').value = 0;
            document.getElementById('depositInput').value = 0;
            document.getElementById('sumRoom').innerText = '-';
        }
        calculateTotalMoveIn();
    }

    function calculateTotalMoveIn() {
        let advanceRaw = parseFloat(document.getElementById('advanceRentInput').value);
        let depositRaw = parseFloat(document.getElementById('depositInput').value);
        
        let advance = isNaN(advanceRaw) ? 0 : advanceRaw;
        let deposit = isNaN(depositRaw) ? 0 : depositRaw;
        let total = advance + deposit;

        document.getElementById('sumAdvance').innerText = '฿' + advance.toLocaleString();
        document.getElementById('sumDeposit').innerText = '฿' + deposit.toLocaleString();
        document.getElementById('sumTotal').innerText = '฿' + total.toLocaleString();
    }

    function validateFullName(input) {
        // ลบตัวเลขและสัญลักษณ์ อนุญาตเฉพาะ ตัวอักษรไทย (\u0E00-\u0E7F), อังกฤษ (a-zA-Z) และช่องว่าง
        input.value = input.value.replace(/[^a-zA-Z\u0E00-\u0E7F\s]/g, '');
        updateSummaryName();
    }

    function validateIdCard(input) {
        // ลบตัวอักษรที่ไม่ใช่ตัวเลข
        input.value = input.value.replace(/[^0-9]/g, '');
        let warn = document.getElementById('idCardWarn');
        if (!warn) return;
        
        if (input.value.length > 0 && input.value.length < 13) {
            warn.innerText = `⚠️ เลขประจำตัวประชาชนต้องครบ 13 หลัก (ปัจจุบันกรอก: ${input.value.length}/13 หลัก)`;
            warn.classList.remove('hidden');
        } else {
            warn.innerText = '';
            warn.classList.add('hidden');
        }
    }

    function validatePhone(input) {
        // ลบตัวอักษรที่ไม่ใช่ตัวเลข
        input.value = input.value.replace(/[^0-9]/g, '');
        let warn = document.getElementById('phoneWarn');
        if (!warn) return;
        
        if (input.value.length > 0 && input.value.length < 10) {
            warn.innerText = `⚠️ เบอร์โทรศัพท์ต้องครบ 10 หลัก (ปัจจุบันกรอก: ${input.value.length}/10 หลัก)`;
            warn.classList.remove('hidden');
        } else {
            warn.innerText = '';
            warn.classList.add('hidden');
        }
    }

    function validateEmail(input) {
        let warn = document.getElementById('emailWarn');
        if (!warn) return;
        
        let val = input.value.trim();
        let emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        
        if (val.length > 0 && !emailRegex.test(val)) {
            warn.innerText = '⚠️ รูปแบบอีเมลไม่ถูกต้อง กรุณากรอกให้สมบูรณ์ (เช่น user@example.com)';
            warn.classList.remove('hidden');
        } else {
            warn.innerText = '';
            warn.classList.add('hidden');
        }
    }

    function validateLeaseForm(e) {
        let nameEl = document.getElementById('fullNameInput');
        let idCardEl = document.getElementById('idCardInput');
        let phoneEl = document.getElementById('phoneInput');
        let emailEl = document.getElementById('emailInput');

        // 1. ตรวจสอบชื่อ-นามสกุล
        if (nameEl && !/^[a-zA-Z\u0E00-\u0E7F\s]+$/.test(nameEl.value.trim())) {
            alert('⚠️ ชื่อ-นามสกุล ต้องเป็นตัวอักษรภาษาไทยหรือภาษาอังกฤษเท่านั้น (ห้ามใส่ตัวเลขหรือสัญลักษณ์พิเศษ)');
            nameEl.focus();
            return false;
        }

        // 2. ตรวจสอบเลขบัตรประชาชน
        if (idCardEl && idCardEl.value.length !== 13) {
            alert(`⚠️ เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลักเท่านั้น (ปัจจุบันกรอก: ${idCardEl.value.length} หลัก)`);
            idCardEl.focus();
            return false;
        }

        // 3. ตรวจสอบเบอร์โทรศัพท์
        if (phoneEl && phoneEl.value.length !== 10) {
            alert(`⚠️ เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลักเท่านั้น (ปัจจุบันกรอก: ${phoneEl.value.length} หลัก)`);
            phoneEl.focus();
            return false;
        }

        // 4. ตรวจสอบอีเมล
        let emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (emailEl && !emailRegex.test(emailEl.value.trim())) {
            alert('⚠️ รูปแบบอีเมลไม่ถูกต้อง กรุณากรอกอีเมลให้สมบูรณ์ (เช่น tenant@example.com)');
            emailEl.focus();
            return false;
        }

        // 4.1 ตรวจสอบเลขมิเตอร์น้ำและไฟฟ้า (ไม่เกิน 4 หลัก 0-9999)
        let waterMeterEl = document.querySelector('input[name="waterMeter"]');
        if (waterMeterEl && (waterMeterEl.value.length > 4 || parseFloat(waterMeterEl.value) > 9999 || parseFloat(waterMeterEl.value) < 0)) {
            alert('⚠️ เลขมิเตอร์น้ำประปาต้องกรอกได้สูงสุดไม่เกิน 4 หลัก (0 - 9999)');
            waterMeterEl.focus();
            return false;
        }

        let electricMeterEl = document.querySelector('input[name="electricMeter"]');
        if (electricMeterEl && (electricMeterEl.value.length > 4 || parseFloat(electricMeterEl.value) > 9999 || parseFloat(electricMeterEl.value) < 0)) {
            alert('⚠️ เลขมิเตอร์ไฟฟ้าต้องกรอกได้สูงสุดไม่เกิน 4 หลัก (0 - 9999)');
            electricMeterEl.focus();
            return false;
        }

        // 5. ตรวจสอบการแนบไฟล์เอกสารสัญญาเช่า (จำเป็นต้องแนบ)
        let leaseFileEl = document.getElementById('leaseFileInput');
        if (!leaseFileEl || !leaseFileEl.files || leaseFileEl.files.length === 0) {
            alert('⚠️ กรุณาแนบไฟล์เอกสารสัญญาเช่า (รูปภาพหรือ PDF) ก่อนทำการบันทึกและเปิดสัญญาเช่า');
            if (leaseFileEl) leaseFileEl.focus();
            return false;
        }

        return true;
    }

    function updateSummaryName() {
        let nameEl = document.getElementById('fullNameInput');
        let sumNameEl = document.getElementById('sumName');
        if(nameEl && sumNameEl) {
            sumNameEl.innerText = nameEl.value.trim() !== "" ? nameEl.value : '-';
        }
    }

    const roomChecklists = <?php echo json_encode($checklistsByRoom, JSON_UNESCAPED_UNICODE); ?>;
    const defaultPresetItems = <?php echo json_encode($defaultItems, JSON_UNESCAPED_UNICODE); ?>;

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function updateRoomChecklist(roomId) {
        const container = document.getElementById('checklistContainer');
        const badge = document.getElementById('checklistCountBadge');
        const actionEl = document.getElementById('checklistHeaderAction');
        const editLink = document.getElementById('editChecklistLink');
        if (!container) return;

        if (!roomId) {
            if (badge) badge.innerText = '0 รายการ';
            if (actionEl) actionEl.classList.add('hidden');
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <i data-lucide="clipboard-list" class="w-10 h-10 mx-auto mb-2 text-slate-300"></i>
                    <p class="text-sm font-medium">กรุณาเลือกห้องพักด้านบนเพื่อแสดงรายละเอียดเช็คลิสต์อุปกรณ์</p>
                </div>`;
            lucide.createIcons();
            return;
        }

        if (actionEl && editLink) {
            actionEl.classList.remove('hidden');
            editLink.href = 'admin_room_checklist.php?roomId=' + roomId;
        }

        const roomSelect = document.getElementById('roomSelect');
        let selectedRoomType = '';
        if (roomSelect && roomSelect.selectedIndex >= 0) {
            const opt = roomSelect.options[roomSelect.selectedIndex];
            selectedRoomType = opt ? (opt.getAttribute('data-type') || '') : '';
        }

        const items = roomChecklists[roomId] || [];
        const isUsingDefault = (items.length === 0);
        const displayItems = isUsingDefault ? defaultPresetItems : items;

        let html = '';
        html += `
        <div class="mb-3 px-4 py-3 bg-gradient-to-r from-blue-50/80 to-indigo-50/60 border border-blue-200/80 rounded-xl text-xs text-blue-900 flex flex-col sm:flex-row sm:items-center justify-between gap-2 shadow-2xs">
            <div class="flex items-center gap-2">
                <i data-lucide="check-square" class="w-4 h-4 text-blue-600 shrink-0"></i>
                <span><b>แอดมินสามารถติ๊กเลือกได้:</b> ติ๊กเครื่องหมายถูกสำหรับสิ่งของที่มีอยู่ในห้องนี้จริง (ระบบจะบันทึกเฉพาะรายการที่ติ๊กไว้)</span>
            </div>
            <span class="text-[11px] text-blue-600 font-semibold shrink-0">* ห้องพัดลมจะเอาติ๊กแอร์ออกให้อัตโนมัติ</span>
        </div>`;

        html += `
        <div class="overflow-x-auto rounded-xl border border-slate-200 shadow-2xs">
            <table class="w-full text-left text-xs sm:text-sm text-slate-600">
                <thead class="bg-slate-50 text-slate-700 font-semibold text-xs border-b border-slate-200">
                    <tr>
                        <th class="p-3 w-28 text-center bg-blue-50/70 border-r border-slate-200">
                            <div class="inline-flex items-center gap-1.5 cursor-pointer select-none" onclick="toggleAllChecklist(event)">
                                <input type="checkbox" id="checkAllItems" checked class="w-4 h-4 text-blue-600 bg-white border-slate-300 rounded focus:ring-blue-500 cursor-pointer">
                                <span class="text-xs font-bold text-blue-900">มีในห้อง</span>
                            </div>
                        </th>
                        <th class="p-3 w-12 text-center">ลำดับ</th>
                        <th class="p-3">รายการอุปกรณ์ / ทรัพย์สิน</th>
                        <th class="p-3 w-28">หมวดหมู่</th>
                        <th class="p-3 w-20 text-center">จำนวน</th>
                        <th class="p-3 w-32 text-center">สถานะในห้อง</th>
                        <th class="p-3">หมายเหตุ</th>
                        <th class="p-3 w-24 text-right">ราคาอ้างอิง</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white" id="checklistTableBody">`;

        displayItems.forEach((item, index) => {
            let catColor = 'bg-slate-100 text-slate-700';
            if (item.category === 'เฟอร์นิเจอร์') catColor = 'bg-amber-100 text-amber-800';
            else if (item.category === 'เครื่องใช้ไฟฟ้า') catColor = 'bg-blue-100 text-blue-800';
            else if (item.category === 'ระบบไฟฟ้า') catColor = 'bg-yellow-100 text-yellow-800';
            else if (item.category === 'ห้องน้ำ') catColor = 'bg-cyan-100 text-cyan-800';
            else if (item.category === 'ระบบความปลอดภัย') catColor = 'bg-purple-100 text-purple-800';

            // ตรวจสอบค่าเริ่มต้น ถ้าเป็นห้องพัดลม (Fan) และเป็นแอร์/ปรับอากาศ ให้ค่าเริ่มต้นเป็น ไม่ติ๊ก (ไม่มีในห้อง)
            let isDefaultChecked = true;
            if (isUsingDefault && selectedRoomType === 'Fan' && (item.itemName.includes('แอร์') || item.itemName.includes('ปรับอากาศ'))) {
                isDefaultChecked = false;
            }

            const estPrice = parseFloat(item.estimatedPrice || 0);

            html += `
                <tr id="cl_row_${index}" class="transition ${isDefaultChecked ? 'hover:bg-slate-50 bg-white' : 'bg-slate-50/70 text-slate-400 opacity-60'}">
                    <td class="p-3 text-center border-r border-slate-100 bg-slate-50/30">
                        <label class="inline-flex items-center justify-center p-1 cursor-pointer">
                            <input type="checkbox" 
                                   name="checklist_items[${index}][is_present]" 
                                   value="1" 
                                   id="cl_check_${index}" 
                                   ${isDefaultChecked ? 'checked' : ''} 
                                   onchange="handleChecklistToggle(${index}, this)"
                                   class="cl-item-checkbox w-5 h-5 text-blue-600 bg-white border-slate-300 rounded focus:ring-blue-500 cursor-pointer transition">
                        </label>
                        <input type="hidden" name="checklist_items[${index}][itemName]" value="${escapeHtml(item.itemName)}">
                        <input type="hidden" name="checklist_items[${index}][category]" value="${escapeHtml(item.category || 'เฟอร์นิเจอร์')}">
                        <input type="hidden" name="checklist_items[${index}][quantity]" value="${item.quantity || 1}">
                        <input type="hidden" name="checklist_items[${index}][estimatedPrice]" value="${item.estimatedPrice || 0}">
                        <input type="hidden" name="checklist_items[${index}][itemStatus]" value="${item.itemStatus || 'Good'}">
                        <input type="hidden" name="checklist_items[${index}][notes]" value="${escapeHtml(item.notes || '')}">
                    </td>
                    <td class="p-3 text-center text-slate-400 font-mono text-xs">${index + 1}</td>
                    <td class="p-3 font-semibold text-slate-900" id="cl_name_${index}" data-raw-name="${escapeHtml(item.itemName)}">
                        ${isDefaultChecked ? escapeHtml(item.itemName) : `<span class="line-through text-slate-400">${escapeHtml(item.itemName)}</span> <span class="text-[10px] text-rose-500 font-bold ml-1">(ไม่มีในห้อง)</span>`}
                    </td>
                    <td class="p-3">
                        <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold ${catColor}">${escapeHtml(item.category || 'ทั่วไป')}</span>
                    </td>
                    <td class="p-3 text-center font-bold text-slate-700">${item.quantity || 1}</td>
                    <td class="p-3 text-center" id="cl_status_${index}">
                        ${isDefaultChecked ? 
                            '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold"><i data-lucide="check" class="w-3.5 h-3.5"></i> มีในห้อง</span>' : 
                            '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-full text-xs font-semibold"><i data-lucide="x" class="w-3.5 h-3.5"></i> ไม่มีในห้อง</span>'}
                    </td>
                    <td class="p-3 text-xs text-slate-500">${escapeHtml(item.notes || '-')}</td>
                    <td class="p-3 text-right font-mono text-xs font-bold text-slate-700">${estPrice > 0 ? '฿' + estPrice.toLocaleString() : '-'}</td>
                </tr>`;
        });

        html += `
                </tbody>
            </table>
        </div>`;

        container.innerHTML = html;
        updateChecklistSummaryCount();
        lucide.createIcons();
    }

    function handleChecklistToggle(index, checkbox) {
        const row = document.getElementById('cl_row_' + index);
        const statusCell = document.getElementById('cl_status_' + index);
        const nameCell = document.getElementById('cl_name_' + index);
        const isChecked = checkbox.checked;

        if (row) {
            if (isChecked) {
                row.className = 'hover:bg-slate-50 transition bg-white';
            } else {
                row.className = 'bg-slate-50/70 text-slate-400 opacity-60 transition';
            }
        }

        if (statusCell) {
            if (isChecked) {
                statusCell.innerHTML = '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 bg-emerald-100 text-emerald-700 rounded-full text-xs font-bold"><i data-lucide="check" class="w-3.5 h-3.5"></i> มีในห้อง</span>';
            } else {
                statusCell.innerHTML = '<span class="inline-flex items-center gap-1 px-2.5 py-0.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-full text-xs font-semibold"><i data-lucide="x" class="w-3.5 h-3.5"></i> ไม่มีในห้อง</span>';
            }
        }

        if (nameCell) {
            const rawName = nameCell.getAttribute('data-raw-name') || '';
            if (isChecked) {
                nameCell.innerHTML = escapeHtml(rawName);
            } else {
                nameCell.innerHTML = `<span class="line-through text-slate-400">${escapeHtml(rawName)}</span> <span class="text-[10px] text-rose-500 font-bold ml-1">(ไม่มีในห้อง)</span>`;
            }
        }

        updateChecklistSummaryCount();
        lucide.createIcons();
    }

    function updateChecklistSummaryCount() {
        const checkboxes = document.querySelectorAll('.cl-item-checkbox');
        const badge = document.getElementById('checklistCountBadge');
        const masterCheck = document.getElementById('checkAllItems');
        if (!checkboxes.length) return;

        let checkedCount = 0;
        checkboxes.forEach(cb => {
            if (cb.checked) checkedCount++;
        });

        if (badge) {
            if (checkedCount === checkboxes.length) {
                badge.innerText = `${checkedCount} รายการ (มีครบทั้งหมด)`;
                badge.className = 'text-xs bg-emerald-100 text-emerald-700 font-bold px-2.5 py-0.5 rounded-full';
            } else {
                badge.innerText = `มีในห้อง ${checkedCount} / ${checkboxes.length} รายการ`;
                badge.className = 'text-xs bg-indigo-100 text-rose-700 font-bold px-2.5 py-0.5 rounded-full';
            }
        }

        if (masterCheck) {
            masterCheck.checked = (checkedCount === checkboxes.length);
            masterCheck.indeterminate = (checkedCount > 0 && checkedCount < checkboxes.length);
        }
    }

    function toggleAllChecklist(event) {
        const masterCheck = document.getElementById('checkAllItems');
        if (event.target !== masterCheck && masterCheck) {
            masterCheck.checked = !masterCheck.checked;
        }
        const state = masterCheck ? masterCheck.checked : true;
        const checkboxes = document.querySelectorAll('.cl-item-checkbox');
        checkboxes.forEach((cb, idx) => {
            cb.checked = state;
            handleChecklistToggle(idx, cb);
        });
    }

    window.onload = function() {
        let roomSelect = document.getElementById('roomSelect');
        if(roomSelect && roomSelect.selectedIndex > 0) {
            let price = roomSelect.options[roomSelect.selectedIndex].getAttribute('data-price');
            if (document.getElementById('depositInput').value === "") {
                document.getElementById('depositInput').value = price * 1.5;
            }
            updateRoomChecklist(roomSelect.value);
        }
        calculateTotalMoveIn();
    };
  
    function selectDuration(dur) {
        const input = document.getElementById('duration');
        if (input) input.value = dur;
        const btn3 = document.getElementById('btnDur3');
        const btn6 = document.getElementById('btnDur6');
        const btn12 = document.getElementById('btnDur12');
        const inactive = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-0.5 text-center transition cursor-pointer border-stone-200 bg-white hover:border-rose-300 text-slate-700 font-semibold';
        if (btn3) btn3.className = inactive;
        if (btn6) btn6.className = inactive;
        if (btn12) btn12.className = inactive;
        const active = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-0.5 text-center transition cursor-pointer border-rose-500 bg-rose-50/80 text-rose-900 font-bold shadow-xs';
        if (dur === 3 && btn3) btn3.className = active;
        if (dur === 6 && btn6) btn6.className = active;
        if (dur === 12 && btn12) btn12.className = active;
        calculateEndDate();
    }

</script>
</body>
</html>