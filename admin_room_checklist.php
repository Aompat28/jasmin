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
$message = "";

// 🌟 1. สร้างตาราง RoomChecklist อัตโนมัติ (ถ้ายังไม่มี)
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

// อัปเดตราคาอ้างอิงพื้นฐานสำหรับรายการเดิมใน DB (กำหนดเพดานไม่เกิน 500 บาททุกรายการ)
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

// รับ roomId จาก URL
$roomId = isset($_GET['roomId']) ? intval($_GET['roomId']) : 0;

// ดึงข้อมูลห้องพักและผู้เช่าปัจจุบัน (ถ้ามี)
$stmtRoom = $conn->prepare("
    SELECT r.*, 
           u.fullName AS tenantName, u.phone AS tenantPhone,
           l.startDate, l.endDate, l.leaseId
    FROM Room r
    LEFT JOIN Lease l ON r.roomId = l.roomId AND l.status = 'Active'
    LEFT JOIN User u ON l.tenantId = u.userId
    WHERE r.roomId = ?
    LIMIT 1
");
$stmtRoom->execute([$roomId]);
$room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    header("Location: manage_room.php");
    exit();
}

// --- 2. ระบบโหลดรายการอุปกรณ์มาตรฐาน (Default Preset กำหนดเพดานไม่เกิน 500 บาท) ---
if (isset($_POST['load_default_preset'])) {
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

    try {
        $stmtAdd = $conn->prepare("INSERT INTO RoomChecklist (roomId, itemName, category, quantity, estimatedPrice, itemStatus, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($defaultItems as $item) {
            $stmtAdd->execute([$roomId, $item['itemName'], $item['category'], $item['quantity'], $item['estimatedPrice'], $item['itemStatus'], $item['notes']]);
        }
        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> โหลดชุดเช็คลิสต์อุปกรณ์มาตรฐาน 10 รายการ พร้อมราคาอ้างอิงเรียบร้อยแล้ว!</div>";
    } catch(PDOException $e) {
        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// --- 3. ระบบเพิ่มรายการอุปกรณ์ใหม่ ---
if (isset($_POST['add_item'])) {
    $itemName = trim($_POST['itemName']);
    $category = $_POST['category'];
    $quantity = intval($_POST['quantity']);
    $estimatedPrice = floatval($_POST['estimatedPrice'] ?? 0);
    $itemStatus = $_POST['itemStatus'];
    $notes = trim($_POST['notes']);

    if (!empty($itemName)) {
        try {
            $stmtInsert = $conn->prepare("INSERT INTO RoomChecklist (roomId, itemName, category, quantity, estimatedPrice, itemStatus, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$roomId, $itemName, $category, $quantity, $estimatedPrice, $itemStatus, $notes]);
            $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> เพิ่มอุปกรณ์ '{$itemName}' (ราคาอ้างอิง ฿" . number_format($estimatedPrice) . ") เรียบร้อยแล้ว</div>";
        } catch(PDOException $e) {
            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// --- 4. อัปเดตสถานะอุปกรณ์และราคาอ้างอิง ---
if (isset($_POST['update_status'])) {
    $checklistId = intval($_POST['checklist_id']);
    $newStatus = $_POST['new_status'];
    $notes = trim($_POST['notes']);
    $estimatedPrice = floatval($_POST['estimatedPrice'] ?? 0);

    try {
        $stmtUpdate = $conn->prepare("UPDATE RoomChecklist SET itemStatus = ?, notes = ?, estimatedPrice = ? WHERE checklistId = ? AND roomId = ?");
        $stmtUpdate->execute([$newStatus, $notes, $estimatedPrice, $checklistId, $roomId]);
        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600'></i> อัปเดตสภาพอุปกรณ์และราคาอ้างอิงเรียบร้อยแล้ว</div>";
    } catch(PDOException $e) {
        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// --- 5. ลบรายการอุปกรณ์ ---
if (isset($_POST['delete_item'])) {
    $checklistId = intval($_POST['checklist_id']);
    try {
        $stmtDel = $conn->prepare("DELETE FROM RoomChecklist WHERE checklistId = ? AND roomId = ?");
        $stmtDel->execute([$checklistId, $roomId]);
        $message = "<div class='alert-box p-4 mb-6 bg-slate-50 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ลบรายการอุปกรณ์ออกเรียบร้อยแล้ว</div>";
    } catch(PDOException $e) {
        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-xs text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// ดึงเช็คลิสต์ทั้งหมดในห้องนี้
$stmtChecklist = $conn->prepare("SELECT * FROM RoomChecklist WHERE roomId = ? ORDER BY FIELD(category, 'เฟอร์นิเจอร์', 'เครื่องใช้ไฟฟ้า', 'ระบบไฟฟ้า', 'ห้องน้ำ', 'ระบบความปลอดภัย'), checklistId ASC");
$stmtChecklist->execute([$roomId]);
$checklist = $stmtChecklist->fetchAll(PDO::FETCH_ASSOC);

// สรุปสถิติสถานะอุปกรณ์และมูลค่าทรัพย์สินรวม
$totalItems = count($checklist);
$totalAssetValue = 0;
$countGood = 0;
$countDamaged = 0;
$countRepairing = 0;
$countMissing = 0;
foreach($checklist as $c) {
    $totalAssetValue += (floatval($c['estimatedPrice'] ?? 0) * intval($c['quantity'] ?? 1));
    if ($c['itemStatus'] === 'Good') $countGood++;
    elseif ($c['itemStatus'] === 'Damaged') $countDamaged++;
    elseif ($c['itemStatus'] === 'Repairing') $countRepairing++;
    elseif ($c['itemStatus'] === 'Missing') $countMissing++;
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
      theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } }
    }
  </script>
  <style>
    body { background-color: #f8fafc; color: #1e293b; }
    @media print {
      body { background-color: #ffffff !important; color: #000000 !important; }
      .no-print { display: none !important; }
      .print-only { display: block !important; }
      .print-card { border: 1px solid #cbd5e1 !important; shadow: none !important; }
    }
    .print-only { display: none; }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-blue-600 selection:text-white">

  <?php include_once 'includes/admin_sidebar.php'; ?>

  <!-- Top Navigation (Admin) -->
  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200 shadow-sm no-print">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-2">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <a href="manage_room.php" class="p-2 text-slate-500 hover:text-blue-600 rounded-xl hover:bg-slate-100 transition">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
          </a>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
              <span class="text-xs bg-slate-100 text-slate-700 font-semibold px-2 py-0.5 rounded-full border border-slate-200">ADMIN</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium">ระบบบันทึกเช็คลิสต์และตรวจเช็คอุปกรณ์ภายในห้องพัก</p>
          </div>
        </div>
        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>
           <button onclick="window.print()" class="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm">
             <i data-lucide="printer" class="w-4 h-4"></i> พิมพ์ใบตรวจห้อง
           </button>
           <a href="manage_room.php" class="text-xs font-semibold text-blue-600 hover:underline">
              กลับหน้าห้องพัก
           </a>
        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
    
    <!-- Room Info Card Header -->
    <div class="bg-gradient-to-r from-slate-900 to-indigo-900 rounded-3xl p-6 text-white shadow-md mb-6 relative overflow-hidden">
      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-2">
              <span class="px-3 py-1 bg-blue-500 text-white font-extrabold text-sm rounded-xl shadow-xs">
                ห้อง <?php echo htmlspecialchars($room['roomNumber']); ?>
              </span>
            <span class="px-3 py-1 bg-white/10 text-white text-xs font-semibold rounded-full border border-white/20">
              <?php echo ($room['roomType'] === 'Air') ? '❄️ ห้องแอร์' : '🌀 ห้องพัดลม'; ?> (ชั้น <?php echo $room['floor']; ?>)
            </span>
            <span class="px-3 py-1 bg-emerald-500/20 text-emerald-300 text-xs font-semibold rounded-full border border-emerald-500/30">
              ฿<?php echo number_format($room['rentPrice']); ?>/เดือน
            </span>
          </div>
          <h1 class="text-xl sm:text-2xl font-display font-bold">📋 เช็คลิสต์อุปกรณ์ & ทรัพย์สินภายในห้องพัก</h1>
          <?php if(!empty($room['tenantName'])): ?>
            <p class="text-xs text-blue-200 mt-1 flex items-center gap-2">
              <span>👤 ผู้เช่าปัจจุบัน: <strong><?php echo htmlspecialchars($room['tenantName']); ?></strong></span>
              <span>📞 <?php echo htmlspecialchars($room['tenantPhone'] ?? '-'); ?></span>
              <?php if(!empty($room['startDate'])): ?>
                <span>• เข้าพักเมื่อ: <?php echo date("d/m/Y", strtotime($room['startDate'])); ?></span>
              <?php endif; ?>
            </p>
          <?php else: ?>
            <p class="text-xs text-amber-300 mt-1">🏠 สถานะปัจจุบัน: ห้องว่าง (Available)</p>
          <?php endif; ?>
        </div>

        <div class="flex items-center gap-2 no-print">
          <?php if(empty($checklist)): ?>
            <form action="" method="POST">
              <button type="submit" name="load_default_preset" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-bold shadow-md transition flex items-center gap-2 cursor-pointer">
                <i data-lucide="sparkles" class="w-4 h-4"></i> โหลดชุดรายการมาตรฐาน 10 อุปกรณ์
              </button>
            </form>
          <?php endif; ?>
          <button onclick="document.getElementById('addItemModal').classList.remove('hidden')" class="px-4 py-2.5 bg-white text-slate-800 hover:bg-slate-100 rounded-xl text-xs font-bold shadow-md transition flex items-center gap-2 cursor-pointer">
            <i data-lucide="plus-circle" class="w-4 h-4 text-blue-600"></i> + เพิ่มอุปกรณ์ใหม่
          </button>
        </div>
      </div>
    </div>

    <!-- Alert Box -->
    <div id="alert-container" class="no-print">
      <?php echo $message; ?>
    </div>

    <!-- Summary Metrics -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3.5 mb-6">
      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold text-slate-500 mb-0.5">อุปกรณ์ทั้งหมด</p>
          <h3 class="text-2xl font-bold font-display text-slate-800"><?php echo $totalItems; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></h3>
        </div>
        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center"><i data-lucide="box" class="w-5 h-5"></i></div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-indigo-200 bg-indigo-50/20 shadow-xs flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold text-indigo-800 mb-0.5">มูลค่าทรัพย์สินรวม</p>
          <h3 class="text-xl font-bold font-display text-indigo-700 font-mono">฿<?php echo number_format($totalAssetValue); ?></h3>
        </div>
        <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center"><i data-lucide="coins" class="w-5 h-5"></i></div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-emerald-200 bg-emerald-50/20 shadow-xs flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold text-emerald-800 mb-0.5">สภาพปกติ (Good)</p>
          <h3 class="text-2xl font-bold font-display text-emerald-600"><?php echo $countGood; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></h3>
        </div>
        <div class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><i data-lucide="check-circle" class="w-5 h-5"></i></div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-rose-200 bg-rose-50/20 shadow-xs flex items-center justify-between">
        <div>
          <p class="text-xs font-semibold text-rose-800 mb-0.5">ชำรุด (Damaged)</p>
          <h3 class="text-2xl font-bold font-display text-rose-600"><?php echo $countDamaged; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></h3>
        </div>
        <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center"><i data-lucide="alert-triangle" class="w-5 h-5"></i></div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-amber-200 bg-amber-50/20 shadow-xs flex items-center justify-between col-span-2 sm:col-span-1">
        <div>
          <p class="text-xs font-semibold text-amber-800 mb-0.5">รอซ่อม/สูญหาย</p>
          <h3 class="text-2xl font-bold font-display text-amber-600"><?php echo ($countRepairing + $countMissing); ?> <span class="text-xs font-normal text-slate-400">รายการ</span></h3>
        </div>
        <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center"><i data-lucide="wrench" class="w-5 h-5"></i></div>
      </div>
    </div>

    <!-- Table Card -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden print-card">
      <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
        <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
          <i data-lucide="clipboard-list" class="w-5 h-5 text-blue-600"></i> รายการอุปกรณ์และสภาพทรัพย์สินในห้องพัก
        </h3>
        <span class="text-xs text-slate-400 font-medium">รวมทั้งหมด <?php echo count($checklist); ?> รายการ</span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-600">
          <thead class="bg-slate-50 text-slate-700 font-semibold border-b border-slate-200 text-xs uppercase">
            <tr>
              <th class="p-4">หมวดหมู่ & ชื่ออุปกรณ์</th>
              <th class="p-4 text-center">จำนวน</th>
              <th class="p-4 text-right">ราคาอ้างอิง (บาท)</th>
              <th class="p-4">สถานะอุปกรณ์</th>
              <th class="p-4">หมายเหตุ / สภาพทรัพย์สิน</th>
              <th class="p-4 text-center no-print">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if(empty($checklist)): ?>
              <tr>
                <td colspan="6" class="p-12 text-center text-slate-400">
                  <div class="max-w-xs mx-auto text-center space-y-3">
                    <i data-lucide="clipboard-x" class="w-12 h-12 text-slate-300 mx-auto"></i>
                    <p class="font-bold text-slate-700 text-sm">ยังไม่มีรายการเช็คลิสต์อุปกรณ์ในห้องนี้</p>
                    <p class="text-xs text-slate-400">กดปุ่ม "โหลดชุดรายการมาตรฐาน 10 อุปกรณ์" เพื่อดึงรายการเฟอร์นิเจอร์และเครื่องใช้ไฟฟ้าเบื้องต้นเข้ามาทันที</p>
                    <form action="" method="POST" class="pt-2">
                      <button type="submit" name="load_default_preset" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md transition inline-flex items-center gap-2">
                        <i data-lucide="sparkles" class="w-4 h-4"></i> โหลดรายการมาตรฐานทันที
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach($checklist as $item): 
                $st = $item['itemStatus'];
                $statusBadge = "bg-emerald-50 text-emerald-700 border-emerald-200";
                $statusLabel = "🟢 ปกติ (Good)";
                if ($st === 'Damaged') { $statusBadge = "bg-rose-50 text-rose-700 border-rose-200"; $statusLabel = "🔴 ชำรุด (Damaged)"; }
                elseif ($st === 'Repairing') { $statusBadge = "bg-amber-50 text-amber-700 border-amber-200"; $statusLabel = "🟡 รอซ่อมบำรุง (Repairing)"; }
                elseif ($st === 'Missing') { $statusBadge = "bg-slate-100 text-slate-700 border-slate-300"; $statusLabel = "⚪ สูญหาย (Missing)"; }
              ?>
              <tr class="hover:bg-slate-50/80 transition">
                <!-- ชื่ออุปกรณ์ -->
                <td class="p-4">
                  <span class="inline-block text-[10px] font-bold px-2 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200 mb-1">
                    <?php echo htmlspecialchars($item['category']); ?>
                  </span>
                  <div class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($item['itemName']); ?></div>
                </td>

                <!-- จำนวน -->
                <td class="p-4 text-center">
                  <span class="px-2.5 py-1 bg-slate-100 text-slate-800 rounded-lg font-bold font-mono text-xs border border-slate-200">
                    <?php echo $item['quantity']; ?>
                  </span>
                </td>

                <!-- ราคาอ้างอิง -->
                <td class="p-4 text-right font-mono">
                  <div class="font-bold text-blue-700 text-sm">฿<?php echo number_format($item['estimatedPrice'], 2); ?></div>
                  <?php if($item['quantity'] > 1): ?>
                    <div class="text-[10px] text-slate-400">รวม ฿<?php echo number_format($item['estimatedPrice'] * $item['quantity'], 2); ?></div>
                  <?php endif; ?>
                </td>

                <!-- สถานะอุปกรณ์ -->
                <td class="p-4">
                  <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border <?php echo $statusBadge; ?>">
                    <?php echo $statusLabel; ?>
                  </span>
                </td>

                <!-- หมายเหตุ -->
                <td class="p-4 text-xs text-slate-600">
                  <?php if(!empty($item['notes'])): ?>
                    <span class="bg-slate-50 px-2.5 py-1 rounded-lg border border-slate-200 inline-block font-sans text-slate-700">
                      <?php echo htmlspecialchars($item['notes']); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-slate-300 italic">-</span>
                  <?php endif; ?>
                  <div class="text-[10px] text-slate-400 mt-1 font-mono">อัปเดต: <?php echo date("d/m/Y H:i", strtotime($item['updated_at'])); ?></div>
                </td>

                <!-- การจัดการ (no-print) -->
                <td class="p-4 text-center no-print">
                  <div class="flex items-center justify-center gap-1.5">
                    <!-- ปุ่มแก้ไขสถานะ -->
                    <button type="button" onclick="openUpdateModal(<?php echo $item['checklistId']; ?>, '<?php echo htmlspecialchars(addslashes($item['itemName'])); ?>', '<?php echo $item['itemStatus']; ?>', '<?php echo htmlspecialchars(addslashes($item['notes'] ?? '')); ?>', <?php echo $item['estimatedPrice']; ?>)" class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg text-xs font-bold transition flex items-center gap-1">
                      <i data-lucide="edit-3" class="w-3.5 h-3.5"></i> แก้ไขสภาพ
                    </button>

                    <!-- ปุ่มลบ -->
                    <form action="" method="POST" class="m-0" data-confirm="ลบรายการอุปกรณ์นี้ออก?">
                      <input type="hidden" name="checklist_id" value="<?php echo $item['checklistId']; ?>">
                      <button type="submit" name="delete_item" class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition" title="ลบรายการ">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                      </button>
                    </form>
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

  <!-- MODAL: เพิ่มอุปกรณ์ใหม่ -->
  <div id="addItemModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 modal-enter">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
        <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
          <i data-lucide="plus-circle" class="w-5 h-5 text-blue-600"></i> เพิ่มอุปกรณ์ในห้องพัก
        </h3>
        <button onclick="document.getElementById('addItemModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form action="" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">ชื่ออุปกรณ์ / ทรัพย์สิน <span class="text-rose-500">*</span></label>
          <input type="text" name="itemName" required placeholder="เช่น ทีวี 32 นิ้ว, ไมโครเวฟ, โซฟา 2 ที่นั่ง" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">หมวดหมู่</label>
          <select name="category" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 font-medium">
            <option value="เฟอร์นิเจอร์">🪑 เฟอร์นิเจอร์</option>
            <option value="เครื่องใช้ไฟฟ้า">🔌 เครื่องใช้ไฟฟ้า</option>
            <option value="ระบบไฟฟ้า">💡 ระบบไฟฟ้า</option>
            <option value="ห้องน้ำ">🚿 ห้องน้ำ & สุขภัณฑ์</option>
            <option value="ระบบความปลอดภัย">🔑 ประตู & ความปลอดภัย</option>
            <option value="อื่นๆ">📦 อื่นๆ</option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">จำนวน</label>
            <input type="number" name="quantity" value="1" min="1" required class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 font-bold">
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">ราคาอ้างอิง/ชิ้น (บาท)</label>
            <input type="number" name="estimatedPrice" value="0" step="50" min="0" placeholder="เช่น 3500" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 font-bold text-blue-700">
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">สถานะเริ่มต้น</label>
          <select name="itemStatus" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 font-bold">
            <option value="Good">🟢 ปกติ (Good)</option>
            <option value="Damaged">🔴 ชำรุด (Damaged)</option>
            <option value="Repairing">🟡 รอซ่อมบำรุง (Repairing)</option>
            <option value="Missing">⚪ สูญหาย (Missing)</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">หมายเหตุ / บันทึกสภาพทรัพย์สิน</label>
          <textarea name="notes" rows="2" placeholder="เช่น มีรอยขีดข่วนเล็กน้อย, พร้อมใช้งาน" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500"></textarea>
        </div>

        <div class="flex gap-2 pt-2">
          <button type="button" onclick="document.getElementById('addItemModal').classList.add('hidden')" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
            ยกเลิก
          </button>
          <button type="submit" name="add_item" class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition">
            บันทึกอุปกรณ์
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- MODAL: แก้ไขสถานะอุปกรณ์ -->
  <div id="updateModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 modal-enter">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
        <h3 class="font-display font-bold text-slate-800 text-base flex items-center gap-2">
          <i data-lucide="edit-3" class="w-5 h-5 text-blue-600"></i> อัปเดตสภาพอุปกรณ์ & ราคาอ้างอิง
        </h3>
        <button onclick="closeUpdateModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form action="" method="POST" class="space-y-4">
        <input type="hidden" name="checklist_id" id="updateChecklistId">
        
        <p class="text-xs text-slate-600">
          อุปกรณ์: <strong id="updateItemName" class="text-slate-900"></strong>
        </p>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">สถานะอุปกรณ์ปัจจุบัน <span class="text-rose-500">*</span></label>
            <select name="new_status" id="updateNewStatus" required class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 font-bold">
              <option value="Good">🟢 ปกติ (Good)</option>
              <option value="Damaged">🔴 ชำรุด (Damaged)</option>
              <option value="Repairing">🟡 รอซ่อมบำรุง (Repairing)</option>
              <option value="Missing">⚪ สูญหาย (Missing)</option>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">ราคาอ้างอิง/ชิ้น (บาท)</label>
            <input type="number" name="estimatedPrice" id="updateEstimatedPrice" value="0" step="50" min="0" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 font-bold text-blue-700">
          </div>
        </div>

        <div>
          <label class="block text-xs font-semibold text-slate-700 mb-1.5">หมายเหตุ / บันทึกสภาพความเสียหาย</label>
          <textarea name="notes" id="updateNotes" rows="3" placeholder="ระบุรายละเอียด เช่น ขาโต๊ะหัก, ขอยื่นซ่อมแซม..." class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500"></textarea>
        </div>

        <div class="flex gap-2 pt-2">
          <button type="button" onclick="closeUpdateModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">
            ยกเลิก
          </button>
          <button type="submit" name="update_status" class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition">
            บันทึกการอัปเดต
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    lucide.createIcons();

    function openUpdateModal(id, name, status, notes, price) {
      document.getElementById('updateChecklistId').value = id;
      document.getElementById('updateItemName').innerText = name;
      document.getElementById('updateNewStatus').value = status;
      document.getElementById('updateNotes').value = notes;
      document.getElementById('updateEstimatedPrice').value = price || 0;
      document.getElementById('updateModal').classList.remove('hidden');
    }

    function closeUpdateModal() {
      document.getElementById('updateModal').classList.add('hidden');
    }
  </script>
</body>
</html>
