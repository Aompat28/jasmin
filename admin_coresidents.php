<?php

session_start();

require_once 'includes/db_connect.php';



// ตรวจสอบสิทธิ์ ต้องเป็น แอดมิน (Admin) เท่านั้น

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {

    header("Location: login.php");

    exit();

}



$adminName = $_SESSION['full_name'] ?? 'ผู้ดูแลระบบ';

$message = "";



// --- 1. ระบบอนุมัติผู้ร่วมอาศัย ---

if (isset($_POST['approve_coresident'])) {

    $coresidentId = intval($_POST['coresident_id']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'อนุมัติเรียบร้อยแล้ว';



    try {

        // ดึงข้อมูลผู้ร่วมอาศัยที่จะอนุมัติ

        $stmtInfo = $conn->prepare("SELECT tenantId, roomId, idCardNumber, fullName FROM coresidents WHERE id = ?");

        $stmtInfo->execute([$coresidentId]);

        $targetCo = $stmtInfo->fetch(PDO::FETCH_ASSOC);



        $stmt = $conn->prepare("UPDATE coresidents SET status = 'Approved', adminRemark = ? WHERE id = ?");

        $stmt->execute([$adminRemark, $coresidentId]);



        // หากมีคำขอ Pending อื่นที่ซ้ำซ้อนของบุคคลนี้ในห้องพักเดียวกัน ให้ลบออกทันทีเพื่อไม่ให้มีรายการซ้ำ

        if ($targetCo) {

            $stmtClean = $conn->prepare("

                DELETE FROM coresidents 

                WHERE tenantId = ? AND roomId = ? 

                  AND (idCardNumber = ? OR fullName = ?) 

                  AND id != ? 

                  AND status = 'Pending'

            ");

            $stmtClean->execute([$targetCo['tenantId'], $targetCo['roomId'], $targetCo['idCardNumber'], $targetCo['fullName'], $coresidentId]);

        }



        $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5'></i> อนุมัติคำขอผู้ร่วมอาศัยเรียบร้อยแล้ว! ผู้เช่าและผู้ร่วมอาศัยสามารถพักอาศัยร่วมกันได้</div>";

    } catch(PDOException $e) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 2. ระบบอนุมัติการยกเลิกผู้ร่วมอาศัย (ตามคำขอของลูกบ้าน) ---

if (isset($_POST['approve_cancellation'])) {

    $coresidentId = intval($_POST['coresident_id']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'อนุมัติการยกเลิกผู้ร่วมอาศัยเรียบร้อยแล้ว';



    try {

        $stmt = $conn->prepare("UPDATE coresidents SET status = 'Cancelled', adminRemark = ? WHERE id = ?");

        $stmt->execute([$adminRemark, $coresidentId]);

        $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5'></i> อนุมัติการยกเลิกผู้ร่วมอาศัยเรียบร้อยแล้ว สถานะเปลี่ยนเป็น 'ยกเลิกแล้ว'</div>";

    } catch(PDOException $e) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 3. ระบบปฏิเสธคำขอยกเลิก (ให้คงสถานะ Approved ตามเดิม) ---

if (isset($_POST['reject_cancellation'])) {

    $coresidentId = intval($_POST['coresident_id']);

    $adminRemark = !empty($_POST['admin_remark']) ? trim($_POST['admin_remark']) : 'ปฏิเสธคำขอยกเลิก (ยังคงสถานะพักอาศัย)';



    try {

        $stmt = $conn->prepare("UPDATE coresidents SET status = 'Approved', adminRemark = ?, cancelReason = NULL WHERE id = ?");

        $stmt->execute([$adminRemark, $coresidentId]);

        $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='info' class='w-5 h-5'></i> ปฏิเสธคำขอยกเลิกเรียบร้อยแล้ว ผู้ร่วมอาศัยยังคงมีสถานะอนุมัติให้พักอาศัยต่อไป</div>";

    } catch(PDOException $e) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 4. ระบบปฏิเสธคำขอ (ไม่อนุมัติเข้าพัก) ---

if (isset($_POST['reject_coresident'])) {

    $coresidentId = intval($_POST['coresident_id']);

    $adminRemark = trim($_POST['admin_remark']);



    if (empty($adminRemark)) {

        $adminRemark = "ไม่ผ่านเกณฑ์การพิจารณา หรือข้อมูลไม่ครบถ้วน";

    }



    try {

        $stmt = $conn->prepare("UPDATE coresidents SET status = 'Rejected', adminRemark = ? WHERE id = ?");

        $stmt->execute([$adminRemark, $coresidentId]);

        $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='x-circle' class='w-5 h-5'></i> ปฏิเสธคำขอผู้ร่วมอาศัยเรียบร้อยแล้ว ระบบได้บันทึกเหตุผลแจ้งให้ลูกบ้านทราบ</div>";

    } catch(PDOException $e) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// --- 3. ระบบลบข้อมูลคำขอ ---

if (isset($_POST['delete_coresident'])) {

    $coresidentId = intval($_POST['coresident_id']);

    try {

        $stmtCheck = $conn->prepare("SELECT idCardImage FROM coresidents WHERE id = ?");

        $stmtCheck->execute([$coresidentId]);

        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['idCardImage']) && file_exists($row['idCardImage'])) {

            unlink($row['idCardImage']);

        }



        $stmtDel = $conn->prepare("DELETE FROM coresidents WHERE id = ?");

        $stmtDel->execute([$coresidentId]);

        $message = "<div class='p-4 mb-6 bg-slate-50 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ลบรายการข้อมูลผู้ร่วมอาศัยเรียบร้อยแล้ว</div>";

    } catch(PDOException $e) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</div>";

    }

}



// ทำความสะอาดรายการ Pending ที่ซ้ำซ้อนกับรายการที่ Approved ไปแล้ว

try {

    $conn->query("

        DELETE p FROM coresidents p

        INNER JOIN coresidents a ON p.tenantId = a.tenantId 

            AND p.roomId = a.roomId 

            AND (p.idCardNumber = a.idCardNumber OR p.fullName = a.fullName)

            AND a.status = 'Approved'

            AND p.status = 'Pending'

            AND p.id != a.id

    ");

} catch(PDOException $e) {}



// --- ดึงรายการคำขอผู้ร่วมอาศัย (รองรับการแยกกรองตามห้อง roomId) ---

$filterRoomId = isset($_GET['roomId']) ? intval($_GET['roomId']) : null;

$filterRoomNumber = null;



if ($filterRoomId) {

    $stmtRoomInfo = $conn->prepare("SELECT roomNumber FROM Room WHERE roomId = ?");

    $stmtRoomInfo->execute([$filterRoomId]);

    $filterRoomNumber = $stmtRoomInfo->fetchColumn();



    $stmtAll = $conn->prepare("

        SELECT c.*, r.roomNumber, u.fullName AS tenantName, u.phone AS tenantPhone 

        FROM coresidents c 

        JOIN Room r ON c.roomId = r.roomId 

        JOIN User u ON c.tenantId = u.userId 

        WHERE c.roomId = ?

        ORDER BY FIELD(c.status, 'Cancel_Pending', 'Pending', 'Approved', 'Cancelled', 'Rejected'), c.id DESC

    ");

    $stmtAll->execute([$filterRoomId]);

} else {

    $stmtAll = $conn->query("

        SELECT c.*, r.roomNumber, u.fullName AS tenantName, u.phone AS tenantPhone 

        FROM coresidents c 

        JOIN Room r ON c.roomId = r.roomId 

        JOIN User u ON c.tenantId = u.userId 

        ORDER BY FIELD(c.status, 'Cancel_Pending', 'Pending', 'Approved', 'Cancelled', 'Rejected'), c.id DESC

    ");

}

$rawRequests = $stmtAll->fetchAll(PDO::FETCH_ASSOC);



// กรองซ้ำซ้อน: หากมีรายการ Approved หรือ Cancel_Pending ของบุคคลเดียวกันในห้องเดียวกัน จะไม่แสดงรายการ Pending ซ้ำอีก

$seenApproved = [];

foreach($rawRequests as $r) {

    if ($r['status'] === 'Approved' || $r['status'] === 'Cancel_Pending') {

        $key = $r['roomId'] . '_' . ($r['idCardNumber'] ?: $r['fullName']);

        $seenApproved[$key] = true;

    }

}



$requests = [];

foreach($rawRequests as $r) {

    if ($r['status'] === 'Pending') {

        $key = $r['roomId'] . '_' . ($r['idCardNumber'] ?: $r['fullName']);

        if (isset($seenApproved[$key])) {

            continue; // ข้ามคำขอ Pending ที่ซ้ำกับคนที่อนุมัติแล้ว

        }

    }

    $requests[] = $r;

}



// นับสถิติ

$totalCount = count($requests);

$countPending = 0;

$countCancelPending = 0;

$countApproved = 0;

$countRejected = 0;

$countCancelled = 0;

foreach($requests as $r) {

    if ($r['status'] === 'Pending') $countPending++;

    elseif ($r['status'] === 'Cancel_Pending') $countCancelPending++;

    elseif ($r['status'] === 'Approved') $countApproved++;

    elseif ($r['status'] === 'Rejected') $countRejected++;

    elseif ($r['status'] === 'Cancelled') $countCancelled++;

}

$countActionRequired = $countPending + $countCancelPending;

$countInactive = $countRejected + $countCancelled;

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

    body {

      background: radial-gradient(circle at 15% 15%, #0f172a 0%, #080d1a 50%, #030712 100%);

      color: #f1f5f9;

      font-family: 'Prompt', sans-serif;

      min-height: 100vh;

      overflow-x: hidden;

    }

    .glass-card {

      background: rgba(15, 23, 42, 0.65);

      backdrop-filter: blur(20px);

      -webkit-backdrop-filter: blur(20px);

      border: 1px solid rgba(255, 255, 255, 0.1);

      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);

      border-radius: 1.5rem;

    }

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

            <i data-lucide="users" class="w-5 h-5 text-amber-300"></i>

          </div>

          <div>

            <div class="flex items-center space-x-2">

              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>

              <span class="text-[10px] bg-blue-500/20 text-blue-300 font-bold px-2 py-0.5 rounded-full border border-blue-500/30 uppercase tracking-wider">CORESIDENTS</span>

            </div>

            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">จัดการคำขอและข้อมูลผู้พักอาศัยร่วม</p>

          </div>

        </div>



        <div class="flex items-center space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

          <a href="admin_dashboard.php" class="text-xs sm:text-sm font-bold text-slate-600 hover:text-rose-600 flex items-center gap-1.5 transition px-3 py-1.5 rounded-xl hover:bg-rose-50/70 border border-stone-200/60">

            <i data-lucide="arrow-left" class="w-4 h-4"></i> แดชบอร์ด

          </a>

          <div class="w-px h-5 bg-stone-200"></div>

          <a href="logout.php" onclick="return confirm('ออกจากระบบ?');" class="text-rose-500 hover:bg-rose-50 hover:text-rose-600 transition p-2 rounded-xl border border-stone-200/80" title="ออกจากระบบ">

            <i data-lucide="log-out" class="w-4 h-4"></i>

          </a>

        </div>

      </div>

    </div>

  </header>



  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">



    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->

    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">

      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>



      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">

        <div>

          <div class="flex items-center gap-2 flex-wrap mb-2">

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">

              <i data-lucide="user-check" class="w-3.5 h-3.5 text-rose-500"></i>

              <span>คำขอผู้ร่วมอาศัย</span>

            </span>

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">

              <i data-lucide="home" class="w-3.5 h-3.5 text-amber-500"></i>

              <span>ตรวจสอบ & พิจารณาอนุมัติ</span>

            </span>

          </div>

          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">

            ระบบอนุมัติคำขอผู้ร่วมอาศัย

          </h1>

          <p class="text-xs sm:text-sm text-slate-500 mt-1">

            ตรวจสอบข้อมูลผู้ร่วมอาศัยที่ลูกบ้านยื่นคำขอ คำขอยกเลิกผู้ร่วมอาศัย ตรวจสอบเอกสาร และพิจารณาอนุมัติ/ปฏิเสธ

          </p>

        </div>



        <div class="flex flex-wrap items-center gap-3 shrink-0">

          <a href="manage_room.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">

            <i data-lucide="door-closed" class="w-4 h-4 text-slate-500"></i>

            <span>ผังห้องพัก</span>

          </a>

        </div>

      </div>

    </div>



    <!-- Alert Box -->

    <?php echo $message; ?>



    <?php if ($filterRoomNumber): ?>

    <div class="mb-6 p-4 bg-rose-50 border-l-4 border-indigo-600 rounded-r-2xl flex items-center justify-between shadow-xs">

        <div class="flex items-center gap-2.5 text-indigo-900 font-bold text-sm">

            <i data-lucide="filter" class="w-5 h-5 text-rose-600 shrink-0"></i>

            <span>กำลังแสดงเฉพาะคำขอผู้ร่วมอาศัยของ: <span class="bg-rose-500 text-white px-2.5 py-0.5 rounded-lg text-xs">ห้อง <?php echo htmlspecialchars($filterRoomNumber); ?></span></span>

        </div>

        <a href="admin_coresidents.php" class="px-3.5 py-1.5 bg-white hover:bg-indigo-100 text-rose-700 rounded-xl text-xs font-bold border border-rose-200 transition shadow-2xs flex items-center gap-1">

            <i data-lucide="x" class="w-3.5 h-3.5"></i> แสดงทุกห้อง

        </a>

    </div>

    <?php endif; ?>



    <!-- Summary Stats Cards -->

    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 sm:gap-4 mb-8">

      <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">

        <div>

          <p class="text-xs font-semibold text-slate-500 mb-1">คำขอทั้งหมด</p>

          <h3 class="text-2xl font-bold font-display text-slate-900"><?php echo $totalCount; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></h3>

        </div>

        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center shrink-0">

          <i data-lucide="layers" class="w-5 h-5 sm:w-6 sm:h-6"></i>

        </div>

      </div>



      <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">

        <div>

          <p class="text-xs font-semibold text-slate-500 mb-1">รออนุมัติเข้าพัก</p>

          <h3 class="text-2xl font-bold font-display text-amber-500"><?php echo $countPending; ?> <span class="text-xs font-normal text-slate-400">คำขอ</span></h3>

          <p class="text-[10px] text-amber-600 font-medium mt-0.5">รอการตรวจสอบ</p>

        </div>

        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-amber-50 text-amber-500 flex items-center justify-center shrink-0">

          <i data-lucide="user-plus" class="w-5 h-5 sm:w-6 sm:h-6"></i>

        </div>

      </div>



      <div class="bg-white p-4 sm:p-5 rounded-2xl border border-amber-200 bg-amber-50/30 shadow-sm flex items-center justify-between">

        <div>

          <p class="text-xs font-semibold text-amber-800 mb-1">ขอยกเลิก</p>

          <h3 class="text-2xl font-bold font-display text-amber-600"><?php echo $countCancelPending; ?> <span class="text-xs font-normal text-slate-400">คำขอ</span></h3>

          <p class="text-[10px] text-amber-700 font-medium mt-0.5">ลูกบ้านขอยกเลิก</p>

        </div>

        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">

          <i data-lucide="user-x" class="w-5 h-5 sm:w-6 sm:h-6"></i>

        </div>

      </div>



      <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">

        <div>

          <p class="text-xs font-semibold text-slate-500 mb-1">อนุมัติแล้ว</p>

          <h3 class="text-2xl font-bold font-display text-emerald-600"><?php echo $countApproved; ?> <span class="text-xs font-normal text-slate-400">คน</span></h3>

          <p class="text-[10px] text-emerald-600 font-medium mt-0.5">พักอาศัยร่วม</p>

        </div>

        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">

          <i data-lucide="check-circle" class="w-5 h-5 sm:w-6 sm:h-6"></i>

        </div>

      </div>



      <div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between col-span-2 sm:col-span-1">

        <div>

          <p class="text-xs font-semibold text-slate-500 mb-1">ไม่อนุมัติ / ยกเลิก</p>

          <h3 class="text-2xl font-bold font-display text-slate-600"><?php echo $countInactive; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></h3>

          <p class="text-[10px] text-slate-400 font-medium mt-0.5">สิ้นสุด/ไม่ผ่าน</p>

        </div>

        <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center shrink-0">

          <i data-lucide="user-minus" class="w-5 h-5 sm:w-6 sm:h-6"></i>

        </div>

      </div>

    </div>



    <!-- Requests Table & Toolbar -->

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-8">

      <!-- Toolbar: Search & Filter Tabs -->

      <div class="p-4 sm:p-5 border-b border-slate-100 bg-slate-50/60 flex flex-col md:flex-row md:items-center justify-between gap-4">

        <!-- Search Input -->

        <div class="relative flex-1 max-w-md">

          <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>

          <input 

            type="text" 

            id="searchInput" 

            placeholder="ค้นหาเลขห้อง, ชื่อผู้เช่า, ผู้ร่วมอาศัย หรือเบอร์โทร..." 

            class="w-full pl-10 pr-9 py-2.5 text-sm bg-white border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100 transition shadow-sm text-slate-800 placeholder-slate-400"

            autocomplete="off"

          >

          <button 

            type="button" 

            id="clearSearchBtn" 

            onclick="clearSearch()" 

            class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 rounded-full hover:bg-slate-100 transition"

            title="ล้างคำค้นหา"

          >

            <i data-lucide="x" class="w-3.5 h-3.5"></i>

          </button>

        </div>



        <!-- Filter Status Tabs & Count -->

        <div class="flex items-center gap-3 justify-between md:justify-end">

          <div class="inline-flex p-1 bg-slate-200/70 rounded-xl text-xs font-semibold text-slate-600 flex-wrap">

            <button type="button" onclick="filterByStatus('all')" id="btnFilterAll" class="filter-tab px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-sm font-bold">

              ทั้งหมด (<?php echo $totalCount; ?>)

            </button>

            <button type="button" onclick="filterByStatus('Pending')" id="btnFilterPending" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-800">

              รออนุมัติ (<?php echo $countPending; ?>)

            </button>

            <button type="button" onclick="filterByStatus('Cancel_Pending')" id="btnFilterCancelPending" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-800">

              ขอยกเลิก (<?php echo $countCancelPending; ?>)

            </button>

            <button type="button" onclick="filterByStatus('Approved')" id="btnFilterApproved" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-800">

              อนุมัติแล้ว (<?php echo $countApproved; ?>)

            </button>

            <button type="button" onclick="filterByStatus('Inactive')" id="btnFilterInactive" class="filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-800">

              ไม่อนุมัติ/ยกเลิก (<?php echo $countInactive; ?>)

            </button>

          </div>

          <div class="text-xs text-slate-500 hidden sm:block whitespace-nowrap">

            แสดง <span id="visibleCount" class="font-bold text-rose-600"><?php echo $totalCount; ?></span> รายการ

          </div>

        </div>

      </div>



      <!-- Table -->

      <div class="overflow-x-auto">

        <table class="w-full text-left text-sm text-slate-600">

          <thead class="bg-slate-50 text-slate-700 font-semibold border-b border-slate-200 text-xs">

            <tr>

              <th class="p-4">ห้อง / ผู้เช่าหลัก</th>

              <th class="p-4">ผู้ร่วมอาศัย / ความสัมพันธ์</th>

              <th class="p-4">ข้อมูลติดต่อ & เอกสาร</th>

              <th class="p-4">วันที่ยื่นคำขอ</th>

              <th class="p-4">สถานะ</th>

              <th class="p-4 text-center">การจัดการ</th>

            </tr>

          </thead>

          <tbody class="divide-y divide-slate-100" id="requestsTableBody">

            <?php if(empty($requests)): ?>

              <tr>

                <td colspan="6" class="p-12 text-center text-slate-400">

                  ยังไม่มีคำขอผู้ร่วมอาศัยในระบบ

                </td>

              </tr>

            <?php else: ?>

              <?php foreach($requests as $r): 

                $searchKeywords = mb_strtolower($r['roomNumber'] . ' ' . $r['tenantName'] . ' ' . $r['fullName'] . ' ' . $r['relationship'] . ' ' . $r['phoneNumber'] . ' ' . $r['idCardNumber'] . ' ' . ($r['cancelReason'] ?? ''), 'UTF-8');

                $isPending = ($r['status'] === 'Pending');

                $isCancelPending = ($r['status'] === 'Cancel_Pending');

              ?>

              <tr class="coresident-row cursor-pointer hover:bg-rose-50/30/70 transition <?php echo $isCancelPending ? 'bg-amber-50/30 border-l-4 border-amber-400' : ($isPending ? 'bg-amber-50/20' : ''); ?>"
                  onclick="openCoresidentDetailModal(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>)"
                  data-search="<?php echo htmlspecialchars($searchKeywords, ENT_QUOTES, 'UTF-8'); ?>"
                  data-status="<?php echo htmlspecialchars($r['status']); ?>">

                

                <!-- ห้อง & ผู้เช่า -->

                <td class="p-4">

                  <div class="flex items-center gap-2 mb-1">

                    <span class="px-2 py-0.5 bg-slate-800 text-white rounded font-bold text-xs">

                       ห้อง <?php echo htmlspecialchars($r['roomNumber']); ?>

                    </span>

                  </div>

                  <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($r['tenantName']); ?></div>

                  <div class="text-[11px] text-slate-400">📞 <?php echo htmlspecialchars($r['tenantPhone'] ?? '-'); ?></div>

                </td>



                <!-- ข้อมูลผู้ร่วมอาศัย -->

                <td class="p-4">

                  <div class="font-bold text-slate-900 text-sm flex items-center gap-1.5">

                    <i data-lucide="user" class="w-4 h-4 text-indigo-500"></i>

                    <?php echo htmlspecialchars($r['fullName']); ?>

                  </div>

                  <div class="mt-1">

                    <span class="inline-block text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200 px-2 py-0.5 rounded-full">

                      <?php echo htmlspecialchars($r['relationship']); ?>

                    </span>

                  </div>

                </td>



                <!-- ข้อมูลติดต่อ & เอกสาร -->

                <td class="p-4">

                  <div class="text-xs text-slate-600">📞 <?php echo htmlspecialchars($r['phoneNumber']); ?></div>

                  <div class="text-[11px] text-slate-400 font-mono mt-0.5">บัตร: <?php echo htmlspecialchars($r['idCardNumber']); ?></div>

                  <?php if(!empty($r['idCardImage'])): ?>

                    <button type="button" onclick="openImageModal('<?php echo htmlspecialchars($r['idCardImage']); ?>', 'สำเนาบัตร - <?php echo htmlspecialchars($r['fullName']); ?> (ห้อง <?php echo htmlspecialchars($r['roomNumber']); ?>)')" class="mt-1.5 px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-semibold rounded-lg inline-flex items-center gap-1 transition">

                      <i data-lucide="file-image" class="w-3.5 h-3.5 text-slate-500"></i> ดูเอกสารแนบ

                    </button>

                  <?php else: ?>

                    <span class="text-[10px] text-slate-300 italic block mt-1">ไม่มีเอกสารแนบ</span>

                  <?php endif; ?>

                </td>



                <!-- วันที่ยื่น -->

                <td class="p-4 text-xs text-slate-500 font-mono">

                  <?php echo date("d/m/Y", strtotime($r['created_at'])); ?><br>

                  <span class="text-[10px] text-slate-400"><?php echo date("H:i", strtotime($r['created_at'])); ?> น.</span>

                </td>



                <!-- สถานะ & หมายเหตุ -->

                <td class="p-4">

                  <?php if($r['status'] === 'Approved'): ?>

                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold">

                      <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> อนุมัติแล้ว

                    </span>

                  <?php elseif($r['status'] === 'Cancel_Pending'): ?>

                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-100 text-amber-800 border border-amber-300 rounded-full text-xs font-bold animate-pulse">

                      <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-amber-600"></i> ลูกบ้านขอยกเลิก

                    </span>

                  <?php elseif($r['status'] === 'Pending'): ?>

                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold animate-pulse">

                      <i data-lucide="clock" class="w-3.5 h-3.5"></i> รออนุมัติเข้าพัก

                    </span>

                  <?php elseif($r['status'] === 'Cancelled'): ?>

                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-slate-100 text-slate-600 border border-slate-300 rounded-full text-xs font-bold">

                      <i data-lucide="user-minus" class="w-3.5 h-3.5"></i> ยกเลิกการเข้าพักแล้ว

                    </span>

                  <?php elseif($r['status'] === 'Rejected'): ?>

                    <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-full text-xs font-bold">

                      <i data-lucide="x-circle" class="w-3.5 h-3.5"></i> ไม่อนุมัติ

                    </span>

                  <?php endif; ?>



                  <?php if(!empty($r['cancelReason']) && ($r['status'] === 'Cancel_Pending' || $r['status'] === 'Cancelled')): ?>

                    <div class="mt-2 p-2 bg-amber-50 border border-amber-200 text-amber-900 rounded-xl text-xs">

                      <span class="font-bold flex items-center gap-1"><i data-lucide="info" class="w-3 h-3 text-amber-600"></i> เหตุผลที่ขอยกเลิก:</span>

                      <p class="mt-0.5 text-slate-700"><?php echo htmlspecialchars($r['cancelReason']); ?></p>

                    </div>

                  <?php endif; ?>



                  <?php if(!empty($r['adminRemark'])): ?>

                    <p class="text-[11px] text-slate-500 mt-1 max-w-xs truncate" title="<?php echo htmlspecialchars($r['adminRemark']); ?>">

                      💬 <?php echo htmlspecialchars($r['adminRemark']); ?>

                    </p>

                  <?php endif; ?>

                </td>



                <!-- การจัดการ -->

                <td class="p-4 text-center">

                  <div class="flex flex-col gap-1.5 w-32 mx-auto" onclick="event.stopPropagation()">

                    <button type="button" 
                            onclick="openCoresidentDetailModal(<?php echo htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8'); ?>)" 
                            class="w-full py-1.5 bg-white hover:bg-indigo-50 text-slate-700 hover:text-indigo-700 border border-slate-200 hover:border-indigo-300 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1 shadow-2xs cursor-pointer">
                      <i data-lucide="eye" class="w-3.5 h-3.5 text-indigo-600"></i> ดูรายละเอียด
                    </button>

                    <?php if($r['status'] === 'Pending'): ?>

                      <!-- ปุ่มอนุมัติเข้าพัก -->

                      <form action="" method="POST" onsubmit="return confirm('ยืนยันอนุมัติคำขอผู้ร่วมอาศัยนี้?');">

                        <input type="hidden" name="coresident_id" value="<?php echo $r['id']; ?>">

                        <button type="submit" name="approve_coresident" class="w-full py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-sm transition flex items-center justify-center gap-1">

                          <i data-lucide="check" class="w-3.5 h-3.5"></i> อนุมัติ

                        </button>

                      </form>



                      <!-- ปุ่มปฏิเสธ -->

                      <button type="button" onclick="openRejectModal('<?php echo $r['id']; ?>', '<?php echo htmlspecialchars($r['fullName']); ?>', '<?php echo htmlspecialchars($r['roomNumber']); ?>')" class="w-full py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1">

                        <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธ

                      </button>



                    <?php elseif($r['status'] === 'Cancel_Pending'): ?>

                      <!-- ปุ่มอนุมัติการยกเลิก -->

                      <form action="" method="POST" onsubmit="return confirm('ยืนยันอนุมัติการยกเลิกผู้ร่วมอาศัยตามคำขอนี้หรือไม่?');">

                        <input type="hidden" name="coresident_id" value="<?php echo $r['id']; ?>">

                        <button type="submit" name="approve_cancellation" class="w-full py-1.5 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold shadow-sm transition flex items-center justify-center gap-1">

                          <i data-lucide="check" class="w-3.5 h-3.5"></i> อนุมัติยกเลิก

                        </button>

                      </form>



                      <!-- ปุ่มปฏิเสธการยกเลิก (คงให้อยู่ต่อ) -->

                      <form action="" method="POST" onsubmit="return confirm('ยืนยันปฏิเสธคำขอยกเลิก (ให้ผู้ร่วมอาศัยคงสถานะพักอาศัยต่อไป)?');">

                        <input type="hidden" name="coresident_id" value="<?php echo $r['id']; ?>">

                        <button type="submit" name="reject_cancellation" class="w-full py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-semibold transition flex items-center justify-center gap-1">

                          <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธยกเลิก

                        </button>

                      </form>



                    <?php elseif($r['status'] === 'Approved'): ?>

                      <button type="button" onclick="openRejectModal('<?php echo $r['id']; ?>', '<?php echo htmlspecialchars($r['fullName']); ?>', '<?php echo htmlspecialchars($r['roomNumber']); ?>')" class="w-full py-1 bg-slate-100 hover:bg-rose-50 text-slate-600 hover:text-rose-700 rounded-lg text-[11px] font-semibold transition">

                        เพิกถอนสิทธิ์

                      </button>



                    <?php elseif($r['status'] === 'Cancelled' || $r['status'] === 'Rejected'): ?>

                      <form action="" method="POST" onsubmit="return confirm('ต้องการเปลี่ยนสถานะกลับเป็นอนุมัติให้พักอาศัยใช่หรือไม่?');">

                        <input type="hidden" name="coresident_id" value="<?php echo $r['id']; ?>">

                        <button type="submit" name="approve_coresident" class="w-full py-1 bg-slate-100 hover:bg-emerald-50 text-slate-600 hover:text-emerald-700 rounded-lg text-[11px] font-semibold transition">

                          เปลี่ยนเป็นอนุมัติ

                        </button>

                      </form>

                    <?php endif; ?>



                    <form action="" method="POST" onsubmit="return confirm('แน่ใจหรือไม่ที่จะลบรายการนี้ออกจากระบบ?');">

                      <input type="hidden" name="coresident_id" value="<?php echo $r['id']; ?>">

                      <button type="submit" name="delete_coresident" class="w-full py-1 text-slate-400 hover:text-rose-600 text-[10px] transition">

                        ลบรายการ

                      </button>

                    </form>

                  </div>

                </td>

              </tr>

              <?php endforeach; ?>



              <!-- Empty state row for search filtering -->

              <tr id="noResultsRow" class="hidden">

                <td colspan="6" class="py-12 px-4 text-center">

                  <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3 text-slate-400">

                    <i data-lucide="search-x" class="w-7 h-7"></i>

                  </div>

                  <p class="text-sm font-bold text-slate-700">ไม่พบคำขอผู้ร่วมอาศัยที่ตรงกับคำค้นหา</p>

                  <p class="text-xs text-slate-400 mt-1">ลองตรวจสอบหมายเลขห้องหรือชื่อใหม่อีกครั้ง</p>

                  <button type="button" onclick="clearSearch()" class="mt-3.5 px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg text-xs font-semibold inline-flex items-center gap-1.5 transition">

                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> ล้างคำค้นหา

                  </button>

                </td>

              </tr>

            <?php endif; ?>

          </tbody>

        </table>

      </div>

    </div>

  </main>



  <!-- MODAL: ปฏิเสธคำขอ (ระบุเหตุผล) -->

  <div id="rejectModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">

    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 modal-enter">

      <div class="flex items-center justify-between pb-3 border-b border-slate-100 mb-4">

        <h3 class="font-display font-bold text-slate-900 text-base flex items-center gap-2">

          <i data-lucide="x-circle" class="w-5 h-5 text-rose-500"></i> ปฏิเสธคำขอผู้ร่วมอาศัย

        </h3>

        <button onclick="closeRejectModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-full hover:bg-slate-100">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="space-y-4">

        <input type="hidden" name="coresident_id" id="rejectId" value="">

        <p class="text-xs text-slate-600">

          ปฏิเสธคำขอของ: <strong id="rejectName" class="text-slate-900"></strong> (ห้อง <span id="rejectRoom"></span>)

        </p>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">เหตุผลที่ไม่อนุมัติ (ลูกบ้านจะเห็นข้อความนี้)</label>

          <textarea name="admin_remark" rows="3" required placeholder="เช่น เอกสารบัตรประชาชนไม่ชัดเจน, ไม่ผ่านเกณฑ์ระเบียบหอพัก ฯลฯ" class="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500 focus:bg-white transition"></textarea>

        </div>



        <div class="flex gap-2 pt-2">

          <button type="button" onclick="closeRejectModal()" class="flex-1 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition">

            ยกเลิก

          </button>

          <button type="submit" name="reject_coresident" class="flex-1 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-md transition">

            ยืนยันปฏิเสธคำขอ

          </button>

        </div>

      </form>

    </div>

  </div>

  <!-- MODAL: รายละเอียดผู้ร่วมอาศัย (2 Columns) -->
  <div id="coresidentDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4 overflow-y-auto">
    <div class="bg-white w-full max-w-4xl max-h-[92vh] rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">
      <!-- Header -->
      <div class="px-5 py-4 sm:px-6 sm:py-5 border-b border-slate-200/80 flex justify-between items-center bg-slate-50/70 shrink-0">
        <div class="flex items-center gap-3">
          <span id="co_modal_room_badge" class="px-3 py-1 bg-slate-900 text-white rounded-xl font-bold text-sm shadow-xs font-mono">ห้อง -</span>
          <span id="co_modal_name_title" class="font-display font-extrabold text-slate-900 text-base sm:text-lg">ข้อมูลผู้ร่วมอาศัย</span>
          <span id="co_modal_status_badge" class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">รออนุมัติ</span>
        </div>
        <button type="button" onclick="closeCoresidentDetailModal()" class="w-9 h-9 rounded-2xl bg-white/80 backdrop-blur-md p-1.5 border border-rose-200/60 shadow-xs hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shadow-2xs" title="ปิด">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <!-- Body 2 columns -->
      <div class="p-4 sm:p-6 overflow-y-auto flex-1 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
          <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลของผู้ร่วมอาศัย -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-indigo-200 bg-gradient-to-b from-indigo-50/40 to-slate-50/20 shadow-2xs space-y-4">
            <div class="flex items-center gap-2.5 pb-3.5 border-b border-indigo-200/80">
              <div class="w-9 h-9 rounded-2xl bg-indigo-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                <i data-lucide="user-check" class="w-5 h-5"></i>
              </div>
              <div>
                <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลผู้ร่วมอาศัย</h4>
                <p class="text-[11px] text-indigo-700 font-medium">รายละเอียดส่วนตัวและเอกสารประจำตัว</p>
              </div>
            </div>
            <!-- ผู้ร่วมอาศัย Card -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs flex items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <div id="co_modal_avatar" class="w-11 h-11 rounded-2xl bg-indigo-50 border border-indigo-200 text-indigo-700 font-extrabold flex items-center justify-center text-base">
                  ผ
                </div>
                <div>
                  <span class="text-[10px] text-slate-400 font-semibold uppercase">ผู้ร่วมอาศัย</span>
                  <div id="co_modal_fullname" class="font-bold text-slate-900 text-sm sm:text-base">-</div>
                  <div class="flex items-center gap-2 mt-1">
                    <span id="co_modal_relationship" class="text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200 px-2 py-0.5 rounded-full">-</span>
                  </div>
                </div>
              </div>
              <a id="co_modal_call_coresident_btn" href="#" class="px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                <i data-lucide="phone-call" class="w-3.5 h-3.5"></i> โทรหา
              </a>
            </div>
            <!-- ข้อมูลติดต่อ & เลขบัตร -->
            <div class="bg-white p-4 rounded-2xl border border-indigo-200/80 shadow-2xs space-y-2.5 text-xs text-slate-600">
              <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                <span class="text-slate-400">เบอร์โทรศัพท์:</span>
                <strong id="co_modal_phone" class="text-slate-900 font-mono font-bold">-</strong>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-slate-400">เลขบัตรประชาชน / Passport:</span>
                <strong id="co_modal_idcard_num" class="text-slate-900 font-mono font-bold">-</strong>
              </div>
            </div>
            <!-- เอกสารสำเนาบัตร -->
            <div id="co_modal_idcard_wrap" class="bg-white border-2 border-dashed border-indigo-300 rounded-2xl p-3.5 shadow-2xs">
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold text-indigo-900 flex items-center gap-1.5">
                  <i data-lucide="id-card" class="w-4 h-4 text-indigo-600"></i> สำเนาบัตรประชาชน / เอกสารประจำตัว
                </span>
                <span class="text-[10px] bg-indigo-100 text-indigo-900 font-extrabold px-2 py-0.5 rounded-lg border border-indigo-200">ID CARD</span>
              </div>
              <div id="co_modal_idcard_box" class="min-h-[140px] flex items-center justify-center bg-stone-50 rounded-xl overflow-hidden">
              </div>
            </div>
          </div>

          <!-- 🟩 กรอบที่ 2: ส่วนข้อมูลห้องพัก & นิติบุคคล -->
          <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">
            <div>
              <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">
                    <i data-lucide="home" class="w-5 h-5"></i>
                  </div>
                  <div>
                    <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลห้อง & นิติบุคคล</h4>
                    <p class="text-[11px] text-emerald-700 font-medium">ห้องพัก ผู้เช่าหลัก วันที่ยื่น และผลการพิจารณา</p>
                  </div>
                </div>
                <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">
                  นิติบุคคล
                </span>
              </div>

              <!-- ข้อมูลผู้เช่าหลัก -->
              <div class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs flex items-center justify-between gap-3 mb-4">
                <div>
                  <span class="text-[10px] text-slate-400 font-semibold uppercase">ผู้เช่าหลักประจำห้อง</span>
                  <div id="co_modal_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</div>
                  <div id="co_modal_tenant_phone" class="text-xs text-slate-500 font-mono mt-0.5">📞 -</div>
                </div>
                <a id="co_modal_call_tenant_btn" href="#" class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 rounded-xl text-xs font-bold transition flex items-center gap-1 shadow-2xs">
                  <i data-lucide="phone-call" class="w-3.5 h-3.5"></i> โทรหาผู้เช่าหลัก
                </a>
              </div>

              <!-- กล่องสถานะ & วันที่ยื่น -->
              <div class="p-4 rounded-2xl border border-slate-200 bg-white shadow-2xs space-y-2.5 text-xs text-slate-600 mb-4">
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-400">สถานะคำขอ:</span>
                  <span id="co_modal_status_text" class="font-bold">-</span>
                </div>
                <div class="flex items-center justify-between pb-2 border-b border-slate-100">
                  <span class="text-slate-400">วันที่ยื่นคำขอ:</span>
                  <span id="co_modal_created_at" class="font-mono text-slate-800 font-bold">-</span>
                </div>
                <div id="co_modal_cancel_reason_wrap" class="hidden pb-2 border-b border-slate-100">
                  <span class="text-rose-600 font-bold block mb-1">เหตุผลที่ขอยกเลิก:</span>
                  <div id="co_modal_cancel_reason_text" class="p-2.5 bg-rose-50 rounded-xl border border-rose-200 text-rose-800 text-xs">-</div>
                </div>
                <div id="co_modal_remark_wrap" class="hidden">
                  <span class="text-slate-400 font-bold block mb-1">หมายเหตุ / เหตุผลจากนิติบุคคล:</span>
                  <div id="co_modal_remark_text" class="p-2.5 bg-stone-50 rounded-xl border border-stone-200 text-slate-700 text-xs">-</div>
                </div>
              </div>
            </div>

            <!-- Actions Area -->
            <div id="co_modal_actions" class="pt-2">
            </div>
          </div>
        </div>
      </div>
      <!-- Footer -->
      <div class="px-5 py-4 border-t border-slate-200/80 bg-white flex items-center justify-between shrink-0">
        <button type="button" onclick="closeCoresidentDetailModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-2xl text-xs sm:text-sm transition cursor-pointer">
          ปิดหน้าต่าง
        </button>
        <div id="co_modal_footer_right" class="flex gap-2">
        </div>
      </div>
    </div>
  </div>



  <!-- MODAL: Standard Document/Image Viewer -->
  <?php include_once 'includes/doc_viewer_modal.php'; ?>



  <script>

    lucide.createIcons();



    // ระบบกรองและค้นหา

    let currentStatusFilter = 'all';



    function filterByStatus(status) {

      currentStatusFilter = status;

      const tabs = {

        'all': document.getElementById('btnFilterAll'),

        'Pending': document.getElementById('btnFilterPending'),

        'Cancel_Pending': document.getElementById('btnFilterCancelPending'),

        'Approved': document.getElementById('btnFilterApproved'),

        'Inactive': document.getElementById('btnFilterInactive')

      };



      Object.keys(tabs).forEach(key => {

        const btn = tabs[key];

        if (btn) {

          if (key === status) {

            btn.className = 'filter-tab px-3 py-1.5 rounded-lg transition bg-white text-slate-900 shadow-sm font-bold';

          } else {

            btn.className = 'filter-tab px-3 py-1.5 rounded-lg transition text-slate-500 hover:text-slate-800';

          }

        }

      });

      applyFilters();

    }



    function applyFilters() {

      const input = document.getElementById('searchInput');

      const query = (input ? input.value : '').trim().toLowerCase();

      const clearBtn = document.getElementById('clearSearchBtn');

      const rows = document.querySelectorAll('.coresident-row');

      let visible = 0;



      if (clearBtn) {

        if (query.length > 0) {

          clearBtn.classList.remove('hidden');

        } else {

          clearBtn.classList.add('hidden');

        }

      }



      rows.forEach(row => {

        const searchData = (row.getAttribute('data-search') || '').toLowerCase();

        const status = row.getAttribute('data-status') || '';



        const matchQuery = query === '' || searchData.includes(query);

        let matchStatus = false;

        if (currentStatusFilter === 'all') {

          matchStatus = true;

        } else if (currentStatusFilter === 'Inactive') {

          matchStatus = (status === 'Rejected' || status === 'Cancelled');

        } else {

          matchStatus = (status === currentStatusFilter);

        }



        if (matchQuery && matchStatus) {

          row.style.display = '';

          visible++;

        } else {

          row.style.display = 'none';

        }

      });



      const visibleElem = document.getElementById('visibleCount');

      if (visibleElem) visibleElem.innerText = visible;



      const noResults = document.getElementById('noResultsRow');

      if (noResults) {

        if (visible === 0 && rows.length > 0) {

          noResults.classList.remove('hidden');

        } else {

          noResults.classList.add('hidden');

        }

      }

    }



    function clearSearch() {

      const input = document.getElementById('searchInput');

      if (input) {

        input.value = '';

        input.focus();

      }

      applyFilters();

    }



    const searchInput = document.getElementById('searchInput');

    if (searchInput) {

      searchInput.addEventListener('input', applyFilters);

    }



    // Modal ปฏิเสธ

    function openRejectModal(id, name, room) {

      document.getElementById('rejectId').value = id;

      document.getElementById('rejectName').innerText = name;

      document.getElementById('rejectRoom').innerText = room;

      document.getElementById('rejectModal').classList.remove('hidden');

    }

    function closeRejectModal() {

      document.getElementById('rejectModal').classList.add('hidden');

    }

    // 🌟 ฟังก์ชันเปิด Modal ดูรายละเอียดผู้ร่วมอาศัย (แบบ 2 กรอบ) 🌟
    function openCoresidentDetailModal(data) {
      if (!data) return;

      function fmtDate(d) {
        if (!d) return '-';
        const clean = d.split(' ')[0];
        const parts = clean.split('-');
        if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
        return d;
      }

      document.getElementById('co_modal_room_badge').innerText = `ห้อง ${data.roomNumber || '-'}`;
      document.getElementById('co_modal_name_title').innerText = `ผู้ร่วมอาศัย: ${data.fullName || '-'}`;

      const statusBadge = document.getElementById('co_modal_status_badge');
      const statusText = document.getElementById('co_modal_status_text');
      const isPending = (data.status === 'Pending');
      const isCancelPending = (data.status === 'Cancel_Pending');
      const isApproved = (data.status === 'Approved');
      const isRejected = (data.status === 'Rejected');

      if (isPending) {
        statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 animate-pulse';
        statusBadge.innerHTML = 'รออนุมัติเข้าพัก';
        statusText.innerText = 'รออนุมัติเข้าพัก';
        statusText.className = 'font-bold text-amber-600';
      } else if (isCancelPending) {
        statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200 animate-pulse';
        statusBadge.innerHTML = 'รออนุมัติการยกเลิก';
        statusText.innerText = 'รออนุมัติการยกเลิก';
        statusText.className = 'font-bold text-rose-600';
      } else if (isApproved) {
        statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200';
        statusBadge.innerHTML = 'อนุมัติแล้ว';
        statusText.innerText = 'อนุมัติแล้ว (มีสิทธิ์พักอาศัย)';
        statusText.className = 'font-bold text-emerald-600';
      } else if (isRejected) {
        statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200';
        statusBadge.innerHTML = 'ไม่อนุมัติ';
        statusText.innerText = 'ไม่อนุมัติ';
        statusText.className = 'font-bold text-rose-600';
      } else {
        statusBadge.className = 'text-xs font-bold px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 border border-slate-200';
        statusBadge.innerHTML = 'ยกเลิกแล้ว';
        statusText.innerText = 'ยกเลิกแล้ว (สิ้นสุดการพักอาศัย)';
        statusText.className = 'font-bold text-slate-500';
      }

      // Left Column
      const fullName = data.fullName || '-';
      document.getElementById('co_modal_fullname').innerText = fullName;
      document.getElementById('co_modal_avatar').innerText = fullName.charAt(0);
      document.getElementById('co_modal_relationship').innerText = data.relationship || '-';
      document.getElementById('co_modal_phone').innerText = data.phoneNumber || '-';
      document.getElementById('co_modal_idcard_num').innerText = data.idCardNumber || '-';

      const callCoBtn = document.getElementById('co_modal_call_coresident_btn');
      if (data.phoneNumber && data.phoneNumber !== '-') {
        callCoBtn.href = `tel:${data.phoneNumber}`;
        callCoBtn.classList.remove('hidden');
      } else {
        callCoBtn.classList.add('hidden');
      }

      // ID Card Image
      const idCardBox = document.getElementById('co_modal_idcard_box');
      if (data.idCardImage && String(data.idCardImage).trim() !== '') {
        idCardBox.innerHTML = `
          <div class="relative group cursor-pointer w-full rounded-xl overflow-hidden border border-indigo-200 shadow-sm" onclick="openDocModal('${data.idCardImage}', 'สำเนาบัตร - ${data.fullName} (ห้อง ${data.roomNumber})')">
            <img src="${data.idCardImage}" class="w-full max-h-[160px] object-cover transition duration-300 group-hover:scale-105" alt="สำเนาบัตร">
            <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2 text-white font-bold text-xs backdrop-blur-2xs">
              <i data-lucide="zoom-in" class="w-4 h-4"></i> คลิกดูภาพขยาย
            </div>
          </div>
        `;
      } else {
        idCardBox.innerHTML = '<span class="text-xs text-slate-400 italic py-4">ไม่มีเอกสารสำเนาบัตรแนบในระบบ</span>';
      }

      // Right Column: Main Tenant
      document.getElementById('co_modal_tenant_name').innerText = data.tenantName || '-';
      document.getElementById('co_modal_tenant_phone').innerText = `📞 ${data.tenantPhone || '-'}`;
      const callTenantBtn = document.getElementById('co_modal_call_tenant_btn');
      if (data.tenantPhone && data.tenantPhone !== '-') {
        callTenantBtn.href = `tel:${data.tenantPhone}`;
        callTenantBtn.classList.remove('hidden');
      } else {
        callTenantBtn.classList.add('hidden');
      }

      document.getElementById('co_modal_created_at').innerText = `${fmtDate(data.created_at)} ${data.created_at ? data.created_at.split(' ')[1] || '' : ''}`;

      // Cancel Reason
      const cancelWrap = document.getElementById('co_modal_cancel_reason_wrap');
      const cancelText = document.getElementById('co_modal_cancel_reason_text');
      if (data.cancelReason && String(data.cancelReason).trim() !== '') {
        cancelWrap.classList.remove('hidden');
        cancelText.innerText = data.cancelReason;
      } else {
        cancelWrap.classList.add('hidden');
      }

      // Admin Remark
      const remarkWrap = document.getElementById('co_modal_remark_wrap');
      const remarkText = document.getElementById('co_modal_remark_text');
      if (data.adminRemark && String(data.adminRemark).trim() !== '') {
        remarkWrap.classList.remove('hidden');
        remarkText.innerText = data.adminRemark;
      } else {
        remarkWrap.classList.add('hidden');
      }

      // Footer actions
      const footerRight = document.getElementById('co_modal_footer_right');
      footerRight.innerHTML = '';

      if (isPending) {
        footerRight.innerHTML = `
          <button type="button" onclick="closeCoresidentDetailModal(); openRejectModal('${data.id}', '${data.fullName}', '${data.roomNumber}')" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-2xl text-xs font-bold transition flex items-center gap-1 cursor-pointer">
            <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธ
          </button>
          <form action="" method="POST" onsubmit="return confirm('ยืนยันอนุมัติคำขอผู้ร่วมอาศัยนี้?');" class="inline">
            <input type="hidden" name="coresident_id" value="${data.id}">
            <button type="submit" name="approve_coresident" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-xs font-bold transition flex items-center gap-1.5 shadow-md cursor-pointer">
              <i data-lucide="check" class="w-4 h-4"></i> อนุมัติเข้าพัก
            </button>
          </form>
        `;
      } else if (isCancelPending) {
        footerRight.innerHTML = `
          <form action="" method="POST" onsubmit="return confirm('ยืนยันปฏิเสธคำขอยกเลิก (ให้ผู้ร่วมอาศัยคงสถานะพักอาศัยต่อไป)?');" class="inline">
            <input type="hidden" name="coresident_id" value="${data.id}">
            <button type="submit" name="reject_cancellation" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-2xl text-xs font-bold transition flex items-center gap-1 cursor-pointer">
              <i data-lucide="x" class="w-3.5 h-3.5"></i> ปฏิเสธยกเลิก
            </button>
          </form>
          <form action="" method="POST" onsubmit="return confirm('ยืนยันอนุมัติการยกเลิกผู้ร่วมอาศัยตามคำขอนี้หรือไม่?');" class="inline">
            <input type="hidden" name="coresident_id" value="${data.id}">
            <button type="submit" name="approve_cancellation" class="px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-2xl text-xs font-bold transition flex items-center gap-1.5 shadow-md cursor-pointer">
              <i data-lucide="check" class="w-4 h-4"></i> อนุมัติการยกเลิก
            </button>
          </form>
        `;
      } else if (isApproved) {
        footerRight.innerHTML = `
          <button type="button" onclick="closeCoresidentDetailModal(); openRejectModal('${data.id}', '${data.fullName}', '${data.roomNumber}')" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-2xl text-xs font-bold transition flex items-center gap-1 cursor-pointer">
            เพิกถอนสิทธิ์
          </button>
        `;
      } else {
        footerRight.innerHTML = `
          <form action="" method="POST" onsubmit="return confirm('ต้องการเปลี่ยนสถานะกลับเป็นอนุมัติให้พักอาศัยใช่หรือไม่?');" class="inline">
            <input type="hidden" name="coresident_id" value="${data.id}">
            <button type="submit" name="approve_coresident" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl text-xs font-bold transition flex items-center gap-1.5 shadow-md cursor-pointer">
              <i data-lucide="check" class="w-4 h-4"></i> เปลี่ยนเป็นอนุมัติ
            </button>
          </form>
        `;
      }

      const modal = document.getElementById('coresidentDetailModal');
      modal.classList.remove('hidden');
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      if (window.lucide) lucide.createIcons();
    }

    function closeCoresidentDetailModal() {
      const modal = document.getElementById('coresidentDetailModal');
      if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
      }
      document.body.style.overflow = '';
    }

    // Escape listener
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeRejectModal();
        closeCoresidentDetailModal();
        if (typeof closeDocModal === 'function') closeDocModal();
      }
    });





  </script>

</body>

</html>

