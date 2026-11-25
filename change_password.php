<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Tenant เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'Tenant';
$userName = $_SESSION['full_name'] ?? 'ผู้เช่า';
$initials = strtoupper(mb_substr($userName, 0, 2, 'UTF-8'));
$myRoomNumber = '';
if ($userRole === 'Tenant') {
    $stmtR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");
    $stmtR->execute([$userId]);
    $myRoomNumber = $stmtR->fetchColumn() ?: '';
}
$message = "";

if (isset($_POST['change_password'])) {
    $oldPassword = $_POST['old_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    if ($newPassword !== $confirmPassword) {
        $message = "<div class='p-4 mb-4 bg-rose-50 text-rose-700 rounded-xl text-sm font-bold'>❌ รหัสผ่านใหม่ไม่ตรงกัน กรุณากรอกใหม่อีกครั้ง</div>";
    } else {
        // ดึงรหัสผ่านปัจจุบันจากฐานข้อมูลมาตรวจสอบ
        $stmt = $conn->prepare("SELECT password FROM User WHERE userId = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // เช็ครหัสผ่านเก่า (รองรับทั้งแบบ Hash และแบบข้อความธรรมดา)
        if ($user && (password_verify($oldPassword, $user['password']) || $oldPassword === $user['password'])) {
            
            // เข้ารหัสรหัสผ่านใหม่
            $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // อัปเดตรหัสผ่านลง Database
            $updateStmt = $conn->prepare("UPDATE User SET password = ? WHERE userId = ?");
            $updateStmt->execute([$newHashedPassword, $userId]);

            $message = "<div class='p-4 mb-4 bg-emerald-50 text-emerald-700 rounded-xl text-sm font-bold'>✅ เปลี่ยนรหัสผ่านสำเร็จ! สามารถใช้รหัสผ่านใหม่ในการเข้าสู่ระบบครั้งต่อไปได้ทันที</div>";
        } else {
            $message = "<div class='p-4 mb-4 bg-rose-50 text-rose-700 rounded-xl text-sm font-bold'>❌ รหัสผ่านเดิมไม่ถูกต้อง</div>";
        }
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
  <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } } }</script>
  <style>body { background-color: #FAF9F6; color: #1e293b; }</style>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-blue-600 selection:text-white bg-[#FAF9F6] text-slate-800">

  <!-- 🌟 แท็บด้านซ้ายมือ สำหรับผู้เช่าพักอาศัย (Tenant Sidebar) 🌟 -->
  <?php include_once 'includes/tenant_sidebar.php'; ?>

  <!-- Header -->
  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer mr-1" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center text-white shadow-md">
            <i data-lucide="key-round" class="w-5 h-5"></i>
          </div>
          <div>
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">เปลี่ยนรหัสผ่าน</p>
          </div>
        </div>
        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>
          <a href="<?php echo ($userRole === 'Tenant') ? 'tenant_dashboard.php' : 'profile.php'; ?>" class="text-xs sm:text-sm font-semibold text-slate-700 hover:text-blue-600 flex items-center gap-1.5 transition bg-slate-100 hover:bg-slate-200 px-3.5 py-2 rounded-xl">
             <i data-lucide="arrow-left" class="w-4 h-4"></i> <span class="hidden sm:inline">กลับหน้าหลัก</span>
          </a>

          <?php if ($userRole === 'Tenant'): ?>
            <div class="w-px h-6 bg-slate-200"></div>
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
          <?php endif; ?>
          <div class="w-px h-6 bg-slate-200"></div>

          <!-- ปุ่มออกจากระบบ -->
          <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 hover:text-rose-700 border border-rose-200 text-xs font-bold transition shadow-2xs cursor-pointer shrink-0" title="ออกจากระบบ">
            <i data-lucide="log-out" class="w-4 h-4"></i>
            <span class="hidden sm:inline">ออกจากระบบ</span>
          </a>

        </div>
      </div>
    </div>
  </header>

  <main class="flex-1 max-w-md w-full mx-auto px-4 py-10">
      <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6">
      <div class="flex items-center gap-2 flex-wrap mb-2">
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
          <i data-lucide="lock" class="w-3.5 h-3.5 text-rose-500"></i>
          <span>ความปลอดภัย</span>
        </span>
      </div>
      <h1 class="text-2xl font-display font-extrabold text-slate-900 tracking-tight">
        ตั้งรหัสผ่านใหม่
      </h1>
      <p class="text-xs text-slate-500 mt-1">
        เพื่อความปลอดภัยของบัญชี กรุณาตั้งรหัสผ่านที่มีความรัดกุม
      </p>
    </div>

      <?php echo $message; ?>

      <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-6 sm:p-8">
          <form action="" method="POST" class="space-y-4">
              <div>
                  <label class="block text-xs font-semibold text-slate-700 mb-1.5">รหัสผ่านเดิม</label>
                  <input type="password" name="old_password" required placeholder="รหัสผ่านปัจจุบัน (หรือเบอร์โทร)" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-blue-500 transition">
              </div>

              <div>
                  <label class="block text-xs font-semibold text-slate-700 mb-1.5">รหัสผ่านใหม่</label>
                  <input type="password" name="new_password" required placeholder="อย่างน้อย 6 ตัวอักษรขึ้นไป" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-blue-500 transition">
              </div>

              <div>
                  <label class="block text-xs font-semibold text-slate-700 mb-1.5">ยืนยันรหัสผ่านใหม่</label>
                  <input type="password" name="confirm_password" required placeholder="กรอกรหัสผ่านใหม่อีกครั้ง" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-blue-500 transition">
              </div>

              <div class="pt-4">
                  <button type="submit" name="change_password" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl shadow-md transition flex items-center justify-center gap-2">
                      <i data-lucide="check" class="w-4 h-4"></i> บันทึกรหัสผ่านใหม่
                  </button>
              </div>
          </form>
      </div>
  </main>

  <script>lucide.createIcons();</script>
</body>
</html>