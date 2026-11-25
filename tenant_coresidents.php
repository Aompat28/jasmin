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



// 1. ดึงข้อมูลห้องพักและสัญญาปัจจุบันของผู้เช่า

$stmtRoom = $conn->prepare("

    SELECT r.roomId, r.roomNumber, l.leaseId, l.startDate, l.endDate 

    FROM Lease l 

    JOIN Room r ON l.roomId = r.roomId 

    WHERE l.tenantId = ? AND l.status = 'Active' 

    LIMIT 1

");

$stmtRoom->execute([$tenantId]);

$myRoom = $stmtRoom->fetch(PDO::FETCH_ASSOC);

$myRoomNumber = $myRoom ? ($myRoom['roomNumber'] ?? '') : '';

if (empty($myRoomNumber)) {

    $stmtHdrR = $conn->prepare("SELECT r.roomNumber FROM Lease l JOIN Room r ON l.roomId = r.roomId WHERE l.tenantId = ? ORDER BY CASE WHEN l.status = 'Active' THEN 1 ELSE 2 END, l.leaseId DESC LIMIT 1");

    $stmtHdrR->execute([$tenantId]);

    $myRoomNumber = $stmtHdrR->fetchColumn() ?: '';

}

$roomId = $myRoom ? $myRoom['roomId'] : null;



// --- 2. ระบบส่งคำขอเพิ่มผู้ร่วมอาศัย ---

if (isset($_POST['submit_coresident'])) {

    if (!$roomId) {

        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> ไม่สามารถส่งคำขอได้ เนื่องจากคุณยังไม่มีสัญญาเช่าห้องพักที่เปิดใช้งานอยู่</div>";

    } else {

        $fullName = trim($_POST['fullName']);

        $relationship = trim($_POST['relationship']);

        $idCardNumber = trim($_POST['idCardNumber']);

        $phoneNumber = trim($_POST['phoneNumber']);

        $idCardImage = null;



        // ตรวจสอบว่ามีคำขอของบุคคลนี้อยู่แล้วหรือไม่ (ป้องกันการส่งซ้ำ)

        $checkStmt = $conn->prepare("

            SELECT id, status, fullName FROM coresidents 

            WHERE tenantId = ? AND roomId = ? AND (idCardNumber = ? OR fullName = ?)

            ORDER BY FIELD(status, 'Approved', 'Pending', 'Rejected') LIMIT 1

        ");

        $checkStmt->execute([$tenantId, $roomId, $idCardNumber, $fullName]);

        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);



        if ($existing && $existing['status'] === 'Approved') {

            $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> บุคคลนี้ได้รับการอนุมัติเป็นผู้ร่วมอาศัยในห้องพักเรียบร้อยแล้ว ไม่จำเป็นต้องส่งคำขอซ้ำ</div>";

        } elseif ($existing && $existing['status'] === 'Pending') {

            $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> คำขอสำหรับบุคคลนี้ได้ถูกส่งไปแล้วและอยู่ระหว่างรอแอดมินอนุมัติ กรุณาอย่าส่งคำขอซ้ำ</div>";

        } elseif (!isset($_FILES['id_card_image']) || $_FILES['id_card_image']['error'] !== UPLOAD_ERR_OK || empty($_FILES['id_card_image']['tmp_name'])) {

            $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> กรุณาแนบรูปถ่ายสำเนาบัตรประชาชน หรือ เอกสารยืนยันตัวตน (บังคับแนบเอกสารเพื่อใช้ตรวจสอบ)</div>";

        } else {

            // ตรวจสอบรูปภาพบัตรประชาชน / เอกสาร

            $uploadDir = 'uploads/';

            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

            $ext = strtolower(pathinfo($_FILES['id_card_image']['name'], PATHINFO_EXTENSION));

            $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];



            if (!in_array($ext, $allowedExts)) {

                $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> รูปแบบไฟล์ไม่ถูกต้อง กรุณาอัปโหลดไฟล์รูปภาพ (JPG, PNG, WEBP) เท่านั้น</div>";

            } else {

                $fileName = time() . '_coresident_' . bin2hex(random_bytes(4)) . '.' . $ext;

                $targetFilePath = $uploadDir . $fileName;



                if (move_uploaded_file($_FILES['id_card_image']['tmp_name'], $targetFilePath)) {

                    $idCardImage = $targetFilePath;

                    try {

                        $stmt = $conn->prepare("

                            INSERT INTO coresidents (tenantId, roomId, fullName, relationship, idCardNumber, phoneNumber, idCardImage, status) 

                            VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending')

                        ");

                        $stmt->execute([$tenantId, $roomId, $fullName, $relationship, $idCardNumber, $phoneNumber, $idCardImage]);

                        $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5'></i> ส่งคำขอเพิ่มผู้ร่วมอาศัยสำเร็จแล้ว! กรุณารอแอดมินนิติบุคคลพิจารณาอนุมัติ</div>";

                    } catch(PDOException $e) {

                        $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . htmlspecialchars($e->getMessage()) . "</div>";

                    }

                } else {

                    $message = "<div class='p-4 mb-6 bg-rose-50 border-l-4 border-rose-500 text-rose-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-circle' class='w-5 h-5'></i> เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ กรุณาลองใหม่อีกครั้ง</div>";

                }

            }

        }

    }

}



// --- 3. ระบบยกเลิกคำขอ (เฉพาะสถานะ Pending ที่ยังไม่ได้อนุมัติ) ---

if (isset($_POST['cancel_coresident'])) {

    $cancelId = intval($_POST['coresident_id']);

    $stmtCheck = $conn->prepare("SELECT idCardImage, status FROM coresidents WHERE id = ? AND tenantId = ?");

    $stmtCheck->execute([$cancelId, $tenantId]);

    $item = $stmtCheck->fetch(PDO::FETCH_ASSOC);



    if ($item && $item['status'] === 'Pending') {

        if (!empty($item['idCardImage']) && file_exists($item['idCardImage'])) {

            unlink($item['idCardImage']);

        }

        $stmtDel = $conn->prepare("DELETE FROM coresidents WHERE id = ? AND tenantId = ?");

        $stmtDel->execute([$cancelId, $tenantId]);

        $message = "<div class='p-4 mb-6 bg-rose-50/30 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='trash-2' class='w-5 h-5'></i> ยกเลิกคำขอเพิ่มผู้ร่วมอาศัยเรียบร้อยแล้ว</div>";

    } else {

        $message = "<div class='p-4 mb-6 bg-amber-50 border-l-4 border-amber-500 text-amber-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='alert-triangle' class='w-5 h-5'></i> ไม่สามารถยกเลิกได้ เนื่องจากรายการนี้ได้รับการพิจารณาแล้ว</div>";

    }

}



// --- 4. ระบบส่งคำขอยกเลิกผู้ร่วมอาศัย (สำหรับรายการที่ Approved แล้ว) ---

if (isset($_POST['request_cancel_coresident'])) {

    $cancelId = intval($_POST['coresident_id']);

    $cancelReason = trim($_POST['cancel_reason'] ?? '');

    if (empty($cancelReason)) {

        $cancelReason = 'ย้ายออกจากหอพักแล้ว / ไม่ประสงค์ให้พักอาศัยร่วมกันแล้ว';

    }



    $stmtCheck = $conn->prepare("SELECT id FROM coresidents WHERE id = ? AND tenantId = ? AND status = 'Approved'");

    $stmtCheck->execute([$cancelId, $tenantId]);

    if ($stmtCheck->fetch()) {

        $stmtUpdate = $conn->prepare("UPDATE coresidents SET status = 'Cancel_Pending', cancelReason = ? WHERE id = ? AND tenantId = ?");

        $stmtUpdate->execute([$cancelReason, $cancelId, $tenantId]);

        $message = "<div class='p-4 mb-6 bg-emerald-50 border-l-4 border-emerald-500 text-emerald-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='check-circle-2' class='w-5 h-5'></i> ส่งคำขอยกเลิกผู้ร่วมอาศัยเรียบร้อยแล้ว! นิติบุคคลจะดำเนินการตรวจสอบและอนุมัติการยกเลิก</div>";

    }

}



// --- 5. ระบบถอนคำขอยกเลิก (เปลี่ยนกลับเป็น Approved ตามเดิม) ---

if (isset($_POST['withdraw_cancel_coresident'])) {

    $cancelId = intval($_POST['coresident_id']);

    $stmtUpdate = $conn->prepare("UPDATE coresidents SET status = 'Approved', cancelReason = NULL WHERE id = ? AND tenantId = ? AND status = 'Cancel_Pending'");

    $stmtUpdate->execute([$cancelId, $tenantId]);

    $message = "<div class='p-4 mb-6 bg-rose-50/30 border-l-4 border-slate-500 text-slate-700 rounded-r-xl shadow-sm text-sm font-bold flex items-center gap-2'><i data-lucide='rotate-ccw' class='w-5 h-5'></i> ถอนคำขอยกเลิกเรียบร้อยแล้ว สถานะผู้ร่วมอาศัยกลับเป็น 'อนุมัติแล้ว' ตามเดิม</div>";

}



// 4. ทำความสะอาดรายการที่ซ้ำซ้อน: ถ้าบุคคลนี้ได้รับการอนุมัติ (Approved) แล้ว ให้ลบหรือข้ามคำขอ Pending ที่ซ้ำกัน

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



// ดึงรายการคำขอผู้ร่วมอาศัยของลูกบ้านคนนี้ (แยกกรองตามห้องปัจจุบัน)

$coresidents = [];

if ($roomId) {

    $stmtList = $conn->prepare("

        SELECT c.*, r.roomNumber 

        FROM coresidents c 

        JOIN Room r ON c.roomId = r.roomId 

        WHERE c.tenantId = ? AND c.roomId = ? 

        ORDER BY FIELD(c.status, 'Approved', 'Pending', 'Rejected'), c.id DESC

    ");

    $stmtList->execute([$tenantId, $roomId]);

    $rawList = $stmtList->fetchAll(PDO::FETCH_ASSOC);



    // กรองซ้ำซ้อนระดับ Application: ถ้ามีรายการ Approved ของบุคคลนี้ จะไม่แสดงรายการ Pending ซ้ำอีกเด็ดขาด

    $seenApprovedKeys = [];

    foreach($rawList as $row) {

        if ($row['status'] === 'Approved') {

            if (!empty($row['idCardNumber'])) $seenApprovedKeys[$row['idCardNumber']] = true;

            if (!empty($row['fullName'])) $seenApprovedKeys[$row['fullName']] = true;

        }

    }



    foreach($rawList as $row) {

        if ($row['status'] === 'Pending') {

            if ((!empty($row['idCardNumber']) && isset($seenApprovedKeys[$row['idCardNumber']])) ||

                (!empty($row['fullName']) && isset($seenApprovedKeys[$row['fullName']]))) {

                continue; // ข้ามคำขอ Pending ที่ซ้ำกับคนที่อนุมัติแล้ว

            }

        }

        $coresidents[] = $row;

    }

}



// สรุปสถิติ

$countApproved = 0;

$countPending = 0;

$countRejected = 0;

foreach($coresidents as $c) {

    if ($c['status'] === 'Approved') $countApproved++;

    elseif ($c['status'] === 'Pending' || $c['status'] === 'Cancel_Pending') $countPending++;

    elseif ($c['status'] === 'Rejected' || $c['status'] === 'Cancelled') $countRejected++;

}

?>



<!DOCTYPE html>

<html lang="th">

<head>

  <meta charset="UTF-8">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title>jasmin</title>

  <script src="https://cdn.tailwindcss.com">
    function toggleRelDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('relDropdownMenu');
        const chevron = document.getElementById('relChevron');
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

    function selectRelOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('relationshipInput');
        if (input) input.value = val;
        const triggerText = document.getElementById('relTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }
        const triggerIcon = document.getElementById('relTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;
        const menu = document.getElementById('relDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('relChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customRelDropdown')) {
            const menu = document.getElementById('relDropdownMenu');
            if (menu) menu.classList.add('hidden');
            const ch = document.getElementById('relChevron');
            if (ch) ch.classList.remove('rotate-180');
        }
    });

</script>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest">
    function toggleRelDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('relDropdownMenu');
        const chevron = document.getElementById('relChevron');
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

    function selectRelOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('relationshipInput');
        if (input) input.value = val;
        const triggerText = document.getElementById('relTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }
        const triggerIcon = document.getElementById('relTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;
        const menu = document.getElementById('relDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('relChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customRelDropdown')) {
            const menu = document.getElementById('relDropdownMenu');
            if (menu) menu.classList.add('hidden');
            const ch = document.getElementById('relChevron');
            if (ch) ch.classList.remove('rotate-180');
        }
    });

</script>

  <script>

    tailwind.config = {

      theme: { extend: { fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] } } }

    }

  
    function toggleRelDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('relDropdownMenu');
        const chevron = document.getElementById('relChevron');
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

    function selectRelOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('relationshipInput');
        if (input) input.value = val;
        const triggerText = document.getElementById('relTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }
        const triggerIcon = document.getElementById('relTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;
        const menu = document.getElementById('relDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('relChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customRelDropdown')) {
            const menu = document.getElementById('relDropdownMenu');
            if (menu) menu.classList.add('hidden');
            const ch = document.getElementById('relChevron');
            if (ch) ch.classList.remove('rotate-180');
        }
    });

</script>

  <style>

    body { background-color: #FAF9F6; color: #1e293b; }

    .modal-enter { animation: modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards; }

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

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="flex items-center justify-between h-16">

        <div class="flex items-center space-x-3">

          <button type="button" onclick="toggleTenantSidebar()" class="p-2 -ml-1 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition focus:outline-none cursor-pointer mr-1" title="เปิด/ปิด แถบเมนูด้านซ้าย">

            <i data-lucide="menu" class="w-5 h-5"></i>

          </button>

          

          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-rose-600 via-rose-500 to-pink-600 flex items-center justify-center text-white shadow-md shrink-0">
            <i data-lucide="users" class="w-5 h-5"></i>
          </div>

          <div>
            <span class="font-display font-bold text-lg tracking-tight text-slate-800">หอพักจัสมิน</span>
            <p class="text-[11px] text-slate-400 font-medium hidden sm:block">ระบบจัดการผู้ร่วมอาศัย</p>
          </div>

        </div>



        <div class="flex items-center space-x-2 sm:space-x-3">
          <?php include 'includes/theme_toggle.php'; ?>

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



  <main class="flex-1 max-w-5xl w-full mx-auto px-3.5 sm:px-6 py-4 sm:py-8 pb-24 sm:pb-8">

    

    <!-- 🌟 Top Hero Banner (Warm Rose & Linen Boutique Style) 🌟 -->
    <div class="p-6 sm:p-8 relative overflow-hidden bg-gradient-to-br from-rose-50/90 via-pink-50/50 to-white border border-rose-200/80 rounded-3xl shadow-xs mb-6 sm:mb-8">
      <div class="absolute -right-16 -top-16 w-64 h-64 bg-rose-400/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-pink-400/10 rounded-full blur-3xl pointer-events-none"></div>

      <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-5">
        <div>
          <div class="flex items-center gap-2 flex-wrap mb-2">
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 bg-white border border-stone-200/80 px-3 py-1 rounded-full shadow-2xs">
              <i data-lucide="user-plus" class="w-3.5 h-3.5 text-rose-500"></i>
              <span>ผู้ร่วมอาศัย</span>
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-3 py-1 rounded-full">
              <i data-lucide="shield-check" class="w-3.5 h-3.5 text-amber-500"></i>
              <span>ลงทะเบียนเพื่อความปลอดภัย</span>
            </span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-display font-extrabold text-slate-900 tracking-tight">
            จัดการผู้ร่วมอาศัยเพิ่มเติม
          </h1>
          <p class="text-xs sm:text-sm text-slate-500 mt-1">
            ยื่นคำขอเพื่อลงทะเบียนคนมาพักอาศัยร่วมกันในห้องพัก เพื่อความปลอดภัยและการยืนยันตัวตน
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3 shrink-0">
          <a href="tenant_dashboard.php" class="px-4 py-2.5 bg-white hover:bg-stone-50 text-slate-700 rounded-2xl text-xs sm:text-sm font-bold transition flex items-center gap-2 cursor-pointer border border-stone-200 shadow-2xs active:scale-95">
            <i data-lucide="arrow-left" class="w-4 h-4 text-slate-500"></i>
            <span>กลับแดชบอร์ด</span>
          </a>
        </div>
      </div>
    </div>

    <!-- 🌟 Summary Stats (แนวนอนเรียงกัน 4 ช่อง) 🌟 -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">

      <div class="bg-white p-3 sm:p-5 rounded-2xl border border-rose-100/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-1 text-center sm:text-left">

        <div>

          <p class="text-[11px] sm:text-xs font-semibold text-slate-500 mb-0.5 sm:mb-1 truncate">รออนุมัติ</p>

          <h3 class="text-xl sm:text-2xl font-bold font-display text-amber-500"><?php echo $countPending; ?> <span class="text-[10px] sm:text-xs font-normal text-slate-400">คำขอ</span></h3>

          <p class="hidden sm:block text-[11px] text-slate-400 mt-0.5">กำลังรอการตรวจสอบ</p>

        </div>

        <div class="w-8 h-8 sm:w-12 sm:h-12 mx-auto sm:mx-0 rounded-xl sm:rounded-full bg-amber-50 text-amber-500 flex items-center justify-center shrink-0">

          <i data-lucide="clock" class="w-4 h-4 sm:w-6 sm:h-6"></i>

        </div>

      </div>



      <div class="bg-white p-3 sm:p-5 rounded-2xl border border-rose-100/80 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-1 text-center sm:text-left">

        <div>

          <p class="text-[11px] sm:text-xs font-semibold text-slate-500 mb-0.5 sm:mb-1 truncate">ไม่อนุมัติ/ยกเลิก</p>

          <h3 class="text-xl sm:text-2xl font-bold font-display text-rose-500"><?php echo $countRejected; ?> <span class="text-[10px] sm:text-xs font-normal text-slate-400">รายการ</span></h3>

          <p class="hidden sm:block text-[11px] text-slate-400 mt-0.5">ตรวจสอบเหตุผลด้านล่าง</p>

        </div>

        <div class="w-8 h-8 sm:w-12 sm:h-12 mx-auto sm:mx-0 rounded-xl sm:rounded-full bg-rose-50 text-rose-500 flex items-center justify-center shrink-0">

          <i data-lucide="user-x" class="w-4 h-4 sm:w-6 sm:h-6"></i>

        </div>

      </div>

    </div>



    <!-- Co-residents List -->

    <div class="bg-white rounded-3xl border border-rose-100/80 shadow-sm overflow-hidden mb-8">

      <div class="p-6 border-b border-rose-100/60 flex items-center justify-between">

        <h3 class="font-display font-bold text-slate-800 text-lg flex items-center gap-2">

          <i data-lucide="clipboard-list" class="w-5 h-5 text-indigo-600"></i> รายการผู้ร่วมอาศัยทั้งหมด

        </h3>

        <span class="text-xs text-slate-400">ทั้งหมด <?php echo count($coresidents); ?> รายการ</span>

      </div>



      <?php if(empty($coresidents)): ?>

        <div class="p-12 text-center">

          <div class="w-16 h-16 bg-indigo-50 text-indigo-500 rounded-full flex items-center justify-center mx-auto mb-4">

            <i data-lucide="users" class="w-8 h-8"></i>

          </div>

          <h4 class="font-bold text-slate-700 text-base">ยังไม่มีข้อมูลผู้ร่วมอาศัย</h4>

          <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">

            หากคุณต้องการให้เพื่อน แฟน หรือคนในครอบครัวมาพักอาศัยร่วมกัน กรุณากดปุ่มยื่นคำขอเพื่อส่งเรื่องให้นิติบุคคลอนุมัติ

          </p>

          <?php if($myRoom): ?>

          <button onclick="openRequestModal()" class="mt-4 px-4 py-2 bg-rose-500 hover:bg-rose-600 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-1.5 shadow-sm">

            <i data-lucide="plus-circle" class="w-4 h-4"></i> ยื่นคำขอใหม่

          </button>

          <?php endif; ?>

        </div>

      <?php else: ?>

        <div class="divide-y divide-slate-100">

          <?php foreach($coresidents as $item): 

            $status = $item['status'];

          ?>

          <div class="p-4 sm:p-6 hover:bg-rose-50/30/60 transition flex flex-col md:flex-row md:items-center justify-between gap-3.5 sm:gap-4">

            <div class="flex items-start gap-3 sm:gap-4">

              <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 shadow-xs mt-0.5">

                <i data-lucide="user" class="w-5 h-5 sm:w-6 sm:h-6"></i>

              </div>

              <div class="flex-1 min-w-0">

                <div class="flex items-center gap-1.5 sm:gap-2 flex-wrap">

                  <h4 class="font-bold text-slate-800 text-sm sm:text-base"><?php echo htmlspecialchars($item['fullName']); ?></h4>

                  <span class="text-[10px] sm:text-xs bg-slate-100 text-slate-700 font-semibold px-2 sm:px-2.5 py-0.5 rounded-full border border-rose-100/80">

                    <?php echo htmlspecialchars($item['relationship']); ?>

                  </span>

                  

                  <?php if($status === 'Approved'): ?>

                    <span class="text-[10px] sm:text-xs bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold px-2 sm:px-2.5 py-0.5 rounded-full flex items-center gap-1">

                      <i data-lucide="check-circle-2" class="w-3 h-3 sm:w-3.5 sm:h-3.5"></i> อนุมัติแล้ว

                    </span>

                  <?php elseif($status === 'Cancel_Pending'): ?>

                    <span class="text-[10px] sm:text-xs bg-amber-50 text-amber-700 border border-amber-300 font-bold px-2 sm:px-2.5 py-0.5 rounded-full flex items-center gap-1 animate-pulse">

                      <i data-lucide="clock" class="w-3 h-3 sm:w-3.5 sm:h-3.5 text-amber-500"></i> รออนุมัติการยกเลิก

                    </span>

                  <?php elseif($status === 'Cancelled'): ?>

                    <span class="text-[10px] sm:text-xs bg-slate-100 text-slate-600 border border-rose-100/80 font-bold px-2 sm:px-2.5 py-0.5 rounded-full flex items-center gap-1">

                      <i data-lucide="user-x" class="w-3 h-3 sm:w-3.5 sm:h-3.5 text-slate-500"></i> ยกเลิกการพักอาศัยแล้ว

                    </span>

                  <?php elseif($status === 'Pending'): ?>

                    <span class="text-[10px] sm:text-xs bg-amber-50 text-amber-700 border border-amber-200 font-bold px-2 sm:px-2.5 py-0.5 rounded-full flex items-center gap-1">

                      <i data-lucide="clock" class="w-3 h-3 sm:w-3.5 sm:h-3.5"></i> รอแอดมินอนุมัติ

                    </span>

                  <?php elseif($status === 'Rejected'): ?>

                    <span class="text-[10px] sm:text-xs bg-rose-50 text-rose-700 border border-rose-200 font-bold px-2 sm:px-2.5 py-0.5 rounded-full flex items-center gap-1">

                      <i data-lucide="x-circle" class="w-3 h-3 sm:w-3.5 sm:h-3.5"></i> ไม่อนุมัติ

                    </span>

                  <?php endif; ?>

                </div>



                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 mt-2 text-xs text-slate-500">

                  <div class="flex items-center gap-1.5">

                    <i data-lucide="phone" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>

                    <span>เบอร์โทร: <strong class="text-slate-700"><?php echo htmlspecialchars($item['phoneNumber']); ?></strong></span>

                  </div>

                  <div class="flex items-center gap-1.5">

                    <i data-lucide="id-card" class="w-3.5 h-3.5 text-slate-400 shrink-0"></i>

                    <span>เลขประจำตัว: <strong class="text-slate-700 font-mono"><?php echo htmlspecialchars($item['idCardNumber']); ?></strong></span>

                  </div>

                  <div class="flex items-center gap-1.5 sm:col-span-2 mt-0.5 text-[11px] text-slate-400">

                    <i data-lucide="calendar" class="w-3.5 h-3.5 shrink-0"></i>

                    <span>ยื่นคำขอเมื่อ: <?php echo date("d/m/Y H:i", strtotime($item['created_at'])); ?> น.</span>

                  </div>

                </div>



                <?php if($status === 'Cancel_Pending' && !empty($item['cancelReason'])): ?>

                  <div class="mt-2.5 p-2.5 bg-amber-50/80 border border-amber-200 rounded-xl text-xs text-amber-800 flex items-start gap-1.5">

                    <i data-lucide="info" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>

                    <div>

                      <strong class="font-bold">เหตุผลที่ขอยกเลิก:</strong> <?php echo htmlspecialchars($item['cancelReason']); ?>

                    </div>

                  </div>

                <?php endif; ?>



                <?php if($status === 'Cancelled' && (!empty($item['cancelReason']) || !empty($item['adminRemark']))): ?>

                  <div class="mt-2.5 p-2.5 bg-slate-100 border border-rose-100/80 rounded-xl text-xs text-slate-600 flex items-start gap-1.5">

                    <i data-lucide="user-x" class="w-4 h-4 text-slate-500 shrink-0 mt-0.5"></i>

                    <div>

                      <?php if(!empty($item['cancelReason'])): ?>

                        <div><strong class="font-bold">เหตุผล:</strong> <?php echo htmlspecialchars($item['cancelReason']); ?></div>

                      <?php endif; ?>

                      <?php if(!empty($item['adminRemark'])): ?>

                        <div class="text-[11px] text-slate-500 mt-0.5"><strong class="font-bold">นิติบุคคล:</strong> <?php echo htmlspecialchars($item['adminRemark']); ?></div>

                      <?php endif; ?>

                    </div>

                  </div>

                <?php endif; ?>



                <?php if($status === 'Rejected' && !empty($item['adminRemark'])): ?>

                  <div class="mt-3 p-3 bg-rose-50 border border-rose-100 rounded-xl text-xs text-rose-700">

                    <strong class="font-bold flex items-center gap-1 mb-0.5">

                      <i data-lucide="message-square-warning" class="w-3.5 h-3.5"></i> เหตุผลจากนิติบุคคล:

                    </strong>

                    <?php echo nl2br(htmlspecialchars($item['adminRemark'])); ?>

                  </div>

                <?php endif; ?>

              </div>

            </div>



            <!-- Action buttons: responsive layout for mobile and desktop -->

            <div class="flex items-center gap-2 w-full md:w-auto pt-3 border-t border-rose-100/60 md:border-0 md:pt-0 justify-end md:justify-end flex-wrap">

              <?php if(!empty($item['idCardImage'])): ?>

                <button type="button" onclick="openImageModal('<?php echo htmlspecialchars($item['idCardImage']); ?>', 'สำเนาบัตรประชาชน - <?php echo htmlspecialchars($item['fullName']); ?>')" class="flex-1 sm:flex-initial justify-center px-3 py-2 sm:py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold flex items-center gap-1.5 transition shadow-xs cursor-pointer active:scale-95">

                  <i data-lucide="file-image" class="w-3.5 h-3.5 text-slate-500"></i> ดูเอกสาร

                </button>

              <?php endif; ?>



              <?php if($status === 'Approved'): ?>

                <!-- ปุ่มขอยกเลิกผู้ร่วมอาศัย (เผื่อไม่ต้องการให้อยู่หรือย้ายออกแล้ว) -->

                <button type="button" onclick="openCancelModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['fullName'])); ?>')" class="flex-1 sm:flex-initial justify-center px-3 py-2 sm:py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 rounded-xl text-xs font-semibold flex items-center gap-1 transition shadow-xs cursor-pointer active:scale-95">

                  <i data-lucide="user-minus" class="w-3.5 h-3.5"></i> ขอยกเลิกผู้ร่วมอาศัย

                </button>

              <?php elseif($status === 'Cancel_Pending'): ?>

                <!-- ปุ่มถอนคำขอยกเลิก -->

                <form action="" method="POST" onsubmit="return confirm('ต้องการถอนคำขอยกเลิกใช่หรือไม่? สถานะจะกลับเป็นอนุมัติแล้วตามเดิม');" class="flex-1 sm:flex-initial">

                  <input type="hidden" name="coresident_id" value="<?php echo $item['id']; ?>">

                  <button type="submit" name="withdraw_cancel_coresident" class="w-full justify-center px-3 py-2 sm:py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-semibold flex items-center gap-1 transition cursor-pointer active:scale-95">

                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i> ถอนคำขอยกเลิก

                  </button>

                </form>

              <?php elseif($status === 'Pending'): ?>

                <form action="" method="POST" onsubmit="return confirm('แน่ใจหรือไม่ที่จะยกเลิกคำขอนี้?');" class="flex-1 sm:flex-initial">

                  <input type="hidden" name="coresident_id" value="<?php echo $item['id']; ?>">

                  <button type="submit" name="cancel_coresident" class="w-full justify-center px-3 py-2 sm:py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-semibold flex items-center gap-1 transition cursor-pointer active:scale-95">

                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ยกเลิกคำขอ

                  </button>

                </form>

              <?php endif; ?>

            </div>

          </div>

          <?php endforeach; ?>

        </div>

      <?php endif; ?>

    </div>



    <!-- ข้อกำหนดและคำชี้แจง -->

    <div class="bg-indigo-50/70 border border-indigo-100 rounded-2xl sm:rounded-3xl p-4 sm:p-6 text-xs text-slate-600 mb-6 sm:mb-0">

      <h4 class="font-bold text-indigo-900 text-sm flex items-center gap-2 mb-2">

        <i data-lucide="info" class="w-4 h-4 text-indigo-600"></i> เงื่อนไขและข้อกำหนดการมีผู้ร่วมอาศัย

      </h4>

      <ul class="list-disc list-inside space-y-1.5 text-indigo-950/80 leading-relaxed">

        <li>ผู้เช่าหลักสามารถยื่นคำขอเพิ่มผู้ร่วมอาศัยได้ตามเงื่อนไขที่หอพักกำหนด</li>

        <li>เมื่อนิติบุคคลทำการอนุมัติแล้ว ผู้ร่วมอาศัยจะมีสิทธิ์พักอาศัยและได้รับบัตรผ่านเข้า-ออกตามระเบียบ</li>

        <li>ในกรณีที่ผู้ร่วมอาศัยย้ายออก หรือไม่ประสงค์ให้อยู่ร่วมกันแล้ว สามารถกด <b>"ขอยกเลิกผู้ร่วมอาศัย"</b> เพื่อแจ้งนิติบุคคลได้</li>

        <li>ห้ามนำข้อมูลเท็จมาลงทะเบียน หากตรวจพบจะมีความผิดตามกฎระเบียบของหอพัก</li>

      </ul>

    </div>



    <?php if($myRoom): ?>

    <!-- Mobile Bottom Floating Action Bar -->

    <div class="sm:hidden fixed bottom-0 left-0 right-0 z-30 p-3 bg-white/95 backdrop-blur-md border-t border-rose-100/80 shadow-[0_-4px_16px_rgba(0,0,0,0.08)]">

      <button onclick="openRequestModal()" class="w-full py-3 px-4 bg-gradient-to-r from-indigo-600 to-blue-600 active:scale-[0.98] text-white rounded-xl text-sm font-bold shadow-md shadow-indigo-300/40 flex items-center justify-center gap-2 transition cursor-pointer">

        <i data-lucide="plus-circle" class="w-5 h-5"></i> ยื่นคำขอเพิ่มผู้ร่วมอาศัย

      </button>

    </div>

    <?php endif; ?>

  </main>



  <!-- MODAL: ฟอร์มยื่นคำขอเพิ่มผู้ร่วมอาศัย -->

  <div id="requestModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 backdrop-blur-sm flex items-end sm:items-center justify-center p-0 sm:p-4">

    <div class="bg-white rounded-t-3xl sm:rounded-3xl max-w-lg w-full p-5 sm:p-8 shadow-2xl border border-rose-100/60 modal-enter max-h-[92vh] sm:max-h-[90vh] overflow-y-auto">

      <div class="flex items-center justify-between pb-3 sm:pb-4 border-b border-rose-100/60 mb-4 sm:mb-6">

        <div>

          <h3 class="font-display font-bold text-slate-800 text-base sm:text-lg flex items-center gap-2">

              <i data-lucide="user-plus" class="w-5 h-5 text-indigo-600"></i> แบบฟอร์มขอเพิ่มผู้ร่วมอาศัย

          </h3>

          <p class="text-xs text-slate-400 mt-0.5">ห้อง <?php echo htmlspecialchars($myRoom['roomNumber'] ?? ''); ?></p>

        </div>

        <button onclick="closeRequestModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition cursor-pointer">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" enctype="multipart/form-data" id="coresidentForm" onsubmit="return validateCoresidentForm(event);" class="space-y-3.5 sm:space-y-4">

        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">ชื่อ - นามสกุล ผู้ร่วมอาศัย <span class="text-rose-500">*</span></label>

          <input type="text" name="fullName" id="coFullNameInput" required placeholder="เช่น นายสมศักดิ์ สุขใจ" oninput="this.value = this.value.replace(/[^a-zA-Z\u0E00-\u0E7F\s]/g, '')" class="w-full px-3.5 py-2.5 text-base sm:text-sm bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-indigo-500 focus:bg-white transition">

        </div>



        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5 sm:gap-4">

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1.5">ความสัมพันธ์ <span class="text-rose-500">*</span></label>
            <input type="hidden" name="relationship" id="relationshipInput" required value="">
            <div class="relative" id="customRelDropdown">
              <button type="button" onclick="toggleRelDropdown(event)" id="relDropdownTrigger"
                      class="w-full px-3.5 py-2.5 text-xs sm:text-sm bg-rose-50/30 border border-rose-100/80 hover:border-rose-300 focus:border-rose-400 rounded-xl flex items-center justify-between text-slate-800 transition cursor-pointer">
                <span class="flex items-center gap-2 truncate">
                  <span id="relTriggerIcon">👥</span>
                  <span id="relTriggerText" class="text-slate-400">-- เลือกความสัมพันธ์ --</span>
                </span>
                <i data-lucide="chevron-down" id="relChevron" class="w-4 h-4 text-slate-400 shrink-0 transition-transform duration-200"></i>
              </button>
              <div id="relDropdownMenu" class="hidden absolute left-0 right-0 top-full mt-1 z-50 bg-white/95 backdrop-blur-xl border border-rose-200/90 rounded-2xl shadow-xl p-1.5 space-y-1">
                <div onclick="selectRelOption('แฟน / คู่สมรส', '💕', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800"><span>💕</span><span>แฟน / คู่สมรส</span></div>
                <div onclick="selectRelOption('บุตร / ธิดา', '👶', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800"><span>👶</span><span>บุตร / ธิดา</span></div>
                <div onclick="selectRelOption('บิดา / มารดา', '👨‍👩‍👧', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800"><span>👨‍👩‍👧</span><span>บิดา / มารดา</span></div>
                <div onclick="selectRelOption('พี่ / น้อง', '👫', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800"><span>👫</span><span>พี่ / น้อง</span></div>
                <div onclick="selectRelOption('เพื่อน', '🤝', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800"><span>🤝</span><span>เพื่อน</span></div>
                <div onclick="selectRelOption('ญาติ', '🏡', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800"><span>🏡</span><span>ญาติ</span></div>
                <div onclick="selectRelOption('อื่นๆ', '💬', event)" class="p-2 rounded-xl flex items-center gap-2 cursor-pointer hover:bg-rose-50/80 transition text-xs font-semibold text-slate-800 border-t border-rose-100/60 pt-1.5"><span>💬</span><span>อื่นๆ</span></div>
              </div>
            </div>
          </div>

          <div>

            <label class="block text-xs font-semibold text-slate-700 mb-1.5">เบอร์โทรศัพท์ติดต่อ <span class="text-rose-500">*</span></label>

            <input type="tel" name="phoneNumber" id="coPhoneInput" required maxlength="10" placeholder="10 หลัก (เฉพาะตัวเลข)" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full px-3.5 py-2.5 text-base sm:text-sm bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-indigo-500 focus:bg-white transition">

          </div>

        </div>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">เลขประจำตัวประชาชน <span class="text-rose-500">*</span></label>

          <input type="text" name="idCardNumber" id="coIdCardInput" required maxlength="13" placeholder="เลขบัตรประชาชน 13 หลัก" oninput="this.value = this.value.replace(/[^0-9]/g, '')" class="w-full px-3.5 py-2.5 text-base sm:text-sm font-mono bg-rose-50/30 border border-rose-100/80 rounded-xl outline-none focus:border-indigo-500 focus:bg-white transition">

        </div>



        <div>

          <label class="block text-xs font-semibold text-slate-700 mb-1.5">

            แนบรูปถ่ายสำเนาบัตรประชาชน / เอกสารยืนยันตัวตน <span class="text-rose-500 font-bold">* (จำเป็นต้องแนบ)</span>

          </label>

          <div id="dropzoneBox" class="border-2 border-dashed border-rose-100/80 hover:border-indigo-400 rounded-2xl p-4 text-center hover:bg-rose-50/30 transition cursor-pointer relative">

            <input type="file" id="idCardFileInput" name="id_card_image" accept="image/jpeg,image/png,image/webp,image/jpg" required onchange="previewUpload(event)" class="absolute inset-0 opacity-0 w-full h-full cursor-pointer z-10">

            <div id="uploadPlaceholder">

              <i data-lucide="upload-cloud" class="w-8 h-8 text-indigo-500 mx-auto mb-1"></i>

              <p class="text-xs font-semibold text-slate-700">คลิกเพื่อเลือกรูปภาพ หรือ ลากไฟล์มาวาง <span class="text-rose-500">*</span></p>

              <p class="text-[10px] text-slate-400 mt-0.5">รองรับไฟล์ JPG, PNG, WEBP (ขนาดไม่เกิน 5MB)</p>

            </div>

            <div id="uploadPreviewWrap" class="hidden">

              <img id="uploadPreviewImg" src="" class="max-h-36 mx-auto rounded-lg shadow-sm border border-rose-100/80">

              <p id="uploadFileName" class="text-xs text-indigo-600 font-bold mt-2"></p>

              <span class="inline-block mt-1 text-[11px] text-slate-400 hover:text-indigo-600 font-medium underline">คลิกเพื่อเปลี่ยนรูปภาพ</span>

            </div>

          </div>

          <!-- ข้อความคำแนะนำการขีดคร่อมเอกสาร -->

          <div class="mt-2 p-2.5 bg-amber-50/90 border border-amber-200/90 rounded-xl flex items-start gap-2 text-[11px] text-amber-900 leading-relaxed">

            <i data-lucide="shield-alert" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5"></i>

            <span>

              <b>คำแนะนำความปลอดภัย:</b> กรุณาขีดคร่อม <b>'ใช้สำหรับเป็นหลักฐานการเข้าพักหอพัก...เท่านั้น'</b> และ <b>ขีดฆ่าข้อมูลศาสนา/กรุ๊ปเลือด</b> ก่อนอัปโหลด

            </span>

          </div>

          <p id="fileRequiredAlert" class="hidden text-xs text-rose-500 font-bold mt-1.5 flex items-center gap-1">

            <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i> กรุณาแนบรูปถ่ายสำเนาบัตรประชาชนหรือเอกสารยืนยันตัวตนก่อนส่งคำขอ

          </p>

        </div>



        <div class="pt-2">

          <label class="flex items-start gap-2 cursor-pointer text-xs text-slate-600 select-none">

            <input type="checkbox" required class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500">

            <span>ข้าพเจ้ารับรองว่าข้อมูลข้างต้นเป็นความจริง และยินยอมปฏิบัติตามกฎระเบียบของหอพัก</span>

          </label>

        </div>



        <div class="pt-2 sm:pt-3 flex gap-2.5 sm:gap-3">

          <button type="button" onclick="closeRequestModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs sm:text-sm font-bold rounded-xl transition cursor-pointer">

            ยกเลิก

          </button>

          <button type="submit" name="submit_coresident" class="flex-1 py-2.5 bg-rose-500 hover:bg-rose-600 text-white text-xs sm:text-sm font-bold rounded-xl shadow-md transition flex items-center justify-center gap-1.5 cursor-pointer">

            <i data-lucide="send" class="w-4 h-4"></i> ส่งคำขอ

          </button>

        </div>

      </form>

    </div>

  </div>



  <!-- MODAL: Standard Document/Image Viewer -->
  <?php include_once 'includes/doc_viewer_modal.php'; ?>



  <!-- MODAL: Cancel Co-resident Request -->

  <div id="cancelModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-900/80 backdrop-blur-sm flex items-end sm:items-center justify-center p-0 sm:p-4">

    <div class="bg-white rounded-t-3xl sm:rounded-3xl max-w-md w-full p-5 sm:p-6 shadow-2xl modal-enter" onclick="event.stopPropagation()">

      <div class="flex items-center justify-between pb-3 border-b border-rose-100/60 mb-4">

        <div class="flex items-center gap-2">

          <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center shrink-0">

            <i data-lucide="user-x" class="w-5 h-5"></i>

          </div>

          <div>

            <h4 class="font-bold text-slate-800 text-sm sm:text-base">ขอยกเลิกผู้ร่วมอาศัย</h4>

            <p class="text-[11px] sm:text-xs text-slate-400">ส่งคำขอนี้เพื่อให้นิติบุคคลยกเลิกสิทธิ์การเข้าพัก</p>

          </div>

        </div>

        <button type="button" onclick="closeCancelModal()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition cursor-pointer">

          <i data-lucide="x" class="w-5 h-5"></i>

        </button>

      </div>



      <form action="" method="POST" class="space-y-3.5 sm:space-y-4">

        <input type="hidden" name="coresident_id" id="cancelTargetId" value="">

        

        <div class="p-3 bg-rose-50/30 rounded-xl sm:rounded-2xl border border-rose-100/80 text-xs">

          <span class="text-slate-400 block text-[11px]">ผู้ร่วมอาศัยที่ต้องการยกเลิก:</span>

          <strong id="cancelTargetName" class="text-slate-800 text-sm font-bold block mt-0.5"></strong>

        </div>



        <div>

          <label class="block text-xs font-bold text-slate-700 mb-1">เหตุผลที่ขอยกเลิก / แจ้งย้ายออก:</label>

          <div class="relative mb-2">
            <select id="cancelReasonPreset" onchange="applyPresetReason(this.value)" class="w-full px-4 py-3 pr-10 bg-white border-2 border-stone-200 hover:border-rose-400 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/10 rounded-2xl text-xs sm:text-sm font-bold text-slate-800 shadow-xs outline-none transition appearance-none cursor-pointer">
              <option value="ย้ายออกจากหอพักแล้ว">ย้ายออกจากหอพักแล้ว</option>
              <option value="ไม่ประสงค์ให้พักอาศัยร่วมกันแล้ว">ไม่ประสงค์ให้พักอาศัยร่วมกันแล้ว</option>
              <option value="สิ้นสุดระยะเวลาการพักอาศัย">สิ้นสุดระยะเวลาการพักอาศัย</option>
              <option value="custom">อื่นๆ (ระบุเพิ่มเติมด้านล่าง)</option>
            </select>
            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3.5 text-rose-500">
              <i data-lucide="chevron-down" class="w-4 h-4"></i>
            </div>
          </div>

          <textarea name="cancel_reason" id="cancelReasonText" rows="2" class="w-full px-3.5 py-2.5 sm:py-2 bg-rose-50/30 border border-rose-100/80 rounded-xl text-base sm:text-xs outline-none focus:border-rose-400 transition" placeholder="ระบุเหตุผลเพิ่มเติม...">ย้ายออกจากหอพักแล้ว</textarea>

        </div>



        <div class="pt-2 flex gap-2.5">

          <button type="button" onclick="closeCancelModal()" class="flex-1 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition cursor-pointer">

            ยกเลิก

          </button>

          <button type="submit" name="request_cancel_coresident" class="flex-1 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center justify-center gap-1.5 cursor-pointer">

            <i data-lucide="send" class="w-4 h-4"></i> ยืนยันส่งคำขอยกเลิก

          </button>

        </div>

      </form>

    </div>

  </div>



  <script>

    lucide.createIcons();



    function openRequestModal() {

      document.getElementById('requestModal').classList.remove('hidden');

    }

    function closeRequestModal() {

      document.getElementById('requestModal').classList.add('hidden');

    }



    function openCancelModal(id, name) {

      document.getElementById('cancelTargetId').value = id;

      document.getElementById('cancelTargetName').innerText = name;

      document.getElementById('cancelReasonPreset').value = "ย้ายออกจากหอพักแล้ว";

      document.getElementById('cancelReasonText').value = "ย้ายออกจากหอพักแล้ว";

      document.getElementById('cancelModal').classList.remove('hidden');

      lucide.createIcons();

    }

    function closeCancelModal() {

      document.getElementById('cancelModal').classList.add('hidden');

    }



    function applyPresetReason(val) {

      const txt = document.getElementById('cancelReasonText');

      if (val === 'custom') {

        txt.value = '';

        txt.focus();

      } else {

        txt.value = val;

      }

    }



    function validateCoresidentForm(e) {

      const nameInput = document.getElementById('coFullNameInput');

      const phoneInput = document.getElementById('coPhoneInput');

      const idCardInput = document.getElementById('coIdCardInput');

      const fileInput = document.getElementById('idCardFileInput');

      const dropzone = document.getElementById('dropzoneBox');

      const alertText = document.getElementById('fileRequiredAlert');



      if (nameInput && !/^[a-zA-Z\u0E00-\u0E7F\s]+$/.test(nameInput.value.trim())) {

        alert('⚠️ ชื่อ-นามสกุล ต้องเป็นตัวอักษรภาษาไทยหรือภาษาอังกฤษเท่านั้น');

        nameInput.focus();

        return false;

      }



      if (phoneInput && phoneInput.value.length !== 10) {

        alert(`⚠️ เบอร์โทรศัพท์ติดต่อต้องเป็นตัวเลข 10 หลักเท่านั้น (ปัจจุบันกรอก: ${phoneInput.value.length} หลัก)`);

        phoneInput.focus();

        return false;

      }



      if (idCardInput && idCardInput.value.length !== 13) {

        alert(`⚠️ เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลักเท่านั้น (ปัจจุบันกรอก: ${idCardInput.value.length} หลัก)`);

        idCardInput.focus();

        return false;

      }



      if (!fileInput || !fileInput.files || fileInput.files.length === 0) {

        if (alertText) alertText.classList.remove('hidden');

        if (dropzone) {

          dropzone.classList.remove('border-rose-100/80', 'hover:border-indigo-400');

          dropzone.classList.add('border-rose-500', 'bg-rose-50/40');

          dropzone.scrollIntoView({ behavior: 'smooth', block: 'center' });

        }

        lucide.createIcons();

        return false;

      }

      return true;

    }



    function previewUpload(event) {

      const file = event.target.files[0];

      const dropzone = document.getElementById('dropzoneBox');

      const alertText = document.getElementById('fileRequiredAlert');



      if (file) {

        if (dropzone) {

          dropzone.classList.remove('border-rose-500', 'bg-rose-50/40');

          dropzone.classList.add('border-emerald-500', 'bg-emerald-50/20');

        }

        if (alertText) alertText.classList.add('hidden');



        const reader = new FileReader();

        reader.onload = function(e) {

          document.getElementById('uploadPlaceholder').classList.add('hidden');

          document.getElementById('uploadPreviewWrap').classList.remove('hidden');

          document.getElementById('uploadPreviewImg').src = e.target.result;

          document.getElementById('uploadFileName').innerText = file.name;

        }

        reader.readAsDataURL(file);

      }

    }





  
    function toggleRelDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('relDropdownMenu');
        const chevron = document.getElementById('relChevron');
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

    function selectRelOption(val, icon, e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('relationshipInput');
        if (input) input.value = val;
        const triggerText = document.getElementById('relTriggerText');
        if (triggerText) {
            triggerText.innerText = val;
            triggerText.className = 'text-slate-800 font-bold';
        }
        const triggerIcon = document.getElementById('relTriggerIcon');
        if (triggerIcon) triggerIcon.innerText = icon;
        const menu = document.getElementById('relDropdownMenu');
        if (menu) menu.classList.add('hidden');
        const chevron = document.getElementById('relChevron');
        if (chevron) chevron.classList.remove('rotate-180');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#customRelDropdown')) {
            const menu = document.getElementById('relDropdownMenu');
            if (menu) menu.classList.add('hidden');
            const ch = document.getElementById('relChevron');
            if (ch) ch.classList.remove('rotate-180');
        }
    });

</script>

</body>

</html>

