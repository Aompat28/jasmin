<?php



session_start();



require_once 'includes/db_connect.php';



// ตรวจสอบสิทธิ์ ต้องเป็น แอดมิน (Admin) เท่านั้น



if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {



    header("Location: login.php");



    exit();



}



$adminName = $_SESSION['full_name'];



$initials = strtoupper(mb_substr($adminName, 0, 2, 'UTF-8'));



$message = "";



// --- 🌟 1. ระบบมอบหมายงานให้พนักงาน (Assign Task พร้อมตรวจสอบสถานะว่าง/ติดงาน) 🌟 ---



if (isset($_POST['assign_task'])) {



    $requestId = intval($_POST['request_id']);



    $assignedTo = !empty($_POST['assigned_to']) ? intval($_POST['assigned_to']) : null;



    $estimatedHours = isset($_POST['estimated_hours']) ? max(1, min(4, intval($_POST['estimated_hours']))) : 2;



    if ($assignedTo) {



        // ตรวจสอบว่าพนักงานคนนี้ติดงาน In Progress อื่นอยู่หรือไม่ (ป้องกันการรับงานซ้อน)



        $stmtCheckBusy = $conn->prepare("



            SELECT sr.requestId, r.roomNumber, sr.category, sr.assignedAt, COALESCE(sr.estimatedHours, 2) AS estimatedHours 



            FROM ServiceRequest sr 



            JOIN Room r ON sr.roomId = r.roomId 



            WHERE sr.assignedTo = ? AND sr.status = 'In Progress' AND sr.requestId != ?



            LIMIT 1



        ");



        $stmtCheckBusy->execute([$assignedTo, $requestId]);



        $busyJob = $stmtCheckBusy->fetch(PDO::FETCH_ASSOC);



        if ($busyJob) {



            $busyStarted = !empty($busyJob['assignedAt']) ? date('H:i', strtotime($busyJob['assignedAt'])) : '-';



            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>



                <i data-lucide='alert-octagon' class='w-5 h-5 shrink-0 text-rose-600'></i>



                <span>ไม่สามารถมอบหมายงานได้! พนักงานท่านนี้กำลังติดงานห้อง <b>{$busyJob['roomNumber']}</b> อยู่ (รับงานเมื่อ {$busyStarted} น. • กำหนดเวลา {$busyJob['estimatedHours']} ชม.) กรุณารอให้พนักงานส่งงานเสร็จ หรือเลือกช่าง/แม่บ้านท่านอื่นที่ว่าง</span>



            </div>";



        } else {



            // ดึงชื่อพนักงานเพื่อแสดงในแจ้งเตือน



            $stmtStaff = $conn->prepare("SELECT fullName, role FROM User WHERE userId = ?");



            $stmtStaff->execute([$assignedTo]);



            $staffInfo = $stmtStaff->fetch(PDO::FETCH_ASSOC);



            $staffName = $staffInfo ? $staffInfo['fullName'] : "พนักงาน";



            $staffRoleTh = ($staffInfo && in_array($staffInfo['role'], ['Cleaner', 'Housekeeper'])) ? "แม่บ้าน" : "ช่างซ่อมบำรุง";



            $stmt = $conn->prepare("UPDATE ServiceRequest SET assignedTo = ?, status = 'In Progress', assignedAt = COALESCE(assignedAt, NOW()), completedAt = NULL, estimatedHours = ? WHERE requestId = ?");



            if ($stmt->execute([$assignedTo, $estimatedHours, $requestId])) {



                $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>



                    <i data-lucide='check-circle' class='w-5 h-5 shrink-0 text-emerald-600'></i>



                    <span>มอบหมายงานให้ <b>{$staffName} ({$staffRoleTh})</b> เรียบร้อยแล้ว! กำหนดเวลาปฏิบัติงาน <b>{$estimatedHours} ชั่วโมง</b> (สถานะพนักงานเปลี่ยนเป็น 'ติดงาน')</span>



                </div>";



            }



        }



    } else {



        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>



            <i data-lucide='alert-triangle' class='w-5 h-5 shrink-0 text-amber-600'></i>



            <span>กรุณาเลือกพนักงานที่ต้องการมอบหมายงาน</span>



        </div>";



    }



}



// --- 🌟 2. ระบบยกเลิกการมอบหมายงาน (กลับสู่สถานะ Pending และคืนสถานะพนักงานเป็นว่าง) 🌟 ---



if (isset($_POST['unassign_task'])) {



    $requestId = intval($_POST['request_id']);



    $stmt = $conn->prepare("UPDATE ServiceRequest SET assignedTo = NULL, status = 'Pending', assignedAt = NULL, completedAt = NULL, estimatedHours = 2 WHERE requestId = ?");



    if ($stmt->execute([$requestId])) {



        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>



            <i data-lucide='refresh-cw' class='w-5 h-5 shrink-0 text-amber-600'></i>



            <span>ยกเลิกการมอบหมายงานเรียบร้อยแล้ว รายการกลับสู่สถานะ 'รอมอบหมาย' และพนักงานเปลี่ยนสถานะเป็น 'ว่าง' พร้อมรับงานใหม่ทันที</span>



        </div>";



    }



}



// --- 🌟 2.2 ระบบทำเครื่องหมายเสร็จสิ้นงานโดยแอดมิน (Admin Complete Task) 🌟 ---
if (isset($_POST['admin_complete_task'])) {
    $requestId = intval($_POST['request_id']);
    $adminComment = trim($_POST['admin_comment'] ?? '');

    $completionImagePath = null;
    if (isset($_FILES['completion_image']) && $_FILES['completion_image']['error'] == 0) {
        $uploadDir = 'uploads/completions/';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }
        $fileName = time() . '_admin_' . basename($_FILES['completion_image']['name']);
        $targetFilePath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['completion_image']['tmp_name'], $targetFilePath)) {
            $completionImagePath = $targetFilePath;
        }
    }

    if ($completionImagePath) {
        if (!empty($adminComment)) {
            $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Completed', completionImage = ?, completedAt = COALESCE(completedAt, NOW()), staffComment = ?, staffCommentedAt = COALESCE(staffCommentedAt, NOW()) WHERE requestId = ?");
            $stmt->execute([$completionImagePath, $adminComment, $requestId]);
        } else {
            $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Completed', completionImage = ?, completedAt = COALESCE(completedAt, NOW()) WHERE requestId = ?");
            $stmt->execute([$completionImagePath, $requestId]);
        }
    } else {
        if (!empty($adminComment)) {
            $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Completed', completedAt = COALESCE(completedAt, NOW()), staffComment = ?, staffCommentedAt = COALESCE(staffCommentedAt, NOW()) WHERE requestId = ?");
            $stmt->execute([$adminComment, $requestId]);
        } else {
            $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Completed', completedAt = COALESCE(completedAt, NOW()) WHERE requestId = ?");
            $stmt->execute([$requestId]);
        }
    }

    $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>
        <i data-lucide='check-circle-2' class='w-5 h-5 shrink-0 text-emerald-600'></i>
        <span>อัปเดตสถานะงานเป็น <b>'เสร็จสิ้นเรียบร้อย' (Completed)</b> สำเร็จ! ระบบได้อัปเดตสถานะให้ผู้เช่า แอดมิน และช่างเห็นตรงกันแล้ว</span>
    </div>";
}

// --- 🌟 2.3 ระบบอนุมัติงานที่ช่างส่งมอบ (Admin Approve Job) 🌟 ---
if (isset($_POST['approve_job'])) {
    $requestId = intval($_POST['request_id']);
    $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'Completed', adminApprovedAt = NOW(), adminRemark = NULL WHERE requestId = ?");
    if ($stmt->execute([$requestId])) {
        $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>
            <i data-lucide='check-check' class='w-5 h-5 shrink-0 text-emerald-600'></i>
            <span>อนุมัติการส่งมอบงานเรียบร้อยแล้ว! สถานะเปลี่ยนเป็น <b>'เสร็จสิ้นเรียบร้อย' (Completed)</b> และผู้เช่าสามารถดูผลงานและให้คะแนนการบริการได้ทันที</span>
        </div>";
    }
}

// --- 🌟 2.4 ระบบส่งงานกลับให้ช่างแก้ไข (Admin Reject / Return Job) 🌟 ---
if (isset($_POST['reject_job'])) {
    $requestId = intval($_POST['request_id']);
    $adminRemark = trim($_POST['admin_remark'] ?? '');
    $stmt = $conn->prepare("UPDATE ServiceRequest SET status = 'In Progress', adminRemark = ? WHERE requestId = ?");
    if ($stmt->execute([$adminRemark, $requestId])) {
        $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-800 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'>
            <i data-lucide='rotate-ccw' class='w-5 h-5 shrink-0 text-amber-600'></i>
            <span>ส่งงานกลับให้พนักงานแก้ไขเรียบร้อยแล้ว สถานะกลับสู่ <b>'กำลังดำเนินการ'</b> พร้อมบันทึกข้อความแจ้งเตือนช่าง/แม่บ้าน</span>
        </div>";
    }
}


// --- 3. ระบบลบ/ปฏิเสธงาน (กรณีลูกบ้านแจ้งผิด) ---



if (isset($_POST['delete_task'])) {



    $requestId = $_POST['request_id'];



    $stmt = $conn->prepare("DELETE FROM ServiceRequest WHERE requestId = ?");



    if ($stmt->execute([$requestId])) {



        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ลบรายการแจ้งซ่อมเรียบร้อยแล้ว</div>";



    }



}



// --- ดึงข้อมูลการแจ้งซ่อม (รองรับการแยกกรองตามห้อง roomId) ---



$filterRoomId = isset($_GET['roomId']) ? intval($_GET['roomId']) : null;



$filterRoomNumber = null;



if ($filterRoomId) {



    $stmtRoomInfo = $conn->prepare("SELECT roomNumber FROM Room WHERE roomId = ?");



    $stmtRoomInfo->execute([$filterRoomId]);



    $filterRoomNumber = $stmtRoomInfo->fetchColumn();



    $stmtReq = $conn->prepare("



        SELECT sr.*, r.roomNumber, 



               u.fullName AS tenantName, u.phone AS tenantPhone,



               staff.fullName AS staffName, staff.role AS staffRole, staff.phone AS staffPhone



        FROM ServiceRequest sr
        LEFT JOIN Room r ON sr.roomId = r.roomId
        LEFT JOIN User u ON sr.tenantId = u.userId



        LEFT JOIN User staff ON sr.assignedTo = staff.userId



        WHERE sr.roomId = ?



        ORDER BY FIELD(sr.status, 'Pending Approval', 'Pending', 'In Progress', 'Completed'), sr.requestId DESC



    ");



    $stmtReq->execute([$filterRoomId]);



} else {



    $stmtReq = $conn->prepare("



        SELECT sr.*, r.roomNumber, 



               u.fullName AS tenantName, u.phone AS tenantPhone,



               staff.fullName AS staffName, staff.role AS staffRole, staff.phone AS staffPhone



        FROM ServiceRequest sr
        LEFT JOIN Room r ON sr.roomId = r.roomId
        LEFT JOIN User u ON sr.tenantId = u.userId



        LEFT JOIN User staff ON sr.assignedTo = staff.userId



        ORDER BY FIELD(sr.status, 'Pending Approval', 'Pending', 'In Progress', 'Completed'), sr.requestId DESC



    ");



    $stmtReq->execute();



}



$requests = $stmtReq->fetchAll(PDO::FETCH_ASSOC);



// ดึงรายชื่อพนักงานที่มีสิทธิ์รับงาน (ช่างซ่อมบำรุง / แม่บ้าน)



$stmtStaffList = $conn->query("



    SELECT userId, fullName, role, phone 



    FROM User 



    WHERE role IN ('Technician', 'Housekeeper', 'Cleaner', 'Staff') AND status = 'Active'



    ORDER BY role DESC, fullName ASC



");



$staffList = $stmtStaffList->fetchAll(PDO::FETCH_ASSOC);



// ดึงข้อมูลงานที่กำลังดำเนินการ (In Progress) เพื่อดูว่าพนักงานคนไหนกำลังติดงานอยู่ และมีกำหนดเวลาเท่าใด



$busyStaff = [];



try {



    $stmtBusy = $conn->query("



        SELECT sr.requestId, sr.assignedTo, sr.roomId, r.roomNumber, sr.category, sr.assignedAt, 



               COALESCE(sr.estimatedHours, 2) AS estimatedHours,



               TIMESTAMPDIFF(MINUTE, sr.assignedAt, NOW()) AS minutesElapsed



        FROM ServiceRequest sr



        JOIN Room r ON sr.roomId = r.roomId



        WHERE sr.status = 'In Progress' AND sr.assignedTo IS NOT NULL



    ");



    while ($row = $stmtBusy->fetch(PDO::FETCH_ASSOC)) {



        $busyStaff[$row['assignedTo']] = $row;



    }



} catch(Throwable $e) {}



// คำนวณจำนวนช่าง/แม่บ้านที่ว่าง และที่ติดงาน



$countAvailableStaff = 0;



$countBusyStaff = 0;



foreach($staffList as $st) {



    if (isset($busyStaff[$st['userId']])) {



        $countBusyStaff++;



    } else {



        $countAvailableStaff++;



    }



}



// สรุปตัวเลขสถิติ



$countTotal = count($requests);
$countPending = 0;



$countInProgress = 0;



$countCompleted = 0;



$countCommented = 0;
$countPendingApproval = 0;

foreach($requests as $r) {

    if($r['status'] == 'Pending') $countPending++;

    if($r['status'] == 'In Progress') $countInProgress++;

    if($r['status'] == 'Pending Approval') $countPendingApproval++;

    if($r['status'] == 'Completed') $countCompleted++;

    if(!empty($r['tenantComment'])) $countCommented++;

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

    .modal-enter { animation: modalFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }

  </style>



  <?php include_once 'includes/swal.php'; ?>



  <?php include_once 'includes/theme_head.php'; ?>
</head>



<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">



  <?php include_once 'includes/admin_sidebar.php'; ?>



  <!-- Top Navigation (Admin Style) -->



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

            <i data-lucide="wrench" class="w-5 h-5 text-amber-300"></i>

          </div>

          <div>

            <div class="flex items-center space-x-2">

              <span class="font-display font-bold text-lg tracking-tight text-slate-900">หอพักจัสมิน</span>

              <span class="text-[10px] bg-rose-50 text-rose-700 font-bold px-2 py-0.5 rounded-full border border-rose-200 uppercase tracking-wider">ADMIN</span>

            </div>

            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">จัดการงานแจ้งซ่อมและมอบหมายช่าง</p>

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



  <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">



    



    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->

    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">

      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>



      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">

        <div>

          <div class="flex items-center gap-2 flex-wrap mb-2">

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">

              <i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-500"></i>

              <span>งานบริการและซ่อมบำรุง</span>

            </span>

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">

              <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-500"></i>

              <span>ติดตามงานแบบเรียลไทม์</span>

            </span>

          </div>

          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">

            จัดการงานแจ้งซ่อมและบริการ

          </h1>

          <p class="text-xs sm:text-sm text-slate-500 mt-1">

            รับเรื่องจากลูกบ้าน ตรวจสอบปัญหา และมอบหมายงานให้ช่างหรือแม่บ้านอย่างมีประสิทธิภาพ

          </p>

        </div>



        <!-- Quick Top Action Buttons -->

        <div class="flex flex-wrap items-center gap-3 shrink-0">

          <a href="admin_reports.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">

            <i data-lucide="bar-chart-3" class="w-4 h-4 text-rose-600"></i>

            <span>รายงาน & สถิติ</span>

          </a>

          <a href="manage_staff.php" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 shadow-sm shadow-rose-600/25 active:scale-95 cursor-pointer">

            <i data-lucide="users" class="w-4 h-4"></i>

            <span>จัดการรายชื่อพนักงาน</span>

          </a>

        </div>

      </div>

    </div>



    <!-- 🌟 แผงตรวจสอบสถานะทีมงาน (ช่าง & แม่บ้าน): ใครว่าง / ใครติดงาน 🌟 -->



    <div class="mb-8 bg-white rounded-3xl p-5 sm:p-6 border border-slate-200/90 shadow-sm">



        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-100">



            <div class="flex items-center gap-2.5">



                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">



                    <i data-lucide="users" class="w-5 h-5"></i>



                </div>



                <div>



                    <h3 class="font-display font-bold text-slate-900 text-base">สถานะการปฏิบัติงานของทีมช่างและแม่บ้าน</h3>



                    <p class="text-xs text-slate-400">ตรวจสอบว่าพนักงานคนไหนกำลังติดงานอยู่ หรือใครว่างพร้อมรับมอบหมายงานใหม่</p>



                </div>



            </div>



            <div class="flex flex-wrap items-center gap-2 text-xs font-bold">



                <span class="px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full flex items-center gap-1.5 shadow-2xs">



                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> ว่างพร้อมรับงาน: <?php echo $countAvailableStaff; ?> คน



                </span>



                <span class="px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full flex items-center gap-1.5 shadow-2xs">



                    <span class="w-2 h-2 rounded-full bg-amber-500"></span> กำลังติดงาน: <?php echo $countBusyStaff; ?> คน



                </span>



            </div>



        </div>



        <!-- Grid การ์ดสถานะของแต่ละคน -->



        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">



            <?php foreach($staffList as $st): 



                $isBusy = isset($busyStaff[$st['userId']]);



                $bInfo = $isBusy ? $busyStaff[$st['userId']] : null;



                $isCleaner = in_array($st['role'], ['Cleaner', 'Housekeeper']);



                $roleLabel = $isCleaner ? 'แม่บ้าน' : 'ช่างซ่อมบำรุง';



                $roleColor = $isCleaner ? 'bg-teal-50 text-teal-700 border-teal-200' : 'bg-rose-50 text-rose-700 border-rose-200';



            ?>



            <div class="p-4 rounded-2xl border <?php echo $isBusy ? 'bg-amber-50/40 border-amber-200/80 shadow-xs' : 'bg-slate-50/60 border-slate-200/80 hover:bg-emerald-50/30 hover:border-emerald-200'; ?> transition flex flex-col justify-between">



                <div class="flex items-start justify-between gap-2">



                    <div class="flex items-center gap-3">



                        <div class="w-10 h-10 rounded-xl <?php echo $isBusy ? 'bg-amber-500 text-white' : 'bg-slate-800 text-white'; ?> flex items-center justify-center font-bold text-sm shadow-xs shrink-0">



                            <?php echo strtoupper(mb_substr($st['fullName'], 0, 1, 'UTF-8')); ?>



                        </div>



                        <div class="min-w-0">



                            <div class="font-bold text-slate-900 text-sm truncate"><?php echo htmlspecialchars($st['fullName']); ?></div>



                            <div class="flex items-center gap-1.5 mt-0.5">



                                <span class="text-[10px] font-semibold px-2 py-0.2 rounded-full border <?php echo $roleColor; ?> shrink-0"><?php echo $roleLabel; ?></span>



                                <?php if(!empty($st['phone'])): ?>



                                    <span class="text-[10px] text-slate-400 truncate">📞 <?php echo htmlspecialchars($st['phone']); ?></span>



                                <?php endif; ?>



                            </div>



                        </div>



                    </div>



                    



                    <!-- ป้ายสถานะ ว่าง / ติดงาน -->



                    <?php if ($isBusy): ?>



                        <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-amber-500 text-white shrink-0 flex items-center gap-1 shadow-2xs">



                            <i data-lucide="lock" class="w-3 h-3"></i> ติดงานอยู่



                        </span>



                    <?php else: ?>



                        <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100 text-emerald-800 border border-emerald-200 shrink-0 flex items-center gap-1 shadow-2xs">



                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> ว่าง



                        </span>



                    <?php endif; ?>



                </div>



                <!-- รายละเอียดงานที่ติดอยู่ (ถ้ามี) -->



                <?php if ($isBusy && $bInfo): 



                    $estH = (int)$bInfo['estimatedHours'];



                    $startTime = !empty($bInfo['assignedAt']) ? date('H:i', strtotime($bInfo['assignedAt'])) : '-';



                    $finishTime = !empty($bInfo['assignedAt']) ? date('H:i', strtotime($bInfo['assignedAt']) + ($estH * 3600)) : '-';



                    $elapsedMin = (int)$bInfo['minutesElapsed'];



                    $totalMin = $estH * 60;



                    $percent = min(100, max(5, round(($elapsedMin / $totalMin) * 100)));



                ?>



                    <div class="mt-3 pt-3 border-t border-amber-200/60 text-xs text-amber-900 space-y-1.5">



                        <div class="flex items-center justify-between font-bold">



                            <span class="flex items-center gap-1 text-slate-900">



                                <i data-lucide="door-closed" class="w-3.5 h-3.5 text-blue-600"></i> ห้อง <?php echo htmlspecialchars($bInfo['roomNumber']); ?>



                                <span class="text-[10px] text-slate-400 font-normal truncate">(<?php echo htmlspecialchars($bInfo['category']); ?>)</span>



                            </span>



                            <span class="text-[10px] text-amber-800 bg-amber-100 px-2 py-0.5 rounded-md font-mono shrink-0">



                                ⏱️ กำหนด <?php echo $estH; ?> ชม.



                            </span>



                        </div>



                        <div class="text-[11px] text-slate-500 flex items-center justify-between">



                            <span>เริ่มงาน: <b><?php echo $startTime; ?> น.</b></span>



                            <span>คาดว่าเสร็จ: <b>~<?php echo $finishTime; ?> น.</b></span>



                        </div>



                        <!-- Progress bar เวลา -->



                        <div class="w-full bg-amber-100 h-1.5 rounded-full overflow-hidden">



                            <div class="bg-amber-500 h-full rounded-full transition-all duration-500" style="width: <?php echo $percent; ?>%;"></div>



                        </div>



                    </div>



                <?php else: ?>



                    <div class="mt-3 pt-2 text-[11px] text-emerald-600 font-medium flex items-center gap-1">



                        <i data-lucide="check" class="w-3.5 h-3.5"></i> พร้อมรับมอบหมายงานทันที



                    </div>



                <?php endif; ?>



            </div>



            <?php endforeach; ?>



        </div>



    </div>



    <div id="alert-container">



        <?php echo $message; ?>



    </div>



    <?php if ($filterRoomNumber): ?>



    <div class="mb-6 p-4 bg-blue-50 border-l-4 border-blue-600 rounded-r-2xl flex items-center justify-between shadow-xs">



        <div class="flex items-center gap-2.5 text-blue-900 font-bold text-sm">



            <i data-lucide="filter" class="w-5 h-5 text-blue-600 shrink-0"></i>



            <span>กำลังแสดงเฉพาะคำขอแจ้งซ่อมของ: <span class="bg-blue-600 text-white px-2.5 py-0.5 rounded-lg text-xs">ห้อง <?php echo htmlspecialchars($filterRoomNumber); ?></span></span>



        </div>



        <a href="admin_requests.php" class="px-3.5 py-1.5 bg-white hover:bg-blue-100 text-blue-700 rounded-xl text-xs font-bold border border-blue-200 transition shadow-2xs flex items-center gap-1">



            <i data-lucide="x" class="w-3.5 h-3.5"></i> แสดงทุกห้อง



        </a>



    </div>



    <?php endif; ?>



        <!-- 🌟 แผงตัวกรองสถานะงาน (ทั้งหมด / รอมอบหมาย / กำลังดำเนินการ / รออนุมัติ / งานที่เสร็จสิ้น / มีติชม) 🌟 -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <!-- 1. ทั้งหมด -->
        <button type="button" onclick="filterByStatus('all')" id="filter-btn-all" class="filter-status-card p-4 rounded-2xl border-2 transition text-left flex flex-col justify-between cursor-pointer bg-slate-900 text-white border-slate-900 shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold opacity-80 mb-2">
                <span>ทั้งหมด</span>
                <i data-lucide="layers" class="w-4 h-4"></i>
            </div>
            <div class="text-2xl font-display font-extrabold font-mono tracking-tight">
                <?php echo $countTotal; ?> <span class="text-xs font-normal opacity-70">งาน</span>
            </div>
        </button>

        <!-- 2. รอมอบหมาย -->
        <button type="button" onclick="filterByStatus('Pending')" id="filter-btn-Pending" class="filter-status-card p-4 rounded-2xl border-2 transition text-left flex flex-col justify-between cursor-pointer bg-white text-slate-800 border-amber-200/80 hover:border-amber-400 hover:shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold text-amber-700 mb-2">
                <span>รอมอบหมาย</span>
                <i data-lucide="clock" class="w-4 h-4 text-amber-500"></i>
            </div>
            <div class="text-2xl font-display font-extrabold font-mono tracking-tight text-amber-600">
                <?php echo $countPending; ?> <span class="text-xs font-normal text-slate-400">งาน</span>
            </div>
        </button>

        <!-- 3. กำลังดำเนินการ -->
        <button type="button" onclick="filterByStatus('In Progress')" id="filter-btn-In_Progress" class="filter-status-card p-4 rounded-2xl border-2 transition text-left flex flex-col justify-between cursor-pointer bg-white text-slate-800 border-blue-200/80 hover:border-blue-400 hover:shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold text-blue-700 mb-2">
                <span>กำลังดำเนินการ</span>
                <i data-lucide="wrench" class="w-4 h-4 text-blue-500"></i>
            </div>
            <div class="text-2xl font-display font-extrabold font-mono tracking-tight text-blue-600">
                <?php echo $countInProgress; ?> <span class="text-xs font-normal text-slate-400">งาน</span>
            </div>
        </button>

        <!-- 4. รอแอดมินอนุมัติ -->
        <button type="button" onclick="filterByStatus('Pending Approval')" id="filter-btn-Pending_Approval" class="filter-status-card p-4 rounded-2xl border-2 transition text-left flex flex-col justify-between cursor-pointer bg-white text-slate-800 border-orange-200/90 hover:border-orange-400 hover:shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold text-orange-700 mb-2">
                <span>รออนุมัติงาน</span>
                <i data-lucide="clipboard-check" class="w-4 h-4 text-orange-500"></i>
            </div>
            <div class="text-2xl font-display font-extrabold font-mono tracking-tight text-orange-600">
                <?php echo $countPendingApproval; ?> <span class="text-xs font-normal text-slate-400">งาน</span>
            </div>
        </button>

        <!-- 5. งานที่เสร็จสิ้น -->
        <button type="button" onclick="filterByStatus('Completed')" id="filter-btn-Completed" class="filter-status-card p-4 rounded-2xl border-2 transition text-left flex flex-col justify-between cursor-pointer bg-white text-slate-800 border-emerald-200/80 hover:border-emerald-400 hover:shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold text-emerald-700 mb-2">
                <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> งานเสร็จสิ้น</span>
                <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-500"></i>
            </div>
            <div class="text-2xl font-display font-extrabold font-mono tracking-tight text-emerald-600">
                <?php echo $countCompleted; ?> <span class="text-xs font-normal text-slate-400">งาน</span>
            </div>
        </button>

        <!-- 6. มีข้อคิดเห็น/ติชม -->
        <button type="button" onclick="filterByStatus('commented')" id="filter-btn-commented" class="filter-status-card p-4 rounded-2xl border-2 transition text-left flex flex-col justify-between cursor-pointer bg-white text-slate-800 border-purple-200/80 hover:border-purple-400 hover:shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold text-purple-700 mb-2">
                <span>มีติชมจากผู้เช่า</span>
                <i data-lucide="message-square-quote" class="w-4 h-4 text-purple-500"></i>
            </div>
            <div class="text-2xl font-display font-extrabold font-mono tracking-tight text-purple-700">
                <?php echo $countCommented; ?> <span class="text-xs font-normal text-slate-400">รายการ</span>
            </div>
        </button>
    </div>

    <!-- 🌟 ตารางคำร้องขอทั้งหมด 🌟 -->



    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">



        <div class="p-6 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">



            <h3 class="font-display font-bold text-slate-900 text-lg flex items-center gap-2">



                <i data-lucide="list-checks" class="w-5 h-5 text-blue-600"></i> รายการคำร้องแจ้งซ่อมและบริการทั้งหมด



            </h3>



            <span class="text-xs text-slate-400">ทั้งหมด <?php echo count($requests); ?> รายการ</span>



        </div>



        <div class="overflow-x-auto">



            <table class="w-full text-left text-sm text-slate-600">



                <thead class="bg-slate-100/50 text-slate-500 font-semibold text-xs uppercase tracking-wider">



                    <tr>



                        <th class="px-6 py-4 border-b border-slate-200">ห้อง / รายละเอียดปัญหา</th>
                        <th class="px-6 py-4 border-b border-slate-200 min-w-[175px] w-48">การนัดหมาย</th>
                        <th class="px-6 py-4 border-b border-slate-200 min-w-[185px] w-52">ผู้รับผิดชอบงาน</th>
                        <th class="px-6 py-4 border-b border-slate-200 w-32 text-center">สถานะ</th>
                        <th class="px-6 py-4 border-b border-slate-200 w-44 text-right">การจัดการ</th>



                    </tr>



                </thead>



                <tbody class="divide-y divide-slate-100">



                    <?php if(count($requests) > 0): foreach($requests as $req): 



                        $isPending = ($req['status'] === 'Pending');



                        $bgRow = $isPending ? "bg-amber-50/20" : "hover:bg-slate-50 transition";



                        



                        $isCleaning = (mb_strpos($req['category'], 'ความสะอาด', 0, 'UTF-8') !== false || mb_strpos($req['category'], 'ขยะ', 0, 'UTF-8') !== false);



                        



                        $statusColor = "bg-slate-100 text-slate-700";



                        $statusText = "ไม่ทราบ";



                        



                        if ($req['status'] === 'Pending') {



                            $statusColor = "bg-amber-100 text-amber-700 border border-amber-200";



                            $statusText = "รอมอบหมายงาน";



                        } elseif ($req['status'] === 'In Progress') {



                            $statusColor = "bg-blue-100 text-blue-700 border border-blue-200";



                            $statusText = "กำลังดำเนินการ";



                        } elseif ($req['status'] === 'Pending Approval') {

                            $statusColor = "bg-orange-100 text-orange-800 border border-orange-300 font-bold";

                            $statusText = "รอแอดมินอนุมัติ";

                        } elseif ($req['status'] === 'Completed') {



                            $statusColor = "bg-emerald-100 text-emerald-700 border border-emerald-200";



                            $statusText = "เสร็จสิ้น";



                        }



                    ?>



                    <tr class="request-table-row <?php echo $bgRow; ?> cursor-pointer hover:bg-blue-50/40 transition group" data-status="<?php echo htmlspecialchars($req['status']); ?>" data-has-comment="<?php echo !empty($req['tenantComment']) ? '1' : '0'; ?>" onclick="openDetailModal(<?php echo $req['requestId']; ?>)">



                        <!-- 1. ข้อมูลห้อง / ปัญหา -->



                        <td class="px-6 py-4">



                            <div class="flex items-start gap-3">



                                <?php if(!empty($req['requestImage'])): ?>



                                    <img src="<?php echo htmlspecialchars($req['requestImage']); ?>" onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($req['requestImage']); ?>', 'รูปปัญหาจากลูกบ้าน ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?>')" class="w-12 h-12 object-cover rounded-lg border border-slate-200 cursor-pointer shadow-sm mt-1 hover:opacity-80 transition" title="คลิกเพื่อขยายรูป">



                                <?php else: ?>



                                    <div class="w-12 h-12 bg-slate-100 rounded-lg flex items-center justify-center text-slate-300 mt-1 border border-slate-200 shrink-0"><i data-lucide="image-off" class="w-5 h-5"></i></div>



                                <?php endif; ?>



                                



                                <div class="min-w-0 flex-1">



                                    <div class="flex items-center gap-2 mb-1 flex-wrap">



                                        <span class="text-xs font-bold px-2 py-0.5 rounded bg-slate-800 text-white">ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?></span>



                                        <span class="text-[10px] text-slate-400 font-mono">REQ-<?php echo str_pad($req['requestId'], 4, '0', STR_PAD_LEFT); ?></span>



                                        <button type="button" onclick="event.stopPropagation(); openDetailModal(<?php echo $req['requestId']; ?>)" class="text-[10px] text-blue-600 font-bold bg-blue-50 hover:bg-blue-100 border border-blue-200 px-2 py-0.5 rounded transition flex items-center gap-1 cursor-pointer">



                                            <i data-lucide="external-link" class="w-3 h-3"></i> ดูรายละเอียด



                                        </button>



                                        <?php if($isCleaning): ?>



                                            <span class="text-[10px] font-bold bg-teal-50 text-teal-700 px-1.5 py-0.5 rounded border border-teal-200 flex items-center gap-1"><i data-lucide="sparkles" class="w-3 h-3"></i> งานแม่บ้าน</span>



                                        <?php else: ?>



                                            <span class="text-[10px] font-bold bg-rose-50 text-rose-700 px-1.5 py-0.5 rounded border border-rose-200 flex items-center gap-1"><i data-lucide="wrench" class="w-3 h-3"></i> งานช่าง</span>



                                        <?php endif; ?>



                                        



                                        <?php if($req['allowEntry'] == 1): ?>



                                            <span class="text-[10px] font-bold bg-emerald-50 text-emerald-700 px-1.5 py-0.5 rounded border border-emerald-200 flex items-center gap-1"><i data-lucide="key" class="w-2.5 h-2.5"></i> กุญแจสำรอง</span>



                                        <?php endif; ?>



                                        <?php if(!empty($req['tenantComment'])): ?>



                                            <span class="text-[10px] font-bold bg-purple-100 text-purple-900 border border-purple-300 px-2 py-0.5 rounded-lg flex items-center gap-1 shadow-2xs">



                                                <i data-lucide="message-square-quote" class="w-3 h-3 text-purple-600"></i> ผู้เช่ามีข้อคิดเห็น/ติชม



                                            </span>



                                        <?php endif; ?>



                                        <?php if(!empty($req['staffComment'])): ?>



                                            <span class="text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300 px-2 py-0.5 rounded-lg flex items-center gap-1 shadow-2xs">



                                                <i data-lucide="message-square-warning" class="w-3 h-3 text-amber-600"></i> <?php echo $isCleaning ? 'แม่บ้าน' : 'ช่าง'; ?>มีรายงานปัญหา



                                            </span>



                                        <?php endif; ?>



                                    </div>



                                    <div class="font-bold text-slate-900 text-sm"><?php echo htmlspecialchars($req['category']); ?></div>



                                    <div class="text-xs text-slate-600 mt-0.5 whitespace-pre-line line-clamp-2"><?php echo htmlspecialchars($req['description']); ?></div>



                                    <div class="text-[11px] text-slate-400 mt-1 flex items-center gap-2">



                                        <span>ผู้เช่า: <?php echo htmlspecialchars($req['tenantName']); ?></span>



                                        <?php if(!empty($req['tenantPhone'])): ?>



                                            <span>• 📞 <?php echo htmlspecialchars($req['tenantPhone']); ?></span>



                                        <?php endif; ?>



                                    </div>



                                    <!-- 🌟 ความคิดเห็น / ติชมจากผู้เช่า (เฉพาะแอดมินและผู้เช่าที่เห็น) 🌟 -->
                                    <?php if(!empty($req['tenantComment'])): ?>
                                        <div class="mt-2.5 p-2.5 bg-purple-50/80 border border-purple-200/90 rounded-xl text-xs max-w-md w-full shadow-2xs">
                                            <div class="flex items-center justify-between gap-1 mb-1 font-bold text-purple-900">
                                                <span class="flex items-center gap-1.5">
                                                    <i data-lucide="message-square-quote" class="w-3.5 h-3.5 text-purple-600"></i>
                                                    ข้อคิดเห็น/ติชมจากผู้เช่า:
                                                </span>
                                                <span class="text-[9px] bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-semibold border border-purple-200 flex items-center gap-1">
                                                    <i data-lucide="lock" class="w-2.5 h-2.5"></i> ลับเฉพาะแอดมิน
                                                </span>
                                            </div>
                                            <div class="text-slate-700 bg-white p-2.5 rounded-lg border border-purple-100 whitespace-pre-line leading-relaxed font-sans break-words shadow-2xs font-medium text-xs">
                                                "<?php echo htmlspecialchars($req['tenantComment']); ?>"
                                            </div>
                                            <?php if(!empty($req['commentedAt'])): ?>
                                                <div class="text-[10px] text-purple-600/80 mt-1 text-right font-medium">
                                                    <i data-lucide="clock" class="w-2.5 h-2.5 inline mr-0.5"></i> ส่งเมื่อ: <?php echo date("d/m/Y H:i", strtotime($req['commentedAt'])); ?> น.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- 🌟 รายงานปัญหาเพิ่มเติมจากช่าง/แม่บ้าน (เห็นเฉพาะแอดมิน) 🌟 -->
                                    <?php if(!empty($req['staffComment'])): ?>
                                        <div class="mt-2 p-2.5 bg-amber-50/90 border border-amber-300/90 rounded-xl text-xs max-w-md w-full shadow-2xs">
                                            <div class="flex items-center justify-between gap-1 mb-1 font-bold text-amber-950">
                                                <span class="flex items-center gap-1.5">
                                                    <i data-lucide="message-square-warning" class="w-3.5 h-3.5 text-amber-600"></i>
                                                    รายงานปัญหาจาก<?php echo $isCleaning ? 'แม่บ้าน' : 'ช่าง'; ?> (เห็นเฉพาะแอดมิน):
                                                </span>
                                                <span class="text-[9px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-bold border border-amber-200 flex items-center gap-1">
                                                    <i data-lucide="lock" class="w-2.5 h-2.5"></i> ลับเฉพาะแอดมิน
                                                </span>
                                            </div>
                                            <div class="text-slate-800 bg-white p-2.5 rounded-lg border border-amber-300 whitespace-pre-line leading-relaxed font-sans break-words shadow-2xs font-medium text-xs">
                                                <?php echo htmlspecialchars($req['staffComment']); ?>
                                            </div>
                                            <?php if(!empty($req['staffCommentedAt'])): ?>
                                                <div class="text-[10px] text-amber-800/80 mt-1 text-right font-mono font-medium">
                                                    <i data-lucide="clock" class="w-2.5 h-2.5 inline mr-0.5"></i> บันทึกเมื่อ: <?php echo date("d/m/Y H:i", strtotime($req['staffCommentedAt'])); ?> น.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <!-- 2. การนัดหมาย -->
                        <td class="px-6 py-4 text-xs font-mono text-slate-600 align-top">
                            <div class="flex flex-col items-start gap-1">
                                <?php if(!empty($req['preferredDate'])): ?>
                                    <div class="font-bold text-rose-600 bg-rose-50/90 px-2.5 py-1 rounded-lg border border-rose-200/80 inline-flex items-center gap-1.5 text-xs shadow-2xs">
                                        <i data-lucide="calendar" class="w-3.5 h-3.5 text-rose-500 shrink-0"></i>
                                        <span><?php echo date("d/m/Y", strtotime($req['preferredDate'])); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if(!empty($req['preferredTime'])): ?>
                                    <div class="inline-flex items-center gap-1.5 text-[11px] font-sans font-medium text-indigo-700 bg-indigo-50/90 px-2 py-0.5 rounded-md border border-indigo-200/70 max-w-[210px] break-words">
                                        <i data-lucide="clock" class="w-3 h-3 text-indigo-500 shrink-0"></i>
                                        <span><?php echo htmlspecialchars($req['preferredTime']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if(empty($req['preferredDate']) && empty($req['preferredTime'])): ?>
                                    <div class="inline-flex items-center gap-1 text-[11px] font-sans text-slate-500 bg-slate-50 px-2 py-0.5 rounded border border-slate-200/60 mb-0.5">
                                        <i data-lucide="calendar-check-2" class="w-3 h-3 text-slate-400 shrink-0"></i>
                                        <span>คิวเร็วที่สุด / ตามสะดวกช่าง</span>
                                    </div>
                                <?php endif; ?>

                                <div class="text-[10px] text-slate-600 font-sans mt-0.5 flex items-center gap-1 font-semibold">
                                    <i data-lucide="clock" class="w-3 h-3 text-slate-400 shrink-0"></i>
                                    <span>แจ้ง: <b><?php echo date("d/m/Y H:i", strtotime($req['requestDate'])); ?> น.</b></span>
                                </div>

                                <?php if(!empty($req['completedAt'])): ?>
                                    <div class="text-[10px] text-emerald-700 font-sans mt-1 pt-1 border-t border-slate-100 flex items-center gap-1 font-bold">
                                        <i data-lucide="check-circle-2" class="w-3 h-3 text-emerald-600 shrink-0"></i>
                                        <span>ส่งงาน: <?php echo date("d/m/Y H:i", strtotime($req['completedAt'])); ?> น.</span>
                                    </div>

                                    <?php 
                                        $dur = duration_th($req['requestDate'], $req['completedAt']);
                                        if ($dur):
                                    ?>
                                        <div class="text-[9px] text-slate-400 font-sans mt-0.5">
                                            ⏱️ ใช้เวลา: <?php echo $dur; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- 3. ผู้รับผิดชอบงาน (ช่าง/แม่บ้าน) -->
                        <td class="px-6 py-4 align-top">
                            <?php if(!empty($req['assignedTo']) && !empty($req['staffName'])): 
                                $isCleanerRole = in_array($req['staffRole'] ?? '', ['Cleaner', 'Housekeeper']);
                                $roleLabel = $isCleanerRole ? "แม่บ้าน" : "ช่าง";
                                $roleBg = $isCleanerRole ? "bg-teal-50 text-teal-700 border-teal-200" : "bg-rose-50 text-rose-700 border-rose-200";
                            ?>
                                <div class="flex items-start gap-2.5">
                                    <div class="w-8 h-8 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center font-bold text-xs text-slate-700 shrink-0 mt-0.5">
                                        <?php echo strtoupper(mb_substr($req['staffName'], 0, 1, 'UTF-8')); ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-bold text-slate-900 text-xs truncate"><?php echo htmlspecialchars($req['staffName']); ?></div>
                                        <span class="inline-block text-[10px] px-1.5 py-0.2 rounded border font-semibold <?php echo $roleBg; ?>"><?php echo $roleLabel; ?></span>
                                        <?php if(!empty($req['staffPhone'])): ?>
                                            <div class="text-[10px] text-slate-400 mt-0.5">📞 <?php echo htmlspecialchars($req['staffPhone']); ?></div>
                                        <?php endif; ?>

                                        <?php if($req['status'] === 'Completed'): ?>
                                            <div class="mt-1.5 inline-flex items-center gap-1 text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200 whitespace-nowrap">
                                                <i data-lucide="check-check" class="w-3 h-3 text-emerald-600"></i> ปฏิบัติงานเสร็จสิ้น
                                            </div>
                                        <?php elseif($req['status'] === 'In Progress'): 
                                            $rowEstH = intval($req['estimatedHours'] ?? 2);
                                            $rowAssignTs = !empty($req['assignedAt']) ? strtotime($req['assignedAt']) : (!empty($req['requestDate']) ? strtotime($req['requestDate']) : time());
                                            $rowFinishTs = $rowAssignTs + ($rowEstH * 3600);
                                        ?>
                                            <div class="mt-1 text-[10px] text-blue-700 bg-blue-50 px-2 py-0.5 rounded-md border border-blue-200/80 flex items-center gap-1 font-semibold whitespace-nowrap">
                                                <i data-lucide="clock" class="w-3 h-3 text-blue-500"></i> กำหนด <?php echo $rowEstH; ?> ชม. (ถึง ~<?php echo date('H:i', $rowFinishTs); ?> น.)
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php if($req['status'] === 'Completed'): ?>
                                    <div class="flex items-start gap-2">
                                        <div class="w-8 h-8 rounded-full bg-emerald-100 border border-emerald-300 flex items-center justify-center font-bold text-xs text-emerald-800 shrink-0 mt-0.5">
                                            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-700"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-900 text-xs">นิติบุคคล / ส่วนกลาง</div>
                                            <span class="inline-block text-[10px] px-1.5 py-0.2 rounded border font-semibold bg-slate-100 text-slate-700 border-slate-200">ผู้ดูแลระบบ</span>
                                            <div class="mt-1.5 inline-flex items-center gap-1 text-[10px] text-emerald-700 font-bold bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200 whitespace-nowrap">
                                                <i data-lucide="check-check" class="w-3 h-3 text-emerald-600"></i> ดำเนินการเสร็จสิ้น
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif($req['status'] === 'In Progress'): ?>
                                    <div class="flex items-start gap-2">
                                        <div class="w-8 h-8 rounded-full bg-blue-100 border border-blue-300 flex items-center justify-center font-bold text-xs text-blue-800 shrink-0 mt-0.5">
                                            <i data-lucide="wrench" class="w-4 h-4 text-blue-600"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold text-slate-900 text-xs">ส่วนกลาง / นิติบุคคล</div>
                                            <span class="inline-block text-[10px] px-1.5 py-0.2 rounded border font-semibold bg-blue-50 text-blue-700 border-blue-200">กำลังดำเนินการ</span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 text-[11px] text-amber-600 font-semibold bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60">
                                        <i data-lucide="user-x" class="w-3.5 h-3.5"></i> ยังไม่มอบหมาย
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>

                        <!-- 4. สถานะ -->



                        <td class="px-6 py-4">



                            <div class="flex flex-col items-center justify-center w-28">



                                <span class="inline-flex items-center justify-center px-2.5 py-1 rounded-full text-[10px] font-bold w-full <?php echo $statusColor; ?>">



                                    <?php echo $statusText; ?>



                                </span>



                                <?php if(!empty($req['tenantComment'])): ?>



                                    <span class="inline-flex items-center justify-center gap-1 px-2 py-0.5 mt-1.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200 w-full shadow-2xs">



                                        <i data-lucide="message-square-quote" class="w-3 h-3 text-purple-600"></i> มีติชม



                                    </span>



                                <?php endif; ?>



                            </div>



                        </td>



                        <!-- 5. การจัดการ -->



                        <td class="px-6 py-4 text-right" onclick="event.stopPropagation()">



                            <div class="flex flex-col items-end gap-1.5">



                                <!-- ปุ่มดูรายละเอียดทั้งหมด -->



                                <button type="button" 



                                        onclick="openDetailModal(<?php echo $req['requestId']; ?>)" 



                                        class="w-32 py-1.5 bg-white hover:bg-blue-50 text-slate-700 hover:text-blue-700 border border-slate-200 hover:border-blue-300 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer">



                                    <i data-lucide="eye" class="w-3.5 h-3.5 text-blue-600"></i> ดูรายละเอียด



                                </button>



                                <?php if($req['status'] === 'Pending'): ?>



                                    <!-- ปุ่มเปิด Modal มอบหมายงาน -->



                                    <button type="button" 



                                            onclick="openAssignModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['roomNumber'])); ?>', '<?php echo htmlspecialchars(addslashes($req['category'])); ?>', '<?php echo htmlspecialchars(addslashes($req['description'])); ?>', '<?php echo $isCleaning ? 'cleaning' : 'repair'; ?>', '<?php echo $req['assignedTo'] ?? ''; ?>', <?php echo intval($req['estimatedHours'] ?? 2); ?>)" 



                                            class="w-32 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold shadow-sm transition flex items-center justify-center gap-1.5">



                                        <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> มอบหมายงาน



                                    </button>



                                    



                                                                        <!-- ปุ่มปิดงานทันที (กรณีแอดมินจัดการเสร็จแล้ว) -->
                                    <button type="button" 
                                            onclick="openAdminCompleteModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['roomNumber'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($req['category'] ?? '')); ?>')" 
                                            class="w-32 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 rounded-lg text-[11px] font-bold transition flex items-center justify-center gap-1 cursor-pointer shadow-2xs">
                                        <i data-lucide="check-circle" class="w-3 h-3 text-emerald-600"></i> ปิดงานเสร็จสิ้น
                                    </button>

                                    <!-- ปุ่มลบทิ้ง -->



                                    <form action="" method="POST" class="m-0" onsubmit="return confirm('แน่ใจหรือไม่ว่าต้องการลบรายการนี้ทิ้ง?');">



                                        <input type="hidden" name="request_id" value="<?php echo $req['requestId']; ?>">



                                        <button type="submit" name="delete_task" class="w-32 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-lg text-[11px] font-bold transition flex items-center justify-center gap-1">



                                            <i data-lucide="trash-2" class="w-3 h-3"></i> ลบรายการ



                                        </button>



                                    </form>



                                <?php elseif($req['status'] === 'In Progress'): ?>



                                    <!-- ปุ่มเปลี่ยนผู้รับผิดชอบ -->



                                    <button type="button" 



                                            onclick="openAssignModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['roomNumber'])); ?>', '<?php echo htmlspecialchars(addslashes($req['category'])); ?>', '<?php echo htmlspecialchars(addslashes($req['description'])); ?>', '<?php echo $isCleaning ? 'cleaning' : 'repair'; ?>', '<?php echo $req['assignedTo'] ?? ''; ?>', <?php echo intval($req['estimatedHours'] ?? 2); ?>)" 



                                            class="w-32 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5">



                                        <i data-lucide="user-cog" class="w-3.5 h-3.5"></i> เปลี่ยนช่าง/คนทำ



                                    </button>



                                                                        <!-- ปุ่มจบงาน/ทำเครื่องหมายเสร็จสิ้น (Admin Complete) -->
                                    <button type="button" 
                                            onclick="openAdminCompleteModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['roomNumber'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($req['category'] ?? '')); ?>')" 
                                            class="w-32 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs cursor-pointer">
                                        <i data-lucide="check-circle" class="w-3.5 h-3.5"></i> จบงาน/เสร็จสิ้น
                                    </button>

                                    <!-- ดึงกลับเป็น Pending -->



                                    <form action="" method="POST" class="m-0" onsubmit="return confirm('ต้องการยกเลิกการมอบหมายงานนี้ชั่วคราว?');">



                                        <input type="hidden" name="request_id" value="<?php echo $req['requestId']; ?>">



                                        <button type="submit" name="unassign_task" class="w-32 py-1 text-slate-400 hover:text-amber-600 text-[10px] font-semibold transition flex items-center justify-center gap-1">



                                            <i data-lucide="rotate-ccw" class="w-3 h-3"></i> ดึงงานกลับ



                                        </button>



                                    </form>

                                <?php elseif($req['status'] === 'Pending Approval'): ?>

                                    <!-- ปุ่มอนุมัติการส่งมอบงาน -->
                                    <form action="" method="POST" class="m-0" onsubmit="return confirm('ยืนยันอนุมัติการส่งมอบงานนี้? (ผู้เช่าจะเห็นงานว่าเสร็จสิ้นทันที)');">
                                        <input type="hidden" name="request_id" value="<?php echo $req['requestId']; ?>">
                                        <button type="submit" name="approve_job" class="w-32 py-1.5 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm cursor-pointer">
                                            <i data-lucide="check-check" class="w-3.5 h-3.5"></i> อนุมัติส่งงาน
                                        </button>
                                    </form>

                                    <?php if(!empty($req['completionImage'])): ?>
                                        <button type="button" onclick="openImageModal('<?php echo htmlspecialchars($req['completionImage']); ?>', 'รูปผลงานจาก <?php echo htmlspecialchars($req['staffName'] ?? 'พนักงาน'); ?> (ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?>)')" class="w-32 py-1 bg-amber-50 hover:bg-amber-100 border border-amber-200 text-amber-800 rounded-lg text-[11px] font-bold transition flex items-center justify-center gap-1 cursor-pointer">
                                            <i data-lucide="camera" class="w-3 h-3 text-amber-600"></i> ดูรูปผลงาน
                                        </button>
                                    <?php endif; ?>

                                    <!-- ปุ่มส่งกลับให้ช่างแก้ไข -->
                                    <button type="button" onclick="openRejectJobModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['roomNumber'] ?? '')); ?>')" class="w-32 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-lg text-[10px] font-bold transition flex items-center justify-center gap-1 cursor-pointer">
                                        <i data-lucide="rotate-ccw" class="w-3 h-3 text-rose-500"></i> ส่งกลับให้แก้
                                    </button>

                                <?php elseif($req['status'] === 'Completed'): ?>



                                    <?php if(!empty($req['completionImage'])): ?>



                                        <!-- ปุ่มดูรูปผลงานที่ช่างถ่ายส่งมา -->



                                        <button type="button" onclick="openImageModal('<?php echo htmlspecialchars($req['completionImage']); ?>', 'รูปผลงานจาก <?php echo htmlspecialchars($req['staffName'] ?? 'พนักงาน'); ?> (ห้อง <?php echo htmlspecialchars($req['roomNumber']); ?>)')" class="w-32 py-1.5 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 rounded-lg text-[11px] font-bold transition flex items-center justify-center gap-1.5 shadow-sm">



                                            <i data-lucide="camera" class="w-3.5 h-3.5"></i> ตรวจผลงาน



                                        </button>



                                    <?php else: ?>



                                        <span class="text-xs text-emerald-600 font-bold flex items-center gap-1">



                                            <i data-lucide="check" class="w-3.5 h-3.5"></i> เสร็จสิ้นแล้ว



                                        </span>



                                    <?php endif; ?>



                                <?php endif; ?>



                            </div>



                        </td>



                    </tr>



                    <?php endforeach; else: ?>



                    <tr>



                        <td colspan="5" class="px-6 py-12 text-center">



                            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-50 text-slate-300 mb-2">



                                <i data-lucide="inbox" class="w-6 h-6"></i>



                            </div>



                            <div class="text-slate-500 text-sm font-medium">ไม่มีรายการแจ้งซ่อมหรือบริการในระบบ</div>



                        </td>



                    </tr>



                    <?php endif; ?>



                                    <tr id="no-matching-requests" class="hidden">
                        <td colspan="5" class="py-12 text-center text-slate-400 text-sm">
                            <i data-lucide="clipboard-x" class="w-8 h-8 mx-auto mb-2 text-slate-300"></i>
                            <div class="font-bold text-slate-600">ไม่พบรายการงานตามตัวกรองที่เลือก</div>
                            <button type="button" onclick="filterByStatus('all')" class="mt-2 text-xs text-blue-600 hover:underline font-semibold">แสดงทั้งหมด</button>
                        </td>
                    </tr>
                </tbody>



            </table>



        </div>



    </div>



  </main>



  <!-- 🌟 MODAL: มอบหมายงานให้ช่าง / แม่บ้าน (Assign Staff Modal - Fullscreen & Boutique) 🌟 -->

  <div id="assignModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden">

      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col">

          

          <!-- Modal Header -->

          <div class="px-5 py-4 sm:px-8 sm:py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">

              <div class="flex items-center gap-3">

                  <div class="w-10 h-10 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center shadow-xs">

                      <i data-lucide="user-check" class="w-5 h-5"></i>

                  </div>

                  <div>

                      <h3 class="font-display font-bold text-slate-800 text-lg sm:text-xl" id="assignModalTitle">มอบหมายงานให้พนักงาน</h3>

                      <p class="text-xs text-slate-500">เลือกช่างหรือแม่บ้านที่ว่าง เพื่อเข้าดำเนินการแก้ไขปัญหาตามวัน-เวลาที่ผู้เช่าสะดวก</p>

                  </div>

              </div>

              <div class="flex items-center gap-2">

                  <button type="button" onclick="closeAssignModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">

                      <i data-lucide="x" class="w-5 h-5"></i>

                  </button>

              </div>

          </div>

          

          <form action="" method="POST" class="flex-1 min-h-0 flex flex-col justify-between overflow-hidden">

              <input type="hidden" name="request_id" id="assign_modal_request_id">

              <input type="hidden" name="estimated_hours" value="2">



              <div class="p-5 sm:p-7 md:p-8 space-y-6 overflow-y-auto flex-1 min-h-0">

                  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 max-w-7xl mx-auto w-full">

                      

                      <!-- LEFT COLUMN: สรุปงานแจ้งซ่อม & วัน-เวลานัดหมายของผู้เช่า -->

                      <div class="space-y-5">

                          

                          <!-- การ์ด 1: รายละเอียดคำขอแจ้งซ่อม -->

                          <div class="p-5 bg-slate-50/80 rounded-3xl border border-slate-200/80 space-y-3.5 shadow-xs">

                              <div class="flex items-center justify-between pb-3 border-b border-slate-200/70">

                                  <span class="text-xs font-bold text-slate-500 flex items-center gap-1.5">

                                      <i data-lucide="file-text" class="w-4 h-4 text-slate-400"></i> รายละเอียดงานแจ้งซ่อม

                                  </span>

                                  <span class="text-xs font-extrabold px-3 py-1 rounded-xl bg-slate-800 text-white shadow-2xs font-mono" id="assign_modal_room">ห้อง -</span>

                              </div>



                              <div class="flex items-center justify-between text-xs">

                                  <span class="text-slate-500 font-semibold">หมวดหมู่งาน:</span>

                                  <span class="font-bold text-slate-800 bg-white px-2.5 py-1 rounded-lg border border-slate-200" id="assign_modal_category">-</span>

                              </div>



                              <div>

                                  <span class="text-xs text-slate-500 font-semibold block mb-1.5">รายละเอียดปัญหา / อาการที่พบ:</span>

                                  <div class="text-xs sm:text-sm text-slate-800 bg-white p-3.5 rounded-2xl border border-slate-200 leading-relaxed shadow-2xs min-h-[70px]" id="assign_modal_desc">-</div>

                              </div>

                          </div>



                          <!-- การ์ด 2: วัน-เวลาที่ผู้เช่าเลือกนัดหมายเข้าซ่อม (ดึงมาจากคำขอของผู้เช่าอัตโนมัติ) -->

                          <div class="p-5 bg-gradient-to-br from-rose-50/60 via-pink-50/40 to-rose-50/60 rounded-3xl border-2 border-rose-200 space-y-4 shadow-xs">

                              <div class="flex items-center justify-between pb-3 border-b border-rose-200/70">

                                  <span class="flex items-center gap-2 text-xs sm:text-sm font-bold text-rose-950">

                                      <div class="w-7 h-7 rounded-lg bg-rose-500 text-white flex items-center justify-center shadow-2xs">

                                          <i data-lucide="calendar" class="w-4 h-4"></i>

                                      </div>

                                      <span>วัน-เวลาที่ผู้เช่าเลือกนัดหมายเข้าซ่อม</span>

                                  </span>

                                  <span id="assign_modal_allow_entry" class="text-xs px-3 py-1 rounded-full font-bold bg-white text-rose-700 border border-rose-200 shadow-2xs">

                                      -

                                  </span>

                              </div>



                              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">

                                  <div class="bg-white p-4 rounded-2xl border border-rose-100 flex items-center gap-3 shadow-2xs">

                                      <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">

                                          <i data-lucide="calendar-check" class="w-5 h-5"></i>

                                      </div>

                                      <div>

                                          <div class="text-[11px] text-slate-400 font-medium">วันที่ผู้เช่าสะดวก</div>

                                          <div class="text-sm sm:text-base font-extrabold text-slate-900 font-mono" id="assign_modal_preferred_date">-</div>

                                      </div>

                                  </div>



                                  <div class="bg-white p-4 rounded-2xl border border-rose-100 flex items-center gap-3 shadow-2xs">

                                      <div class="w-10 h-10 rounded-xl bg-pink-50 text-pink-600 flex items-center justify-center shrink-0">

                                          <i data-lucide="clock" class="w-5 h-5"></i>

                                      </div>

                                      <div>

                                          <div class="text-[11px] text-slate-400 font-medium">ช่วงเวลาที่สะดวก</div>

                                          <div class="text-sm sm:text-base font-extrabold text-slate-900" id="assign_modal_preferred_time">-</div>

                                      </div>

                                  </div>

                              </div>



                              <p class="text-[11px] text-rose-800/80 leading-relaxed bg-white/70 p-3 rounded-xl border border-rose-100/60 flex items-start gap-1.5">

                                  <i data-lucide="info" class="w-4 h-4 text-rose-600 shrink-0 mt-0.5"></i>

                                  <span>เวลาข้างต้นเป็นช่วงเวลาที่ผู้เช่ากำหนดไว้ในคำขอแจ้งซ่อม สามารถมอบหมายให้ช่างที่พร้อมรับงานเพื่อเข้าดำเนินการตามเวลานี้ได้ทันที</span>

                              </p>

                          </div>



                      </div>



                      <!-- RIGHT COLUMN: การมอบหมายงานให้ช่างที่ว่าง -->

                      <div class="space-y-5">

                          

                          <div class="p-5 sm:p-6 bg-slate-50/80 rounded-3xl border border-slate-200/80 space-y-4 shadow-xs">

                              <div>

                                  <label class="block text-sm font-bold text-slate-800 mb-2 flex items-center justify-between">

                                      <span class="flex items-center gap-1.5">

                                          <i data-lucide="wrench" class="w-4 h-4 text-blue-600"></i> เลือกพนักงานผู้รับผิดชอบ <span class="text-rose-500">*</span>

                                      </span>

                                      <span id="jobTypeHint" class="text-xs font-semibold text-blue-600"></span>

                                  </label>



                                  <div class="relative">

                                      <select name="assigned_to" id="assigned_to_select" required class="w-full px-4 py-3.5 pr-10 text-xs sm:text-sm bg-white border-2 border-stone-200 hover:border-blue-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 rounded-2xl outline-none font-bold text-slate-800 transition appearance-none cursor-pointer shadow-2xs">

                                          <option value="">-- กรุณาเลือกพนักงานที่ว่าง --</option>

                                          

                                          <!-- กลุ่มช่างซ่อมบำรุง -->

                                          <optgroup label="🔧 ช่างซ่อมบำรุง (Technician)">

                                              <?php foreach($staffList as $st): 

                                                  if(!in_array($st['role'], ['Technician', 'Staff'])) continue;

                                                  $isBusy = isset($busyStaff[$st['userId']]);

                                                  $bInfo = $isBusy ? $busyStaff[$st['userId']] : null;

                                              ?>

                                                  <option value="<?php echo $st['userId']; ?>" 

                                                          data-role="Technician" 

                                                          data-busy="<?php echo $isBusy ? '1' : '0'; ?>"

                                                          <?php echo $isBusy ? 'disabled' : ''; ?>

                                                          class="<?php echo $isBusy ? 'text-slate-400 bg-slate-100 italic' : 'text-emerald-700 font-semibold'; ?>">

                                                      <?php if($isBusy): ?>

                                                          🔴 [ติดงาน] <?php echo htmlspecialchars($st['fullName']); ?> (ห้อง <?php echo htmlspecialchars($bInfo['roomNumber']); ?> • <?php echo $bInfo['estimatedHours']; ?> ชม.)

                                                      <?php else: ?>

                                                          🟢 [ว่าง] <?php echo htmlspecialchars($st['fullName']); ?> (พร้อมรับงาน) <?php echo !empty($st['phone']) ? '• โทร ' . $st['phone'] : ''; ?>

                                                      <?php endif; ?>

                                                  </option>

                                              <?php endforeach; ?>

                                          </optgroup>



                                          <!-- กลุ่มแม่บ้าน -->

                                          <optgroup label="🧹 พนักงานแม่บ้าน (Cleaner / Housekeeper)">

                                              <?php foreach($staffList as $st): 

                                                  if(!in_array($st['role'], ['Cleaner', 'Housekeeper'])) continue;

                                                  $isBusy = isset($busyStaff[$st['userId']]);

                                                  $bInfo = $isBusy ? $busyStaff[$st['userId']] : null;

                                              ?>

                                                  <option value="<?php echo $st['userId']; ?>" 

                                                          data-role="Cleaner" 

                                                          data-busy="<?php echo $isBusy ? '1' : '0'; ?>"

                                                          <?php echo $isBusy ? 'disabled' : ''; ?>

                                                          class="<?php echo $isBusy ? 'text-slate-400 bg-slate-100 italic' : 'text-emerald-700 font-semibold'; ?>">

                                                      <?php if($isBusy): ?>

                                                          🔴 [ติดงาน] <?php echo htmlspecialchars($st['fullName']); ?> (ห้อง <?php echo htmlspecialchars($bInfo['roomNumber']); ?> • <?php echo $bInfo['estimatedHours']; ?> ชม.)

                                                      <?php else: ?>

                                                          🟢 [ว่าง] <?php echo htmlspecialchars($st['fullName']); ?> (พร้อมรับงาน) <?php echo !empty($st['phone']) ? '• โทร ' . $st['phone'] : ''; ?>

                                                      <?php endif; ?>

                                                  </option>

                                              <?php endforeach; ?>

                                          </optgroup>

                                      </select>

                                      <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-blue-500">

                                          <i data-lucide="chevron-down" class="w-4 h-4"></i>

                                      </div>

                                  </div>



                                  <p id="staffSelectWarning" class="text-[11px] text-amber-600 font-medium mt-2 flex items-center gap-1.5">

                                      <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0"></i> พนักงานที่ติดงาน (🔴) จะไม่สามารถเลือกได้จนกว่าจะส่งงานเสร็จ

                                  </p>

                              </div>



                              <!-- คำแนะนำการมอบหมาย -->

                              <div class="p-4 bg-blue-50/60 rounded-2xl border border-blue-100 text-xs space-y-2 text-blue-950">

                                  <div class="font-bold flex items-center gap-1.5 text-blue-800">

                                      <i data-lucide="sparkles" class="w-4 h-4 text-blue-600"></i> ระบบเลือกช่างที่ว่างให้อัตโนมัติ:

                                  </div>

                                  <ul class="list-disc list-inside space-y-1 text-slate-600 pl-1 leading-relaxed">

                                      <li>ระบบจะเลือกพนักงานที่ <span class="font-bold text-emerald-700">🟢 ว่าง (พร้อมรับงาน)</span> ให้เป็นลำดับแรก</li>

                                      <li>เมื่อกดยืนยันมอบหมายงาน สถานะของพนักงานจะเปลี่ยนเป็น <span class="font-bold text-rose-600">🔴 ติดงาน</span> ทันที</li>

                                      <li>ผู้เช่าจะได้รับการแจ้งเตือนพร้อมข้อมูลชื่อและเบอร์โทรติดต่อของช่างผู้รับผิดชอบ</li>

                                  </ul>

                              </div>

                          </div>



                      </div>



                  </div>

              </div>



              <!-- Modal Footer -->

              <div class="p-4 sm:p-5 border-t border-slate-100 bg-slate-50 flex items-center justify-end gap-3 shrink-0">

                  <button type="button" onclick="closeAssignModal()" class="px-6 py-3 bg-white hover:bg-slate-100 text-slate-600 border border-slate-200 rounded-2xl text-xs sm:text-sm font-bold transition cursor-pointer shadow-2xs">

                      ยกเลิก

                  </button>

                  <button type="submit" name="assign_task" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl text-xs sm:text-sm font-bold shadow-md shadow-blue-500/25 transition flex items-center justify-center gap-2 cursor-pointer active:scale-98">

                      <i data-lucide="check" class="w-4 h-4"></i> ยืนยันมอบหมายงาน

                  </button>

              </div>

          </form>



      </div>

  </div>



  <!-- 🌟 MODAL: ดูรูปภาพขยาย 🌟 -->



  <div id="imagePreviewModal" class="hidden fixed inset-0 z-[60] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4 modal-enter" onclick="closeImageModal()">



      <div class="relative max-w-2xl w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">



          <button type="button" onclick="closeImageModal()" class="absolute -top-12 right-0 text-white/70 hover:text-rose-400 p-2 transition flex items-center gap-2 font-bold text-sm">



              ปิดรูปภาพ <i data-lucide="x-circle" class="w-6 h-6"></i>



          </button>



          <div id="imageTitle" class="absolute -top-10 left-0 text-white font-bold text-sm bg-black/50 px-3 py-1 rounded-full backdrop-blur-md"></div>



          <img id="previewImage" src="" alt="Image Preview" class="max-h-[80vh] max-w-full rounded-2xl shadow-2xl object-contain border-2 border-white/10 bg-black/50">



      </div>



  </div>



  <!-- 🌟 MODAL จอใหญ่: รายละเอียดคำขอแจ้งซ่อม/บริการแบบเต็ม แบ่ง 2 กรอบชัดเจน (ผู้เช่า VS ช่าง/แม่บ้าน) 🌟 -->



  <div id="requestDetailModal" style="display: none;" class="fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeDetailModal()">



      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-slate-200/80 flex flex-col" onclick="event.stopPropagation()">



          



          <!-- Modal Top Header -->



          <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/90 shrink-0">



              <div class="flex items-center gap-2.5 flex-wrap">



                  <span class="text-xs font-bold px-3 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">



                      ห้อง <span id="detail_room_number">-</span>



                  </span>



                  <span id="detail_req_code" class="text-xs font-mono font-bold text-slate-600 bg-white px-2.5 py-1 rounded-xl border border-slate-200 shadow-2xs">



                      REQ-0000



                  </span>



                  <div id="detail_status_badge" class="text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5 shadow-2xs"></div>



              </div>



              <div class="flex items-center gap-2">



                  <button type="button" onclick="closeDetailModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">



                      <i data-lucide="x" class="w-5 h-5"></i>



                  </button>



              </div>



          </div>



          <!-- Body: 2 Side-by-Side Large Frames -->



          <div class="p-5 sm:p-6 md:p-8 space-y-6 overflow-y-auto flex-1 min-h-0">



              



              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">



                  



                  <!-- ======================================================== -->



                  <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลของผู้เช่า (Tenant Frame)         -->



                  <!-- ======================================================== -->



                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-blue-200 bg-gradient-to-b from-blue-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">



                      <div>



                          <!-- Header กรอบข้อมูลผู้เช่า -->



                          <div class="flex items-center justify-between pb-3.5 border-b border-blue-200/80 mb-4">



                              <div class="flex items-center gap-2.5">



                                  <div class="w-9 h-9 rounded-2xl bg-blue-600 text-white flex items-center justify-center shrink-0 shadow-xs">



                                      <i data-lucide="user" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลของผู้เช่า</h4>



                                      <p class="text-[11px] text-blue-700 font-medium">รายละเอียดปัญหาที่แจ้ง และวันเวลาที่นัดหมาย</p>



                                  </div>



                              </div>



                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-100/80 text-blue-800 border border-blue-200 font-mono">



                                  ผู้เช่าห้องพัก



                              </span>



                          </div>



                          <!-- ประเภทงาน & กำหนดเวลา -->



                          <div class="space-y-1 mb-3.5">



                              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">ประเภทงาน / ปัญหาที่แจ้ง</span>



                              <div class="flex items-center justify-between gap-2 flex-wrap">



                                  <h3 class="text-base sm:text-lg font-bold text-slate-900" id="detail_category">-</h3>



                                  <div class="flex items-center gap-1.5 flex-wrap">



                                      <span id="detail_est_hours_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 font-mono shadow-2xs">⏱️ 2 ชม.</span>



                                      <span id="detail_job_type_badge" class="px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1.5 border shadow-2xs"></span>



                                      <span id="detail_key_badge" class="px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1.5 border shadow-2xs"></span>



                                  </div>



                              </div>



                          </div>



                          <!-- ข้อมูลติดต่อผู้เช่า -->



                          <div class="p-4 bg-white rounded-2xl border border-blue-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">



                              <div class="flex items-center gap-3">



                                  <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0 shadow-2xs font-bold">



                                      <i data-lucide="user" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">ผู้เช่า / ผู้แจ้งเรื่อง</span>



                                      <span id="detail_tenant_name" class="font-bold text-slate-900 text-sm sm:text-base">-</span>



                                      <div class="text-xs text-slate-500 font-mono mt-0.5" id="detail_tenant_phone">-</div>



                                  </div>



                              </div>



                              <a id="detail_tenant_call_btn" href="#" class="px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 rounded-xl text-xs font-bold border border-emerald-200 transition flex items-center gap-1.5 shadow-2xs">



                                  <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i>



                                  <span>โทรหาผู้เช่า</span>



                              </a>



                          </div>



                          <!-- รายละเอียดปัญหาที่แจ้ง -->



                          <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-2xs mb-4">



                              <div class="text-[11px] font-bold text-slate-400 mb-1.5 flex items-center gap-1.5">



                                  <i data-lucide="align-left" class="w-3.5 h-3.5 text-blue-600"></i> รายละเอียดปัญหาที่แจ้ง:



                              </div>



                              <p class="text-xs sm:text-sm text-slate-700 whitespace-pre-line leading-relaxed font-normal" id="detail_description">-</p>



                          </div>



                          <!-- นัดหมายวันเวลาเข้าห้อง -->



                          <div id="detail_appointment_box" class="p-3.5 bg-rose-50/80 border border-rose-200/90 rounded-2xl flex items-center gap-3 mb-4 text-xs shadow-2xs">



                              <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0">



                                  <i data-lucide="calendar-clock" class="w-4 h-4"></i>



                              </div>



                              <div>



                                  <span class="text-[10px] text-indigo-500 font-bold uppercase tracking-wider block">วันและช่วงเวลาที่นัดหมายเข้าห้อง</span>



                                  <span id="detail_preferred_text" class="font-bold text-indigo-950 text-xs sm:text-sm"></span>



                              </div>



                          </div>



                      </div>



                      <!-- 📷 รูปก่อนทำ (ปัญหาจากลูกบ้าน) BEFORE -->



                      <div class="bg-white border-2 border-dashed border-amber-300 rounded-2xl p-3.5 shadow-2xs">



                          <div class="flex items-center justify-between mb-2.5">



                              <span class="text-xs font-bold text-amber-900 flex items-center gap-1.5">



                                  <i data-lucide="camera" class="w-4 h-4 text-amber-600"></i> รูปถ่ายปัญหาหน้างาน (ก่อนทำ)



                              </span>



                              <span class="text-[10px] bg-amber-100 text-amber-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-amber-200">BEFORE</span>



                          </div>



                          



                          <div id="detail_before_img_container" class="min-h-[160px] flex items-center justify-center">



                              <!-- Dynamic Before Image or placeholder -->



                          </div>



                      </div>



                  </div>



                  <!-- ======================================================== -->



                  <!-- 🟩 กรอบที่ 2: ส่วนการปฏิบัติงานของช่าง/แม่บ้าน (Staff Frame) -->



                  <!-- ======================================================== -->



                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-emerald-200 bg-gradient-to-b from-emerald-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">



                      <div>



                          <!-- Header กรอบช่าง/แม่บ้าน -->



                          <div class="flex items-center justify-between pb-3.5 border-b border-emerald-200/80 mb-4">



                              <div class="flex items-center gap-2.5">



                                  <div class="w-9 h-9 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-xs">



                                      <i data-lucide="wrench" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <h4 class="font-display font-bold text-slate-900 text-base">ส่วนการปฏิบัติงานของช่าง / แม่บ้าน</h4>



                                      <p class="text-[11px] text-emerald-700 font-medium">เจ้าหน้าที่ผู้รับผิดชอบ ไทม์ไลน์ และผลงานที่ส่งมอบ</p>



                                  </div>



                              </div>



                              <span id="detail_staff_status_badge" class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">



                                  ทีมช่าง & แม่บ้าน



                              </span>



                          </div>



                          <!-- ข้อมูลเจ้าหน้าที่ผู้รับผิดชอบงาน -->



                          <div id="detail_staff_box" class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">



                              <div class="flex items-center gap-3">



                                  <div id="detail_staff_avatar" class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 shadow-2xs font-bold text-sm">



                                      W



                                  </div>



                                  <div>



                                      <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">เจ้าหน้าที่ผู้รับผิดชอบ</span>



                                      <div class="flex items-center gap-2">



                                          <span id="detail_staff_name" class="font-bold text-slate-900 text-sm sm:text-base">-</span>



                                          <span id="detail_staff_role" class="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">-</span>



                                      </div>



                                      <span id="detail_staff_phone" class="font-mono text-xs text-slate-500 mt-0.5 block"></span>



                                  </div>



                              </div>



                              <div id="detail_staff_call_box" class="flex items-center gap-2">



                                  <a id="detail_staff_call_btn" href="#" class="px-3.5 py-2 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 rounded-xl text-xs font-bold border border-emerald-200 transition flex items-center gap-1.5 shadow-2xs">



                                      <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i>



                                      <span>โทรหาพนักงาน</span>



                                  </a>



                              </div>



                          </div>



                          <!-- กรณียังไม่มอบหมาย -->



                          <div id="detail_unassigned_box" class="hidden p-4 bg-amber-50 border-2 border-dashed border-amber-300 rounded-2xl text-amber-900 text-xs flex items-center justify-between flex-wrap gap-2 mb-4 shadow-2xs">



                              <span class="flex items-center gap-2 font-bold text-sm">



                                  <i data-lucide="alert-circle" class="w-5 h-5 text-amber-600 shrink-0"></i> ยังไม่ได้มอบหมายงานให้พนักงาน



                              </span>



                              <button type="button" id="detail_assign_btn_inline" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition shadow-sm cursor-pointer flex items-center gap-1.5">



                                  <i data-lucide="user-plus" class="w-4 h-4"></i> มอบหมายทันที



                              </button>



                          </div>



                          <!-- บันทึกวันและเวลาการทำงาน (ไทม์ไลน์ 3 สเต็ป) -->



                          <div class="space-y-2 mb-4">



                              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">บันทึกวันและเวลาการทำงาน</span>



                              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">



                                  



                                  <!-- 1. วันที่แจ้งเรื่อง -->



                                  <div class="bg-white border border-amber-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-amber-700 flex items-center gap-1.5">



                                          <i data-lucide="send" class="w-3.5 h-3.5 text-amber-600"></i> 1. แจ้งเมื่อ



                                      </span>



                                      <div class="text-xs font-bold text-slate-900 mt-1 font-mono" id="detail_created_date">-</div>



                                      <div class="text-[10px] text-amber-800 font-semibold mt-0.5" id="detail_request_time"></div>



                                  </div>



                                  <!-- 2. นัดหมายเข้าทำ / รับงานเมื่อ -->



                                  <div class="bg-white border border-blue-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-blue-700 flex items-center gap-1.5">



                                          <i data-lucide="wrench" class="w-3.5 h-3.5 text-blue-600"></i> 2. รับงานเมื่อ



                                      </span>



                                      <div class="text-xs font-bold text-slate-900 mt-1 font-mono" id="detail_timeline_pref_date">-</div>



                                      <div class="text-[10px] text-blue-800 font-semibold mt-0.5" id="detail_timeline_pref_time"></div>



                                  </div>



                                  <!-- 3. วันที่เสร็จสิ้น -->



                                  <div id="detail_completed_box" class="bg-white border border-slate-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-slate-700 flex items-center gap-1.5">



                                          <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> 3. ส่งงาน/จบงาน



                                      </span>



                                      <div class="text-xs font-bold text-slate-900 mt-1 font-mono" id="detail_completed_at">-</div>



                                      <div class="text-[10px] text-emerald-800 font-semibold mt-0.5" id="detail_completed_label">ยังไม่เสร็จสิ้น</div>



                                  </div>



                              </div>



                          </div>



                          <!-- 📝 รายงานปัญหาเพิ่มเติมจากช่าง/ผู้ปฏิบัติงาน (เห็นเฉพาะแอดมิน) -->



                          <div id="detail_staff_comment_box" class="hidden bg-amber-50/70 border-2 border-dashed border-amber-300 rounded-2xl p-4 shadow-2xs space-y-2.5 mb-4">



                              <div class="flex items-center justify-between flex-wrap gap-2">



                                  <span class="flex items-center gap-2 text-xs font-bold text-amber-950">



                                      <i data-lucide="message-square-warning" class="w-4 h-4 text-amber-600"></i>



                                      รายงาน / บันทึกปัญหาเพิ่มเติมถึงแอดมิน



                                  </span>



                                  <span class="text-[10px] bg-white text-amber-800 font-extrabold px-2.5 py-0.5 rounded-lg border border-amber-200 flex items-center gap-1 shadow-2xs">



                                      <i data-lucide="lock" class="w-3 h-3 text-amber-600"></i> ลับเฉพาะแอดมิน (ลูกบ้านไม่เห็น)



                                  </span>



                              </div>



                              <div id="detail_staff_comment_text" class="text-xs sm:text-sm text-slate-800 bg-white p-3.5 rounded-xl border border-amber-300 leading-relaxed font-sans min-h-[48px] flex items-center whitespace-pre-line shadow-2xs font-medium">



                                  -



                              </div>



                              <div class="flex items-center justify-between text-[11px] text-amber-900 pt-0.5">



                                  <span id="detail_staff_comment_author"></span>



                                  <span id="detail_staff_comment_time" class="font-mono"></span>



                              </div>



                          </div>



                      </div>



                      <!-- ✨ รูปหลังทำ (ผลงานที่ช่างส่งมอบ) AFTER -->



                      <div class="bg-white border-2 border-dashed border-emerald-300 rounded-2xl p-3.5 shadow-2xs">



                          <div class="flex items-center justify-between mb-2.5">



                              <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">



                                  <i data-lucide="sparkles" class="w-4 h-4 text-emerald-600"></i> รูปผลงานการซ่อม (หลังทำ)



                              </span>



                              <span class="text-[10px] bg-emerald-100 text-emerald-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-emerald-200">AFTER</span>



                          </div>



                          <div id="detail_after_img_container" class="min-h-[160px] flex items-center justify-center">



                              <!-- Dynamic After Image -->



                          </div>



                      </div>



                  </div>



              </div>



              <!-- ======================================================== -->



              <!-- 🟪 กรอบที่ 3: ส่วนการประเมินและข้อความติชม (Feedback)      -->



              <!-- ======================================================== -->



              <div id="detail_comment_container" class="hidden bg-purple-50/70 border-2 border-purple-200 rounded-3xl p-5 shadow-2xs">



                  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">



                      <span class="text-xs sm:text-sm font-bold text-purple-950 flex items-center gap-2">



                          <i data-lucide="message-square-quote" class="w-4 h-4 text-purple-600"></i>



                          ความคิดเห็นและการติชมผลงานจากผู้เช่า



                      </span>



                      <span class="text-[11px] bg-white text-purple-700 font-bold px-3 py-1 rounded-full border border-purple-200 flex items-center gap-1.5 shadow-2xs">



                          <i data-lucide="lock" class="w-3.5 h-3.5 text-purple-500"></i> ลับเฉพาะคุณและนิติบุคคล



                      </span>



                  </div>



                  <div class="bg-white/90 p-4 rounded-xl border border-purple-100 text-xs sm:text-sm text-slate-700 leading-relaxed whitespace-pre-line font-sans" id="detail_comment_text">



                      -



                  </div>



                  <div id="detail_comment_time" class="text-[10px] text-purple-700 mt-2 font-medium text-right"></div>



              </div>



          </div>



          <!-- Footer Actions -->



          <div class="px-6 py-4 border-t border-slate-100 bg-slate-50 flex flex-wrap items-center justify-between gap-3 shrink-0">



              <button type="button" onclick="closeDetailModal()" class="px-5 py-2.5 bg-white hover:bg-slate-100 border border-slate-200 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer shadow-2xs">



                  ปิดหน้าต่าง



              </button>



              <div id="detail_modal_actions" class="flex items-center gap-2 flex-wrap">



                  <!-- Action buttons like assign, reassign -->



              </div>



          </div>



      </div>



  </div>



  <script>



    lucide.createIcons();



    // ข้อมูลคำขอทั้งหมดสำหรับ Detail Modal



    window.requestsData = <?php 



        $indexedRequests = [];



        foreach($requests as $r) {



            $indexedRequests[$r['requestId']] = [



                'requestId' => (int)$r['requestId'],



                'reqCode' => 'REQ-' . str_pad($r['requestId'], 4, '0', STR_PAD_LEFT),



                'roomId' => (int)$r['roomId'],



                'roomNumber' => $r['roomNumber'],



                'tenantId' => (int)$r['tenantId'],



                'tenantName' => $r['tenantName'],



                'tenantPhone' => $r['tenantPhone'] ?? '',



                'category' => $r['category'],



                'description' => $r['description'],



                'requestImage' => $r['requestImage'] ?? '',



                'allowEntry' => (int)$r['allowEntry'],



                'preferredDate' => $r['preferredDate'] ? date('d/m/Y', strtotime($r['preferredDate'])) : '-',



                'preferredTime' => $r['preferredTime'] ?? '',



                'requestDate' => $r['requestDate'] ? date('d/m/Y H:i', strtotime($r['requestDate'])) : '-',



                'requestDateRaw' => $r['requestDate'] ?? '',



                'status' => $r['status'],



                'assignedTo' => $r['assignedTo'] ? (int)$r['assignedTo'] : null,



                'assignedAt' => !empty($r['assignedAt']) ? date('d/m/Y H:i', strtotime($r['assignedAt'])) : '',



                'timeAgoAssigned' => !empty($r['assignedAt']) ? time_ago_th($r['assignedAt']) : '',



                'staffName' => $r['staffName'] ?? '',



                'staffRole' => $r['staffRole'] ?? '',



                'staffPhone' => $r['staffPhone'] ?? '',



                'completionImage' => $r['completionImage'] ?? '',



                'completedAt' => $r['completedAt'] ? date('d/m/Y H:i', strtotime($r['completedAt'])) : '',



                'completedAtRaw' => $r['completedAt'] ?? '',



                'duration' => (!empty($r['requestDate']) && !empty($r['completedAt'])) ? duration_th($r['requestDate'], $r['completedAt']) : '',



                'timeAgoRequest' => !empty($r['requestDate']) ? time_ago_th($r['requestDate']) : '',



                'timeAgoCompleted' => !empty($r['completedAt']) ? time_ago_th($r['completedAt']) : '',



                'tenantComment' => $r['tenantComment'] ?? '',



                'commentedAt' => $r['commentedAt'] ? date('d/m/Y H:i', strtotime($r['commentedAt'])) : '',



                'estimatedHours' => (int)($r['estimatedHours'] ?? 2),



                'isCleaning' => (mb_strpos($r['category'], 'ความสะอาด', 0, 'UTF-8') !== false || mb_strpos($r['category'], 'ขยะ', 0, 'UTF-8') !== false),



                'staffComment' => $r['staffComment'] ?? '',
                'staffCommentedAt' => !empty($r['staffCommentedAt']) ? date('d/m/Y H:i', strtotime($r['staffCommentedAt'])) : '',
                'adminRemark' => $r['adminRemark'] ?? '',
                'adminApprovedAt' => !empty($r['adminApprovedAt']) ? date('d/m/Y H:i', strtotime($r['adminApprovedAt'])) : ''
            ];



        }



        echo json_encode($indexedRequests, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);



    ?>;



    // เปิด Modal ดูรายละเอียดเต็ม



    function openDetailModal(reqId) {



        const modal = document.getElementById('requestDetailModal');



        if (!modal) return;



        const data = window.requestsData ? (window.requestsData[reqId] || window.requestsData[String(reqId)]) : null;



        if (!data) {



            console.warn("No data found for request ID:", reqId);



            return;



        }



        modal.style.display = 'flex';



        try {



            document.getElementById('detail_room_number').innerText = (data.roomNumber || '-');



            document.getElementById('detail_req_code').innerText = data.reqCode || '-';



            document.getElementById('detail_category').innerText = data.category || '-';



            document.getElementById('detail_description').innerText = data.description || '-';



            document.getElementById('detail_tenant_name').innerText = data.tenantName || '-';



            document.getElementById('detail_tenant_phone').innerText = data.tenantPhone || 'ไม่ระบุเบอร์โทร';



            



            // กำหนดเวลา



            const estHoursBadge = document.getElementById('detail_est_hours_badge');



            if (estHoursBadge) {



                estHoursBadge.innerText = '⏱️ ' + (data.estimatedHours || 2) + ' ชม.';



            }



            // นัดหมายเข้าห้อง



            const apptBox = document.getElementById('detail_appointment_box');



            const prefText = document.getElementById('detail_preferred_text');



            if ((data.preferredDate && data.preferredDate !== '-') || data.preferredTime) {
                apptBox.classList.remove('hidden');
                let prefStr = 'นัดเข้า: ';
                if (data.preferredDate && data.preferredDate !== '-') {
                    prefStr += data.preferredDate + (data.preferredTime ? ' (' + data.preferredTime + ')' : '');
                } else {
                    prefStr += data.preferredTime;
                }
                prefText.innerText = prefStr;
            } else {
                apptBox.classList.add('hidden');
            }



            // ไทม์ไลน์ 1. แจ้งเมื่อ



            document.getElementById('detail_created_date').innerText = (data.requestDate || '-') + ' น.';



            document.getElementById('detail_request_time').innerText = '';



            



            // ไทม์ไลน์ 2. รับงานเมื่อ / นัดหมาย



            const timePrefDate = document.getElementById('detail_timeline_pref_date');



            const timePrefTime = document.getElementById('detail_timeline_pref_time');



            if (data.assignedAt) {



                timePrefDate.innerText = data.assignedAt + ' น.';



                timePrefTime.innerText = data.timeAgoAssigned ? '(' + data.timeAgoAssigned + ')' : '';



            } else if (data.preferredDate && data.preferredDate !== '-') {



                timePrefDate.innerText = data.preferredDate;



                timePrefTime.innerText = data.preferredTime ? ('⏰ ' + data.preferredTime) : '';



            } else {



                timePrefDate.innerText = 'ตามคิวงาน';



                timePrefTime.innerText = '';



            }



            // ไทม์ไลน์ 3. ส่งงาน/จบงาน



            const completedAtEl = document.getElementById('detail_completed_at');



            const completedLabelEl = document.getElementById('detail_completed_label');



            const completedBox = document.getElementById('detail_completed_box');



            if (data.status === 'Completed' && data.completedAt) {



                completedAtEl.innerText = data.completedAt + ' น.';



                let compLabel = data.duration ? ('⏱️ ใช้เวลา: ' + data.duration) : '';



                if (!compLabel) {
                    compLabel = 'เสร็จสิ้นเรียบร้อย';
                }



                completedLabelEl.innerText = compLabel;



                completedBox.className = 'bg-white border border-emerald-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs';



            } else if (data.status === 'Completed') {



                completedAtEl.innerText = '-';



                completedLabelEl.innerText = 'เสร็จสิ้นแล้ว (ไม่มีบันทึกเวลา)';



                completedBox.className = 'bg-white border border-emerald-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs';



            } else {



                completedAtEl.innerText = '-';



                completedLabelEl.innerText = 'ยังไม่เสร็จสิ้น';



                completedBox.className = 'bg-white border border-slate-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs';



            }



            // Phone call link



            const callBtn = document.getElementById('detail_tenant_call_btn');



            if (data.tenantPhone) {



                callBtn.href = 'tel:' + data.tenantPhone;



                callBtn.classList.remove('hidden');



            } else {



                callBtn.classList.add('hidden');



            }



            // Status Badge



            const statusBadge = document.getElementById('detail_status_badge');



            if (data.status === 'Pending') {



                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-1.5 shadow-2xs';



                statusBadge.innerHTML = '<i data-lucide="inbox" class="w-3.5 h-3.5 text-amber-600"></i> รอมอบหมายงาน';



            } else if (data.status === 'In Progress') {



                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 flex items-center gap-1.5 shadow-2xs';



                statusBadge.innerHTML = '<i data-lucide="wrench" class="w-3.5 h-3.5 text-blue-600"></i> นิติบุคคลมอบหมายงานแล้ว';

            } else if (data.status === 'Pending Approval') {

                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-orange-50 text-orange-800 border border-orange-300 flex items-center gap-1.5 shadow-2xs';

                statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-orange-600"></i> ช่างส่งงานแล้ว (รอแอดมินอนุมัติ)';

            } else if (data.status === 'Completed') {

                statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5 shadow-2xs';

                statusBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> เสร็จสิ้นแล้ว';

            }



            // Job Type Badge



            const jobBadge = document.getElementById('detail_job_type_badge');



            if (data.isCleaning) {



                jobBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-teal-50 text-teal-700 border border-teal-200 shadow-2xs';



                jobBadge.innerHTML = '<i data-lucide="sparkles" class="w-3.5 h-3.5"></i> งานแม่บ้าน';



            } else {



                jobBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs';



                jobBadge.innerHTML = '<i data-lucide="wrench" class="w-3.5 h-3.5"></i> งานช่าง';



            }



            // Key Permission Badge



            const keyBadge = document.getElementById('detail_key_badge');



            if (data.allowEntry == 1) {



                keyBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs';



                keyBadge.innerHTML = '<i data-lucide="key" class="w-3.5 h-3.5"></i> ใช้กุญแจสำรองได้';



            } else {



                keyBadge.className = 'px-2.5 py-1 rounded-xl text-xs font-bold flex items-center gap-1 bg-slate-100 text-slate-600 border border-slate-200 shadow-2xs';



                keyBadge.innerHTML = '<i data-lucide="lock" class="w-3.5 h-3.5"></i> ต้องมีผู้เช่าอยู่ห้อง';



            }



            // Before Image Container



            const beforeContainer = document.getElementById('detail_before_img_container');



            if (data.requestImage) {



                beforeContainer.innerHTML = `



                    <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-amber-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${data.requestImage}', 'รูปก่อนทำ (ปัญหาจากลูกบ้าน - ห้อง ${data.roomNumber})')">



                        <img src="${data.requestImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="ก่อนทำ">



                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">



                            <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>



                        </div>



                    </div>`;



            } else {



                beforeContainer.innerHTML = `



                    <div class="w-full h-32 rounded-2xl bg-white border border-dashed border-amber-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">



                        <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>



                        <span>ลูกบ้านไม่ได้แนบรูป</span>



                    </div>`;



            }



            // Staff Info



            const staffBox = document.getElementById('detail_staff_box');



            const unassignedBox = document.getElementById('detail_unassigned_box');



            const staffStatusBadge = document.getElementById('detail_staff_status_badge');



            if (data.assignedTo && data.staffName) {



                staffBox.classList.remove('hidden');



                unassignedBox.classList.add('hidden');



                document.getElementById('detail_staff_name').innerText = data.staffName;



                document.getElementById('detail_staff_avatar').innerText = (data.staffName || 'S').substring(0, 1).toUpperCase();



                



                const isCleaner = ['Cleaner', 'Housekeeper'].includes(data.staffRole);



                document.getElementById('detail_staff_role').innerText = isCleaner ? 'แม่บ้าน' : 'ช่างซ่อมบำรุง';



                document.getElementById('detail_staff_phone').innerText = data.staffPhone ? '• 📞 ' + data.staffPhone : '';



                



                const staffCallBtn = document.getElementById('detail_staff_call_btn');



                if (data.staffPhone) {



                    staffCallBtn.href = 'tel:' + data.staffPhone;



                    document.getElementById('detail_staff_call_box').classList.remove('hidden');



                } else {



                    document.getElementById('detail_staff_call_box').classList.add('hidden');



                }



                staffStatusBadge.innerText = 'มอบหมายแล้ว';



                staffStatusBadge.className = 'text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-100/80 text-blue-800 border border-blue-200 font-mono';



            } else {



                staffBox.classList.add('hidden');



                unassignedBox.classList.remove('hidden');



                staffStatusBadge.innerText = 'รอมอบหมาย';



                staffStatusBadge.className = 'text-[11px] font-bold px-2.5 py-1 rounded-xl bg-amber-100/80 text-amber-800 border border-amber-200 font-mono';



                document.getElementById('detail_assign_btn_inline').onclick = function() {



                    closeDetailModal();



                    openAssignModal(data.requestId, data.roomNumber, data.category, data.description, data.isCleaning ? 'cleaning' : 'repair', '', data.estimatedHours || 2);



                };



            }



            // After Image Container



            const afterContainer = document.getElementById('detail_after_img_container');



            if (data.completionImage) {



                afterContainer.innerHTML = `



                    <div class="relative group cursor-pointer w-full rounded-2xl overflow-hidden border border-emerald-300 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${data.completionImage}', 'รูปหลังทำ (ผลงานที่ส่งมอบ - ห้อง ${data.roomNumber})')">



                        <img src="${data.completionImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="หลังทำ">



                        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">



                            <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>



                        </div>



                    </div>`;



            } else {



                afterContainer.innerHTML = `



                    <div class="w-full h-32 rounded-2xl bg-white border border-dashed border-emerald-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">



                        <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>



                        <span>${data.status === 'Completed' ? 'ไม่มีรูปภาพผลงาน' : 'ยังไม่ได้ส่งมอบงาน'}</span>



                    </div>`;



            }



            // Staff Report / Comment to Admin



            const staffCommBox = document.getElementById('detail_staff_comment_box');



            if (staffCommBox) {



                if (data.staffComment) {



                    staffCommBox.classList.remove('hidden');



                    document.getElementById('detail_staff_comment_text').innerText = data.staffComment;



                    document.getElementById('detail_staff_comment_author').innerText = 'รายงานโดย: ' + (data.staffName || 'เจ้าหน้าที่') + (data.staffRole ? ' (' + data.staffRole + ')' : '');



                    document.getElementById('detail_staff_comment_time').innerText = data.staffCommentedAt ? ('บันทึกเมื่อ: ' + data.staffCommentedAt + ' น.') : '';



                } else {



                    staffCommBox.classList.add('hidden');



                }



            }



            // Tenant Feedback / Comment



            const commentContainer = document.getElementById('detail_comment_container');



            if (data.tenantComment) {



                document.getElementById('detail_comment_text').innerText = '"' + data.tenantComment + '"';



                document.getElementById('detail_comment_time').innerText = data.commentedAt ? 'ส่งข้อคิดเห็นเมื่อ: ' + data.commentedAt + ' น.' : '';



                commentContainer.classList.remove('hidden');



            } else {



                commentContainer.classList.add('hidden');



            }



            // Action buttons in modal footer



            const actionsBox = document.getElementById('detail_modal_actions');



            actionsBox.innerHTML = '';



            if (data.status === 'Pending') {



                const assignBtn = document.createElement('button');



                assignBtn.type = 'button';



                assignBtn.className = 'px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm cursor-pointer';



                assignBtn.innerHTML = '<i data-lucide="user-plus" class="w-4 h-4"></i> มอบหมายงาน';



                assignBtn.onclick = function() {



                    closeDetailModal();



                    openAssignModal(data.requestId, data.roomNumber, data.category, data.description, data.isCleaning ? 'cleaning' : 'repair', '', data.estimatedHours || 2);



                };



                actionsBox.appendChild(assignBtn);



            } else if (data.status === 'In Progress') {



                const changeStaffBtn = document.createElement('button');



                changeStaffBtn.type = 'button';



                changeStaffBtn.className = 'px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer';



                changeStaffBtn.innerHTML = '<i data-lucide="user-cog" class="w-4 h-4"></i> เปลี่ยนช่าง/คนทำ';



                changeStaffBtn.onclick = function() {



                    closeDetailModal();



                    openAssignModal(data.requestId, data.roomNumber, data.category, data.description, data.isCleaning ? 'cleaning' : 'repair', data.assignedTo, data.estimatedHours || 2);



                };



                actionsBox.appendChild(changeStaffBtn);

                const completeBtn = document.createElement('button');
                completeBtn.type = 'button';
                completeBtn.className = 'px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm cursor-pointer';
                completeBtn.innerHTML = '<i data-lucide="check-circle" class="w-4 h-4"></i> จบงาน/เสร็จสิ้น';
                completeBtn.onclick = function() {
                    closeDetailModal();
                    openAdminCompleteModal(data.requestId, data.roomNumber, data.category);
                };
                actionsBox.appendChild(completeBtn);

            } else if (data.status === 'Pending Approval') {

                const approveBtn = document.createElement('button');
                approveBtn.type = 'button';
                approveBtn.className = 'px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm cursor-pointer';
                approveBtn.innerHTML = '<i data-lucide="check-check" class="w-4 h-4"></i> อนุมัติส่งมอบงาน (Completed)';
                approveBtn.onclick = function() {
                    if (confirm('ยืนยันอนุมัติการส่งมอบงานนี้? (ผู้เช่าจะเห็นงานว่าเสร็จสิ้นทันที)')) {
                        submitApproveJob(data.requestId);
                    }
                };
                actionsBox.appendChild(approveBtn);

                if (data.completionImage) {
                    const viewCompBtn = document.createElement('button');
                    viewCompBtn.type = 'button';
                    viewCompBtn.className = 'px-4 py-2 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer';
                    viewCompBtn.innerHTML = '<i data-lucide="camera" class="w-4 h-4 text-amber-600"></i> ตรวจรูปผลงาน';
                    viewCompBtn.onclick = function() {
                        openImageModal(data.completionImage, 'รูปผลงานการซ่อม (ห้อง ' + data.roomNumber + ')');
                    };
                    actionsBox.appendChild(viewCompBtn);
                }

                const rejectBtn = document.createElement('button');
                rejectBtn.type = 'button';
                rejectBtn.className = 'px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer';
                rejectBtn.innerHTML = '<i data-lucide="rotate-ccw" class="w-4 h-4 text-rose-600"></i> ส่งกลับให้ช่างแก้ไข';
                rejectBtn.onclick = function() {
                    closeDetailModal();
                    openRejectJobModal(data.requestId, data.roomNumber);
                };
                actionsBox.appendChild(rejectBtn);

            } else if (data.status === 'Completed' && data.completionImage) {



                const viewCompBtn = document.createElement('button');



                viewCompBtn.type = 'button';



                viewCompBtn.className = 'px-4 py-2 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 cursor-pointer';



                viewCompBtn.innerHTML = '<i data-lucide="camera" class="w-4 h-4"></i> ตรวจผลงาน';



                viewCompBtn.onclick = function() {



                    openImageModal(data.completionImage, 'รูปผลงาน: ห้อง ' + data.roomNumber);



                };



                actionsBox.appendChild(viewCompBtn);



            }



            if (window.lucide) {



                lucide.createIcons();



            }



        } catch (err) {



            console.error("Error populating detail modal:", err);



        }



    }



    function closeDetailModal() {



        const modal = document.getElementById('requestDetailModal');



        if (modal) {



            modal.style.display = 'none';



        }



    }



    // 🌟 ฟังก์ชันกรองรายการแจ้งซ่อมตามแท็บสถานะ 🌟

    function filterByStatus(status) {

        const cardMap = {

            'all': 'filter-btn-all',

            'Pending': 'filter-btn-Pending',

            'In Progress': 'filter-btn-In_Progress',

            'In_Progress': 'filter-btn-In_Progress',

            'Pending Approval': 'filter-btn-Pending_Approval',

            'Pending_Approval': 'filter-btn-Pending_Approval',

            'Completed': 'filter-btn-Completed',

            'commented': 'filter-btn-commented'

        };

        

        let normalizedStatus = status;

        if (status === 'In_Progress') normalizedStatus = 'In Progress';
        if (status === 'Pending_Approval') normalizedStatus = 'Pending Approval';



        document.querySelectorAll('.filter-status-card').forEach(c => {

            c.classList.remove('bg-slate-900', 'text-white', 'border-slate-900', 'shadow-sm');

            c.classList.add('bg-white', 'text-slate-800');

        });



        const activeId = cardMap[status] || cardMap[normalizedStatus] || 'filter-btn-all';

        const activeEl = document.getElementById(activeId);

        if (activeEl) {

            activeEl.classList.remove('bg-white', 'text-slate-800');

            activeEl.classList.add('bg-slate-900', 'text-white', 'border-slate-900', 'shadow-sm');

        }



        const rows = document.querySelectorAll('.request-table-row');

        let visibleCount = 0;

        rows.forEach(row => {

            const rowStatus = row.getAttribute('data-status');

            const hasComment = row.getAttribute('data-has-comment') === '1';

            let match = false;

            if (normalizedStatus === 'all') {

                match = true;

            } else if (normalizedStatus === 'commented') {

                match = hasComment;

            } else {

                match = (rowStatus === normalizedStatus);

            }

            if (match) {

                row.style.display = '';

                visibleCount++;

            } else {

                row.style.display = 'none';

            }

        });



        const noMatchRow = document.getElementById('no-matching-requests');

        if (noMatchRow) {

            if (visibleCount === 0 && rows.length > 0) {

                noMatchRow.classList.remove('hidden');

            } else {

                noMatchRow.classList.add('hidden');

            }

        }

    }



    // ซ่อน Alert อัตโนมัติ



    document.addEventListener("DOMContentLoaded", function() {

        // ตรวจสอบ Parameter จาก URL สำหรับแท็บสถานะและเปิด Modal รายละเอียดอัตโนมัติ

        const urlParams = new URLSearchParams(window.location.search);

        const statusParam = urlParams.get('status');

        const reqIdParam = urlParams.get('req_id') || urlParams.get('requestId') || urlParams.get('id');



        if (statusParam) {

            filterByStatus(statusParam);

        }

        if (reqIdParam) {

            setTimeout(function() {

                openDetailModal(reqIdParam);

            }, 300);

        }



        const alerts = document.querySelectorAll(".alert-box");



        alerts.forEach(function(alert) {



            setTimeout(function() {



                alert.style.opacity = "0";



                setTimeout(function() { alert.remove(); }, 500);



            }, 3000);



        });



    });



    // 🌟 เปิด Modal มอบหมายงาน พร้อมระบบเลือกพนักงานที่ว่างอัตโนมัติ และกำหนดเวลา 1 - 4 ชม. 🌟



    function openAssignModal(reqId, roomNumber, category, description, jobType, currentAssignedId, estimatedHours, preferredDate, preferredTime, allowEntry) {

        document.getElementById('assign_modal_request_id').value = reqId;

        document.getElementById('assign_modal_room').innerText = 'ห้อง ' + roomNumber;

        document.getElementById('assign_modal_category').innerText = category;

        document.getElementById('assign_modal_desc').innerText = description;

        

        // 🌟 ดึงข้อมูลวัน-เวลานัดหมายของผู้เช่า 🌟

        const data = window.requestsData ? (window.requestsData[reqId] || window.requestsData[String(reqId)]) : null;

        const prefDate = preferredDate || (data ? data.preferredDate : '-') || '-';

        const prefTime = preferredTime || (data ? data.preferredTime : '') || '';

        const isAllow = (allowEntry !== undefined && allowEntry !== null) ? allowEntry : (data ? data.allowEntry : 0);



        const dateEl = document.getElementById('assign_modal_preferred_date');

        if (dateEl) dateEl.innerText = (prefDate && prefDate !== '-') ? prefDate : 'ไม่ระบุวัน (ตามคิวงาน)';



        const timeEl = document.getElementById('assign_modal_preferred_time');

        if (timeEl) timeEl.innerText = prefTime ? prefTime : 'ตามสะดวก / คิวช่าง';



        const allowEl = document.getElementById('assign_modal_allow_entry');

        if (allowEl) {

            if (parseInt(isAllow) === 1 || isAllow === true) {

                allowEl.innerHTML = '<span class="inline-flex items-center gap-1">🔑 ยินยอมให้เข้าห้องได้ทันที</span>';

                allowEl.className = 'text-xs px-3 py-1 rounded-full font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs';

            } else {

                allowEl.innerHTML = '<span class="inline-flex items-center gap-1">👤 ต้องมีผู้เช่าอยู่ห้องด้วย</span>';

                allowEl.className = 'text-xs px-3 py-1 rounded-full font-bold bg-amber-50 text-amber-800 border border-amber-200 shadow-2xs';

            }

        }



        const select = document.getElementById('assigned_to_select');

        const options = Array.from(select.querySelectorAll('option'));

        let firstAvailableMatching = null;

        let firstAvailableAny = null;



        options.forEach(opt => {

            if (!opt.value) return;

            const isBusy = opt.getAttribute('data-busy') === '1';

            const optRole = opt.getAttribute('data-role'); // 'Technician' or 'Cleaner'

            const isCurrent = currentAssignedId && String(opt.value) === String(currentAssignedId);



            if (isCurrent) {

                opt.disabled = false;

            } else if (isBusy) {

                opt.disabled = true;

            } else {

                opt.disabled = false;

            }



            // ค้นหาพนักงานที่ว่างคนแรกตามสายงาน

            if (!opt.disabled) {

                if (!firstAvailableAny) firstAvailableAny = opt.value;

                if (jobType === 'cleaning' && optRole === 'Cleaner' && !firstAvailableMatching) {

                    firstAvailableMatching = opt.value;

                } else if (jobType !== 'cleaning' && optRole === 'Technician' && !firstAvailableMatching) {

                    firstAvailableMatching = opt.value;

                }

            }

        });



        // กำหนดค่าพนักงานเริ่มต้น: หากมีผู้รับผิดชอบเดิมใช้เดิม, หากไม่มีให้ fallback ไปหาคนที่ว่างทันที

        if (currentAssignedId) {

            select.value = currentAssignedId;

        } else if (firstAvailableMatching) {

            select.value = firstAvailableMatching;

        } else if (firstAvailableAny) {

            select.value = firstAvailableAny;

        } else {

            select.value = '';

        }



        // ข้อความใบ้ประเภทงาน

        const hintEl = document.getElementById('jobTypeHint');

        if (hintEl) {

            if (jobType === 'cleaning') {

                hintEl.innerText = '🧹 แนะนำ: มอบหมายให้ พนักงานแม่บ้าน';

            } else {

                hintEl.innerText = '🔧 แนะนำ: มอบหมายให้ ช่างซ่อมบำรุง';

            }

        }



        const modal = document.getElementById('assignModal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.style.display = 'flex';
        }

        if (window.lucide) lucide.createIcons();
    }

    function closeAssignModal() {
        const assignModal = document.getElementById('assignModal');
        if (assignModal) {
            assignModal.classList.remove('flex');
            assignModal.classList.add('hidden');
            assignModal.style.display = 'none';
        }
    }



    // เปิดดูรูปภาพ



    function openImageModal(url, titleText) {



        document.getElementById('previewImage').src = url;



        document.getElementById('imageTitle').textContent = titleText;



        const imgModal = document.getElementById('imagePreviewModal');



        imgModal.classList.remove('hidden');



        imgModal.style.display = 'flex';



    }



    



    function closeImageModal() {



        const imgModal = document.getElementById('imagePreviewModal');



        imgModal.classList.add('hidden');



        imgModal.style.display = 'none';



        document.getElementById('previewImage').src = ''; 



    }



    // ปิด Modal เมื่อกดปุ่ม ESC



    document.addEventListener('keydown', function(e) {



        if (e.key === 'Escape') {
            closeDetailModal();
            closeAssignModal();
            closeImageModal();
            closeAdminCompleteModal();
            closeRejectJobModal();
        }



    });



  </script>



  <script src="js/realtime-time.js"></script>

<script>



    // ฟังก์ชันสลับขยายเต็มหน้าจอ 100% / คืนขนาด



    function toggleFullscreenModal(btn) {



        const modal = btn.closest('[id$="Modal"]');



        if (!modal) return;



        const card = modal.querySelector(':scope > div');



        if (!card) return;



        const isFull = card.classList.contains('is-fullscreen-100');



        if (isFull) {



            card.classList.remove('is-fullscreen-100', '!w-full', '!h-full', '!max-w-none', '!rounded-none');



            modal.classList.remove('!p-0');



            btn.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';



            btn.title = 'ขยายเต็มหน้าจอ 100%';



        } else {



            card.classList.add('is-fullscreen-100', '!w-full', '!h-full', '!max-w-none', '!rounded-none');



            modal.classList.add('!p-0');



            btn.innerHTML = '<i data-lucide="eye" class="w-4 h-4"></i>';



            btn.title = 'คืนขนาดเดิม';



        }



        if (window.lucide) lucide.createIcons();



    }




    // 🌟 ฟังก์ชันอนุมัติส่งมอบงาน (Admin Approve) 🌟
    function submitApproveJob(reqId) {
        if (!confirm('ยืนยันอนุมัติผลงานและปิดงานนี้เป็น เสร็จสิ้น (Completed) หรือไม่?\n\nเมื่ออนุมัติแล้ว ผู้เช่าจะมองเห็นว่างานเสร็จสิ้น รูปภาพผลงาน และสามารถให้คะแนนประเมินได้ทันที')) {
            return;
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'request_id';
        idInput.value = reqId;
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'approve_job';
        actionInput.value = '1';
        form.appendChild(idInput);
        form.appendChild(actionInput);
        document.body.appendChild(form);
        form.submit();
    }

    // 🌟 ฟังก์ชันเปิด-ปิด Modal ส่งกลับให้ช่างแก้ไข 🌟
    function openRejectJobModal(reqId, roomNum) {
        const modal = document.getElementById('rejectJobModal');
        if (!modal) return;
        document.getElementById('reject_job_req_id').value = reqId;
        document.getElementById('reject_job_room_display').textContent = roomNum ? ('ห้อง ' + roomNum) : ('#' + reqId);
        document.getElementById('reject_job_remark').value = '';
        modal.classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
        setTimeout(() => {
            const ta = document.getElementById('reject_job_remark');
            if (ta) ta.focus();
        }, 100);
    }

    function closeRejectJobModal() {
        const modal = document.getElementById('rejectJobModal');
        if (modal) modal.classList.add('hidden');
    }

    // 🌟 ฟังก์ชันเปิด-ปิด Modal ทำเครื่องหมายเสร็จสิ้นงาน (Admin Complete) 🌟
    function openAdminCompleteModal(reqId, roomNumber, category) {
        const modal = document.getElementById('adminCompleteModal');
        if (!modal) return;
        document.getElementById('admin_complete_req_id').value = reqId;
        const roomDisplay = document.getElementById('admin_complete_room_display');
        if (roomDisplay) roomDisplay.innerText = roomNumber ? ('ห้อง ' + roomNumber) : ('#' + reqId);
        const catDisplay = document.getElementById('admin_complete_cat_display');
        if (catDisplay) catDisplay.innerText = category || '-';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }

    function closeAdminCompleteModal() {
        const modal = document.getElementById('adminCompleteModal');
        if (modal) {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
            modal.style.display = 'none';
        }
    }

    function validateAdminCompleteForm() {
        return true;
    }
</script>




  <!-- 🌟 Modal ทำเครื่องหมายเสร็จสิ้นงาน (Admin Complete Modal) 🌟 -->
  <div id="adminCompleteModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all duration-300 scale-95" id="adminCompleteModalCard">
      <div class="p-5 sm:p-6 bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 text-white flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center shrink-0">
            <i data-lucide="check-circle-2" class="w-6 h-6 text-white"></i>
          </div>
          <div>
            <h3 class="font-display font-bold text-lg text-white">จบงาน / บันทึกผลงานเสร็จสิ้น</h3>
            <p class="text-xs text-emerald-100 font-sans">อัปเดตสถานะเป็น Completed แสดงผลทันทีทุกฝ่าย</p>
          </div>
        </div>
        <button type="button" onclick="closeAdminCompleteModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form action="" method="POST" enctype="multipart/form-data" class="p-6 space-y-4 m-0" onsubmit="return validateAdminCompleteForm();">
        <input type="hidden" name="request_id" id="admin_complete_req_id" value="">
        <input type="hidden" name="admin_complete_task" value="1">

        <div class="p-3.5 bg-slate-50 rounded-2xl border border-slate-200 text-xs text-slate-700 space-y-1">
          <div class="flex items-center justify-between">
            <span class="text-slate-400">ห้องพัก:</span>
            <span class="font-bold text-slate-900 font-mono text-sm" id="admin_complete_room_display">-</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-400">หมวดหมู่งาน:</span>
            <span class="font-semibold text-slate-800" id="admin_complete_cat_display">-</span>
          </div>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <i data-lucide="camera" class="w-3.5 h-3.5 text-emerald-600"></i>
            <span>แนบรูปภาพผลงานหลังทำ (ไม่บังคับ):</span>
          </label>
          <input type="file" name="completion_image" accept="image/*" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer border border-slate-200 rounded-xl p-1 bg-white">
          <p class="text-[10px] text-slate-400 mt-1">ลูกบ้านและผู้เกี่ยวข้องจะสามารถเปิดดูรูปภาพนี้ได้ในประวัติงาน</p>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <i data-lucide="file-text" class="w-3.5 h-3.5 text-emerald-600"></i>
            <span>บันทึกผลการปฏิบัติงาน / หมายเหตุ:</span>
          </label>
          <textarea name="admin_comment" rows="3" placeholder="ระบุการแก้ไข เช่น ซ่อมเปลี่ยนหลอดไฟใหม่เรียบร้อย, ทำความสะอาดและตรวจเช็คแล้ว..." class="w-full p-3 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:border-emerald-500 text-slate-800 font-sans leading-relaxed"></textarea>
        </div>

        <div class="pt-2 flex items-center justify-end gap-3 border-t border-slate-100">
          <button type="button" onclick="closeAdminCompleteModal()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
            ยกเลิก
          </button>
          <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-md transition flex items-center gap-1.5">
            <i data-lucide="check" class="w-4 h-4"></i> ยืนยันการจบงาน (Completed)
          </button>
        </div>
      </form>
    </div>
  </div>


  <!-- 🌟 Modal ส่งกลับให้ช่างแก้ไข (Admin Reject / Request Changes Modal) 🌟 -->
  <div id="rejectJobModal" class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl border border-slate-200 overflow-hidden transform transition-all duration-300">
      <div class="p-5 sm:p-6 bg-gradient-to-r from-amber-600 via-orange-600 to-amber-700 text-white flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center shrink-0">
            <i data-lucide="rotate-ccw" class="w-6 h-6 text-white"></i>
          </div>
          <div>
            <h3 class="font-display font-bold text-lg text-white">ส่งกลับให้ช่างแก้ไข</h3>
            <p class="text-xs text-amber-100 font-sans">เปลี่ยนสถานะกลับเป็น In Progress พร้อมแจ้งหมายเหตุ</p>
          </div>
        </div>
        <button type="button" onclick="closeRejectJobModal()" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form action="" method="POST" class="p-6 space-y-4 m-0" onsubmit="return (document.getElementById('reject_job_remark').value.trim() !== '') || (alert('กรุณาระบุสิ่งที่ต้องการให้แก้ไข'), false);">
        <input type="hidden" name="request_id" id="reject_job_req_id" value="">
        <input type="hidden" name="reject_job" value="1">

        <div class="p-3.5 bg-amber-50 rounded-2xl border border-amber-200 text-xs text-amber-900 space-y-1">
          <div class="flex items-center justify-between">
            <span class="text-amber-700 font-medium">รายการห้องพัก:</span>
            <span class="font-bold font-mono text-sm" id="reject_job_room_display">-</span>
          </div>
          <p class="text-[11px] text-amber-700 pt-1 border-t border-amber-200/60 leading-relaxed">
            เมื่อส่งกลับ สถานะจะเปลี่ยนเป็น <strong>กำลังดำเนินการ (In Progress)</strong> ช่าง/แม่บ้านจะเห็นหมายเหตุในหน้ารายการงาน เพื่อเข้าแก้ไขงานต่อไป
          </p>
        </div>

        <div>
          <label class="block text-xs font-bold text-slate-700 mb-1.5 flex items-center gap-1.5">
            <i data-lucide="alert-circle" class="w-3.5 h-3.5 text-amber-600"></i>
            <span>ระบุสิ่งที่ต้องการให้แก้ไข / หมายเหตุถึงผู้ปฏิบัติงาน <span class="text-rose-500">*</span>:</span>
          </label>
          <textarea name="admin_remark" id="reject_job_remark" rows="4" required placeholder="เช่น งานสียังไม่เรียบร้อยตรงขอบประตู, กรุณาเก็บกวาดเศษอุปกรณ์หน้างานเพิ่มเติม..." class="w-full p-3 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:border-amber-500 text-slate-800 font-sans leading-relaxed"></textarea>
        </div>

        <div class="pt-2 flex items-center justify-end gap-3 border-t border-slate-100">
          <button type="button" onclick="closeRejectJobModal()" class="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition">
            ยกเลิก
          </button>
          <button type="submit" class="px-5 py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold shadow-md transition flex items-center gap-1.5">
            <i data-lucide="send" class="w-4 h-4"></i> ส่งกลับให้ช่างแก้ไข
          </button>
        </div>
      </form>
    </div>
  </div>

</body>



</html>