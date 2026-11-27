<?php



session_start();



require_once 'includes/db_connect.php';







// ตรวจสอบสิทธิ์ ต้องเป็น ลูกบ้าน (Tenant) เท่านั้น



if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Tenant') {



    header("Location: login.php");



    exit();



}







$tenantId = $_SESSION['user_id'];



$tenantName = $_SESSION['full_name'];



$initials = strtoupper(mb_substr($tenantName, 0, 2, 'UTF-8'));



$message = "";







// 1. ดึงข้อมูล roomId ปัจจุบันของผู้เช่า



$stmtRoom = $conn->prepare("



    SELECT r.roomNumber, l.roomId 



    FROM Lease l 



    JOIN Room r ON l.roomId = r.roomId 



    WHERE l.tenantId = ? AND l.status = 'Active' 



    LIMIT 1



");



$stmtRoom->execute([$tenantId]);



$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);







// --- ระบบลบ/ยกเลิกรายการแจ้งซ่อม (เฉพาะสถานะ Pending) ---



if (isset($_POST['delete_request'])) {



    $deleteId = intval($_POST['delete_id']);



    



    $stmtCheck = $conn->prepare("SELECT status, requestImage FROM ServiceRequest WHERE requestId = ? AND tenantId = ?");



    $stmtCheck->execute([$deleteId, $tenantId]);



    $reqToDel = $stmtCheck->fetch(PDO::FETCH_ASSOC);







    if ($reqToDel && $reqToDel['status'] === 'Pending') {



        if (!empty($reqToDel['requestImage']) && file_exists($reqToDel['requestImage'])) {



            @unlink($reqToDel['requestImage']);



        }



        $stmtDel = $conn->prepare("DELETE FROM ServiceRequest WHERE requestId = ?");



        if ($stmtDel->execute([$deleteId])) {



            $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle' class='w-5 h-5'></i> ยกเลิกรายการแจ้งซ่อมเรียบร้อยแล้วครับ</div>";



        }



    } else {



        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่สามารถยกเลิกได้ เนื่องจากนิติบุคคลรับเรื่องหรือมอบหมายงานแล้ว</div>";



    }



}







// --- ระบบบันทึกข้อคิดเห็น/ติชมงานจากผู้เช่า (AJAX Endpoint) ---

if (isset($_POST['action']) && $_POST['action'] === 'save_tenant_comment') {

    header('Content-Type: application/json; charset=utf-8');

    $reqId = intval($_POST['request_id'] ?? 0);

    $comment = trim($_POST['tenant_comment'] ?? '');



    $stmtCheck = $conn->prepare("SELECT requestId, status, completionImage FROM ServiceRequest WHERE requestId = ? AND tenantId = ?");

    $stmtCheck->execute([$reqId, $tenantId]);

    $checkReq = $stmtCheck->fetch(PDO::FETCH_ASSOC);



    if ($checkReq) {

        if ($checkReq['status'] !== 'Completed') {

            echo json_encode(['success' => false, 'message' => 'งานยังไม่ได้รับการอนุมัติเสร็จสิ้นจากนิติบุคคล ไม่สามารถส่งข้อคิดเห็นได้']);

            exit;

        }

        if (!empty($comment)) {

            $stmtComment = $conn->prepare("UPDATE ServiceRequest SET tenantComment = ?, commentedAt = NOW() WHERE requestId = ? AND tenantId = ?");

            if ($stmtComment->execute([$comment, $reqId, $tenantId])) {

                echo json_encode([

                    'success' => true,

                    'message' => 'บันทึกความคิดเห็นเรียบร้อยแล้ว!',

                    'tenantComment' => $comment,

                    'commentedAt' => date('d/m/Y H:i')

                ]);

            } else {

                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการบันทึกความคิดเห็น']);

            }

        } else {

            echo json_encode(['success' => false, 'message' => 'กรุณากรอกความคิดเห็นก่อนกดบันทึกครับ']);

        }

    } else {

        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลคำร้องนี้']);

    }

    exit;

}



// --- ระบบบันทึกข้อคิดเห็น/ติชมงานจากผู้เช่า (Form POST Fallback) ---

if (isset($_POST['submit_comment'])) {

    $reqId = intval($_POST['request_id']);

    $comment = trim($_POST['tenant_comment']);



    $stmtCheck = $conn->prepare("SELECT requestId, status, completionImage FROM ServiceRequest WHERE requestId = ? AND tenantId = ?");

    $stmtCheck->execute([$reqId, $tenantId]);

    $checkReq = $stmtCheck->fetch(PDO::FETCH_ASSOC);



    if ($checkReq) {

        if ($checkReq['status'] !== 'Completed') {

            echo json_encode(['success' => false, 'message' => 'งานยังไม่ได้รับการอนุมัติเสร็จสิ้นจากนิติบุคคล ไม่สามารถส่งข้อคิดเห็นได้']);

            exit;

        }

        if (!empty($comment)) {

            $stmtComment = $conn->prepare("UPDATE ServiceRequest SET tenantComment = ?, commentedAt = NOW() WHERE requestId = ? AND tenantId = ?");

            if ($stmtComment->execute([$comment, $reqId, $tenantId])) {

                $message = "<div class='alert-box p-4 mb-6 bg-purple-50 border-l-4 border-purple-500 text-purple-800 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle' class='w-5 h-5 text-purple-600'></i> บันทึกความคิดเห็น/ข้อเสนอแนะเรียบร้อยแล้ว! (ข้อความนี้จะแสดงเฉพาะคุณและนิติบุคคลเท่านั้น ช่างและแม่บ้านจะไม่เห็นข้อความนี้)</div>";

            } else {

                $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาดในการบันทึกความคิดเห็น</div>";

            }

        } else {

            $message = "<div class='alert-box p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> กรุณากรอกความคิดเห็นก่อนกดส่งครับ</div>";

        }

    } else {

        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่พบข้อมูลคำร้องนี้ครับ</div>";

    }

}

// --- ดึงประวัติการแจ้งซ่อมของลูกบ้านคนนี้ (แยกกรองตามห้องปัจจุบัน) ---



$myRoomId = $myRoom ? $myRoom['roomId'] : null;



$stmtReq = $conn->prepare("
    SELECT sr.*, r.roomNumber, staff.fullName AS staffName, staff.phone AS staffPhone, staff.role AS staffRole
    FROM ServiceRequest sr
    LEFT JOIN Room r ON sr.roomId = r.roomId
    LEFT JOIN User staff ON sr.assignedTo = staff.userId
    WHERE sr.tenantId = ?
    ORDER BY sr.requestId DESC
");
$stmtReq->execute([$tenantId]);



$requests = $stmtReq->fetchAll(PDO::FETCH_ASSOC);







// นับสถิติแต่ละสถานะ



$countTotal = count($requests);



$countPending = 0;



$countInProgress = 0;



$countCompleted = 0;



foreach($requests as $r) {



    if ($r['status'] === 'Pending') $countPending++;
        elseif ($r['status'] === 'In Progress' || $r['status'] === 'Pending Approval') $countInProgress++;



    elseif ($r['status'] === 'Completed') $countCompleted++;



}







// รวบรวมข้อมูลสำหรับ Request Detail Modal (แบบขยายดูรายละเอียดงานและเปรียบเทียบรูปภาพ ก่อน-หลัง)



$allRequestsData = [];



foreach ($requests as $r) {



    $assignedDate = !empty($r['assignedAt']) ? $r['assignedAt'] : (!empty($r['requestDate']) ? $r['requestDate'] : '');



    $dur = (!empty($r['assignedAt']) && !empty($r['completedAt'])) 



           ? duration_th($r['assignedAt'], $r['completedAt']) 



           : ((!empty($r['requestDate']) && !empty($r['completedAt'])) ? duration_th($r['requestDate'], $r['completedAt']) : '');







    $isCleanerRole = in_array($r['staffRole'] ?? '', ['Cleaner', 'Housekeeper']);



    $roleLabel = $isCleanerRole ? "แม่บ้าน" : "ช่างซ่อมบำรุง";







    $allRequestsData[$r['requestId']] = [



        'requestId' => (int)$r['requestId'],



        'reqCode' => 'REQ-' . str_pad($r['requestId'], 4, '0', STR_PAD_LEFT),



        'roomId' => (int)($r['roomId'] ?? 0),



        'roomNumber' => $r['roomNumber'] ?? ($myRoom['roomNumber'] ?? '-'),



        'category' => $r['category'],



        'description' => $r['description'] ?? '',



        'preferredDate' => !empty($r['preferredDate']) ? date('d/m/Y', strtotime($r['preferredDate'])) : '-',



        'preferredTime' => $r['preferredTime'] ?? '',



        'allowEntry' => (int)($r['allowEntry'] ?? 0),



        'status' => $r['status'],



        'requestDate' => !empty($r['requestDate']) ? date('d/m/Y H:i', strtotime($r['requestDate'])) : '-',



        'requestDateRaw' => $r['requestDate'] ?? '',



        'timeAgoRequest' => !empty($r['requestDate']) ? time_ago_th($r['requestDate']) : '',



        'assignedAt' => !empty($r['assignedAt']) ? date('d/m/Y H:i', strtotime($r['assignedAt'])) : '',



        'assignedAtRaw' => $r['assignedAt'] ?? '',



        'timeAgoAssigned' => !empty($r['assignedAt']) ? time_ago_th($r['assignedAt']) : '',



        'completedAt' => !empty($r['completedAt']) ? date('d/m/Y H:i', strtotime($r['completedAt'])) : '',



        'completedAtRaw' => $r['completedAt'] ?? '',



        'timeAgoCompleted' => !empty($r['completedAt']) ? time_ago_th($r['completedAt']) : '',



        'duration' => $dur,



        'staffName' => $r['staffName'] ?? '',



        'staffPhone' => $r['staffPhone'] ?? '',



        'staffRole' => $roleLabel,



        'requestImage' => $r['requestImage'] ?? '',



        'completionImage' => $r['completionImage'] ?? '',



        'tenantComment' => $r['tenantComment'] ?? '',



        'commentedAt' => !empty($r['commentedAt']) ? date('d/m/Y H:i', strtotime($r['commentedAt'])) : '',



        'estimatedHours' => (int)($r['estimatedHours'] ?? 2)



    ];



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



    body { background-color: #FAF9F6; color: #1e293b; }



    .modal-enter { animation: modalFadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards; }



    @keyframes modalFadeIn { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }



  </style>



  <?php include_once 'includes/swal.php'; ?>



  <?php include_once 'includes/theme_head.php'; ?>
</head>



<body class="min-h-screen flex flex-col antialiased selection:bg-rose-500 selection:text-white bg-[#FAF9F6] text-slate-800">







  <!-- 🌟 แท็บด้านซ้ายมือ สำหรับผู้เช่าพักอาศัย (Tenant Sidebar) 🌟 -->



  <?php include_once 'includes/tenant_sidebar.php'; ?>







  <!-- Top Navigation -->



  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100/80 shadow-sm">

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between h-14 sm:h-16">

        <div class="flex items-center space-x-2.5 sm:space-x-3">

          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer mr-1" title="เปิด/ปิด แถบเมนูด้านซ้าย">

            <i data-lucide="menu" class="w-5 h-5"></i>

          </button>

          

          <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-md shrink-0">

            <i data-lucide="history" class="w-4 h-4 sm:w-5 sm:h-5"></i>

          </div>

          <div>
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <p class="text-[10px] sm:text-[11px] text-slate-400 font-medium">ประวัติการแจ้งซ่อม</p>
          </div>

        </div>



        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

          <a href="tenant_request.php" class="px-3 sm:px-4 py-2 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white rounded-xl text-xs sm:text-sm font-bold shadow-sm flex items-center gap-1.5 transition">

            <i data-lucide="plus-circle" class="w-4 h-4"></i>

            <span class="hidden xs:inline">แจ้งซ่อมใหม่</span>

          </a>

          <a href="tenant_dashboard.php" class="px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs sm:text-sm font-semibold transition flex items-center gap-1.5" title="กลับหน้าหลัก">

            <i data-lucide="arrow-left" class="w-4 h-4"></i>

            <span class="hidden sm:inline">กลับหน้าหลัก</span>

          </a>



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



          <!-- ปุ่มออกจากระบบ (อยู่มุมขวาบนตรงชื่อ) -->

          <a href="logout.php" onclick="return confirm('ยืนยันออกจากระบบ?');" class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 hover:text-rose-700 border border-rose-200 text-xs font-bold transition shadow-2xs cursor-pointer shrink-0" title="ออกจากระบบ">

            <i data-lucide="log-out" class="w-4 h-4"></i>

            <span class="hidden sm:inline">ออกจากระบบ</span>

          </a>

        </div>

      </div>

    </div>

  </header>







  <main class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">



    



    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="clipboard-list" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ประวัติการแจ้งซ่อมและบริการ</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="home" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ห้อง <?php echo htmlspecialchars($myRoom['roomNumber'] ?? '-'); ?></span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            ประวัติการแจ้งซ่อมและบริการ
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            ติดตามสถานะงานซ่อม ตรวจสอบช่างผู้ดูแล และดูความคืบหน้าการทำงานได้แบบเรียลไทม์
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="tenant_request.php" class="px-5 py-2.5 bg-gradient-to-r from-rose-600 via-rose-500 to-pink-600 hover:from-rose-700 hover:to-pink-700 text-white rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 shadow-sm shadow-rose-600/25 active:scale-95 cursor-pointer">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>แจ้งซ่อมรายการใหม่</span>
          </a>
        </div>
      </div>
    </div>







    <!-- Alert Box -->



    <div id="alert-container">



      <?php echo $message; ?>



    </div>







    <!-- Stats Summary Cards -->



    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-6">



      <div onclick="filterRequests('all')" class="stat-card cursor-pointer bg-white p-4 rounded-2xl border border-rose-100/80 shadow-xs hover:border-blue-300 transition text-center sm:text-left">



        <div class="text-[11px] font-semibold text-slate-400 mb-0.5">ทั้งหมด</div>



        <div class="text-2xl font-bold font-display text-slate-800"><?php echo $countTotal; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></div>



      </div>



      <div onclick="filterRequests('Pending')" class="stat-card cursor-pointer bg-white p-4 rounded-2xl border border-rose-100/80 shadow-xs hover:border-amber-300 transition text-center sm:text-left">



        <div class="text-[11px] font-semibold text-amber-600 mb-0.5">รอรับงาน</div>



        <div class="text-2xl font-bold font-display text-amber-600"><?php echo $countPending; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></div>



      </div>



      <div onclick="filterRequests('In Progress')" class="stat-card cursor-pointer bg-white p-4 rounded-2xl border border-rose-100/80 shadow-xs hover:border-blue-300 transition text-center sm:text-left">



        <div class="text-[11px] font-semibold text-rose-600 mb-0.5">กำลังดำเนินการ</div>



        <div class="text-2xl font-bold font-display text-rose-600"><?php echo $countInProgress; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></div>



      </div>



      <div onclick="filterRequests('Completed')" class="stat-card cursor-pointer bg-white p-4 rounded-2xl border border-rose-100/80 shadow-xs hover:border-emerald-300 transition text-center sm:text-left">



        <div class="text-[11px] font-semibold text-emerald-600 mb-0.5">เสร็จสิ้น</div>



        <div class="text-2xl font-bold font-display text-emerald-600"><?php echo $countCompleted; ?> <span class="text-xs font-normal text-slate-400">รายการ</span></div>



      </div>



    </div>







    <!-- Filter Buttons -->



    <div class="flex items-center gap-2 mb-6 overflow-x-auto pb-1">



      <button onclick="filterRequests('all')" class="filter-btn active px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-800 text-white" data-filter="all">



        ทั้งหมด (<?php echo $countTotal; ?>)



      </button>



      <button onclick="filterRequests('Pending')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-rose-100/80" data-filter="Pending">



        ⏳ รอรับงาน (<?php echo $countPending; ?>)



      </button>



      <button onclick="filterRequests('In Progress')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-rose-100/80" data-filter="In Progress">



        ⚙️ กำลังดำเนินการ (<?php echo $countInProgress; ?>)



      </button>



      <button onclick="filterRequests('Completed')" class="filter-btn px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-white text-slate-600 hover:bg-slate-100 border border-rose-100/80" data-filter="Completed">



        ✅ เสร็จสิ้น (<?php echo $countCompleted; ?>)



      </button>



    </div>







    <!-- Requests List -->



    <div class="space-y-4" id="requestsListContainer">



      <?php if(count($requests) > 0): foreach($requests as $req): 



          $statusColor = "bg-slate-100 text-slate-600 border-rose-100/80";



          $statusText = $req['status'];



          $statusIcon = "info";







          if ($req['status'] === 'Pending') {



              $statusColor = "bg-amber-50 text-amber-700 border-amber-200";



              $statusText = "รอนิติมอบหมายงาน"; 



              $statusIcon = "clock";



          } elseif ($req['status'] === 'In Progress') {
              $statusColor = "bg-rose-50 text-rose-700 border-rose-200";
              $statusText = "นิติมอบหมายงานแล้ว";
              $statusIcon = "wrench";
          } elseif ($req['status'] === 'Pending Approval') {
              $statusColor = "bg-orange-50 text-orange-700 border-orange-200";
              $statusText = "ช่างปฏิบัติงานแล้ว (รอนิติตรวจรับ)";
              $statusIcon = "clock";
          } elseif ($req['status'] === 'Completed') {



              $statusColor = "bg-emerald-50 text-emerald-700 border-emerald-200";



              $statusText = "เสร็จสิ้นเรียบร้อย";



              $statusIcon = "check-circle-2";



          }



          



          $hasWorkSubmitted = !empty($req['completionImage']) && ($req['status'] === 'Completed');
          $canComment = ($req['status'] === 'Completed');



      ?>



      <div class="req-card bg-white p-5 sm:p-6 rounded-3xl border border-rose-100/90 shadow-sm hover:shadow-md hover:border-blue-300 transition relative overflow-hidden cursor-pointer group" onclick="openRequestDetailModal(<?php echo $req['requestId']; ?>)" data-status="<?php echo $req['status']; ?>">



        <div class="flex flex-col md:flex-row gap-4 sm:gap-6">



          



          <!-- Image -->



          <div class="shrink-0">



            <?php if(!empty($req['requestImage'])): ?>



              <div class="relative group/img cursor-pointer w-20 h-20 sm:w-28 sm:h-28 rounded-2xl overflow-hidden border border-rose-100/80 shadow-sm" onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($req['requestImage']); ?>', 'รูปภาพปัญหาที่คุณแจ้ง')">



                <img src="<?php echo htmlspecialchars($req['requestImage']); ?>" class="w-full h-full object-cover transition group-hover/img:scale-105" alt="รูปปัญหา" onerror="this.onerror=null; this.style.display='none';">



                <div class="absolute inset-0 bg-black/30 flex items-center justify-center opacity-0 group-hover/img:opacity-100 transition">



                  <i data-lucide="zoom-in" class="w-6 h-6 text-white drop-shadow-md"></i>



                </div>



              </div>



            <?php else: ?>



              <div class="w-20 h-20 sm:w-28 sm:h-28 bg-rose-50/30 rounded-2xl border border-rose-100/60 flex flex-col items-center justify-center text-slate-300">



                <i data-lucide="image-off" class="w-6 h-6 mb-1"></i>



                <span class="text-[10px] font-medium">ไม่มีรูปภาพ</span>



              </div>



            <?php endif; ?>







          </div>







          <!-- Content Details -->



          <div class="flex-1 min-w-0">



            <div class="flex items-center gap-2 mb-2 flex-wrap">



              <span class="text-xs font-bold px-2.5 py-0.5 rounded-md bg-slate-100 text-slate-600 border border-rose-100/80 font-mono">



                #REQ-<?php echo str_pad($req['requestId'], 4, '0', STR_PAD_LEFT); ?>



              </span>



              



              <div class="inline-flex items-center gap-1.5 text-xs text-slate-500 font-medium bg-rose-50/30 px-2 py-0.5 rounded-md border border-rose-100/80">



                <i data-lucide="clock" class="w-3.5 h-3.5 text-slate-400"></i>



                <span>แจ้งเมื่อ: <b><?php echo date("d/m/Y H:i", strtotime($req['requestDate'])); ?> น.</b></span>







              </div>







              <?php if(!empty($req['preferredDate'])): ?>



                <span class="text-[11px] text-indigo-600 font-bold bg-indigo-50 px-2 py-0.5 rounded-md border border-indigo-100 flex items-center gap-1">



                  <i data-lucide="calendar-clock" class="w-3.5 h-3.5"></i> นัดเข้า: <?php echo date("d/m/Y", strtotime($req['preferredDate'])); ?>



                </span>



              <?php endif; ?>











            </div>







            <h3 class="font-bold text-slate-800 text-base sm:text-lg group-hover:text-rose-700 transition"><?php echo htmlspecialchars($req['category']); ?></h3>



            <p class="text-xs sm:text-sm text-slate-600 mt-2 bg-rose-50/30/80 p-3 rounded-2xl border border-rose-100/60 whitespace-pre-line leading-relaxed"><?php echo htmlspecialchars($req['description']); ?></p>



            



            <?php if($req['allowEntry'] == 1): ?>



              <div class="mt-2.5">



                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">



                  <i data-lucide="key" class="w-3.5 h-3.5"></i> ยินยอมให้นิติบุคคลใช้กุญแจสำรองเปิดห้อง



                </span>



              </div>



            <?php endif; ?>











            <!-- ข้อมูลพนักงานรับผิดชอบ -->



            <?php if ($req['status'] === 'Pending'): ?>



              <div class="mt-3 text-xs text-amber-700 bg-amber-50/80 px-3.5 py-2 rounded-xl border border-amber-200/60 flex items-center gap-2">



                <i data-lucide="hourglass" class="w-4 h-4 text-amber-500 shrink-0"></i>



                <span>ส่งเรื่องถึงนิติบุคคลแล้ว กำลังรอนิติบุคคลมอบหมายงานให้พนักงาน</span>



              </div>



            <?php elseif (!empty($req['staffName'])): 
                $isCleanerRole = in_array($req['staffRole'] ?? '', ['Cleaner', 'Housekeeper']);
                $roleLabel = $isCleanerRole ? "แม่บ้าน" : "ช่างซ่อมบำรุง";
                $isJobDone = ($req['status'] === 'Completed');
            ?>
              <div class="mt-3 text-xs <?php echo $isJobDone ? 'text-emerald-950 bg-emerald-50/90 border-emerald-200' : 'text-blue-900 bg-blue-50/90 border-blue-200'; ?> px-3.5 py-2.5 rounded-2xl border flex flex-wrap items-center justify-between gap-2 shadow-2xs">
                <div class="flex items-center gap-2 font-bold">
                  <i data-lucide="<?php echo $isJobDone ? 'check-check' : ($isCleanerRole ? 'sparkles' : 'wrench'); ?>" class="w-4 h-4 <?php echo $isJobDone ? 'text-emerald-600' : 'text-blue-600'; ?>"></i>
                  <span><?php echo $isJobDone ? 'ผู้ปฏิบัติงาน (เสร็จสิ้น):' : 'ผู้รับผิดชอบ:'; ?> คุณ<?php echo htmlspecialchars($req['staffName']); ?> (<?php echo $roleLabel; ?>)</span>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                  <?php if(!$isJobDone): 
                    $estH = intval($req['estimatedHours'] ?? 2);
                    $assignTs = !empty($req['assignedAt']) ? strtotime($req['assignedAt']) : (!empty($req['requestDate']) ? strtotime($req['requestDate']) : time());
                    $expFinishTs = $assignTs + ($estH * 3600);
                  ?>
                    <span class="text-[11px] font-semibold text-blue-900 bg-white px-2.5 py-1 rounded-xl border border-blue-200 shadow-2xs flex items-center gap-1">
                      <i data-lucide="clock" class="w-3.5 h-3.5 text-blue-600"></i> กำหนดเวลา: <?php echo $estH; ?> ชม. (ถึง ~<?php echo date('H:i', $expFinishTs); ?> น.)
                    </span>
                  <?php endif; ?>
                  <?php if(!empty($req['staffPhone'])): ?>
                    <a href="tel:<?php echo htmlspecialchars($req['staffPhone']); ?>" onclick="event.stopPropagation()" class="text-xs bg-white px-3 py-1 rounded-xl border <?php echo $isJobDone ? 'border-emerald-200 text-emerald-800 hover:bg-emerald-100' : 'border-blue-200 text-blue-700 hover:bg-blue-100'; ?> font-bold flex items-center gap-1.5 transition shadow-2xs">
                      <i data-lucide="phone" class="w-3.5 h-3.5"></i> โทร <?php echo htmlspecialchars($req['staffPhone']); ?>
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>











            <!-- ข้อมูลเวลาช่างส่งงานเสร็จ (Completed At / ส่งงาน) -->



            
            <?php if ($req['status'] === 'Pending Approval'): ?>
              <div class="mt-3 p-3 bg-gradient-to-r from-orange-50 to-amber-50/80 rounded-2xl border border-orange-200 text-xs flex flex-wrap items-center justify-between gap-2 shadow-2xs">
                <div class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-xl bg-orange-100 text-orange-700 flex items-center justify-center shrink-0">
                    <i data-lucide="clock" class="w-4 h-4"></i>
                  </div>
                  <div>
                    <span class="font-bold text-orange-950 block sm:inline"><?php echo (in_array($req['staffRole'] ?? '', ['Cleaner', 'Housekeeper']) ? 'แม่บ้าน' : 'ช่าง'); ?>ปฏิบัติงานเสร็จแล้ว:</span>
                    <span class="text-orange-800 ml-1">อยู่ระหว่างรอฝ่ายนิติบุคคลตรวจสอบและอนุมัติส่งมอบงาน</span>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($req['status'] === 'Completed' && !empty($req['completedAt'])): ?>



              <div class="mt-3 p-3 bg-gradient-to-r from-emerald-50 to-teal-50/80 rounded-2xl border border-emerald-200 text-xs flex flex-wrap items-center justify-between gap-2 shadow-2xs">



                <div class="flex items-center gap-2">



                  <div class="w-7 h-7 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0">



                    <i data-lucide="check-circle" class="w-4 h-4"></i>



                  </div>



                  <div>



                    <span class="font-bold text-emerald-950 block sm:inline"><?php echo (in_array($req['staffRole'] ?? '', ['Cleaner', 'Housekeeper']) ? 'แม่บ้าน' : 'ช่าง'); ?>ส่งมอบงานแล้ว:</span>



                    <span class="font-mono font-bold text-emerald-800 ml-1"><?php echo date("d/m/Y H:i", strtotime($req['completedAt'])); ?> น.</span>







                  </div>



                </div>



                <?php 



                  $duration = duration_th($req['requestDate'], $req['completedAt']);



                  if ($duration):



                ?>



                  <span class="text-[11px] font-semibold text-emerald-800 bg-white px-2.5 py-1 rounded-xl border border-emerald-200 shadow-2xs">



                    ⏱️ ใช้เวลาแก้ไข: <?php echo $duration; ?>



                  </span>



                <?php endif; ?>



              </div>



            <?php endif; ?>



            <!-- ความคิดเห็น/ติชมงาน (เมื่อช่าง/แม่บ้านส่งงานแล้ว หรือ เสร็จสิ้น) -->



            <?php if ($canComment): ?>



              <div class="mt-4 pt-3.5 border-t border-rose-100/60" id="card_comment_section_<?php echo $req['requestId']; ?>">



                <?php if(!empty($req['tenantComment'])): ?>



                  <div class="bg-purple-50/70 border border-purple-200/80 rounded-2xl p-4 text-xs">



                    <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">



                      <span class="font-bold text-purple-900 flex items-center gap-1.5 text-xs sm:text-sm">



                        <i data-lucide="message-square-quote" class="w-4 h-4 text-purple-600"></i> ข้อคิดเห็น/ติชมของคุณ:



                      </span>



                      <span class="text-[10px] bg-white text-purple-700 font-semibold px-2 py-0.5 rounded-full border border-purple-200 flex items-center gap-1 shadow-2xs">



                        <i data-lucide="lock" class="w-3 h-3 text-purple-500"></i> ลับเฉพาะคุณและแอดมิน (ช่าง/แม่บ้านไม่เห็น)



                      </span>



                    </div>



                    <p class="text-slate-700 whitespace-pre-line bg-white/90 p-3 rounded-xl border border-purple-100 leading-relaxed font-sans">"<?php echo htmlspecialchars($req['tenantComment']); ?>"</p>



                    <div class="mt-2.5 flex items-center justify-between text-[11px] text-purple-600/90 pt-1">



                      <span>



                        <?php if(!empty($req['commentedAt'])): ?>



                          <i data-lucide="clock" class="w-3 h-3 inline mr-0.5"></i> ส่งเมื่อ: <?php echo date("d/m/Y H:i", strtotime($req['commentedAt'])); ?> น.



                        <?php endif; ?>



                      </span>



                      <button type="button" onclick="event.stopPropagation(); openCommentModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['tenantComment'])); ?>')" class="text-purple-700 hover:text-purple-900 font-bold flex items-center gap-1 hover:underline transition">



                        <i data-lucide="edit-3" class="w-3 h-3"></i> แก้ไขความคิดเห็น



                      </button>



                    </div>



                  </div>



                <?php else: ?>



                  <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-purple-50/50 border border-dashed border-purple-200 rounded-2xl p-4">



                    <div class="flex items-center gap-2.5 text-xs text-slate-600">



                      <div class="w-8 h-8 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center shrink-0">



                        <i data-lucide="message-square-plus" class="w-4 h-4"></i>



                      </div>



                      <div>



                        <span class="font-bold text-slate-800 block text-xs sm:text-sm">



                          <?php echo $hasWorkSubmitted ? 'ทีมงานส่งมอบรูปผลงานแล้ว! ร่วมติชมหรือแสดงความคิดเห็น' : 'งานเสร็จสิ้นแล้ว! ร่วมติชมหรือแสดงความคิดเห็น'; ?>



                        </span>



                        <span class="text-[11px] text-slate-400">ส่งตรงถึงนิติบุคคล (ช่าง/แม่บ้านจะไม่เห็นข้อความนี้)</span>



                      </div>



                    </div>



                    <button type="button" onclick="event.stopPropagation(); openCommentModal(<?php echo $req['requestId']; ?>, '')" class="w-full sm:w-auto shrink-0 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm">



                      <i data-lucide="message-square" class="w-4 h-4"></i> เขียนติชมงาน



                    </button>



                  </div>



                <?php endif; ?>



              </div>



            <?php endif; ?>











          </div>







          <!-- Status & Actions (Right Column) -->



          <div class="flex flex-row md:flex-col items-center md:items-end justify-between md:justify-start gap-2.5 shrink-0 md:w-44 pt-3 md:pt-0 border-t md:border-t-0 border-rose-100/60">



            <div class="px-3.5 py-1.5 rounded-full border text-xs font-bold flex items-center gap-1.5 w-auto md:w-full justify-center <?php echo $statusColor; ?>">



              <i data-lucide="<?php echo $statusIcon; ?>" class="w-3.5 h-3.5"></i> <?php echo $statusText; ?>



            </div>







            <div class="flex flex-row md:flex-col gap-2 w-auto md:w-full">



              <button type="button" onclick="event.stopPropagation(); openRequestDetailModal(<?php echo $req['requestId']; ?>)" class="w-full px-3 py-2 bg-slate-100 hover:bg-rose-50 text-slate-700 hover:text-rose-700 border border-rose-100/80 hover:border-rose-200 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs">



                <i data-lucide="eye" class="w-3.5 h-3.5"></i> รายละเอียด



              </button>







              <?php if(!empty($req['completionImage'])): ?>
                <button type="button" onclick="event.stopPropagation(); openImageModal('<?php echo htmlspecialchars($req['completionImage']); ?>', 'รูปภาพผลงานหลังแก้ไขเสร็จ')" class="w-full px-3 py-2 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs">
                  <i data-lucide="camera" class="w-3.5 h-3.5"></i> ดูผลงานช่าง
                </button>
              <?php endif; ?>
              <?php if($req['status'] === 'Pending Approval'): ?>
                <span class="w-full px-2 py-1.5 bg-orange-50 text-orange-700 border border-orange-200 rounded-xl text-[11px] font-bold flex items-center justify-center gap-1 text-center shadow-2xs">
                  <i data-lucide="clock" class="w-3 h-3 text-orange-600"></i> รอนิติอนุมัติ
                </span>
              <?php endif; ?>







              <?php if ($canComment): ?>



                <button type="button" id="card_comment_side_btn_<?php echo $req['requestId']; ?>" onclick="event.stopPropagation(); openCommentModal(<?php echo $req['requestId']; ?>, '<?php echo htmlspecialchars(addslashes($req['tenantComment'] ?? '')); ?>')" class="w-full px-3 py-2 <?php echo !empty($req['tenantComment']) ? 'bg-purple-50 hover:bg-purple-100 text-purple-700 border-purple-200' : 'bg-white hover:bg-purple-50 text-purple-600 hover:text-purple-700 border-purple-200'; ?> border rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs">



                  <i data-lucide="message-square-quote" class="w-3.5 h-3.5"></i> <?php echo !empty($req['tenantComment']) ? 'แก้ไขความคิดเห็น' : 'ติชมผลงาน'; ?>



                </button>



              <?php endif; ?>







              <?php if ($req['status'] === 'Pending'): ?>



                <form action="" method="POST" class="w-full m-0" onclick="event.stopPropagation()" onsubmit="return confirm('นิติบุคคลยังไม่ได้รับเรื่อง คุณแน่ใจหรือไม่ว่าต้องการยกเลิกการแจ้งนี้?');">



                  <input type="hidden" name="delete_id" value="<?php echo $req['requestId']; ?>">



                  <button type="submit" name="delete_request" class="w-full px-3 py-2 bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-600 rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5">



                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ยกเลิกแจ้งซ่อม



                  </button>



                </form>



              <?php endif; ?>



            </div>



          </div>







        </div>



      </div>



      <?php endforeach; else: ?>



        <div class="text-center py-20 bg-white rounded-3xl border border-rose-100/80 shadow-sm p-8">



          <div class="w-16 h-16 bg-rose-50/30 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300">



            <i data-lucide="clipboard-x" class="w-8 h-8"></i>



          </div>



          <h3 class="text-lg font-bold text-slate-700 font-display">ยังไม่มีประวัติการแจ้งซ่อม</h3>



          <p class="text-sm text-slate-500 mt-1">หากพบปัญหาภายในห้องพัก สามารถส่งเรื่องผ่านหน้าแจ้งซ่อมใหม่ได้เลยครับ</p>



          <a href="tenant_request.php" class="inline-flex items-center gap-2 mt-5 px-5 py-2.5 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-sm font-bold shadow-sm transition">



            <i data-lucide="plus-circle" class="w-4 h-4"></i> แจ้งซ่อมใหม่ตอนนี้



          </a>



        </div>



      <?php endif; ?>



    </div>







  </main>







  <!-- MODAL: ดูรูปภาพ -->



  <div id="imagePreviewModal" class="hidden fixed inset-0 z-[60] bg-slate-900/90 backdrop-blur-sm flex items-center justify-center p-4 modal-enter" onclick="closeImageModal()">



    <div class="relative max-w-2xl w-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">



      <button type="button" onclick="closeImageModal()" class="absolute -top-12 right-0 text-white/70 hover:text-rose-400 p-2 transition flex items-center gap-2 font-bold text-sm">



        ปิดรูปภาพ <i data-lucide="x-circle" class="w-6 h-6"></i>



      </button>



      <div id="imageTitle" class="absolute -top-10 left-0 text-white font-bold text-sm bg-black/50 px-3 py-1 rounded-full backdrop-blur-md"></div>



      <img id="previewImage" src="" alt="Image Preview" class="max-h-[80vh] max-w-full rounded-2xl shadow-2xl object-contain border-2 border-white/10 bg-black/50">



    </div>



  </div>







  <!-- 🌟 MODAL จอใหญ่: รายละเอียดงานแบบเต็ม แบ่งกรอบชัดเจนระหว่างผู้เช่า และช่าง/แม่บ้าน 🌟 -->



  <div id="requestDetailModal" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center p-0 sm:p-2 md:p-3 overflow-hidden" onclick="closeRequestDetailModal()">



      <div class="bg-white w-full h-full sm:h-[97vh] max-w-none sm:max-w-[98vw] 2xl:max-w-[98vw] rounded-none sm:rounded-3xl shadow-2xl overflow-hidden modal-enter border border-rose-100/80 flex flex-col" onclick="event.stopPropagation()">



          



          <!-- Modal Top Header -->



          <div class="px-6 py-4 border-b border-rose-100/60 flex justify-between items-center bg-rose-50/30/90 shrink-0">



              <div class="flex items-center gap-2.5 flex-wrap">



                  <span class="text-xs font-bold px-3 py-1 rounded-xl bg-slate-900 text-white font-mono shadow-2xs">



                      ห้อง <span id="req_detail_room">-</span>



                  </span>



                  <span id="req_detail_code" class="text-xs font-mono font-bold text-slate-600 bg-white px-2.5 py-1 rounded-xl border border-rose-100/80 shadow-2xs">



                      REQ-0000



                  </span>



                  <div id="req_detail_status_badge" class="text-xs font-bold px-3 py-1 rounded-xl flex items-center gap-1.5 shadow-2xs"></div>



              </div>



              <div class="flex items-center gap-2">



                  <button type="button" onclick="closeRequestDetailModal()" class="w-9 h-9 rounded-2xl bg-slate-200/70 hover:bg-rose-100 hover:text-rose-600 text-slate-600 transition flex items-center justify-center cursor-pointer shrink-0 shadow-2xs" title="ปิดหน้าต่าง">



                      <i data-lucide="x" class="w-5 h-5"></i>



                  </button>



              </div>



          </div>







          <!-- Modal Scrollable Content: 2 Clear Side-by-Side Frames (จอใหญ่) -->



          <div class="p-5 sm:p-6 md:p-8 space-y-6 overflow-y-auto flex-1 min-h-0">



              



              <!-- 🌟 แบ่ง 2 กรอบหลัก: ฝั่งผู้เช่า (ซ้าย) VS ฝั่งช่าง/แม่บ้าน (ขวา) 🌟 -->



              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch">



                  



                  <!-- ======================================================== -->



                  <!-- 🟦 กรอบที่ 1: ส่วนข้อมูลการแจ้งของผู้เช่า (Tenant Frame)   -->



                  <!-- ======================================================== -->



                  <div class="rounded-3xl p-5 sm:p-6 border-2 border-rose-200 bg-gradient-to-b from-blue-50/50 to-slate-50/30 flex flex-col justify-between shadow-2xs space-y-4">



                      <div>



                          <!-- Header กรอบผู้เช่า -->



                          <div class="flex items-center justify-between pb-3.5 border-b border-rose-200/80 mb-4">



                              <div class="flex items-center gap-2.5">



                                  <div class="w-9 h-9 rounded-2xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-xs">



                                      <i data-lucide="user" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <h4 class="font-display font-bold text-slate-900 text-base">ส่วนข้อมูลของผู้เช่า</h4>



                                      <p class="text-[11px] text-rose-700 font-medium">รายละเอียดปัญหาที่แจ้ง และวันเวลาที่นัดหมาย</p>



                                  </div>



                              </div>



                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-blue-100/80 text-blue-800 border border-rose-200 font-mono">



                                  ผู้เช่าห้องพัก



                              </span>



                          </div>







                          <!-- ประเภทงาน & กำหนดเวลา -->



                          <div class="space-y-1 mb-3.5">



                              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">ประเภทงาน / ปัญหาที่แจ้ง</span>



                              <div class="flex items-center justify-between gap-2 flex-wrap">



                                  <h3 class="text-base sm:text-lg font-bold text-slate-900" id="req_detail_category">-</h3>



                                  <div class="flex items-center gap-1.5 flex-wrap">



                                      <span id="req_detail_hours_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 font-mono shadow-2xs">⏱️ 2 ชม.</span>



                                      <span id="req_detail_entry_badge" class="text-xs font-bold px-2.5 py-1 rounded-xl border shadow-2xs"></span>



                                  </div>



                              </div>



                          </div>







                          <!-- รายละเอียดปัญหา -->



                          <div class="bg-white p-4 rounded-2xl border border-rose-100/80 shadow-2xs mb-4">



                              <div class="text-[11px] font-bold text-slate-400 mb-1.5 flex items-center gap-1.5">



                                  <i data-lucide="align-left" class="w-3.5 h-3.5 text-rose-600"></i> รายละเอียดปัญหาที่แจ้ง:



                              </div>



                              <p class="text-xs sm:text-sm text-slate-700 whitespace-pre-line leading-relaxed font-normal" id="req_detail_desc">-</p>



                          </div>







                          <!-- นัดหมายวันเวลาเข้าห้อง -->



                          <div id="req_detail_appointment" class="p-3.5 bg-indigo-50/80 border border-indigo-200/90 rounded-2xl flex items-center gap-3 mb-4 text-xs shadow-2xs">



                              <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0">



                                  <i data-lucide="calendar-clock" class="w-4 h-4"></i>



                              </div>



                              <div>



                                  <span class="text-[10px] text-indigo-500 font-bold uppercase tracking-wider block">วันและช่วงเวลาที่นัดหมายเข้าห้อง</span>



                                  <span id="req_detail_pref_text" class="font-bold text-indigo-950 text-xs sm:text-sm"></span>



                              </div>



                          </div>



                      </div>







                      <!-- 📷 รูปก่อนทำ (ปัญหาที่คุณแจ้ง) BEFORE -->



                      <div class="bg-white border-2 border-dashed border-amber-300 rounded-2xl p-3.5 shadow-2xs">



                          <div class="flex items-center justify-between mb-2.5">



                              <span class="text-xs font-bold text-amber-900 flex items-center gap-1.5">



                                  <i data-lucide="camera" class="w-4 h-4 text-amber-600"></i> รูปถ่ายปัญหาหน้างาน (ก่อนทำ)



                              </span>



                              <span class="text-[10px] bg-amber-100 text-amber-900 font-extrabold px-2.5 py-0.5 rounded-lg border border-amber-200">BEFORE</span>



                          </div>



                          <div id="req_detail_before_img_container" class="min-h-[160px] flex items-center justify-center">



                              <!-- Dynamic Before Image -->



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



                              <span class="text-[11px] font-bold px-2.5 py-1 rounded-xl bg-emerald-100/80 text-emerald-800 border border-emerald-200 font-mono">



                                  ทีมช่าง & แม่บ้าน



                              </span>



                          </div>







                          <!-- เจ้าหน้าที่ผู้รับผิดชอบงาน -->



                          <div id="req_detail_staff_box" class="bg-white p-4 rounded-2xl border border-emerald-200/80 shadow-2xs flex items-center justify-between flex-wrap gap-3 mb-4">



                              <div class="flex items-center gap-3 text-slate-700 font-medium">



                                  <div class="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-700 flex items-center justify-center shrink-0 shadow-2xs">



                                      <i data-lucide="user-check" class="w-5 h-5"></i>



                                  </div>



                                  <div>



                                      <span class="text-[10px] text-slate-400 block font-bold uppercase tracking-wider">เจ้าหน้าที่ผู้รับผิดชอบ</span>



                                      <span class="font-bold text-slate-900 text-sm sm:text-base" id="req_detail_staff_name">-</span>



                                  </div>



                              </div>



                              <a id="req_detail_staff_phone_btn" href="#" class="hidden inline-flex items-center gap-2 text-xs font-bold text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3.5 py-2 rounded-xl transition shadow-2xs">



                                  <i data-lucide="phone-call" class="w-3.5 h-3.5 text-emerald-600"></i>



                                  <span class="staff-phone-text">โทรติดต่อเจ้าหน้าที่</span>



                              </a>



                          </div>







                          <!-- บันทึกวันและเวลาการทำงาน (ไทม์ไลน์ 3 สเต็ป) -->



                          <div class="space-y-2 mb-4">



                              <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">บันทึกวันและเวลาการทำงาน</span>



                              <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">



                                  



                                  <!-- 1. แจ้งเมื่อ -->



                                  <div class="bg-white border border-amber-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-amber-700 flex items-center gap-1.5">



                                          <i data-lucide="send" class="w-3.5 h-3.5 text-amber-600"></i> 1. แจ้งเมื่อ



                                      </span>



                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="req_detail_req_date">-</div>



                                      <div class="text-[10px] text-amber-800 font-semibold mt-0.5" id="req_detail_req_ago"></div>



                                  </div>







                                  <!-- 2. รับงานเมื่อ -->



                                  <div class="bg-white border border-rose-200 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-rose-700 flex items-center gap-1.5">



                                          <i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-600"></i> 2. รับงานเมื่อ



                                      </span>



                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="req_detail_assigned_date">-</div>



                                      <div class="text-[10px] text-blue-800 font-semibold mt-0.5" id="req_detail_assigned_ago"></div>



                                  </div>







                                  <!-- 3. จบงานเมื่อ -->



                                  <div id="req_detail_completed_box" class="bg-white border border-rose-100/80 rounded-2xl p-3 flex flex-col justify-between shadow-2xs">



                                      <span class="text-[11px] font-bold text-slate-700 flex items-center gap-1.5">



                                          <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> 3. ส่งงาน/จบงาน



                                      </span>



                                      <div class="text-xs font-bold text-slate-800 mt-1 font-mono" id="req_detail_completed_date">-</div>



                                      <div class="text-[10px] text-emerald-800 font-semibold mt-0.5" id="req_detail_duration"></div>



                                  </div>



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



                          <div id="req_detail_after_img_container" class="min-h-[160px] flex items-center justify-center">



                              <!-- Dynamic After Image -->



                          </div>



                      </div>



                  </div>







              </div>







              <!-- ======================================================== -->



              <!-- 🟪 กรอบที่ 3: ส่วนการประเมินและข้อความติชม (Feedback)      -->



              <!-- ======================================================== -->



              <div id="req_detail_comment_section" class="bg-purple-50/70 border-2 border-purple-200 rounded-3xl p-5 shadow-2xs">



                  <div class="flex items-center justify-between mb-3 flex-wrap gap-2">



                      <span class="text-xs sm:text-sm font-bold text-purple-950 flex items-center gap-2">



                          <i data-lucide="message-square-quote" class="w-4 h-4 text-purple-600"></i>



                          ความคิดเห็นและการติชมผลงานจากผู้เช่า



                      </span>



                      <span class="text-[11px] bg-white text-purple-700 font-bold px-3 py-1 rounded-full border border-purple-200 flex items-center gap-1.5 shadow-2xs">



                          <i data-lucide="lock" class="w-3.5 h-3.5 text-purple-500"></i> ลับเฉพาะคุณและนิติบุคคล



                      </span>



                  </div>



                  <div id="req_detail_comment_display">



                      <!-- Dynamic comment content -->



                  </div>



              </div>







          </div>







          <!-- Modal Footer -->



          <div class="px-6 py-4 border-t border-rose-100/60 bg-rose-50/30 flex items-center justify-between gap-3 shrink-0">



              <button type="button" onclick="closeRequestDetailModal()" class="px-5 py-2.5 bg-white hover:bg-slate-100 border border-rose-100/80 text-slate-700 rounded-xl text-xs font-bold transition cursor-pointer shadow-2xs">



                  ปิดหน้าต่าง



              </button>







              <div id="req_detail_footer_action">



                  <!-- Dynamic Action button -->



              </div>



          </div>



      </div>



  </div>







  <!-- MODAL: เขียน / แก้ไข ความคิดเห็นและติชมผลงาน --><!-- MODAL: เขียน / แก้ไข ความคิดเห็นและติชมผลงาน -->



  <div id="commentModal" class="hidden fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">



    <div class="bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden modal-enter">



      <div class="p-5 border-b border-rose-100/60 flex justify-between items-center bg-rose-50/30">



        <div class="flex items-center gap-2.5">



          <div class="w-9 h-9 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center shadow-xs">



            <i data-lucide="message-square-quote" class="w-5 h-5"></i>



          </div>



          <div>



            <h3 class="font-display font-bold text-slate-800 text-base" id="commentModalTitle">ความคิดเห็น / ติชมการทำงาน</h3>



            <p class="text-xs text-slate-500">ส่งข้อเสนอแนะถึงนิติบุคคลโดยตรง</p>



          </div>



        </div>



        <button type="button" onclick="closeCommentModal()" class="text-slate-400 hover:text-rose-500 p-1.5 rounded-lg transition"><i data-lucide="x" class="w-5 h-5"></i></button>



      </div>



      



      <form action="" method="POST" class="p-6 space-y-4">



        <input type="hidden" name="request_id" id="comment_modal_request_id">



        <div>



          <label class="block text-xs font-bold text-slate-700 mb-1.5">แสดงความคิดเห็นของคุณต่อการให้บริการ</label>



          <textarea name="tenant_comment" id="comment_modal_textarea" rows="4" required placeholder="เช่น ช่างซ่อมได้รวดเร็วและเรียบร้อยดีมากครับ / ประทับใจการบริการ..." class="w-full px-3.5 py-2.5 text-sm bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-purple-500 focus:bg-white transition resize-none"></textarea>



          <div class="text-[11px] text-purple-700 bg-purple-50 p-2.5 rounded-xl border border-purple-200 mt-2 flex items-center gap-2">



            <i data-lucide="shield-check" class="w-4 h-4 text-purple-600 shrink-0"></i>



            <span><strong>🔒 ความเป็นส่วนตัว:</strong> ข้อความนี้จะส่งถึงผู้เช่าและแอดมินเท่านั้น (ช่างและแม่บ้านไม่เห็นข้อความนี้)</span>



          </div>



        </div>



        



        <div class="flex items-center justify-end gap-2.5 pt-2">



          <button type="button" onclick="closeCommentModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">ยกเลิก</button>



          <button type="submit" name="submit_comment" class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center gap-1.5">



            <i data-lucide="send" class="w-3.5 h-3.5"></i> บันทึกความคิดเห็น



          </button>



        </div>



      </form>



    </div>



  </div>







  <script>



    lucide.createIcons();







    // ข้อมูลรายการแจ้งซ่อมทั้งหมดสำหรับ Request Detail Modal



    window.tenantRequests = <?php echo json_encode($allRequestsData, JSON_UNESCAPED_UNICODE); ?>;







    function openRequestDetailModal(reqId) {



        const req = window.tenantRequests ? window.tenantRequests[reqId] : null;



        if (!req) return;







        // ข้อมูลหลัก



        document.getElementById('req_detail_room').textContent = req.roomNumber || '-';



        document.getElementById('req_detail_code').textContent = req.reqCode;



        document.getElementById('req_detail_category').textContent = req.category;



        document.getElementById('req_detail_desc').textContent = req.description || '-';



        const hoursBadge = document.getElementById('req_detail_hours_badge');



        if (hoursBadge) {



            hoursBadge.textContent = '⏱️ กำหนดเวลา ' + (req.estimatedHours || 2) + ' ชม.';



        }







        // ป้ายสถานะ



        const statusBadge = document.getElementById('req_detail_status_badge');



        if (req.status === 'Completed') {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> เสร็จสิ้นเรียบร้อย (นิติอนุมัติแล้ว)';
        } else if (req.status === 'Pending Approval') {
            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-orange-50 text-orange-700 border border-orange-200 flex items-center gap-1.5';
            statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-orange-600"></i> ช่างปฏิบัติงานแล้ว (รอนิติตรวจรับ)';
        } else if (req.status === 'In Progress') {



            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-rose-50 text-rose-700 border border-rose-200 flex items-center gap-1.5';



            statusBadge.innerHTML = '<i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-600"></i> นิติมอบหมายงานแล้ว';



        } else {



            statusBadge.className = 'text-xs font-bold px-3 py-1 rounded-xl bg-amber-50 text-amber-700 border border-amber-200 flex items-center gap-1.5';



            statusBadge.innerHTML = '<i data-lucide="clock" class="w-3.5 h-3.5 text-amber-600"></i> รอนิติมอบหมายงาน';



        }







        // นัดหมายเข้าห้อง



        const prefBox = document.getElementById('req_detail_appointment');



        if (req.preferredDate && req.preferredDate !== '-') {



            prefBox.classList.remove('hidden');



            document.getElementById('req_detail_pref_text').textContent = 'นัดเข้า: ' + req.preferredDate + (req.preferredTime ? ' (' + req.preferredTime + ')' : '');



        } else {



            prefBox.classList.add('hidden');



        }







        // สิทธิ์การเข้าห้อง



        const entryBadge = document.getElementById('req_detail_entry_badge');



        if (req.allowEntry == 1) {



            entryBadge.className = 'text-xs font-bold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-xl border border-emerald-200 flex items-center gap-1.5';



            entryBadge.innerHTML = '<i data-lucide="key" class="w-3.5 h-3.5"></i> ยินยอมให้ใช้กุญแจสำรอง';



        } else {



            entryBadge.className = 'text-xs font-bold text-slate-600 bg-slate-100 px-2.5 py-1 rounded-xl border border-rose-100/80 flex items-center gap-1.5';



            entryBadge.innerHTML = '<i data-lucide="lock" class="w-3.5 h-3.5"></i> ต้องมีผู้เช่าอยู่ห้อง';



        }







        // ข้อมูลพนักงานรับผิดชอบ



        const staffBox = document.getElementById('req_detail_staff_box');



        const staffNameEl = document.getElementById('req_detail_staff_name');



        const staffPhoneBtn = document.getElementById('req_detail_staff_phone_btn');







        if (req.staffName) {



            staffBox.classList.remove('hidden');



            staffNameEl.textContent = 'คุณ' + req.staffName + (req.staffRole ? ' (' + req.staffRole + ')' : '');



            if (req.staffPhone) {



                staffPhoneBtn.href = 'tel:' + req.staffPhone;



                staffPhoneBtn.querySelector('.staff-phone-text').textContent = 'โทร ' + req.staffPhone;



                staffPhoneBtn.classList.remove('hidden');



            } else {



                staffPhoneBtn.classList.add('hidden');



            }



        } else if (req.status === 'Pending') {



            staffBox.classList.remove('hidden');



            staffNameEl.innerHTML = '<span class="text-amber-700 font-medium">รอนิติบุคคลมอบหมายงานให้เจ้าหน้าที่</span>';



            staffPhoneBtn.classList.add('hidden');



        } else {



            staffBox.classList.add('hidden');



        }







        // ไทม์ไลน์ 1: แจ้งเมื่อ



        document.getElementById('req_detail_req_date').textContent = req.requestDate + ' น.';



        document.getElementById('req_detail_req_ago').textContent = '';







        // ไทม์ไลน์ 2: รับงานเมื่อ



        const assignedDateEl = document.getElementById('req_detail_assigned_date');



        const assignedAgoEl = document.getElementById('req_detail_assigned_ago');



        if (req.assignedAt) {



            assignedDateEl.textContent = req.assignedAt + ' น.';



            assignedAgoEl.textContent = '';



        } else {



            assignedDateEl.innerHTML = req.status === 'Pending' ? '<span class="text-amber-600 font-semibold">รอนิติมอบหมาย</span>' : '-';



            assignedAgoEl.textContent = '';



        }







        // ไทม์ไลน์ 3: จบงานเมื่อ



        const completedBox = document.getElementById('req_detail_completed_box');



        const completedDateEl = document.getElementById('req_detail_completed_date');



        const durationEl = document.getElementById('req_detail_duration');







        if (req.status === 'Completed' && req.completedAt) {
            completedDateEl.textContent = req.completedAt + ' น.';
            durationEl.textContent = req.duration ? ('⏱️ ใช้เวลา: ' + req.duration) : 'เสร็จสิ้นเรียบร้อย';
            durationEl.classList.remove('hidden');
            completedBox.classList.remove('bg-rose-50/30', 'border-rose-100/80');
            completedBox.classList.add('bg-emerald-50/70', 'border-emerald-200/80');
        } else if (req.status === 'Completed') {
            completedDateEl.textContent = 'เสร็จสิ้นแล้ว';
            durationEl.classList.add('hidden');
            completedBox.classList.remove('bg-rose-50/30', 'border-rose-100/80');
            completedBox.classList.add('bg-emerald-50/70', 'border-emerald-200/80');



        } else {



            completedDateEl.innerHTML = '<span class="text-rose-600 font-semibold flex items-center gap-1.5"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span></span> กำลังดำเนินการ</span>';



            durationEl.classList.add('hidden');



            completedBox.classList.remove('bg-emerald-50/70', 'border-emerald-200/80');



            completedBox.classList.add('bg-rose-50/30', 'border-rose-100/80');



        }







        // รูปก่อนทำ



        const beforeContainer = document.getElementById('req_detail_before_img_container');



        if (req.requestImage) {



            beforeContainer.innerHTML = `



                <div class="relative group cursor-pointer rounded-2xl overflow-hidden border border-amber-200 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${req.requestImage}', 'รูปก่อนทำ (ปัญหาที่คุณแจ้ง - ห้อง ${req.roomNumber})')">



                    <img src="${req.requestImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="ก่อนทำ">



                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">



                        <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>



                    </div>



                </div>`;



        } else {



            beforeContainer.innerHTML = `



                <div class="h-32 rounded-2xl bg-white border border-dashed border-amber-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">



                    <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>



                    <span>ไม่ได้แนบรูปปัญหา</span>



                </div>`;



        }







        // รูปหลังทำ



        const afterContainer = document.getElementById('req_detail_after_img_container');



        if (req.completionImage) {
            const statusLabel = req.status === 'Pending Approval' 
                ? '<div class="mt-2 text-center text-xs font-bold text-orange-800 bg-orange-50 p-2.5 rounded-xl border border-orange-200 flex items-center justify-center gap-1.5"><i data-lucide="clock" class="w-4 h-4 text-orange-600"></i> ช่างส่งมอบผลงานแล้ว (อยู่ระหว่างนิติตรวจสอบและอนุมัติ)</div>' 
                : '<div class="mt-2 text-center text-xs font-bold text-emerald-800 bg-emerald-50 p-2.5 rounded-xl border border-emerald-200 flex items-center justify-center gap-1.5"><i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600"></i> นิติบุคคลตรวจสอบและอนุมัติงานเรียบร้อยแล้ว</div>';

            afterContainer.innerHTML = `
                <div class="relative group cursor-pointer rounded-2xl overflow-hidden border border-emerald-300 shadow-sm bg-slate-950 aspect-video flex items-center justify-center" onclick="openImageModal('${req.completionImage}', 'รูปหลังทำ (ผลงานที่ช่างส่งมอบ - ห้อง ${req.roomNumber})')">
                    <img src="${req.completionImage}" class="w-full h-full object-cover transition duration-300 group-hover:scale-105" alt="หลังทำ">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent flex items-end p-2.5 opacity-90 group-hover:opacity-100 transition">
                        <span class="text-xs text-white font-bold flex items-center gap-1"><i data-lucide="zoom-in" class="w-3.5 h-3.5"></i> แตะเพื่อดูภาพเต็ม</span>
                    </div>
                </div>` + statusLabel;
        } else if (req.status === 'Pending Approval') {
            afterContainer.innerHTML = `
                <div class="h-32 rounded-2xl bg-orange-50/70 border border-dashed border-orange-200 flex flex-col items-center justify-center text-orange-700 text-xs text-center p-3">
                    <div class="w-8 h-8 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center mb-1.5">
                        <i data-lucide="clock" class="w-4 h-4"></i>
                    </div>
                    <span class="font-bold text-orange-800">ช่างปฏิบัติงานเสร็จแล้ว</span>
                    <span class="text-[11px] text-orange-600 mt-0.5">อยู่ระหว่างนิติบุคคลตรวจสอบและอนุมัติงาน</span>
                </div>`;
        } else if (req.status === 'Completed') {
            afterContainer.innerHTML = `
                <div class="h-32 rounded-2xl bg-white border border-dashed border-emerald-200/80 flex flex-col items-center justify-center text-slate-400 text-xs">
                    <i data-lucide="image-off" class="w-6 h-6 mb-1 text-slate-300"></i>
                    <span>ไม่มีรูปภาพผลงาน</span>
                </div>`;
        } else {



            afterContainer.innerHTML = `



                <div class="h-32 rounded-2xl bg-rose-50/30 border border-dashed border-rose-100/80 flex flex-col items-center justify-center text-slate-400 text-xs text-center p-3">



                    <div class="w-8 h-8 rounded-full bg-rose-50 text-blue-500 flex items-center justify-center mb-1.5">



                        <i data-lucide="hourglass" class="w-4 h-4"></i>



                    </div>



                    <span class="font-bold text-slate-600">กำลังดำเนินการ</span>



                    <span class="text-[11px] text-slate-400 mt-0.5">ช่างจะอัปโหลดรูปเมื่อส่งมอบงาน</span>



                </div>`;



        }







        // ความคิดเห็น / ติชม (กล่องพิมพ์ติชมและแก้ไขได้ทันทีในหน้าเดียวกัน ไม่ต้องเด้งออกไปที่ไหน)

        const commentSec = document.getElementById('req_detail_comment_section');

        const commentDisplay = document.getElementById('req_detail_comment_display');

        const footerAction = document.getElementById('req_detail_footer_action');

        const canComment = (req.status === 'Completed' || req.tenantComment);



        if (canComment) {

            commentSec.classList.remove('hidden');

            const hasComment = !!(req.tenantComment && req.tenantComment.trim());

            const curComment = req.tenantComment ? req.tenantComment.trim() : '';



            commentDisplay.innerHTML = `

                <div class="space-y-3">

                    <div class="relative">

                        <textarea id="inline_tenant_comment_input" 

                            rows="3" 

                            placeholder="แตะที่นี่เพื่อพิมพ์ติชมหรือข้อเสนอแนะต่อการทำงาน ส่งตรงถึงนิติบุคคลได้ทันที..." 

                            class="w-full px-4 py-3 text-xs sm:text-sm bg-white border border-purple-200 rounded-2xl outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-200/50 text-slate-800 placeholder-slate-400 transition resize-y shadow-2xs leading-relaxed font-sans"></textarea>

                    </div>



                    <div class="flex items-center justify-between flex-wrap gap-2 pt-0.5 text-[11px]">

                        <div id="inline_tenant_comment_status" class="text-purple-700 flex items-center gap-1.5 font-sans">

                            ${req.commentedAt ? `<i data-lucide="clock" class="w-3.5 h-3.5 text-purple-600"></i> บันทึกเมื่อ: ${req.commentedAt} น.` : '<span class="text-slate-400">สามารถพิมพ์และกดบันทึกได้ทันที</span>'}

                        </div>

                        <div class="flex items-center gap-2">

                            <span id="inline_tenant_comment_saved_badge" class="hidden text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200 flex items-center gap-1">

                                <i data-lucide="check-circle-2" class="w-3.5 h-3.5 text-emerald-600"></i> บันทึกแล้ว!

                            </span>

                            <button type="button" onclick="saveTenantCommentInline(${req.requestId})" id="btn_save_tenant_comment" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-sm hover:shadow cursor-pointer">

                                <i data-lucide="send" class="w-3.5 h-3.5"></i>

                                <span id="btn_save_tenant_comment_text">${hasComment ? 'บันทึกการแก้ไข' : 'บันทึกความคิดเห็น'}</span>

                            </button>

                        </div>

                    </div>

                </div>

            `;

            const ta = document.getElementById('inline_tenant_comment_input');

            if (ta) ta.value = curComment;



            footerAction.innerHTML = '';

        } else {

            commentSec.classList.add('hidden');

            footerAction.innerHTML = '';

        }



        lucide.createIcons();



        document.getElementById('requestDetailModal').classList.remove('hidden');



    }







    function closeRequestDetailModal() {



        const modal = document.getElementById('requestDetailModal');



        if (modal) modal.classList.add('hidden');



    }







    function openImageModal(url, titleText) {



        document.getElementById('previewImage').src = url;



        document.getElementById('imageTitle').textContent = titleText;



        document.getElementById('imagePreviewModal').classList.remove('hidden');



    }



    



    function closeImageModal() {



        document.getElementById('imagePreviewModal').classList.add('hidden');



        document.getElementById('previewImage').src = ''; 



    }







    function escapeHtml(str) {

        if (!str) return '';

        return String(str)

            .replace(/&/g, '&amp;')

            .replace(/</g, '&lt;')

            .replace(/>/g, '&gt;')

            .replace(/"/g, '&quot;')

            .replace(/'/g, '&#039;');

    }



    // --- ฟังก์ชันบันทึกความคิดเห็นแบบ Inline ในหน้าเดียวกัน ---

    function saveTenantCommentInline(reqId) {

        const textarea = document.getElementById('inline_tenant_comment_input');

        if (!textarea) return;

        const comment = textarea.value.trim();

        if (!comment) {

            if (typeof Swal !== 'undefined') {

                Swal.fire({

                    icon: 'warning',

                    title: 'กรุณากรอกความคิดเห็น',

                    text: 'โปรดพิมพ์ข้อความติชมหรือข้อเสนอแนะก่อนกดบันทึกครับ',

                    confirmButtonColor: '#7c3aed'

                });

            } else {

                alert('กรุณากรอกความคิดเห็นก่อนกดส่งครับ');

            }

            textarea.focus();

            return;

        }



        const btn = document.getElementById('btn_save_tenant_comment');

        const btnText = document.getElementById('btn_save_tenant_comment_text');

        const savedBadge = document.getElementById('inline_tenant_comment_saved_badge');

        const statusBox = document.getElementById('inline_tenant_comment_status');



        if (btn) btn.disabled = true;

        if (btnText) btnText.textContent = 'กำลังบันทึก...';



        const formData = new FormData();

        formData.append('action', 'save_tenant_comment');

        formData.append('request_id', reqId);

        formData.append('tenant_comment', comment);



        fetch(window.location.href, {

            method: 'POST',

            body: formData

        })

        .then(res => res.json())

        .then(data => {

            if (btn) btn.disabled = false;

            if (btnText) btnText.textContent = 'บันทึกการแก้ไข';



            if (data.success) {

                if (statusBox) {

                    statusBox.innerHTML = `<i data-lucide="clock" class="w-3.5 h-3.5 text-purple-600"></i> บันทึกเมื่อ: ${data.commentedAt} น.`;

                }

                if (savedBadge) {

                    savedBadge.classList.remove('hidden');

                    setTimeout(() => {

                        savedBadge.classList.add('hidden');

                    }, 3500);

                }

                if (window.tenantRequests && window.tenantRequests[reqId]) {

                    window.tenantRequests[reqId].tenantComment = comment;

                    window.tenantRequests[reqId].commentedAt = data.commentedAt;

                }



                // 🌟 อัปเดตการ์ดที่แสดงบนหน้าเว็บให้แสดงข้อความติชมทันทีโดยไม่ต้องรีเฟรช 🌟

                const cardCommentSec = document.getElementById('card_comment_section_' + reqId);

                if (cardCommentSec) {

                    cardCommentSec.innerHTML = `

                        <div class="bg-purple-50/70 border border-purple-200/80 rounded-2xl p-4 text-xs transition duration-300">

                            <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">

                                <span class="font-bold text-purple-900 flex items-center gap-1.5 text-xs sm:text-sm">

                                    <i data-lucide="message-square-quote" class="w-4 h-4 text-purple-600"></i> ข้อคิดเห็น/ติชมของคุณ:

                                </span>

                                <span class="text-[10px] bg-white text-purple-700 font-semibold px-2 py-0.5 rounded-full border border-purple-200 flex items-center gap-1 shadow-2xs">

                                    <i data-lucide="lock" class="w-3 h-3 text-purple-500"></i> ลับเฉพาะคุณและแอดมิน (ช่าง/แม่บ้านไม่เห็น)

                                </span>

                            </div>

                            <p class="text-slate-700 whitespace-pre-line bg-white/90 p-3 rounded-xl border border-purple-100 leading-relaxed font-sans font-medium">"${escapeHtml(comment)}"</p>

                            <div class="mt-2.5 flex items-center justify-between text-[11px] text-purple-600/90 pt-1">

                                <span>

                                    <i data-lucide="clock" class="w-3 h-3 inline mr-0.5"></i> ส่งเมื่อ: ${data.commentedAt} น.

                                </span>

                                <button type="button" onclick="event.stopPropagation(); openCommentModal(${reqId}, '${escapeHtml(comment)}')" class="text-purple-700 hover:text-purple-900 font-bold flex items-center gap-1 hover:underline transition cursor-pointer">

                                    <i data-lucide="edit-3" class="w-3 h-3"></i> แก้ไขความคิดเห็น

                                </button>

                            </div>

                        </div>

                    `;

                }



                const sideBtn = document.getElementById('card_comment_side_btn_' + reqId);

                if (sideBtn) {

                    sideBtn.className = "w-full px-3 py-2 bg-purple-50 hover:bg-purple-100 text-purple-700 border-purple-200 border rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-2xs";

                    sideBtn.innerHTML = `<i data-lucide="message-square-quote" class="w-3.5 h-3.5"></i> แก้ไขความคิดเห็น`;

                    sideBtn.setAttribute('onclick', `event.stopPropagation(); openCommentModal(${reqId}, '${escapeHtml(comment)}')`);

                }



                lucide.createIcons();



                if (typeof Swal !== 'undefined') {

                    const Toast = Swal.mixin({

                        toast: true,

                        position: 'top-end',

                        showConfirmButton: false,

                        timer: 2500,

                        timerProgressBar: true

                    });

                    Toast.fire({

                        icon: 'success',

                        title: 'บันทึกความคิดเห็นเรียบร้อยแล้ว'

                    });

                }

            } else {

                if (typeof Swal !== 'undefined') {

                    Swal.fire({

                        icon: 'error',

                        title: 'เกิดข้อผิดพลาด',

                        text: data.message || 'ไม่สามารถบันทึกความคิดเห็นได้',

                        confirmButtonColor: '#7c3aed'

                    });

                } else {

                    alert(data.message || 'เกิดข้อผิดพลาด');

                }

            }

        })

        .catch(err => {

            if (btn) btn.disabled = false;

            if (btnText) btnText.textContent = 'บันทึกการแก้ไข';

            console.error(err);

            alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');

        });

    }



    // เมื่อกดปุ่มติชมงานจากการ์ดภายนอก ให้เปิดหน้ารายละเอียดและโฟกัสไปที่ช่องติชมทันที

    function openCommentModal(reqId, currentComment) {

        openRequestDetailModal(reqId);

        setTimeout(() => {

            const input = document.getElementById('inline_tenant_comment_input');

            if (input) {

                input.scrollIntoView({ behavior: 'smooth', block: 'center' });

                input.focus();

            }

        }, 200);

    }



    function closeCommentModal() {

        const modal = document.getElementById('commentModal');

        if (modal) modal.classList.add('hidden');

    }

    function filterRequests(status) {



        const cards = document.querySelectorAll('.req-card');



        const buttons = document.querySelectorAll('.filter-btn');







        buttons.forEach(btn => {



            if (btn.getAttribute('data-filter') === status) {



                btn.classList.add('bg-slate-800', 'text-white');



                btn.classList.remove('bg-white', 'text-slate-600');



            } else {



                btn.classList.remove('bg-slate-800', 'text-white');



                btn.classList.add('bg-white', 'text-slate-600');



            }



        });







        cards.forEach(card => {
            const cardStatus = card.getAttribute('data-status');
            let isMatch = false;
            if (status === 'all') {
                isMatch = true;
            } else if (status === 'In Progress') {
                isMatch = (cardStatus === 'In Progress' || cardStatus === 'Pending Approval');
            } else {
                isMatch = (cardStatus === status);
            }
            if (isMatch) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });



    }







    // ปิด Modal เมื่อกดปุ่ม Escape



    document.addEventListener('keydown', function(e) {



        if (e.key === 'Escape') {



            closeRequestDetailModal();



            closeCommentModal();



            closeImageModal();



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







</script>



</body>



</html>



