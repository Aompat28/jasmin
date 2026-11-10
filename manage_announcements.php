<?php
// manage_announcements.php
// ระบบจัดการข่าวสารและประกาศ (Admin Announcements Management)
// สไตล์: Warm & Cozy Boutique (อบอุ่น มินิมอล ระดับมืออาชีพ)

session_start();
require_once 'includes/db_connect.php';
require_once 'includes/mailer.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit();
}

$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$initials = strtoupper(mb_substr($adminName, 0, 2, 'UTF-8'));
$message = "";

// สร้างคอลัมน์ type ถ้ายังไม่มี
try {
    $conn->exec("ALTER TABLE Announcements ADD COLUMN message TEXT NULL");
} catch(Throwable $e) {}
try {
    $conn->exec("ALTER TABLE Announcements ADD COLUMN content TEXT NULL");
} catch(Throwable $e) {}
try {
    $conn->exec("ALTER TABLE Announcements ADD COLUMN type VARCHAR(50) DEFAULT 'info'");
} catch(Throwable $e) {}

// 1. เมื่อแอดมินกดโพสต์ข่าวสารใหม่
if (isset($_POST['post_announcement'])) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $type = $_POST['type'] ?? 'info';

    if (!empty($title) && !empty($content)) {
        try {
            $stmt = $conn->prepare("INSERT INTO Announcements (title, content, message, type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $content, $content, $type]);
            $message = "<div class='p-4 mb-6 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold flex items-center gap-2'>
                <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>
                <span>โพสต์ข่าวสารประกาศเรียบร้อยแล้ว! ข้อมูลส่งตรงถึงลูกบ้านทุกคนทันที</span>
            </div>";

            // Best-effort: แจ้งลูกบ้านทั้งหมดที่มีอีเมล
            try {
                $tenants = $conn->query("
                    SELECT fullName, email FROM User
                    WHERE role = 'Tenant' AND email IS NOT NULL AND email != ''
                ")->fetchAll(PDO::FETCH_ASSOC);

                $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
                $safeContent = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
                foreach ($tenants as $tenant) {
                    $safeName = htmlspecialchars($tenant['fullName'] ?? '', ENT_QUOTES, 'UTF-8');
                    sendMail(
                        $tenant['email'],
                        "[หอพักจัสมิน] ประกาศใหม่: {$title}",
                        "<h2>สวัสดีคุณ {$safeName}</h2>
                        <p>มีประกาศใหม่จากนิติบุคคลหอพักจัสมิน</p>
                        <h3>{$safeTitle}</h3>
                        <div>{$safeContent}</div>
                        <p>คุณสามารถเข้าสู่ระบบเพื่อดูรายละเอียดประกาศได้ทันที</p>"
                    );
                }
            } catch(Throwable $e) {}
        } catch(Throwable $e) {
            $message = "<div class='p-4 mb-6 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-sm font-semibold flex items-center gap-2'>
                <i data-lucide='alert-circle' class='w-5 h-5 text-rose-600 shrink-0'></i>
                <span>เกิดข้อผิดพลาดในการบันทึก: " . htmlspecialchars($e->getMessage()) . "</span>
            </div>";
        }
    } else {
        $message = "<div class='p-4 mb-6 rounded-2xl bg-amber-50 border border-amber-200 text-amber-800 text-sm font-semibold flex items-center gap-2'>
            <i data-lucide='alert-triangle' class='w-5 h-5 text-amber-600 shrink-0'></i>
            <span>กรุณากรอกหัวข้อและเนื้อหาประกาศให้ครบถ้วน</span>
        </div>";
    }
}

// 2. เมื่อแอดมินกดลบข่าวสาร
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmtDel = $conn->prepare("DELETE FROM Announcements WHERE announcementId = ?");
        $stmtDel->execute([$id]);
        header("Location: manage_announcements.php");
        exit();
    } catch(Throwable $e) {}
}

// ดึงรายการข่าวสารทั้งหมด เรียงจากใหม่ไปเก่า
$announcements = [];
try {
    $announcements = $conn->query("SELECT * FROM Announcements ORDER BY announcementId DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>jasmin</title>
  <script src="https://cdn.tailwindcss.com">
    function selectAnnouncementType(type) {
        const input = document.getElementById('announcementTypeInput');
        if (input) input.value = type;

        const btnInfo = document.getElementById('btnAnnInfo');
        const btnWarn = document.getElementById('btnAnnWarn');
        const btnSucc = document.getElementById('btnAnnSucc');

        const inactiveCls = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white text-stone-700 font-semibold';
        if (btnInfo) btnInfo.className = inactiveCls;
        if (btnWarn) btnWarn.className = inactiveCls;
        if (btnSucc) btnSucc.className = inactiveCls;

        if (type === 'info' && btnInfo) {
            btnInfo.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs';
        } else if (type === 'warning' && btnWarn) {
            btnWarn.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-amber-500 bg-amber-50/80 text-amber-950 font-bold shadow-xs';
        } else if (type === 'success' && btnSucc) {
            btnSucc.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-emerald-500 bg-emerald-50/80 text-emerald-950 font-bold shadow-xs';
        }
    }

</script>
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest">
    function selectAnnouncementType(type) {
        const input = document.getElementById('announcementTypeInput');
        if (input) input.value = type;

        const btnInfo = document.getElementById('btnAnnInfo');
        const btnWarn = document.getElementById('btnAnnWarn');
        const btnSucc = document.getElementById('btnAnnSucc');

        const inactiveCls = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white text-stone-700 font-semibold';
        if (btnInfo) btnInfo.className = inactiveCls;
        if (btnWarn) btnWarn.className = inactiveCls;
        if (btnSucc) btnSucc.className = inactiveCls;

        if (type === 'info' && btnInfo) {
            btnInfo.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs';
        } else if (type === 'warning' && btnWarn) {
            btnWarn.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-amber-500 bg-amber-50/80 text-amber-950 font-bold shadow-xs';
        } else if (type === 'success' && btnSucc) {
            btnSucc.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-emerald-500 bg-emerald-50/80 text-emerald-950 font-bold shadow-xs';
        }
    }

</script>
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
  
    function selectAnnouncementType(type) {
        const input = document.getElementById('announcementTypeInput');
        if (input) input.value = type;

        const btnInfo = document.getElementById('btnAnnInfo');
        const btnWarn = document.getElementById('btnAnnWarn');
        const btnSucc = document.getElementById('btnAnnSucc');

        const inactiveCls = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white text-stone-700 font-semibold';
        if (btnInfo) btnInfo.className = inactiveCls;
        if (btnWarn) btnWarn.className = inactiveCls;
        if (btnSucc) btnSucc.className = inactiveCls;

        if (type === 'info' && btnInfo) {
            btnInfo.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs';
        } else if (type === 'warning' && btnWarn) {
            btnWarn.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-amber-500 bg-amber-50/80 text-amber-950 font-bold shadow-xs';
        } else if (type === 'success' && btnSucc) {
            btnSucc.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-emerald-500 bg-emerald-50/80 text-emerald-950 font-bold shadow-xs';
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
<body class="min-h-screen flex flex-col antialiased selection:bg-indigo-600 selection:text-white pb-16 md:pb-8 bg-[#FAF9F6] text-slate-800">

  <?php include_once 'includes/admin_sidebar.php'; ?>

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
            <i data-lucide="megaphone" class="w-5 h-5 text-amber-300"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>
              <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200 uppercase tracking-wider">ADMIN</span>
            </div>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">จัดการข่าวสารและประกาศถึงลูกบ้าน</p>
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

  <!-- Main Content -->
  <main class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="megaphone" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ข่าวสารประชาสัมพันธ์</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="bell" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ทั้งหมด <?php echo count($announcements); ?> ประกาศ</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            จัดการข่าวสารและประกาศ
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            เผยแพร่ข่าวสาร แจ้งเตือนสำคัญ และประกาศทั่วไปให้ผู้เช่าทุกคนทราบผ่านแดชบอร์ด
          </p>
        </div>

        <!-- Quick Top Action Buttons -->
        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="admin_dashboard.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="layout-dashboard" class="w-4 h-4 text-slate-500"></i>
            <span>แดชบอร์ดหลัก</span>
          </a>
        </div>
      </div>
    </div>

    <?php echo $message; ?>

    <!-- ฟอร์มสร้างประกาศใหม่ (Warm & Cozy Boutique Card) -->
    <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-6 sm:p-7">
      <div class="flex items-center gap-2 pb-4 border-b border-stone-100 mb-5">
        <div class="w-9 h-9 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
          <i data-lucide="plus-circle" class="w-5 h-5"></i>
        </div>
        <div>
          <h2 class="font-display font-bold text-slate-900 text-base">เขียนประกาศข่าวสารใหม่</h2>
          <p class="text-xs text-stone-400">ส่งตรงไปยังหน้าแรกของผู้เช่าทันที</p>
        </div>
      </div>

      <form action="manage_announcements.php" method="POST" class="space-y-4">
        <div>
          <label class="block text-xs font-bold text-stone-700 mb-1.5">หัวข้อประกาศ <span class="text-rose-500">*</span></label>
          <input type="text" name="title" required placeholder="เช่น แจ้งกำหนดการทำความสะอาดพื้นที่ส่วนกลาง และล้างแท้งค์น้ำ" class="w-full px-4 py-2.5 text-sm bg-stone-50 border border-stone-200 rounded-2xl outline-none focus:border-rose-500 focus:bg-white transition text-slate-800 placeholder-slate-400">
        </div>

        <div>
          <label class="block text-xs font-bold text-stone-700 mb-1.5">เนื้อหาประกาศ <span class="text-rose-500">*</span></label>
          <textarea name="content" rows="4" required placeholder="ระบุรายละเอียด วันที่ เวลา หรือข้อควรปฏิบัติสำหรับผู้เช่า..." class="w-full px-4 py-2.5 text-sm bg-stone-50 border border-stone-200 rounded-2xl outline-none focus:border-rose-500 focus:bg-white transition text-slate-800 placeholder-slate-400 resize-none leading-relaxed"></textarea>
        </div>

        <div>
          <label class="block text-xs font-bold text-stone-700 mb-1.5">ระดับความสำคัญ</label>
          <input type="hidden" name="type" id="announcementTypeInput" value="info">
          <div class="grid grid-cols-3 gap-2" id="announcementTypePills">
            <button type="button" onclick="selectAnnouncementType('info')" id="btnAnnInfo"
                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs">
              <span class="text-base">🔵</span>
              <span class="text-xs">ทั่วไป</span>
            </button>
            <button type="button" onclick="selectAnnouncementType('warning')" id="btnAnnWarn"
                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white hover:border-amber-400 text-stone-700 font-semibold">
              <span class="text-base">🟠</span>
              <span class="text-xs">แจ้งเตือน</span>
            </button>
            <button type="button" onclick="selectAnnouncementType('success')" id="btnAnnSucc"
                    class="p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white hover:border-emerald-400 text-stone-700 font-semibold">
              <span class="text-base">🟢</span>
              <span class="text-xs">ข่าวดี</span>
            </button>
          </div>
        </div>

        <div class="pt-3 border-t border-stone-100 flex items-center justify-end">
          <button type="submit" name="post_announcement" class="px-6 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white text-xs sm:text-sm font-bold rounded-2xl shadow-sm shadow-rose-600/25 transition flex items-center gap-2 cursor-pointer active:scale-95">
            <i data-lucide="send" class="w-4 h-4"></i>
            <span>เผยแพร่ประกาศทันที</span>
          </button>
        </div>
      </form>
    </div>

    <!-- รายการประกาศทั้งหมด -->
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="font-display font-bold text-slate-900 text-lg flex items-center gap-2">
          <i data-lucide="list" class="w-5 h-5 text-stone-500"></i>
          <span>รายการประกาศทั้งหมดในระบบ</span>
        </h3>
      </div>

      <?php if(count($announcements) > 0): ?>
        <div class="space-y-3.5">
          <?php foreach($announcements as $news): ?>
            <?php
              $annType = $news['type'] ?? 'info';
              $badgeStyle = 'bg-sky-50 text-sky-700 border-sky-200';
              $badgeText = 'ทั่วไป';
              if ($annType === 'warning') {
                $badgeStyle = 'bg-amber-50 text-amber-700 border-amber-200';
                $badgeText = 'แจ้งเตือนสำคัญ';
              } elseif ($annType === 'success') {
                $badgeStyle = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                $badgeText = 'ข่าวดี';
              }
              $dateDisplay = $news['created_at'] ?? ($news['createdAt'] ?? date('Y-m-d H:i:s'));
              $bodyText = $news['message'] ?? ($news['content'] ?? '');
            ?>
            <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-5 sm:p-6 transition hover:border-stone-300 relative group">
              <div class="flex items-start justify-between gap-3 mb-2">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold border <?php echo $badgeStyle; ?>">
                    <?php echo $badgeText; ?>
                  </span>
                  <h4 class="font-bold text-slate-900 text-base"><?php echo htmlspecialchars($news['title']); ?></h4>
                </div>

                <a href="manage_announcements.php?delete=<?php echo $news['announcementId']; ?>"
                   onclick="return confirm('ยืนยันลบประกาศนี้หรือไม่?');"
                   class="p-2 text-stone-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition shrink-0"
                   title="ลบประกาศ">
                  <i data-lucide="trash-2" class="w-4 h-4"></i>
                </a>
              </div>

              <p class="text-stone-600 text-sm leading-relaxed whitespace-pre-line mt-2"><?php echo htmlspecialchars($bodyText); ?></p>

              <div class="flex items-center justify-between text-xs text-stone-400 mt-4 pt-3 border-t border-stone-100">
                <span class="flex items-center gap-1.5 font-mono">
                  <i data-lucide="clock" class="w-3.5 h-3.5 text-stone-400"></i>
                  <?php echo date("d/m/Y H:i", strtotime($dateDisplay)); ?> น.
                </span>
                <span class="text-indigo-600 font-medium text-[11px]">แสดงบนแดชบอร์ดลูกบ้านทุกคน</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="bg-white rounded-3xl border border-stone-200/80 shadow-sm p-12 text-center text-slate-400">
          <i data-lucide="message-square-dashed" class="w-12 h-12 mx-auto mb-3 text-stone-300"></i>
          <h4 class="font-bold text-stone-700 text-sm">ยังไม่มีประกาศข่าวสารในขณะนี้</h4>
          <p class="text-xs text-stone-400 mt-1">เริ่มต้นสื่อสารกับลูกบ้านโดยการสร้างประกาศแรกด้านบน</p>
        </div>
      <?php endif; ?>
    </div>

  </main>

  <script>
    lucide.createIcons();
  
    function selectAnnouncementType(type) {
        const input = document.getElementById('announcementTypeInput');
        if (input) input.value = type;

        const btnInfo = document.getElementById('btnAnnInfo');
        const btnWarn = document.getElementById('btnAnnWarn');
        const btnSucc = document.getElementById('btnAnnSucc');

        const inactiveCls = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-stone-200 bg-white text-stone-700 font-semibold';
        if (btnInfo) btnInfo.className = inactiveCls;
        if (btnWarn) btnWarn.className = inactiveCls;
        if (btnSucc) btnSucc.className = inactiveCls;

        if (type === 'info' && btnInfo) {
            btnInfo.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-sky-500 bg-sky-50/80 text-sky-950 font-bold shadow-xs';
        } else if (type === 'warning' && btnWarn) {
            btnWarn.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-amber-500 bg-amber-50/80 text-amber-950 font-bold shadow-xs';
        } else if (type === 'success' && btnSucc) {
            btnSucc.className = 'p-2.5 rounded-2xl border-2 flex flex-col items-center justify-center gap-1 text-center transition cursor-pointer border-emerald-500 bg-emerald-50/80 text-emerald-950 font-bold shadow-xs';
        }
    }

</script>
</body>
</html>
