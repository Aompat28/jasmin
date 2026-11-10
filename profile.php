<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบว่าล็อกอินหรือยัง
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'Tenant';

// ดึงข้อมูลเลขห้องพักสำหรับผู้เช่า (Tenant)
$tenantRoomNumber = '';
if ($userRole === 'Tenant') {
    $stmtTRoom = $conn->prepare("
        SELECT r.roomNumber 
        FROM Lease l 
        JOIN Room r ON l.roomId = r.roomId 
        WHERE l.tenantId = ? 
        ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC 
        LIMIT 1
    ");
    $stmtTRoom->execute([$userId]);
    $tenantRoomNumber = $stmtTRoom->fetchColumn() ?: '';
}
$message = "";
$pwSuccess = false;

// 1. จัดการอัปเดตข้อมูลส่วนตัว
if (isset($_POST['update_profile'])) {
    $fullName = trim($_POST['fullName'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (!empty($fullName)) {
        try {
            $stmt = $conn->prepare("UPDATE `User` SET fullName = ?, phone = ?, email = ? WHERE userId = ?");
            $stmt->execute([$fullName, $phone, $email, $userId]);
            
            $_SESSION['full_name'] = $fullName;
            
            $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-2xl shadow-xs text-sm font-bold flex items-center justify-between transition-all duration-300'>
                <div class='flex items-center gap-2.5'>
                    <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>
                    <span>อัปเดตข้อมูลส่วนตัวเรียบร้อยแล้ว!</span>
                </div>
                <button type='button' onclick='this.closest(\".alert-box\").remove()' class='text-emerald-600 hover:text-emerald-800 p-1 cursor-pointer'><i data-lucide='x' class='w-4 h-4'></i></button>
            </div>";
        } catch(PDOException $e) {
            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
                <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>
                <span>เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</span>
            </div>";
        }
    } else {
        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
            <i data-lucide='alert-triangle' class='w-5 h-5 shrink-0'></i>
            <span>กรุณากรอกชื่อ-นามสกุล</span>
        </div>";
    }
}

// 2. จัดการเปลี่ยนรหัสผ่าน
if (isset($_POST['change_password'])) {
    $currentPassword = $_POST['currentPassword'] ?? '';
    $newPassword = $_POST['newPassword'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
            <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>
            <span>กรุณากรอกรหัสผ่านให้ครบทุกช่อง</span>
        </div>";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
            <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>
            <span>รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน กรุณากรอกใหม่อีกครั้ง</span>
        </div>";
    } elseif (mb_strlen($newPassword) < 6) {
        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
            <i data-lucide='alert-triangle' class='w-5 h-5 shrink-0'></i>
            <span>รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 6 ตัวอักษรขึ้นไป</span>
        </div>";
    } else {
        $stmtUser = $conn->prepare("SELECT password FROM `User` WHERE userId = ?");
        $stmtUser->execute([$userId]);
        $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($userData && (password_verify($currentPassword, $userData['password']) || $currentPassword === $userData['password'])) {
            $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtUpdatePass = $conn->prepare("UPDATE `User` SET password = ? WHERE userId = ?");
            $stmtUpdatePass->execute([$hashedNewPassword, $userId]);
            $pwSuccess = true;
            $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
                <i data-lucide='shield-check' class='w-5 h-5 shrink-0'></i>
                <span>ทำการเปลี่ยนรหัสผ่านแล้ว! กำลังนำไปยังหน้าเข้าสู่ระบบ...</span>
            </div>";
        } else {
            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-xs text-sm font-bold flex items-center gap-2'>
                <i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i>
                <span>รหัสผ่านปัจจุบันไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง</span>
            </div>";
        }
    }
}

// ดึงข้อมูลผู้ใช้ปัจจุบันล่าสุด
$stmtCurrent = $conn->prepare("SELECT * FROM `User` WHERE userId = ?");
$stmtCurrent->execute([$userId]);
$user = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

// ดึงข้อมูลห้องพักและสัญญาเช่า (เฉพาะกรณีที่ผู้ใช้งานเป็น Tenant)
$myRoom = null;
if ($userRole === 'Tenant') {
    $stmtRoom = $conn->prepare("
        SELECT l.*, r.roomNumber, r.roomType, r.floor, r.rentPrice 
        FROM Lease l
        JOIN Room r ON l.roomId = r.roomId
        WHERE l.tenantId = ? AND l.status = 'Active'
        LIMIT 1
    ");
    $stmtRoom->execute([$userId]);
    $myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);
}

// กำหนดลิงก์ปุ่มกลับหน้าหลักตาม Role
$backPage = "admin_dashboard.php";
$backPageTitle = "แดชบอร์ดแอดมิน";
if ($userRole === 'Admin') {
    $backPage = "admin_dashboard.php";
    $backPageTitle = "แดชบอร์ดแอดมิน";
} elseif ($userRole === 'Tenant') {
    $backPage = "tenant_dashboard.php";
    $backPageTitle = "หน้าหลักลูกบ้าน";
} elseif (in_array($userRole, ['Staff', 'Technician', 'Cleaner', 'Housekeeper'])) {
    $backPage = "staff_dashboard.php";
    $backPageTitle = "ระบบพนักงาน";
}

$initials = strtoupper(mb_substr($user['fullName'] ?? $user['username'] ?? 'U', 0, 2, 'UTF-8'));
?>

<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
      background-color: #FAF9F6;
      color: #1e293b;
      font-family: 'Prompt', 'Noto Sans Thai', sans-serif;
      min-height: 100vh;
      overflow-x: hidden;
    }
    .glass-card, .boutique-card {
      background: #ffffff;
      border: 1px solid rgba(244, 63, 94, 0.15);
      box-shadow: 0 4px 20px -4px rgba(28, 25, 23, 0.04), 0 2px 6px -2px rgba(28, 25, 23, 0.02);
      border-radius: 1.5rem;
    }
    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(6px); } to { opacity: 1; transform: scale(1) translateY(0); } }
  </style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white pb-16 md:pb-8 bg-[#FAF9F6] text-slate-800">

  <?php 
  if (isset($_SESSION['role']) && $_SESSION['role'] === 'Tenant') {
      include_once 'includes/tenant_sidebar.php';
  } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin') {
      include_once 'includes/admin_sidebar.php';
  }
  ?>

  <!-- Top Navigation Bar -->
  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        
        <!-- Logo & Brand -->
        <div class="flex items-center space-x-2">
          <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
            <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-white hover:bg-slate-100 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
              <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
          <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'Tenant'): ?>
            <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-white hover:bg-slate-100 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
              <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
          <?php endif; ?>
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center text-white shadow-md shrink-0">
            <i data-lucide="user-cog" class="w-5 h-5"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
              <?php if ($userRole === 'Tenant'): ?>
                <a href="profile.php" class="inline-flex items-center gap-1.5 text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold px-2.5 py-0.5 rounded-full border border-rose-200 transition shadow-2xs" title="โปรไฟล์ของฉัน">
                  <span><?php echo htmlspecialchars($user['fullName'] ?? ($headerTenantName ?? 'ผู้เช่า')); ?></span>
                  <?php if(!empty($tenantRoomNumber ?? ($headerRoomNumber ?? ''))): ?>
                    <span class="text-rose-400">•</span>
                    <span class="text-rose-700 font-bold">ห้อง <?php echo htmlspecialchars($tenantRoomNumber ?? ($headerRoomNumber ?? '')); ?></span>
                  <?php endif; ?>
                </a>
              <?php else: ?>
                <span class="text-xs bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200 uppercase tracking-wider">ADMIN</span>
              <?php endif; ?>
            </div>
            <p class="text-[11px] text-slate-400 font-medium">โปรไฟล์ & ตั้งค่าความปลอดภัย</p>
          </div>
        </div>

        <!-- Right Side Nav Actions -->
        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

 

          <!-- ปุ่มออกจากระบบ (สำหรับทุก role รวมทั้ง Tenant) -->
          <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 hover:text-rose-700 border border-rose-200 text-xs font-bold transition shadow-2xs cursor-pointer shrink-0" title="ออกจากระบบ">
            <i data-lucide="log-out" class="w-4 h-4"></i>
            <span class="hidden sm:inline">ออกจากระบบ</span>
          </a>

        </div>
      </div>
    </div>
  </header>

  <!-- Main Container -->
  <main class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
    
    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="user-check" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>บัญชีผู้ใช้งาน</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ความปลอดภัยระดับสูง</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            โปรไฟล์และความปลอดภัย
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            จัดการข้อมูลส่วนตัว เบอร์ติดต่อ ความปลอดภัยของรหัสผ่าน และข้อมูลสัญญาเช่า
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="<?php echo $backPage; ?>" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="arrow-left" class="w-4 h-4 text-slate-500"></i>
            <span>ย้อนกลับ</span>
          </a>
        </div>
      </div>
    </div>
        </div>

        <!-- 🌟 การ์ดข้อมูลห้องพักและสัญญาเช่า (เฉพาะลูกบ้าน Tenant) 🌟 -->
        <?php if($userRole === 'Tenant'): ?>
        <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm p-6 relative overflow-hidden">
          <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <i data-lucide="home" class="w-4 h-4"></i>
              </div>
              <h3 class="font-bold text-white text-sm font-display">ห้องพักของคุณ</h3>
            </div>
            <?php if($myRoom): ?>
              <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">สัญญาเช่า Active</span>
            <?php endif; ?>
          </div>

          <?php if($myRoom): ?>
            <div class="space-y-3.5 text-xs">
              
              <div class="bg-gradient-to-r from-blue-50 to-indigo-50/70 p-4 rounded-2xl border border-blue-100 flex items-center justify-between">
                <div>
                  <div class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">หมายเลขห้อง</div>
                  <div class="text-2xl font-bold font-display text-white mt-0.5">ห้อง <?php echo htmlspecialchars($myRoom['roomNumber']); ?></div>
                  <div class="text-[11px] text-slate-500 mt-0.5"><?php echo htmlspecialchars($myRoom['roomType']); ?> (ชั้น <?php echo htmlspecialchars($myRoom['floor']); ?>)</div>
                </div>
                <div class="text-right">
                  <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">ค่าเช่ารายเดือน</div>
                  <div class="text-lg font-bold font-display text-blue-700 mt-0.5">฿<?php echo number_format($myRoom['rentPrice'], 2); ?></div>
                </div>
              </div>

              <!-- ข้อมูลระยะเวลาสัญญา -->
              <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-100 space-y-1.5">
                <div class="flex items-center justify-between text-slate-500">
                  <span>วันเริ่มสัญญา:</span>
                  <span class="font-mono font-bold text-slate-700"><?php echo date("d/m/Y", strtotime($myRoom['startDate'])); ?></span>
                </div>
                <div class="flex items-center justify-between text-slate-500">
                  <span>วันสิ้นสุดสัญญา:</span>
                  <span class="font-mono font-bold text-rose-600"><?php echo date("d/m/Y", strtotime($myRoom['endDate'])); ?></span>
                </div>
                <?php
                  $daysLeft = ceil((strtotime($myRoom['endDate']) - time()) / 86400);
                ?>
                <div class="pt-2 border-t border-slate-200/60 flex items-center justify-between">
                  <span class="text-slate-400">ระยะเวลาคงเหลือ:</span>
                  <span class="font-bold text-[11px] px-2 py-0.5 rounded-md <?php echo $daysLeft > 30 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'; ?>">
                    <?php echo $daysLeft > 0 ? "เหลือ {$daysLeft} วัน" : "ครบกำหนดสัญญาแล้ว"; ?>
                  </span>
                </div>
              </div>

              <!-- เอกสารสัญญาเช่า -->
              <div>
                <?php if(!empty($myRoom['leaseImage'])): ?>
                  <button type="button" onclick="openDocModal('<?php echo htmlspecialchars($myRoom['leaseImage'], ENT_QUOTES); ?>')" class="w-full py-2.5 px-3 bg-white hover:bg-blue-50 border border-blue-200 text-blue-700 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer">
                    <i data-lucide="file-text" class="w-4 h-4 text-blue-600"></i> ดูรูปภาพเอกสารสัญญาเช่า
                  </button>
                <?php else: ?>
                  <div class="text-center py-2 text-slate-400 text-[11px]">
                    ยังไม่มีรูปภาพเอกสารสัญญาแนบในระบบ
                  </div>
                <?php endif; ?>
              </div>

            </div>
          <?php else: ?>
            <div class="text-center py-6 text-slate-400 text-xs">
              <i data-lucide="folder-x" class="w-8 h-8 mx-auto mb-2 text-slate-600"></i>
              <p>ยังไม่มีข้อมูลสัญญาเช่าที่เปิดใช้งานอยู่</p>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>

      <!-- ================= ฝั่งขวา: ฟอร์มแก้ไขข้อมูลส่วนตัว & ฟอร์มเปลี่ยนรหัสผ่าน (8 Cols) ================= -->
      <div class="lg:col-span-8 space-y-6">
        
        <!-- 🌟 การ์ด 1: ข้อมูลส่วนตัว (Personal Details Form) 🌟 -->
        <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm p-6 sm:p-8">
          <div class="flex items-center gap-3 pb-4 border-b border-slate-100 mb-6">
            <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 shadow-2xs">
              <i data-lucide="user-edit" class="w-5 h-5"></i>
            </div>
            <div>
              <h3 class="font-display font-bold text-white text-base sm:text-lg">ข้อมูลส่วนตัว</h3>
              <p class="text-xs text-slate-400">แก้ไขข้อมูลชื่อ-นามสกุล เบอร์โทรศัพท์ และอีเมลสำหรับการติดต่อ</p>
            </div>
          </div>

          <form action="" method="POST" class="space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
              
              <!-- ชื่อผู้ใช้งาน (Username) - Disabled -->
              <div class="sm:col-span-2">
                <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
                  <i data-lucide="lock" class="w-3.5 h-3.5 text-slate-400"></i> ชื่อบัญชีผู้ใช้งาน (Username)
                </label>
                <div class="relative">
                  <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-100 border border-slate-200 rounded-xl text-slate-500 font-mono cursor-not-allowed select-none">
                  <i data-lucide="at-sign" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                </div>
                <p class="text-[11px] text-slate-400 mt-1">* ชื่อผู้ใช้งานใช้สำหรับเข้าสู่ระบบ ไม่สามารถเปลี่ยนแปลงได้</p>
              </div>

              <!-- ชื่อ - นามสกุล -->
              <div class="sm:col-span-2">
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  ชื่อ - นามสกุล <span class="text-rose-500">*</span>
                </label>
                <div class="relative">
                  <input type="text" name="fullName" value="<?php echo htmlspecialchars($user['fullName']); ?>" required placeholder="กรอกชื่อและนามสกุล" class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50/70 border border-slate-200 rounded-xl outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition">
                  <i data-lucide="user" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                </div>
              </div>

              <!-- เบอร์โทรศัพท์ -->
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  เบอร์โทรศัพท์ <span class="text-rose-500">*</span>
                </label>
                <div class="relative">
                  <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="เช่น 0812345678" class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50/70 border border-slate-200 rounded-xl outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition">
                  <i data-lucide="phone" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                </div>
              </div>

              <!-- อีเมล -->
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">อีเมล (Email)</label>
                <div class="relative">
                  <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="เช่น yourname@email.com" class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50/70 border border-slate-200 rounded-xl outline-none focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 transition">
                  <i data-lucide="mail" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                </div>
              </div>

            </div>

            <!-- Submit Button -->
            <div class="pt-4 border-t border-slate-100 flex items-center justify-end">
              <button type="submit" name="update_profile" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs sm:text-sm font-bold shadow-sm transition flex items-center gap-2 cursor-pointer active:scale-[0.98]">
                <i data-lucide="save" class="w-4 h-4"></i>
                <span>บันทึกข้อมูลส่วนตัว</span>
              </button>
            </div>
          </form>
        </div>

        <!-- 🌟 การ์ด 2: ความปลอดภัยและรหัสผ่าน (Security & Password Form) 🌟 -->
        <div class="bg-white rounded-3xl border border-slate-200/90 shadow-sm p-6 sm:p-8">
          <div class="flex items-center gap-3 pb-4 border-b border-slate-100 mb-6">
            <div class="w-10 h-10 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0 shadow-2xs">
              <i data-lucide="shield-check" class="w-5 h-5"></i>
            </div>
            <div>
              <h3 class="font-display font-bold text-white text-base sm:text-lg">ความปลอดภัยและรหัสผ่าน</h3>
              <p class="text-xs text-slate-400">เปลี่ยนรหัสผ่านเพื่อความปลอดภัยในการเข้าใช้งานบัญชีของคุณ</p>
            </div>
          </div>

          <form action="" method="POST" class="space-y-4">
            
            <!-- รหัสผ่านปัจจุบัน -->
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1.5">
                รหัสผ่านปัจจุบัน <span class="text-rose-500">*</span>
              </label>
              <div class="relative">
                <input type="password" id="curPass" name="currentPassword" required placeholder="กรอกรหัสผ่านเดิมที่ใช้งานอยู่" class="w-full pl-10 pr-10 py-2.5 text-sm bg-slate-50/70 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white focus:ring-2 focus:ring-rose-100 transition">
                <i data-lucide="key" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                <button type="button" onclick="togglePasswordVisibility('curPass', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 transition cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                  <i data-lucide="eye" class="w-4 h-4"></i>
                </button>
              </div>
            </div>

            <!-- รหัสผ่านใหม่ & ยืนยันรหัสผ่านใหม่ (2 Cols) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              
              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  รหัสผ่านใหม่ <span class="text-rose-500">*</span>
                </label>
                <div class="relative">
                  <input type="password" id="newPass" name="newPassword" required minlength="6" placeholder="รหัสผ่านใหม่ (6 ตัวขึ้นไป)" class="w-full pl-10 pr-10 py-2.5 text-sm bg-slate-50/70 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white focus:ring-2 focus:ring-rose-100 transition">
                  <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                  <button type="button" onclick="togglePasswordVisibility('newPass', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 transition cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                    <i data-lucide="eye" class="w-4 h-4"></i>
                  </button>
                </div>
              </div>

              <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">
                  ยืนยันรหัสผ่านใหม่ <span class="text-rose-500">*</span>
                </label>
                <div class="relative">
                  <input type="password" id="cfmPass" name="confirmPassword" required minlength="6" placeholder="กรอกรหัสผ่านใหม่อีกครั้ง" class="w-full pl-10 pr-10 py-2.5 text-sm bg-slate-50/70 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white focus:ring-2 focus:ring-rose-100 transition">
                  <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                  <button type="button" onclick="togglePasswordVisibility('cfmPass', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1 transition cursor-pointer" title="แสดง/ซ่อนรหัสผ่าน">
                    <i data-lucide="eye" class="w-4 h-4"></i>
                  </button>
                </div>
              </div>

            </div>

            <!-- ข้อแนะนำความปลอดภัย -->
            <div class="p-3 bg-slate-50 rounded-xl border border-slate-200/70 flex items-start gap-2 text-[11px] text-slate-500">
              <i data-lucide="info" class="w-4 h-4 text-slate-400 shrink-0 mt-0.5"></i>
              <span>เมื่อเปลี่ยนรหัสผ่านสำเร็จแล้ว ระบบจะแจ้งเตือนและนำคุณออกจากระบบเพื่อให้เข้าสู่ระบบใหม่อีกครั้งด้วยรหัสผ่านใหม่</span>
            </div>

            <!-- Submit Button -->
            <div class="pt-4 border-t border-slate-100 flex items-center justify-end">
              <button type="submit" name="change_password" class="px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs sm:text-sm font-bold shadow-sm transition flex items-center gap-2 cursor-pointer active:scale-[0.98]">
                <i data-lucide="key" class="w-4 h-4"></i>
                <span>เปลี่ยนรหัสผ่านใหม่</span>
              </button>
            </div>

          </form>
        </div>

      </div>

    </div>

  </main>

  <?php include_once 'includes/doc_viewer_modal.php'; ?>

  <!-- Realtime clock script -->
  <script src="js/realtime-time.js"></script>

  <script>
    lucide.createIcons();

    // ฟังก์ชันเปิด/ปิดการมองเห็นรหัสผ่าน (Show / Hide Password)
    function togglePasswordVisibility(inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
        btn.innerHTML = isPassword ? '<i data-lucide="eye-off" class="w-4 h-4 text-slate-600"></i>' : '<i data-lucide="eye" class="w-4 h-4 text-slate-400"></i>';
        if (window.lucide) { lucide.createIcons(); }
    }

    // ซ่อน Alert อัตโนมัติเมื่อเวลาผ่านไป 4 วินาที
    document.addEventListener("DOMContentLoaded", function() {
        const alerts = document.querySelectorAll(".alert-box");
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.opacity = "0";
                alert.style.transform = "translateY(-6px)";
                setTimeout(function() { alert.remove(); }, 400);
            }, 4500);
        });
    });
  </script>

  <?php if ($pwSuccess): ?>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
        Swal.fire({
            title: '🎉 ทำการเปลี่ยนรหัสผ่านแล้ว!',
            text: 'เปลี่ยนรหัสผ่านสำเร็จเรียบร้อย กรุณาเข้าสู่ระบบอีกครั้งด้วยรหัสผ่านใหม่ของคุณ',
            icon: 'success',
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'ตกลง เข้าสู่ระบบใหม่',
            allowOutsideClick: false,
            customClass: {
                popup: 'rounded-3xl shadow-2xl font-sans border border-slate-100',
                confirmButton: 'rounded-xl px-5 py-2.5 font-semibold text-sm shadow-md'
            }
        }).then(() => {
            window.location.href = 'logout.php?msg=pwd_changed';
        });
    });
  </script>
  <?php endif; ?>

</body>
</html>
