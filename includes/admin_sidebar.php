<?php
// includes/admin_sidebar.php
// แถบเมนูด้านซ้ายศูนย์ควบคุมและการจัดการ สำหรับผู้ดูแลระบบ (Admin Left Sidebar)
// สไตล์: Warm & Cozy Boutique (อบอุ่น มินิมอล โทนเทา-น้ำเงิน คลีน สวยงามระดับมืออาชีพ)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// แสดงเฉพาะผู้ใช้ที่มีสิทธิ์ Admin เท่านั้น
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    return;
}

$sidebarAdminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';
$sidebarInitials = strtoupper(mb_substr($sidebarAdminName, 0, 2, 'UTF-8'));

// 1. ดึงข้อมูลจำนวนงานค้างและสถิติสำหรับป้ายแจ้งเตือน (Badges) แบบเรียลไทม์
$sidebarSlipCount = 0;
$sidebarPendingServices = 0;
$sidebarPendingRenewals = 0;
$sidebarPendingCoresidents = 0;
$sidebarPendingRoomChanges = 0;
$sidebarPendingMoveOuts = 0;
$sidebarTotalRooms = 0;

if (isset($conn) && $conn instanceof PDO) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM Invoices WHERE slipImage IS NOT NULL AND (status = 'Unpaid' OR status = 'Pending')");
        if ($stmt) $sidebarSlipCount = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM ServiceRequest WHERE status = 'Pending'");
        if ($stmt) $sidebarPendingServices = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM LeaseRenewals WHERE status = 'Pending'");
        if ($stmt) $sidebarPendingRenewals = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM coresidents WHERE status = 'Pending'");
        if ($stmt) $sidebarPendingCoresidents = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM RoomChangeRequests WHERE status = 'Pending'");
        if ($stmt) $sidebarPendingRoomChanges = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM Lease WHERE status = 'Active' AND moveOutRequestDate IS NOT NULL");
        if ($stmt) $sidebarPendingMoveOuts = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM Room");
        if ($stmt) $sidebarTotalRooms = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}
}

$sidebarPendingRoomActions = $sidebarPendingRenewals + $sidebarPendingRoomChanges + $sidebarPendingMoveOuts;

// 2. ตรวจสอบหน้าปัจจุบันเพื่อทำการไฮไลท์กรอบเมนูที่เลือก (Active Item)
$currentScript = basename($_SERVER['PHP_SELF']);
$queryString = $_SERVER['QUERY_STRING'] ?? '';

$activeKey = '';
if ($currentScript === 'admin_dashboard.php') {
    $activeKey = 'dashboard';
} elseif (in_array($currentScript, ['manage_room.php', 'admin_renewals.php', 'admin_room_changes.php', 'move_out_form.php', 'check_in_form.php', 'admin_room_checklist.php'])) {
    $activeKey = 'room';
} elseif ($currentScript === 'manage_invoice.php') {
    $activeKey = 'invoice';
} elseif ($currentScript === 'admin_requests.php' || $currentScript === 'manage_staff.php') {
    $activeKey = 'requests';
} elseif ($currentScript === 'admin_coresidents.php') {
    $activeKey = 'coresidents';
} elseif ($currentScript === 'admin_reports.php') {
    $activeKey = 'reports';
} elseif ($currentScript === 'manage_announcements.php') {
    $activeKey = 'announcements';
} elseif ($currentScript === 'profile.php') {
    $activeKey = 'profile';
}

// 3. รายการเมนูทั้งหมด (Warm & Cozy Boutique Menu Items)
$sidebarMenuItems = [
    [
        'key' => 'dashboard',
        'title' => 'แดชบอร์ดภาพรวม',
        'subtitle' => 'สรุปข้อมูลหอพัก',
        'url' => 'admin_dashboard.php',
        'icon' => 'layout-dashboard',
        'iconBg' => 'bg-rose-50 text-rose-600 border border-rose-200/60',
        'badge' => 0,
        'badgeColor' => ''
    ],
    [
        'key' => 'room',
        'title' => 'ห้องพัก & สัญญาเช่า',
        'subtitle' => ($sidebarPendingRoomActions > 0 ? "รอจัดการ {$sidebarPendingRoomActions} รายการ" : ($sidebarTotalRooms > 0 ? "{$sidebarTotalRooms} ห้องพัก" : 'จัดการห้องพัก')),
        'url' => 'manage_room.php',
        'icon' => 'door-closed',
        'iconBg' => 'bg-pink-50 text-pink-600 border border-pink-200/60',
        'badge' => $sidebarPendingRoomActions,
        'badgeColor' => 'bg-rose-500 text-white'
    ],
    [
        'key' => 'invoice',
        'title' => 'การเงิน & ใบแจ้งหนี้',
        'subtitle' => ($sidebarSlipCount > 0 ? "รอตรวจ {$sidebarSlipCount} สลิป" : 'ออกบิลและตรวจสลิป'),
        'url' => 'manage_invoice.php',
        'icon' => 'receipt',
        'iconBg' => 'bg-emerald-50 text-emerald-700 border border-emerald-200/50',
        'badge' => $sidebarSlipCount,
        'badgeColor' => 'bg-amber-500 text-white'
    ],
    [
        'key' => 'requests',
        'title' => 'แจ้งซ่อม & งานบริการ',
        'subtitle' => ($sidebarPendingServices > 0 ? "รอดำเนินการ {$sidebarPendingServices} งาน" : 'มอบหมายงานช่าง'),
        'url' => 'admin_requests.php',
        'icon' => 'wrench',
        'iconBg' => 'bg-amber-50 text-amber-700 border border-amber-200/50',
        'badge' => $sidebarPendingServices,
        'badgeColor' => 'bg-rose-500 text-white'
    ],
    [
        'key' => 'announcements',
        'title' => 'ข่าวสาร & ประกาศ',
        'subtitle' => 'แจ้งประชาสัมพันธ์ลูกบ้าน',
        'url' => 'manage_announcements.php',
        'icon' => 'megaphone',
        'iconBg' => 'bg-amber-50 text-amber-700 border border-amber-200/50',
        'badge' => 0,
        'badgeColor' => ''
    ],
    [
        'key' => 'coresidents',
        'title' => 'ผู้พักอาศัยร่วม',
        'subtitle' => ($sidebarPendingCoresidents > 0 ? "รอ {$sidebarPendingCoresidents} คำขอ" : 'จัดการสมาชิกห้อง'),
        'url' => 'admin_coresidents.php',
        'icon' => 'users',
        'iconBg' => 'bg-purple-50 text-purple-700 border border-purple-200/50',
        'badge' => $sidebarPendingCoresidents,
        'badgeColor' => 'bg-purple-600 text-white'
    ],
    [
        'key' => 'reports',
        'title' => 'รายงาน & สถิติ',
        'subtitle' => 'วิเคราะห์ผลประกอบการ',
        'url' => 'admin_reports.php',
        'icon' => 'bar-chart-3',
        'iconBg' => 'bg-rose-50/60 text-rose-700 border border-rose-200/50',
        'badge' => 0,
        'badgeColor' => ''
    ]
];
?>

<!-- 🌟 CSS Styles: Warm & Cozy Boutique Sidebar 🌟 -->
<style id="admin-sidebar-style">
  #admin-sidebar {
    width: 17rem !important; /* กว้าง 272px กำลังพอดี สบายตา */
    transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    transform: translateX(-100%); /* ค่าเริ่มต้น: ปิดสนิท */
  }

  html, body {
    overflow-x: clip; /* ป้องกันทั้งหน้าเว็บเลื่อนซ้าย-ขวาโดยไม่ตั้งใจ */
  }

  /* Transition นุ่มนวลสำหรับพื้นที่เนื้อหาและ Header */
  main, header > div, .max-w-7xl, .max-w-6xl {
    transition: max-width 0.28s cubic-bezier(0.16, 1, 0.3, 1), padding 0.28s cubic-bezier(0.16, 1, 0.3, 1), width 0.28s cubic-bezier(0.16, 1, 0.3, 1);
  }

  /* กรณีเปิดสุด (Desktop): เนื้อหาหน้าเว็บเลื่อนเว้นที่ 17rem */
  @media (min-width: 1024px) {
    body.admin-sidebar-open {
      padding-left: 17rem !important;
      transition: padding-left 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    }
    body.admin-sidebar-open #admin-sidebar {
      transform: translateX(0) !important;
    }
    body.admin-sidebar-closed {
      padding-left: 0 !important;
      transition: padding-left 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    }
    body.admin-sidebar-closed #admin-sidebar {
      transform: translateX(-100%) !important;
    }

    body.admin-sidebar-open main,
    body.admin-sidebar-open header > div,
    body.admin-sidebar-open .max-w-7xl {
      max-width: 98% !important;
    }
  }

  /* เมื่อปิดแถบลงไป (Sidebar Closed): ขยายให้เต็มจอ 100% สบายตา */
  body.admin-sidebar-closed main,
  body.admin-sidebar-closed header > div,
  body.admin-sidebar-closed .max-w-7xl,
  body.admin-sidebar-closed .max-w-6xl {
    max-width: 100% !important;
    width: 100% !important;
  }

  body.admin-sidebar-closed main {
    padding-left: 1.5rem !important;
    padding-right: 1.5rem !important;
  }

  @media (min-width: 1280px) {
    body.admin-sidebar-closed main {
      padding-left: 2.25rem !important;
      padding-right: 2.25rem !important;
    }
    body.admin-sidebar-closed header > div {
      padding-left: 2.25rem !important;
      padding-right: 2.25rem !important;
    }
  }

  /* ตารางปรับตัวเต็มพื้นที่ */
  body.admin-sidebar-closed .overflow-x-auto > table {
    width: 100% !important;
  }

  /* Mobile / Tablet Drawer */
  @media (max-width: 1023px) {
    body {
      padding-left: 0 !important;
    }
    body.admin-sidebar-open #admin-sidebar {
      transform: translateX(0) !important;
    }
    body.admin-sidebar-closed #admin-sidebar {
      transform: translateX(-100%) !important;
    }
  }
</style>

<!-- Script ป้องกัน Layout Shift ก่อนโหลด DOM เสร็จ -->
<script>
  (function() {
    try {
      const saved = localStorage.getItem('admin_sidebar_state');
      const isDesktop = window.innerWidth >= 1024;
      const isOpen = (saved !== null) ? (saved === 'open') : isDesktop;
      if (isOpen) {
        document.body.classList.add('admin-sidebar-open');
      } else {
        document.body.classList.add('admin-sidebar-closed');
      }
    } catch(e) {}
  })();
</script>

<!-- Backdrop มืดสำหรับจอมือถือ/แท็บเล็ต เมื่อเปิดแถบข้าง -->
<div id="admin-sidebar-backdrop" onclick="toggleAdminSidebar(false)" class="fixed inset-0 bg-stone-900/40 backdrop-blur-xs z-40 hidden lg:hidden transition-opacity duration-200"></div>

<!-- 🌟 แท็บด้านซ้ายมือ ศูนย์ควบคุมและการจัดการ (Admin Left Sidebar) 🌟 -->
<aside id="admin-sidebar" class="fixed top-0 bottom-0 left-0 z-50 flex flex-col bg-[#FFF8FA] border-r border-rose-200/70 shadow-[4px_0_24px_-4px_rgba(244,63,94,0.06)] select-none">

  <!-- Sidebar Brand Header -->
  <div class="h-20 px-5 border-b border-stone-200/70 flex items-center justify-between shrink-0 bg-white/90 backdrop-blur-xs">
    <div class="flex items-center gap-3 overflow-hidden">
      <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-sm ring-1 ring-stone-900/10 shrink-0">
        <i data-lucide="shield-check" class="w-5 h-5 text-amber-300"></i>
      </div>
      <div class="min-w-0">
        <div class="flex items-center gap-1.5">
          <span class="font-display font-bold text-base tracking-tight text-stone-900 truncate">หอพักจัสมิน</span>
          <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200 tracking-wide uppercase text-[10px]">ADMIN</span>
        </div>
        <p class="text-[11px] text-stone-400 font-medium truncate flex items-center gap-1 mt-0.5">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
          ศูนย์ควบคุมและการจัดการ
        </p>
      </div>
    </div>
    <!-- Close button for mobile -->
    <button type="button" onclick="toggleAdminSidebar(false)" class="lg:hidden p-1.5 rounded-xl text-stone-400 hover:text-stone-700 hover:bg-stone-100 transition cursor-pointer" title="ปิดแถบข้าง">
      <i data-lucide="x" class="w-5 h-5"></i>
    </button>
  </div>

  <!-- Sidebar Nav Menu Items -->
  <div class="flex-1 overflow-y-auto overflow-x-hidden p-3.5 space-y-1.5 scrollbar-thin">

    <div class="px-3 pt-2 pb-1 flex items-center justify-between">
      <span class="text-[10.5px] font-bold uppercase tracking-wider text-stone-400">เมนูบริหารจัดการ</span>
      <span class="text-[10px] text-stone-400 font-mono">Boutique</span>
    </div>

    <?php foreach($sidebarMenuItems as $item):
      $isActive = ($item['key'] === $activeKey);
      $badgeCount = $item['badge'];
    ?>
      <?php if ($isActive): ?>
        <!-- 🌟 เมนูที่เลือก (Active Item): Warm Boutique Highlight 🌟 -->
        <a href="<?php echo $item['url']; ?>"
           class="relative flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition-all duration-200 group bg-rose-50/90 text-rose-950 font-bold shadow-[0_2px_12px_-2px_rgba(244,63,94,0.12)] border border-rose-200/90"
           title="<?php echo htmlspecialchars($item['title'] . ' - ' . $item['subtitle']); ?>">

          <!-- แท็บเส้นไฮไลท์สีครามด้านซ้าย -->
          <span class="absolute left-0 top-1/2 -translate-y-1/2 w-1.5 h-6 bg-rose-500 rounded-r-full shadow-xs"></span>

          <div class="w-9 h-9 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-xs ring-2 ring-rose-500/20">
            <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4"></i>
          </div>

          <div class="flex-1 min-w-0">
            <div class="text-[13px] font-bold text-rose-950 truncate tracking-tight"><?php echo $item['title']; ?></div>
            <div class="text-[11px] text-rose-700/90 font-medium truncate"><?php echo $item['subtitle']; ?></div>
          </div>

          <?php if ($badgeCount > 0): ?>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $item['badgeColor'] ?: 'bg-rose-500 text-white'; ?> shrink-0 shadow-2xs animate-pulse">
              <?php echo $badgeCount; ?>
            </span>
          <?php endif; ?>
        </a>

      <?php else: ?>
        <!-- เมนูปกติ (Inactive Item): อบอุ่น เรียบหรู 🌟 -->
        <a href="<?php echo $item['url']; ?>"
           class="relative flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition-all duration-200 group border border-transparent text-stone-600 hover:text-rose-900 hover:bg-rose-50/50 hover:border-rose-200/60"
           title="<?php echo htmlspecialchars($item['title'] . ' - ' . $item['subtitle']); ?>">

          <div class="w-9 h-9 rounded-xl <?php echo $item['iconBg']; ?> flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform shadow-2xs">
            <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4"></i>
          </div>

          <div class="flex-1 min-w-0">
            <div class="text-[13px] font-semibold text-stone-700 group-hover:text-stone-900 transition truncate"><?php echo $item['title']; ?></div>
            <div class="text-[11px] text-stone-400 font-normal truncate"><?php echo $item['subtitle']; ?></div>
          </div>

          <?php if ($badgeCount > 0): ?>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?php echo $item['badgeColor'] ?: 'bg-stone-500 text-white'; ?> shrink-0 shadow-2xs">
              <?php echo $badgeCount; ?>
            </span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>

  </div>

  <!-- Sidebar Footer: บัญชีผู้ดูแล & สลับธีม & ออกจากระบบ -->
  <div class="p-3.5 border-t border-stone-200/70 bg-white/70 backdrop-blur-xs shrink-0 flex flex-col gap-2.5">
    <!-- Theme Switcher row (2 สี: Dark / Light) -->
    <div class="flex items-center justify-between px-1">
      <span class="text-[11px] font-semibold text-stone-500 flex items-center gap-1.5">
        <i data-lucide="palette" class="w-3.5 h-3.5 text-rose-500"></i>
        <span>ธีมแสดงผล</span>
      </span>
      <?php include 'includes/theme_toggle.php'; ?>
    </div>

    <!-- User Profile Quick Link & Logout -->
    <div class="flex items-center justify-between gap-2">
      <!-- User Profile Quick Link -->
      <a href="profile.php" class="flex-1 flex items-center gap-2.5 p-2 rounded-2xl hover:bg-stone-50 border border-stone-200/50 hover:border-stone-300 transition group <?php echo ($activeKey === 'profile') ? 'bg-stone-100/80 border-stone-300 shadow-xs' : 'bg-stone-50/50'; ?>" title="โปรไฟล์ของฉัน">
        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-stone-800 to-stone-950 text-white flex items-center justify-center font-bold text-xs shrink-0 shadow-2xs">
          <?php echo $sidebarInitials; ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-xs font-semibold text-stone-800 truncate group-hover:text-rose-600 transition"><?php echo htmlspecialchars($sidebarAdminName); ?></div>
          <div class="text-[10px] text-emerald-600 font-medium flex items-center gap-1">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> แอดมิน (Admin)
          </div>
        </div>
      </a>

      <!-- ปุ่มออกจากระบบ -->
      <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="p-2.5 text-rose-500 hover:bg-rose-50 hover:text-rose-700 rounded-xl transition shrink-0 border border-transparent hover:border-rose-100" title="ออกจากระบบ">
        <i data-lucide="log-out" class="w-4 h-4"></i>
      </a>
    </div>
  </div>

</aside>

<!-- 🌟 JavaScript ควบคุมการเปิด-ปิด: ปิดก็ปิดให้สุด เปิดก็เปิดให้สุด 🌟 -->
<script>
  function toggleAdminSidebar(forceState = null) {
    const isCurrentlyOpen = document.body.classList.contains('admin-sidebar-open');
    const shouldOpen = (forceState !== null) ? forceState : !isCurrentlyOpen;
    const backdrop = document.getElementById('admin-sidebar-backdrop');

    if (shouldOpen) {
      document.body.classList.remove('admin-sidebar-closed');
      document.body.classList.add('admin-sidebar-open');
      localStorage.setItem('admin_sidebar_state', 'open');
      if (backdrop && window.innerWidth < 1024) {
        backdrop.classList.remove('hidden');
      }
    } else {
      document.body.classList.remove('admin-sidebar-open');
      document.body.classList.add('admin-sidebar-closed');
      localStorage.setItem('admin_sidebar_state', 'closed');
      if (backdrop) {
        backdrop.classList.add('hidden');
      }
    }

    if (window.lucide) {
      lucide.createIcons();
    }
  }

  // เรียกใช้เมื่อโหลด DOM เสร็จ
  document.addEventListener('DOMContentLoaded', function() {
    try {
      const saved = localStorage.getItem('admin_sidebar_state');
      const isDesktop = window.innerWidth >= 1024;
      const isOpen = (saved !== null) ? (saved === 'open') : isDesktop;
      toggleAdminSidebar(isOpen);
    } catch(e) {}
    if (window.lucide) {
      lucide.createIcons();
    }
  });

  // ปิดด้วยปุ่ม ESC บนคีย์บอร์ด
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      toggleAdminSidebar(false);
    }
  });
</script>
