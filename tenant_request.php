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



// ปรับปรุงขนาด preferredTime อัตโนมัติ ป้องกันปัญหา Data too long

try {

    $conn->exec("ALTER TABLE ServiceRequest MODIFY COLUMN preferredTime VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");

} catch(Throwable $e) {}



// 1. ดึงข้อมูลห้องพักปัจจุบันของผู้เช่า



$stmtRoom = $conn->prepare("



    SELECT l.roomId, r.roomNumber, r.roomType, r.floor 



    FROM Lease l 



    JOIN Room r ON l.roomId = r.roomId 



    WHERE l.tenantId = ? AND l.status = 'Active' 



    LIMIT 1



");



$stmtRoom->execute([$tenantId]);



$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);



$roomId = $myRoom ? $myRoom['roomId'] : null;



$roomNumber = $myRoom ? $myRoom['roomNumber'] : null;



// --- ระบบบันทึกการแจ้งซ่อม ---



if (isset($_POST['submit_request'])) {



    if (!$roomId) {



        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> ไม่สามารถแจ้งซ่อมได้ เนื่องจากไม่พบข้อมูลสัญญาเช่าที่ใช้งานอยู่</div>";



    } elseif (empty($_POST['preferredDate'])) {



        $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> กรุณาระบุวันและช่วงเวลาที่สะดวกให้ช่างเข้าทำงาน (จำเป็นต้องเลือก)</div>";



    } else {



        $category = $_POST['category'] ?? 'อื่นๆ';



        $description = trim($_POST['description'] ?? '');



        $preferredDate = !empty($_POST['preferredDate']) ? $_POST['preferredDate'] : null;
        $preferredTime = !empty($_POST['preferredTime']) ? mb_substr(trim($_POST['preferredTime']), 0, 250, 'UTF-8') : null;
        $preferredPeriod = !empty($_POST['preferred_period']) ? trim($_POST['preferred_period']) : '';
        $preferredStartTime = !empty($_POST['preferredStartTime']) ? trim($_POST['preferredStartTime']) : '';
        $preferredEndTime = !empty($_POST['preferredEndTime']) ? trim($_POST['preferredEndTime']) : '';
        $estimatedHours = isset($_POST['estimated_hours']) ? max(1, min(4, intval($_POST['estimated_hours']))) : 2;
        $allowEntry = !empty($_POST['allowEntry']) ? 1 : 0;
        $requestImage = null;

        // ดึงเวลาเริ่มต้นและสิ้นสุด
        $checkStartTime = $preferredStartTime;
        $checkEndTime = $preferredEndTime;
        if (empty($checkStartTime) && $preferredTime && preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $preferredTime, $tm)) {
            $checkStartTime = $tm[1];
            $checkEndTime = $tm[2];
        } elseif (empty($checkStartTime) && $preferredTime && preg_match('/(\d{1,2}:\d{2})/', $preferredTime, $tm)) {
            $checkStartTime = $tm[1];
        }

        // ประกอบค่า preferredTime หากมีเวลาเริ่มต้น-สิ้นสุด
        if ($checkStartTime && $checkEndTime) {
            $pName = ($preferredPeriod === 'morning') ? 'ช่วงเช้า' : 'ช่วงบ่าย-เย็น';
            $preferredTime = "{$pName} (เวลา {$checkStartTime} - {$checkEndTime} น.)";
        }

        // ตรวจสอบไม่ให้เลือกเวลาที่ผ่านมาแล้ว
        $todayStr = date('Y-m-d');
        $currentTimeStr = date('H:i');
        $isPastTime = false;
        $invalidPeriodTime = false;
        $periodErrMsg = '';

        if ($preferredDate < $todayStr) {
            $isPastTime = true;
        } elseif ($preferredDate === $todayStr && $checkStartTime && $checkStartTime <= $currentTimeStr) {
            $isPastTime = true;
        }

        // ข้อกำหนด: ตรวจสอบลำดับเวลาเริ่มต้น-สิ้นสุด
        if ($checkStartTime && $checkEndTime && $checkEndTime <= $checkStartTime) {
            $invalidPeriodTime = true;
            $periodErrMsg = "เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น (ตัวอย่างเช่น 09:00 - 11:30 น.)";
        }
        // ข้อกำหนด: หากผู้เช่าทำการเลือกช่วงเช้าจะไม่สามารถเลือกเวลาของช่วงบ่าย-เย็นได้
        elseif ($preferredPeriod === 'morning' && (($checkStartTime && $checkStartTime >= '12:00') || ($checkEndTime && $checkEndTime >= '12:00'))) {
            $invalidPeriodTime = true;
            $periodErrMsg = "คุณเลือกช่วงเช้า ไม่สามารถเลือกเวลาของช่วงบ่าย-เย็นได้ (กรุณาระบุเวลาช่วง 08:00 - 11:59 น.)";
        } elseif ($preferredPeriod === 'afternoon' && (($checkStartTime && $checkStartTime < '12:00') || ($checkEndTime && $checkEndTime < '12:00'))) {
            $invalidPeriodTime = true;
            $periodErrMsg = "คุณเลือกช่วงบ่าย-เย็น ไม่สามารถเลือกเวลาของช่วงเช้าได้ (กรุณาระบุเวลาช่วง 12:00 - 18:00 น.)";
        }

        if ($isPastTime) {
            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> ไม่สามารถเลือกเวลาที่ผ่านมาแล้วได้ กรุณาเลือกเวลาปัจจุบันหรือเวลาหลังจากนี้เท่านั้น</div>";
        } elseif ($invalidPeriodTime) {
            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> {$periodErrMsg}</div>";
        } else {

        // ตรวจสอบการอัปโหลดรูปภาพปัญหา (ถ้ามี)



        if (isset($_FILES['request_image']) && $_FILES['request_image']['error'] == 0) {
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) { 
                mkdir($uploadDir, 0777, true); 
            }
            $ext = strtolower(pathinfo($_FILES['request_image']['name'], PATHINFO_EXTENSION));
            $fileName = time() . '_req_' . uniqid() . '.' . $ext;
            $targetFilePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['request_image']['tmp_name'], $targetFilePath)) {
                $requestImage = 'uploads/' . $fileName;
            }
        }



        



        try {

            $stmt = $conn->prepare("INSERT INTO ServiceRequest (tenantId, roomId, category, description, requestImage, preferredDate, preferredTime, estimatedHours, allowEntry, status, requestDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())");

            $success = $stmt->execute([$tenantId, $roomId, $category, $description, $requestImage, $preferredDate, $preferredTime, $estimatedHours, $allowEntry]);

        } catch(Throwable $e) {

            $success = false;

            $dbError = $e->getMessage();

        }



        if (!empty($success)) {



            $submittedTime = date("d/m/Y H:i:s");



            $prefDisplay = !empty($preferredDate) ? date("d/m/Y", strtotime($preferredDate)) : 'คิวที่เร็วที่สุด';



            $timeDisplay = !empty($preferredTime) ? $preferredTime : 'ตามสะดวกของช่าง';



            



            $message = "<div class='alert-box p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 rounded-2xl shadow-sm text-sm font-medium flex flex-col sm:flex-row sm:items-center justify-between gap-3'>



                <div class='flex items-center gap-2.5'>



                    <i data-lucide='check-circle-2' class='w-5 h-5 text-emerald-600 shrink-0'></i>



                    <div>



                        <span class='font-bold text-slate-800'>ส่งเรื่องแจ้งซ่อมเรียบร้อยแล้ว! ข้อมูลถูกส่งไปยังนิติบุคคลแบบเรียลไทม์</span>



                        <div class='text-xs text-emerald-700 mt-0.5 font-sans'>



                            เวลานัดหมาย: <strong class='text-emerald-900'>{$prefDisplay}</strong> ({$timeDisplay})



                        </div>



                    </div>



                </div>



                <div class='flex items-center gap-2 shrink-0'>



                    <a href='tenant_dashboard.php' class='px-3.5 py-1.5 bg-white hover:bg-rose-50/30 text-slate-700 border border-rose-100/80 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs'>



                        <i data-lucide='layout-dashboard' class='w-3.5 h-3.5 text-rose-600'></i> หน้าแดชบอร์ด



                    </a>



                    <a href='tenant_request_history.php' class='px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-2xs'>



                        <i data-lucide='history' class='w-3.5 h-3.5'></i> ดูประวัติงานซ่อม



                    </a>



                </div>



            </div>";



        } else {

            $errDetail = !empty($dbError) ? " (" . htmlspecialchars($dbError) . ")" : "";

            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> เกิดข้อผิดพลาดในการส่งข้อมูล: {$errDetail} กรุณาลองใหม่อีกครั้ง</div>";

        }

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



    body { background-color: #FAF9F6; color: #1e293b; }



    



    /* ซ่อน scrollbar ในแนวนอน */



    .no-scrollbar::-webkit-scrollbar { display: none; }



    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }



  </style>



  <?php include_once 'includes/theme_head.php'; ?>
</head>



<body class="min-h-screen flex flex-col antialiased selection:bg-amber-600 selection:text-white bg-[#FAF9F6] text-slate-800">



  <!-- 🌟 แท็บด้านซ้ายมือ สำหรับผู้เช่าพักอาศัย (Tenant Sidebar) 🌟 -->



  <?php include_once 'includes/tenant_sidebar.php'; ?>



  <!-- Top Navigation Bar -->



  <!-- Top Navigation Bar -->



  <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-rose-100/80 shadow-sm">



    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">



      <div class="flex items-center justify-between h-16">



        <div class="flex items-center space-x-3">



          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer mr-1" title="เปิด/ปิด แถบเมนูด้านซ้าย">



            <i data-lucide="menu" class="w-5 h-5"></i>



          </button>



          



          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 flex items-center justify-center text-white shadow-md">



            <i data-lucide="wrench" class="w-5 h-5"></i>



          </div>



          <div>
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <p class="text-[11px] text-slate-400">ระบบแจ้งซ่อมและนัดหมายเวลาปฏิบัติงาน</p>
          </div>



        </div>



        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

           <a href="tenant_dashboard.php" class="px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs sm:text-sm font-semibold transition flex items-center gap-1.5">
              <i data-lucide="arrow-left" class="w-4 h-4"></i> <span class="hidden sm:inline">กลับหน้าหลัก</span><span class="sm:hidden">กลับ</span>
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



  <main class="flex-1 max-w-3xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">



    



    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->

    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">

      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>



      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">

        <div>

          <div class="flex items-center gap-2 flex-wrap mb-2">

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">

              <i data-lucide="wrench" class="w-3.5 h-3.5 text-rose-500"></i>

              <span>บริการแจ้งซ่อม & ช่างประจำอาคาร</span>

            </span>

            

          </div>

          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">

            แจ้งซ่อมและขอรับบริการ

          </h1>

          <p class="text-xs sm:text-sm text-slate-500 mt-1">

            ส่งเรื่องให้นิติบุคคล พร้อมระบุหมวดหมู่งานและช่วงเวลาที่คุณสะดวกให้ช่างเข้าดำเนินการ

          </p>

        </div>



        

      </div>

    </div>



        <form action="" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-6">



            



            <!-- 1. ประเภทงาน / ปัญหาที่พบ -->



            <div>



                <label class="block text-sm font-bold text-slate-700 mb-1.5 flex items-center justify-between">



                    <span class="flex items-center gap-1.5">



                        <i data-lucide="wrench" class="w-4 h-4 text-amber-500"></i>



                        <span>ประเภทงาน / ปัญหาที่พบ </span>



                        <span class="text-rose-500">*</span>



                    </span>



                    



                </label>



                <!-- Hidden inputs for category and estimated_hours -->

                <input type="hidden" name="category" id="categoryInput" value="ประปา/ห้องน้ำ" required>

                <input type="hidden" name="estimated_hours" id="estimatedHoursInput" value="2">



                <!-- 🌟 Custom Luxury Rounded Dropdown (ไม่ใช่กรอบสี่เหลี่ยม OS) 🌟 -->

                <div class="relative" id="customCategoryDropdown">

                    <button type="button" onclick="toggleCategoryDropdown(event)" id="categoryDropdownTrigger"
                            class="w-full px-4 py-3.5 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 rounded-2xl flex items-center justify-between text-xs sm:text-sm font-bold text-slate-800 shadow-xs transition-all duration-200 cursor-pointer">

                        <div class="flex items-center gap-3 min-w-0">

                            <div id="catTriggerIcon" class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 border border-blue-200/80 flex items-center justify-center text-lg shrink-0">

                                🚿

                            </div>

                            <div class="text-left min-w-0">

                                <div id="catTriggerTitle" class="text-xs sm:text-sm font-bold text-slate-900 truncate">ประปา / ท่อน้ำรั่วซึม / สุขภัณฑ์ / ชักโครก</div>

                                <div id="catTriggerSubtitle" class="text-[11px] text-slate-500 font-medium truncate">หมวดหมู่ปัญหา</div>

                            </div>

                        </div>

                        <div class="flex items-center gap-2 shrink-0">

                            <i data-lucide="chevron-down" id="catTriggerChevron" class="w-4 h-4 text-rose-500 transition-transform duration-200"></i>

                        </div>

                    </button>



                    <!-- Floating Menu Panel -->

                    <div id="categoryDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-2 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-3xl shadow-xl shadow-rose-500/10 p-3 space-y-3 max-h-80 overflow-y-auto scrollbar-thin transition-all duration-200">

                        

                        <!-- 🟢 งานขนาดเล็ก (1 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> งานทั่วไป / ไฟฟ้า / ประตู

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('ไฟฟ้า/หลอดไฟ', 1, '💡', 'ไฟฟ้า / หลอดไฟ / ปลั๊กไฟ', 'งานทั่วไป / ไฟฟ้า / ประตู', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-emerald-50/70 border border-transparent hover:border-emerald-200/60"

                                     data-val="ไฟฟ้า/หลอดไฟ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">💡</span>

                                        <span class="text-xs font-bold text-slate-800">ไฟฟ้า / หลอดไฟ / ปลั๊กไฟ</span>

                                    </div>

                                    

                                </div>

                                <div onclick="selectCategoryOption('ลูกบิดประตู/ก๊อกน้ำ', 1, '🚪', 'ลูกบิดประตู / ก๊อกน้ำ / ขันน็อต', 'งานทั่วไป / ไฟฟ้า / ประตู', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-emerald-50/70 border border-transparent hover:border-emerald-200/60"

                                     data-val="ลูกบิดประตู/ก๊อกน้ำ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🚪</span>

                                        <span class="text-xs font-bold text-slate-800">ลูกบิดประตู / ก๊อกน้ำ / ขันน็อต</span>

                                    </div>

                                    

                                </div>

                                <div onclick="selectCategoryOption('เก็บขยะ/สิ่งปฏิกูล', 1, '🗑️', 'เก็บขยะ / สิ่งปฏิกูล', 'งานทั่วไป / ไฟฟ้า / ประตู', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-emerald-50/70 border border-transparent hover:border-emerald-200/60"

                                     data-val="เก็บขยะ/สิ่งปฏิกูล">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🗑️</span>

                                        <span class="text-xs font-bold text-slate-800">เก็บขยะ / สิ่งปฏิกูล</span>

                                    </div>

                                    

                                </div>

                            </div>

                        </div>



                        <!-- 🔵 งานปานกลาง / งานทั่วไป (2 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-blue-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-blue-500"></span> งานประปา / ห้องพัก

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('ประปา/ห้องน้ำ', 2, '🚿', 'ประปา / ท่อน้ำรั่วซึม / สุขภัณฑ์ / ชักโครก', 'งานประปา / ห้องพัก', 'bg-blue-50 text-blue-700 border-blue-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition bg-blue-50/70 border border-blue-200/80"

                                     data-val="ประปา/ห้องน้ำ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🚿</span>

                                        <span class="text-xs font-bold text-slate-800">ประปา / ท่อน้ำรั่วซึม / สุขภัณฑ์ / ชักโครก</span>

                                    </div>

                                    

                                </div>

                                <div onclick="selectCategoryOption('เฟอร์นิเจอร์/ประตู/หน้าต่าง', 2, '🪑', 'เฟอร์นิเจอร์ / ประตู / หน้าต่าง / บานพับ', 'งานประปา / ห้องพัก', 'bg-blue-50 text-blue-700 border-blue-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-blue-50/70 border border-transparent hover:border-blue-200/60"

                                     data-val="เฟอร์นิเจอร์/ประตู/หน้าต่าง">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🪑</span>

                                        <span class="text-xs font-bold text-slate-800">เฟอร์นิเจอร์ / ประตู / หน้าต่าง / บานพับ</span>

                                    </div>

                                    

                                </div>

                                <div onclick="selectCategoryOption('ทำความสะอาดห้องพัก', 2, '🧹', 'ทำความสะอาดห้องพัก', 'งานประปา / ห้องพัก', 'bg-blue-50 text-blue-700 border-blue-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-blue-50/70 border border-transparent hover:border-blue-200/60"

                                     data-val="ทำความสะอาดห้องพัก">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🧹</span>

                                        <span class="text-xs font-bold text-slate-800">ทำความสะอาดห้องพัก</span>

                                    </div>

                                    

                                </div>

                            </div>

                        </div>



                        <!-- 🟣 งานขนาดใหญ่ (3 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-purple-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-purple-500"></span> งานระบบไฟฟ้าหลัก

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('ระบบไฟหลัก/ตู้เบรกเกอร์', 3, '⚡', 'ซ่อมระบบไฟหลัก / ตู้เบรกเกอร์', 'งานระบบไฟฟ้าหลัก', 'bg-purple-50 text-purple-700 border-purple-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-purple-50/70 border border-transparent hover:border-purple-200/60"

                                     data-val="ระบบไฟหลัก/ตู้เบรกเกอร์">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">⚡</span>

                                        <span class="text-xs font-bold text-slate-800">ซ่อมระบบไฟหลัก / ตู้เบรกเกอร์</span>

                                    </div>

                                    

                                </div>

                            </div>

                        </div>



                        <!-- 🔴 งานซ่อมหนัก (4 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-rose-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-rose-500"></span> งานโครงสร้างและอื่นๆ

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('รื้อท่อประปา/ฝ้าเพดาน/ยาแนว', 4, '🛠️', 'รื้อซ่อมท่อประปา / ซ่อมฝ้าเพดาน / ยาแนวห้องน้ำ', 'งานโครงสร้างและอื่นๆ', 'bg-rose-50 text-rose-700 border-rose-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-rose-50/70 border border-transparent hover:border-rose-200/60"

                                     data-val="รื้อท่อประปา/ฝ้าเพดาน/ยาแนว">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🛠️</span>

                                        <span class="text-xs font-bold text-slate-800">รื้อซ่อมท่อประปา / ซ่อมฝ้าเพดาน / ยาแนว</span>

                                    </div>

                                    

                                </div>

                                <div onclick="selectCategoryOption('อื่นๆ', 2, '📦', 'อื่นๆ (ระบุในรายละเอียดปัญหา)', 'งานทั่วไป', 'bg-slate-100 text-slate-700 border-slate-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-slate-50 border border-transparent hover:border-slate-200"

                                     data-val="อื่นๆ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">📦</span>

                                        <span class="text-xs font-bold text-slate-800">อื่นๆ (ระบุในรายละเอียดปัญหา)</span>

                                    </div>

                                    

                                </div>

                            </div>

                        </div>



                    </div>

                </div>
            <!-- 2. รายละเอียดปัญหา -->
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1.5">
                    รายละเอียดปัญหา <span class="text-rose-500">*</span>
                </label>
                <textarea name="description" required rows="3" placeholder="อธิบายปัญหาที่พบ เช่น หลอดไฟห้องน้ำขาด, ก๊อกอ่างล้างหน้าน้ำหยดตลอดเวลา เพื่อให้นิติบุคคลเตรียมอุปกรณ์ได้ถูกต้อง..." class="w-full px-4 py-3 text-sm bg-white text-slate-800 placeholder-slate-400 border border-stone-200 focus:border-rose-500 focus:ring-2 focus:ring-rose-200/50 rounded-xl outline-none transition leading-relaxed shadow-2xs"></textarea>
            </div>

            <!-- 3. รูปถ่ายหน้างาน -->
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1.5">
                    รูปถ่ายปัญหาหน้างาน <span class="text-slate-400 font-normal text-xs">(ถ้ามี จะช่วยให้นิติวินิจฉัยและเตรียมอะไหล่ได้เร็วขึ้น)</span>
                </label>
                <input type="file" name="request_image" accept="image/*" class="w-full text-sm text-slate-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-stone-100 file:text-slate-700 hover:file:bg-stone-200 bg-white border border-stone-200 rounded-xl transition cursor-pointer shadow-2xs">
            </div>

            <!-- ======================================================== -->
            <!-- 🌟 5. วันและช่วงเวลาที่สะดวก 🌟 -->
            <!-- ======================================================== -->
            <div class="pt-4 border-t border-rose-100/60 space-y-4">
                <div class="flex items-center justify-between">
                    <label class="text-sm font-bold text-slate-800 flex items-center gap-2">
                        <i data-lucide="calendar" class="w-4 h-4 text-rose-600"></i>
                        <span>ระบุวันและช่วงเวลาที่สะดวกให้ช่างเข้าทำงาน</span>
                        <span class="text-rose-500">*</span>
                    </label>
                    <span class="text-[11px] text-rose-700 bg-rose-50 px-2.5 py-0.5 rounded-full font-bold border border-rose-200 flex items-center gap-1 shadow-2xs">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                        จำเป็นต้องเลือก
                    </span>
                </div>

                <!-- 5.1 วันที่สะดวก -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        วันที่สะดวกเข้าห้อง <span class="text-rose-500 font-bold">* (จำเป็นต้องเลือก)</span>
                    </label>
                    <input type="date" name="preferredDate" id="preferredDateInput" required value="<?php echo (date('H:i') >= '16:00') ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'); ?>" min="<?php echo (date('H:i') >= '16:00') ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'); ?>" onchange="handleDateChange()" class="w-full px-4 py-2.5 text-sm bg-white text-slate-800 border border-stone-200 rounded-xl outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-200/50 transition cursor-pointer shadow-2xs">
                    <p class="text-[11px] text-slate-400 mt-1">โปรดระบุวันที่ที่ท่านสะดวกให้ช่างหรือแม่บ้านเข้าดำเนินการ</p>
                </div>

                <!-- 5.2 ช่วงเวลา (เช้า vs บ่าย-เย็น) -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-2">เลือกช่วงเวลาที่สะดวก (เช้า - บ่าย/เย็น) <span class="text-rose-500 font-bold">* (จำเป็นต้องเลือก)</span></label>
                    <div class="grid grid-cols-2 gap-3">
                        
                        <!-- ช่วงเช้า -->
                        <label class="cursor-pointer">
                            <input type="radio" name="preferred_period" value="morning" checked onchange="updateTimeSlots()" class="peer sr-only">
                            <div class="p-3.5 rounded-2xl border-2 border-rose-100/80 bg-white peer-checked:border-blue-600 peer-checked:bg-rose-50/50 hover:bg-rose-50/30 transition flex items-center gap-3 shadow-2xs">
                                <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                                    <i data-lucide="sun" class="w-5 h-5"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-slate-800">☀️ ช่วงเช้า</div>
                                </div>
                            </div>
                        </label>

                        <!-- ช่วงบ่าย - เย็น -->
                        <label class="cursor-pointer">
                            <input type="radio" name="preferred_period" value="afternoon" onchange="updateTimeSlots()" class="peer sr-only">
                            <div class="p-3.5 rounded-2xl border-2 border-rose-100/80 bg-white peer-checked:border-blue-600 peer-checked:bg-rose-50/50 hover:bg-rose-50/30 transition flex items-center gap-3 shadow-2xs">
                                <div class="w-10 h-10 rounded-xl bg-orange-100 text-orange-600 flex items-center justify-center shrink-0">
                                    <i data-lucide="sunset" class="w-5 h-5"></i>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-slate-800">⛅ ช่วงบ่าย - เย็น</div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- 5.3 ระบุเวลาที่สะดวก (ตั้งแต่เวลา - ถึงเวลา) -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-xs font-bold text-slate-700">
                            ระบุเวลาที่สะดวก (ตั้งแต่เวลา - ถึงเวลา) <span class="text-rose-500 font-bold">*</span>
                        </label>
                    </div>

                    <!-- Hidden input to submit with the form -->
                    <input type="hidden" name="preferredTime" id="preferredTime" value="ช่วงเช้า (เวลา 09:00 - 11:30 น.)">

                    <div class="space-y-2">
                        <!-- ช่องระบุเวลา: ตั้งแต่เวลา - ถึงเวลา -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center gap-1.5">
                                    <i data-lucide="clock" class="w-3.5 h-3.5 text-rose-500"></i>
                                    <span>ตั้งแต่เวลา</span>
                                    <span class="text-rose-500">*</span>
                                </label>
                                <input type="time" name="preferredStartTime" id="preferredStartTime" required
                                       value="09:00"
                                       onchange="handleTimeRangeChange()"
                                       class="w-full px-4 py-2.5 bg-white border-2 border-stone-200 rounded-xl text-slate-800 text-sm font-bold font-mono focus:outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-200/50 transition shadow-2xs">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 mb-1 flex items-center gap-1.5">
                                    <i data-lucide="clock-arrow-up" class="w-3.5 h-3.5 text-rose-500"></i>
                                    <span>ถึงเวลา</span>
                                    <span class="text-rose-500">*</span>
                                </label>
                                <input type="time" name="preferredEndTime" id="preferredEndTime" required
                                       value="11:30"
                                       onchange="handleTimeRangeChange()"
                                       class="w-full px-4 py-2.5 bg-white border-2 border-stone-200 rounded-xl text-slate-800 text-sm font-bold font-mono focus:outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-200/50 transition shadow-2xs">
                            </div>
                        </div>

                        <!-- คำแนะนำการระบุเวลา -->
                        <div id="time-restriction-hint" class="text-[11px] text-slate-500 flex items-center gap-1.5 pt-1">
                            <i data-lucide="info" class="w-3.5 h-3.5 text-blue-500 shrink-0"></i>
                            <span id="time-restriction-text">ผู้เช่าสามารถระบุเวลาเริ่มต้นและเวลาสิ้นสุดที่สะดวกได้ตามต้องการ</span>
                        </div>
                    </div>
                </div>

                <!-- 5.4 กล่องสรุปเวลานัดหมายแบบเรียลไทม์ (Live Preview) -->



                <div class="p-3.5 bg-gradient-to-r from-blue-50 to-indigo-50 border border-rose-200/80 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">



                    <div class="flex items-center gap-2.5">



                        <div class="w-8 h-8 rounded-xl bg-rose-500 text-white flex items-center justify-center shrink-0 shadow-2xs">



                            <i data-lucide="calendar-check-2" class="w-4 h-4"></i>



                        </div>



                        <div>



                            <span class="text-[10px] text-slate-400 uppercase tracking-wider block font-semibold">สรุปเวลานัดหมายที่เลือก</span>



                            <span class="font-bold text-slate-800 text-xs sm:text-sm">



                                <span id="preview-date">ตามคิวที่เร็วที่สุด</span>



                                <span class="text-slate-400 mx-1">•</span>



                                <span id="preview-time" class="text-rose-700">ช่วงเช้า (เวลา 09:00 - 11:30 น.)</span>



                            </span>



                        </div>



                    </div>



                    



                </div>



            </div>



            <!-- 7. Submit Button -->



            <div class="pt-2">



                <button type="submit" name="submit_request" class="w-full py-3.5 bg-gradient-to-r from-slate-900 to-slate-800 hover:from-black hover:to-slate-900 text-white rounded-xl text-sm font-bold shadow-md hover:shadow-lg transition flex items-center justify-center gap-2 active:scale-[0.99] cursor-pointer">



                    <i data-lucide="send" class="w-4 h-4 text-amber-400"></i> ส่งเรื่องแจ้งซ่อมให้นิติบุคคล



                </button>



            </div>



        </form>



    </div>

  </main>



  <script src="js/realtime-time.js"></script>



  <script>



    lucide.createIcons();



    // ระบบแจ้งเตือน Auto Hide



    document.addEventListener("DOMContentLoaded", function() {



        const alerts = document.querySelectorAll(".alert-box");



        alerts.forEach(function(alert) {



            setTimeout(function() {



                alert.style.opacity = "0";



                setTimeout(function() { alert.remove(); }, 500);



            }, 6000);



        });



        // Initialize time slots



        updateTimeSlots();



    });



    // ปรับปรุงขนาดงานและเวลาตามประเภทที่เลือกใน Dropdown



    

    // 🌟 ระบบ Custom Category Dropdown สำหรับแจ้งซ่อม 🌟

    function toggleCategoryDropdown(e) {

        if (e) e.stopPropagation();

        const menu = document.getElementById('categoryDropdownMenu');

        const chevron = document.getElementById('catTriggerChevron');

        if (menu) {

            const isHidden = menu.classList.contains('hidden');

            menu.classList.toggle('hidden');

            if (chevron) {

                if (isHidden) chevron.classList.add('rotate-180');

                else chevron.classList.remove('rotate-180');

            }

        }

        if (window.lucide) lucide.createIcons();

    }



    function selectCategoryOption(val, hours, icon, title, subtitle, badgeClass, e) {
        if (e) e.stopPropagation();

        const catInp = document.getElementById('categoryInput');
        const estInp = document.getElementById('estimatedHoursInput');
        if (catInp) catInp.value = val;
        if (estInp) estInp.value = hours;

        const iconEl = document.getElementById('catTriggerIcon');
        const titleEl = document.getElementById('catTriggerTitle');
        const subEl = document.getElementById('catTriggerSubtitle');
        if (iconEl) iconEl.innerHTML = icon;
        if (titleEl) titleEl.innerText = title;
        if (subEl) subEl.innerText = subtitle;

        // อัปเดตไฮไลท์ในตัวเลือก
        document.querySelectorAll('.cat-option-item').forEach(item => {
            const itemVal = item.getAttribute('data-val');
            if (itemVal === val) {
                item.className = 'cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition bg-blue-50/70 border border-blue-200/80';
            } else {
                item.className = 'cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-slate-50 border border-transparent hover:border-slate-200';
            }
        });

        // ปิดเมนู
        const menu = document.getElementById('categoryDropdownMenu');
        const chevron = document.getElementById('catTriggerChevron');
        if (menu) menu.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');

        if (window.lucide) lucide.createIcons();
    }



    // ปิด Category Dropdown เมื่อคลิกข้างนอก

    document.addEventListener('click', function(e) {

        const dd = document.getElementById('customCategoryDropdown');

        const menu = document.getElementById('categoryDropdownMenu');

        const chevron = document.getElementById('catTriggerChevron');

        if (dd && menu && !dd.contains(e.target)) {

            menu.classList.add('hidden');

            if (chevron) chevron.classList.remove('rotate-180');

        }

    });



    
    
    function handleDateChange() {
        updateTimeSlots();
        updateSummaryPreview();
    }
    function updateTimeSlots() {
        const startInput = document.getElementById("preferredStartTime");
        const endInput = document.getElementById("preferredEndTime");
        const hintText = document.getElementById("time-restriction-text");
        const dateInputEl = document.getElementById("preferredDateInput");

        // คำนวณวันและเวลาปัจจุบัน
        const now = new Date();
        const pad = n => String(n).padStart(2, "0");
        const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        const currentTimeStr = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        const selectedDate = dateInputEl ? dateInputEl.value : "";
        const isToday = (selectedDate === todayStr);

        // ตรวจสอบช่วงเช้า (ถ้าเป็นวันนี้และเวลา >= 12:00 ช่วงเช้าจะถูกปิดและสลับไปบ่ายโดยอัตโนมัติ)
        const morningRadio = document.querySelector('input[name="preferred_period"][value="morning"]');
        const afternoonRadio = document.querySelector('input[name="preferred_period"][value="afternoon"]');
        const morningLabel = morningRadio ? morningRadio.closest("label") : null;

        if (isToday && currentTimeStr >= "12:00") {
            if (morningRadio) {
                morningRadio.disabled = true;
                if (morningLabel) {
                    morningLabel.classList.add("opacity-40", "cursor-not-allowed", "pointer-events-none");
                    morningLabel.setAttribute("title", "ช่วงเช้าของวันนี้ผ่านไปแล้ว");
                }
                if (morningRadio.checked && afternoonRadio) {
                    afternoonRadio.checked = true;
                }
            }
        } else {
            if (morningRadio) {
                morningRadio.disabled = false;
                if (morningLabel) {
                    morningLabel.classList.remove("opacity-40", "cursor-not-allowed", "pointer-events-none");
                    morningLabel.removeAttribute("title");
                }
            }
        }

        const period = document.querySelector('input[name="preferred_period"]:checked')?.value || "morning";

        // เมื่อสลับช่วงเวลา ปรับคำแนะนำและตั้งค่าเริ่มต้นถ้าค่าเดิมอยู่นอกช่วง
        if (period === "morning") {
            if (hintText) {
                hintText.textContent = "ช่วงเช้า: กรุณาระบุเวลาในช่วงเช้า (ก่อน 12:00 น.)";
            }
            if (startInput && startInput.value >= "12:00") {
                startInput.value = "09:00";
            }
            if (endInput && endInput.value >= "12:00") {
                endInput.value = "11:30";
            }
        } else {
            if (hintText) {
                hintText.textContent = "ช่วงบ่าย - เย็น: กรุณาระบุเวลาในช่วงบ่ายหรือเย็น (ตั้งแต่ 12:00 น. เป็นต้นไป)";
            }
            if (startInput && startInput.value < "12:00") {
                startInput.value = "13:00";
            }
            if (endInput && endInput.value < "12:00") {
                endInput.value = "16:00";
            }
        }

        syncPreferredTime();
    }

    // ฟังก์ชันซิงค์เวลาเมื่อมีการเปลี่ยนค่า โดยไม่ขัดจังหวะด้วย alert
    function handleTimeRangeChange() {
        syncPreferredTime();
    }

    // ซิงค์ค่าเวลาลง hiddenPreferredTime เพื่อส่งฟอร์ม
    function syncPreferredTime() {
        const period = document.querySelector('input[name="preferred_period"]:checked')?.value || "morning";
        const startInput = document.getElementById("preferredStartTime");
        const endInput = document.getElementById("preferredEndTime");
        const hiddenPreferredTime = document.getElementById("preferredTime");
        const sVal = startInput ? startInput.value : "";
        const eVal = endInput ? endInput.value : "";
        
        if (hiddenPreferredTime) {
            if (sVal && eVal) {
                const periodText = (period === "morning") ? "ช่วงเช้า" : "ช่วงบ่าย-เย็น";
                hiddenPreferredTime.value = `${periodText} (เวลา ${sVal} - ${eVal} น.)`;
            } else if (sVal) {
                const periodText = (period === "morning") ? "ช่วงเช้า" : "ช่วงบ่าย-เย็น";
                hiddenPreferredTime.value = `${periodText} (เวลา ${sVal} น.)`;
            } else {
                hiddenPreferredTime.value = "";
            }
        }
        updateSummaryPreview();
        if (window.lucide) lucide.createIcons();
    }

    // ปรับปรุงกล่องสรุปผลเรียลไทม์
    function updateSummaryPreview() {
        const dateInputEl = document.getElementById("preferredDateInput");
        const dateInput = dateInputEl ? dateInputEl.value : "";
        const hiddenPreferredTime = document.getElementById("preferredTime")?.value || "";
        const startInput = document.getElementById("preferredStartTime");
        const endInput = document.getElementById("preferredEndTime");
        const sVal = startInput ? startInput.value : "";
        const eVal = endInput ? endInput.value : "";
        const hours = document.getElementById("estimatedHoursInput")?.value || "2";
        const previewDateEl = document.getElementById("preview-date");
        const previewTimeEl = document.getElementById("preview-time");

        const now = new Date();
        const pad = n => String(n).padStart(2, "0");
        const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        const currentTimeStr = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        const isToday = (dateInput === todayStr);
        const isPastToday = isToday && (sVal && sVal <= currentTimeStr);

        if (dateInput) {
            const d = new Date(dateInput);
            const day = String(d.getDate()).padStart(2, "0");
            const month = String(d.getMonth() + 1).padStart(2, "0");
            const year = d.getFullYear() + 543;
            previewDateEl.innerHTML = `<span class="text-slate-800 font-bold">${day}/${month}/${year}</span>`;
            dateInputEl.classList.remove("border-rose-400", "ring-2", "ring-rose-200");
        } else {
            previewDateEl.innerHTML = '<span class="text-rose-600 font-bold animate-pulse">⚠️ กรุณาระบุวันที่ (จำเป็นต้องเลือก)</span>';
        }

        if (previewTimeEl) {
            if (sVal && eVal && eVal <= sVal) {
                previewTimeEl.innerHTML = '<span class="text-amber-600 font-bold">⚠️ เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น</span>';
            } else if (isPastToday) {
                previewTimeEl.innerHTML = `<span class="text-rose-600 font-bold">⚠️ ${hiddenPreferredTime} (เวลาเริ่มต้นผ่านไปแล้ว)</span>`;
            } else if (hiddenPreferredTime) {
                previewTimeEl.innerHTML = `<span class="text-rose-700 font-bold">${hiddenPreferredTime}</span>`;
            } else {
                previewTimeEl.innerHTML = '<span class="text-rose-600 font-bold">⚠️ กรุณาระบุเวลาที่สะดวก</span>';
            }
        }
    }

    // ตรวจสอบความถูกต้องเฉพาะเมื่อ Submit ฟอร์มเท่านั้น
    document.addEventListener("DOMContentLoaded", function() {
        const form = document.querySelector("form");
        if (form) {
            form.addEventListener("submit", function(e) {
                const dateInput = document.getElementById("preferredDateInput");
                const hiddenPreferredTime = document.getElementById("preferredTime");
                const startInput = document.getElementById("preferredStartTime");
                const endInput = document.getElementById("preferredEndTime");
                const period = document.querySelector('input[name="preferred_period"]:checked')?.value || "morning";

                if (!dateInput.value) {
                    e.preventDefault();
                    dateInput.focus();
                    dateInput.classList.add("border-rose-500", "ring-2", "ring-rose-200");
                    dateInput.scrollIntoView({ behavior: "smooth", block: "center" });
                    alert("กรุณาระบุวันและช่วงเวลาที่สะดวกให้ช่างเข้าทำงาน (จำเป็นต้องเลือก)");
                    return;
                }

                if (!startInput || !startInput.value || !endInput || !endInput.value) {
                    e.preventDefault();
                    alert("กรุณาระบุเวลาที่สะดวกให้ครบทั้งเวลาเริ่มต้นและเวลาสิ้นสุด");
                    if (startInput && !startInput.value) startInput.focus();
                    else if (endInput) endInput.focus();
                    return;
                }

                const sVal = startInput.value;
                const eVal = endInput.value;

                if (eVal <= sVal) {
                    e.preventDefault();
                    alert("เวลาสิ้นสุดต้องมากกว่าเวลาเริ่มต้น (ตัวอย่างเช่น 09:00 - 11:30 น.)");
                    endInput.focus();
                    return;
                }

                if (period === "morning" && (sVal >= "12:00" || eVal >= "12:00")) {
                    e.preventDefault();
                    alert("คุณเลือกช่วงเช้า กรุณาระบุเวลาในช่วงเช้า (ก่อน 12:00 น.)");
                    startInput.focus();
                    return;
                }

                if (period === "afternoon" && (sVal < "12:00" || eVal < "12:00")) {
                    e.preventDefault();
                    alert("คุณเลือกช่วงบ่าย - เย็น กรุณาระบุเวลาในช่วงบ่ายหรือเย็น (ตั้งแต่ 12:00 น. เป็นต้นไป)");
                    startInput.focus();
                    return;
                }

                const now = new Date();
                const pad = n => String(n).padStart(2, "0");
                const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
                const currentTimeStr = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
                if (dateInput.value === todayStr && sVal <= currentTimeStr) {
                    e.preventDefault();
                    alert("เวลาเริ่มต้นที่คุณเลือกได้ผ่านไปแล้วสำหรับวันนี้ กรุณาระบุเวลาหลังจากนี้ หรือเลื่อนวันนัดหมาย");
                    startInput.focus();
                    return;
                }
            });
        }
    });
  </script>

</body>

</html>



