<?php
session_start();
require_once 'includes/db_connect.php';

// ตรวจสอบสิทธิ์ ต้องเป็น Admin เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$message = "";

// --- 1. ระบบเพิ่มพนักงานใหม่ ---
if (isset($_POST['add_staff'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $fullName = trim($_POST['fullName']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role']; // Admin, Technician, Cleaner

    try {
        $stmt = $conn->prepare("INSERT INTO User (username, password, fullName, phone, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $password, $fullName, $phone, $role]);
        $message = "<div class='bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>✅ เพิ่มพนักงานใหม่สำเร็จ!</p></div>";
    } catch(PDOException $e) {
        if ($e->getCode() == 23000) {
            $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>❌ เพิ่มไม่สำเร็จ</p><p class='text-sm'>ชื่อผู้ใช้งาน (Username) นี้มีคนใช้แล้ว กรุณาตั้งใหม่</p></div>";
        } else {
            $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>❌ เกิดข้อผิดพลาด</p><p class='text-sm'>" . $e->getMessage() . "</p></div>";
        }
    }
}

// --- 2. ระบบลบพนักงาน ---
if (isset($_POST['delete_staff'])) {
    $deleteId = $_POST['deleteId'];
    
    // ป้องกันไม่ให้แอดมินลบตัวเองที่กำลังล็อกอินอยู่
    if ($deleteId == $_SESSION['user_id']) {
        $message = "<div class='bg-amber-50 border-l-4 border-amber-500 text-amber-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>⚠️ ไม่สามารถลบได้</p><p class='text-sm'>คุณไม่สามารถลบบัญชีที่คุณกำลังใช้งานอยู่ได้</p></div>";
    } else {
        try {
            $stmt = $conn->prepare("DELETE FROM User WHERE userId = ?");
            $stmt->execute([$deleteId]);
            $message = "<div class='bg-slate-50 border-l-4 border-slate-500 text-slate-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>🗑️ ลบพนักงานออกจากระบบสำเร็จ</p></div>";
        } catch(PDOException $e) {
            $message = "<div class='bg-rose-50 border-l-4 border-rose-500 text-rose-700 p-4 mb-6 rounded-md shadow-sm'><p class='font-bold'>❌ ลบไม่สำเร็จ</p><p class='text-sm'>พนักงานรายนี้อาจมีข้อมูลผูกกับประวัติการทำงานในระบบ</p></div>";
        }
    }
}

// ดึงข้อมูลพนักงานทั้งหมด (ไม่ดึง Tenant มาโชว์ในหน้านี้)
$staffs = $conn->query("SELECT * FROM User WHERE role IN ('Admin', 'Technician', 'Cleaner') ORDER BY role ASC, fullName ASC")->fetchAll(PDO::FETCH_ASSOC);

// นับจำนวนพนักงานแต่ละแผนก
$countAdmin = 0; $countTech = 0; $countCleaner = 0;
foreach($staffs as $s) {
    if($s['role'] == 'Admin') $countAdmin++;
    if($s['role'] == 'Technician') $countTech++;
    if($s['role'] == 'Cleaner') $countCleaner++;
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
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } } }
  </script>
  <style>body { background-color: #FAF9F6; color: #1e293b; }</style>
  <?php include_once 'includes/swal.php'; ?>
  <?php include_once 'includes/theme_head.php'; ?>
</head>
<body class="min-h-screen flex flex-col antialiased selection:bg-blue-600 selection:text-white bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

  <!-- Top Navigation (Admin) -->
  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-16">
        <div class="flex items-center space-x-3">
          <button type="button" onclick="toggleAdminSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer" title="เปิด/ปิด แถบเมนูด้านซ้าย">
            <i data-lucide="menu" class="w-5 h-5"></i>
          </button>
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white shadow-md">
            <i data-lucide="users-round" class="w-5 h-5"></i>
          </div>
          <div class="flex items-center space-x-2">
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <span class="text-xs bg-slate-100 text-slate-700 font-semibold px-2 py-0.5 rounded-full border border-slate-200 uppercase">ADMIN</span>
          </div>
        </div>

        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

          <a href="logout.php" onclick="return confirm('ออกจากระบบ?');" class="text-slate-400 hover:text-rose-500 transition p-1.5 rounded-lg hover:bg-rose-50" title="ออกจากระบบ">
              <i data-lucide="log-out" class="w-5 h-5"></i>
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
              <i data-lucide="users" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ฝ่ายปฏิบัติการ & เจ้าหน้าที่</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>จัดการรายชื่อบุคลากร</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ระบบจัดการผู้ใช้งาน (พนักงาน)
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            เพิ่ม/ลบ บัญชีสำหรับ แอดมิน, ช่างซ่อม และแม่บ้านประจำอาคาร
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="admin_requests.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="arrow-left" class="w-4 h-4 text-slate-500"></i>
            <span>งานแจ้งซ่อม</span>
          </a>
          <button type="button" onclick="document.getElementById('addStaffModal').classList.remove('hidden')" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 shadow-sm shadow-rose-600/25 active:scale-95 cursor-pointer">
            <i data-lucide="user-plus" class="w-4 h-4"></i>
            <span>เพิ่มพนักงานใหม่</span>
          </button>
        </div>
      </div>
    </div>

    <?php echo $message; ?>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center"><i data-lucide="shield" class="w-6 h-6"></i></div>
            <div><p class="text-xs text-slate-500 font-medium mb-1">แอดมิน (Admin)</p><p class="text-2xl font-bold text-slate-800"><?php echo $countAdmin; ?> <span class="text-sm font-normal text-slate-400">คน</span></p></div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center"><i data-lucide="wrench" class="w-6 h-6"></i></div>
            <div><p class="text-xs text-slate-500 font-medium mb-1">ช่างซ่อม (Technician)</p><p class="text-2xl font-bold text-blue-600"><?php echo $countTech; ?> <span class="text-sm font-normal text-slate-400">คน</span></p></div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center"><i data-lucide="sparkles" class="w-6 h-6"></i></div>
            <div><p class="text-xs text-slate-500 font-medium mb-1">แม่บ้าน (Cleaner)</p><p class="text-2xl font-bold text-emerald-600"><?php echo $countCleaner; ?> <span class="text-sm font-normal text-slate-400">คน</span></p></div>
        </div>
    </div>

    <!-- Staff Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-600 whitespace-nowrap">
                <thead class="bg-slate-50 text-slate-700 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="p-4 w-12 text-center">ID</th>
                        <th class="p-4">ชื่อ - นามสกุล</th>
                        <th class="p-4">ตำแหน่ง (Role)</th>
                        <th class="p-4">ชื่อผู้ใช้งาน (Username)</th>
                        <th class="p-4">เบอร์โทรศัพท์</th>
                        <th class="p-4 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach($staffs as $s): ?>
                    <tr class="hover:bg-slate-50/50 transition">
                        <td class="p-4 text-center text-slate-400 font-mono text-xs">#<?php echo $s['userId']; ?></td>
                        <td class="p-4 font-semibold text-slate-800"><?php echo htmlspecialchars($s['fullName']); ?></td>
                        <td class="p-4">
                            <?php if($s['role'] == 'Admin'): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-50 text-purple-700 border border-purple-200 rounded-md text-xs font-bold"><i data-lucide="shield" class="w-3 h-3"></i> ผู้ดูแลระบบ</span>
                            <?php elseif($s['role'] == 'Technician'): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-blue-50 text-blue-700 border border-blue-200 rounded-md text-xs font-bold"><i data-lucide="wrench" class="w-3 h-3"></i> ช่างซ่อม</span>
                            <?php elseif($s['role'] == 'Cleaner'): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-md text-xs font-bold"><i data-lucide="sparkles" class="w-3 h-3"></i> แม่บ้าน</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 text-slate-500"><i data-lucide="user" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i><?php echo htmlspecialchars($s['username']); ?></td>
                        <td class="p-4 text-slate-500"><i data-lucide="phone" class="w-3.5 h-3.5 inline mr-1 text-slate-400"></i><?php echo htmlspecialchars($s['phone'] ?? '-'); ?></td>
                        <td class="p-4 text-center">
                            <form action="" method="POST" onsubmit="return confirm('แน่ใจหรือไม่ที่จะลบบัญชี <?php echo htmlspecialchars($s['fullName']); ?> ออกจากระบบ?');">
                                <input type="hidden" name="deleteId" value="<?php echo $s['userId']; ?>">
                                <?php if($s['userId'] == $_SESSION['user_id']): ?>
                                    <button type="button" disabled class="px-3 py-1.5 bg-slate-100 text-slate-400 text-xs font-bold rounded-lg cursor-not-allowed">ใช้งานอยู่</button>
                                <?php else: ?>
                                    <button type="submit" name="delete_staff" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 text-xs font-bold rounded-lg flex items-center justify-center gap-1.5 shadow-sm transition mx-auto">
                                        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ลบผู้ใช้
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
  </main>

  <!-- MODAL: Add New Staff -->
  <div id="addStaffModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-2xl w-full p-8 shadow-2xl border border-slate-100">
      <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-5">
        <h3 class="font-display font-bold text-slate-800 text-lg flex items-center gap-2">
            <i data-lucide="user-plus" class="w-5 h-5 text-blue-600"></i> เพิ่มพนักงานใหม่
        </h3>
        <button onclick="document.getElementById('addStaffModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 p-1 bg-slate-100 hover:bg-slate-200 rounded-full transition">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form action="" method="POST" class="space-y-4">
        <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
            <input type="text" name="fullName" required placeholder="ชื่อจริง นามสกุล" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 transition">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">ตำแหน่ง (Role) <span class="text-rose-500">*</span></label>
                <select name="role" required class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 transition">
                    <option value="Technician">ช่างซ่อม (Technician)</option>
                    <option value="Cleaner">แม่บ้าน (Cleaner)</option>
                    <option value="Admin">แอดมิน (Admin)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">เบอร์โทรศัพท์</label>
                <input type="tel" name="phone" placeholder="08xxxxxxxx" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 transition">
            </div>
        </div>
        <div class="pt-2 border-t border-slate-100">
            <h4 class="text-[11px] font-bold text-slate-500 mb-3">ข้อมูลสำหรับการเข้าสู่ระบบ (Login Info)</h4>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Username <span class="text-rose-500">*</span></label>
                    <input type="text" name="username" required placeholder="ตั้งชื่อผู้ใช้งาน (ห้ามซ้ำ)" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1.5">Password <span class="text-rose-500">*</span></label>
                    <input type="text" name="password" required placeholder="ตั้งรหัสผ่านเบื้องต้น" class="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 transition">
                </div>
            </div>
        </div>
        <div class="pt-4 mt-2">
            <button type="submit" name="add_staff" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl shadow-md transition flex justify-center items-center gap-2">
                <i data-lucide="check" class="w-4 h-4"></i> บันทึกข้อมูลพนักงาน
            </button>
        </div>
      </form>
    </div>
  </div>

  <script>lucide.createIcons();</script>
</body>
</html>