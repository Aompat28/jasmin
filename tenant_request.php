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

        $estimatedHours = isset($_POST['estimated_hours']) ? max(1, min(4, intval($_POST['estimated_hours']))) : 2;

        $allowEntry = !empty($_POST['allowEntry']) ? 1 : 0;

        $requestImage = null;

        // ตรวจสอบไม่ให้เลือกเวลาที่ผ่านมาแล้ว
        $todayStr = date('Y-m-d');
        $currentTimeStr = date('H:i');
        $isPastTime = false;

        if ($preferredDate < $todayStr) {
            $isPastTime = true;
        } elseif ($preferredDate === $todayStr && $preferredTime) {
            if (preg_match('/(\d{1,2}:\d{2})/', $preferredTime, $timeMatch)) {
                $timeParts = explode(':', $timeMatch[1]);
                $startTime = sprintf('%02d:%02d', intval($timeParts[0]), intval($timeParts[1]));
                if ($startTime <= $currentTimeStr) {
                    $isPastTime = true;
                }
            }
        }

        if ($isPastTime) {
            $message = "<div class='alert-box p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-2xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5 shrink-0'></i> ไม่สามารถเลือกเวลาที่ผ่านมาแล้วได้ กรุณาเลือกเวลาปัจจุบันหรือเวลาหลังจากนี้เท่านั้น</div>";
        } else {

        // ตรวจสอบการอัปโหลดรูปภาพปัญหา (ถ้ามี)



        if (isset($_FILES['request_image']) && $_FILES['request_image']['error'] == 0) {



            $uploadDir = 'uploads/';



            if (!is_dir($uploadDir)) { 



                mkdir($uploadDir, 0777, true); 



            }



            $ext = strtolower(pathinfo($_FILES['request_image']['name'], PATHINFO_EXTENSION));



            $fileName = time() . '_req_' . uniqid() . '.' . $ext;



            $targetFilePath = $uploadDir . $fileName;



            



            if (move_uploaded_file($_FILES['request_image']['tmp_name'], $targetFilePath)) {



                $requestImage = $targetFilePath;



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



                            เวลานัดหมาย: <strong class='text-emerald-900'>{$prefDisplay}</strong> ({$timeDisplay}) • กำหนดเวลา: <strong class='text-emerald-900'>{$estimatedHours} ชั่วโมง</strong>



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
            <div class="flex items-center space-x-2">
              <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
              <a href="profile.php" class="inline-flex items-center gap-1.5 text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold px-2.5 py-0.5 rounded-full border border-rose-200 transition shadow-2xs" title="โปรไฟล์ของฉัน">
                <span><?php echo htmlspecialchars($headerTenantName ?? $sidebarTenantName ?? ($tenantName ?? 'ผู้เช่า')); ?></span>
                <?php if(!empty($headerRoomNumber ?? $sidebarRoomNumber ?? ($myRoomNumber ?? ''))): ?>
                  <span class="text-rose-400">•</span>
                  <span>ห้อง <?php echo htmlspecialchars($headerRoomNumber ?? $sidebarRoomNumber ?? ($myRoomNumber ?? '')); ?></span>
                <?php endif; ?>
              </a>
            </div>
            <p class="text-[11px] text-slate-400">ระบบแจ้งซ่อมและนัดหมายเวลาปฏิบัติงาน</p>
          </div>



        </div>



        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>



           <a href="tenant_request_history.php" class="px-3.5 py-1.5 rounded-xl border border-rose-100/80 text-slate-700 hover:bg-slate-100 text-xs sm:text-sm font-semibold transition flex items-center gap-1.5">



              <i data-lucide="history" class="w-4 h-4 text-rose-500"></i> <span class="hidden sm:inline">ประวัติการแจ้งซ่อม</span><span class="sm:hidden">ประวัติ</span>



           </a>



           <a href="tenant_dashboard.php" class="px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs sm:text-sm font-semibold transition flex items-center gap-1.5">



              <i data-lucide="arrow-left" class="w-4 h-4"></i> <span class="hidden sm:inline">กลับหน้าหลัก</span><span class="sm:hidden">กลับ</span>



           </a>



           <div class="w-px h-6 bg-slate-200"></div>



           <!-- ลิงก์โปรไฟล์ของผู้เช่า (ตรงชื่อ) -->



           <a href="profile.php" class="flex items-center gap-2 p-1 sm:p-1.5 hover:bg-rose-50/70 rounded-xl transition border border-transparent hover:border-rose-200 group" title="โปรไฟล์ของฉัน">



            <div class="w-8 h-8 rounded-full bg-rose-100 text-rose-700 flex items-center justify-center font-bold text-xs border border-rose-200 shadow-2xs shrink-0">



              <?php echo $initials; ?>



            </div>



            <div class="hidden sm:block text-left pr-0.5">



              <div class="text-xs font-bold text-slate-800 group-hover:text-rose-600 transition truncate max-w-[120px] sm:max-w-[160px]"><?php echo htmlspecialchars($tenantName); ?></div>



              <div class="text-[10px] text-rose-600 font-semibold truncate flex items-center gap-1">



                <?php if(!empty($roomNumber)): ?>



                  <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> ห้อง <?php echo htmlspecialchars($roomNumber); ?>



                <?php else: ?>



                  <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> ผู้เช่า



                <?php endif; ?>



              </div>



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

            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">

              <i data-lucide="clock" class="w-3.5 h-3.5 text-amber-500"></i>

              <span>บริการรวดเร็วทันใจ</span>

            </span>

          </div>

          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">

            แจ้งซ่อมและขอรับบริการ

          </h1>

          <p class="text-xs sm:text-sm text-slate-500 mt-1">

            ส่งเรื่องให้นิติบุคคล พร้อมระบุหมวดหมู่งานและช่วงเวลาที่คุณสะดวกให้ช่างเข้าดำเนินการ

          </p>

        </div>



        <div class="flex flex-wrap items-center gap-3 shrink-0">

          <a href="tenant_request_history.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">

            <i data-lucide="history" class="w-4 h-4 text-slate-500"></i>

            <span>ประวัติการแจ้งซ่อม</span>

          </a>

        </div>

      </div>

    </div>



        <form action="" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 space-y-6">



            



            <!-- 1. ประเภทงาน / ปัญหาและกำหนดเวลาปฏิบัติงาน -->



            <div>



                <label class="block text-sm font-bold text-slate-700 mb-1.5 flex items-center justify-between">



                    <span class="flex items-center gap-1.5">



                        <i data-lucide="wrench" class="w-4 h-4 text-amber-500"></i>



                        <span>ประเภทงาน / ปัญหาที่พบ (พร้อมกำหนดเวลาทำงาน)</span>



                        <span class="text-rose-500">*</span>



                    </span>



                    <span class="text-[11px] text-slate-400 font-normal">จำกัดเวลาตามขนาดงาน 1-4 ชม.</span>



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

                                <div id="catTriggerSubtitle" class="text-[11px] text-blue-600 font-semibold truncate">งานปานกลาง / ทั่วไป (จำกัดเวลา 2 ชั่วโมง)</div>

                            </div>

                        </div>

                        <div class="flex items-center gap-2 shrink-0">

                            <span id="catTriggerBadge" class="text-[10px] px-2.5 py-0.5 rounded-full font-bold bg-blue-50 text-blue-700 border border-blue-200">2 ชม.</span>

                            <i data-lucide="chevron-down" id="catTriggerChevron" class="w-4 h-4 text-rose-500 transition-transform duration-200"></i>

                        </div>

                    </button>



                    <!-- Floating Menu Panel -->

                    <div id="categoryDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-2 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-3xl shadow-xl shadow-rose-500/10 p-3 space-y-3 max-h-80 overflow-y-auto scrollbar-thin transition-all duration-200">

                        

                        <!-- 🟢 งานขนาดเล็ก (1 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span> งานขนาดเล็ก (จำกัดเวลา 1 ชั่วโมง)

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('ไฟฟ้า/หลอดไฟ', 1, '💡', 'ไฟฟ้า / หลอดไฟ / ปลั๊กไฟ', 'งานขนาดเล็ก (จำกัดเวลา 1 ชั่วโมง)', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-emerald-50/70 border border-transparent hover:border-emerald-200/60"

                                     data-val="ไฟฟ้า/หลอดไฟ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">💡</span>

                                        <span class="text-xs font-bold text-slate-800">ไฟฟ้า / หลอดไฟ / ปลั๊กไฟ</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">1 ชม.</span>

                                </div>

                                <div onclick="selectCategoryOption('ลูกบิดประตู/ก๊อกน้ำ', 1, '🚪', 'ลูกบิดประตู / ก๊อกน้ำ / ขันน็อต', 'งานขนาดเล็ก (จำกัดเวลา 1 ชั่วโมง)', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-emerald-50/70 border border-transparent hover:border-emerald-200/60"

                                     data-val="ลูกบิดประตู/ก๊อกน้ำ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🚪</span>

                                        <span class="text-xs font-bold text-slate-800">ลูกบิดประตู / ก๊อกน้ำ / ขันน็อต</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">1 ชม.</span>

                                </div>

                                <div onclick="selectCategoryOption('เก็บขยะ/สิ่งปฏิกูล', 1, '🗑️', 'เก็บขยะ / สิ่งปฏิกูล', 'งานขนาดเล็ก (จำกัดเวลา 1 ชั่วโมง)', 'bg-emerald-50 text-emerald-700 border-emerald-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-emerald-50/70 border border-transparent hover:border-emerald-200/60"

                                     data-val="เก็บขยะ/สิ่งปฏิกูล">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🗑️</span>

                                        <span class="text-xs font-bold text-slate-800">เก็บขยะ / สิ่งปฏิกูล</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">1 ชม.</span>

                                </div>

                            </div>

                        </div>



                        <!-- 🔵 งานปานกลาง / งานทั่วไป (2 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-blue-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-blue-500"></span> งานปานกลาง / งานทั่วไป (จำกัดเวลา 2 ชั่วโมง)

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('ประปา/ห้องน้ำ', 2, '🚿', 'ประปา / ท่อน้ำรั่วซึม / สุขภัณฑ์ / ชักโครก', 'งานปานกลาง / ทั่วไป (จำกัดเวลา 2 ชั่วโมง)', 'bg-blue-50 text-blue-700 border-blue-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition bg-blue-50/70 border border-blue-200/80"

                                     data-val="ประปา/ห้องน้ำ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🚿</span>

                                        <span class="text-xs font-bold text-slate-800">ประปา / ท่อน้ำรั่วซึม / สุขภัณฑ์ / ชักโครก</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-blue-50 text-blue-700 border border-blue-200">2 ชม.</span>

                                </div>

                                <div onclick="selectCategoryOption('เฟอร์นิเจอร์/ประตู/หน้าต่าง', 2, '🪑', 'เฟอร์นิเจอร์ / ประตู / หน้าต่าง / บานพับ', 'งานปานกลาง / ทั่วไป (จำกัดเวลา 2 ชั่วโมง)', 'bg-blue-50 text-blue-700 border-blue-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-blue-50/70 border border-transparent hover:border-blue-200/60"

                                     data-val="เฟอร์นิเจอร์/ประตู/หน้าต่าง">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🪑</span>

                                        <span class="text-xs font-bold text-slate-800">เฟอร์นิเจอร์ / ประตู / หน้าต่าง / บานพับ</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-blue-50 text-blue-700 border border-blue-200">2 ชม.</span>

                                </div>

                                <div onclick="selectCategoryOption('ทำความสะอาดห้องพัก', 2, '🧹', 'ทำความสะอาดห้องพัก', 'งานปานกลาง / ทั่วไป (จำกัดเวลา 2 ชั่วโมง)', 'bg-blue-50 text-blue-700 border-blue-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-blue-50/70 border border-transparent hover:border-blue-200/60"

                                     data-val="ทำความสะอาดห้องพัก">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🧹</span>

                                        <span class="text-xs font-bold text-slate-800">ทำความสะอาดห้องพัก</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-blue-50 text-blue-700 border border-blue-200">2 ชม.</span>

                                </div>

                            </div>

                        </div>



                        <!-- 🟣 งานขนาดใหญ่ (3 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-purple-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-purple-500"></span> งานขนาดใหญ่ (จำกัดเวลา 3 ชั่วโมง)

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('ระบบไฟหลัก/ตู้เบรกเกอร์', 3, '⚡', 'ซ่อมระบบไฟหลัก / ตู้เบรกเกอร์', 'งานขนาดใหญ่ (จำกัดเวลา 3 ชั่วโมง)', 'bg-purple-50 text-purple-700 border-purple-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-purple-50/70 border border-transparent hover:border-purple-200/60"

                                     data-val="ระบบไฟหลัก/ตู้เบรกเกอร์">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">⚡</span>

                                        <span class="text-xs font-bold text-slate-800">ซ่อมระบบไฟหลัก / ตู้เบรกเกอร์</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-purple-50 text-purple-700 border border-purple-200">3 ชม.</span>

                                </div>

                            </div>

                        </div>



                        <!-- 🔴 งานซ่อมหนัก (4 ชั่วโมง) -->

                        <div>

                            <div class="text-[10px] font-bold text-rose-700 uppercase tracking-wider px-2 mb-1 flex items-center gap-1.5">

                                <span class="w-2 h-2 rounded-full bg-rose-500"></span> งานซ่อมหนัก (จำกัดเวลา 4 ชั่วโมง)

                            </div>

                            <div class="space-y-1">

                                <div onclick="selectCategoryOption('รื้อท่อประปา/ฝ้าเพดาน/ยาแนว', 4, '🛠️', 'รื้อซ่อมท่อประปา / ซ่อมฝ้าเพดาน / ยาแนวห้องน้ำ', 'งานซ่อมหนัก (จำกัดเวลา 4 ชั่วโมง)', 'bg-rose-50 text-rose-700 border-rose-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-rose-50/70 border border-transparent hover:border-rose-200/60"

                                     data-val="รื้อท่อประปา/ฝ้าเพดาน/ยาแนว">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">🛠️</span>

                                        <span class="text-xs font-bold text-slate-800">รื้อซ่อมท่อประปา / ซ่อมฝ้าเพดาน / ยาแนว</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-rose-50 text-rose-700 border border-rose-200">4 ชม.</span>

                                </div>

                                <div onclick="selectCategoryOption('อื่นๆ', 2, '📦', 'อื่นๆ (ระบุในรายละเอียดปัญหา)', 'งานทั่วไป (กำหนดเวลา 2 ชั่วโมง)', 'bg-slate-100 text-slate-700 border-slate-200', event)"

                                     class="cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-slate-50 border border-transparent hover:border-slate-200"

                                     data-val="อื่นๆ">

                                    <div class="flex items-center gap-2.5">

                                        <span class="text-base">📦</span>

                                        <span class="text-xs font-bold text-slate-800">อื่นๆ (ระบุในรายละเอียดปัญหา)</span>

                                    </div>

                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-bold bg-slate-100 text-slate-700 border border-slate-200">2 ชม.</span>

                                </div>

                            </div>

                        </div>



                    </div>

                </div>



                <!-- ป้ายแสดงขนาดงานและกำหนดเวลาที่เลือกแบบ Realtime -->



                <div id="category-duration-badge" class="mt-2.5 flex items-center justify-between p-3 rounded-2xl bg-amber-50/90 border border-amber-200/90 text-xs">



                    <div class="flex items-center gap-2">



                        <div class="w-7 h-7 rounded-lg bg-amber-500 text-white flex items-center justify-center shrink-0">



                            <i data-lucide="timer" class="w-4 h-4"></i>



                        </div>



                        <div>



                            <span class="text-slate-500 text-[11px] block">ขนาดของงานและกำหนดเวลาปฏิบัติงาน:</span>



                            <span id="category-duration-text" class="font-bold text-slate-900 text-xs sm:text-sm">งานปานกลาง (กำหนดเวลา 2 ชั่วโมง)</span>



                        </div>



                    </div>



                    <span id="category-hours-pill" class="px-2.5 py-1 bg-white text-amber-800 font-bold font-mono rounded-xl border border-amber-200 text-xs shadow-2xs">



                        ⏱️ 2 ชม.



                    </span>



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



            <!-- 🌟 5. วันและช่วงเวลาที่สะดวก<!-- ======================================================== -->



            <!-- 🌟 5. วันและช่วงเวลาที่สะดวก (แบ่งช่วงเช้า - เย็น) 🌟       -->



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



                    <input type="date" name="preferredDate" id="preferredDateInput" required min="<?php echo (date('H:i') >= '16:00') ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'); ?>" onchange="handleDateChange()" class="w-full px-4 py-2.5 text-sm bg-white text-slate-800 border border-stone-200 rounded-xl outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-200/50 transition cursor-pointer shadow-2xs">



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



                                    <div class="text-xs font-bold text-slate-800">☀️ ช่วงเช้า</div>



                                    <div class="text-[11px] text-slate-500 font-mono">09:00 - 12:00 น.</div>



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



                                    <div class="text-xs font-bold text-slate-800">⛅ ช่วงบ่าย - เย็น</div>



                                    <div class="text-[11px] text-slate-500 font-mono">13:00 - 17:00 น.</div>



                                </div>



                            </div>



                        </label>



                    </div>



                </div>



                <!-- 5.3 สล็อตเวลาเจาะจง (Dynamic ตามชั่วโมง 1-4 ชม. และช่วงเช้า/เย็น) -->



                <div>



                    <div class="flex items-center justify-between mb-2">



                        <label class="block text-xs font-bold text-slate-700">



                            เวลาเข้าทำงานที่เจาะจง <span class="text-rose-500 font-bold">* (จำเป็นต้องเลือก)</span> <span class="text-slate-400 font-normal">(คำนวณตามกำหนดเวลา <span id="current-hours-label" class="font-bold text-amber-600 font-mono">2 ชม.</span>)</span>



                        </label>



                    </div>



                    <!-- Hidden input to submit with the form -->



                    <input type="hidden" name="preferredTime" id="preferredTime" value="ช่วงเช้า: 09:00 - 11:00 น.">



                    <!-- Time Slots Buttons Grid -->



                    <div id="time-slots-container" class="grid grid-cols-1 sm:grid-cols-2 gap-2">



                        <!-- Dynamic via JavaScript -->



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



                                <span id="preview-time" class="text-rose-700">ช่วงเช้า: 09:00 - 11:00 น.</span>



                            </span>



                        </div>



                    </div>



                    <div class="self-end sm:self-center">



                        <span class="inline-flex items-center gap-1 bg-white text-rose-700 font-bold px-2.5 py-1 rounded-xl border border-rose-200 font-mono text-[11px] shadow-2xs">



                            <i data-lucide="clock" class="w-3 h-3 text-blue-500"></i>



                            <span id="preview-hours">2</span> ชั่วโมง



                        </span>



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



    <!-- ลิงก์ประวัติการแจ้งซ่อมที่ด้านล่าง -->



    <div class="mt-6 text-center">



        <a href="tenant_request_history.php" class="text-xs text-slate-500 hover:text-rose-600 font-semibold inline-flex items-center gap-1.5 transition">



            <i data-lucide="history" class="w-4 h-4"></i> ต้องการดูประวัติและติดตามสถานะงานซ่อมที่ส่งไปแล้ว? คลิกที่นี่



        </a>



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



        document.getElementById('categoryInput').value = val;

        document.getElementById('estimatedHoursInput').value = hours;



        document.getElementById('catTriggerIcon').innerHTML = icon;

        document.getElementById('catTriggerTitle').innerText = title;

        document.getElementById('catTriggerSubtitle').innerText = subtitle;

        

        const badge = document.getElementById('catTriggerBadge');

        if (badge) {

            badge.innerText = hours + ' ชม.';

            badge.className = 'text-[10px] px-2.5 py-0.5 rounded-full font-bold border ' + badgeClass;

        }



        // อัปเดตไฮไลท์ในตัวเลือก

        document.querySelectorAll('.cat-option-item').forEach(item => {

            const itemVal = item.getAttribute('data-val');

            if (itemVal === val) {

                item.className = 'cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition bg-blue-50/70 border border-blue-200/80';

            } else {

                item.className = 'cat-option-item rounded-xl p-2.5 flex items-center justify-between cursor-pointer transition hover:bg-slate-50 border border-transparent hover:border-slate-200';

            }

        });



        // อัปเดตป้ายเตือนขนาดงาน

        const durationBadge = document.getElementById('category-duration-badge');

        const durationText = document.getElementById('category-duration-text');

        const hoursPill = document.getElementById('category-hours-pill');

        if (durationBadge) durationBadge.classList.remove('hidden');

        if (durationText) durationText.innerText = subtitle;

        if (hoursPill) hoursPill.innerText = hours + ' ชม.';



        // ปิดเมนู

        const menu = document.getElementById('categoryDropdownMenu');

        const chevron = document.getElementById('catTriggerChevron');

        if (menu) menu.classList.add('hidden');

        if (chevron) chevron.classList.remove('rotate-180');



        if (typeof calculateSuggestedSlots === 'function') {

            calculateSuggestedSlots();

        }

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



    function handleCategoryChange(selectEl) {



        if (!selectEl) return;



        const selectedOpt = selectEl.options[selectEl.selectedIndex];



        const hours = parseInt(selectedOpt.getAttribute('data-hours') || '2');



        const badge = document.getElementById('category-duration-badge');



        const badgeText = document.getElementById('category-duration-text');



        const hoursPill = document.getElementById('category-hours-pill');



        const hiddenEstHours = document.getElementById('estimatedHoursInput');



        



        if (selectEl.value) {



            hiddenEstHours.value = hours;



            



            let desc = 'งานขนาดเล็ก (กำหนดเวลา 1 ชั่วโมง)';



            if (hours === 2) desc = 'งานปานกลาง / ทั่วไป (กำหนดเวลา 2 ชั่วโมง)';



            else if (hours === 3) desc = 'งานขนาดใหญ่ (กำหนดเวลา 3 ชั่วโมง)';



            else if (hours === 4) desc = 'งานซ่อมหนัก (กำหนดเวลา 4 ชั่วโมง)';



            



            badgeText.textContent = desc;



            hoursPill.textContent = '⏱️ ' + hours + ' ชม.';



            badge.classList.remove('hidden');



            



            const currentHoursLabel = document.getElementById('current-hours-label');



            if (currentHoursLabel) currentHoursLabel.textContent = hours + ' ชม.';



            const previewHours = document.getElementById('preview-hours');



            if (previewHours) previewHours.textContent = hours;



            



            updateTimeSlots();



        } else {



            badge.classList.add('hidden');



        }



    }



    // Dynamic Time Slot Generator (ช่วงเช้า 09:00 - 12:00 / ช่วงบ่าย-เย็น 13:00 - 17:00)



    function handleDateChange() {
        updateTimeSlots();
        updateSummaryPreview();
    }

    function updateTimeSlots() {
        const hours = parseInt(document.getElementById('estimatedHoursInput')?.value || '2');
        const container = document.getElementById('time-slots-container');
        const hiddenPreferredTime = document.getElementById('preferredTime');
        const dateInputEl = document.getElementById('preferredDateInput');

        // คำนวณวันและเวลาปัจจุบัน
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        const todayStr = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
        const currentTimeStr = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
        const selectedDate = dateInputEl ? dateInputEl.value : '';
        const isToday = (!selectedDate || selectedDate === todayStr);

        // ตรวจสอบช่วงเช้า (ถ้าเป็นวันนี้และเวลา >= 12:00 ช่วงเช้าจะถูกปิดและสลับไปบ่ายโดยอัตโนมัติ)
        const morningRadio = document.querySelector('input[name="preferred_period"][value="morning"]');
        const afternoonRadio = document.querySelector('input[name="preferred_period"][value="afternoon"]');
        const morningLabel = morningRadio ? morningRadio.closest('label') : null;

        if (isToday && currentTimeStr >= '12:00') {
            if (morningRadio) {
                morningRadio.disabled = true;
                if (morningLabel) {
                    morningLabel.classList.add('opacity-40', 'cursor-not-allowed', 'pointer-events-none');
                }
                if (morningRadio.checked && afternoonRadio) {
                    afternoonRadio.checked = true;
                }
            }
        } else {
            if (morningRadio) {
                morningRadio.disabled = false;
                if (morningLabel) {
                    morningLabel.classList.remove('opacity-40', 'cursor-not-allowed', 'pointer-events-none');
                }
            }
        }

        const period = document.querySelector('input[name="preferred_period"]:checked')?.value || 'morning';

        container.innerHTML = '';
        let slots = [];

        if (period === 'morning') {
            if (hours === 1) {
                slots = [
                    { val: 'ช่วงเช้า: 09:00 - 10:00 น.', label: '09:00 - 10:00 น.', desc: 'เช้าตรู่ (1 ชม.)', start: '09:00' },
                    { val: 'ช่วงเช้า: 10:00 - 11:00 น.', label: '10:00 - 11:00 น.', desc: 'สายๆ (1 ชม.)', start: '10:00' },
                    { val: 'ช่วงเช้า: 11:00 - 12:00 น.', label: '11:00 - 12:00 น.', desc: 'ก่อนเที่ยง (1 ชม.)', start: '11:00' },
                    { val: 'ช่วงเช้า (09:00 - 12:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงเช้า', desc: '09:00 - 12:00 น.', start: '09:00' }
                ];
            } else if (hours === 2) {
                slots = [
                    { val: 'ช่วงเช้า: 09:00 - 11:00 น.', label: '09:00 - 11:00 น.', desc: 'เช้าถึงสาย (2 ชม.)', start: '09:00' },
                    { val: 'ช่วงเช้า: 10:00 - 12:00 น.', label: '10:00 - 12:00 น.', desc: 'สายถึงเที่ยง (2 ชม.)', start: '10:00' },
                    { val: 'ช่วงเช้า (09:00 - 12:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงเช้า', desc: '09:00 - 12:00 น.', start: '09:00' }
                ];
            } else if (hours === 3) {
                slots = [
                    { val: 'ช่วงเช้า: 09:00 - 12:00 น. (เต็มช่วงเช้า)', label: '09:00 - 12:00 น.', desc: 'เต็มช่วงเช้า (3 ชม.)', start: '09:00' },
                    { val: 'ช่วงเช้า (09:00 - 12:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงเช้า', desc: 'ตามการจัดคิว', start: '09:00' }
                ];
            } else if (hours === 4) {
                slots = [
                    { val: 'ช่วงเช้า-บ่าย: 09:00 - 13:00 น. (4 ชม.)', label: '09:00 - 13:00 น.', desc: 'เช้าถึงบ่ายต้น (4 ชม.)', start: '09:00' },
                    { val: 'ช่วงเช้า (เริ่ม 09:00 น.)', label: 'เริ่มเช้า 09:00 น.', desc: 'ทำต่อเนื่องจนเสร็จ', start: '09:00' }
                ];
            }
        } else {
            if (hours === 1) {
                slots = [
                    { val: 'ช่วงบ่าย-เย็น: 13:00 - 14:00 น.', label: '13:00 - 14:00 น.', desc: 'บ่ายต้น (1 ชม.)', start: '13:00' },
                    { val: 'ช่วงบ่าย-เย็น: 14:00 - 15:00 น.', label: '14:00 - 15:00 น.', desc: 'บ่ายสอง (1 ชม.)', start: '14:00' },
                    { val: 'ช่วงบ่าย-เย็น: 15:00 - 16:00 น.', label: '15:00 - 16:00 น.', desc: 'บ่ายสาม (1 ชม.)', start: '15:00' },
                    { val: 'ช่วงบ่าย-เย็น: 16:00 - 17:00 น.', label: '16:00 - 17:00 น.', desc: 'ช่วงเย็น (1 ชม.)', start: '16:00' },
                    { val: 'ช่วงบ่าย-เย็น (13:00 - 17:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงบ่าย-เย็น', desc: '13:00 - 17:00 น.', start: '13:00' }
                ];
            } else if (hours === 2) {
                slots = [
                    { val: 'ช่วงบ่าย-เย็น: 13:00 - 15:00 น.', label: '13:00 - 15:00 น.', desc: 'บ่ายต้น (2 ชม.)', start: '13:00' },
                    { val: 'ช่วงบ่าย-เย็น: 14:00 - 16:00 น.', label: '14:00 - 16:00 น.', desc: 'บ่ายกลาง (2 ชม.)', start: '14:00' },
                    { val: 'ช่วงบ่าย-เย็น: 15:00 - 17:00 น.', label: '15:00 - 17:00 น.', desc: 'บ่ายถึงเย็น (2 ชม.)', start: '15:00' },
                    { val: 'ช่วงบ่าย-เย็น (13:00 - 17:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงบ่าย-เย็น', desc: '13:00 - 17:00 น.', start: '13:00' }
                ];
            } else if (hours === 3) {
                slots = [
                    { val: 'ช่วงบ่าย-เย็น: 13:00 - 16:00 น.', label: '13:00 - 16:00 น.', desc: 'บ่ายต้นถึงสี่โมง (3 ชม.)', start: '13:00' },
                    { val: 'ช่วงบ่าย-เย็น: 14:00 - 17:00 น.', label: '14:00 - 17:00 น.', desc: 'บ่ายสองถึงห้าโมง (3 ชม.)', start: '14:00' },
                    { val: 'ช่วงบ่าย-เย็น (13:00 - 17:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงบ่าย-เย็น', desc: '13:00 - 17:00 น.', start: '13:00' }
                ];
            } else if (hours === 4) {
                slots = [
                    { val: 'ช่วงบ่าย-เย็น: 13:00 - 17:00 น. (เต็มช่วงบ่าย-เย็น)', label: '13:00 - 17:00 น.', desc: 'เต็มช่วงบ่ายถึงเย็น (4 ชม.)', start: '13:00' },
                    { val: 'ช่วงบ่าย-เย็น (13:00 - 17:00 น. ตามคิวช่าง)', label: 'สะดวกตลอดช่วงบ่าย-เย็น', desc: 'ตามการจัดคิว', start: '13:00' }
                ];
            }
        }

        // ตรวจสอบ slot ที่ผ่านมาแล้ว
        let firstAvailableVal = null;
        let availableCount = 0;

        slots.forEach(s => {
            s.isPast = isToday && (s.start <= currentTimeStr);
            if (!s.isPast) {
                availableCount++;
                if (!firstAvailableVal) {
                    firstAvailableVal = s.val;
                }
            }
        });

        if (availableCount === 0) {
            container.innerHTML = `
                <div class="col-span-full p-4 bg-amber-50/90 border border-amber-200 rounded-2xl text-amber-800 text-xs flex items-center gap-2.5">
                    <i data-lucide="clock" class="w-5 h-5 shrink-0 text-amber-600"></i>
                    <div>
                        <div class="font-bold">ทุกช่วงเวลาของรอบนี้ผ่านไปแล้วสำหรับวันนี้</div>
                        <div class="text-[11px] text-amber-700/80 mt-0.5">กรุณาเลือกช่วงเวลาอื่น หรือเลื่อนวันนัดหมายเป็นวันถัดไป</div>
                    </div>
                </div>
            `;
            hiddenPreferredTime.value = '';
        } else {
            slots.forEach((s) => {
                const label = document.createElement('label');
                if (s.isPast) {
                    label.className = 'cursor-not-allowed opacity-40 select-none';
                    label.innerHTML = `
                        <input type="radio" name="slot_choice" value="${s.val}" disabled class="peer sr-only">
                        <div class="p-3 rounded-2xl border border-slate-200 bg-slate-50 text-slate-400 transition shadow-2xs">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-xs font-mono line-through text-slate-400">${s.label}</span>
                                <span class="text-[9px] font-bold text-rose-500 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-100">ผ่านไปแล้ว</span>
                            </div>
                            <div class="text-[10px] text-slate-400 mt-0.5">${s.desc}</div>
                        </div>
                    `;
                } else {
                    const isChecked = (s.val === firstAvailableVal);
                    label.className = 'cursor-pointer';
                    label.innerHTML = `
                        <input type="radio" name="slot_choice" value="${s.val}" ${isChecked ? 'checked' : ''} class="peer sr-only">
                        <div class="p-3 rounded-2xl border border-rose-100/80 bg-white hover:bg-rose-50/30 peer-checked:border-blue-600 peer-checked:bg-rose-500 peer-checked:text-white transition shadow-2xs">
                            <div class="font-bold text-xs font-mono">${s.label}</div>
                            <div class="text-[10px] text-slate-400 peer-checked:text-blue-100 mt-0.5">${s.desc}</div>
                        </div>
                    `;
                    label.querySelector('input').addEventListener('change', function() {
                        hiddenPreferredTime.value = this.value;
                        updateSummaryPreview();
                    });
                }
                container.appendChild(label);
            });

            hiddenPreferredTime.value = firstAvailableVal || '';
        }

        updateSummaryPreview();
        lucide.createIcons();
    }

    function updateSummaryPreview() {
        const dateInputEl = document.getElementById('preferredDateInput');
        const dateInput = dateInputEl ? dateInputEl.value : '';
        const hiddenPreferredTime = document.getElementById('preferredTime')?.value || '';
        const hours = document.getElementById('estimatedHoursInput')?.value || '2';
        const previewDateEl = document.getElementById('preview-date');
        const previewTimeEl = document.getElementById('preview-time');

        if (dateInput) {
            const d = new Date(dateInput);
            const day = String(d.getDate()).padStart(2, '0');
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const year = d.getFullYear() + 543;
            previewDateEl.innerHTML = `<span class="text-slate-800 font-bold">${day}/${month}/${year}</span>`;
            dateInputEl.classList.remove('border-rose-400', 'ring-2', 'ring-rose-200');
        } else {
            previewDateEl.innerHTML = '<span class="text-rose-600 font-bold animate-pulse">⚠️ กรุณาระบุวันที่ (จำเป็นต้องเลือก)</span>';
        }

        if (previewTimeEl) {
            if (hiddenPreferredTime) {
                previewTimeEl.innerHTML = `<span class="text-slate-800 font-bold">${hiddenPreferredTime}</span>`;
            } else {
                previewTimeEl.innerHTML = '<span class="text-rose-600 font-bold">⚠️ หมดเวลาสำหรับรอบนี้</span>';
            }
        }

        document.getElementById('preview-hours').textContent = hours;
    }

    // ตรวจสอบความถูกต้องก่อน Submit ฟอร์ม
    document.addEventListener("DOMContentLoaded", function() {
        const form = document.querySelector("form");
        if (form) {
            form.addEventListener("submit", function(e) {
                const dateInput = document.getElementById("preferredDateInput");
                const hiddenPreferredTime = document.getElementById("preferredTime");

                if (!dateInput.value) {
                    e.preventDefault();
                    dateInput.focus();
                    dateInput.classList.add("border-rose-500", "ring-2", "ring-rose-200");
                    dateInput.scrollIntoView({ behavior: "smooth", block: "center" });
                    alert("กรุณาระบุวันและช่วงเวลาที่สะดวกให้ช่างเข้าทำงาน (จำเป็นต้องเลือก)");
                    return;
                }

                if (!hiddenPreferredTime || !hiddenPreferredTime.value) {
                    e.preventDefault();
                    alert("ช่วงเวลาที่คุณเลือกไม่สามารถใช้งานได้ (เนื่องจากเวลาดังกล่าวได้ผ่านไปแล้ว) กรุณาเลือกช่วงเวลาใหม่");
                    return;
                }
            });
        }
    });
  </script>

</body>

</html>



