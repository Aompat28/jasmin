<?php

// includes/tenant_sidebar.php

// แถบเมนูด้านซ้ายสำหรับผู้เช่าพักอาศัย (Tenant Left Sidebar)

// สไตล์เดียวกันกับศูนย์ควบคุมและการจัดการของผู้ดูแลระบบ (Admin Sidebar)



if (session_status() === PHP_SESSION_NONE) {

    session_start();

}



// แสดงเฉพาะผู้ใช้ที่มีสิทธิ์ Tenant เท่านั้น

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {

    return;

}



$tenantId = (int)$_SESSION['user_id'];

$sidebarTenantName = $_SESSION['full_name'] ?? 'ผู้เช่า';

$sidebarInitials = strtoupper(mb_substr($sidebarTenantName, 0, 2, 'UTF-8'));



// 1. ดึงข้อมูลเลขห้องพักปัจจุบันของผู้เช่า

$sidebarRoomNumber = '';

$sidebarRoomType = '';

$sidebarFloor = '';

if (isset($conn) && $conn instanceof PDO) {

    try {

        $stmtRoom = $conn->prepare("

            SELECT r.roomNumber, r.roomType, r.floor 

            FROM Lease l 

            JOIN Room r ON l.roomId = r.roomId 

            WHERE l.tenantId = ? 

            ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC 

            LIMIT 1

        ");

        $stmtRoom->execute([$tenantId]);

        if ($rRow = $stmtRoom->fetch(PDO::FETCH_ASSOC)) {

            $sidebarRoomNumber = $rRow['roomNumber'];

            $sidebarRoomType = $rRow['roomType'];

            $sidebarFloor = $rRow['floor'];

        }

    } catch(Throwable $e) {}

}

$headerTenantName = $sidebarTenantName;
$headerRoomNumber = $sidebarRoomNumber;
$_SESSION['tenant_room_number'] = $sidebarRoomNumber;



// 2. ดึงจำนวนแจ้งเตือนและงานค้าง (Badges) แบบเรียลไทม์

$sidebarUnpaidCount = 0;

$sidebarPendingServices = 0;

$sidebarPendingRenewals = 0;

$sidebarPendingRoomChanges = 0;

$sidebarPendingCoresidents = 0;

$sidebarPendingMoveOuts = 0;

$sidebarPendingParking = 0;



if (isset($conn) && $conn instanceof PDO) {

    // บิลค้างชำระ

    try {

        $stmt = $conn->prepare("SELECT COUNT(*) FROM Invoices WHERE tenantId = ? AND status = 'Unpaid'");

        $stmt->execute([$tenantId]);

        $sidebarUnpaidCount = (int)$stmt->fetchColumn();

    } catch(Throwable $e) {}



    // งานแจ้งซ่อมที่กำลังดำเนินการหรือรอรับเรื่อง

    try {

        $stmt = $conn->prepare("SELECT COUNT(*) FROM ServiceRequest WHERE tenantId = ? AND status IN ('Pending', 'In Progress')");

        $stmt->execute([$tenantId]);

        $sidebarPendingServices = (int)$stmt->fetchColumn();

    } catch(Throwable $e) {}



    // คำขอต่อสัญญาที่รออนุมัติ

    try {

        $stmt = $conn->prepare("SELECT COUNT(*) FROM LeaseRenewals WHERE tenantId = ? AND status = 'Pending'");

        $stmt->execute([$tenantId]);

        $sidebarPendingRenewals = (int)$stmt->fetchColumn();

    } catch(Throwable $e) {}



    // คำขอย้ายห้องที่รออนุมัติ

    try {

        $stmt = $conn->prepare("SELECT COUNT(*) FROM RoomChangeRequests WHERE tenantId = ? AND status = 'Pending'");

        $stmt->execute([$tenantId]);

        $sidebarPendingRoomChanges = (int)$stmt->fetchColumn();

    } catch(Throwable $e) {}



    // คำขอผู้ร่วมอาศัยที่รออนุมัติ

    try {

        $stmt = $conn->prepare("SELECT COUNT(*) FROM coresidents WHERE tenantId = ? AND status = 'Pending'");

        $stmt->execute([$tenantId]);

        $sidebarPendingCoresidents = (int)$stmt->fetchColumn();

    } catch(Throwable $e) {}



    // คำขอย้ายออกที่กำลังดำเนินการ

    try {

        $stmt = $conn->prepare("SELECT COUNT(*) FROM Lease WHERE tenantId = ? AND status = 'Active' AND moveOutRequestDate IS NOT NULL AND (moveOutStatus IS NULL OR moveOutStatus != 'Settled')");

        $stmt->execute([$tenantId]);

        $sidebarPendingMoveOuts = (int)$stmt->fetchColumn();

    } catch(Throwable $e) {}

    // คำขอเพิ่มรถ / ที่จอดรถที่รอตรวจสอบ
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM parking_requests WHERE tenantId = ? AND status = 'Pending'");
        $stmt->execute([$tenantId]);
        $sidebarPendingParking = (int)$stmt->fetchColumn();
    } catch(Throwable $e) {}

}



// 3. ตรวจสอบหน้าปัจจุบันเพื่อทำการไฮไลท์กรอบเมนูที่เลือก (Active Item)

$currentScript = basename($_SERVER['PHP_SELF']);

$activeKey = '';

if ($currentScript === 'tenant_dashboard.php') {

    $activeKey = 'dashboard';

} elseif ($currentScript === 'view_invoices.php' || $currentScript === 'tenant_invoice.php') {

    $activeKey = 'invoice';

} elseif ($currentScript === 'tenant_request.php') {

    $activeKey = 'requests';

} elseif ($currentScript === 'tenant_request_history.php') {

    $activeKey = 'request_history';

} elseif ($currentScript === 'tenant_renewal.php') {

    $activeKey = 'renewals';

} elseif ($currentScript === 'tenant_room_change.php') {

    $activeKey = 'room_change';

} elseif ($currentScript === 'tenant_coresidents.php') {

    $activeKey = 'coresidents';

} elseif ($currentScript === 'tenant_move_out.php') {

    $activeKey = 'move_out';

} elseif ($currentScript === 'tenant_parking.php') {

    $activeKey = 'parking';

} elseif ($currentScript === 'profile.php' || $currentScript === 'change_password.php') {

    $activeKey = 'profile';

}



// 4. รายการเมนูบริการลูกบ้าน

$sidebarMenuItems = [

    [

        'key' => 'dashboard',

        'title' => 'แดชบอร์ดภาพรวม',

        'subtitle' => (!empty($sidebarRoomNumber) ? "ห้อง {$sidebarRoomNumber}" : 'สรุปข้อมูลห้องพัก'),

        'url' => 'tenant_dashboard.php',

        'icon' => 'layout-dashboard',

        'iconBg' => 'bg-blue-50 text-blue-600',

        'badge' => 0,

        'badgeColor' => ''

    ],

    [

        'key' => 'invoice',

        'title' => 'ใบแจ้งหนี้ & ชำระเงิน',

        'subtitle' => ($sidebarUnpaidCount > 0 ? "รอชำระ {$sidebarUnpaidCount} บิล" : 'ตรวจบิล / สลิปโอน'),

        'url' => 'view_invoices.php',

        'icon' => 'receipt',

        'iconBg' => 'bg-emerald-50 text-emerald-600',

        'badge' => $sidebarUnpaidCount,

        'badgeColor' => 'bg-amber-500'

    ],

    [

        'key' => 'requests',

        'title' => 'งานแจ้งซ่อมบำรุง',

        'subtitle' => ($sidebarPendingServices > 0 ? "กำลังซ่อม {$sidebarPendingServices} งาน" : 'ส่งเรื่องแจ้งซ่อม'),

        'url' => 'tenant_request.php',

        'icon' => 'wrench',

        'iconBg' => 'bg-amber-50 text-amber-600',

        'badge' => $sidebarPendingServices,

        'badgeColor' => 'bg-rose-500'

    ],

    [

        'key' => 'request_history',

        'title' => 'ประวัติการแจ้งซ่อม',

        'subtitle' => 'ติดตามสถานะงาน',

        'url' => 'tenant_request_history.php',

        'icon' => 'history',

        'iconBg' => 'bg-blue-50 text-blue-600',

        'badge' => 0,

        'badgeColor' => ''

    ],

    [

        'key' => 'renewals',

        'title' => 'ขอต่ออายุสัญญา',

        'subtitle' => ($sidebarPendingRenewals > 0 ? 'รออนุมัติ 1 คำขอ' : 'ต่อสัญญาเช่า'),

        'url' => 'tenant_renewal.php',

        'icon' => 'refresh-cw',

        'iconBg' => 'bg-indigo-50 text-indigo-600',

        'badge' => $sidebarPendingRenewals,

        'badgeColor' => 'bg-indigo-600'

    ],

    [

        'key' => 'room_change',

        'title' => 'ขอย้ายห้องพัก',

        'subtitle' => ($sidebarPendingRoomChanges > 0 ? 'รออนุมัติ 1 คำขอ' : 'เปลี่ยนห้องพัก'),

        'url' => 'tenant_room_change.php',

        'icon' => 'arrow-left-right',

        'iconBg' => 'bg-purple-50 text-purple-600',

        'badge' => $sidebarPendingRoomChanges,

        'badgeColor' => 'bg-purple-600'

    ],

    [

        'key' => 'coresidents',

        'title' => 'ผู้ร่วมอาศัย',

        'subtitle' => ($sidebarPendingCoresidents > 0 ? "รอ {$sidebarPendingCoresidents} คำขอ" : 'คำขอเพิ่มสมาชิก'),

        'url' => 'tenant_coresidents.php',

        'icon' => 'users',

        'iconBg' => 'bg-violet-50 text-violet-600',

        'badge' => $sidebarPendingCoresidents,

        'badgeColor' => 'bg-violet-600'

    ],

    [

        'key' => 'move_out',

        'title' => 'แจ้งย้ายออก & คืนห้อง',

        'subtitle' => ($sidebarPendingMoveOuts > 0 ? 'รอคืนเงิน/เคลียร์ห้อง' : 'ขอคืนห้องพัก'),

        'url' => 'tenant_move_out.php',

        'icon' => 'package-minus',

        'iconBg' => 'bg-rose-50 text-rose-600',

        'badge' => $sidebarPendingMoveOuts,

        'badgeColor' => 'bg-rose-500'

    ],

    [

        'key' => 'parking',

        'title' => 'ขอเพิ่มรถ & ที่จอดรถ',

        'subtitle' => ($sidebarPendingParking > 0 ? "รออนุมัติ {$sidebarPendingParking} คำขอ" : 'ลงทะเบียนรถมากกว่า 1 คัน'),

        'url' => 'tenant_parking.php',

        'icon' => 'car',

        'iconBg' => 'bg-cyan-50 text-cyan-600',

        'badge' => $sidebarPendingParking,

        'badgeColor' => 'bg-cyan-600'

    ]

];

?>



<!-- 🌟 CSS Styles: เปิด-ปิดแบบ 100% สไตล์แอดมิน 🌟 -->

<style id="tenant-sidebar-style">

  #tenant-sidebar {

    width: 16rem !important; /* กว้าง 256px */

    transition: transform 0.26s cubic-bezier(0.16, 1, 0.3, 1);

    transform: translateX(-100%); /* ค่าเริ่มต้น: ปิด */

  }



  html, body {

    overflow-x: clip;

  }



  main, header > div, .max-w-7xl, .max-w-6xl, .max-w-5xl, .max-w-4xl, .max-w-3xl {

    transition: max-width 0.26s cubic-bezier(0.16, 1, 0.3, 1), padding 0.26s cubic-bezier(0.16, 1, 0.3, 1), width 0.26s cubic-bezier(0.16, 1, 0.3, 1);

  }



  /* กรณีเปิดสุด (Desktop): เนื้อหาเลื่อนเว้นที่ 16rem */

  @media (min-width: 1024px) {

    body.tenant-sidebar-open {

      padding-left: 16rem !important;

      transition: padding-left 0.26s cubic-bezier(0.16, 1, 0.3, 1);

    }

    body.tenant-sidebar-open #tenant-sidebar {

      transform: translateX(0) !important;

    }

    body.tenant-sidebar-closed {

      padding-left: 0 !important;

      transition: padding-left 0.26s cubic-bezier(0.16, 1, 0.3, 1);

    }

    body.tenant-sidebar-closed #tenant-sidebar {

      transform: translateX(-100%) !important;

    }



    body.tenant-sidebar-open main,

    body.tenant-sidebar-open header > div,

    body.tenant-sidebar-open .max-w-7xl,

    body.tenant-sidebar-open .max-w-6xl,

    body.tenant-sidebar-open .max-w-5xl {

      max-width: 98% !important;

    }

  }



  /* เมื่อปิดแถบลงไป (Sidebar Closed): ขยายเต็มจอ 100% */

  body.tenant-sidebar-closed main,

  body.tenant-sidebar-closed header > div,

  body.tenant-sidebar-closed .max-w-7xl,

  body.tenant-sidebar-closed .max-w-6xl,

  body.tenant-sidebar-closed .max-w-5xl,

  body.tenant-sidebar-closed .max-w-4xl,

  body.tenant-sidebar-closed .max-w-3xl {

    max-width: 100% !important;

    width: 100% !important;

  }



  body.tenant-sidebar-closed main {

    padding-left: 1.25rem !important;

    padding-right: 1.25rem !important;

  }



  @media (min-width: 1280px) {

    body.tenant-sidebar-closed main {

      padding-left: 2rem !important;

      padding-right: 2rem !important;

    }

    body.tenant-sidebar-closed header > div {

      padding-left: 2rem !important;

      padding-right: 2rem !important;

    }

  }



  body.tenant-sidebar-closed .overflow-x-auto > table {

    width: 100% !important;

  }



  @media (max-width: 1023px) {

    body {

      padding-left: 0 !important;

    }

    body.tenant-sidebar-open #tenant-sidebar {

      transform: translateX(0) !important;

    }

    body.tenant-sidebar-closed #tenant-sidebar {

      transform: translateX(-100%) !important;

    }

  }

</style>



<!-- Script ป้องกัน Layout Shift -->

<script>

  (function() {

    try {

      const saved = localStorage.getItem('tenant_sidebar_state');

      const isDesktop = window.innerWidth >= 1024;

      const isOpen = (saved !== null) ? (saved === 'open') : isDesktop;

      if (isOpen) {

        document.body.classList.add('tenant-sidebar-open');

      } else {

        document.body.classList.add('tenant-sidebar-closed');

      }

    } catch(e) {}

  })();

</script>



<!-- Backdrop Overlay บนมือถือ -->

<div id="tenant-sidebar-backdrop" onclick="toggleTenantSidebar(false)" class="fixed inset-0 bg-slate-900/40 backdrop-blur-xs z-40 transition-opacity duration-200 hidden lg:hidden"></div>



<!-- 🌟 แท็บด้านซ้ายมือ ศูนย์บริการลูกบ้าน (Tenant Sidebar) 🌟 -->

<aside id="tenant-sidebar" class="fixed top-0 bottom-0 left-0 z-50 flex flex-col bg-[#FFF8FA] border-r border-rose-200/70 shadow-[4px_0_24px_-4px_rgba(244,63,94,0.06)] select-none">

  

  <!-- Sidebar Header -->

  <div class="h-16 px-4 border-b border-slate-100 flex items-center justify-between shrink-0 bg-white">

    <div class="flex items-center space-x-3 overflow-hidden">

      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center text-white shadow-md shrink-0">

        <i data-lucide="home" class="w-5 h-5"></i>

      </div>

      <div class="min-w-0">

        <div class="flex items-center space-x-2">

          <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
          <span class="text-xs bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200">TENANT</span>
        </div>
        <p class="text-xs text-slate-500 font-medium truncate">ระบบจัดการลูกบ้าน</p>

      </div>

    </div>

    <!-- ปุ่มปิดแถบด้านซ้าย -->

    <button type="button" onclick="toggleTenantSidebar(false)" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg lg:hidden transition cursor-pointer" title="ปิดเมนู">

      <i data-lucide="x" class="w-5 h-5"></i>

    </button>

  </div>



  <!-- Sidebar Nav Menu Items -->

  <div class="flex-1 overflow-y-auto overflow-x-hidden p-3 space-y-1.5 scrollbar-thin">

    

    <div class="px-2 pt-1 pb-1">
      <span class="text-xs font-bold uppercase tracking-wider text-slate-500">เมนูบริหารและบริการ</span>
    </div>

    <?php foreach($sidebarMenuItems as $item): 
      $isActive = ($item['key'] === $activeKey);
      $badgeCount = $item['badge'];
    ?>
      <?php if ($isActive): ?>
        <!-- 🌟 เมนูที่กำลังใช้งาน: กรอบไฮไลท์สีน้ำเงินเหมือนแอดมิน 🌟 -->
        <a href="<?php echo $item['url']; ?>" 
           class="relative flex items-center gap-3 px-3 py-2.5 rounded-2xl transition-all duration-200 group border border-rose-200/90 bg-rose-50/90 text-rose-950 font-bold shadow-[0_2px_12px_-2px_rgba(244,63,94,0.12)]" 
           title="<?php echo htmlspecialchars($item['title'] . ' - ' . $item['subtitle']); ?>">
          
          <span class="absolute left-0 top-1/2 -translate-y-1/2 w-1.5 h-6 bg-rose-500 rounded-r-full shadow-xs"></span>
          
          <div class="w-9 h-9 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-xs ring-2 ring-rose-500/20">
            <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4"></i>
          </div>

          <div class="flex-1 min-w-0">
            <div class="text-sm font-bold text-blue-900 truncate"><?php echo $item['title']; ?></div>
            <div class="text-xs text-blue-700/90 font-medium truncate"><?php echo $item['subtitle']; ?></div>
          </div>

          <?php if ($badgeCount > 0): ?>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold text-white <?php echo $item['badgeColor'] ?: 'bg-rose-500'; ?> shrink-0 animate-pulse">
              <?php echo $badgeCount; ?>
            </span>
          <?php endif; ?>
        </a>
      <?php else: ?>
        <!-- เมนูปกติ -->
        <a href="<?php echo $item['url']; ?>" 
           class="relative flex items-center gap-3 px-3 py-2.5 rounded-2xl transition-all duration-200 group border border-transparent text-stone-600 hover:text-rose-900 hover:bg-rose-50/50 hover:border-rose-200/60" 
           title="<?php echo htmlspecialchars($item['title'] . ' - ' . $item['subtitle']); ?>">
          
          <div class="w-9 h-9 rounded-xl <?php echo $item['iconBg']; ?> flex items-center justify-center shrink-0 group-hover:scale-105 transition-transform">
            <i data-lucide="<?php echo $item['icon']; ?>" class="w-4 h-4"></i>
          </div>

          <div class="flex-1 min-w-0">
            <div class="text-sm font-bold text-slate-800 group-hover:text-rose-700 transition truncate"><?php echo $item['title']; ?></div>
            <div class="text-xs text-slate-500 font-medium truncate"><?php echo $item['subtitle']; ?></div>
          </div>

          <?php if ($badgeCount > 0): ?>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold text-white <?php echo $item['badgeColor'] ?: 'bg-slate-500'; ?> shrink-0">
              <?php echo $badgeCount; ?>
            </span>
          <?php endif; ?>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>



  </div>



  <!-- Sidebar Footer: User Profile & สลับธีม -->

  <div class="p-3 border-t border-rose-200/70 bg-white/70 backdrop-blur-xs shrink-0 flex flex-col gap-2">

    <!-- Theme Switcher row (2 สี: Dark / Light) -->
    <div class="flex items-center justify-between px-1">
      <span class="text-[11px] font-semibold text-slate-500 flex items-center gap-1.5">
        <i data-lucide="palette" class="w-3.5 h-3.5 text-rose-500"></i>
        <span>ธีมแสดงผล</span>
      </span>
      <?php include 'includes/theme_toggle.php'; ?>
    </div>

    <!-- User Profile Link -->

    <a href="profile.php" class="w-full flex items-center justify-between gap-2.5 p-2 rounded-xl hover:bg-white hover:shadow-xs border border-transparent hover:border-rose-200 transition group <?php echo ($activeKey === 'profile') ? 'bg-white border-rose-300 shadow-xs' : ''; ?>" title="แก้ไขโปรไฟล์">

      <div class="flex items-center gap-2.5 min-w-0">

        <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs shrink-0 shadow-2xs border border-rose-200 group-hover:scale-105 transition">

          <?php echo $sidebarInitials; ?>

        </div>

        <div class="min-w-0 text-left">

          <div class="text-sm font-semibold text-slate-800 truncate group-hover:text-rose-600 transition"><?php echo htmlspecialchars($sidebarTenantName); ?></div>

        </div>

      </div>

      <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400 group-hover:text-rose-600 transition shrink-0"></i>

    </a>

  </div>



</aside>



<!-- 🌟 JavaScript ควบคุมการเปิด-ปิด เพียงปุ่มเดียว: ปิดก็ปิดให้สุด เปิดก็เปิดให้สุด 🌟 -->

<script>

  function toggleTenantSidebar(forceState = null) {

    const isCurrentlyOpen = document.body.classList.contains('tenant-sidebar-open');

    const shouldOpen = (forceState !== null) ? forceState : !isCurrentlyOpen;

    const backdrop = document.getElementById('tenant-sidebar-backdrop');



    if (shouldOpen) {

      document.body.classList.remove('tenant-sidebar-closed');

      document.body.classList.add('tenant-sidebar-open');

      localStorage.setItem('tenant_sidebar_state', 'open');

      if (backdrop && window.innerWidth < 1024) backdrop.classList.remove('hidden');

    } else {

      document.body.classList.remove('tenant-sidebar-open');

      document.body.classList.add('tenant-sidebar-closed');

      localStorage.setItem('tenant_sidebar_state', 'closed');

      if (backdrop) backdrop.classList.add('hidden');

    }



    if (window.lucide) {

      lucide.createIcons();

    }

  }



  document.addEventListener('DOMContentLoaded', function() {

    try {

      const saved = localStorage.getItem('tenant_sidebar_state');

      const isDesktop = window.innerWidth >= 1024;

      const isOpen = (saved !== null) ? (saved === 'open') : isDesktop;

      toggleTenantSidebar(isOpen);

    } catch(e) {}

    if (window.lucide) {

      lucide.createIcons();

    }

  });



  document.addEventListener('keydown', function(e) {

    if (e.key === 'Escape') {

      toggleTenantSidebar(false);

    }

  });

</script>



